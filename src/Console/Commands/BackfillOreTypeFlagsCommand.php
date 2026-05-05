<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\TypeIdRegistry;
use Illuminate\Support\Facades\DB;

class BackfillOreTypeFlagsCommand extends Command
{
    protected $signature = 'mining-manager:backfill-ore-types
                            {--batch=1000 : Number of records to process per batch}';

    protected $description = 'Backfill ore-type classification flags (is_moon_ore, is_ice, is_gas, is_abyssal, is_triglavian) + ore_category for existing mining ledger entries';

    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Mining Manager - Backfill Ore Type Flags                ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->line('');

        $batchSize = $this->option('batch');

        // Count total records that need updating
        $total = MiningLedger::count();
        $this->info("📊 Found {$total} total ledger entries");

        if ($total === 0) {
            $this->warn('⚠️  No ledger entries found.');
            return Command::SUCCESS;
        }

        $this->line('');
        $this->info('🔄 Processing entries...');

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $updated = 0;
        $errors = 0;

        // Process in batches to avoid memory issues
        MiningLedger::chunk($batchSize, function ($entries) use (&$updated, &$errors, $progressBar) {
            foreach ($entries as $entry) {
                try {
                    // Classify ore type using TypeIdRegistry — same logic as
                    // ProcessMiningLedgerCommand uses on initial ingestion, kept
                    // in sync so backfill produces the exact same classifications.
                    $isMoonOre = TypeIdRegistry::isMoonOre($entry->type_id);
                    $isIce = TypeIdRegistry::isIce($entry->type_id);
                    $isGas = TypeIdRegistry::isGas($entry->type_id);
                    $isAbyssal = in_array($entry->type_id, TypeIdRegistry::ABYSSAL_ORES, true);
                    $isTriglavian = TypeIdRegistry::isTriglavianOre($entry->type_id);
                    $oreCategory = $this->classifyOreCategory(
                        $entry->type_id,
                        $isMoonOre, $isIce, $isGas, $isAbyssal, $isTriglavian
                    );

                    // Only update if values have changed (avoids touching
                    // updated_at on already-correct rows)
                    if ($entry->is_moon_ore != $isMoonOre ||
                        $entry->is_ice != $isIce ||
                        $entry->is_gas != $isGas ||
                        $entry->is_abyssal != $isAbyssal ||
                        $entry->is_triglavian != $isTriglavian ||
                        $entry->ore_category !== $oreCategory) {

                        $entry->update([
                            'is_moon_ore' => $isMoonOre,
                            'is_ice' => $isIce,
                            'is_gas' => $isGas,
                            'is_abyssal' => $isAbyssal,
                            'is_triglavian' => $isTriglavian,
                            'ore_category' => $oreCategory,
                        ]);

                        $updated++;
                    }

                    $progressBar->advance();

                } catch (\Exception $e) {
                    $this->error("\n❌ Error processing entry ID {$entry->id}: {$e->getMessage()}");
                    $errors++;
                    $progressBar->advance();
                }
            }
        });

        $progressBar->finish();
        $this->line('');
        $this->line('');

        // Summary
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║                         SUMMARY                            ║');
        $this->info('╠════════════════════════════════════════════════════════════╣');
        $this->info("║  ✅ Total processed:  {$total}");
        $this->info("║  🔄 Updated:          {$updated}");
        $this->info("║  ⏭️  Skipped:          " . ($total - $updated - $errors));
        $this->info("║  ❌ Errors:           {$errors}");
        $this->info('╚════════════════════════════════════════════════════════════╝');

        return Command::SUCCESS;
    }

    /**
     * Classify an ore into its category string for the `ore_category` column.
     * Mirrors ProcessMiningLedgerCommand::classifyOreCategory but takes the
     * pre-computed flags so we don't double-call TypeIdRegistry checks.
     *
     * Used by analytics filters and dashboard ore-mix charts. Output values:
     *   moon_r4 / moon_r8 / moon_r16 / moon_r32 / moon_r64 / moon
     *   ice
     *   gas
     *   abyssal
     *   triglavian
     *   ore (catch-all for vanilla regular ores)
     *
     * @param int  $typeId
     * @param bool $isMoonOre
     * @param bool $isIce
     * @param bool $isGas
     * @param bool $isAbyssal
     * @param bool $isTriglavian
     * @return string
     */
    private function classifyOreCategory(int $typeId, bool $isMoonOre, bool $isIce, bool $isGas, bool $isAbyssal, bool $isTriglavian): string
    {
        if ($isMoonOre) {
            $rarity = TypeIdRegistry::getMoonOreRarity($typeId);
            return $rarity ? 'moon_' . $rarity : 'moon';
        }
        if ($isIce) return 'ice';
        if ($isGas) return 'gas';
        if ($isAbyssal) return 'abyssal';
        if ($isTriglavian) return 'triglavian';
        return 'ore';
    }
}
