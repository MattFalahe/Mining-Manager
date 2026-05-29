<?php

namespace MiningManager\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Moon\MetenoxCargoService;
use MiningManager\Services\Notification\NotificationService;
use MiningManager\Services\Pricing\PriceProviderService;

/**
 * Scan every Metenox MoonMaterialBay for cross-up transitions above the
 * configured fill-% threshold (default 85). Fires one
 * `metenox_cargo_full` notification per crossing; latch in
 * `metenox_cargo_alert_state` prevents repeat fires while still over
 * threshold, and resets implicitly when cargo gets pulled (fill drops
 * below threshold).
 *
 * Read-only with respect to ESI/SeAT data — uses SeAT's existing
 * `corporation_assets` mirror; no new polling. The only writes are to
 * MM's own `metenox_cargo_alert_state` dedup table and the notification
 * dispatch chain.
 *
 * Schedule: every 5 minutes (configured in ScheduleSeeder). Each run is
 * O(drills × cargo_rows) — modest for any realistic install.
 *
 * Cluster-safe via Cache::lock with a 10-min TTL — covers the worst-case
 * runtime if pricing is slow.
 */
class ScanMetenoxCargoFillCommand extends Command
{
    protected $signature = 'mining-manager:scan-metenox-cargo-fill';

    protected $description = 'Scan Metenox MoonMaterialBays and dispatch metenox_cargo_full notifications on threshold transitions';

    public function handle(): int
    {
        $lock = Cache::lock('mining-manager:scan-metenox-cargo-fill', 600);
        if (!$lock->get()) {
            $this->info('Another scan instance is already running; skipping this tick.');
            return self::SUCCESS;
        }

        try {
            return $this->doScan();
        } finally {
            $lock->release();
        }
    }

