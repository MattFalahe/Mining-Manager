<?php

namespace MiningManager\Services\Tax;

use MiningManager\Models\MiningTax;
use MiningManager\Models\PaymentAllocation;
use MiningManager\Models\ProcessedTransaction;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Tax\Concerns\ResolvesCharacterOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Reading and matching corporation wallet payments against tax invoices.
 *
 * Everything here works from corporation_wallet_journals. That is the wallet
 * the ISK actually arrives in, and it is populated for every payer whether or
 * not they have added their character to SeAT with a wallet scope. An earlier
 * version of this class matched against character_wallet_journals, which meant
 * a payment from anyone without a personal wallet token could never be
 * verified even though the corp could plainly see the ISK.
 *
 * Tax codes are read from reason and description together. EVE puts the note a
 * player types into reason; description is CCP's generated sentence and never
 * contains the code.
 *
 * Applying a payment is not done here. Anything that credits an invoice goes
 * through PaymentAllocationService so there is one implementation of the
 * claim, cascade and surplus rules.
 */
class WalletTransferService
{
    use ResolvesCharacterOwnership;

    protected SettingsManagerService $settings;

    protected PaymentAllocationService $allocator;

    public function __construct(SettingsManagerService $settings, PaymentAllocationService $allocator)
    {
        $this->settings = $settings;
        $this->allocator = $allocator;
    }

    /**
     * Set the corporation context for settings retrieval.
     */
    public function setCorporationContext(?int $corporationId): self
    {
        $this->settings->setActiveCorporation($corporationId);
        $this->allocator->setCorporationContext($corporationId);

        return $this;
    }

    /**
     * The wallet divisions to check for payments.
     *
     * Always includes the master wallet as a fallback: operators routinely
     * configure a dedicated tax division but members pay into the master
     * wallet anyway because that is what the corp hangar shows them.
     *
     * @return array
     */
    private function getPaymentDivisions(): array
    {
        $paymentSettings = $this->settings->getPaymentSettings();
        $division = (int) ($paymentSettings['wallet_division'] ?? 1);

        $divisions = [1];
        if ($division !== 1) {
            array_unshift($divisions, $division);
        }

        return array_unique($divisions);
    }

    /**
     * The corporation whose wallet we are reading.
     */
    private function resolveCorporationId(?int $corporationId = null): ?int
    {
        if ($corporationId) {
            return $corporationId;
        }

        $configured = $this->settings->getSetting('general.moon_owner_corporation_id');

        if ($configured) {
            return (int) $configured;
        }

        return $this->settings->getAllCorporations()->first()->corporation_id ?? null;
    }

    /**
     * Base query for player donations into the configured wallet divisions.
     */
    private function donationQuery(?int $corporationId, ?int $days = null)
    {
        $query = DB::table('corporation_wallet_journals')
            ->where('ref_type', 'player_donation')
            ->whereIn('division', $this->getPaymentDivisions());

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        if ($days !== null) {
            $query->where('date', '>=', Carbon::now()->subDays($days));
        }

        return $query;
    }

    /**
     * Load a single donation by its wallet journal reference id.
     *
     * @return object|null
     */
    public function findTransaction(int $transactionId, ?int $corporationId = null)
    {
        return $this->donationQuery($this->resolveCorporationId($corporationId))
            ->where('id', $transactionId)
            ->first();
    }

    /**
     * Pull a tax code out of a journal row.
     *
     * Both fields are searched because operators have historically told
     * members to put the code in either one, and because a copy-pasted code
     * sometimes lands in the wrong box.
     */
    public function extractTaxCodeFromTransaction(object $transaction): ?string
    {
        $text = trim(($transaction->reason ?? '') . ' ' . ($transaction->description ?? ''));

        return TaxCode::extractCodeFromText($text);
    }

