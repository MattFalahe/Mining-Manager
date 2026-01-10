<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\CorporationObserverMining;
use MiningManager\Services\Pricing\PriceProviderService;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Services\Notification\WebhookService;
use MiningManager\Http\Controllers\DashboardController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProcessMiningLedgerCommand extends Command
{
    protected $signature = 'mining-manager:process-ledger
                            {--observer_id= : Process specific observer/structure}
                            {--character_id= : Process specific character ID}
                            {--days=30 : Number of days to process}
                            {--recalculate : Recalculate prices and taxes for existing entries}';

    protected $description = 'Process corporation observer mining data for COMPLETE moon mining tracking';

    public function handle()
    {
        // Check feature flag
        $settingsService = app(SettingsManagerService::class);
        $features = $settingsService->getFeatureFlags();
        if (!($features['auto_process_ledger'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Mining Manager - Corporation Observer Processing         ║');
        $this->info('║   Tracking ALL miners at your structures - not just SeAT!  ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->line('');

        $observerId = $this->option('observer_id');
        $characterId = $this->option('character_id');
        $days = $this->option('days');
        $recalculate = $this->option('recalculate');
        $cutoffDate = Carbon::now()->subDays($days);

        // Use corporation settings for tax rates (per structure-owner corporation)
        $settingsService = app(SettingsManagerService::class);
        $this->info("💰 Tax rates: per structure-owner corporation from settings (multi-corp support)");

        // Build query for observer data (eager-load observer for corporation_id resolution)
        $query = CorporationObserverMining::with(['character', 'type', 'structure', 'observer'])
            ->where('last_updated', '>=', $cutoffDate);

        if ($observerId) {
            $query->where('observer_id', $observerId);
            $this->info("🔍 Processing observer ID: {$observerId}");
        } else {
            $this->info("🔍 Processing ALL corporation observers");
        }

        if ($characterId) {
            $query->where('character_id', $characterId);
            $this->info("👤 Filtering for character ID: {$characterId}");
        }

        $observerEntries = $query->get();

        if ($observerEntries->isEmpty()) {
            $this->warn('⚠️  No observer mining data found for the specified criteria.');
            $this->line('');
            $this->info('Make sure:');
            $this->info('  • Your corporation has structures with mining observers');
            $this->info('  • SeAT has fetched recent observer data');
            $this->info('  • Mining activity has occurred at your structures');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$observerEntries->count()} observer mining entries");
        $this->line('');

        // Check price cache freshness before processing
        try {
            $priceService = app(PriceProviderService::class);
            // Spot-check a common ore type (Veldspar = 1230) for cache freshness
            $pricingSettings = $settingsService->getPricingSettings();
            $regionId = (int) ($pricingSettings['default_region_id'] ?? 10000002);
            if (!$priceService->isCacheFresh(1230, $regionId)) {
                $this->warn('⚠️  Price cache is STALE — prices may be outdated. Run mining-manager:cache-prices first.');
                Log::warning('Mining Manager: ProcessMiningLedgerCommand running with stale price cache');
            } else {
                $this->info('✅ Price cache is fresh');
            }
        } catch (\Exception $e) {
            $this->warn('⚠️  Could not verify price cache freshness: ' . $e->getMessage());
        }
        $this->line('');

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        // Group by observer (structure) — each observer belongs to one corporation
        $byObserver = $observerEntries->groupBy('observer_id');
        $this->info("🏗️  Processing {$byObserver->count()} structures");
        $this->line('');

        $progressBar = $this->output->createProgressBar($observerEntries->count());
        $progressBar->start();

        DB::beginTransaction();

        try {
            // Process entries grouped by observer — switch corp context once per observer
            foreach ($byObserver as $obsId => $entries) {
                // Resolve structure owner corporation from observer relationship
                $structureCorpId = $entries->first()->corporation_id; // via getCorporationIdAttribute accessor

                // Switch settings context to this structure owner's corporation
                if ($structureCorpId) {
                    $settingsService->setActiveCorporation((int) $structureCorpId);
                } else {
                    $settingsService->setActiveCorporation(null);
                }
                $taxSelector = $settingsService->getTaxSelector();

                foreach ($entries as $entry) {
                try {
                    // Check if already processed
                    $existing = MiningLedger::where('character_id', $entry->character_id)
                        ->whereDate('date', Carbon::parse($entry->last_updated)->toDateString())
                        ->where('type_id', $entry->type_id)
                        ->where('observer_id', $entry->observer_id)
                        ->first();

                    // Get structure's solar system
                    $solarSystemId = $entry->structure->solar_system_id ?? null;

                    // Calculate ore values using OreValuationService (daily session pricing)
                    $valuationService = app(OreValuationService::class);
                    $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);

                    $unitPrice = $values['unit_price'] ?? 0;
                    $oreValue = $values['ore_value'] ?? 0;
                    $mineralValue = $values['mineral_value'] ?? 0;
                    $totalValue = $values['total_value'] ?? 0;

                    // Classify ore type (must be before tax rate lookup)
                    $isMoonOre = TypeIdRegistry::isMoonOre($entry->type_id);
                    $isIce = TypeIdRegistry::isIce($entry->type_id);
                    $isGas = TypeIdRegistry::isGas($entry->type_id);
                    $isAbyssal = in_array($entry->type_id, TypeIdRegistry::ABYSSAL_ORES);
                    $oreCategory = $this->classifyOreCategory($entry->type_id);

                    // Get tax rate from this structure owner's corporation settings
                    $taxRate = $this->getTaxRateFromSettings($settingsService, $entry->type_id, $isMoonOre, $isIce, $isGas, $isAbyssal, $taxSelector, $structureCorpId);
                    $taxAmount = $totalValue * ($taxRate / 100);

                    // Prepare data
                    $data = [
                        'character_id' => $entry->character_id,
                        'date' => Carbon::parse($entry->last_updated)->toDateString(),
                        'type_id' => $entry->type_id,
                        'quantity' => $entry->quantity,
                        'solar_system_id' => $solarSystemId,
                        'observer_id' => $entry->observer_id,
                        'unit_price' => $unitPrice,
                        'ore_value' => $oreValue,
                        'mineral_value' => $mineralValue,
                        'total_value' => $totalValue,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                        'ore_type' => $oreCategory,
                        'corporation_id' => $structureCorpId,
                        'is_taxable' => true,
                        'is_moon_ore' => $isMoonOre,
                        'is_ice' => $isIce,
                        'is_gas' => $isGas,
                        'is_abyssal' => $isAbyssal,
                        'ore_category' => $oreCategory,
                        'processed_at' => Carbon::now(),
                    ];

                    if ($existing) {
                        // Observer data is CUMULATIVE — quantity grows as miners mine more.
                        // Always update if quantity increased or if recalculating prices.
                        if ($recalculate || $entry->quantity > $existing->quantity) {
                            $existing->update($data);
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    } else {
                        // Cross-source dedup: adjust personal ESI record if it exists
                        // Character ESI combines ALL mining of a type in a system into one entry,
                        // so if a character mined at both a corp moon and non-corp moon,
                        // we subtract the observer quantity and keep the remainder as untaxed.
                        $personalDupe = MiningLedger::where('character_id', $entry->character_id)
                            ->whereDate('date', Carbon::parse($entry->last_updated)->toDateString())
                            ->where('type_id', $entry->type_id)
                            ->whereNull('observer_id')
                            ->first();

                        if ($personalDupe) {
                            $remainder = $personalDupe->quantity - $entry->quantity;
                            if ($remainder <= 0) {
                                // Observer covers all or more — remove the character entry
                                $personalDupe->delete();
                                Log::debug("Mining Manager: Replaced personal ESI record with observer data for character {$entry->character_id}, type {$entry->type_id}");
                            } else {
                                // Character mined more than observer shows — keep remainder as non-corp mining
                                $valuationService = app(OreValuationService::class);
                                $remainderValues = $valuationService->calculateOreValue($entry->type_id, $remainder);
                                $personalDupe->update([
                                    'quantity' => $remainder,
                                    'unit_price' => $remainderValues['unit_price'] ?? 0,
                                    'ore_value' => $remainderValues['ore_value'] ?? 0,
                                    'mineral_value' => $remainderValues['mineral_value'] ?? 0,
                                    'total_value' => $remainderValues['total_value'] ?? 0,
                                    'tax_rate' => 0,
                                    'tax_amount' => 0,
                                    'processed_at' => Carbon::now(),
                                ]);
                                Log::debug("Mining Manager: Adjusted personal ESI record for character {$entry->character_id}, type {$entry->type_id}: kept {$remainder} units as non-corp mining");
                            }
                        }

                        MiningLedger::create($data);
                        $processed++;
                    }

                    $progressBar->advance();

                } catch (\Exception $e) {
                    $this->error("\n❌ Error processing entry for character {$entry->character_id}: {$e->getMessage()}");
                    $errors++;
                    $progressBar->advance();
                }
                } // end inner foreach (entries per observer)
            } // end outer foreach (observers)

            DB::commit();
            $progressBar->finish();

            $this->line('');
            $this->line('');
            $this->info('✅ Processing complete!');
            $this->line('');
            
            $this->table(
                ['Status', 'Count'],
                [
                    ['🆕 New entries created', $processed],
                    ['🔄 Existing entries updated', $updated],
                    ['⏭️  Entries skipped', $skipped],
                    ['❌ Errors', $errors],
                ]
            );

            // Show mining summary
            if ($processed > 0 || $updated > 0) {
                $this->line('');
                $this->info('📈 Mining Summary:');
                
                $uniqueMiners = $observerEntries->unique('character_id')->count();
                $uniqueStructures = $observerEntries->unique('observer_id')->count();
                $totalQuantity = $observerEntries->sum('quantity');
                $valuationSvc = app(OreValuationService::class);
                $totalValue = $observerEntries->sum(function($entry) use ($valuationSvc) {
                    $vals = $valuationSvc->calculateOreValue($entry->type_id, $entry->quantity);
                    return $vals['total_value'] ?? 0;
                });
                // Sum actual per-entry tax amounts from database instead of using a flat rate
                $totalTaxes = MiningLedger::where('character_id', '>', 0)
                    ->whereIn('character_id', $observerEntries->pluck('character_id')->unique())
                    ->where('date', '>=', $cutoffDate->toDateString())
                    ->sum('tax_amount');

                $this->line("   • Unique miners tracked: {$uniqueMiners} (including non-registered)");
                $this->line("   • Structures: {$uniqueStructures}");
                $this->line("   • Total quantity: " . number_format($totalQuantity) . " units");
                $this->line("   • Estimated value: " . number_format($totalValue, 0) . " ISK");
                $this->line("   • Total est. taxes (per settings): " . number_format($totalTaxes, 0) . " ISK");
                
                // Show sample of miners
                $this->line('');
                $this->info('👥 Sample of tracked miners:');
                $sampleMiners = $observerEntries->unique('character_id')->take(10);
                foreach ($sampleMiners as $mining) {
                    $name = $mining->character_name;
                    $registered = $mining->isCharacterRegistered() ? '✓ Registered' : '⚠️  Not in SeAT';
                    $this->line("   • {$name} ({$registered})");
                }
                
                if ($uniqueMiners > 10) {
                    $remaining = $uniqueMiners - 10;
                    $this->line("   ... and {$remaining} more miners");
                }
            }

            if ($errors > 0) {
                $this->line('');
                $this->warn("⚠️  Completed with {$errors} errors. Check logs for details.");
                return Command::FAILURE;
            }

            // Check for jackpot ores in processed entries
            if ($processed > 0) {
                $this->checkForJackpotOres($observerEntries);
            }

            // Clean up orphaned character-imported entries where observer data exists
            // Character ESI combines all mining of a type in a system, so orphans
            // may contain both corp and non-corp quantities mixed together.
            if ($processed > 0 || $updated > 0) {
                $this->line('');
                $this->info('🔗 Cleaning up character-imported entries with matching observer data...');

                $cleaned = 0;
                $adjusted = 0;
                try {
                    $orphans = MiningLedger::whereNull('corporation_id')
                        ->whereNull('observer_id')
                        ->where('is_moon_ore', true)
                        ->where('date', '>=', $cutoffDate->toDateString())
                        ->get();

                    $valuationSvc = app(OreValuationService::class);

                    foreach ($orphans as $orphan) {
                        // Sum all observer quantities for same character+date+type
                        $observerQty = MiningLedger::where('character_id', $orphan->character_id)
                            ->whereDate('date', $orphan->date)
                            ->where('type_id', $orphan->type_id)
                            ->whereNotNull('observer_id')
                            ->sum('quantity');

                        if ($observerQty <= 0) {
                            continue;
                        }

                        $remainder = $orphan->quantity - $observerQty;
                        if ($remainder <= 0) {
                            $orphan->delete();
                            $cleaned++;
                        } else {
                            // Keep remainder as non-corp mining
                            $remainderValues = $valuationSvc->calculateOreValue($orphan->type_id, $remainder);
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
                        $this->info("   Removed {$cleaned} duplicates, adjusted {$adjusted} entries (kept non-corp remainder).");
                    } else {
                        $this->info("   No orphaned entries to clean up.");
                    }
                } catch (\Exception $e) {
                    $this->warn("   ⚠️  Backfill failed: {$e->getMessage()}");
                    Log::warning('Mining Manager: Corporation ID backfill failed', ['error' => $e->getMessage()]);
                }
            }

            // Auto-generate daily summaries for all dates that were touched
            if ($processed > 0 || $updated > 0) {
                $this->line('');
                $this->info('📊 Updating daily summaries for processed dates...');

                try {
                    $summaryService = app(\MiningManager\Services\Ledger\LedgerSummaryService::class);

                    // Collect distinct character+date pairs from processed entries
                    $touchedPairs = $observerEntries->map(function ($entry) {
                        return [
                            'character_id' => $entry->character_id,
                            'date' => Carbon::parse($entry->last_updated)->toDateString(),
                        ];
                    })->unique(function ($item) {
                        return $item['character_id'] . '|' . $item['date'];
                    });

                    $summaryCount = 0;
                    foreach ($touchedPairs as $pair) {
                        $summaryService->generateDailySummary($pair['character_id'], $pair['date']);
                        $summaryCount++;
                    }

                    $this->info("   Updated {$summaryCount} daily summaries.");
                } catch (\Exception $e) {
                    $this->warn("   ⚠️  Daily summary update failed: {$e->getMessage()}");
                    Log::warning('Mining Manager: Auto daily summary update failed', ['error' => $e->getMessage()]);
                }
            }

            // Clear dashboard cache so new data shows immediately
            \MiningManager\Http\Controllers\DashboardController::clearDashboardCache();
            $this->info("\n✅ Processing complete. Dashboard cache cleared.");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();
            $progressBar->finish();
            $this->line('');
            $this->line('');
            $this->error("❌ Fatal error: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Get tax rate for an ore type from corporation settings.
     * Returns 0 if no corporation is configured or ore type is not taxable.
     *
     * @param SettingsManagerService $settingsService
     * @param int $typeId
     * @param bool $isMoonOre
     * @param bool $isIce
     * @param bool $isGas
     * @param bool $isAbyssal
     * @param array $taxSelector
     * @param int|null $structureCorpId
     * @return float Tax rate percentage (0-100)
     */
    private function getTaxRateFromSettings(
        SettingsManagerService $settingsService,
        int $typeId,
        bool $isMoonOre,
        bool $isIce,
        bool $isGas,
        bool $isAbyssal,
        array $taxSelector,
        ?int $structureCorpId
    ): float {
        // No corporation resolved for this structure → 0% tax (statistics only)
        if (!$structureCorpId) {
            return 0.0;
        }

        // Get tax rates from settings (respects active corporation context)
        $taxRates = $settingsService->getTaxRates();

        // Check taxability via tax selector
        if ($isMoonOre) {
            if (!empty($taxSelector['no_moon_ore'])) {
                return 0.0;
            }
            if (empty($taxSelector['all_moon_ore']) && empty($taxSelector['only_corp_moon_ore'])) {
                return 0.0;
            }
            // Get rarity-specific rate
            $rarity = TypeIdRegistry::getMoonOreRarity($typeId);
            if ($rarity) {
                $rarityKey = strtolower($rarity);
                return (float) ($taxRates['moon_ore'][$rarityKey] ?? $taxRates['moon_ore']['r4'] ?? 5.0);
            }
            return (float) ($taxRates['moon_ore']['r4'] ?? 5.0);
        }

        if ($isIce) {
            return ($taxSelector['ice'] ?? true) ? (float) ($taxRates['ice'] ?? 10.0) : 0.0;
        }

        if ($isGas) {
            return ($taxSelector['gas'] ?? false) ? (float) ($taxRates['gas'] ?? 10.0) : 0.0;
        }

        if ($isAbyssal) {
            return ($taxSelector['abyssal_ore'] ?? false) ? (float) ($taxRates['abyssal_ore'] ?? 15.0) : 0.0;
        }

        // Regular ore
        return ($taxSelector['ore'] ?? true) ? (float) ($taxRates['ore'] ?? 10.0) : 0.0;
    }

    /**
     * Classify ore into a category string for statistics.
     *
     * @param int $typeId
     * @return string
     */
    private function classifyOreCategory(int $typeId): string
    {
        if (TypeIdRegistry::isMoonOre($typeId)) {
            $rarity = TypeIdRegistry::getMoonOreRarity($typeId);
            return $rarity ? 'moon_' . $rarity : 'moon';
        }

        if (TypeIdRegistry::isIce($typeId)) {
            return 'ice';
        }

        if (TypeIdRegistry::isGas($typeId)) {
            return 'gas';
        }

        if (in_array($typeId, TypeIdRegistry::ABYSSAL_ORES)) {
            return 'abyssal';
        }

        return 'ore';
    }

    /**
     * Check if any processed entries contain jackpot ores and flag extractions
     *
     * @param \Illuminate\Support\Collection $observerEntries
     * @return void
     */
    private function checkForJackpotOres($observerEntries)
    {
        try {
            $jackpotTypeIds = TypeIdRegistry::getAllJackpotOres();

            $jackpotEntries = $observerEntries->filter(function ($entry) use ($jackpotTypeIds) {
                return in_array($entry->type_id, $jackpotTypeIds);
            });

            if ($jackpotEntries->isEmpty()) {
                return;
            }

            $this->line('');
            $this->info('⭐ Jackpot ores detected in mining data!');

            // Group by observer/structure
            $byObserver = $jackpotEntries->groupBy('observer_id');
            $jackpotsFound = 0;

            foreach ($byObserver as $observerId => $entries) {
                // Find extraction for this structure
                $entryDate = Carbon::parse($entries->first()->last_updated);

                $extraction = MoonExtraction::where('structure_id', $observerId)
                    ->where('chunk_arrival_time', '<=', $entryDate->endOfDay())
                    ->where('natural_decay_time', '>=', $entryDate->startOfDay())
                    ->where('is_jackpot', false)
                    ->first();

                if (!$extraction) {
                    continue;
                }

                $extraction->is_jackpot = true;
                $extraction->jackpot_detected_at = now();
                $extraction->save();
                $jackpotsFound++;

                $this->info("  ⭐ JACKPOT: {$extraction->moon_name} (Structure {$observerId})");

                // Get details for notification
                $structureName = DB::table('universe_structures')
                    ->where('structure_id', $observerId)
                    ->value('name') ?? "Structure {$observerId}";

                $systemName = DB::table('universe_structures')
                    ->join('solar_systems', 'universe_structures.solar_system_id', '=', 'solar_systems.system_id')
                    ->where('universe_structures.structure_id', $observerId)
                    ->value('solar_systems.name') ?? 'Unknown System';

                $jackpotOreDetails = [];
                foreach ($entries->unique('type_id') as $entry) {
                    $oreName = $entry->type->typeName ?? "Type {$entry->type_id}";
                    $totalQty = $entries->where('type_id', $entry->type_id)->sum('quantity');
                    $jackpotOreDetails[] = [
                        'name' => $oreName,
                        'type_id' => $entry->type_id,
                        'quantity' => $totalQty,
                    ];
                }

                // First miner who mined jackpot ore
                $firstEntry = $entries->first();
                $detectedBy = $firstEntry->character_name ?? "Character {$firstEntry->character_id}";

                // Send webhook notification
                try {
                    $webhookService = app(WebhookService::class);
                    $webhookService->sendMoonNotification('jackpot_detected', [
                        'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                        'structure_name' => $structureName,
                        'system_name' => $systemName,
                        'detected_by' => $detectedBy,
                        'jackpot_ores' => $jackpotOreDetails,
                        'jackpot_percentage' => 100,
                        'extraction_id' => $extraction->id,
                    ], $extraction->corporation_id);

                    $this->info("  📡 Jackpot notification sent!");
                } catch (\Exception $e) {
                    $this->warn("  ⚠️ Failed to send jackpot notification: {$e->getMessage()}");
                }
            }

            if ($jackpotsFound > 0) {
                $this->info("💎 Found {$jackpotsFound} new jackpot extraction(s)!");
            }
        } catch (\Exception $e) {
            $this->warn("⚠️ Error checking for jackpot ores: {$e->getMessage()}");
        }
    }
}
