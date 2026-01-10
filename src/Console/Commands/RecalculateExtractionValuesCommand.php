<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Moon\MoonValueCalculationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RecalculateExtractionValuesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:recalculate-extraction-values
                            {--hours=4 : Recalculate values for extractions arriving within this many hours}
                            {--force : Force recalculation even if already done recently}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate moon extraction values 2-3 hours before chunk arrival based on current prices';

    /**
     * Moon value calculation service
     *
     * @var MoonValueCalculationService
     */
    protected $valueService;

    /**
     * Create a new command instance.
     *
     * @param MoonValueCalculationService $valueService
     * @return void
     */
    public function __construct(MoonValueCalculationService $valueService)
    {
        parent::__construct();
        $this->valueService = $valueService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $hours = $this->option('hours');
        $force = $this->option('force');

        $this->info("Mining Manager: Recalculating extraction values for chunks arriving within {$hours} hours...");

        // Find extractions arriving soon
        $now = Carbon::now();
        $futureWindow = $now->copy()->addHours($hours);

        $extractions = MoonExtraction::where('chunk_arrival_time', '>=', $now)
            ->where('chunk_arrival_time', '<=', $futureWindow)
            ->where('status', 'extracting')
            ->whereNotNull('ore_composition')
            ->get();

        if ($extractions->isEmpty()) {
            $this->info("No extractions found arriving within {$hours} hours");
            return 0;
        }

        $this->info("Found {$extractions->count()} extractions to recalculate");

        $recalculated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($extractions as $extraction) {
            try {
                // Skip if already recalculated recently (within last hour), unless forced
                if (!$force && $extraction->value_last_updated && $extraction->value_last_updated->gt(Carbon::now()->subHour())) {
                    $this->line("  Skipped extraction {$extraction->id} (recently updated)");
                    $skipped++;
                    continue;
                }

                // Store the old value for comparison
                $oldValue = $extraction->estimated_value;

                // Recalculate value based on current prices
                $newValue = $this->valueService->calculateExtractionValue($extraction);

                if ($newValue === null) {
                    $this->warn("  Could not calculate value for extraction {$extraction->id}");
                    $failed++;
                    continue;
                }

                // Update the extraction
                $extraction->update([
                    'estimated_value' => $newValue,
                    'estimated_value_pre_arrival' => $newValue,
                    'value_last_updated' => Carbon::now(),
                ]);

                // Calculate and display change
                $change = 0;
                $changePercent = 0;
                if ($oldValue > 0) {
                    $change = $newValue - $oldValue;
                    $changePercent = ($change / $oldValue) * 100;
                }

                $changeIndicator = $change > 0 ? '↑' : ($change < 0 ? '↓' : '=');
                $changeColor = $change > 0 ? 'green' : ($change < 0 ? 'red' : 'yellow');

                $this->line(sprintf(
                    "  ✓ Extraction %d: %s ISK → %s ISK %s %s (%+.2f%%)",
                    $extraction->id,
                    number_format($oldValue),
                    number_format($newValue),
                    $changeIndicator,
                    number_format(abs($change)),
                    $changePercent
                ));

                $recalculated++;

                Log::info("Mining Manager: Recalculated pre-arrival value for extraction {$extraction->id}", [
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'change' => $change,
                    'change_percent' => $changePercent,
                    'chunk_arrival' => $extraction->chunk_arrival_time->toDateTimeString(),
                ]);

            } catch (\Exception $e) {
                $this->error("  ✗ Failed to recalculate extraction {$extraction->id}: " . $e->getMessage());
                Log::error("Mining Manager: Failed to recalculate extraction {$extraction->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $failed++;
            }
        }

        $this->info("\nRecalculation complete:");
        $this->info("  Recalculated: {$recalculated}");
        if ($skipped > 0) {
            $this->info("  Skipped: {$skipped}");
        }
        if ($failed > 0) {
            $this->error("  Failed: {$failed}");
        }

        return 0;
    }
}
