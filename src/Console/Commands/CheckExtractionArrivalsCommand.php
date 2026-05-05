<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Moon\MoonExtractionService;
use MiningManager\Services\Configuration\SettingsManagerService;
use Carbon\Carbon;

/**
 * Fires moon_arrival webhook notifications based on the stored
 * chunk_arrival_time — NOT on ESI state changes.
 *
 * Rationale: the previous ESI-polling-based notification path was
 * fragile. The import loop's determineStatus() would set status
 * directly to 'ready' whenever chunk_arrival_time had passed,
 * bypassing the transition-detection code that fired notifications.
 *
 * This command takes a simpler approach: any extraction whose
 * chunk_arrival_time has passed and hasn't been notified yet gets
 * a notification fired. Idempotent via notification_sent flag.
 *
 * Runs every minute so notifications arrive within ~60s of the
 * actual chunk arrival time, regardless of when ESI refreshes.
 */
class CheckExtractionArrivalsCommand extends Command
{
    protected $signature = 'mining-manager:check-extraction-arrivals
                            {--hours-back=72 : Only consider arrivals within this many hours (prevents spam on historical data)}
                            {--limit=50 : Maximum notifications to dispatch per run}
                            {--unstable-warning-hours=2 : Fire moon_chunk_unstable this many hours before natural_decay_time}
                            {--dry-run : Show what would be notified without firing}';

    protected $description = 'Fire moon_arrival + moon_chunk_unstable notifications based on stored timestamps';

    protected MoonExtractionService $extractionService;
    protected SettingsManagerService $settingsService;

    public function __construct(
        MoonExtractionService $extractionService,
        SettingsManagerService $settingsService
    ) {
        parent::__construct();
        $this->extractionService = $extractionService;
        $this->settingsService = $settingsService;
    }

