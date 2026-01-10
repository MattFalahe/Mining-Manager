<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\Tax\TaxCalculationService;
use MiningManager\Services\Ledger\LedgerSummaryService;
use MiningManager\Services\Configuration\SettingsManagerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily ledger price update command.
 *
 * Implements "daily session pricing" — each day's mining is appraised at
 * the current market price. At month end, taxes are the sum of daily values.
 *
 * This command re-prices ledger entries that:
 * - Have a total_value of 0 (never priced)
 * - Were mined today (re-price with latest market data)
 * - Or are within a configurable lookback window (--days)
 *
 * Schedule: Run daily after mining-manager:cache-prices
 */
class UpdateLedgerPricesCommand extends Command
{
    protected $signature = 'mining-manager:update-ledger-prices
                            {--days=1 : Number of days back to re-price (default: today only)}
                            {--all-unpriced : Re-price ALL entries with total_value = 0, regardless of date}
                            {--force : Force re-price even if total_value > 0}
                            {--character_id= : Only update specific character}';

    protected $description = 'Update mining ledger entry values using current market prices (daily session pricing)';

    public function handle()
    {
        $this->info('╔═══════════════════════════════════════════════╗');
        $this->info('║   Mining Manager - Daily Ledger Price Update  ║');
        $this->info('╚═══════════════════════════════════════════════╝');
        $this->line('');

        $days = (int) $this->option('days');
        $allUnpriced = $this->option('all-unpriced');
        $force = $this->option('force');
        $characterId = $this->option('character_id');

        $valuationService = app(OreValuationService::class);
        $taxService = app(TaxCalculationService::class);
        $settingsService = app(SettingsManagerService::class);

        // Build query
        $query = MiningLedger::query();

        if ($characterId) {
            $query->where('character_id', $characterId);
            $this->info("👤 Filtering for character ID: {$characterId}");
        }

        if ($allUnpriced) {
            // Re-price all entries that have never been priced
            $query->where(function ($q) {
                $q->where('total_value', 0)
                  ->orWhereNull('total_value');
            });
            $this->info('🔍 Mode: Re-pricing ALL unpriced entries');
        } elseif ($force) {
            // Force re-price everything within the date range
            $cutoffDate = Carbon::now()->subDays($days)->startOfDay();
            $query->where('date', '>=', $cutoffDate);
            $this->info("🔍 Mode: Force re-pricing all entries from last {$days} day(s)");
        } else {
            // Default: re-price entries from recent days that are unpriced OR from today
            $cutoffDate = Carbon::now()->subDays($days)->startOfDay();
            $query->where('date', '>=', $cutoffDate)
                  ->where(function ($q) {
                      $q->where('total_value', 0)
                        ->orWhereNull('total_value')
                        ->orWhereDate('date', Carbon::today()); // Always re-price today's entries
                  });
            $this->info("🔍 Mode: Updating unpriced entries + today's entries (last {$days} day(s))");
        }

        $entries = $query->get();

        if ($entries->isEmpty()) {
            $this->info('✅ No entries need price updates.');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$entries->count()} entries to update");
        $this->line('');

        $bar = $this->output->createProgressBar($entries->count());
        $bar->start();

        $updated = 0;
        $errors = 0;
        $skipped = 0;
        $affectedPairs = collect(); // Track character_id + date pairs for daily summary regeneration

        foreach ($entries as $entry) {
            try {
                $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);

                $newTotalValue = $values['total_value'] ?? 0;

                // Skip if value hasn't changed and isn't zero
                if (!$force && $entry->total_value == $newTotalValue && $newTotalValue > 0) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Recalculate tax_amount using current tax rates
                $entryCorpId = $entry->corporation_id;
                if (!$entryCorpId && $entry->observer_id) {
                    $entryCorpId = DB::table('corporation_industry_mining_observers')
                        ->where('observer_id', $entry->observer_id)
                        ->value('corporation_id');
                }
                if ($entryCorpId) {
                    $settingsService->setActiveCorporation((int) $entryCorpId);
                }

                $characterCorpId = DB::table('character_affiliations')
                    ->where('character_id', $entry->character_id)
                    ->value('corporation_id');

                $taxRate = $taxService->getTaxRateForOre($entry->type_id, $characterCorpId);
                $newTaxAmount = $newTotalValue * ($taxRate / 100);

                $entry->update([
                    'unit_price' => $values['unit_price'] ?? 0,
                    'ore_value' => $values['ore_value'] ?? 0,
                    'mineral_value' => $values['mineral_value'] ?? 0,
                    'total_value' => $newTotalValue,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $newTaxAmount,
                ]);

                // Track this character+date for daily summary regeneration
                $pairKey = $entry->character_id . '_' . $entry->date;
                if (!$affectedPairs->has($pairKey)) {
                    $affectedPairs->put($pairKey, [
                        'character_id' => $entry->character_id,
                        'date' => $entry->date,
                    ]);
                }

                $updated++;
            } catch (\Exception $e) {
                Log::error("Mining Manager: Failed to update price for ledger entry {$entry->id}: {$e->getMessage()}");
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->line('');

        $this->table(
            ['Status', 'Count'],
            [
                ['✅ Updated', $updated],
                ['⏭️  Skipped (unchanged)', $skipped],
                ['❌ Errors', $errors],
            ]
        );

        // Regenerate daily summaries for affected character+date pairs
        if ($affectedPairs->isNotEmpty()) {
            $this->line('');
            $this->info("📋 Regenerating daily summaries for {$affectedPairs->count()} character/date pairs...");

            $summaryService = app(LedgerSummaryService::class);
            $summariesUpdated = 0;
            $summaryErrors = 0;

            foreach ($affectedPairs as $pair) {
                try {
                    $date = Carbon::parse($pair['date']);
                    $summaryService->generateDailySummary($pair['character_id'], $date);
                    $summariesUpdated++;
                } catch (\Exception $e) {
                    Log::error("Mining Manager: Failed to regenerate daily summary for character {$pair['character_id']} on {$pair['date']}: {$e->getMessage()}");
                    $summaryErrors++;
                }
            }

            $this->table(
                ['Daily Summaries', 'Count'],
                [
                    ['✅ Regenerated', $summariesUpdated],
                    ['❌ Errors', $summaryErrors],
                ]
            );

            if ($summariesUpdated > 0) {
                Log::info("Mining Manager: Regenerated {$summariesUpdated} daily summaries after price update");
            }
        }

        if ($updated > 0) {
            Log::info("Mining Manager: Updated prices for {$updated} ledger entries");
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