    /**
     * The standing keyword that marks a payment as paying ahead.
     *
     * Empty disables the feature. Returns null rather than an empty string so
     * callers only have one falsy case to think about.
     */
    public function getUpfrontKeyword(): ?string
    {
        $keyword = trim((string) ($this->settings->getPaymentSettings()['upfront_keyword'] ?? ''));

        return $keyword === '' ? null : $keyword;
    }

    /**
     * True when a payment is a deliberate pay-ahead rather than settlement of
     * a specific invoice.
     *
     * Checked only after tax-code extraction has come up empty, so a member who
     * quotes both a code and the keyword gets the specific behaviour they asked
     * for. Matching is case-insensitive and substring-based because the reason
     * field is typed by hand under time pressure and will arrive with stray
     * spaces, punctuation and capitalisation.
     */
    public function looksLikeUpfrontPayment(object $transaction): bool
    {
        $keyword = $this->getUpfrontKeyword();

        if ($keyword === null) {
            return false;
        }

        $text = trim(($transaction->reason ?? '') . ' ' . ($transaction->description ?? ''));

        return $text !== '' && stripos($text, $keyword) !== false;
    }

    /**
     * Find the tax code record a payment refers to.
     *
     * With payment.accept_alt_characters on (the default) the search widens to
     * every character belonging to the paying player, so someone can settle
     * their main's bill from whichever alt is holding the ISK.
     */
    public function resolveTaxCodeRecord(string $code, int $payerCharacterId): ?TaxCode
    {
        $paymentSettings = $this->settings->getPaymentSettings();
        $acceptAlts = (bool) ($paymentSettings['accept_alt_characters'] ?? true);
        $eligibleCharacterIds = $this->eligibleCharacterIds($payerCharacterId, $acceptAlts);

        $record = TaxCode::where('code', $code)
            ->whereIn('character_id', $eligibleCharacterIds)
            ->where('status', 'active')
            ->first();

        if ($record) {
            return $record;
        }

        // A code that has already been marked used still identifies the right
        // invoice. That matters for follow-up payments on an invoice that was
        // briefly settled and then reopened, and for members who reuse the
        // code from their last payment out of habit.
        return TaxCode::where('code', $code)
            ->whereIn('character_id', $eligibleCharacterIds)
            ->where('status', '!=', 'cancelled')
            ->first();
    }

