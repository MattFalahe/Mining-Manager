<?php

namespace MiningManager\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Notification\NotificationService;
use Carbon\Carbon;

/**
 * Mining Manager's Manager Core fast-poll notification handler.
 *
 * Called by MC's EsiNotificationRegistry when its fast-poll discovers a new
 * `MoonminingExtractionStarted` notification. Fires the `extraction_started`
 * notification immediately — far ahead of the SeAT-native path, which waits
 * on the corp moon-extraction ESI endpoint's ~30 min cache.
 *
 * Contract (duck-typed, same as Structure Manager's handler): the
 * $notification has ->type, ->corporation_id, ->timestamp, ->text,
 * ->parsed_data (array), ->notification_id. MC dispatches each notification
 * exactly once (dedup on notification_id + dispatched flag), so this handler
 * doesn't need its own re-dispatch guard.
 */
class MoonNotificationHandler
{
    /**
     * CCP notification types this handler subscribes to via MC's registry.
     */
    public static function registeredTypes(): array
    {
        return ['MoonminingExtractionStarted'];
    }

    /**
     * Registry entry point.
     */
    public static function handle($notification): void
    {
        try {
            (new self())->process($notification);
        } catch (\Throwable $e) {
            Log::error('[Mining Manager] MoonNotificationHandler failed: ' . $e->getMessage());
        }
    }

    private function process($notification): void
    {
        if (($notification->type ?? null) !== 'MoonminingExtractionStarted') {
            return;
        }

        $settings = app(SettingsManagerService::class);

        // Respect the moon-tracking feature flag — same gate the cron uses.
        $features = $settings->getFeatureFlags();
        if (!($features['enable_moon_tracking'] ?? true)) {
            return;
        }

        // Corp scope: only our moon-owner corp. MC polls a multi-corp key pool,
        // so a notification may belong to a corp we don't run moons for.
        $corpId = (int) ($notification->corporation_id ?? 0);
        $moonOwner = $settings->getTaxProgramCorporationId();
        if ($moonOwner !== null && $corpId !== 0 && $corpId !== $moonOwner) {
            return;
        }

        $data = is_array($notification->parsed_data ?? null) ? $notification->parsed_data : [];

        $structureId = isset($data['structureID']) ? (int) $data['structureID'] : null;
        if (!$structureId) {
            return;
        }
        $moonId = isset($data['moonID']) ? (int) $data['moonID'] : null;

        // chunk arrival = CCP readyTime (Windows FILETIME, 100-ns ticks since 1601).
        $arrival = $this->filetimeToCarbon($data['readyTime'] ?? null);

        // Names — the notification usually carries structureName; fall back to DB.
        $structureName = !empty($data['structureName'])
            ? $data['structureName']
            : (DB::table('universe_structures')->where('structure_id', $structureId)->value('name')
                ?? "Structure {$structureId}");

        $moonName = $moonId
            ? (DB::table('moons')->where('moon_id', $moonId)->value('name') ?? "Moon {$moonId}")
            : 'Unknown Moon';

        // Defense-in-depth dedup: if the matching live extraction row already
        // exists (ESI poll beat the notification), claim its
        // extraction_started_sent latch so the SeAT-native pass can't also
        // fire if the operator flips modes mid-cycle. A refinery runs at most
        // one active extraction, so structure_id + extracting + future arrival
        // uniquely identifies it without fragile exact-timestamp matching.
        $extractionUrl = null;
        $row = MoonExtraction::where('structure_id', $structureId)
            ->where('status', 'extracting')
            ->where('chunk_arrival_time', '>', Carbon::now())
            ->orderByDesc('chunk_arrival_time')
            ->first();

        if ($row) {
            $claimed = MoonExtraction::where('id', $row->id)
                ->where('extraction_started_sent', false)
                ->update(['extraction_started_sent' => true]);
            if ($claimed === 0) {
                // Already notified through the row (cron got here first).
                return;
            }
            $extractionUrl = rtrim(config('app.url', ''), '/') . '/mining-manager/moon/' . $row->id;
        }

        $timeUntil = ($arrival && $arrival->isFuture())
            ? Carbon::now()->diffForHumans($arrival, ['parts' => 2, 'syntax' => Carbon::DIFF_ABSOLUTE])
            : null;

        app(NotificationService::class)->sendExtractionStarted(array_filter([
            'moon_name' => $moonName,
            'structure_name' => $structureName,
            'chunk_arrival_time' => $arrival ? $arrival->format('Y-m-d H:i') : null,
            'time_until_arrival' => $timeUntil,
            'extraction_url' => $extractionUrl,
        ], fn ($v) => $v !== null));

        Log::info(sprintf(
            '[Mining Manager] fast-poll extraction_started fired for structure %d (notification #%s)',
            $structureId,
            $notification->notification_id ?? '?'
        ));
    }

    /**
     * Convert a CCP FILETIME value (100-ns ticks since 1601-01-01 UTC) to a
     * Carbon instance. Returns null for empty/non-numeric input.
     */
    private function filetimeToCarbon($filetime): ?Carbon
    {
        if (!$filetime || !is_numeric($filetime)) {
            return null;
        }
        $unixSeconds = (int) (((int) $filetime) / 10_000_000) - 11_644_473_600;
        return $unixSeconds > 0 ? Carbon::createFromTimestamp($unixSeconds, 'UTC') : null;
    }
}
