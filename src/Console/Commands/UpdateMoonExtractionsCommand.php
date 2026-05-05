<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
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
        // Mutex lock — schedule entry has allow_overlap: false but that
        // only serialises the same schedule. Manual artisan runs or other
        // invocation paths can still overlap a running cron. Cache::lock
        // gives process-wide serialisation; matches the pattern used by
        // ProcessMiningLedgerCommand, DetectJackpotsCommand, etc.
        $lock = Cache::lock('mining-manager:update-extractions', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return self::SUCCESS;
        }

        try {
            return $this->handleWithLock();
        } finally {
            $lock->release();
        }
    }

    private function handleWithLock(): int
    {
        // Check feature flag
        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $features = $settingsService->getFeatureFlags();
        if (!($features['enable_moon_tracking'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $this->info('Starting moon extraction update...');

        // Build query for refineries (Athanor + Tatara)
        $query = CorporationStructure::whereIn('type_id', [35835, 35836]);

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
                            'ore_composition' => $extraction['ore_composition'] ?? null,
                            'updated_at' => Carbon::now(),
                        ]);
                        $this->line("  Updated extraction (chunk arrival: {$extraction['chunk_arrival_time']})");
                        $updated++;
                    } else {
                        // Create new record - wrapped in try/catch for race condition
                        // protection against the unique constraint on (structure_id, extraction_start_time)
                        try {
                            MoonExtraction::create([
                                'structure_id' => $structure->structure_id,
                                'corporation_id' => $structure->corporation_id,
                                'moon_id' => $extraction['moon_id'] ?? null,
                                'extraction_start_time' => $extraction['extraction_start_time'],
                                'chunk_arrival_time' => $extraction['chunk_arrival_time'],
                                'natural_decay_time' => $extraction['natural_decay_time'],
                                'status' => $this->determineStatus($extraction),
                                'ore_composition' => $extraction['ore_composition'] ?? null,
                            ]);
                            $this->line("  Created new extraction (chunk arrival: {$extraction['chunk_arrival_time']})");
                            $created++;
                        } catch (QueryException $e) {
                            // Unique constraint violation - another process created it first
                            if (str_contains($e->getMessage(), 'Duplicate entry') || $e->getCode() === '23000') {
                                $this->line("  Skipped duplicate extraction (chunk arrival: {$extraction['chunk_arrival_time']})");
                            } else {
                                throw $e; // Re-throw non-duplicate errors
                            }
                        }
                    }
                }

            } catch (\Exception $e) {
                $this->error("Error processing structure {$structure->name}: {$e->getMessage()}");
                $errors++;
            }
        }

        // Update status of past extractions — delegated to the service so that
        // moon arrival notifications fire on the extracting → ready transition.
        // (Previously this command had a private updatePastExtractions() that
        // duplicated the status flip without calling the notification dispatcher,
        // causing moon arrival notifications to silently never fire.)
        $this->extractionService->updateExtractionStatuses();

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

}
