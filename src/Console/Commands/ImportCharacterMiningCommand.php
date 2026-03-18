<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Services\Ledger\LedgerSummaryService;
use Carbon\Carbon;

class ImportCharacterMiningCommand extends Command
{
    protected $signature = 'mining-manager:import-character-mining
                            {--character_id= : Import specific character ID only}
                            {--days=30 : Number of days to import}
                            {--force : Re-import even if entries already exist}';

    protected $description = 'Import character mining ledger data from SeAT core (belt, anomaly, ice, gas mining)';

    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Mining Manager - Character Mining Import                 ║');
        $this->info('║   Importing personal mining data from SeAT ESI cache       ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->line('');

        $characterId = $this->option('character_id');
        $days = (int) $this->option('days');
        $force = $this->option('force');
        $cutoffDate = Carbon::now()->subDays($days);

        // Check if SeAT's CharacterMining model exists
        if (!class_exists(\Seat\Eveapi\Models\Industry\CharacterMining::class)) {
            $this->error('❌ SeAT CharacterMining model not found. Is SeAT v5.x installed?');
            return Command::FAILURE;
        }

        // Build query for SeAT character mining data
        $query = \Seat\Eveapi\Models\Industry\CharacterMining::where('date', '>=', $cutoffDate->toDateString());

        if ($characterId) {
            $query->where('character_id', $characterId);
            $this->info("👤 Importing for character ID: {$characterId}");
        } else {
            $this->info("👤 Importing for ALL characters with mining data");
        }

        $entries = $query->get();

        if ($entries->isEmpty()) {
            $this->warn('⚠️  No character mining data found in SeAT.');
            $this->line('');
            $this->info('This is normal if:');
            $this->info('  • SeAT hasn\'t updated character mining data yet');
            $this->info('  • No characters have mined recently');
            $this->info('  • Characters don\'t have the mining ledger ESI scope');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$entries->count()} character mining entries (last {$days} days)");
        $this->line('');

        $valuationService = app(OreValuationService::class);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $touchedPairs = collect();

        $progressBar = $this->output->createProgressBar($entries->count());
        $progressBar->start();

        foreach ($entries as $entry) {
            try {
                // Skip if observer data already exists for this entry (observer is authoritative)
                $hasObserver = MiningLedger::where('character_id', $entry->character_id)
                    ->whereDate('date', $entry->date)
                    ->where('type_id', $entry->type_id)
                    ->whereNotNull('observer_id')
                    ->exists();

                if ($hasObserver) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                // Check for existing personal entry
                $existing = MiningLedger::where('character_id', $entry->character_id)
                    ->whereDate('date', $entry->date)
                    ->where('type_id', $entry->type_id)
                    ->where('solar_system_id', $entry->solar_system_id)
                    ->whereNull('observer_id')
                    ->first();

                if ($existing && !$force) {
                    // Update only if quantity changed
                    if ($existing->quantity != $entry->quantity) {
                        $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);
                        $existing->update([
                            'quantity' => $entry->quantity,
                            'unit_price' => $values['unit_price'] ?? 0,
                            'ore_value' => $values['ore_value'] ?? 0,
                            'mineral_value' => $values['mineral_value'] ?? 0,
                            'total_value' => $values['total_value'] ?? 0,
                            'processed_at' => Carbon::now(),
                        ]);
                        $updated++;

                        $pairKey = $entry->character_id . '|' . $entry->date;
                        $touchedPairs->put($pairKey, [
                            'character_id' => $entry->character_id,
                            'date' => $entry->date,
                        ]);
                    } else {
                        $skipped++;
                    }
                    $progressBar->advance();
                    continue;
                }

                // Calculate values
                $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);

                // Classify ore
                $isMoonOre = TypeIdRegistry::isMoonOre($entry->type_id);
                $isIce = TypeIdRegistry::isIce($entry->type_id);
                $isGas = TypeIdRegistry::isGas($entry->type_id);
                $isAbyssal = in_array($entry->type_id, TypeIdRegistry::ABYSSAL_ORES);
                $oreCategory = $this->classifyOreCategory($entry->type_id);

                // Delete existing if force mode
                if ($existing && $force) {
                    $existing->delete();
                }

                MiningLedger::create([
                    'character_id' => $entry->character_id,
                    'date' => $entry->date,
                    'type_id' => $entry->type_id,
                    'quantity' => $entry->quantity,
                    'solar_system_id' => $entry->solar_system_id,
                    'unit_price' => $values['unit_price'] ?? 0,
                    'ore_value' => $values['ore_value'] ?? 0,
                    'mineral_value' => $values['mineral_value'] ?? 0,
                    'total_value' => $values['total_value'] ?? 0,
                    'is_moon_ore' => $isMoonOre,
                    'is_ice' => $isIce,
                    'is_gas' => $isGas,
                    'is_abyssal' => $isAbyssal,
                    'ore_category' => $oreCategory,
                    'processed_at' => Carbon::now(),
                ]);
                $created++;

                $pairKey = $entry->character_id . '|' . $entry->date;
                $touchedPairs->put($pairKey, [
                    'character_id' => $entry->character_id,
                    'date' => $entry->date,
                ]);

            } catch (\Exception $e) {
                $errors++;
                Log::warning("Mining Manager: Import error for character {$entry->character_id}, type {$entry->type_id}: {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');

        $this->table(
            ['Status', 'Count'],
            [
                ['New entries created', $created],
                ['Existing entries updated', $updated],
                ['Skipped (observer data exists)', $skipped],
                ['Errors', $errors],
            ]
        );

        // Update daily summaries for touched character+date pairs
        if ($touchedPairs->isNotEmpty()) {
            $this->line('');
            $this->info('Updating daily summaries...');

            try {
                $summaryService = app(LedgerSummaryService::class);
                $summaryCount = 0;

                foreach ($touchedPairs as $pair) {
                    $summaryService->generateDailySummary($pair['character_id'], $pair['date']);
                    $summaryCount++;
                }

                $this->info("Updated {$summaryCount} daily summaries.");
            } catch (\Exception $e) {
                $this->warn("Daily summary update failed: {$e->getMessage()}");
            }
        }

        $this->info('Import complete.');

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function classifyOreCategory(int $typeId): string
    {
        if (TypeIdRegistry::isMoonOre($typeId)) {
            $rarity = TypeIdRegistry::getMoonOreRarity($typeId);
            return $rarity ? 'moon_' . $rarity : 'moon';
        }
        if (TypeIdRegistry::isIce($typeId)) return 'ice';
        if (TypeIdRegistry::isGas($typeId)) return 'gas';
        if (in_array($typeId, TypeIdRegistry::ABYSSAL_ORES)) return 'abyssal';
        return 'ore';
    }
}
