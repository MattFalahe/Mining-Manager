<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Tax\WalletTransferService;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                            {--auto-match : Automatically match payments to taxes}
                            {--reset-month= : Reset all payment data for a month (YYYY-MM) and re-match}';

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
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Create a new command instance.
     *
     * @param WalletTransferService $walletService
     * @param SettingsManagerService $settingsService
     */
    public function __construct(WalletTransferService $walletService, SettingsManagerService $settingsService)
    {
        parent::__construct();
        $this->walletService = $walletService;
        $this->settingsService = $settingsService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lock = Cache::lock('mining-manager:verify-payments', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return self::SUCCESS;
        }

        try {
        // Check feature flag
        $features = $this->settingsService->getFeatureFlags();
        if (!($features['verify_wallet_transactions'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        // Handle --reset-month: clear payment data for a month, then re-match
        if ($resetMonth = $this->option('reset-month')) {
            return $this->handleResetMonth($resetMonth);
        }

        $this->info('Starting payment verification...');

        $days = (int) $this->option('days');
        $characterId = $this->option('character_id');
        $autoMatch = $this->option('auto-match');
        $cutoffDate = Carbon::now()->subDays($days);

        // Get payment settings for division info
        $paymentSettings = $this->settingsService->getPaymentSettings();
        $division = $paymentSettings['wallet_division'] ?? 1;
        $divisionName = $this->settingsService->getWalletDivisionName();

        $this->info("Checking transactions from the last {$days} days");
        $this->info("Primary wallet division: {$divisionName} (division {$division})" .
            ($division !== 1 ? ' + Master Wallet (fallback)' : ''));

        // Get corporation ID for filtering
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

        // Build the divisions to check (primary + master wallet fallback)
        $divisions = [$division];
        if ($division !== 1) {
            $divisions[] = 1; // Always check master wallet as fallback
        }

        // Query corporation wallet journals with division filtering
        $query = DB::table('corporation_wallet_journals')
            ->where('date', '>=', $cutoffDate)
            ->where('ref_type', 'player_donation')
            ->whereIn('division', $divisions);

        if ($moonOwnerCorpId) {
            $query->where('corporation_id', $moonOwnerCorpId);
        }

        if ($characterId) {
            $query->where('first_party_id', $characterId);
            $this->info("Verifying payments for character ID: {$characterId}");
        }

        $transactions = $query->orderBy('date', 'desc')->get();

        if ($transactions->isEmpty()) {
            $this->warn('No relevant transactions found in configured wallet divisions');
            return Command::SUCCESS;
        }

        $this->info("Found {$transactions->count()} transactions to check");

        $matched = 0;
        $unmatched = 0;
        $errors = 0;
        $processedTransactionIds = [];

        foreach ($transactions as $transaction) {
            try {
                // Check if transaction has a tax code in reason or description
                $text = ($transaction->reason ?? '') . ' ' . ($transaction->description ?? '');
                $taxCode = $this->extractTaxCode($text);

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

                // Skip if tax is already fully paid
                if ($tax->status === 'paid') {
                    continue;
                }

                // Skip if this exact transaction was already applied
                // Check both the tax record and the processed set from this run
                if (in_array($transaction->id, $processedTransactionIds)) {
                    continue;
                }
                if ($tax->transaction_id == $transaction->id) {
                    continue;
                }
                // Check if any other tax record already has this transaction
                if (MiningTax::where('transaction_id', $transaction->id)->exists()) {
                    continue;
                }

                // Verify amount matches
                $amount = abs($transaction->amount);
                $tolerance = $paymentSettings['match_tolerance'] ?? 100;

                if (abs($amount - $tax->amount_owed) > $tolerance) {
                    $this->warn("Amount mismatch for tax code '{$taxCode}': " .
                               "Expected " . number_format($tax->amount_owed, 2) . ", Got " . number_format($amount, 2));
                }

                $divLabel = $transaction->division == 1 ? 'Master Wallet' : "Division {$transaction->division}";

                if ($autoMatch) {
                    DB::transaction(function () use ($tax, $taxCodeRecord, $transaction, $amount, &$processedTransactionIds) {
                        $tax = MiningTax::where('id', $tax->id)->lockForUpdate()->first();

                        // Always add to existing amount_paid (supports partial payments)
                        $newPaid = ($tax->amount_paid ?? 0) + $amount;

                        $tax->update([
                            'amount_paid' => $newPaid,
                            'paid_at' => $transaction->date,
                            'status' => ($tax->amount_owed - $newPaid) < 1 ? 'paid' : 'partial',
                            'transaction_id' => $transaction->id,
                        ]);

                        // Only mark tax code as used once fully paid
                        if (($tax->amount_owed - $newPaid) < 1) {
                            $taxCodeRecord->update([
                                'used_at' => Carbon::now(),
                                'transaction_id' => $transaction->id,
                                'status' => 'used',
                            ]);
                        }

                        $processedTransactionIds[] = $transaction->id;
                    });

                    $this->line("Matched payment for character {$transaction->first_party_id}: " .
                               number_format($amount, 2) . " ISK [{$divLabel}]");
                    $matched++;
                } else {
                    $this->line("Found potential match for character {$transaction->first_party_id}: " .
                               number_format($amount, 2) . " ISK (code: {$taxCode}) [{$divLabel}]");
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

        // Cleanup dismissed transactions older than 30 days
        $cleanupCutoff = Carbon::now()->subDays(30);
        $cleaned = DB::table('mining_manager_dismissed_transactions')
            ->where('dismissed_at', '<', $cleanupCutoff)
            ->delete();

        if ($cleaned > 0) {
            $this->info("Cleaned up {$cleaned} dismissed transaction(s) older than 30 days");
        }

        return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * Extract tax code from transaction text (reason + description).
     * Delegates to TaxCode::extractCodeFromText() which handles mixed code lengths.
     *
     * @param string|null $text
     * @return string|null
     */
    private function extractTaxCode(?string $text): ?string
    {
        return TaxCode::extractCodeFromText($text);
    }

    /**
     * Reset payment data for a specific month and re-match from wallet.
     */
    protected function handleResetMonth(string $monthStr): int
    {
        try {
            $month = Carbon::parse($monthStr . '-01');
        } catch (\Exception $e) {
            $this->error("Invalid month format. Use YYYY-MM (e.g., 2026-03)");
            return Command::FAILURE;
        }

        $monthLabel = $month->format('F Y');
        $monthKey = $month->format('Y-m');

        // Find all taxes for this month
        $taxes = MiningTax::where('month', $month->format('Y-m-01'))
            ->orWhere(function ($q) use ($month) {
                $q->where('period_start', '>=', $month->startOfMonth()->format('Y-m-d'))
                  ->where('period_start', '<=', $month->endOfMonth()->format('Y-m-d'));
            })
            ->get();

        if ($taxes->isEmpty()) {
            $this->warn("No tax records found for {$monthLabel}");
            return Command::SUCCESS;
        }

        $this->info("Found {$taxes->count()} tax records for {$monthLabel}");
        $this->info("Resetting payment data...");

        $resetCount = 0;
        DB::transaction(function () use ($taxes, &$resetCount) {
            foreach ($taxes as $tax) {
                $wasChanged = $tax->amount_paid > 0 || $tax->status === 'paid' || $tax->status === 'partial';

                $tax->update([
                    'amount_paid' => 0,
                    'paid_at' => null,
                    'status' => 'unpaid',
                    'transaction_id' => null,
                ]);

                // Reset associated tax codes back to active
                TaxCode::where('mining_tax_id', $tax->id)
                    ->where('status', 'used')
                    ->update([
                        'status' => 'active',
                        'used_at' => null,
                        'transaction_id' => null,
                    ]);

                if ($wasChanged) {
                    $resetCount++;
                }
            }
        });

        $this->info("Reset {$resetCount} paid/partial records back to unpaid");
        $this->info("Re-matching payments from wallet...");

        // Now run auto-match with enough days to cover the month
        $daysSinceMonthStart = Carbon::now()->diffInDays($month->startOfMonth()) + 5;
        $this->call('mining-manager:verify-payments', [
            '--days' => $daysSinceMonthStart,
            '--auto-match' => true,
        ]);

        return Command::SUCCESS;
    }
}
