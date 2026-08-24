<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MiningTax;
use MiningManager\Models\PaymentAllocation;
use MiningManager\Models\ProcessedTransaction;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Tax\PaymentAllocationService;
use MiningManager\Services\Tax\WalletTransferService;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Scheduled matching of corporation wallet donations against tax invoices.
 *
 * This used to carry its own copy of the matching logic, including its own
 * idea of what counted as already-credited. It guarded on
 * mining_taxes.transaction_id, a column overwritten on every payment, so an
 * invoice settled in two instalments could have the earlier instalment
 * credited again on a later run and quietly reach "paid" while still short.
 *
 * The matching and crediting now belong to WalletTransferService and
 * PaymentAllocationService, which claim each transaction in
 * mining_manager_processed_transactions before touching an invoice. What is
 * left here is scheduling, reporting and the reset tooling.
 */
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
                            {--ignore-cutover : Include payments dated before the verification cutover}
                            {--reset-month= : Reset all payment data for a month (YYYY-MM) and re-match}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify wallet transfers against outstanding tax payments';

    protected WalletTransferService $walletService;

    protected PaymentAllocationService $allocator;

    protected SettingsManagerService $settingsService;

    public function __construct(
        WalletTransferService $walletService,
        PaymentAllocationService $allocator,
        SettingsManagerService $settingsService
    ) {
        parent::__construct();

        $this->walletService = $walletService;
        $this->allocator = $allocator;
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
            $features = $this->settingsService->getFeatureFlags();
            if (!($features['verify_wallet_transactions'] ?? true)) {
                $this->info('Feature disabled in settings. Skipping.');

                return Command::SUCCESS;
            }

            if ($resetMonth = $this->option('reset-month')) {
                return $this->handleResetMonth($resetMonth);
            }

            return $this->runVerification();
        } finally {
            $lock->release();
        }
    }

    /**
     * Scan the configured wallet divisions and credit what can be credited.
     */
    protected function runVerification(): int
    {
        $this->info('Starting payment verification...');

        $days = (int) $this->option('days');
        $characterId = $this->option('character_id');

        $paymentSettings = $this->settingsService->getPaymentSettings();

        // The schedule always passes --auto-match; the setting is what lets an
        // operator hold every match for review without editing the schedule.
        $autoMatch = (bool) $this->option('auto-match');
        if ($autoMatch && !($paymentSettings['auto_match_payments'] ?? true)) {
            $autoMatch = false;
            $this->warn('Auto-match is turned off in settings, reporting matches without applying them');
        }

        $division = $paymentSettings['wallet_division'] ?? 1;
        $divisionName = $this->settingsService->getWalletDivisionName();

        $corporationId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->walletService->setCorporationContext($corporationId ? (int) $corporationId : null);

        $this->info("Checking transactions from the last {$days} days");
        $this->info("Primary wallet division: {$divisionName} (division {$division})" .
            ($division !== 1 ? ' + Master Wallet (fallback)' : ''));

        $ignoreCutover = (bool) $this->option('ignore-cutover');
        $epoch = $this->allocator->getDedupEpoch();

        if ($epoch && !$ignoreCutover) {
            $this->info('Verification cutover: ' . $epoch->toDateTimeString() . ' (anything earlier is left untouched)');
        } elseif ($epoch) {
            $this->warn('Cutover ignored, payments from before ' . $epoch->toDateTimeString() . ' are in scope');
        }

        $transactions = $this->pendingTransactions($corporationId, $days, $characterId);

        if ($transactions->isEmpty()) {
            $this->warn('No unclaimed transactions found in configured wallet divisions');

            $this->cleanupDismissed();

            return Command::SUCCESS;
        }

        $this->info("Found {$transactions->count()} transactions to check");

        $matched = 0;
        $unmatched = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($transactions as $transaction) {
            try {
                $result = $this->walletService->matchTransaction((int) $transaction->id, [
                    'apply' => $autoMatch,
                    'transaction' => $transaction,
                    'ignore_cutover' => $ignoreCutover,
                ]);

                if ($result['reason'] === 'before_cutover') {
                    $skipped++;
                    continue;
                }

                if (!$result['matched']) {
                    $unmatched++;
                    $this->reportUnmatched($transaction, $result);
                    continue;
                }

                $matched++;
                $this->reportMatched($transaction, $result, $autoMatch);
            } catch (\Exception $e) {
                $this->error("Error processing transaction {$transaction->id}: {$e->getMessage()}");
                Log::error('Mining Manager: verify-payments failed on a transaction', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        $this->info("\nVerification complete!");
        $this->info("Matched: {$matched} payments");
        if ($unmatched > 0) {
            $this->info("Unmatched: {$unmatched} transactions");
        }
        if ($skipped > 0) {
            $this->info("Before cutover, left alone: {$skipped} transactions");
        }
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        if (!$autoMatch && $matched > 0) {
            $this->info("\nRun with --auto-match to automatically apply these payments");
        }

        $this->cleanupDismissed();

        return Command::SUCCESS;
    }

    /**
     * Donations inside the window that nothing has claimed yet.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function pendingTransactions($corporationId, int $days, $characterId)
    {
        $paymentSettings = $this->settingsService->getPaymentSettings();
        $division = (int) ($paymentSettings['wallet_division'] ?? 1);

        $divisions = [1];
        if ($division !== 1) {
            array_unshift($divisions, $division);
        }

        $query = DB::table('corporation_wallet_journals')
            ->where('date', '>=', Carbon::now()->subDays($days))
            ->where('ref_type', 'player_donation')
            ->whereIn('division', array_unique($divisions))
            ->whereNotIn('id', function ($sub) {
                $sub->select('transaction_id')->from('mining_manager_processed_transactions');
            });

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        if ($characterId) {
            $query->where('first_party_id', $characterId);
            $this->info("Verifying payments for character ID: {$characterId}");
        }

        return $query->orderBy('date', 'desc')->get();
    }

    /**
     * Console line for a payment that was credited, including any cascade.
     */
    protected function reportMatched(object $transaction, array $result, bool $autoMatch): void
    {
        $divLabel = $transaction->division == 1 ? 'Master Wallet' : "Division {$transaction->division}";
        $amount = number_format($result['amount'], 2);

        if (!$autoMatch) {
            $this->line("Found potential match for character {$transaction->first_party_id}: " .
                "{$amount} ISK (code: {$result['tax_code']}) [{$divLabel}]");

            return;
        }

        if (!$result['applied']) {
            $this->warn("Matched but not applied for character {$transaction->first_party_id}: " .
                "{$amount} ISK ({$result['reason']})");

            return;
        }

        $invoiceCount = count($result['allocations']);
        $spread = $invoiceCount > 1 ? " across {$invoiceCount} invoices" : '';
        $surplus = $result['credited'] > 0
            ? ' (' . number_format($result['credited'], 2) . ' ISK held as credit)'
            : '';

        $this->line("Matched payment for character {$transaction->first_party_id}: " .
            "{$amount} ISK{$spread} [{$divLabel}]{$surplus}");
    }

    /**
     * Console line explaining why a payment is still sitting there.
     */
    protected function reportUnmatched(object $transaction, array $result): void
    {
        $amount = number_format($result['amount'], 2);

        switch ($result['reason']) {
            case 'no_tax_code':
                $this->line("No tax code on {$amount} ISK from character {$transaction->first_party_id}, needs assigning by hand");
                break;

            case 'tax_code_not_recognised':
                $this->warn("Tax code '{$result['tax_code']}' from character {$transaction->first_party_id} matches no invoice");
                break;

            case 'invoice_missing':
                $this->warn("Tax code '{$result['tax_code']}' points at an invoice that no longer exists");
                break;

            default:
                $this->line("Unmatched {$amount} ISK from character {$transaction->first_party_id} ({$result['reason']})");
        }
    }

    /**
     * Dismissed rows are a UI convenience, not a permanent record, so they age
     * out once the transaction has fallen off the verification window anyway.
     */
    protected function cleanupDismissed(): void
    {
        $cleaned = DB::table('mining_manager_dismissed_transactions')
            ->where('dismissed_at', '<', Carbon::now()->subDays(30))
            ->delete();

        if ($cleaned > 0) {
            $this->info("Cleaned up {$cleaned} dismissed transaction(s) older than 30 days");
        }
    }

    /**
     * Reset payment data for a specific month and re-match from wallet.
     *
     * Releases the transaction claims as well as the invoice state. Without
     * that the re-match would find every payment already consumed and quietly
     * leave every invoice unpaid.
     */
    protected function handleResetMonth(string $monthStr): int
    {
        try {
            $month = Carbon::parse($monthStr . '-01');
        } catch (\Exception $e) {
            $this->error('Invalid month format. Use YYYY-MM (e.g. 2026-03)');

            return Command::FAILURE;
        }

        $monthLabel = $month->format('F Y');

        $taxes = MiningTax::where('month', $month->format('Y-m-01'))
            ->orWhere(function ($q) use ($month) {
                $q->where('period_start', '>=', $month->copy()->startOfMonth()->format('Y-m-d'))
                    ->where('period_start', '<=', $month->copy()->endOfMonth()->format('Y-m-d'));
            })
            ->get();

        if ($taxes->isEmpty()) {
            $this->warn("No tax records found for {$monthLabel}");

            return Command::SUCCESS;
        }

        $this->info("Found {$taxes->count()} tax records for {$monthLabel}");
        $this->info('Resetting payment data...');

        $resetCount = 0;
        $releasedCount = 0;

        DB::transaction(function () use ($taxes, &$resetCount, &$releasedCount) {
            $taxIds = $taxes->pluck('id')->all();

            $transactionIds = PaymentAllocation::whereIn('mining_tax_id', $taxIds)
                ->whereNotNull('transaction_id')
                ->pluck('transaction_id')
                ->unique()
                ->all();

            PaymentAllocation::whereIn('mining_tax_id', $taxIds)->delete();

            // Only release a payment that no surviving invoice still relies on.
            if (!empty($transactionIds)) {
                $stillAllocated = PaymentAllocation::whereIn('transaction_id', $transactionIds)
                    ->pluck('transaction_id')
                    ->unique()
                    ->all();

                $releasable = array_values(array_diff($transactionIds, $stillAllocated));

                if (!empty($releasable)) {
                    $releasedCount = ProcessedTransaction::whereIn('transaction_id', $releasable)->delete();
                }
            }

            // Invoices predating the allocation ledger have no allocation rows,
            // so fall back to the transaction id still recorded on the invoice.
            $legacyIds = $taxes->pluck('transaction_id')
                ->filter(fn ($id) => $id !== null && ctype_digit((string) $id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();

            if (!empty($legacyIds)) {
                $releasedCount += ProcessedTransaction::whereIn('transaction_id', $legacyIds)->delete();
            }

            foreach ($taxes as $tax) {
                $wasChanged = $tax->amount_paid > 0 || $tax->status === 'paid' || $tax->status === 'partial';

                $tax->update([
                    'amount_paid' => 0,
                    'paid_at' => null,
                    'status' => 'unpaid',
                    'transaction_id' => null,
                ]);

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
        $this->info("Released {$releasedCount} transaction claim(s) for re-matching");
        $this->info('Re-matching payments from wallet...');

        $daysSinceMonthStart = Carbon::now()->diffInDays($month->copy()->startOfMonth()) + 5;

        // A month reset is a deliberate re-run over historical data, so the
        // cutover guard would defeat the whole point of it.
        $this->call('mining-manager:verify-payments', [
            '--days' => $daysSinceMonthStart,
            '--auto-match' => true,
            '--ignore-cutover' => true,
        ]);

        return Command::SUCCESS;
    }
}
