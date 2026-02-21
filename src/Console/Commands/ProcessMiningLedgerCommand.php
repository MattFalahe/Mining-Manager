<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\CorporationObserverMining;
use MiningManager\Services\Pricing\PriceProviderService;
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
                            {--tax_rate=15 : Default tax rate percentage}
                            {--recalculate : Recalculate prices and taxes for existing entries}';

    protected $description = 'Process corporation observer mining data for COMPLETE moon mining tracking';

    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Mining Manager - Corporation Observer Processing         ║');
        $this->info('║   Tracking ALL miners at your structures - not just SeAT!  ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->line('');
        
        $observerId = $this->option('observer_id');
        $characterId = $this->option('character_id');
        $days = $this->option('days');
        $defaultTaxRate = $this->option('tax_rate');
        $recalculate = $this->option('recalculate');
        $cutoffDate = Carbon::now()->subDays($days);

        $this->info("💰 Using default tax rate: {$defaultTaxRate}%");

        // Build query for observer data
        $query = CorporationObserverMining::with(['character', 'type', 'structure'])
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

        // Get pricing service
        $priceService = app(PriceProviderService::class);

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        // Group by structure for progress
        $byStructure = $observerEntries->groupBy('observer_id');
        $this->info("🏗️  Processing {$byStructure->count()} structures");
        $this->line('');

        $progressBar = $this->output->createProgressBar($observerEntries->count());
        $progressBar->start();

        DB::beginTransaction();

        try {
            foreach ($observerEntries as $entry) {
                try {
                    // Check if already processed
                    $existing = MiningLedger::where('character_id', $entry->character_id)
                        ->whereDate('date', Carbon::parse($entry->last_updated)->toDateString())
                        ->where('type_id', $entry->type_id)
                        ->where('observer_id', $entry->observer_id)
                        ->first();

                    // Get price for the ore
                    $price = $priceService->getPrice($entry->type_id);
                    
                    // Calculate values
                    $oreValue = $entry->quantity * $price;
                    
                    // Use default tax rate (can be overridden per character/type in future)
                    $taxRate = $defaultTaxRate;
                    $taxAmount = $oreValue * ($taxRate / 100);

                    // Get structure's solar system
                    $solarSystemId = $entry->structure->solar_system_id ?? null;

                    // Classify ore type
                    $isMoonOre = TypeIdRegistry::isMoonOre($entry->type_id);
                    $isIce = TypeIdRegistry::isIce($entry->type_id);
                    $isGas = TypeIdRegistry::isGas($entry->type_id);
                    $isAbyssal = in_array($entry->type_id, TypeIdRegistry::ABYSSAL_ORES);
                    $oreCategory = $this->classifyOreCategory($entry->type_id);

                    // Prepare data
                    $data = [
                        'character_id' => $entry->character_id,
                        'date' => Carbon::parse($entry->last_updated)->toDateString(),
                        'type_id' => $entry->type_id,
                        'quantity' => $entry->quantity,
                        'solar_system_id' => $solarSystemId,
                        'observer_id' => $entry->observer_id,
                        'unit_price' => $price,
                        'ore_value' => $oreValue,
                        'total_value' => $oreValue,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                        'ore_type' => $entry->type->typeName ?? null,
                        'corporation_id' => $entry->corporation_id,
                        'is_taxable' => true,
                        'is_moon_ore' => $isMoonOre,
                        'is_ice' => $isIce,
                        'is_gas' => $isGas,
                        'is_abyssal' => $isAbyssal,
                        'ore_category' => $oreCategory,
                        'processed_at' => Carbon::now(),
                    ];

                    if ($existing) {
                        if ($recalculate) {
                            $existing->update($data);
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    } else {
                        // Cross-source dedup: remove personal ESI record if it exists
                        // Observer data is more authoritative (has observer_id, structure info)
                        $personalDupe = MiningLedger::where('character_id', $entry->character_id)
                            ->whereDate('date', Carbon::parse($entry->last_updated)->toDateString())
                            ->where('type_id', $entry->type_id)
                            ->whereNull('observer_id')
                            ->first();

                        if ($personalDupe) {
                            $personalDupe->delete();
                            Log::debug("Mining Manager: Replaced personal ESI record with observer data for character {$entry->character_id}, type {$entry->type_id}");
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
            }

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
                $totalValue = $observerEntries->sum(function($entry) use ($priceService) {
                    return $entry->quantity * $priceService->getPrice($entry->type_id);
                });
                $totalTaxes = $totalValue * ($defaultTaxRate / 100);
                
                $this->line("   • Unique miners tracked: {$uniqueMiners} (including non-registered)");
                $this->line("   • Structures: {$uniqueStructures}");
                $this->line("   • Total quantity: " . number_format($totalQuantity) . " units");
                $this->line("   • Estimated value: " . number_format($totalValue, 0) . " ISK");
                $this->line("   • Total taxes ({$defaultTaxRate}%): " . number_format($totalTaxes, 0) . " ISK");
                
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

            // Clear dashboard cache after processing new ledger data
            $this->info("\n🔄 Clearing dashboard cache to reflect new data...");
            Cache::tags(['dashboard'])->flush();
            // Alternative: Clear all mining-manager related caches
            DashboardController::clearDashboardCache();
            $this->info("✅ Dashboard cache cleared successfully!");

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
