<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Tax\TaxCalculationService;
use Carbon\Carbon;

class CalculateMonthlyTaxesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:calculate-taxes
                            {--month= : Month to calculate (YYYY-MM format)}
                            {--character_id= : Calculate for specific character}
                            {--recalculate : Recalculate existing tax records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate monthly mining taxes for characters based on their mining activity';

    /**
     * Tax calculation service
     *
     * @var TaxCalculationService
     */
    protected $taxService;

    /**
     * Create a new command instance.
     *
     * @param TaxCalculationService $taxService
     */
    public function __construct(TaxCalculationService $taxService)
    {
        parent::__construct();
        $this->taxService = $taxService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting tax calculation...');

        // Determine which month to process
        $month = $this->option('month')
            ? Carbon::parse($this->option('month') . '-01')
            : Carbon::now()->subMonth();

        $recalculate = (bool) $this->option('recalculate');

        $this->info("Calculating taxes for period: {$month->copy()->startOfMonth()->format('Y-m-d')} to {$month->copy()->endOfMonth()->format('Y-m-d')}");

        if ($characterId = $this->option('character_id')) {
            $this->info("Calculating for character ID: {$characterId}");

            // For a single character, use recalculateTax directly
            try {
                $taxAmount = $this->taxService->recalculateTax((int) $characterId, $month);
                $this->info("Calculated tax for character {$characterId}: " . number_format($taxAmount, 2) . " ISK");
            } catch (\Exception $e) {
                $this->error("Error calculating tax for character {$characterId}: {$e->getMessage()}");
                return Command::FAILURE;
            }
        } else {
            // Use the service's calculateMonthlyTaxes which handles
            // individual vs accumulated mode, grouping, and all business logic
            try {
                $results = $this->taxService->calculateMonthlyTaxes($month, $recalculate);

                $this->info("Tax calculation complete!");
                $this->info("Method: {$results['method']}");
                $this->info("Calculated: {$results['count']} tax records");
                $this->info("Total: " . number_format($results['total'], 2) . " ISK");

                if (!empty($results['errors'])) {
                    $this->warn("Errors: " . count($results['errors']));
                    foreach ($results['errors'] as $error) {
                        $this->error("  Character {$error['character_id']}: {$error['error']}");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Tax calculation failed: {$e->getMessage()}");
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
