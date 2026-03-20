<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Tax\WalletTransferService;
use Seat\Eveapi\Models\Wallet\CharacterWalletJournal;
use Carbon\Carbon;

class VerifyWalletPaymentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:verify-payments
                            {--days=7 : Number of days to check back}
                            {--character_id= : Verify payments for specific character}
                            {--auto-match : Automatically match payments to taxes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify wallet transfers against outstanding tax payments';

    /**
     * Wallet transfer service
     *
     * @var WalletTransferService
     */
    protected $walletService;

    /**
     * Create a new command instance.
     *
     * @param WalletTransferService $walletService
     */
    public function __construct(WalletTransferService $walletService)
    {
        parent::__construct();
        $this->walletService = $walletService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check feature flag
        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $features = $settingsService->getFeatureFlags();
        if (!($features['verify_wallet_transactions'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $this->info('Starting payment verification...');

        $days = $this->option('days');
        $characterId = $this->option('character_id');
        $autoMatch = $this->option('auto-match');
        $cutoffDate = Carbon::now()->subDays($days);

        $this->info("Checking transactions from the last {$days} days");

        // Get wallet transactions that might be tax payments
        $query = CharacterWalletJournal::where('date', '>=', $cutoffDate)
            ->whereIn('ref_type', ['player_donation', 'corporation_account_withdrawal']);

        if ($characterId) {
            $query->where('first_party_id', $characterId);
            $this->info("Verifying payments for character ID: {$characterId}");
        }

        $transactions = $query->get();

        if ($transactions->isEmpty()) {
            $this->warn('No relevant transactions found');
            return Command::SUCCESS;
        }

        $this->info("Found {$transactions->count()} transactions to check");

        $matched = 0;
        $unmatched = 0;
        $errors = 0;

        foreach ($transactions as $transaction) {
            try {
                // Check if transaction has a tax code in description
                $taxCode = $this->extractTaxCode($transaction->description);

                if (!$taxCode) {
                    $unmatched++;
                    continue;
                }

                // Find tax code record
                $taxCodeRecord = TaxCode::where('code', $taxCode)
                    ->where('character_id', $transaction->first_party_id)
                    ->first();

                if (!$taxCodeRecord) {
                    $this->warn("Tax code '{$taxCode}' not found for character {$transaction->first_party_id}");
                    $unmatched++;
                    continue;
                }

                // Find associated tax record
                $tax = MiningTax::find($taxCodeRecord->mining_tax_id);

                if (!$tax) {
                    $this->warn("Tax record not found for code '{$taxCode}'");
                    $unmatched++;
                    continue;
                }

                // Verify amount matches
                $amount = abs($transaction->amount);
                $tolerance = 0.01; // 1 cent tolerance for rounding

                if (abs($amount - $tax->amount_owed) > $tolerance) {
                    $this->warn("Amount mismatch for tax code '{$taxCode}': " .
                               "Expected {$tax->amount_owed}, Got {$amount}");
                }

                if ($autoMatch) {
                    // Update tax record
                    $tax->update([
                        'amount_paid' => $amount,
                        'paid_at' => $transaction->date,
                        'status' => 'paid',
                        'transaction_id' => $transaction->id,
                    ]);

                    // Mark tax code as used
                    $taxCodeRecord->update([
                        'used_at' => Carbon::now(),
                        'transaction_id' => $transaction->id,
                    ]);

                    $this->line("Matched payment for character {$transaction->first_party_id}: " .
                               number_format($amount, 2) . " ISK");
                    $matched++;
                } else {
                    $this->line("Found potential match for character {$transaction->first_party_id}: " .
                               number_format($amount, 2) . " ISK (code: {$taxCode})");
                    $matched++;
                }

            } catch (\Exception $e) {
                $this->error("Error processing transaction {$transaction->id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("\nVerification complete!");
        $this->info("Matched: {$matched} payments");
        if ($unmatched > 0) {
            $this->info("Unmatched: {$unmatched} transactions");
        }
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        if (!$autoMatch && $matched > 0) {
            $this->info("\nRun with --auto-match to automatically apply these payments");
        }

        return Command::SUCCESS;
    }

    /**
     * Extract tax code from transaction description
     *
     * @param string $description
     * @return string|null
     */
    private function extractTaxCode(?string $description): ?string
    {
        if (!$description) {
            return null;
        }

        // Use configured prefix and length from settings
        $prefix = preg_quote(\MiningManager\Models\TaxCode::getPrefix(), '/');
        $length = \MiningManager\Models\TaxCode::getCodeLength();

        if (preg_match('/' . $prefix . '([A-Z0-9]{' . $length . '})/', $description, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