    private function doScan(): int
    {
        $settings = app(SettingsManagerService::class);

        // Threshold is operator-configurable; default 85% per the v2.0.1
        // design discussion. Clamp to [50, 99] to keep operators from
        // accidentally disabling the alert (0%) or over-tightening to a
        // value the bay can't realistically hit due to integer rounding.
        $thresholdPct = (float) $settings->getSetting('notifications.metenox_cargo_full_threshold_pct', 85);
        $thresholdPct = max(50.0, min(99.0, $thresholdPct));

        // Single-corp scope: Moon Owner Corporation only. Matches the
        // Metenox Cargo page narrowing — drills owned by other configured
        // (member) corps are intentionally not scanned so the notification
        // and the page show the same set of drills. If multi-moon-owner
        // installs ever emerge as a real use case, revisit both surfaces
        // together so they stay aligned.
        $moonOwnerCorpId = $settings->getSetting('general.moon_owner_corporation_id');
        if (!$moonOwnerCorpId) {
            $this->info('Moon Owner Corporation not configured; nothing to scan. Set it in Settings -> General.');
            return self::SUCCESS;
        }
        $moonOwnerCorpId = (int) $moonOwnerCorpId;

        $cargoService = app(MetenoxCargoService::class);
        $notifyService = app(NotificationService::class);

        $totalDrills    = 0;
        $totalAlerts    = 0;
        $totalSkipped   = 0;
        $totalErrors    = 0;

        foreach ([$moonOwnerCorpId] as $corpId) {
            $summaries = $cargoService->summaryForCorporation($corpId);
            if ($summaries->isEmpty()) {
                continue;
            }

            // Cache the corp name once per corp for the notification payload.
            $corpName = DB::table('corporation_infos')
                ->where('corporation_id', $corpId)
                ->value('name') ?? ('Corporation #' . $corpId);

            foreach ($summaries as $row) {
                $totalDrills++;
                $structureId = (int) $row->structure_id;
                $currentPct  = (float) $row->fill_pct;

                // Load existing latch state (if any) — default zeros for
                // first-time observation.
                $latch = DB::table('metenox_cargo_alert_state')
                    ->where('structure_id', $structureId)
                    ->first();
                $previousPct = $latch ? (float) $latch->last_fill_pct : 0.0;

                $crossedUp = ($currentPct >= $thresholdPct) && ($previousPct < $thresholdPct);

                // Always update the latch with the current observation —
                // even when no notification fires — so the next tick sees
                // an accurate "previous" reading.
                DB::table('metenox_cargo_alert_state')->updateOrInsert(
                    ['structure_id' => $structureId],
                    [
                        'corporation_id' => $corpId,
                        'last_fill_pct'  => $currentPct,
                        // last_alerted_at + fill_pct_at_alert are only
                        // touched when we actually fire (below).
                        'updated_at'     => Carbon::now(),
                        'created_at'     => $latch ? ($latch->created_at ?? Carbon::now()) : Carbon::now(),
                    ]
                );

                if (!$crossedUp) {
                    $totalSkipped++;
                    continue;
                }

                // Lock the latch row to alerted state before dispatching so
                // a concurrent tick (or notification retry) can't fire twice.
                DB::table('metenox_cargo_alert_state')
                    ->where('structure_id', $structureId)
                    ->update([
                        'last_alerted_at'    => Carbon::now(),
                        'fill_pct_at_alert'  => $currentPct,
                        'updated_at'         => Carbon::now(),
                    ]);

                try {
                    // Compute the ISK value of the bay contents using the
                    // moon-material-only filter that forCorporation() returns.
                    // Best-effort: if pricing fails the notification still
                    // fires, just without an ISK figure.
                    $estimatedIsk = null;
                    try {
                        $cargoRows = $cargoService->forCorporation($corpId)
                            ->where('structure_id', $structureId);
                        $typeIds = $cargoRows->pluck('type_id')->unique()->all();
                        if (!empty($typeIds)) {
                            $prices = app(PriceProviderService::class)->getPrices($typeIds);
                            $isk = 0.0;
                            foreach ($cargoRows as $cr) {
                                $unit = $prices[(int) $cr->type_id] ?? null;
                                if ($unit !== null) {
                                    $isk += $unit * (float) $cr->total_quantity;
                                }
                            }
                            $estimatedIsk = $isk > 0 ? $isk : null;
                        }
                    } catch (\Throwable $e) {
                        Log::info('[MM] Metenox cargo fill: pricing failed, firing without ISK', [
                            'structure_id' => $structureId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $cargoUrl = url(route('mining-manager.moon.metenox-cargo', [], false));

                    $notifyService->sendMetenoxCargoFull([
                        'structure_id'      => $structureId,
                        'structure_name'    => $row->structure_name ?? ('Structure #' . $structureId),
                        'corporation_id'    => $corpId,
                        'corporation_name'  => $corpName,
                        'fill_pct'          => $currentPct,
                        'bay_used_m3'       => (float) $row->total_m3,
                        'bay_capacity_m3'   => (float) $row->capacity_m3,
                        'estimated_isk'     => $estimatedIsk,
                        'cargo_url'         => $cargoUrl,
                        // system_name is best-effort — solar_systems lookup
                        // (also LEFT JOIN-safe in case SDE doesn't have it).
                        'system_name'       => DB::table('solar_systems')
                            ->where('system_id', $row->system_id)
                            ->value('name') ?? ('System ' . ($row->system_id ?? '?')),
                    ]);

                    $totalAlerts++;
                    $this->line(sprintf(
                        '  Fired alert for structure %d (%s) — %s%% full',
                        $structureId,
                        $row->structure_name ?? 'Unknown',
                        number_format($currentPct, 1)
                    ));
                } catch (\Throwable $e) {
                    $totalErrors++;
                    Log::warning('[MM] Metenox cargo fill: notification dispatch failed', [
                        'structure_id' => $structureId,
                        'corp_id'      => $corpId,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info(sprintf(
            'Metenox cargo scan complete: drills=%d fired=%d skipped=%d errors=%d threshold=%s%%',
            $totalDrills,
            $totalAlerts,
            $totalSkipped,
            $totalErrors,
            number_format($thresholdPct, 1)
        ));

        return self::SUCCESS;
    }
}
