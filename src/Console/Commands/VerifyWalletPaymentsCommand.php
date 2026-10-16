<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MiningTax;
use MiningManager\Models\PaymentAllocation;
use MiningManager\Models\PaymentCredit;
use MiningManager\Models\ProcessedTransaction;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Tax\PaymentAllocationService;
use MiningManager\Services\Tax\WalletTransferService;
use MiningManager\Services\Tax\RefundService;
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
                            {--reset-month= : Undo the automatic payment matches of a month (YYYY-MM) and match them again}
                            {--dry-run : With --reset-month, report what would be undone and change nothing}
                            {--force : With --reset-month, skip the confirmation prompt}';

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
    protected function runVerification(?int $days = null, ?bool $autoMatch = null, ?bool $ignoreCutover = null): int
    {
        $this->info('Starting payment verification...');

        $days = $days ?? (int) $this->option('days');
        $characterId = $this->option('character_id');

        $paymentSettings = $this->settingsService->getPaymentSettings();

        // The schedule always passes --auto-match; the setting is what lets an
        // operator hold every match for review without editing the schedule.
        $autoMatch = $autoMatch ?? (bool) $this->option('auto-match');
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

        $ignoreCutover = $ignoreCutover ?? (bool) $this->option('ignore-cutover');
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
            // Still worth looking: refunds confirm against money going out, and
            // nothing coming in has no bearing on whether it went.
            $this->confirmRefunds($corporationId ? (int) $corporationId : null);

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
        $this->confirmRefunds($corporationId ? (int) $corporationId : null);

        return Command::SUCCESS;
    }

    /**
     * Mark refunds confirmed once the ISK is seen leaving the wallet.
     *
     * Rides along with payment verification rather than taking a schedule slot
     * of its own: this already reads the corporation wallet on a timer, and a
     * refund confirming a few hours after the transfer went out is soon enough
     * for something a person does by hand.
     */
    protected function confirmRefunds(?int $corporationId): void
    {
        try {
            $result = app(RefundService::class)->reconcile($corporationId);

            if ($result['confirmed'] > 0) {
                $this->info("Confirmed {$result['confirmed']} refund(s) against outgoing transfers");
            }

            if ($result['ambiguous'] > 0) {
                $this->warn("{$result['ambiguous']} refund(s) could not be matched to one transfer for certain and were left pending.");
                $this->line('Look at them on the Balances tab: guessing here would mark money returned that might not have been.');
            }
        } catch (\Exception $e) {
            // A refund that fails to confirm stays pending, which is the safe
            // resting state. Never let it take down payment verification.
            $this->warn("Refund reconciliation failed: {$e->getMessage()}");
            Log::warning('Mining Manager: refund reconciliation failed', ['error' => $e->getMessage()]);
        }
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
     * Undo one month's automatic payment matches and run the matcher again.
     *
     * Only what the matcher did can be undone and redone: a wallet payment
     * whose every slice was matched automatically onto this month's invoices.
     * Anything else stays as it is, because matching again could not put it
     * back the way it was:
     *
     *  - payments assigned by hand, and bills marked paid by hand
     *  - balance drawn down from earlier credit
     *  - a payment that also paid an invoice outside this month; undoing half
     *    of it would leave its claim in place and lose the other half
     *  - a payment whose surplus was banked and has since been spent or
     *    refunded
     *
     * An unspent surplus goes with its payment: matching it again banks it
     * again, and keeping both would hold the same ISK twice.
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
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // Matching again is the point of a reset. With automatic matching
        // switched off nothing would put the payments back, so stop before
        // anything is undone.
        $paymentSettings = $this->settingsService->getPaymentSettings();
        if (!$dryRun && !($paymentSettings['auto_match_payments'] ?? true)) {
            $this->error('Automatic matching is switched off in settings, so nothing would match these payments again after the reset. Switch it on first, or look with --dry-run.');

            return Command::FAILURE;
        }

        $taxIds = $this->resetScope($month);

        if (empty($taxIds)) {
            $this->warn("No tax records found for {$monthLabel}");

            return Command::SUCCESS;
        }

        // Work out exactly what is at stake before touching any of it.
        $plan = $this->planReset($taxIds);

        $this->line('');
        $this->warn("This undoes the automatic payment matches for {$monthLabel} and matches them again.");
        $this->table(['What', 'Count'], [
            ['Tax records in scope', count($taxIds)],
            ['Wallet payments undone and matched again', count($plan['transactions'])],
            ['ISK taken off invoices until they match again', number_format($plan['isk'], 2)],
            ['Invoices that change', count($plan['undo'])],
            ['Unspent held balances removed with their payment', count($plan['credits'])],
            ['Payments left as they are', count($plan['kept'])],
        ]);

        if (!empty($plan['kept'])) {
            $this->line('');
            $this->line('Left as they are:');
            $this->table(['Transaction', 'Why'], collect($plan['kept'])
                ->map(fn ($why, $transactionId) => [$transactionId, $why])
                ->take(15)
                ->values()
                ->all());

            if (count($plan['kept']) > 15) {
                $this->line('  ... and ' . (count($plan['kept']) - 15) . ' more');
            }
        }

        if ($dryRun) {
            $this->line('');
            $this->comment('Dry run. Nothing was changed and no re-match was run.');

            return Command::SUCCESS;
        }

        if (empty($plan['transactions'])) {
            $this->info("Nothing in {$monthLabel} was matched automatically, so there is nothing to undo.");

            return Command::SUCCESS;
        }

        // confirm() answers with its default when there is nobody to ask, so a
        // non-interactive run cancels here rather than quietly resetting a month.
        if (! $force && ! $this->confirm("Reset payment data for {$monthLabel}?", false)) {
            $this->info('Cancelled. Nothing was changed.');
            $this->line('Running this non-interactively? Pass --force, ideally after --dry-run.');

            return Command::SUCCESS;
        }

        $this->info('Resetting payment data...');

        $applied = DB::transaction(function () use ($taxIds) {
            // Planned again under lock: the preview can be minutes old by the
            // time somebody answers the prompt.
            MiningTax::whereIn('id', $taxIds)->lockForUpdate()->get();

            $plan = $this->planReset($taxIds);
            $this->applyReset($plan);

            return $plan;
        });

        $this->info('Undid ' . count($applied['transactions']) . ' payment(s) on ' . count($applied['undo']) . ' invoice(s)');
        $this->info('Re-matching payments from wallet...');

        $daysSinceMonthStart = (int) Carbon::now()->diffInDays($month->copy()->startOfMonth()) + 5;

        // In this run, under this run's lock. Calling the command again from
        // here found its own lock held and skipped, which left every invoice
        // reset and nothing matched.
        return $this->runVerification($daysSinceMonthStart, true, true);
    }

    /**
     * The month's invoices a reset may touch. Bills a director settled by
     * decision rather than by payment are left out.
     *
     * @return array<int>
     */
    protected function resetScope(Carbon $month): array
    {
        return MiningTax::where(function ($q) use ($month) {
                $q->where('month', $month->format('Y-m-01'))
                    ->orWhere(function ($q) use ($month) {
                        $q->where('period_start', '>=', $month->copy()->startOfMonth()->format('Y-m-d'))
                            ->where('period_start', '<=', $month->copy()->endOfMonth()->format('Y-m-d'));
                    });
            })
            ->whereNotIn('status', ['waived', 'exempted'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * What a reset of these invoices would undo, and what it would leave.
     *
     * @param  array<int>  $taxIds
     * @return array{transactions: array<int>, slices: array<int>, credits: array<int>, undo: array<int, float>, legacy: array<int, int>, isk: float, kept: array<int, string>}
     */
    protected function planReset(array $taxIds): array
    {
        $plan = ['transactions' => [], 'slices' => [], 'credits' => [], 'undo' => [], 'legacy' => [], 'isk' => 0.0, 'kept' => []];

        $candidates = PaymentAllocation::whereIn('mining_tax_id', $taxIds)
            ->whereNotNull('transaction_id')
            ->whereNull('credit_id')
            ->pluck('transaction_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($candidates as $transactionId) {
            // Every slice of the payment, here and anywhere else, including
            // draw-downs of a surplus it banked, which carry its id too.
            $slices = PaymentAllocation::where('transaction_id', $transactionId)->get();
            $own = $slices->filter(fn ($slice) => $slice->credit_id === null);
            $credits = PaymentCredit::where('transaction_id', $transactionId)->get();

            $why = null;

            if ($own->contains(fn ($slice) => $slice->source === PaymentAllocation::SOURCE_MANUAL)) {
                $why = 'assigned by hand';
            } elseif ($own->contains(fn ($slice) => !in_array((int) $slice->mining_tax_id, $taxIds, true))) {
                $why = 'also paid an invoice outside this month';
            } elseif ($slices->contains(fn ($slice) => $slice->credit_id !== null)
                || $credits->contains(fn ($credit) => round((float) $credit->remaining, 2) < round((float) $credit->amount, 2))) {
                $why = 'its held balance has been spent or refunded';
            }

            if ($why !== null) {
                $plan['kept'][$transactionId] = $why;

                continue;
            }

            $plan['transactions'][] = $transactionId;

            foreach ($own as $slice) {
                $taxId = (int) $slice->mining_tax_id;
                $plan['slices'][] = (int) $slice->id;
                $plan['undo'][$taxId] = round(($plan['undo'][$taxId] ?? 0) + (float) $slice->amount, 2);
                $plan['isk'] += (float) $slice->amount;
            }

            foreach ($credits as $credit) {
                $plan['credits'][] = (int) $credit->id;
            }
        }

        // Invoices credited before the allocation ledger existed have no
        // slices, only the id of the last payment on them. The wallet matched
        // those, so they reset the way they always did, unless that payment
        // has been recorded against anything in the ledger since.
        foreach (MiningTax::whereIn('id', $taxIds)->whereNotNull('transaction_id')->get() as $tax) {
            if ((float) $tax->amount_paid <= 0 || !ctype_digit((string) $tax->transaction_id)) {
                continue;
            }

            $transactionId = (int) $tax->transaction_id;

            if (PaymentAllocation::where('mining_tax_id', $tax->id)->exists()
                || PaymentAllocation::where('transaction_id', $transactionId)->exists()) {
                continue;
            }

            $plan['legacy'][(int) $tax->id] = $transactionId;
            $plan['undo'][(int) $tax->id] = round((float) $tax->amount_paid, 2);
            $plan['isk'] += (float) $tax->amount_paid;
            $plan['transactions'][] = $transactionId;
        }

        $plan['transactions'] = array_values(array_unique($plan['transactions']));
        $plan['isk'] = round($plan['isk'], 2);

        return $plan;
    }

    /**
     * Take the planned payments off their invoices and release them for the
     * matcher. Each invoice loses exactly what is undone, so a part paid by
     * hand, from credit or before the ledger existed stays on it.
     */
    protected function applyReset(array $plan): void
    {
        if (!empty($plan['slices'])) {
            PaymentAllocation::whereIn('id', $plan['slices'])->delete();
        }

        if (!empty($plan['credits'])) {
            PaymentCredit::whereIn('id', $plan['credits'])->delete();
        }

        if (!empty($plan['transactions'])) {
            ProcessedTransaction::whereIn('transaction_id', $plan['transactions'])->delete();
        }

        foreach ($plan['undo'] as $taxId => $undone) {
            $tax = MiningTax::where('id', $taxId)->first();

            if (!$tax) {
                continue;
            }

            $paid = isset($plan['legacy'][$taxId])
                ? 0.0
                : max(0.0, round((float) $tax->amount_paid - $undone, 2));

            $settled = $paid > 0
                && ((float) $tax->amount_owed - $paid) < PaymentAllocationService::SETTLED_TOLERANCE;

            $latest = PaymentAllocation::where('mining_tax_id', $taxId)
                ->orderByDesc('allocated_at')
                ->get();

            $tax->update([
                'amount_paid' => $paid,
                'status' => $settled ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'paid_at' => $paid > 0 ? ($latest->first()->allocated_at ?? $tax->paid_at) : null,
                'transaction_id' => $latest->first(fn ($slice) => $slice->transaction_id !== null)->transaction_id ?? null,
            ]);

            // A bill that is open again takes payments on its code again.
            if (!$settled) {
                TaxCode::where('mining_tax_id', $taxId)
                    ->where('status', 'used')
                    ->update([
                        'status' => 'active',
                        'used_at' => null,
                        'transaction_id' => null,
                    ]);
            }
        }
    }
}
