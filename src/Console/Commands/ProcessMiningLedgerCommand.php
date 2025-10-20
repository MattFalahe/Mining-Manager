<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningLedger;
use Seat\Eveapi\Models\Character\CharacterMiningLedger;
use Carbon\Carbon;

class ProcessMiningLedgerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:process-ledger
                            {--character_id= : Process specific character ID}
                            {--days=30 : Number of days to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process mining ledger data from ESI and populate mining manager tables';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting mining ledger processing...');
        
        $characterId = $this->option('character_id');
        $days = $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);

        // Build query
        $query = CharacterMiningLedger::where('date', '>=', $cutoffDate);
        
        if ($characterId) {
            $query->where('character_id', $characterId);
            $this->info("Processing ledger for character ID: {$characterId}");
        } else {
            $this->info("Processing ledger for all characters");
        }

        $ledgerEntries = $query->get();
        $processed = 0;
        $errors = 0;

        $this->info("Found {$ledgerEntries->count()} ledger entries to process");

        foreach ($ledgerEntries as $entry) {
            try {
                // Check if already processed
                $exists = MiningLedger::where('character_id', $entry->character_id)
                    ->where('date', $entry->date)
                    ->where('type_id', $entry->type_id)
                    ->where('solar_system_id', $entry->solar_system_id)
                    ->exists();

                if (!$exists) {
                    MiningLedger::create([
                        'character_id' => $entry->character_id,
                        'date' => $entry->date,
                        'type_id' => $entry->type_id,
                        'quantity' => $entry->quantity,
                        'solar_system_id' => $entry->solar_system_id,
                        'processed_at' => Carbon::now(),
                    ]);
                    $processed++;
                }
            } catch (\Exception $e) {
                $this->error("Error processing entry: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("Processing complete!");
        $this->info("Processed: {$processed} entries");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        return Command::SUCCESS;
    }
}
