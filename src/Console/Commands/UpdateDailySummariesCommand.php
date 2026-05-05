<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Ledger\LedgerSummaryService;
use MiningManager\Services\Pricing\OreValuationService;
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
                $this->reconcileLateObserverData($summaryService);
            }

            return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * Reconcile character-imported entries with late-arriving observer data.
     *
     * Character mining ESI data arrives without corporation_id. Observer data
     * (which is authoritative and includes corporation_id) can arrive 12-24h
     * later. This step finds character-imported moon ore entries from the
     * previous 2 days that now have matching observer data, adjusts quantities
     * (to preserve non-corp mining), and regenerates affected daily summaries.
     */
    private function reconcileLateObserverData(LedgerSummaryService $summaryService): void
    {
        $this->line('');
        $this->info('🔗 Reconciling late observer data (previous 2 days)...');

        $reconcileStart = Carbon::today()->subDays(2);
        $reconcileEnd = Carbon::yesterday();

        $orphans = MiningLedger::whereNull('corporation_id')
            ->whereNull('observer_id')
            ->where('is_moon_ore', true)
            ->whereBetween('date', [$reconcileStart, $reconcileEnd])
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('   No unresolved character-imported moon ore entries found.');
            return;
        }

        $valuationService = app(OreValuationService::class);
        $cleaned = 0;
        $adjusted = 0;
        $affectedPairs = collect();

        // Only reconcile against Moon Owner Corp's observer data
        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $moonOwnerCorpId = $settingsService->getSetting('general.moon_owner_corporation_id');

        foreach ($orphans as $orphan) {
            // Sum all observer quantities for same character+date+type
            // Only match against Moon Owner Corp's observers (not other corps)
            $observerQuery = MiningLedger::where('character_id', $orphan->character_id)
                ->whereDate('date', $orphan->date)
                ->where('type_id', $orphan->type_id)
                ->whereNotNull('observer_id');

            if ($moonOwnerCorpId) {
                $observerQuery->where('corporation_id', $moonOwnerCorpId);
            }

            $observerQty = $observerQuery->sum('quantity');

            if ($observerQty <= 0) {
                continue;
            }

            $pairKey = $orphan->character_id . '|' . $orphan->date;
            $affectedPairs->put($pairKey, [
                'character_id' => $orphan->character_id,
                'date' => $orphan->date instanceof Carbon ? $orphan->date->format('Y-m-d') : (string) $orphan->date,
            ]);

            $remainder = $orphan->quantity - $observerQty;
            if ($remainder <= 0) {
                $orphan->delete();
                $cleaned++;
            } else {
                $remainderValues = $valuationService->calculateOreValue($orphan->type_id, $remainder);
                $orphan->update([
                    'quantity' => $remainder,
                    'unit_price' => $remainderValues['unit_price'] ?? 0,
                    'ore_value' => $remainderValues['ore_value'] ?? 0,
                    'mineral_value' => $remainderValues['mineral_value'] ?? 0,
                    'total_value' => $remainderValues['total_value'] ?? 0,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'processed_at' => Carbon::now(),
                ]);
                $adjusted++;
            }
        }

        if ($cleaned > 0 || $adjusted > 0) {
            $this->info("   Removed {$cleaned} duplicates, adjusted {$adjusted} entries.");

            // Regenerate daily summaries for affected character+date pairs
            foreach ($affectedPairs as $pair) {
                try {
                    $summaryService->generateDailySummary($pair['character_id'], $pair['date']);
                } catch (\Exception $e) {
                    Log::warning("Mining Manager: Reconciliation summary failed for character {$pair['character_id']} on {$pair['date']}: {$e->getMessage()}");
                }
            }
            $this->info("   Regenerated {$affectedPairs->count()} daily summaries.");
        } else {
            $this->info('   No late observer data found to reconcile.');
        }
    }
}
