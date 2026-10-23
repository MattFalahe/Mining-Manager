<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
                            {--period-type= : Override period type (monthly|biweekly; "weekly" is accepted but silently maps to monthly — legacy)}
                            {--character_id= : Calculate one character\'s account (its main and alts)}
                            {--corporation_id= : Before tax profiles: read settings as this structure-owner corporation}
                            {--recalculate : Recalculate existing tax records}
                            {--force : Run even if today is not a period boundary}';

    protected $description = 'Calculate mining taxes based on configured period (monthly or biweekly)';

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
        $lock = Cache::lock('mining-manager:calculate-taxes', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return self::SUCCESS;
        }

        try {
        // Bills already raised go overdue on time every day, so this runs
        // before anything below can skip the run or fail it. Most days are not
        // a period boundary, and Send Tax Reminders, the only other daily
        // caller, can be switched off.
        try {
            $overdueCount = $this->taxService->updateOverdueTaxes();
            if ($overdueCount > 0) {
                $this->info("Marked {$overdueCount} taxes as overdue");
            }
        } catch (\Exception $e) {
            $this->warn("Failed to update overdue statuses: {$e->getMessage()}");
        }

        // Check feature flag
        $features = $this->settingsService->getFeatureFlags();
        if (!($features['auto_calculate_taxes'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $recalculate = (bool) $this->option('recalculate');
        $force = (bool) $this->option('force');

        // If a specific corporation is provided, set it as active context
        if ($corporationId = $this->option('corporation_id')) {
            if ($this->settingsService->usesTaxProfiles()) {
                $this->warn('Tax profiles decide every account\'s settings, so --corporation_id changes nothing.');
            } else {
                $this->settingsService->setActiveCorporation((int) $corporationId);
                $this->info("Using corporation context: {$corporationId}");
            }
        }

        // Each run is a period type and the period it bills.
        $runs = [];

        if ($this->option('period-start')) {
            // Explicit period start date provided. Each tax profile bills on
            // its own period, so with none named every period in use is
            // billed for that date.
            $periodDate = Carbon::parse($this->option('period-start'));
            $periodTypes = $this->option('period-type')
                ? [$this->option('period-type')]
                : $this->periodHelper->periodTypesInUse();

            foreach ($periodTypes as $periodType) {
                $runs[] = [$periodType, ...$this->periodHelper->getPeriodBounds($periodDate, $periodType)];
            }
        } elseif ($this->option('month')) {
            // Legacy --month option (always monthly)
            $month = Carbon::parse($this->option('month') . '-01');
            $runs[] = ['monthly', $month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        } else {
            // Automatic: the previous completed period of every billing period
            // the tax profiles use, on the days it falls due. A run for one
            // period bills only the accounts on it.
            $periodTypes = $this->option('period-type')
                ? [$this->option('period-type')]
                : $this->periodHelper->periodTypesInUse();

            foreach ($periodTypes as $periodType) {
                if ($force || $this->periodHelper->shouldCalculateToday($periodType)) {
                    $runs[] = [$periodType, ...$this->periodHelper->getPreviousCompletedPeriod($periodType)];
                }
            }

            if (!$runs) {
                $this->info('Today is not a ' . implode(' or ', $periodTypes) . ' boundary. Skipping. Use --force to override.');
                return Command::SUCCESS;
            }
        }

        $failed = false;
        foreach ($runs as [$periodType, $startDate, $endDate]) {
            $failed = !$this->calculatePeriod($periodType, $startDate, $endDate, $recalculate) || $failed;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * Bill one period, for every account on it or for one account.
     */
    private function calculatePeriod(string $periodType, Carbon $startDate, Carbon $endDate, bool $recalculate): bool
    {
        $periodLabel = $this->periodHelper->formatPeriod($startDate, $endDate, $periodType);
        $this->info("Calculating {$periodType} taxes for: {$periodLabel} ({$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')})");

        if ($characterId = $this->option('character_id')) {
            // A bill covers an account, so this is the character's main and alts,
            // worked out exactly as the run over everyone would.
            $this->info("Calculating for the account of character ID: {$characterId}");

            try {
                $results = $this->taxService->calculateTaxes($startDate, $endDate, $periodType, $recalculate, 'Manual: console', (int) $characterId);

                $this->info("Calculated: {$results['count']} tax record(s), total " . number_format($results['total'], 2) . " ISK");

                if ($results['count'] === 0 && !$recalculate) {
                    $this->line('An account that already has a bill for this period is only worked out again with --recalculate.');
                }

                foreach ($results['errors'] ?? [] as $error) {
                    $this->error("  Character {$error['character_id']}: {$error['error']}");
                }

                if (!empty($results['errors'])) {
                    return false;
                }
            } catch (\Exception $e) {
                $this->error("Error calculating tax for character {$characterId}: {$e->getMessage()}");
                return false;
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

                // Send notifications if any taxes were created
                if ($results['count'] > 0) {
                    $dueDate = $this->periodHelper->calculateDueDate($endDate);
                    $dueDateStr = $dueDate?->format('Y-m-d');

                    // Director summary notification
                    try {
                        app(NotificationService::class)->sendTaxGenerated(
                            $periodLabel,
                            $results['count'],
                            $results['total'],
                            $periodType,
                            $dueDateStr
                        );
                    } catch (\Exception $e) {
                        $this->warn("Tax generated notification failed: {$e->getMessage()}");
                    }

                    // Member-facing announcement
                    try {
                        app(NotificationService::class)->sendTaxAnnouncement(
                            $periodLabel,
                            $periodType,
                            $dueDateStr
                        );
                    } catch (\Exception $e) {
                        $this->warn("Tax announcement notification failed: {$e->getMessage()}");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Tax calculation failed: {$e->getMessage()}");
                return false;
            }
        }

        return true;
    }
}
