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

    protected $description = 'Backfill is_moon_ore, is_ice, and is_gas flags for existing mining ledger entries';

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
                    // Classify ore type using TypeIdRegistry
                    $isMoonOre = TypeIdRegistry::isMoonOre($entry->type_id);
                    $isIce = TypeIdRegistry::isIce($entry->type_id);
                    $isGas = TypeIdRegistry::isGas($entry->type_id);

                    // Only update if values have changed
                    if ($entry->is_moon_ore != $isMoonOre ||
                        $entry->is_ice != $isIce ||
                        $entry->is_gas != $isGas) {

                        $entry->update([
                            'is_moon_ore' => $isMoonOre,
                            'is_ice' => $isIce,
                            'is_gas' => $isGas,
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
}
