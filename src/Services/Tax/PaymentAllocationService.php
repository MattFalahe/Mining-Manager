<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningTax;
use MiningManager\Models\PaymentAllocation;
use MiningManager\Models\PaymentCredit;
use MiningManager\Models\ProcessedTransaction;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Tax\Concerns\ResolvesCharacterOwnership;

/**
 * The single place a wallet payment turns into credited invoices.
 *
 * Everything that used to apply a payment did it with its own copy of the
 * logic: the artisan command, the wallet journal listener, and two methods on
 * WalletTransferService. They disagreed about which journal table to read,
 * whether to look at the reason field, and how to guard against crediting the
 * same transfer twice. All of them now route through allocate().
 *
 * What one call does, atomically:
 *
 *   1. Refuses anything dated before the verification cutover, so historical
 *      records are never re-examined.
 *   2. Claims the transaction in mining_manager_processed_transactions. The
 *      unique index on transaction_id makes this a compare-and-swap: if the
 *      insert fails, another worker already has it and we stop before
 *      touching a single invoice.
 *   3. Applies as much as the target invoice still owes.
 *   4. Rolls any remainder onto that player's next-oldest unpaid invoices.
 *   5. Parks whatever is still left as credit against the paying character.
 *
 * Every slice is written to mining_manager_payment_allocations, so an invoice's
 * amount_paid can always be reconciled against the payments behind it.
 */
class PaymentAllocationService
{
    use ResolvesCharacterOwnership;

    /**
     * ISK below which an invoice counts as settled. Guards against decimal
     * dust leaving an invoice a fraction short of paid forever.
     */
    public const SETTLED_TOLERANCE = 1.0;

    protected SettingsManagerService $settings;

    public function __construct(SettingsManagerService $settings)
    {
        $this->settings = $settings;
    }

    public function setCorporationContext(?int $corporationId): self
    {
        $this->settings->setActiveCorporation($corporationId);

        return $this;
    }

