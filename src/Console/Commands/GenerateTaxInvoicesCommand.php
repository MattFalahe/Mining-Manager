<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxInvoice;
use MiningManager\Services\Tax\TaxCodeGeneratorService;
use MiningManager\Services\Notification\NotificationService;
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
    protected $description = 'Generate tax invoice records for unpaid mining taxes';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lock = Cache::lock('mining-manager:generate-invoices', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
            // Check feature flag
            $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
            $features = $settingsService->getFeatureFlags();
            if (!($features['auto_generate_invoices'] ?? true)) {
                $this->info('Feature disabled in settings. Skipping.');
                return Command::SUCCESS;
            }

            $this->info('Starting invoice generation...');

            $dryRun = $this->option('dry-run');
            if ($dryRun) {
                $this->warn('DRY RUN MODE - No invoices will be created');
            }

            // Build query for unpaid taxes with completed periods that need invoices
            $query = MiningTax::where('status', 'unpaid')
                ->where('amount_owed', '>', 0)
                ->whereDoesntHave('taxInvoices', function ($q) {
                    $q->whereIn('status', ['pending', 'sent']);
                });

            if ($month = $this->option('month')) {
                $monthDate = Carbon::parse($month . '-01');
                // Filter by calendar month (covers all period types within that month)
                $query->where('month', $monthDate->format('Y-m-01'));
                $this->info("Generating invoices for month: {$monthDate->format('Y-m')}");
            } else {
                // Default: only taxes whose period has ended (no invoices for in-progress periods)
                $query->where(function ($q) {
                    $q->where(function ($inner) {
                        $inner->whereNotNull('period_end')
                              ->where('period_end', '<', Carbon::now()->startOfDay());
                    })->orWhere(function ($inner) {
                        // Pre-migration records without period_end
                        $inner->whereNull('period_end')
                              ->where('month', '<', Carbon::now()->startOfMonth());
                    });
                });
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
            $errors = 0;

            foreach ($unpaidTaxes as $tax) {
                try {
                    if ($dryRun) {
                        $this->line("Would generate invoice for character {$tax->character_id}: " . number_format($tax->amount_owed, 2) . " ISK");
                        $generated++;
                        continue;
                    }

                    // Create invoice record. Mirror the mining_tax's due_date onto
                    // the invoice's expires_at so sendTaxInvoice() has a real
                    // deadline to display (the tax_invoices table has no due_date
                    // column — expires_at is the operational deadline).
                    $invoice = TaxInvoice::create([
                        'mining_tax_id' => $tax->id,
                        'character_id' => $tax->character_id,
                        'amount' => $tax->amount_owed,
                        'status' => 'pending',
                        'generated_at' => Carbon::now(),
                        'expires_at' => $tax->due_date,
                    ]);

                    // Auto-generate tax code if the tax record doesn't have an active one.
                    // Previously this required a separate manual `generate-tax-codes` command.
                    if (!$tax->taxCodes()->where('status', 'active')->exists()) {
                        try {
                            $codeService = app(TaxCodeGeneratorService::class);
                            $taxCode = $codeService->generateTaxCode($tax, $invoice);
                            $this->line("  Tax code generated: {$taxCode->code}");
                        } catch (\Exception $e) {
                            $this->warn("  Failed to generate tax code for character {$tax->character_id}: {$e->getMessage()}");
                            // Non-fatal — invoice was still created, code can be generated later
                        }
                    }

                    // Fire tax_invoice notification — one individual ping per invoice.
                    // Respects the full pipeline gating: master toggle, per-type
                    // toggle, per-channel toggle, per-webhook subscription.
                    // Wrapped in try/catch so a notification failure never blocks
                    // invoice creation — if the webhook's down, we still have the
                    // invoice row and the miner can see it in My Taxes.
                    try {
                        app(NotificationService::class)->sendTaxInvoice($invoice);
                    } catch (\Exception $e) {
                        $this->warn("  Failed to dispatch tax_invoice notification for character {$tax->character_id}: {$e->getMessage()}");
                        Log::warning("GenerateTaxInvoicesCommand: tax_invoice dispatch failed", [
                            'invoice_id' => $invoice->id,
                            'character_id' => $tax->character_id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $this->line("Generated invoice for character {$tax->character_id}: " . number_format($tax->amount_owed, 2) . " ISK");
                    $generated++;

                } catch (\Exception $e) {
                    $this->error("Error generating invoice for character {$tax->character_id}: {$e->getMessage()}");
                    $errors++;
                }
            }

            $this->info("Invoice generation complete!");
            $this->info("Generated: {$generated} invoices");
            if ($errors > 0) {
                $this->warn("Errors: {$errors}");
            }

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
