<?php

namespace MiningManager\Services\Notification\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use MiningManager\Models\WebhookConfiguration;
use MiningManager\Services\Configuration\SettingsManagerService;

/**
 * Shared webhook dispatch concerns for NotificationService (and any
 * future dispatcher that might spin up alongside it).
 *
 * Extracted in Phase A of the notification consolidation (2026-04-23) to
 * eliminate duplicated infrastructure that had burned us twice —
 * most notably the per-type role-mention "legacy fallback" fix that had
 * to be applied independently to two services. After Phases B-F folded
 * the old WebhookService into NotificationService entirely, this trait
 * is used by the single remaining dispatcher, but is left as a trait
 * so it can be reused if we ever introduce a second dispatch class
 * (e.g. a dedicated ESI-mail service or a Pings plugin adapter).
 *
 * Contents:
 *  - postWithRetry — HTTP POST with 5xx/429 retry + Retry-After support.
 *  - getDiscordRoleMention — per-type ping_role setting authoritative,
 *    with legacy webhook-level discord_role_id as fallback.
 *  - getMoonOwnerScopedWebhooks — returns webhooks whose corporation_id
 *    is NULL or matches the configured Tax Program corp. Used for
 *    tax, moon, and theft dispatches (everything that concerns the
 *    moon-owner corp's operations).
 *  - getCorpName — human-readable Tax Program corp name for footers.
 *
 * Usage:
 *   class FooDispatcher {
 *       use \MiningManager\Services\Notification\Concerns\WebhookDispatchTrait;
 *   }
 */
trait WebhookDispatchTrait
{
    /**
     * Maximum seconds to honour from a 429 `Retry-After` header before
     * giving up. Discord and Slack typically issue Retry-After values in
     * the 0.5–3s range; values above 5s usually mean a global rate limit
     * that's better deferred to a queued retry than blocking a PHP-FPM
     * worker indefinitely. Operators see the failure in logs and the
     * notification will fire on the next cron tick anyway (most surfaces
     * have idempotent dedup latches).
     *
     * Tuned from the previous 10s cap — saves up to 5 worker-seconds per
     * rate-limited webhook. Batch dispatches (e.g. tax invoices fanning
     * out to N webhooks) accumulate this cost linearly.
     */
    protected const RETRY_AFTER_HARD_CAP_SECONDS = 5;

    /**
     * Send an HTTP POST with retry logic for transient failures.
     *
     * Retries up to $maxAttempts times on 5xx server errors and 429 rate
     * limits (respecting the Retry-After header, capped at
     * RETRY_AFTER_HARD_CAP_SECONDS). Client errors other than 429 are
     * returned immediately — no point retrying a 4xx. Exceptions
     * (connection failures, timeouts) are retried then rethrown if all
     * attempts fail.
     *
     * Total worst-case blocking time:
     *   3 × HTTP timeout (10s) + 2 × retry delay (2s) + 2 × rate-limit cap (5s)
     *   = 30 + 4 + 10 = 44s in the absolute worst case (every attempt times
     *   out + every gap is the rate-limit cap), but typical 429 paths block
     *   only 1 × 5s = 5s before the second attempt succeeds.
     *
     * @param string $url Target webhook URL
     * @param array  $payload JSON payload
     * @param int    $maxAttempts Total attempts including the first
     * @param int    $retryDelaySeconds Baseline sleep between attempts
     * @return Response
     * @throws \Exception when all attempts fail with exceptions
     */
    protected function postWithRetry(
        string $url,
        array $payload,
        int $maxAttempts = 3,
        int $retryDelaySeconds = 2
    ): Response {
        $lastException = null;
        $response = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout(10)->post($url, $payload);

                // Don't retry on client errors (4xx) — only on server errors (5xx) or rate limits (429)
                if ($response->successful()
                    || $response->status() === 204
                    || ($response->clientError() && $response->status() !== 429)) {
                    return $response;
                }

                // Rate limited — respect Retry-After header if present, but
                // cap to RETRY_AFTER_HARD_CAP_SECONDS to bound worker
                // blocking time. A larger requested wait is treated as
                // "give up this attempt" — the next attempt will retry at
                // the bottom of the loop after the standard delay, which
                // is usually enough to slip through Discord's per-route
                // bucket reset.
                if ($response->status() === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?? $retryDelaySeconds);
                    $boundedWait = min($retryAfter, self::RETRY_AFTER_HARD_CAP_SECONDS);
                    Log::debug("Mining Manager: Webhook rate limited, retrying in {$boundedWait}s (server requested {$retryAfter}s; attempt {$attempt}/{$maxAttempts})");
                    sleep($boundedWait);
                    continue;
                }

