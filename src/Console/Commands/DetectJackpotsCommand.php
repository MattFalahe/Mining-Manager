<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Moon\MoonOreHelper;

class DetectJackpotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:detect-jackpots
                            {--all : Check all extractions, not just recent ones}
                            {--days=30 : Number of days to check (default: 30)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect jackpot moon extractions based on mining ledger data';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting jackpot detection...');

        $checkAll = $this->option('all');
        $days = $this->option('days');

        // Get extractions to check
        $query = MoonExtraction::query();
        
        if (!$checkAll) {
            $query->where('chunk_arrival_time', '>=', now()->subDays($days));
        }

        $extractions = $query->get();
        $total = $extractions->count();

        if ($total === 0) {
            $this->warn('No extractions found to check.');
            return Command::SUCCESS;
        }

        $this->info("Checking {$total} extractions...");

        $detected = 0;
        $alreadyMarked = 0;
        $noData = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($extractions as $extraction) {
            // Check if extraction already marked as jackpot
            if ($extraction->is_jackpot) {
                $alreadyMarked++;
                $bar->advance();
                continue;
            }

            // Check mining ledger for jackpot ores
            $hasJackpot = $this->checkForJackpotOres($extraction);

            if ($hasJackpot) {
                $extraction->is_jackpot = true;
                $extraction->save();
                $detected++;
                
                $this->newLine();
                $this->info("  ⭐ JACKPOT DETECTED: {$extraction->moon_name}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Jackpot detection complete!');
        $this->table(
            ['Status', 'Count'],
            [
                ['New jackpots detected', $detected],
                ['Already marked as jackpot', $alreadyMarked],
                ['No jackpot', $total - $detected - $alreadyMarked],
            ]
        );

        if ($detected > 0) {
            $this->info("💎 Found {$detected} new jackpot extractions!");
        }

        return Command::SUCCESS;
    }

    /**
     * Check if extraction has jackpot ores in mining ledger
     *
     * @param MoonExtraction $extraction
     * @return bool
     */
    private function checkForJackpotOres(MoonExtraction $extraction): bool
    {
        // Get the solar system for this structure
        $structure = \DB::table('universe_structures')
            ->where('structure_id', $extraction->structure_id)
            ->first();

        if (!$structure) {
            return false;
        }

        // Check mining ledger for jackpot ore type IDs
        $hasJackpot = MiningLedger::where('solar_system_id', $structure->solar_system_id)
            ->whereBetween('date', [
                $extraction->chunk_arrival_time->toDateString(),
                $extraction->natural_decay_time->toDateString()
            ])
            ->whereIn('type_id', MoonOreHelper::getAllJackpotTypeIds())
            ->exists();

        return $hasJackpot;
    }
}
