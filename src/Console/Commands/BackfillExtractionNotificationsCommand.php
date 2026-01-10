<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;
use MiningManager\Models\MoonExtraction;

class BackfillExtractionNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:backfill-extraction-notifications
                            {--limit=100 : Maximum number of extractions to process}
                            {--structure= : Only process specific structure ID}
                            {--days=90 : Look back this many days for notifications}
                            {--dry-run : Show what would be updated}
                            {--force : Overwrite existing notification data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill ore composition data from character notifications for existing moon extractions';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $limit = $this->option('limit');
        $structureId = $this->option('structure');
        $days = $this->option('days');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info("Backfilling extraction notification data...");
        if ($dryRun) {
            $this->warn("DRY RUN MODE - No changes will be made");
        }

        // Build query for extractions to process
        $query = MoonExtraction::query()
            ->where('extraction_start_time', '>=', Carbon::now()->subDays($days));

        if ($structureId) {
            $query->where('structure_id', $structureId);
        }

        // Only process extractions without notification data unless force is used
        if (!$force) {
            $query->where(function($q) {
                $q->where('has_notification_data', false)
                  ->orWhereNull('has_notification_data');
            });
        }

        $extractions = $query->limit($limit)->get();

        if ($extractions->isEmpty()) {
            $this->info("No extractions found to process.");
            return 0;
        }

        $this->info("Found {$extractions->count()} extractions to process");

        $progressBar = $this->output->createProgressBar($extractions->count());
        $progressBar->start();

        $processed = 0;
        $updated = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($extractions as $extraction) {
            $progressBar->advance();

            try {
                $notificationData = $this->findNotificationForExtraction(
                    $extraction->structure_id,
                    $extraction->extraction_start_time
                );

                if (!$notificationData) {
                    $notFound++;
                    Log::debug("No notification found for extraction {$extraction->id} at structure {$extraction->structure_id}");
                    continue;
                }

                // Parse the notification YAML
                $data = Yaml::parse($notificationData->text);

                if (!isset($data['oreVolumeByType'])) {
                    $this->warn("\nNotification found but no ore volume data for extraction {$extraction->id}");
                    $notFound++;
                    continue;
                }

                // Get current ore composition
                $oreComposition = is_string($extraction->ore_composition)
                    ? json_decode($extraction->ore_composition, true)
                    : $extraction->ore_composition;
                $oreComposition = $oreComposition ?? [];

                // Update with actual volumes
                $updated_composition = $this->updateCompositionWithActualVolumes(
                    $oreComposition,
                    $data['oreVolumeByType']
                );

                if (!$dryRun) {
                    // Update the extraction record
                    $extraction->ore_composition = json_encode($updated_composition);
                    $extraction->has_notification_data = true;
                    $extraction->save();

                    // Recalculate value with actual quantities
                    $this->recalculateExtractionValue($extraction);
                }

                $updated++;
                $this->newLine();
                $this->info("Updated extraction {$extraction->id} with notification data");

            } catch (\Exception $e) {
                $errors++;
                Log::error("Error processing extraction {$extraction->id}: " . $e->getMessage());
                $this->error("\nError processing extraction {$extraction->id}: " . $e->getMessage());
            }

            $processed++;
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info("Backfill complete!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $processed],
                ['Updated', $updated],
                ['Notifications Not Found', $notFound],
                ['Errors', $errors],
            ]
        );

        return 0;
    }

    /**
     * Find the notification for a specific extraction
     *
     * @param int $structureId
     * @param string $extractionStartTime
     * @return object|null
     */
    private function findNotificationForExtraction(int $structureId, string $extractionStartTime): ?object
    {
        // Look for MoonminingExtractionStarted notification within 5 minutes of extraction start
        $notification = DB::table('character_notifications')
            ->where('type', 'MoonminingExtractionStarted')
            ->where('text', 'LIKE', '%structureID: ' . $structureId . '%')
            ->where('timestamp', '>=', Carbon::parse($extractionStartTime)->subMinutes(5))
            ->where('timestamp', '<=', Carbon::parse($extractionStartTime)->addMinutes(5))
            ->orderBy('timestamp', 'desc')
            ->first();

        return $notification;
    }

    /**
     * Update ore composition with actual volumes from notification
     *
     * @param array $composition
     * @param array $oreVolumes
     * @return array
     */
    private function updateCompositionWithActualVolumes(array $composition, array $oreVolumes): array
    {
        foreach ($oreVolumes as $typeId => $volumeM3) {
            // Find this ore in the composition
            foreach ($composition as $oreName => &$oreData) {
                if ($oreData['type_id'] == $typeId) {
                    // Get unit volume for this ore type
                    $unitVolume = $this->getOreUnitVolume($typeId);

                    // Calculate quantity in units
                    $quantityInUnits = $unitVolume > 0 ? ($volumeM3 / $unitVolume) : 0;

                    // Update with actual data
                    $oreData['quantity'] = $quantityInUnits;
                    $oreData['volume_m3'] = $volumeM3;

                    // Calculate value for this ore
                    $oreData['value'] = $this->calculateOreValue($typeId, $quantityInUnits);

                    Log::debug("Updated {$oreName} (type {$typeId}): {$quantityInUnits} units ({$volumeM3} m³), value: " . number_format($oreData['value'], 0) . " ISK");
                    break;
                }
            }
        }

        return $composition;
    }

    /**
     * Calculate value for a specific ore type and quantity
     *
     * @param int $typeId
     * @param float $quantity
     * @return float
     */
    private function calculateOreValue(int $typeId, float $quantity): float
    {
        try {
            $valueService = app(\MiningManager\Services\Moon\MoonValueCalculationService::class);

            $value = $valueService->calculateRefinedValue($typeId, $quantity);

            return $value > 0 ? $value : 0;
        } catch (\Exception $e) {
            Log::warning("Could not calculate value for type {$typeId}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get the unit volume for an ore type
     *
     * @param int $typeId
     * @return float
     */
    private function getOreUnitVolume(int $typeId): float
    {
        $oreType = DB::table('invTypes')
            ->where('typeID', $typeId)
            ->first();

        return $oreType ? (float)$oreType->volume : 16.0; // Default to 16 m³
    }

    /**
     * Recalculate extraction value with updated quantities
     *
     * @param MoonExtraction $extraction
     * @return void
     */
    private function recalculateExtractionValue(MoonExtraction $extraction): void
    {
        try {
            // Get the value calculation service
            $valueService = app(\MiningManager\Services\Moon\MoonValueCalculationService::class);

            // Recalculate value using the extraction object
            $newValue = $valueService->calculateExtractionValue($extraction);

            if ($newValue === null) {
                Log::warning("Could not calculate value for extraction {$extraction->id}");
                return;
            }

            // Update the extraction
            $extraction->estimated_value = $newValue;

            // If this is the first time we're adding notification data, store as "at start" value
            if (!$extraction->estimated_value_at_start) {
                $extraction->estimated_value_at_start = $newValue;
            }

            $extraction->value_last_updated = Carbon::now();
            $extraction->save();

            Log::info("Recalculated value for extraction {$extraction->id}: " . number_format($newValue, 2) . " ISK");

        } catch (\Exception $e) {
            Log::error("Failed to recalculate value for extraction {$extraction->id}: " . $e->getMessage());
        }
    }
}
