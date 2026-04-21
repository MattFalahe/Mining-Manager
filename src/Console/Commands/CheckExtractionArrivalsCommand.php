<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
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
                            {--dry-run : Show what would be notified without firing}';

    protected $description = 'Fire moon_arrival notifications for extractions whose chunk_arrival_time has passed';

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

                // Reuse the existing dispatch method (private — call via reflection
                // since this is the canonical notification builder already in use
                // by updateExtractionStatuses). Safer than duplicating the embed code.
                $this->dispatchArrivalNotification($extraction);

                // Mark as sent so we never re-fire
                $extraction->update(['notification_sent' => true]);

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

        if ($dryRun) {
            $this->warn("DRY RUN — no notifications fired, no flags updated.");
        } else {
            $this->info("Dispatched: {$dispatched}" . ($failed > 0 ? ", Failed: {$failed}" : ''));
        }

        return Command::SUCCESS;
    }

    /**
     * Delegate to the existing notification builder on the service.
     * The service's sendMoonArrivalNotification() is private, so we
     * reach it via a small reflection hop. This keeps the embed
     * format and payload in one place (DRY with updateExtractionStatuses).
     */
    private function dispatchArrivalNotification(MoonExtraction $extraction): void
    {
        $reflection = new \ReflectionClass($this->extractionService);
        $method = $reflection->getMethod('sendMoonArrivalNotification');
        $method->setAccessible(true);
        $method->invoke($this->extractionService, $extraction);
    }
}
