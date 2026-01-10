<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxInvoice;
use MiningManager\Services\Tax\ContractManagementService;
use Carbon\Carbon;

class GenerateTaxInvoicesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:generate-invoices
                            {--month= : Month to generate invoices for (YYYY-MM)}
                            {--character_id= : Generate for specific character}
                            {--dry-run : Show what would be generated without creating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate tax invoices/contracts for unpaid mining taxes';

    /**
     * Contract management service
     *
     * @var ContractManagementService
     */
    protected $contractService;

    /**
     * Create a new command instance.
     *
     * @param ContractManagementService $contractService
     */
    public function __construct(ContractManagementService $contractService)
    {
        parent::__construct();
        $this->contractService = $contractService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting invoice generation...');

        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No invoices will be created');
        }

        // Build query for unpaid taxes
        $query = MiningTax::where('status', 'unpaid')
            ->where('amount_owed', '>', 0);

        if ($month = $this->option('month')) {
            $monthDate = Carbon::parse($month . '-01');
            $query->where('month', $monthDate->format('Y-m-01'));
            $this->info("Generating invoices for month: {$monthDate->format('Y-m')}");
        }

        if ($characterId = $this->option('character_id')) {
            $query->where('character_id', $characterId);
            $this->info("Generating for character ID: {$characterId}");
        }

        $unpaidTaxes = $query->get();

        if ($unpaidTaxes->isEmpty()) {
            $this->warn('No unpaid taxes found to generate invoices for');
            return Command::SUCCESS;
        }

        $this->info("Found {$unpaidTaxes->count()} unpaid tax records");

        $generated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($unpaidTaxes as $tax) {
            try {
                // Check if invoice already exists
                $existingInvoice = TaxInvoice::where('mining_tax_id', $tax->id)
                    ->whereIn('status', ['pending', 'sent'])
                    ->first();

                if ($existingInvoice) {
                    $this->line("Skipping character {$tax->character_id} - invoice already exists");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("Would generate invoice for character {$tax->character_id}: " . number_format($tax->amount_owed, 2) . " ISK");
                    $generated++;
                    continue;
                }

                // Create invoice record
                $invoice = TaxInvoice::create([
                    'mining_tax_id' => $tax->id,
                    'character_id' => $tax->character_id,
                    'amount' => $tax->amount_owed,
                    'status' => 'pending',
                    'generated_at' => Carbon::now(),
                ]);

                // Note: Actual contract creation via ESI would happen here
                // For now, we just create the invoice record
                // $this->contractService->createIngameContract($invoice);

                $this->line("Generated invoice for character {$tax->character_id}: " . number_format($tax->amount_owed, 2) . " ISK");
                $generated++;

            } catch (\Exception $e) {
                $this->error("Error generating invoice for character {$tax->character_id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("Invoice generation complete!");
        $this->info("Generated: {$generated} invoices");
        if ($skipped > 0) {
            $this->info("Skipped: {$skipped} (already have invoices)");
        }
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        return Command::SUCCESS;
    }
}
