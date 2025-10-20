<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
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

        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();

        $this->info("Calculating taxes for period: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");

        // Get mining activity for the period
        $query = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at');

        if ($characterId = $this->option('character_id')) {
            $query->where('character_id', $characterId);
            $this->info("Calculating for character ID: {$characterId}");
        }

        // Group by character
        $miningByCharacter = $query->get()->groupBy('character_id');

        $this->info("Found mining activity for {$miningByCharacter->count()} characters");

        $calculated = 0;
        $errors = 0;

        foreach ($miningByCharacter as $characterId => $miningRecords) {
            try {
                // Check if tax already exists
                $existingTax = MiningTax::where('character_id', $characterId)
                    ->where('month', $startDate->format('Y-m-01'))
                    ->first();

                if ($existingTax && !$this->option('recalculate')) {
                    $this->line("Skipping character {$characterId} - tax already calculated");
                    continue;
                }

                // Calculate tax using service
                $taxAmount = $this->taxService->calculateMonthlyTax($characterId, $startDate, $endDate);

                if ($existingTax) {
                    $existingTax->update([
                        'amount_owed' => $taxAmount,
                        'calculated_at' => Carbon::now(),
                    ]);
                    $this->line("Recalculated tax for character {$characterId}: " . number_format($taxAmount, 2) . " ISK");
                } else {
                    MiningTax::create([
                        'character_id' => $characterId,
                        'month' => $startDate->format('Y-m-01'),
                        'amount_owed' => $taxAmount,
                        'amount_paid' => 0,
                        'status' => 'unpaid',
                        'calculated_at' => Carbon::now(),
                    ]);
                    $this->line("Calculated tax for character {$characterId}: " . number_format($taxAmount, 2) . " ISK");
                }

                $calculated++;
            } catch (\Exception $e) {
                $this->error("Error calculating tax for character {$characterId}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("Tax calculation complete!");
        $this->info("Calculated: {$calculated} character taxes");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        return Command::SUCCESS;
    }
}
