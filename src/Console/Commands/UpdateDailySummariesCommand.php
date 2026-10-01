<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Ledger\LedgerSummaryService;
use MiningManager\Services\Ledger\PersonalMiningReconciler;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Daily summary update command.
 *
 * Rebuilds daily mining summaries with rich per-ore breakdowns and
 * estimated tax calculations. This enables live tax tracking — players
 * see their estimated tax obligation accumulate throughout the month.
 *
 * By default rebuilds today and yesterday to catch late ESI data.
 *
 * Schedule: Run daily after mining-manager:calculate-taxes (e.g. 2:30 AM)
 */
class UpdateDailySummariesCommand extends Command
{
    protected $signature = 'mining-manager:update-daily-summaries
                            {--days=2 : Number of days back to rebuild (default: today + yesterday)}
                            {--date= : Rebuild a specific date (YYYY-MM-DD format)}
                            {--month= : Rebuild an entire month (YYYY-MM format)}
                            {--today-only : Only rebuild today (fast mode for frequent cron runs)}
                            {--character_id= : Only process specific character}';

    protected $description = 'Update daily mining ledger summaries with estimated tax calculations';

    public function handle(LedgerSummaryService $summaryService)
    {
        $lock = Cache::lock('mining-manager:update-daily-summaries', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
            $this->info('Mining Manager - Daily Summary Update');
            $this->info('=====================================');
            $this->line('');

            $days = (int) $this->option('days');
            $date = $this->option('date');
            $month = $this->option('month');
            $characterId = $this->option('character_id');

            // Determine date range
            if ($this->option('today-only')) {
                // Fast mode: only rebuild today (used by frequent cron runs after process-ledger)
                $startDate = Carbon::today();
                $endDate = Carbon::today();
                $this->info("Mode: Today only ({$startDate->format('Y-m-d')})");
            } elseif ($date) {
                // Single specific date
                try {
                    $startDate = Carbon::parse($date)->startOfDay();
                    $endDate = $startDate->copy();
                } catch (\Exception $e) {
                    $this->error("Invalid date format. Use YYYY-MM-DD (e.g. 2026-02-15)");
                    return Command::FAILURE;
                }

                $this->info("Mode: Rebuilding single date {$date}");
            } elseif ($month) {
                try {
                    $monthDate = Carbon::parse($month . '-01');
                } catch (\Exception $e) {
                    $this->error("Invalid month format. Use YYYY-MM (e.g. 2026-02)");
                    return Command::FAILURE;
                }

                $startDate = $monthDate->copy()->startOfMonth();
                $endDate = $monthDate->copy()->endOfMonth();

                // Don't go past today
                if ($endDate->isFuture()) {
                    $endDate = Carbon::today();
                }

                $this->info("Mode: Rebuilding entire month {$month}");
            } else {
                $endDate = Carbon::today();
                $startDate = Carbon::today()->subDays($days - 1);
                $this->info("Mode: Rebuilding last {$days} day(s) ({$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')})");
            }

            if ($characterId) {
                $this->info("Filtering for character ID: {$characterId}");
            }

            // Find all characters with mining data in the date range
            $query = MiningLedger::whereBetween('date', [$startDate, $endDate]);

            if ($characterId) {
                $query->where('character_id', $characterId);
            }

            $characters = $query->distinct()->pluck('character_id');

            if ($characters->isEmpty()) {
                $this->info('No mining data found in date range.');
                return Command::SUCCESS;
            }

            $this->info("Found {$characters->count()} character(s) with mining data");
            $this->line('');

            // Build list of dates to process
            $dates = [];
            for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
                $dates[] = $d->format('Y-m-d');
            }

            $totalTasks = $characters->count() * count($dates);
            $bar = $this->output->createProgressBar($totalTasks);
            $bar->start();

            $generated = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($characters as $charId) {
                foreach ($dates as $dateStr) {
                    try {
                        // Check if this character has mining data for this date
                        $hasData = MiningLedger::where('character_id', $charId)
                            ->whereDate('date', $dateStr)
                            ->exists();

                        if (!$hasData) {
                            $skipped++;
                            $bar->advance();
                            continue;
                        }

                        $summaryService->generateDailySummary($charId, $dateStr);
                        $generated++;
                    } catch (\Exception $e) {
                        Log::error("Mining Manager: Failed to generate daily summary for character {$charId} on {$dateStr}: {$e->getMessage()}");
                        $errors++;
                    }

                    $bar->advance();
                }
            }

            $bar->finish();
            $this->line('');
            $this->line('');

            $this->table(
                ['Status', 'Count'],
                [
                    ['Generated/Updated', $generated],
                    ['Skipped (no data)', $skipped],
                    ['Errors', $errors],
                ]
            );

            if ($generated > 0) {
                Log::info("Mining Manager: Updated {$generated} daily summaries for {$characters->count()} character(s)");
            }

            // Reconciliation: check previous 2 days for character-imported moon ore
            // entries that now have matching observer data. Observer data can arrive
            // 12-24h late from ESI, so we retroactively clean up and regenerate.
            if (!$date && !$month && !$characterId) {
                $this->reconcileLateObserverData();
            }

            return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * Hand late-arriving observer data to the reconciler.
     *
     * This used to be a second implementation living here, with a two day
     * window and its own rules. It also subtracted the observer quantity again
     * every time it saw the same row, so a legitimate remainder it trimmed on
     * one day was deleted outright on the next. One implementation now, shared
     * with the command that offers a dry run.
     */
    private function reconcileLateObserverData(): void
    {
        $this->line('');
        $this->info('🔗 Reconciling late observer data...');

        $result = app(PersonalMiningReconciler::class)->reconcile();

        if ($result['moon_owner'] === null) {
            $this->warn('   No Moon Owner Corporation set, nothing to match against.');

            return;
        }

        if ($result['removed'] === 0) {
            $this->info('   No late observer data found to reconcile.');

            return;
        }

        $this->info("   Removed {$result['removed']} duplicate row(s), "
            . "rebuilt {$result['summaries']} daily summar(ies).");
    }
}
