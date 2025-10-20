<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Moon\MoonExtractionService;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use Carbon\Carbon;

class UpdateMoonExtractionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:update-extractions
                            {--structure_id= : Update specific structure}
                            {--corporation_id= : Update structures for specific corporation}
                            {--active-only : Only update active extractions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update moon extraction data from corporation structures';

    /**
     * Moon extraction service
     *
     * @var MoonExtractionService
     */
    protected $extractionService;

    /**
     * Create a new command instance.
     *
     * @param MoonExtractionService $extractionService
     */
    public function __construct(MoonExtractionService $extractionService)
    {
        parent::__construct();
        $this->extractionService = $extractionService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting moon extraction update...');

        // Build query for refineries
        $query = CorporationStructure::where('type_id', 35835); // Athanor
        
        // Also include Tatara refineries
        $query->orWhere('type_id', 35836);

        if ($structureId = $this->option('structure_id')) {
            $query->where('structure_id', $structureId);
            $this->info("Updating structure ID: {$structureId}");
        }

        if ($corporationId = $this->option('corporation_id')) {
            $query->where('corporation_id', $corporationId);
            $this->info("Updating structures for corporation ID: {$corporationId}");
        }

        $structures = $query->get();

        if ($structures->isEmpty()) {
            $this->warn('No refineries found');
            return Command::SUCCESS;
        }

        $this->info("Found {$structures->count()} refinery structures");

        $updated = 0;
        $created = 0;
        $errors = 0;

        foreach ($structures as $structure) {
            try {
                $this->line("Processing structure: {$structure->name}");

                // Fetch extraction data from ESI via service
                $extractionData = $this->extractionService->fetchExtractionData($structure->structure_id);

                if (empty($extractionData)) {
                    $this->line("  No active extractions");
                    continue;
                }

                foreach ($extractionData as $extraction) {
                    // Check if extraction already exists
                    $existing = MoonExtraction::where('structure_id', $structure->structure_id)
                        ->where('extraction_start_time', $extraction['extraction_start_time'])
                        ->first();

                    if ($existing) {
                        // Update existing record
                        $existing->update([
                            'chunk_arrival_time' => $extraction['chunk_arrival_time'],
                            'natural_decay_time' => $extraction['natural_decay_time'],
                            'status' => $this->determineStatus($extraction),
                            'moon_id' => $extraction['moon_id'] ?? null,
                            'updated_at' => Carbon::now(),
                        ]);
                        $this->line("  Updated extraction (chunk arrival: {$extraction['chunk_arrival_time']})");
                        $updated++;
                    } else {
                        // Create new record
                        MoonExtraction::create([
                            'structure_id' => $structure->structure_id,
                            'corporation_id' => $structure->corporation_id,
                            'moon_id' => $extraction['moon_id'] ?? null,
                            'extraction_start_time' => $extraction['extraction_start_time'],
                            'chunk_arrival_time' => $extraction['chunk_arrival_time'],
                            'natural_decay_time' => $extraction['natural_decay_time'],
                            'status' => $this->determineStatus($extraction),
                        ]);
                        $this->line("  Created new extraction (chunk arrival: {$extraction['chunk_arrival_time']})");
                        $created++;
                    }
                }

            } catch (\Exception $e) {
                $this->error("Error processing structure {$structure->name}: {$e->getMessage()}");
                $errors++;
            }
        }

        // Update status of past extractions
        $this->updatePastExtractions();

        $this->info("\nMoon extraction update complete!");
        $this->info("Created: {$created} new extractions");
        $this->info("Updated: {$updated} existing extractions");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        return Command::SUCCESS;
    }

    /**
     * Determine extraction status based on times
     *
     * @param array $extraction
     * @return string
     */
    private function determineStatus(array $extraction): string
    {
        $now = Carbon::now();
        $chunkArrival = Carbon::parse($extraction['chunk_arrival_time']);
        $naturalDecay = Carbon::parse($extraction['natural_decay_time']);

        if ($now < $chunkArrival) {
            return 'extracting';
        } elseif ($now >= $chunkArrival && $now < $naturalDecay) {
            return 'ready';
        } else {
            return 'expired';
        }
    }

    /**
     * Update status of past extractions
     *
     * @return void
     */
    private function updatePastExtractions(): void
    {
        $now = Carbon::now();

        // Mark as expired if past natural decay time
        $expired = MoonExtraction::where('status', '!=', 'expired')
            ->where('natural_decay_time', '<', $now)
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            $this->info("Marked {$expired} extractions as expired");
        }

        // Mark as ready if chunk has arrived but not expired
        $ready = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '<=', $now)
            ->where('natural_decay_time', '>', $now)
            ->update(['status' => 'ready']);

        if ($ready > 0) {
            $this->info("Marked {$ready} extractions as ready");
        }
    }
}
