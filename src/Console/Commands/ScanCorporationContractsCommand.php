<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Tax\ContractManagementService;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Facades\Log;

class ScanCorporationContractsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:scan-corporation-contracts
                            {--corporation_id= : Specific corporation ID to scan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan corporation contracts for tax payment matches (by tax code in title)';

    /**
     * Contract management service
     *
     * @var ContractManagementService
     */
    protected $contractService;

    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Create a new command instance.
     *
     * @param ContractManagementService $contractService
     * @param SettingsManagerService $settingsService
     */
    public function __construct(ContractManagementService $contractService, SettingsManagerService $settingsService)
    {
        parent::__construct();
        $this->contractService = $contractService;
        $this->settingsService = $settingsService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $corporationId = $this->option('corporation_id')
            ?? $this->settingsService->getSetting('general.moon_owner_corporation_id');

        if (!$corporationId) {
            $this->warn('No corporation ID configured. Set general.moon_owner_corporation_id in settings or use --corporation_id option.');
            return 1;
        }

        // Set corporation context
        $this->settingsService->setActiveCorporation($corporationId);
        $this->contractService->setCorporationContext($corporationId);

        // Check if contract payment method is enabled
        $paymentMethod = $this->settingsService->getSetting('tax_rates.tax_payment_method', 'contract');
        if ($paymentMethod !== 'contract') {
            $this->info("Skipping contract scan - payment method is '{$paymentMethod}' (not 'contract'). Change in Settings > Tax Rates to enable.");
            return 0;
        }

        $this->info("Scanning corporation contracts for corp ID: {$corporationId}...");

        try {
            $results = $this->contractService->scanCorporationContracts($corporationId);

            $this->info("Scan complete:");
            $this->line("  Contracts scanned: {$results['scanned']}");
            $this->line("  Matched to tax codes: {$results['matched']}");
            $this->line("  Marked as paid: {$results['paid']}");

            if (!empty($results['errors'])) {
                $this->warn("  Errors: " . count($results['errors']));
                foreach ($results['errors'] as $error) {
                    $this->error("    Contract {$error['contract_id']}: {$error['error']}");
                }
            }

            if ($results['matched'] > 0 || $results['paid'] > 0) {
                Log::info("Mining Manager: Corporation contract scan - Scanned: {$results['scanned']}, Matched: {$results['matched']}, Paid: {$results['paid']}");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('Error scanning contracts: ' . $e->getMessage());
            Log::error('Mining Manager: Contract scan command error: ' . $e->getMessage());
            return 1;
        }
    }
}
