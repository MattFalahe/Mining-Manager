<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Ledger\LedgerSummaryService;
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
                            {--month= : Rebuild an entire month (YYYY-MM format)}
                            {--character_id= : Only process specific character}';

    protected $description = 'Update daily mining ledger summaries with estimated tax calculations';

    public function handle(LedgerSummaryService $summaryService)
    {
        $this->info('Mining Manager - Daily Summary Update');
        $this->info('=====================================');
        $this->line('');

        $days = (int) $this->option('days');
        $month = $this->option('month');
        $characterId = $this->option('character_id');

        // Determine date range
        if ($month) {
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

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
