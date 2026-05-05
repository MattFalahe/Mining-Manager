<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
        $lock = Cache::lock('mining-manager:generate-tax-codes', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
            // Set corporation context
            $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
            if ($moonOwnerCorpId) {
                $this->settingsService->setActiveCorporation((int) $moonOwnerCorpId);
            }

            // Determine scope — if --month is given, filter to that month.
            // Otherwise find ALL unpaid taxes across any month/period that need codes.
            // (The old default of subMonth() missed bi-weekly periods in the current month.)
            if ($this->option('month')) {
                $monthDate = Carbon::parse($this->option('month') . '-01')->startOfMonth();
                $monthLabel = $monthDate->format('F Y');
                $this->info("Generating tax codes for: {$monthLabel}");

                $taxIds = MiningTax::where('month', $monthDate->format('Y-m-01'))
                    ->whereIn('status', ['unpaid', 'overdue'])
                    ->whereDoesntHave('taxCodes', function ($q) {
                        $q->where('status', 'active');
                    })
                    ->pluck('id')
                    ->toArray();
            } else {
                $this->info("Generating tax codes for ALL unpaid taxes missing active codes...");

                // Find any unpaid/overdue tax without an active code, regardless of month.
                // This covers monthly, biweekly, and weekly periods without assumptions.
                $taxIds = MiningTax::whereIn('status', ['unpaid', 'overdue'])
                    ->whereDoesntHave('taxCodes', function ($q) {
                        $q->where('status', 'active');
                    })
                    ->pluck('id')
                    ->toArray();
            }

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
        } finally {
            $lock->release();
        }
    }
}