    public function handle(): int
    {
        $lock = Cache::lock('mining-manager:check-extraction-arrivals', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
            // Respect the feature flag — same check as update-extractions
            $features = $this->settingsService->getFeatureFlags();
            if (!($features['enable_moon_tracking'] ?? true)) {
                $this->info('Moon tracking feature disabled in settings. Skipping.');
                return Command::SUCCESS;
            }

            $now = Carbon::now();
            $hoursBack = (int) $this->option('hours-back');
            $limit = (int) $this->option('limit');
            $dryRun = (bool) $this->option('dry-run');
            $windowStart = $now->copy()->subHours($hoursBack);

            // Find extractions that have arrived but haven't been notified yet.
            // Filters:
            //  - chunk_arrival_time has passed (<= now)
            //  - within the hours-back window (prevents notifying for ancient data)
            //  - notification_sent = false (idempotent — already-notified rows skipped)
            //  - corporation_id matches moon owner (safety: don't notify for other corps)
            $moonOwnerCorpId = $this->settingsService->getTaxProgramCorporationId();

            $query = MoonExtraction::query()
                ->where('chunk_arrival_time', '<=', $now)
                ->where('chunk_arrival_time', '>=', $windowStart)
                ->where('notification_sent', false)
                // Skip cancelled extractions — detectCancellations() (called from
                // updateExtractionStatuses via the update-extractions cron) marks
                // rows whose director cancelled them in-game. No point firing a
                // "moon chunk ready" notification for a cancelled extraction.
                ->where('status', '!=', 'cancelled');

            if ($moonOwnerCorpId !== null) {
                $query->where('corporation_id', $moonOwnerCorpId);
            }

            $extractions = $query
                ->orderBy('chunk_arrival_time', 'asc')
                ->limit($limit)
                ->get();

            if ($extractions->isEmpty()) {
                $this->info("No extraction arrivals pending notification (checked window: last {$hoursBack}h).");
                return Command::SUCCESS;
            }

            $this->info("Found {$extractions->count()} extraction(s) needing arrival notification:");

            $dispatched = 0;
            $failed = 0;

            foreach ($extractions as $extraction) {
                $moonLabel = $extraction->moon_name ?? "Moon {$extraction->moon_id}";
                $this->line("  #{$extraction->id} — {$moonLabel} — arrived {$extraction->chunk_arrival_time->diffForHumans()}");

                if ($dryRun) {
                    $dispatched++;
                    continue;
                }

                try {
                    // Snapshot the value at arrival — ONCE. This is the arrival-time
                    // price of the chunk, locked in at the moment the chunk became
                    // minable. Separate from estimated_value which tracks current
                    // running value (may drift as prices change).
                    //
                    // Idempotent: only snapshots if not already set. The every-minute
                    // cron will typically hit this ≤ 60s after actual arrival, so the
                    // snapshot is essentially arrival-time pricing. Once set, no
                    // recalculation overrides it (see RecalculateExtractionValuesCommand).
                    if ($extraction->estimated_value_pre_arrival === null && $extraction->estimated_value > 0) {
                        $extraction->update([
                            'estimated_value_pre_arrival' => $extraction->estimated_value,
                        ]);
                        Log::info("CheckExtractionArrivalsCommand: snapshotted arrival value for extraction {$extraction->id}: " . number_format($extraction->estimated_value) . " ISK");
                    }

                    // Reuse the existing dispatch method (canonical notification
                    // builder used by updateExtractionStatuses too). Safer than
                    // duplicating the embed code.
                    //
                    // The service handles the notification_sent latch atomically
                    // (compare-and-swap UPDATE WHERE flag=false). Two implications:
                    //   1. We MUST NOT set the flag here. Pre-M3-fix the command
                    //      always set it post-dispatch — that overrode the service's
                    //      rollback-on-failure path, leaving notifications silently
                    //      lost on transient errors.
                    //   2. If the service silently bails (race lost — another worker
                    //      already claimed the latch), this counter increments
                    //      without an actual ping firing. Minor counter imprecision
                    //      vs the prior duplicate-ping bug it cures.
                    $this->extractionService->sendMoonArrivalNotification($extraction);

                    $dispatched++;

                    Log::info("CheckExtractionArrivalsCommand: fired moon_arrival notification for extraction {$extraction->id}", [
                        'moon_id' => $extraction->moon_id,
                        'moon_name' => $extraction->moon_name,
                        'structure_id' => $extraction->structure_id,
                        'chunk_arrival_time' => $extraction->chunk_arrival_time->toIso8601String(),
                        'minutes_late' => (int) $extraction->chunk_arrival_time->diffInMinutes($now, true),
                    ]);
                } catch (\Exception $e) {
                    $failed++;
                    $this->error("  Failed: {$e->getMessage()}");
                    Log::error("CheckExtractionArrivalsCommand: failed to fire notification for extraction {$extraction->id}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // ================================================================
            // PASS 2: fire moon_chunk_unstable SAFETY warnings for capital
            // pilots. Fires N hours (default 2) BEFORE the chunk enters
            // the plugin's UNSTABLE state (= fractured_at + 48h, which is
            // MoonExtraction::getUnstableStartTime()). Gives Rorqual / Orca
            // pilots time to dock up before hostile gangs arrive.
            //
            // IMPORTANT: uses the PLUGIN's lifecycle model, not ESI's
            // natural_decay_time. The plugin defines:
            //   chunk arrives → fractured_at → 48h ready → 2h unstable → expired
            // Raw ESI natural_decay_time is the auto-fracture mark (~3h
            // after arrival), which is a totally different point in the
            // lifecycle and NOT the right trigger for this warning.
            //
            // SQL broadens the candidate set (anything with a recent
            // fracture OR recent chunk arrival); PHP narrows using the
            // plugin's getUnstableStartTime() helper which correctly
            // handles fractured_at + auto_fractured + fallback cases.
            // ================================================================
            $warningHours = (float) $this->option('unstable-warning-hours');

            $unstableQuery = MoonExtraction::query()
                // Broad bound: anything that COULD have its unstable phase
                // within the next warningHours hours. Pre-fracture rows use
                // chunk_arrival as the fallback base in getFractureTime();
                // post-fracture rows use fractured_at. Either way, the
                // unstable_start is at most ~55 hours after chunk_arrival
                // (chunk_arrival + 3h auto-fracture + 48h ready + 2h unstable
                // window = 53h; +2h warning lead = 55h).
                ->where('chunk_arrival_time', '<=', $now)
                ->where('chunk_arrival_time', '>=', $now->copy()->subHours(55))
                ->where('unstable_warning_sent', false)
                ->whereNotIn('status', ['cancelled', 'expired']);

            if ($moonOwnerCorpId !== null) {
                $unstableQuery->where('corporation_id', $moonOwnerCorpId);
            }

            $unstableCandidates = $unstableQuery
                ->orderBy('chunk_arrival_time', 'asc')
                ->limit($limit * 2)  // over-fetch — we'll filter in PHP
                ->get()
                // Filter to rows where the plugin's unstable_start is within
                // the warning window. Uses the model's helper so fracture-
                // time resolution stays consistent with the rest of the code.
                ->filter(function (MoonExtraction $extraction) use ($now, $warningHours) {
                    $unstableStart = $extraction->getUnstableStartTime();
                    if (!$unstableStart) {
                        return false;
                    }
                    // Must still be in the stable window (haven't entered
                    // unstable yet) AND within the warning lead time.
                    if ($unstableStart->lte($now)) {
                        return false; // already unstable or expired — too late
                    }
                    $hoursUntilUnstable = $now->diffInMinutes($unstableStart) / 60;
                    return $hoursUntilUnstable <= $warningHours;
                })
                ->take($limit)
                ->values();

            if ($unstableCandidates->isEmpty()) {
                $this->info("No chunks approaching unstable state (within next {$warningHours}h of fractured_at + 48h).");
            } else {
                $this->info("Found {$unstableCandidates->count()} chunk(s) approaching unstable state:");

                $warned = 0;
                $warningFailed = 0;

                foreach ($unstableCandidates as $extraction) {
                    $moonLabel = $extraction->moon_name ?? "Moon {$extraction->moon_id}";
                    $unstableStart = $extraction->getUnstableStartTime();
                    $timeLeft = $now->diffForHumans($unstableStart, [
                        'parts' => 2,
                        'syntax' => Carbon::DIFF_ABSOLUTE,
                    ]);
                    $this->line("  #{$extraction->id} — {$moonLabel} — goes unstable in {$timeLeft}");

                    if ($dryRun) {
                        $warned++;
                        continue;
                    }

                    try {
                        // Delegates to MoonExtractionService — that method
                        // handles the flag flip + dispatch + logging in one
                        // place so both the command and any future caller
                        // stay consistent.
                        $this->extractionService->sendMoonChunkUnstableNotification($extraction);
                        $warned++;
                    } catch (\Exception $e) {
                        $warningFailed++;
                        $this->error("  Failed: {$e->getMessage()}");
                        Log::error("CheckExtractionArrivalsCommand: failed to fire unstable warning for extraction {$extraction->id}", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }

                if (!$dryRun) {
                    $this->info("Unstable warnings dispatched: {$warned}" . ($warningFailed > 0 ? ", Failed: {$warningFailed}" : ''));
                }
            }

            if ($dryRun) {
                $this->warn("DRY RUN — no notifications fired, no flags updated.");
            } else {
                $this->info("Arrival dispatched: {$dispatched}" . ($failed > 0 ? ", Failed: {$failed}" : ''));
            }

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

}