    /**
     * Scan recent donations and match what can be matched.
     *
     * @param  int  $days
     * @param  bool  $autoMatch  Apply the payments, rather than only reporting them
     * @return array  matched, unmatched, skipped, errors
     */
    public function verifyPayments(int $days = 7, bool $autoMatch = false): array
    {
        Log::info("Mining Manager: starting payment verification (last {$days} days)");

        $corporationId = $this->resolveCorporationId();

        $transactions = $this->donationQuery($corporationId, $days)
            ->whereNotIn('id', function ($query) {
                $query->select('transaction_id')->from('mining_manager_processed_transactions');
            })
            ->orderBy('date', 'desc')
            ->get();

        $matched = 0;
        $unmatched = 0;
        $skipped = 0;
        $errors = [];

        foreach ($transactions as $transaction) {
            try {
                if ($this->allocator->isBeforeEpoch($transaction->date)) {
                    $skipped++;
                    continue;
                }

                $result = $this->matchTransaction((int) $transaction->id, [
                    'apply' => $autoMatch,
                    'transaction' => $transaction,
                ]);

                if ($result['matched']) {
                    $matched++;
                } else {
                    $unmatched++;
                }
            } catch (\Exception $e) {
                Log::error("Mining Manager: error processing transaction {$transaction->id}: " . $e->getMessage());
                $errors[] = [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::info("Mining Manager: payment verification complete. Matched: {$matched}, unmatched: {$unmatched}, before cutover: {$skipped}");

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Try to match one donation to an invoice via its tax code.
     *
     * Returns why it failed rather than a bare false, because the wallet
     * verification page needs to tell a director what to do next: a payment
     * with no code needs assigning by hand, a payment whose code points at an
     * unknown invoice is a different problem entirely.
     *
     * @param  array  $options  apply (bool), transaction (preloaded row)
     * @return array  matched, applied, reason, tax_id, tax_code, amount, allocations, credited
     */
    public function matchTransaction(int $transactionId, array $options = []): array
    {
        $result = [
            'matched' => false,
            'applied' => false,
            'reason' => null,
            'tax_id' => null,
            'tax_code' => null,
            'amount' => 0.0,
            'allocations' => [],
            'credited' => 0.0,
            'upfront' => false,
        ];

        $transaction = $options['transaction'] ?? $this->findTransaction($transactionId);

        if (!$transaction) {
            $result['reason'] = 'transaction_not_found';

            return $result;
        }

        $result['amount'] = round(abs((float) $transaction->amount), 2);

        if (ProcessedTransaction::isProcessed($transactionId)) {
            $result['reason'] = 'already_claimed';

            return $result;
        }

        $ignoreCutover = (bool) ($options['ignore_cutover'] ?? false);

        if (!$ignoreCutover && $this->allocator->isBeforeEpoch($transaction->date)) {
            $result['reason'] = 'before_cutover';

            return $result;
        }

        $code = $this->extractTaxCodeFromTransaction($transaction);

        if (!$code) {
            // No code, but the member may have marked it as paying ahead. That
            // is a real instruction, not a failure to match: settle whatever
            // they already owe, oldest first, and bank the rest as balance.
            if ($this->looksLikeUpfrontPayment($transaction)) {
                return $this->applyUpfrontPayment($transaction, $result, $options);
            }

            $result['reason'] = 'no_tax_code';

            return $result;
        }

        $result['tax_code'] = $code;

        $payerCharacterId = (int) ($transaction->first_party_id ?? 0);
        $taxCodeRecord = $this->resolveTaxCodeRecord($code, $payerCharacterId);

        if (!$taxCodeRecord) {
            $result['reason'] = 'tax_code_not_recognised';
            Log::warning("Mining Manager: tax code '{$code}' not found for character {$payerCharacterId}");

            return $result;
        }

        $tax = MiningTax::find($taxCodeRecord->mining_tax_id);

        if (!$tax) {
            $result['reason'] = 'invoice_missing';
            Log::warning("Mining Manager: no invoice behind tax code '{$code}'");

            return $result;
        }

        $result['matched'] = true;
        $result['tax_id'] = (int) $tax->id;

        if ((int) $taxCodeRecord->character_id !== $payerCharacterId) {
            Log::info('Mining Manager: payment credited via an alt of the taxed character', [
                'tax_code' => $code,
                'taxed_character_id' => (int) $taxCodeRecord->character_id,
                'paying_character_id' => $payerCharacterId,
                'transaction_id' => $transactionId,
            ]);
        }

        $tolerance = (float) $this->settings->getSetting('payment.match_tolerance', 100);
        if (abs($result['amount'] - (float) $tax->amount_owed) > $tolerance) {
            Log::warning("Mining Manager: amount differs from invoice for code '{$code}': owed " .
                number_format($tax->amount_owed, 2) . ', paid ' . number_format($result['amount'], 2));
        }

        if (!($options['apply'] ?? true)) {
            return $result;
        }

        $allocation = $this->allocator->allocate($transaction, (int) $tax->id, [
            'source' => PaymentAllocation::SOURCE_AUTO,
            'ignore_cutover' => $ignoreCutover,
        ]);

        $result['applied'] = $allocation['applied'];
        $result['allocations'] = $allocation['allocations'];
        $result['credited'] = $allocation['credited'];

        if (!$allocation['applied']) {
            $result['reason'] = $allocation['reason'];
        }

        return $result;
    }

    /**
     * Consume a payment the member marked as paying ahead.
     *
     * No invoice is nominated, so the allocator settles their oldest open
     * invoices first and banks whatever is left. Clearing debt before banking
     * is the only sane order: the alternative parks credit while leaving the
     * member overdue, which would then trigger the very notices the payment
     * was meant to avoid.
     *
     * @param  array  $result  The in-progress result from matchTransaction()
     */
    protected function applyUpfrontPayment(object $transaction, array $result, array $options): array
    {
        $result['matched'] = true;
        $result['upfront'] = true;

        if (!($options['apply'] ?? true)) {
            return $result;
        }

        $allocation = $this->allocator->allocate($transaction, null, [
            'source' => PaymentAllocation::SOURCE_AUTO,
            'notes' => 'Paid ahead using ' . ($this->getUpfrontKeyword() ?? 'the upfront keyword'),
            'ignore_cutover' => (bool) ($options['ignore_cutover'] ?? false),
        ]);

        $result['applied'] = $allocation['applied'];
        $result['allocations'] = $allocation['allocations'];
        $result['credited'] = $allocation['credited'];

        // Nothing owing and nothing banked means the surplus was discarded
        // because credit holding is switched off. That is a configuration
        // choice rather than a match failure, but the caller should not be told
        // the payment was applied when no money moved anywhere.
        if (!$allocation['applied'] && $allocation['credited'] <= 0) {
            $result['reason'] = $allocation['reason'] ?? 'upfront_not_held';
        }

        if ($allocation['applied'] || $allocation['credited'] > 0) {
            Log::info('Mining Manager: took an upfront payment', [
                'transaction_id' => (int) $transaction->id,
                'character_id' => (int) ($transaction->first_party_id ?? 0),
                'invoices_settled' => count($allocation['allocations']),
                'banked' => $allocation['credited'],
            ]);
        }

        return $result;
    }

    /**
     * Match a single transaction and apply it.
     *
     * Thin boolean wrapper kept for call sites that only care whether it
     * worked. New code should use matchTransaction() and read the reason.
     */
    public function matchTransactionToTax(int $transactionId): bool
    {
        return $this->matchTransaction($transactionId, ['apply' => true])['applied'];
    }

    /**
     * Donations that carry a recognisable tax code but have not been credited.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getPendingPayments(int $days = 7)
    {
        $corporationId = $this->resolveCorporationId();

        return $this->donationQuery($corporationId, $days)
            ->whereNotIn('id', function ($query) {
                $query->select('transaction_id')->from('mining_manager_processed_transactions');
            })
            ->get()
            ->filter(fn ($transaction) => $this->extractTaxCodeFromTransaction($transaction) !== null)
            ->values();
    }

    /**
     * Assign a payment to a specific invoice by hand.
     *
     * This is what a director does with a transfer that arrived without a tax
     * code. The payer still has to be the invoiced character or one of their
     * alts, unless strict mode is on, in which case it must be the exact
     * character.
     *
     * @param  array  $options  allocated_by, notes, cascade
     * @return array  success, reason, allocations, credited
     */
    public function manualMatch(int $transactionId, int $taxId, array $options = []): array
    {
        $result = [
            'success' => false,
            'reason' => null,
            'allocations' => [],
            'credited' => 0.0,
        ];

        $transaction = $this->findTransaction($transactionId);

        if (!$transaction) {
            $result['reason'] = 'transaction_not_found';

            return $result;
        }

        $tax = MiningTax::find($taxId);

        if (!$tax) {
            $result['reason'] = 'invoice_not_found';

            return $result;
        }

        if (ProcessedTransaction::isProcessed($transactionId)) {
            $result['reason'] = 'already_claimed';

            return $result;
        }

        $payerCharacterId = (int) ($transaction->first_party_id ?? 0);
        $paymentSettings = $this->settings->getPaymentSettings();
        $acceptAlts = (bool) ($paymentSettings['accept_alt_characters'] ?? true);

        $isSameOwner = $acceptAlts
            ? $this->sharesSeatUser((int) $tax->character_id, $payerCharacterId)
            : ((int) $tax->character_id === $payerCharacterId);

        if (!$isSameOwner) {
            $result['reason'] = 'character_mismatch';

            return $result;
        }

        if ((int) $tax->character_id !== $payerCharacterId) {
            Log::info('Mining Manager: manual assignment credited via an alt', [
                'tax_id' => (int) $tax->id,
                'taxed_character_id' => (int) $tax->character_id,
                'paying_character_id' => $payerCharacterId,
                'transaction_id' => $transactionId,
            ]);
        }

        // A manual assignment is a deliberate act, so it is allowed to settle
        // a payment that predates the verification cutover. That is the whole
        // point of the queue: old transfers nobody could match automatically.
        $allocation = $this->allocator->allocate($transaction, (int) $tax->id, [
            'source' => PaymentAllocation::SOURCE_MANUAL,
            'allocated_by' => $options['allocated_by'] ?? null,
            'notes' => $options['notes'] ?? null,
            'cascade' => $options['cascade'] ?? null,
            'ignore_cutover' => true,
        ]);

        $result['success'] = $allocation['applied'];
        $result['allocations'] = $allocation['allocations'];
        $result['credited'] = $allocation['credited'];

        if (!$allocation['applied']) {
            $result['reason'] = $allocation['reason'];
        }

        // A codeless payment leaves no trail back to the invoice, so record
        // one. The code reads MANUAL rather than a generated string because it
        // was never quoted by the member.
        if ($allocation['applied']) {
            TaxCode::firstOrCreate(
                [
                    'mining_tax_id' => $tax->id,
                    'character_id' => $tax->character_id,
                    'transaction_id' => $transactionId,
                ],
                [
                    'code' => 'MANUAL',
                    'status' => 'used',
                    'generated_at' => Carbon::now(),
                    'used_at' => Carbon::now(),
                    'notes' => $options['notes'] ?? 'Assigned by hand from wallet verification',
                ]
            );
        }

        return $result;
    }

    /**
     * Undo a payment assignment, putting the transaction back in the queue.
     */
    public function unassign(int $transactionId, ?int $actorId = null): array
    {
        return $this->allocator->unallocate($transactionId, $actorId);
    }

    /**
     * Reverse every payment against an invoice and reopen it.
     *
     * Distinct from unassign(): this works from the invoice side and drops
     * everything credited to it, which is what you want when an invoice was
     * settled in error rather than when one payment went to the wrong place.
     */
    public function reversePayment(int $taxId, string $reason): bool
    {
        try {
            return DB::transaction(function () use ($taxId, $reason) {
                $tax = MiningTax::findOrFail($taxId);

                if ($tax->status !== 'paid' && $tax->status !== 'partial') {
                    throw new \Exception('Invoice is not marked as paid');
                }

                // Release the claims too, otherwise the payments stay consumed
                // and can never be reassigned anywhere.
                $transactionIds = PaymentAllocation::where('mining_tax_id', $taxId)
                    ->whereNotNull('transaction_id')
                    ->pluck('transaction_id')
                    ->unique()
                    ->all();

                PaymentAllocation::where('mining_tax_id', $taxId)->delete();

                if (!empty($transactionIds)) {
                    $stillUsed = PaymentAllocation::whereIn('transaction_id', $transactionIds)
                        ->pluck('transaction_id')
                        ->unique()
                        ->all();

                    $releasable = array_diff($transactionIds, $stillUsed);

                    if (!empty($releasable)) {
                        ProcessedTransaction::whereIn('transaction_id', $releasable)->delete();
                    }
                }

                $tax->update([
                    'amount_paid' => 0,
                    'paid_at' => null,
                    'status' => 'unpaid',
                    'transaction_id' => null,
                    'notes' => ($tax->notes ? $tax->notes . "\n" : '') .
                        Carbon::now()->toDateTimeString() . " - Payment reversed. Reason: {$reason}",
                ]);

                TaxCode::where('mining_tax_id', $tax->id)
                    ->where('status', 'used')
                    ->update(['status' => 'cancelled']);

                Log::info("Mining Manager: reversed all payments for invoice {$taxId}");

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Mining Manager: error reversing payment: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Find the donation that settles a given invoice, by tax code.
     *
     * @return array|null
     */
    public function verifyPaymentFromJournal(MiningTax $taxRecord, ?int $corporationId = null): ?array
    {
        try {
            $taxCode = TaxCode::where('mining_tax_id', $taxRecord->id)
                ->where('status', '!=', 'cancelled')
                ->first();

            if (!$taxCode) {
                return null;
            }

            $paymentSettings = $this->settings->getPaymentSettings();
            $acceptAlts = (bool) ($paymentSettings['accept_alt_characters'] ?? true);
            $eligibleCharacterIds = $this->eligibleCharacterIds((int) $taxRecord->character_id, $acceptAlts);

            $query = $this->donationQuery($corporationId)
                ->whereIn('first_party_id', $eligibleCharacterIds)
                ->where(function ($q) use ($taxCode) {
                    $q->where('reason', 'LIKE', "%{$taxCode->code}%")
                        ->orWhere('description', 'LIKE', "%{$taxCode->code}%");
                });

            // The claim table is the only reliable answer to "has this been
            // credited yet". mining_taxes.transaction_id holds just the most
            // recent payment, so an invoice settled in instalments would
            // re-offer its earlier payments forever.
            $query->whereNotIn('id', function ($sub) {
                $sub->select('transaction_id')->from('mining_manager_processed_transactions');
            });

            $transaction = $query->orderBy('date', 'desc')->first();

            if (!$transaction) {
                return null;
            }

            $tolerance = (float) $this->settings->getSetting('payment.match_tolerance', 100);
            $amountDiff = abs(abs((float) $transaction->amount) - (float) $taxRecord->amount_owed);
            $amountMismatch = $amountDiff > $tolerance;

            if ($amountMismatch) {
                Log::warning("Mining Manager: amount differs for invoice {$taxRecord->id}, owed " .
                    number_format($taxRecord->amount_owed, 2) . ', paid ' .
                    number_format(abs($transaction->amount), 2));
            }

            return [
                'id' => $transaction->id,
                'corporation_id' => $transaction->corporation_id,
                'date' => $transaction->date,
                'amount' => abs((float) $transaction->amount),
                'first_party_id' => $transaction->first_party_id,
                'second_party_id' => $transaction->second_party_id,
                'reason' => $transaction->reason,
                'description' => $transaction->description,
                'ref_type' => $transaction->ref_type,
                'tax_code' => $taxCode->code,
                'amount_mismatch' => $amountMismatch,
            ];
        } catch (\Exception $e) {
            Log::error("Mining Manager: failed to check the journal for invoice {$taxRecord->id}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Every player donation for a corporation, with the payer's name attached.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getCorporationDonations(int $corporationId, int $days = 30)
    {
        $donations = DB::table('corporation_wallet_journals as cwj')
            ->leftJoin('character_infos as ci', 'cwj.first_party_id', '=', 'ci.character_id')
            ->where('cwj.corporation_id', $corporationId)
            ->where('cwj.ref_type', 'player_donation')
            ->whereIn('cwj.division', $this->getPaymentDivisions())
            ->where('cwj.date', '>=', Carbon::now()->subDays($days))
            ->select('cwj.*', 'ci.name as character_name')
            ->orderBy('cwj.date', 'desc')
            ->get();

        $claimed = $this->claimedTransactionMap(
            $donations->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        return $donations
            ->map(function ($donation) use ($claimed) {
                $taxId = $claimed[(int) $donation->id] ?? null;

                $donation->verified = $taxId !== null;
                $donation->mismatch = false;
                $donation->matched_tax_id = $taxId;

                return $donation;
            });
    }

    /**
     * Auto-verify outstanding invoices against the corporation wallet.
     *
     * @return array
     */
    public function autoVerifyFromCorporationWallet(?int $corporationId = null, int $days = 30): array
    {
        $verified = 0;
        $failed = 0;
        $errors = [];

        $unpaidTaxes = MiningTax::whereIn('status', ['unpaid', 'overdue', 'partial'])->get();

        foreach ($unpaidTaxes as $tax) {
            try {
                $transaction = $this->verifyPaymentFromJournal($tax, $corporationId);

                if (!$transaction) {
                    continue;
                }

                if ($this->allocator->isBeforeEpoch($transaction['date'])) {
                    continue;
                }

                $row = $this->findTransaction((int) $transaction['id'], $corporationId);

                if (!$row) {
                    continue;
                }

                $applied = $this->allocator->allocate($row, (int) $tax->id, [
                    'source' => PaymentAllocation::SOURCE_AUTO,
                ]);

                if ($applied['applied']) {
                    $verified++;

                    Log::info('Mining Manager: auto-verified an invoice payment', [
                        'tax_id' => $tax->id,
                        'character_id' => $tax->character_id,
                        'amount' => $transaction['amount'],
                        'transaction_id' => $transaction['id'],
                    ]);
                }
            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'tax_id' => $tax->id,
                    'error' => $e->getMessage(),
                ];

                Log::error("Mining Manager: failed to auto-verify invoice {$tax->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'verified' => $verified,
            'failed' => $failed,
            'total_checked' => $unpaidTaxes->count(),
            'errors' => $errors,
        ];
    }

    /**
     * Donations still waiting for someone to decide what they are.
     *
     * A donation drops off this list once it has been claimed, whether that
     * was by the automatic matcher or by a director assigning it by hand. It
     * also drops off if it was dismissed.
     *
     * Rows carry a `blocker` describing why they are still here, so the page
     * can say something more useful than "pending".
     *
     * @return \Illuminate\Support\Collection
     */
    public function getUnmatchedDonations(int $corporationId, int $days = 30, bool $includeLegacy = false)
    {
        return $this->unmatchedDonationBreakdown($corporationId, $days, $includeLegacy)['donations'];
    }

    /**
     * The pending list plus a count of what was withheld from it.
     *
     * Pre-cutover payments that carry a valid tax code are hidden by default.
     * The old pipeline recorded only the most recent payment per invoice, so
     * for anything settled in instalments the earlier payments cannot be
     * proven to have been credited even though they were. Showing them invites
     * a director to assign a payment that has already been applied, which the
     * cutover guard does not stop because a manual assignment is deliberate.
     *
     * Pre-cutover payments with NO code stay visible: nothing could ever have
     * matched those automatically, so they are exactly the ones that still
     * need a human. Same for a code that matches no invoice at all.
     *
     * @return array{donations: \Illuminate\Support\Collection, hidden_legacy: int}
     */
    public function unmatchedDonationBreakdown(int $corporationId, int $days = 30, bool $includeLegacy = false): array
    {
        $donations = DB::table('corporation_wallet_journals as cwj')
            ->leftJoin('character_infos as ci', 'cwj.first_party_id', '=', 'ci.character_id')
            ->where('cwj.corporation_id', $corporationId)
            ->where('cwj.ref_type', 'player_donation')
            ->whereIn('cwj.division', $this->getPaymentDivisions())
            ->where('cwj.date', '>=', Carbon::now()->subDays($days))
            ->select('cwj.*', 'ci.name as character_name')
            ->orderBy('cwj.date', 'desc')
            ->get();

        $claimed = $this->claimedTransactionMap(
            $donations->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $dismissedIds = DB::table('mining_manager_dismissed_transactions')
            ->pluck('transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $unmatched = [];
        $hiddenLegacy = 0;

        foreach ($donations as $donation) {
            $transactionId = (int) $donation->id;

            if (isset($claimed[$transactionId]) || in_array($transactionId, $dismissedIds, true)) {
                continue;
            }

            $code = $this->extractTaxCodeFromTransaction($donation);
            $matchedTaxId = null;
            $blocker = 'no_tax_code';

            // Marked as paying ahead and simply not picked up yet. Saying so
            // stops a director assigning by hand something the next scheduled
            // run will handle correctly on its own.
            if (!$code && $this->looksLikeUpfrontPayment($donation)) {
                $blocker = 'upfront_pending';
            }

            if ($code) {
                $taxCode = TaxCode::where('code', $code)->first();

                if ($taxCode) {
                    $matchedTaxId = $taxCode->mining_tax_id;
                    $blocker = 'code_not_applied';
                } else {
                    $blocker = 'tax_code_not_recognised';
                }
            }

            // Only relabel a payment the matcher would otherwise have picked
            // up. A payment with no code at all is stuck for that reason
            // whichever side of the cutover it sits on, and "No tax code" tells
            // a director far more about what to do next.
            if ($blocker === 'code_not_applied' && $this->allocator->isBeforeEpoch($donation->date)) {
                $blocker = 'before_cutover';

                if (!$includeLegacy) {
                    $hiddenLegacy++;
                    continue;
                }
            }

            $donation->verified = false;
            $donation->mismatch = $blocker === 'tax_code_not_recognised';
            $donation->matched_tax_id = $matchedTaxId;
            $donation->tax_code = $code;
            $donation->blocker = $blocker;

            $unmatched[] = $donation;
        }

        return [
            'donations' => collect($unmatched),
            'hidden_legacy' => $hiddenLegacy,
        ];
    }

    /**
     * transaction_id => tax_id for everything already credited.
     *
     * Scoped to the ids actually on screen. The claim table only grows, so
     * loading all of it to answer a question about thirty days of donations
     * gets slower every month the install runs.
     *
     * @param  array  $transactionIds
     */
    private function claimedTransactionMap(array $transactionIds): array
    {
        // No rows on screen means nothing to look up. Guarding here rather than
        // treating an empty list as "no filter", which would fetch the lot.
        if (empty($transactionIds)) {
            return [];
        }

        return DB::table('mining_manager_processed_transactions')
            ->whereIn('transaction_id', $transactionIds)
            ->pluck('tax_id', 'transaction_id')
            ->map(fn ($id) => $id === null ? null : (int) $id)
            ->toArray();
    }

    /**
     * Get payment statistics.
     */
    public function getPaymentStatistics(): array
    {
        $thisMonth = Carbon::now()->startOfMonth();

        return [
            'total_paid_this_month' => MiningTax::where('status', 'paid')
                ->where('paid_at', '>=', $thisMonth)
                ->sum('amount_paid'),
            'payments_this_month' => MiningTax::where('status', 'paid')
                ->where('paid_at', '>=', $thisMonth)
                ->count(),
            'partial_payments' => MiningTax::where('status', 'partial')->count(),
            'total_unpaid' => MiningTax::where('status', 'unpaid')->sum('amount_owed'),
            'average_payment_time' => $this->calculateAveragePaymentTime(),
        ];
    }

    /**
     * Average days between an invoice being calculated and it being settled.
     */
    private function calculateAveragePaymentTime(): float
    {
        $paidTaxes = MiningTax::where('status', 'paid')
            ->whereNotNull('calculated_at')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', Carbon::now()->subMonths(3))
            ->get();

        if ($paidTaxes->isEmpty()) {
            return 0;
        }

        $totalDays = 0;
        foreach ($paidTaxes as $tax) {
            $totalDays += $tax->calculated_at->diffInDays($tax->paid_at);
        }

        return $totalDays / $paidTaxes->count();
    }
}