                Log::debug("Mining Manager: Webhook returned {$response->status()}, retrying (attempt {$attempt}/{$maxAttempts})");
            } catch (\Exception $e) {
                $lastException = $e;
                Log::debug("Mining Manager: Webhook request failed: {$e->getMessage()} (attempt {$attempt}/{$maxAttempts})");
            }

            if ($attempt < $maxAttempts) {
                sleep($retryDelaySeconds);
            }
        }

        // Return last response if we have one, otherwise throw
        if ($response !== null) {
            return $response;
        }

        throw $lastException ?? new \Exception('Webhook request failed after all retry attempts');
    }

    /**
     * Resolve the Discord role mention string for a notification type on a
     * specific webhook, honouring per-type settings.
     *
     * The per-type "ping_role" setting is authoritative — if a user turned
     * off role pings for this event type in Notification Settings, we
     * never ping, regardless of any legacy `discord_role_id` on the webhook
     * itself. This fix landed independently in both dispatchers before this
     * trait existed; it's centralised here to prevent future regressions.
     *
     * Precedence when ping_role is ON:
     *   1. per-type role (notifications.types.{type}.role_id)
     *   2. webhook's own discord_role_id (legacy)
     *   3. null (no mention)
     *
     * @param string               $eventType  e.g. 'theft_detected', 'moon_arrival'
     * @param WebhookConfiguration $webhook    Webhook providing legacy fallback
     * @return string|null Discord "<@&roleId>" mention, or null for no ping
     */
    /**
     * Resolve the Discord role mention string for a notification on a
     * given webhook. Public so diagnostic previews can call it directly
     * rather than via reflection (also keeps it discoverable from any
     * future dispatcher).
     */
    public function getDiscordRoleMention(string $eventType, WebhookConfiguration $webhook): ?string
    {
        // Types that are fired in batches and must NEVER role-ping, regardless
        // of settings — pinging once per invoice (or per dispatched item in any
        // per-character batch) would spam the role with N messages on every
        // cron run. The per-user mention still works for these; only the
        // blanket role ping is suppressed.
        //
        // Kept as a hard-coded guard rather than a setting because legacy DB
        // values with ping_role=true for these types could otherwise re-emerge
        // after the UI toggle was removed.
        $typesThatMustNotRolePing = ['tax_invoice'];
        if (in_array($eventType, $typesThatMustNotRolePing, true)) {
            return null;
        }

        // Map webhook event keys to settings-type keys where they differ.
        // moon_arrival (webhook subscription column) maps to moon_ready
        // (settings type key). Most types match directly.
        $settingsKey = match ($eventType) {
            'moon_arrival' => 'moon_ready',
            default => $eventType,
        };

        $typeSettings = app(SettingsManagerService::class)
            ->getTypeNotificationSettings($settingsKey);

        // Per-type settings are authoritative: if ping_role is OFF, never
        // ping — even if the webhook has a legacy discord_role_id set.
        if (!$typeSettings['ping_role']) {
            return null;
        }

        // ping_role is ON — prefer the per-type role, fall back to legacy.
        if (!empty($typeSettings['role_id'])) {
            return "<@&{$typeSettings['role_id']}>";
        }

        if ($webhook->discord_role_id) {
            return $webhook->getDiscordRoleMention();
        }

        return null;
    }

    /**
     * Return the set of webhooks that should receive a notification scoped
     * to the Tax Program / Moon Owner Corporation.
     *
     * Moon, theft, and tax notifications always concern the corp that runs
     * the mining tax program on this SeAT install. We intentionally exclude
     * webhooks owned by other corps on the same install — they may be run
     * by unrelated directors with separate moons/wallets that shouldn't
     * receive this corp's alerts.
     *
     * Event notifications (event_created/started/completed) are GLOBAL and
     * should NOT use this helper — they fire to every enabled subscribed
     * webhook regardless of corporation_id.
     *
     * Individual tax notifications (tax_reminder / tax_invoice / tax_overdue)
     * can pass the miner's corporation_id as `$extraCorpId` to ALSO include
     * webhooks bound to that corp — letting each mining-group director see
     * their own members' notifications in their own Discord channel, while
     * the admin (corp_id=NULL) + the tax program corp continue to see all.
     * Broadcast tax notifications (tax_generated, tax_announcement) should
     * NOT pass extraCorpId — those are admin-only.
     *
     * @param  string   $eventType     Webhook subscription column (e.g. 'moon_arrival')
     * @param  int|null $extraCorpId   Optional additional corp ID to include in the OR-clause
     * @return Collection<WebhookConfiguration>
     */
    protected function getMoonOwnerScopedWebhooks(string $eventType, ?int $extraCorpId = null): Collection
    {
        $moonOwnerCorpId = app(SettingsManagerService::class)->getTaxProgramCorporationId();

        if ($moonOwnerCorpId === null) {
            Log::warning("WebhookDispatchTrait: moon_owner_corporation_id is not configured — '{$eventType}' will only fire for global (NULL corp) webhooks" . ($extraCorpId ? " or miner corp {$extraCorpId}" : ""));
        }

        $allEnabled = WebhookConfiguration::enabled()->forEvent($eventType)->get();

        Log::info("WebhookDispatchTrait::getMoonOwnerScopedWebhooks('{$eventType}')", [
            'moon_owner_corp_id' => $moonOwnerCorpId,
            'extra_corp_id' => $extraCorpId,
            'enabled_subscribed_count' => $allEnabled->count(),
            'candidates' => $allEnabled->map(fn($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'corporation_id' => $w->corporation_id,
            ])->toArray(),
        ]);

        $matched = $allEnabled->filter(function ($webhook) use ($moonOwnerCorpId, $extraCorpId) {
            if ($webhook->corporation_id === null) {
                return true; // Global webhook — always included
            }
            $webhookCorpId = (int) $webhook->corporation_id;
            if ($moonOwnerCorpId !== null && $webhookCorpId === $moonOwnerCorpId) {
                return true; // Tax program corp — always included
            }
            if ($extraCorpId !== null && $webhookCorpId === $extraCorpId) {
                return true; // Miner's own corp (individual tax notifications only)
            }
            return false;
        });

        Log::info("WebhookDispatchTrait::getMoonOwnerScopedWebhooks('{$eventType}') matched " . $matched->count() . " webhook(s) after corp scope filter", [
            'matched_ids' => $matched->pluck('id')->toArray(),
            'extra_corp_applied' => $extraCorpId !== null,
        ]);

        return $matched;
    }

    /**
     * Get the configured Tax Program / Moon Owner corporation's display name
     * for notification footers. Returns 'Corporation' if unconfigured or the
     * corp row is missing.
     *
     * @return string
     */
    protected function getCorpName(): string
    {
        $corpId = app(SettingsManagerService::class)->getTaxProgramCorporationId();

        if (!$corpId) {
            return 'Corporation';
        }

        return DB::table('corporation_infos')
            ->where('corporation_id', $corpId)
            ->value('name') ?? 'Corporation';
    }
}
