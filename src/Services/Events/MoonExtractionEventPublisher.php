<?php

namespace MiningManager\Services\Events;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MoonExtraction;

/**
 * Publishes moon-extraction lifecycle events to Manager Core's EventBus.
 *
 * Three events, fired exactly once per extraction per lifecycle stage by the
 * mining:scan-extraction-events cron command (dedup is enforced via the
 * `moon_extraction_event_log` table — each stage column is set to NOW() when
 * its event is published, so re-runs are idempotent).
 *
 * Event catalog (schema_version = 1):
 *   - mining.extraction_ready    — chunk has fractured, 48h fleet-able window opens
 *   - mining.extraction_unstable — final 2h capital-safety window before expiry
 *   - mining.extraction_expired  — window closed, no more mining
 *
 * Consumers (SeAT Broadcast in v2.0.0) use these to populate FC Opportunities
 * boards, fire pre-extraction reminder pings, and dismiss expired entries.
 *
 * Standalone-safe via the Topics::publish class_exists guard inside each call:
 * with Manager Core absent, every method returns false and the publisher is
 * a no-op.
 */
class MoonExtractionEventPublisher
{
    public const SCHEMA_VERSION = 1;
    public const SOURCE_PLUGIN  = 'mining-manager';

    /**
     * Fired when an extraction transitions to the ready window
     * (chunk arrived + fractured, 48h of fleet-able mining begins).
     */
    public static function publishReady(MoonExtraction $extraction): bool
    {
        return self::publish('mining.extraction_ready', $extraction);
    }

    /**
     * Fired when an extraction transitions to the unstable window
     * (48h-50h after fracture — the final 2h capital-safety alert).
     */
    public static function publishUnstable(MoonExtraction $extraction): bool
    {
        return self::publish('mining.extraction_unstable', $extraction);
    }

    /**
     * Fired when an extraction has expired (past 50h after fracture).
     * Cleanup signal — consumers should drop the extraction from active views.
     */
    public static function publishExpired(MoonExtraction $extraction): bool
    {
        return self::publish('mining.extraction_expired', $extraction);
    }

    /**
     * Publish a moon-extraction lifecycle event via Manager Core's Topics
     * facade. Returns true on success, false when MC is absent or the
     * publish failed. Never throws.
     */
    protected static function publish(string $topic, MoonExtraction $extraction): bool
    {
        if (! class_exists(\ManagerCore\Topics::class)) {
            return false;
        }

        try {
            $payload = self::buildPayload($extraction);

            // Topics::publish handles publisher attribution + idempotency-key
            // composition from the template registered in MC/Topics.php.
            $result = \ManagerCore\Topics::publish($topic, $payload);

            return $result !== null;
        } catch (\Throwable $e) {
            Log::warning(
                '[MiningManager] MoonExtractionEventPublisher: failed to publish '
                . $topic . ' for extraction ' . $extraction->id . ': ' . $e->getMessage()
            );

            return false;
        }
    }

    /**
     * Build the full payload for an extraction-lifecycle event. Every event
     * gets the same shape so subscribers can branch on event name only.
     */
    protected static function buildPayload(MoonExtraction $extraction): array
    {
        // Ensure display names are populated (cheap when already set).
        if (! isset($extraction->attributes['moon_name'])) {
            MoonExtraction::loadDisplayNames(collect([$extraction]));
        }

        $fractureTime   = $extraction->getFractureTime();
        $unstableStart  = $extraction->getUnstableStartTime();
        $expiryTime     = $extraction->getExpiryTime();

        // Deep link to the moon-extraction detail page (per-extraction route,
        // gated by `mining-manager.member` permission). Best-effort: if the
        // route isn't registered (e.g. someone publishes from a Tinker
        // shell with the routes file not loaded) we just omit the URL.
        $detailUrl = null;
        try {
            $detailUrl = route('mining-manager.moon.show', $extraction->id);
        } catch (\Throwable $e) {
            $detailUrl = null;
        }

        return [
            // Core identity
            'extraction_id'         => (int) $extraction->id,
            'moon_id'               => $extraction->moon_id !== null ? (int) $extraction->moon_id : null,
            'moon_name'             => $extraction->moon_name ?? null,
            'structure_id'          => $extraction->structure_id !== null ? (int) $extraction->structure_id : null,
            'structure_name'        => $extraction->structure_name ?? null,

            // Deep link — subscribers (notably SeAT Broadcast) surface this
            // as a "View in Mining Manager" button on their calendar/board.
            'url'                   => $detailUrl,

            // Visibility scoping (mirrors mining.jackpot_detected contract)
            'corporation_id'        => $extraction->corporation_id !== null ? (int) $extraction->corporation_id : null,
            'role_id'               => null,

            // Lifecycle timestamps (the consumer's calendar / FC ops board
            // anchors against window_closes_at; ready_at + unstable_at give
            // the full picture for richer renders).
            'chunk_arrival_time'    => optional($extraction->chunk_arrival_time)?->toIso8601String(),
            'fractured_at'          => optional($fractureTime)?->toIso8601String(),
            'window_opens_at'       => optional($fractureTime)?->toIso8601String(),
            'unstable_starts_at'    => optional($unstableStart)?->toIso8601String(),
            'window_closes_at'      => optional($unstableStart)?->toIso8601String(),
            'expires_at'            => optional($expiryTime)?->toIso8601String(),

            // Useful metadata
            'auto_fractured'        => (bool) $extraction->auto_fractured,
            'is_jackpot'            => (bool) $extraction->is_jackpot,
            'estimated_value'       => (float) ($extraction->display_estimated_value ?? $extraction->estimated_value ?? 0),
            'status'                => (string) ($extraction->getEffectiveStatus() ?? $extraction->status ?? 'unknown'),

            // Pinned envelope fields (Topics will overwrite the source_plugin
            // via the registered publisher, but we include them so consumers
            // see a consistent shape during local debugging too).
            'source_plugin'         => self::SOURCE_PLUGIN,
            'schema_version'        => self::SCHEMA_VERSION,
            'timestamp'             => Carbon::now()->utc()->toIso8601String(),
        ];
    }
}
