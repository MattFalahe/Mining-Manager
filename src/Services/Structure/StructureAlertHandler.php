<?php

namespace MiningManager\Services\Structure;

use Illuminate\Support\Facades\Log;
use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Notification\NotificationService;
use Carbon\Carbon;

/**
 * Subscriber for Structure Manager's `structure.alert.*` events on Manager
 * Core's EventBus. Filters incoming alerts to structures that are currently
 * running a moon extraction and dispatches either:
 *
 *   - extraction_at_risk  — fuel_critical / shield_reinforced /
 *                           armor_reinforced / hull_reinforced flavors
 *                           (extraction is still recoverable)
 *
 *   - extraction_lost     — destroyed flavor
 *                           (structure destroyed, chunk gone for good)
 *
 * Only fires when both Manager Core AND Structure Manager are installed.
 * Registered as a capability via PluginBridge in the service provider
 * (see MiningManagerServiceProvider::boot), and the EventBus subscription
 * is upserted on every boot (idempotent updateOrCreate semantics).
 *
 * Idempotency: five boolean columns on `moon_extractions` (one per flavor)
 * dedup repeat events for the same extraction. SM polls every 10 min;
 * without the latch, every poll would re-fire the notification until fuel
 * is topped off or the timer clears.
 *
 * Filtering precedence (fail-fast):
 *  1. Event name must match known flavor (defensive — we subscribed to
 *     `structure.alert.*` which could include future SM events we don't
 *     yet handle)
 *  2. Payload must contain a structure_id
 *  3. Extraction must exist for this structure, active status, within
 *     plausible time window
 *  4. Dedup flag must be unset
 *  5. NotificationService handles per-webhook subscription filtering
 *     + corp scoping via the structure_corporation_id in data
 */
