<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningTax;
use MiningManager\Services\Tax\TaxCodeGeneratorService;
use MiningManager\Services\Configuration\SettingsManagerService;
use Carbon\Carbon;

class GenerateTaxCodesCommand extends Command
{
    protected $signature = 'mining-manager:generate-tax-codes
                            {--month= : Month to generate codes for (YYYY-MM format)}';

    protected $description = 'Generate payment codes for unpaid tax records (no recalculation)';

    protected TaxCodeGeneratorService $codeService;
    protected SettingsManagerService $settingsService;

    public function __construct(
        TaxCodeGeneratorService $codeService,
        SettingsManagerService $settingsService
    ) {
        parent::__construct();
        $this->codeService = $codeService;
        $this->settingsService = $settingsService;
    }

    public function handle()
    {
        // Set corporation context
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        if ($moonOwnerCorpId) {
            $this->settingsService->setActiveCorporation((int) $moonOwnerCorpId);
        }

        // Determine month
        if ($this->option('month')) {
            $monthDate = Carbon::parse($this->option('month') . '-01')->startOfMonth();
        } else {
            $monthDate = Carbon::now()->subMonth()->startOfMonth();
        }

        $monthLabel = $monthDate->format('F Y');
        $this->info("Generating tax codes for: {$monthLabel}");

        // Get unpaid taxes without active codes
        $taxIds = MiningTax::where('month', $monthDate->format('Y-m-01'))
            ->whereIn('status', ['unpaid', 'overdue'])
            ->whereDoesntHave('taxCodes', function ($q) {
                $q->where('status', 'active');
            })
            ->pluck('id')
            ->toArray();

        if (empty($taxIds)) {
            $this->info('No unpaid taxes found that need codes.');
            return Command::SUCCESS;
        }

        $this->info("Found " . count($taxIds) . " tax records needing codes.");

        $results = $this->codeService->generateBulkTaxCodes($taxIds);

        $this->info("Generated: {$results['generated']} codes");

        if (!empty($results['errors'])) {
            $this->warn("Errors: " . count($results['errors']));
            foreach ($results['errors'] as $error) {
                $this->error("  Tax #{$error['tax_id']}: {$error['error']}");
            }
        }

        return Command::SUCCESS;
    }
}