    /**
     * The verification cutover, stamped when the allocation ledger shipped.
     *
     * Payments dated before it are left exactly as they were credited by the
     * old pipeline. They are never re-matched, re-credited or corrected.
     * Returns null on installs where the stamp is missing, which disables the
     * guard rather than silently rejecting everything.
     */
    public function getDedupEpoch(): ?Carbon
    {
        $raw = $this->settings->getSetting('payment.dedup_epoch');

        if (empty($raw)) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Exception $e) {
            Log::warning('Mining Manager: payment.dedup_epoch is not a readable date, cutover guard disabled', [
                'value' => $raw,
            ]);

            return null;
        }
    }

    /**
     * True when a payment predates the cutover and must be left alone.
     *
     * @param  mixed  $date
     */
    public function isBeforeEpoch($date): bool
    {
        $epoch = $this->getDedupEpoch();

        if ($epoch === null || empty($date)) {
            return false;
        }

        try {
            return Carbon::parse($date)->lt($epoch);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Apply a wallet payment to invoices.
     *
     * @param  object  $transaction  A corporation_wallet_journals row. Needs at
     *                               least id, amount, date and first_party_id.
     * @param  int|null  $primaryTaxId  Invoice to settle first. When null the
     *                                  payment simply flows to the payer's
     *                                  oldest unpaid invoices.
     * @param  array  $options  source, allocated_by, notes, cascade, hold_credit
     * @return array  applied, reason, allocations, credited, total, claimed_tax_id
     */
    public function allocate(object $transaction, ?int $primaryTaxId = null, array $options = []): array
    {
        $transactionId = (int) $transaction->id;
        $amount = round(abs((float) $transaction->amount), 2);
        $payerCharacterId = (int) ($transaction->first_party_id ?? 0);
        $transactionDate = $transaction->date ?? Carbon::now();

        $source = $options['source'] ?? PaymentAllocation::SOURCE_AUTO;
        $allocatedBy = $options['allocated_by'] ?? null;
        $notes = $options['notes'] ?? null;

        $paymentSettings = $this->settings->getPaymentSettings();
        $cascade = $options['cascade'] ?? (bool) ($paymentSettings['cascade_remainder'] ?? true);
        $holdCredit = $options['hold_credit'] ?? (bool) ($paymentSettings['hold_surplus_as_credit'] ?? true);
        $acceptAlts = (bool) ($paymentSettings['accept_alt_characters'] ?? true);

        $result = [
            'applied' => false,
            'reason' => null,
            'allocations' => [],
            'credited' => 0.0,
            'total' => $amount,
            'claimed_tax_id' => null,
        ];

        if ($amount <= 0) {
            $result['reason'] = 'zero_amount';

            return $result;
        }

        // Automated matching stops at the cutover so historical payments are
        // never re-credited. A director assigning one by hand is explicitly
        // choosing to deal with an old transfer, so that path opts out.
        if (!($options['ignore_cutover'] ?? false) && $this->isBeforeEpoch($transactionDate)) {
            $result['reason'] = 'before_cutover';

            return $result;
        }

        $eligibleCharacterIds = $this->eligibleCharacterIds($payerCharacterId, $acceptAlts);

        DB::transaction(function () use (
            $transaction, $transactionId, $amount, $payerCharacterId, $transactionDate,
            $primaryTaxId, $eligibleCharacterIds, $cascade, $holdCredit,
            $source, $allocatedBy, $notes, &$result
        ) {
            // Compare-and-swap on the unique transaction_id. Losing this race
            // means another path already consumed the payment, so we bail
            // before any invoice is touched. tax_id is backfilled below once
            // we know which invoice took the first slice.
            try {
                ProcessedTransaction::create([
                    'transaction_id' => $transactionId,
                    'character_id' => $payerCharacterId,
                    'tax_id' => $primaryTaxId,
                    'matched_at' => Carbon::now(),
                ]);
            } catch (QueryException $e) {
                $result['reason'] = 'already_claimed';
                Log::info("Mining Manager: transaction {$transactionId} already claimed, skipping double-credit attempt");

                return;
            }

            $targets = $this->buildTargetList($primaryTaxId, $eligibleCharacterIds, $cascade);

            if (empty($targets)) {
                $result['reason'] = 'no_open_invoice';
            }

            $remaining = $amount;
            $firstTaxId = null;

            foreach ($targets as $index => $taxId) {
                if ($remaining < self::SETTLED_TOLERANCE) {
                    break;
                }

                $tax = MiningTax::where('id', $taxId)->lockForUpdate()->first();

                if (!$tax) {
                    continue;
                }

                $outstanding = round((float) $tax->amount_owed - (float) ($tax->amount_paid ?? 0), 2);

                if ($outstanding < self::SETTLED_TOLERANCE) {
                    continue;
                }

                $slice = round(min($remaining, $outstanding), 2);
                $remaining = round($remaining - $slice, 2);

                // The first invoice keeps the caller's stated source; anything
                // the remainder rolls onto is a cascade regardless of how the
                // payment was found in the first place.
                $sliceSource = $index === 0 || $firstTaxId === null
                    ? $source
                    : PaymentAllocation::SOURCE_CASCADE;

                $this->creditTax($tax, $slice, $transactionId, $transactionDate);

                PaymentAllocation::create([
                    'transaction_id' => $transactionId,
                    'credit_id' => null,
                    'mining_tax_id' => $tax->id,
                    'character_id' => $payerCharacterId,
                    'amount' => $slice,
                    'source' => $sliceSource,
                    'allocated_by' => $allocatedBy,
                    'notes' => $notes,
                    'allocated_at' => Carbon::now(),
                ]);

                $result['allocations'][] = [
                    'tax_id' => (int) $tax->id,
                    'character_id' => (int) $tax->character_id,
                    'amount' => $slice,
                    'source' => $sliceSource,
                    'fully_paid' => $slice >= $outstanding - self::SETTLED_TOLERANCE,
                ];

                if ($firstTaxId === null) {
                    $firstTaxId = (int) $tax->id;
                }
            }

            if ($firstTaxId !== null) {
                $result['applied'] = true;
                $result['claimed_tax_id'] = $firstTaxId;
                $result['reason'] = null;

                // Point the claim at whatever actually took the money, which
                // is not always the invoice the caller nominated.
                ProcessedTransaction::where('transaction_id', $transactionId)
                    ->update(['tax_id' => $firstTaxId]);
            }

            if ($remaining >= self::SETTLED_TOLERANCE) {
                if ($holdCredit) {
                    PaymentCredit::create([
                        'character_id' => $payerCharacterId,
                        'transaction_id' => $transactionId,
                        'amount' => $remaining,
                        'remaining' => $remaining,
                        'source' => PaymentCredit::SOURCE_OVERPAYMENT,
                        'created_by' => $allocatedBy,
                        'notes' => $notes,
                    ]);

                    $result['credited'] = $remaining;

                    Log::info('Mining Manager: held surplus as credit', [
                        'transaction_id' => $transactionId,
                        'character_id' => $payerCharacterId,
                        'amount' => $remaining,
                    ]);
                } else {
                    $result['credited'] = 0.0;

                    Log::info('Mining Manager: payment left a surplus and credit holding is off, surplus discarded', [
                        'transaction_id' => $transactionId,
                        'character_id' => $payerCharacterId,
                        'amount' => $remaining,
                    ]);
                }
            }

            // Nothing to apply it to and nothing held: release the claim so a
            // later run (or a director, by hand) can still use the payment.
            if ($firstTaxId === null && $result['credited'] <= 0) {
                ProcessedTransaction::where('transaction_id', $transactionId)->delete();
            }
        });

        if ($result['applied']) {
            Log::info('Mining Manager: applied payment', [
                'transaction_id' => $transactionId,
                'character_id' => $payerCharacterId,
                'total' => $amount,
                'invoices' => count($result['allocations']),
                'surplus_held' => $result['credited'],
                'source' => $source,
            ]);
        }

        return $result;
    }

    /**
     * Ordered list of invoice ids a payment should try to settle.
     *
     * The nominated invoice always comes first so a director's explicit choice
     * wins. Everything after it is that player's remaining unpaid invoices,
     * oldest period first, so the remainder pays down the longest-standing
     * debt rather than the most convenient one.
     *
     * @return array<int>
     */
    protected function buildTargetList(?int $primaryTaxId, array $eligibleCharacterIds, bool $cascade): array
    {
        $targets = [];

        if ($primaryTaxId !== null) {
            $targets[] = $primaryTaxId;
        }

        if (!$cascade && $primaryTaxId !== null) {
            return $targets;
        }

        if (empty($eligibleCharacterIds)) {
            return $targets;
        }

        // Cascade off with nothing nominated still needs somewhere to go, so
        // take the oldest open invoice and stop there.
        $limit = $cascade ? null : 1;

        $others = MiningTax::whereIn('character_id', $eligibleCharacterIds)
            ->whereIn('status', ['unpaid', 'overdue', 'partial'])
            ->when($primaryTaxId !== null, fn ($q) => $q->where('id', '!=', $primaryTaxId))
            ->orderByRaw('COALESCE(period_start, month) asc')
            ->orderBy('id')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_merge($targets, $others);
    }

    /**
     * Move an invoice forward by one slice.
     *
     * transaction_id is still written for backwards compatibility with the
     * views and exports that read it, but it only ever holds the most recent
     * payment. The allocation rows are the real history.
     */
    protected function creditTax(MiningTax $tax, float $slice, ?int $transactionId, $transactionDate): void
    {
        $newPaid = round((float) ($tax->amount_paid ?? 0) + $slice, 2);
        $settled = ((float) $tax->amount_owed - $newPaid) < self::SETTLED_TOLERANCE;

        // Credit drawdowns have no wallet transaction behind them, so leave the
        // column null rather than writing a zero that reads like a real id.
        $reference = $transactionId ?: null;

        $tax->update([
            'amount_paid' => $newPaid,
            'paid_at' => $transactionDate,
            'status' => $settled ? 'paid' : 'partial',
            'transaction_id' => $reference,
        ]);

        if ($settled) {
            TaxCode::where('mining_tax_id', $tax->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'used',
                    'used_at' => Carbon::now(),
                    'transaction_id' => $reference,
                ]);
        }
    }

    /**
     * Draw a character's held credit down against an invoice.
     *
     * Called when a fresh invoice is calculated for someone who previously
     * overpaid. Returns how much was applied.
     */
    public function applyCreditsToTax(MiningTax $tax, ?int $actorId = null): float
    {
        $paymentSettings = $this->settings->getPaymentSettings();
        $acceptAlts = (bool) ($paymentSettings['accept_alt_characters'] ?? true);
        $eligibleCharacterIds = $this->eligibleCharacterIds((int) $tax->character_id, $acceptAlts);

        $applied = 0.0;

        DB::transaction(function () use ($tax, $eligibleCharacterIds, $actorId, &$applied) {
            $locked = MiningTax::where('id', $tax->id)->lockForUpdate()->first();

            if (!$locked) {
                return;
            }

            $outstanding = round((float) $locked->amount_owed - (float) ($locked->amount_paid ?? 0), 2);

            if ($outstanding < self::SETTLED_TOLERANCE) {
                return;
            }

            $credits = PaymentCredit::availableFor($eligibleCharacterIds)
                ->lockForUpdate()
                ->get();

            foreach ($credits as $credit) {
                if ($outstanding < self::SETTLED_TOLERANCE) {
                    break;
                }

                $slice = round(min($outstanding, (float) $credit->remaining), 2);

                if ($slice < self::SETTLED_TOLERANCE) {
                    continue;
                }

                $credit->update(['remaining' => round((float) $credit->remaining - $slice, 2)]);

                $this->creditTax($locked, $slice, $credit->transaction_id, Carbon::now());

                PaymentAllocation::create([
                    'transaction_id' => $credit->transaction_id,
                    'credit_id' => $credit->id,
                    'mining_tax_id' => $locked->id,
                    'character_id' => (int) $credit->character_id,
                    'amount' => $slice,
                    'source' => PaymentAllocation::SOURCE_CREDIT,
                    'allocated_by' => $actorId,
                    'notes' => 'Drawn from surplus held on an earlier payment',
                    'allocated_at' => Carbon::now(),
                ]);

                $outstanding = round($outstanding - $slice, 2);
                $applied = round($applied + $slice, 2);
            }

            // Say it on the invoice itself, not just in the allocation rows.
            // An invoice that arrives already part-paid reads as a mistake
            // unless it explains where the money came from, and this is the
            // text that reaches the details page, the exports and the receipt.
            if ($applied > 0) {
                $note = Carbon::now()->format('Y-m-d H:i') . ' - '
                    . number_format($applied, 0)
                    . ' ISK paid from account balance held on an earlier overpayment.';

                $locked->update([
                    'notes' => $locked->notes ? $locked->notes . "

" . $note : $note,
                ]);
            }
        });

        if ($applied > 0) {
            Log::info('Mining Manager: applied held credit to invoice', [
                'tax_id' => (int) $tax->id,
                'character_id' => (int) $tax->character_id,
                'amount' => $applied,
            ]);
        }

        return $applied;
    }

    /**
     * Undo everything a payment did.
     *
     * Refuses when part of the surplus it generated has already been spent on
     * another invoice, because unwinding that would mean silently reopening an
     * invoice the member has since been told is settled. In that case the
     * credit has to be dealt with first.
     *
     * @return array  reversed, reason, invoices
     */
    public function unallocate(int $transactionId, ?int $actorId = null): array
    {
        $result = [
            'reversed' => false,
            'reason' => null,
            'invoices' => [],
        ];

        DB::transaction(function () use ($transactionId, $actorId, &$result) {
            $credit = PaymentCredit::where('transaction_id', $transactionId)->lockForUpdate()->first();

            if ($credit && round((float) $credit->remaining, 2) < round((float) $credit->amount, 2)) {
                $result['reason'] = 'credit_partially_spent';

                return;
            }

            $allocations = PaymentAllocation::where('transaction_id', $transactionId)
                ->whereNull('credit_id')
                ->get();

            foreach ($allocations as $allocation) {
                $tax = MiningTax::where('id', $allocation->mining_tax_id)->lockForUpdate()->first();

                if (!$tax) {
                    continue;
                }

                $newPaid = round(max(0, (float) ($tax->amount_paid ?? 0) - (float) $allocation->amount), 2);
                $settled = ((float) $tax->amount_owed - $newPaid) < self::SETTLED_TOLERANCE;

                $tax->update([
                    'amount_paid' => $newPaid,
                    'status' => $newPaid <= 0 ? 'unpaid' : ($settled ? 'paid' : 'partial'),
                    'paid_at' => $newPaid <= 0 ? null : $tax->paid_at,
                    'transaction_id' => null,
                ]);

                // An invoice that is no longer settled needs a live code again,
                // otherwise the member has nothing to quote on their next payment.
                if (!$settled) {
                    TaxCode::where('mining_tax_id', $tax->id)
                        ->where('status', 'used')
                        ->where('transaction_id', $transactionId)
                        ->update([
                            'status' => 'active',
                            'used_at' => null,
                            'transaction_id' => null,
                        ]);
                }

                $result['invoices'][] = [
                    'tax_id' => (int) $tax->id,
                    'amount' => (float) $allocation->amount,
                ];
            }

            // Scoped to the direct allocations. A credit drawdown carries the
            // same transaction id but belongs to whatever invoice it later went
            // to, and the guard above already refused when any of it was spent.
            PaymentAllocation::where('transaction_id', $transactionId)
                ->whereNull('credit_id')
                ->delete();

            if ($credit) {
                $credit->delete();
            }

            ProcessedTransaction::where('transaction_id', $transactionId)->delete();

            $result['reversed'] = true;
        });

        if ($result['reversed']) {
            Log::info('Mining Manager: reversed payment allocation', [
                'transaction_id' => $transactionId,
                'invoices' => count($result['invoices']),
                'actor' => $actorId,
            ]);
        }

        return $result;
    }
}