class StructureAlertHandler
{
    /**
     * Highest schema_version of `structure.alert.*` envelope MM understands.
     *
     * SM's AlertEventEnvelope::SCHEMA_VERSION currently emits 1. If SM ever
     * ships v2 with breaking field changes, MM will defensively skip those
     * events until this constant is bumped. The bump is intentional code
     * review: verify the new shape is compatible with our embed builders +
     * dedup keys, then raise the cap.
     *
     * Forward-compat over silent-misrender: older MM with newer SM logs and
     * skips rather than rendering an embed missing fields v2 added.
     */
    const SUPPORTED_SCHEMA_VERSION = 1;

    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Entry point — called by Manager Core's EventBus via the PluginBridge
     * capability `structure.notify_alert`. Signature must match EventBus
     * dispatch: (eventName, publisher, payload).
     *
     * @param string $eventName e.g. 'structure.alert.fuel_critical'
     * @param string $publisher e.g. 'structure-manager'
     * @param array  $payload   Event data from SM
     * @return void
     */
    public function handle(string $eventName, string $publisher, array $payload): void
    {
        // Defensive schema_version check. AlertEventEnvelope from SM tags every
        // payload with schema_version=1 today. If SM ever ships a v2 with
        // breaking changes, we want to fail loud and skip rather than silently
        // render embeds with missing fields. Bumping the supported version
        // is intentional code review (verify the v2 changes are compatible
        // with our embed builders, then bump SUPPORTED_SCHEMA_VERSION).
        //
        // schema_version=0 (i.e. missing) means the publisher is pre-envelope
        // which isn't possible with current SM but we accept it for backward
        // compat with any test fixtures or third-party publishers.
        $schemaVersion = (int) ($payload['schema_version'] ?? 0);
        if ($schemaVersion > self::SUPPORTED_SCHEMA_VERSION) {
            Log::warning("[MM] StructureAlertHandler: ignoring {$eventName} — schema_version={$schemaVersion} is newer than supported v" . self::SUPPORTED_SCHEMA_VERSION, [
                'publisher' => $publisher,
                'event_id' => $payload['event_id'] ?? null,
            ]);
            return;
        }

        // Map event name → flavor key. Anything unrecognised gets logged and
        // discarded rather than fed as an unknown flavor downstream.
        $flavor = $this->resolveFlavorFromEvent($eventName);
        if ($flavor === null) {
            Log::debug("[MM] StructureAlertHandler: ignoring unrecognised event '{$eventName}' from {$publisher}");
            return;
        }

        $structureId = (int) ($payload['structure_id'] ?? 0);
        if (!$structureId) {
            Log::warning("[MM] StructureAlertHandler: event '{$eventName}' missing structure_id in payload", [
                'publisher' => $publisher,
                'payload_keys' => array_keys($payload),
            ]);
            return;
        }

        // Find the active extraction on this structure.
        // Window: chunk_arrival within the last 55h covers the full plugin
        // lifecycle (chunk_arrival → auto_fracture +3h → ready +48h →
        // unstable +2h = 53h, plus some slack). Cancelled/expired are out.
        $extraction = MoonExtraction::query()
            ->where('structure_id', $structureId)
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->where('chunk_arrival_time', '>', Carbon::now()->subHours(55))
            ->orderBy('chunk_arrival_time', 'desc')
            ->first();

        if (!$extraction) {
            // Common case when SM fires for a structure we don't actively
            // track (no moon extraction) — not an error, just a no-op.
            Log::debug("[MM] StructureAlertHandler: no active extraction for structure {$structureId}; ignoring {$eventName}");
            return;
        }

        // Special-case fuel_recovered: SM fires this when a refinery's fuel
        // status returns to good (operator topped it off). We use it to clear
        // our local fuel_critical dedup latch so a future re-critical fires
        // a fresh notification instead of being silently swallowed by the
        // sticky latch.
        //
        // No notification dispatch — this is purely state cleanup. SM's own
        // webhook fires the "structure refueled" message if the operator
        // wants the all-clear announcement; MM's role is just to keep its
        // own bookkeeping in sync with SM's lifecycle.
        if ($flavor === 'fuel_recovered') {
            $resetCount = MoonExtraction::where('id', $extraction->id)
                ->where('alert_fuel_critical_sent', true)
                ->update(['alert_fuel_critical_sent' => false]);

            if ($resetCount > 0) {
                Log::info("[MM] StructureAlertHandler: cleared alert_fuel_critical_sent latch for extraction {$extraction->id} (structure {$structureId}) after fuel_recovered event — re-critical events will fire fresh notifications");
            } else {
                Log::debug("[MM] StructureAlertHandler: fuel_recovered for extraction {$extraction->id} but latch was already clear — no-op");
            }
            return;
        }

        // Atomic claim of the dedup latch.
        //
        // Previously this was a non-atomic check-then-update:
        //
        //   if ($extraction->{$dedupCol}) return;
        //   ... dispatch ...
        //   $extraction->update([$dedupCol => true]);
        //
        // If SM published the same event twice rapidly (or two queue
        // workers picked up the same event in parallel), both invocations
        // could read the latch as `false`, both dispatch, and both flip
        // the latch — duplicate Discord pings for one real event.
        //
        // The new pattern is a compare-and-swap via the database:
        //
        //   UPDATE moon_extractions SET <flag> = 1
        //     WHERE id = ? AND <flag> = 0
        //
        // Returns the count of rows updated. Only the worker that flips
        // the flag from 0→1 gets back 1; everyone else gets 0 and bails.
        // This is safe under any level of concurrency the database can
        // handle (uses row-level locking on InnoDB).
        //
        // If dispatch fails (skipped due to MC/SM missing, or throws),
        // we roll the claim back so the next cron tick retries — this
        // preserves the pre-fix behavior of "don't eat the latch on a
        // failed dispatch" while gaining race safety.
        $dedupCol = $this->getDedupColumnForFlavor($flavor);

        $claimed = MoonExtraction::where('id', $extraction->id)
            ->where($dedupCol, false)
            ->update([$dedupCol => true]);

        if ($claimed === 0) {
            // Either another worker already claimed this (race we successfully
            // dodged) or the latch was already true from a prior run.
            Log::debug("[MM] StructureAlertHandler: {$flavor} latch already claimed for extraction {$extraction->id}; skipping duplicate");
            return;
        }

        // We won the claim. Refresh local model so subsequent reads see
        // the updated state (e.g. anything that reads $extraction->{$dedupCol}
        // downstream — currently nothing does, but defensive).
        $extraction->refresh();

        // Build the notification data payload. Most fields are lifted from
        // the SM event payload (structure metadata); we enrich with
        // extraction/moon info.
        $data = $this->buildNotificationData($extraction, $flavor, $payload);

        // Dispatch — destroyed flavor uses the lost wrapper (different
        // webhook toggle, different embed); all other flavors share at_risk.
        try {
            if ($flavor === 'destroyed') {
                $result = $this->notificationService->sendExtractionLost($data);
            } else {
                $data['alert_flavor'] = $flavor;
                $result = $this->notificationService->sendExtractionAtRisk($data);
            }

            // Roll back the claim if dispatch was skipped (MC/SM unexpectedly
            // unavailable at dispatch time despite being present at boot —
            // should never happen but defensive). Operator can fix the
            // underlying issue and the next cron tick will retry naturally.
            $skipped = isset($result['skipped']) && $result['skipped'];
            if ($skipped) {
                MoonExtraction::where('id', $extraction->id)->update([$dedupCol => false]);
                Log::info("[MM] StructureAlertHandler: {$flavor} dispatch skipped for extraction {$extraction->id} — claim rolled back, will retry on next event", [
                    'reason' => $result['reason'] ?? 'unknown',
                ]);
            } else {
                Log::info("[MM] StructureAlertHandler: fired {$flavor} for extraction {$extraction->id} (structure {$structureId})");
            }
        } catch (\Throwable $e) {
            // Roll back on exception too. Means a permanently-broken webhook
            // could re-fire on every cron tick until fixed — acceptable
            // trade-off vs leaving a stuck latch that requires manual reset.
            MoonExtraction::where('id', $extraction->id)->update([$dedupCol => false]);
            Log::error("[MM] StructureAlertHandler: error firing {$flavor} for extraction {$extraction->id}: " . $e->getMessage() . ' — claim rolled back', [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Map SM event name → our internal flavor key.
     *
     * @param string $eventName
     * @return string|null null = not a flavor we handle (yet)
     */
    protected function resolveFlavorFromEvent(string $eventName): ?string
    {
        return match ($eventName) {
            'structure.alert.fuel_critical' => 'fuel_critical',
            'structure.alert.fuel_recovered' => 'fuel_recovered',
            'structure.alert.shield_reinforced' => 'shield_reinforced',
            'structure.alert.armor_reinforced' => 'armor_reinforced',
            'structure.alert.hull_reinforced' => 'hull_reinforced',
            'structure.alert.destroyed' => 'destroyed',
            default => null,
        };
    }

    /**
     * Pick the boolean column on moon_extractions that gates this flavor.
     *
     * @param string $flavor
     * @return string
     */
    protected function getDedupColumnForFlavor(string $flavor): string
    {
        return match ($flavor) {
            'fuel_critical' => 'alert_fuel_critical_sent',
            'shield_reinforced' => 'alert_shield_reinforced_sent',
            'armor_reinforced' => 'alert_armor_reinforced_sent',
            'hull_reinforced' => 'alert_hull_reinforced_sent',
            'destroyed' => 'alert_destroyed_sent',
            default => 'alert_fuel_critical_sent', // unreachable in practice
        };
    }

    /**
     * Build the $data array passed to NotificationService. Merges SM's
     * structure metadata with MM-side enrichment (moon/extraction).
     *
     * @param MoonExtraction $extraction
     * @param string $flavor
     * @param array $payload SM event payload
     * @return array
     */
    protected function buildNotificationData(MoonExtraction $extraction, string $flavor, array $payload): array
    {
        // Ensure display names are resolved (batch-load or accessor).
        $extraction->loadMissing('corporation');

        // Extraction URL for Discord "View Extraction" links.
        // Route signature: /mining-manager/moon/{extraction} → mining-manager.moon.show
        $extractionUrl = null;
        try {
            $extractionUrl = route('mining-manager.moon.show', $extraction->id);
        } catch (\Throwable $e) {
            // Route may not exist in every install — non-fatal, omit link
        }

        $data = [
            // SM-sourced (envelope contract fields)
            'structure_id'            => (int) ($payload['structure_id'] ?? $extraction->structure_id),
            'structure_corporation_id' => (int) ($payload['corporation_id'] ?? $extraction->corporation_id),
            'structure_name'          => $payload['structure_name'] ?? $extraction->structure_name,
            'structure_type'          => $payload['structure_type'] ?? null,
            'system_name'             => $payload['system_name'] ?? null,
            'system_security'         => isset($payload['system_security']) ? (float) $payload['system_security'] : null,

            // Envelope severity ('info' | 'warning' | 'critical') — passes through
            // to the embed builder for finer color tier than flavor-only mapping.
            'severity'                => $payload['severity'] ?? null,

            // SM Structure Board deeplink — surfaced as a secondary "View on
            // Structure Board" button in the embed alongside the MM extraction
            // link, so operators can pivot between the two plugins quickly.
            'structure_board_url'     => $payload['url'] ?? null,

            // Idempotency key — included in the data payload purely for
            // diagnostics (echoed in logs by NotificationService::send).
            // Subscribers should not branch on it.
            'event_id'                => $payload['event_id'] ?? null,

            // MM-sourced enrichment
            'moon_name'               => $extraction->moon_name,
            'estimated_value'         => (int) ($extraction->estimated_value ?? 0),
            'extraction_url'          => $extractionUrl,
        ];

        // Flavor-specific fields
        if ($flavor === 'fuel_critical') {
            $data['days_remaining'] = $payload['days_remaining'] ?? null;
            $data['hours_remaining'] = $payload['hours_remaining'] ?? null;
            $data['fuel_expires'] = $this->formatIso($payload['fuel_expires'] ?? null);
        } elseif (in_array($flavor, ['shield_reinforced', 'armor_reinforced', 'hull_reinforced'], true)) {
            $data['timer_ends_at'] = $this->formatIso($payload['timer_ends_at'] ?? null);

            // Hostile force context — only meaningful for tactical (combat) timers.
            // SM populates these from the underlying ESI notification (Structure
            // Under Attack / Structure Lost Shields / etc). attacker_summary is
            // a pre-formatted human-readable string (e.g. "Goonswarm [GOONS] —
            // Pilot Name [Corp Name]"); attacker_corporation_name is the bare
            // corp name. We pass both so the embed builder picks the richest one
            // available without losing the simpler form.
            $data['attacker_corporation_name'] = $payload['attacker_corporation_name'] ?? null;
            $data['attacker_summary']          = $payload['attacker_summary'] ?? null;
        } elseif ($flavor === 'destroyed') {
            $data['destroyed_at'] = $this->formatIso($payload['destroyed_at'] ?? null);
            $data['detection_source'] = $payload['detection_source'] ?? 'unknown';
            $data['final_timer_result'] = $payload['final_timer_result'] ?? null;
            $data['killmail_url'] = $payload['killmail_url'] ?? null;
            // For extraction_lost, "chunk_value" reflects what was lost —
            // use estimated_value_pre_arrival (the snapshot at chunk
            // arrival) if present, otherwise current estimated_value.
            $data['chunk_value'] = (int) ($extraction->estimated_value_pre_arrival ?? $extraction->estimated_value ?? 0);

            // Killer attribution — same fields as reinforced flavors. Useful
            // for post-mortem reporting ("we lost Athanor X to Corp Y").
            $data['attacker_corporation_name'] = $payload['attacker_corporation_name'] ?? null;
            $data['attacker_summary']          = $payload['attacker_summary'] ?? null;
        }

        return $data;
    }

    /**
     * Normalise ISO8601 → human-readable UTC display. Returns null if input
     * is null or unparseable.
     *
     * @param mixed $iso
     * @return string|null
     */
    protected function formatIso($iso): ?string
    {
        if (!$iso) return null;
        try {
            return Carbon::parse($iso)->format('Y-m-d H:i') . ' UTC';
        } catch (\Throwable $e) {
            return (string) $iso;
        }
    }
}
