<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Tax\TaxCalculationService;
use MiningManager\Services\Tax\TaxPeriodHelper;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Notification\NotificationService;
use Carbon\Carbon;

class CalculateMonthlyTaxesCommand extends Command
{
    protected $signature = 'mining-manager:calculate-taxes
                            {--month= : Month to calculate (YYYY-MM format, monthly period only)}
                            {--period-start= : Period start date (YYYY-MM-DD, for any period type)}
                            {--period-type= : Override period type (monthly|biweekly|weekly)}
                            {--character_id= : Calculate for specific character}
                            {--corporation_id= : Calculate for specific structure-owner corporation}
                            {--recalculate : Recalculate existing tax records}
                            {--force : Run even if today is not a period boundary}';

    protected $description = 'Calculate mining taxes based on configured period (monthly, biweekly, or weekly)';

    protected TaxCalculationService $taxService;
    protected SettingsManagerService $settingsService;
    protected TaxPeriodHelper $periodHelper;

    public function __construct(
        TaxCalculationService $taxService,
        SettingsManagerService $settingsService,
        TaxPeriodHelper $periodHelper
    ) {
        parent::__construct();
        $this->taxService = $taxService;
        $this->settingsService = $settingsService;
        $this->periodHelper = $periodHelper;
    }

    public function handle()
    {
        // Check feature flag
        $features = $this->settingsService->getFeatureFlags();
        if (!($features['auto_calculate_taxes'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        // Determine period type from option or settings
        $periodType = $this->option('period-type') ?? $this->periodHelper->getConfiguredPeriodType();
        $recalculate = (bool) $this->option('recalculate');
        $force = (bool) $this->option('force');

        // If a specific corporation is provided, set it as active context
        if ($corporationId = $this->option('corporation_id')) {
            $this->settingsService->setActiveCorporation((int) $corporationId);
            $this->info("Using corporation context: {$corporationId}");
        }

        // Determine the period to calculate
        if ($this->option('period-start')) {
            // Explicit period start date provided
            $periodDate = Carbon::parse($this->option('period-start'));
            [$startDate, $endDate] = $this->periodHelper->getPeriodBounds($periodDate, $periodType);
        } elseif ($this->option('month')) {
            // Legacy --month option (always monthly)
            $month = Carbon::parse($this->option('month') . '-01');
            $startDate = $month->copy()->startOfMonth();
            $endDate = $month->copy()->endOfMonth();
            $periodType = 'monthly';
        } else {
            // Automatic: calculate the previous completed period
            // But only if today is a period boundary (or --force is used)
            if (!$force && !$this->periodHelper->shouldCalculateToday($periodType)) {
                $this->info("Today is not a {$periodType} boundary. Skipping. Use --force to override.");
                return Command::SUCCESS;
            }

            [$startDate, $endDate] = $this->periodHelper->getPreviousCompletedPeriod($periodType);
        }

        $periodLabel = $this->periodHelper->formatPeriod($startDate, $endDate, $periodType);
        $this->info("Calculating {$periodType} taxes for: {$periodLabel} ({$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')})");

        if ($characterId = $this->option('character_id')) {
            $this->info("Calculating for character ID: {$characterId}");

            try {
                $taxAmount = $this->taxService->recalculateTax((int) $characterId, $startDate);
                $this->info("Calculated tax for character {$characterId}: " . number_format($taxAmount, 2) . " ISK");
            } catch (\Exception $e) {
                $this->error("Error calculating tax for character {$characterId}: {$e->getMessage()}");
                return Command::FAILURE;
            }
        } else {
            try {
                $triggeredBy = app()->runningInConsole() ? 'Scheduled Task' : 'System';
                $results = $this->taxService->calculateTaxes($startDate, $endDate, $periodType, $recalculate, $triggeredBy);

                $this->info("Tax calculation complete!");
                $this->info("Period type: {$periodType}");
                $this->info("Method: {$results['method']}");
                $this->info("Calculated: {$results['count']} tax records");
                $this->info("Total: " . number_format($results['total'], 2) . " ISK");

                if (!empty($results['errors'])) {
                    $this->warn("Errors: " . count($results['errors']));
                    foreach ($results['errors'] as $error) {
                        $this->error("  Character {$error['character_id']}: {$error['error']}");
                    }
                }

                // Send "taxes generated" notification if any taxes were created
                if ($results['count'] > 0) {
                    try {
                        $dueDate = $this->periodHelper->calculateDueDate($endDate);
                        app(NotificationService::class)->sendTaxGenerated(
                            $periodLabel,
                            $results['count'],
                            $results['total'],
                            $periodType,
                            $dueDate?->format('Y-m-d')
                        );
                    } catch (\Exception $e) {
                        $this->warn("Tax generated notification failed: {$e->getMessage()}");
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
