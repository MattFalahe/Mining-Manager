<?php

namespace MiningManager\Services\Tax;

use MiningManager\Models\MiningTax;
use MiningManager\Models\ProcessedTransaction;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Configuration\SettingsManagerService;
use Seat\Eveapi\Models\Wallet\CharacterWalletJournal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class WalletTransferService
{
    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settings;

    /**
     * Constructor
     *
     * @param SettingsManagerService $settings
     */
    public function __construct(SettingsManagerService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Set the corporation context for settings retrieval.
     *
     * @param int|null $corporationId
     * @return self
     */
    public function setCorporationContext(?int $corporationId): self
    {
        $this->settings->setActiveCorporation($corporationId);
        return $this;
    }

    /**
     * Get the wallet divisions to check for payments.
     * Always includes master wallet (1) as fallback, plus the configured division.
     *
     * @return array
     */
    private function getPaymentDivisions(): array
    {
        $paymentSettings = $this->settings->getPaymentSettings();
        $division = (int) ($paymentSettings['wallet_division'] ?? 1);

        // Always check master wallet (1) as secondary source
        $divisions = [1];
        if ($division !== 1) {
            // Configured division is primary, master wallet is fallback
            array_unshift($divisions, $division);
        }

        return array_unique($divisions);
    }

    /**
     * Verify wallet payments against outstanding taxes.
     *
     * @param int $days
     * @param bool $autoMatch
     * @return array
     */
    public function verifyPayments(int $days = 7, bool $autoMatch = false): array
    {
        Log::info("Mining Manager: Starting payment verification (last {$days} days)");

        $cutoffDate = Carbon::now()->subDays($days);

        // Get potential tax payment transactions, EXCLUDING those already
        // processed (claimed in mining_manager_processed_transactions). Without
        // this exclusion, every cron run (every 6h) re-processes the same
        // transactions, and any tax that's only partially paid (status=partial,
        // tax_codes.status=active) gets its amount_paid ratcheted up by the
        // same payment over and over until it appears fully paid — silent
        // double-credit on partial-payment scenarios.
        //
        // This mirrors the canonical filter pattern from
        // ProcessWalletJournalListener (the listener that fires when wallet
        // updates arrive). Defense-in-depth: applyPayment() also writes
        // atomically to the dedup table inside its DB::transaction so a
        // racy second worker can't credit the same transaction twice even
        // if the filter slipped (replication lag, mid-cron insert, etc.).
        $transactions = CharacterWalletJournal::where('date', '>=', $cutoffDate)
            ->where('ref_type', 'player_donation')
            ->whereNotIn('id', function ($query) {
                $query->select('transaction_id')
                    ->from('mining_manager_processed_transactions');
            })
            ->get();

        if ($transactions->isEmpty()) {
            Log::info("Mining Manager: No relevant transactions found");
            return [
                'matched' => 0,
                'unmatched' => 0,
                'errors' => [],
            ];
        }

        $matched = 0;
        $unmatched = 0;
        $errors = [];

        foreach ($transactions as $transaction) {
            try {
                $result = $this->processTransaction($transaction, $autoMatch);

                if ($result['matched']) {
                    $matched++;
                } else {
                    $unmatched++;
                }

            } catch (\Exception $e) {
                Log::error("Mining Manager: Error processing transaction {$transaction->id}: " . $e->getMessage());
                $errors[] = [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::info("Mining Manager: Payment verification complete. Matched: {$matched}, Unmatched: {$unmatched}");

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'errors' => $errors,
        ];
    }

    /**
     * Process a single transaction.
     *
     * @param CharacterWalletJournal $transaction
     * @param bool $autoMatch
     * @return array
     */
    private function processTransaction(CharacterWalletJournal $transaction, bool $autoMatch): array
    {
        // Extract tax code from description
        $taxCode = $this->extractTaxCode($transaction->description);

        if (!$taxCode) {
            return ['matched' => false];
        }

        // Find tax code record
        $taxCodeRecord = TaxCode::where('code', $taxCode)
            ->where('character_id', $transaction->first_party_id)
            ->where('status', 'active')
            ->first();

        if (!$taxCodeRecord) {
            Log::warning("Mining Manager: Tax code '{$taxCode}' not found or not active for character {$transaction->first_party_id}");
            return ['matched' => false];
        }

        // Find associated tax record
        $tax = MiningTax::find($taxCodeRecord->mining_tax_id);

        if (!$tax) {
            Log::warning("Mining Manager: Tax record not found for code '{$taxCode}'");
            return ['matched' => false];
        }

        // Verify amount
        $amount = abs($transaction->amount);
        $tolerance = $this->settings->getSetting('payment.match_tolerance', 100);

        if (abs($amount - $tax->amount_owed) > $tolerance) {
            Log::warning("Mining Manager: Amount mismatch for tax code '{$taxCode}': Expected {$tax->amount_owed}, Got {$amount}");
        }

        if ($autoMatch) {
            $this->applyPayment($tax, $taxCodeRecord, $transaction, $amount);
        }

        return ['matched' => true, 'tax_code' => $taxCode, 'amount' => $amount];
    }

    /**
     * Apply payment to tax record from a CharacterWalletJournal model.
     *
     * Thin wrapper around `applyPaymentToTax()`; preserves the original
     * signature for callers that already have a typed model instance
     * (processTransaction, manualMatch).
     *
     * @param MiningTax $tax
     * @param TaxCode $taxCode
     * @param CharacterWalletJournal $transaction
     * @param float $amount
     * @return void
     */
    private function applyPayment(MiningTax $tax, TaxCode $taxCode, CharacterWalletJournal $transaction, float $amount): void
    {
        $this->applyPaymentToTax(
            $tax,
            $taxCode,
            (int) $transaction->id,
            $transaction->date,
            (int) $tax->character_id,
            $amount
        );
    }

    /**
     * Canonical payment-application core. Single source of truth for the
     * "credit a payment to a tax" operation across all three entry paths:
     *   1. WalletTransferService::processTransaction → applyPayment (model)
     *   2. ProcessWalletJournalListener::handle (queued)
     *   3. WalletTransferService::autoVerifyFromCorporationWallet
     *
     * Pre-fix C1+C2 (audit follow-up): paths 2 and 3 each had their own
     * divergent write logic. Path 2 had the dedup-table insert AFTER the
     * tax update (race window). Path 3 didn't write to the dedup table at
     * all AND set status='paid' with replacement-not-accumulation
     * semantics, bulldozing partial payments. Now all three paths funnel
     * through this method so the atomic-claim + partial-payment semantics
     * are guaranteed identical.
     *
     * Idempotency:
     *   - Inserts the dedup row FIRST inside DB::transaction. The unique
     *     constraint on `mining_manager_processed_transactions.transaction_id`
     *     makes this the canonical "compare-and-swap on a unique row" — a
     *     QueryException on insert means another path already credited
     *     this transaction; we bail before touching the tax.
     *
     * Partial-payment support:
     *   - Accumulates `amount_paid` rather than replacing it.
     *   - Sets status='partial' until the running total covers
     *     `amount_owed`, then 'paid'.
     *   - Marks the TaxCode as 'used' only on full payment.
     *
     * @param MiningTax  $tax
     * @param TaxCode    $taxCode
     * @param int        $transactionId   wallet journal row id
     * @param mixed      $transactionDate Carbon|string — paid_at value
     * @param int        $characterId     paying character (for log context)
     * @param float      $amount          payment amount in ISK
     * @return bool   true if payment applied; false if dedup-claim collided
     *                or the tax disappeared mid-flight
     */
    private function applyPaymentToTax(
        MiningTax $tax,
        TaxCode $taxCode,
        int $transactionId,
        $transactionDate,
        int $characterId,
        float $amount
    ): bool {
        // Track whether the inner transaction actually applied the payment so
        // the post-transaction log line reflects reality. Closures need a
        // reference variable to write back to the outer scope.
        $applied = false;

        DB::transaction(function () use ($tax, $taxCode, $transactionId, $transactionDate, $characterId, $amount, &$applied) {
            // ATOMIC CLAIM via the dedup table: try to insert
            // mining_manager_processed_transactions FIRST. The transaction_id
            // column has a unique constraint — if another worker already
            // processed this transaction, our insert throws a QueryException,
            // we catch it, and bail before touching the tax. This is the
            // canonical "compare-and-swap on a unique row" pattern; it's the
            // only way to make payment application safe under concurrency
            // without distributed locking.
            //
            // Why insert FIRST rather than after the tax update? If the tax
            // update were to land before the dedup row, two parallel workers
            // could both update amount_paid (each adding the same $amount)
            // before either tried the unique insert — the second worker's
            // unique violation would correctly roll back its own insert, but
            // the inner DB::transaction rollback would also revert the tax
            // update, leaving us with a tax that was net-zero changed despite
            // two attempts (and one legit). By claiming the dedup row first,
            // the second worker bails BEFORE touching the tax row at all.
            try {
                ProcessedTransaction::create([
                    'transaction_id' => $transactionId,
                    'character_id' => $characterId,
                    'tax_id' => $tax->id,
                    'matched_at' => Carbon::now(),
                ]);
            } catch (QueryException $e) {
                // Unique violation = transaction already processed by another
                // path. Not an error — bail silently. The tax row is untouched.
                Log::info("Mining Manager: Transaction {$transactionId} already processed; skipping double-credit attempt for character {$characterId}");
                return;
            }

            // Re-fetch with lock to prevent race conditions on concurrent payments
            $taxLocked = MiningTax::where('id', $tax->id)->lockForUpdate()->first();
            if (!$taxLocked) {
                // Tax disappeared mid-flight — let the dedup row stand so we
                // don't loop on a missing tax, but log it as anomalous.
                Log::warning("Mining Manager: Tax {$tax->id} not found during payment application for transaction {$transactionId}; dedup row inserted, no payment applied");
                return;
            }

            // Support partial payments — accumulate amount_paid.
            $newPaid = ($taxLocked->amount_paid ?? 0) + $amount;

            $taxLocked->update([
                'amount_paid' => $newPaid,
                'paid_at' => $transactionDate,
                'status' => ($taxLocked->amount_owed - $newPaid) < 1 ? 'paid' : 'partial',
                'transaction_id' => $transactionId,
            ]);

            // Mark tax code as used only when fully paid.
            if (($taxLocked->amount_owed - $newPaid) < 1) {
                $taxCode->update([
                    'used_at' => Carbon::now(),
                    'transaction_id' => $transactionId,
                    'status' => 'used',
                ]);
            }

            $applied = true;
        });

        if ($applied) {
            Log::info("Mining Manager: Applied payment for character {$characterId}: " . number_format($amount, 2) . " ISK (code: {$taxCode->code})");
        }

        return $applied;
    }

    /**
     * Extract tax code from transaction description.
     * Delegates to TaxCode::extractCodeFromText() which handles mixed code lengths.
     *
     * @param string|null $description
     * @return string|null
     */
    private function extractTaxCode(?string $description): ?string
    {
        return TaxCode::extractCodeFromText($description);
    }

    /**
     * Match a single transaction to its tax record and apply payment.
     * Called from the wallet verification page "Verify" button.
     *
     * @param int $transactionId
     * @return bool True if the transaction was matched and applied
     */
    public function matchTransactionToTax(int $transactionId): bool
    {
        $transaction = CharacterWalletJournal::findOrFail($transactionId);

        $result = $this->processTransaction($transaction, true);

        return $result['matched'] ?? false;
    }

    /**
     * Get pending payments (potential matches).
     *
     * @param int $days
     * @return \Illuminate\Support\Collection
     */
    public function getPendingPayments(int $days = 7)
    {
        $cutoffDate = Carbon::now()->subDays($days);

        return CharacterWalletJournal::where('date', '>=', $cutoffDate)
            ->where('ref_type', 'player_donation')
            ->whereNotIn('id', function ($query) {
                $query->select('transaction_id')
                    ->from('mining_manager_processed_transactions');
            })
            ->with('character')
            ->get()
            ->filter(function ($transaction) {
                return $this->extractTaxCode($transaction->description) !== null;
            });
    }

    /**
     * Manually match a transaction to a tax.
     *
     * @param int $transactionId
     * @param int $taxId
     * @return bool
     */
    public function manualMatch(int $transactionId, int $taxId): bool
    {
        try {
            $transaction = CharacterWalletJournal::findOrFail($transactionId);
            $tax = MiningTax::findOrFail($taxId);

            if ($tax->character_id !== $transaction->first_party_id) {
                throw new \Exception("Character mismatch");
            }

            $amount = abs($transaction->amount);

            // Create tax code record if it doesn't exist
            $taxCode = TaxCode::firstOrCreate(
                [
                    'mining_tax_id' => $tax->id,
                    'character_id' => $tax->character_id,
                    'transaction_id' => $transaction->id,
                ],
                [
                    'code' => 'MANUAL',
                    'status' => 'used',
                    'generated_at' => Carbon::now(),
                    'used_at' => Carbon::now(),
                ]
            );

            $this->applyPayment($tax, $taxCode, $transaction, $amount);

            return true;

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error in manual match: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Reverse a payment.
     *
     * @param int $taxId
     * @param string $reason
     * @return bool
     */
    public function reversePayment(int $taxId, string $reason): bool
    {
        try {
            return DB::transaction(function () use ($taxId, $reason) {
                $tax = MiningTax::findOrFail($taxId);

                if ($tax->status !== 'paid' && $tax->status !== 'partial') {
                    throw new \Exception("Tax is not marked as paid");
                }

                $tax->update([
                    'amount_paid' => 0,
                    'paid_at' => null,
                    'status' => 'unpaid',
                    'transaction_id' => null,
                    'notes' => ($tax->notes ? $tax->notes . "\n" : '') .
                              Carbon::now()->toDateTimeString() . " - Payment reversed. Reason: {$reason}",
                ]);

                // Mark associated tax codes as cancelled
                TaxCode::where('mining_tax_id', $tax->id)
                    ->where('status', 'used')
                    ->update(['status' => 'cancelled']);

                Log::info("Mining Manager: Reversed payment for tax {$taxId}");

                return true;
            });

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error reversing payment: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify tax payment from corporation wallet journal
     *
     * Queries corporation_wallet_journals for player_donation type transactions
     * that match tax codes in reason or description fields
     *
     * @param MiningTax $taxRecord The tax record to verify
     * @param int|null $corporationId Optional corporation ID filter
     * @return array|null Transaction data if found, null otherwise
     */
    public function verifyPaymentFromJournal(MiningTax $taxRecord, ?int $corporationId = null): ?array
    {
        try {
            // Get the tax code for this tax record
            $taxCode = TaxCode::where('mining_tax_id', $taxRecord->id)
                ->where('status', '!=', 'cancelled')
                ->first();

            if (!$taxCode) {
                return null;
            }

            // Build query for corporation wallet journals
            // Filter by configured wallet division(s) — primary + master wallet fallback
            $divisions = $this->getPaymentDivisions();

            $query = DB::table('corporation_wallet_journals')
                ->where('ref_type', 'player_donation')
                ->where('first_party_id', $taxRecord->character_id)
                ->whereIn('division', $divisions)
                ->where(function($q) use ($taxCode) {
                    $q->where('reason', 'LIKE', "%{$taxCode->code}%")
                      ->orWhere('description', 'LIKE', "%{$taxCode->code}%");
                });

            // Filter by corporation if specified
            if ($corporationId !== null) {
                $query->where('corporation_id', $corporationId);
            }

            // Exclude transactions already applied. Use the canonical dedup
            // table (mining_manager_processed_transactions) rather than
            // mining_taxes.transaction_id — the latter is mutated per
            // payment, so a partial-payment tax that gets a follow-up
            // payment with the same wallet code would have its OLDER
            // transaction_id overwritten and the older transaction would
            // re-suggest itself on the next auto-verify run. The dedup
            // table is append-only and is the single source of truth for
            // "has this transaction been credited to anything yet?".
            $query->whereNotIn('id', function ($sub) {
                $sub->select('transaction_id')
                    ->from('mining_manager_processed_transactions');
            });

            // Get the most recent matching transaction (tax code match is primary identifier)
            $transaction = $query->orderBy('date', 'desc')->first();

            if (!$transaction) {
                return null;
            }

            // Check amount tolerance — warn but don't block if code matches
            $tolerance = $this->settings->getSetting('payment.match_tolerance', 100);
            $amountDiff = abs(abs($transaction->amount) - $taxRecord->amount_owed);
            $amountMismatch = $amountDiff > $tolerance;

            if ($amountMismatch) {
                Log::warning("WalletTransferService: Amount mismatch for tax {$taxRecord->id} — expected " .
                    number_format($taxRecord->amount_owed, 2) . ", got " .
                    number_format(abs($transaction->amount), 2) . " (diff: " .
                    number_format($amountDiff, 2) . " ISK)");
            }

            return [
                'id' => $transaction->id,
                'corporation_id' => $transaction->corporation_id,
                'date' => $transaction->date,
                'amount' => abs($transaction->amount),
                'first_party_id' => $transaction->first_party_id,
                'second_party_id' => $transaction->second_party_id,
                'reason' => $transaction->reason,
                'description' => $transaction->description,
                'ref_type' => $transaction->ref_type,
                'tax_code' => $taxCode->code,
                'amount_mismatch' => $amountMismatch,
            ];

        } catch (\Exception $e) {
            Log::error("WalletTransferService: Failed to verify payment from journal for tax {$taxRecord->id}", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get all player donation transactions for a corporation
     *
     * @param int $corporationId Corporation ID to filter by
     * @param int $days Number of days to look back
     * @return \Illuminate\Support\Collection
     */
    public function getCorporationDonations(int $corporationId, int $days = 30)
    {
        $cutoffDate = Carbon::now()->subDays($days);

        $divisions = $this->getPaymentDivisions();

        return DB::table('corporation_wallet_journals as cwj')
            ->leftJoin('character_infos as ci', 'cwj.first_party_id', '=', 'ci.character_id')
            ->where('cwj.corporation_id', $corporationId)
            ->where('cwj.ref_type', 'player_donation')
            ->whereIn('cwj.division', $divisions)
            ->where('cwj.date', '>=', $cutoffDate)
            ->select(
                'cwj.*',
                'ci.name as character_name',
                DB::raw('false as verified'),
                DB::raw('false as mismatch'),
                DB::raw('null as matched_tax_id')
            )
            ->orderBy('cwj.date', 'desc')
            ->get();
    }

    /**
     * Auto-verify all unpaid taxes by checking corporation wallet journals
     *
     * @param int|null $corporationId Optional corporation ID filter
     * @param int $days Number of days to look back in wallet history
     * @return array Statistics about verification
     */
    public function autoVerifyFromCorporationWallet(?int $corporationId = null, int $days = 30): array
    {
        $verified = 0;
        $failed = 0;
        $errors = [];

        // Get all unpaid/overdue/partial taxes.
        $unpaidTaxes = MiningTax::whereIn('status', ['unpaid', 'overdue', 'partial'])
            ->get();

        foreach ($unpaidTaxes as $tax) {
            try {
                $transaction = $this->verifyPaymentFromJournal($tax, $corporationId);

                if ($transaction) {
                    // Resolve the TaxCode that maps this tax → wallet code.
                    // verifyPaymentFromJournal already proved one exists (the
                    // journal-row lookup uses it). Re-fetch as a model so we
                    // can pass it to applyPaymentToTax.
                    $taxCode = TaxCode::where('mining_tax_id', $tax->id)
                        ->where('status', '!=', 'cancelled')
                        ->first();

                    if (!$taxCode) {
                        // Defensive — shouldn't happen since verifyPaymentFromJournal
                        // returned non-null, but log and skip rather than crash.
                        Log::warning("WalletTransferService: TaxCode disappeared between verifyPaymentFromJournal and apply for tax {$tax->id}");
                        continue;
                    }

                    // Route through the canonical applyPaymentToTax helper —
                    // same atomic-claim, partial-payment-accumulating
                    // semantics as the listener and the manual paths.
                    //
                    // Pre-fix this method had its own divergent write logic:
                    // (a) replaced amount_paid instead of accumulating
                    //     (bulldozed partial payments to "fully paid" with
                    //     the wrong amount), and (b) skipped the dedup-table
                    //     insert entirely (allowing the same transaction to
                    //     re-credit a tax on every cron run).
                    $applied = $this->applyPaymentToTax(
                        $tax,
                        $taxCode,
                        (int) $transaction['id'],
                        Carbon::parse($transaction['date']),
                        (int) $tax->character_id,
                        (float) $transaction['amount']
                    );

                    if ($applied) {
                        $verified++;

                        Log::info("WalletTransferService: Auto-verified tax payment", [
                            'tax_id' => $tax->id,
                            'character_id' => $tax->character_id,
                            'amount' => $transaction['amount'],
                            'transaction_id' => $transaction['id'],
                        ]);
                    }
                    // applied=false means dedup-claim collided (transaction
                    // already credited by another path) or the tax row
                    // disappeared mid-flight; both are non-error skips that
                    // applyPaymentToTax already logged.
                }

            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'tax_id' => $tax->id,
                    'error' => $e->getMessage(),
                ];

                Log::error("WalletTransferService: Failed to auto-verify tax {$tax->id}", [
                    'error' => $e->getMessage()
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
     * Get unmatched corporation donations (donations without matching tax codes)
     *
     * @param int $corporationId Corporation ID
     * @param int $days Number of days to look back
     * @return \Illuminate\Support\Collection
     */
    public function getUnmatchedDonations(int $corporationId, int $days = 30)
    {
        $cutoffDate = Carbon::now()->subDays($days);

        // Get all donations with character names (filtered to payment divisions)
        $divisions = $this->getPaymentDivisions();

        $donations = DB::table('corporation_wallet_journals as cwj')
            ->leftJoin('character_infos as ci', 'cwj.first_party_id', '=', 'ci.character_id')
            ->where('cwj.corporation_id', $corporationId)
            ->where('cwj.ref_type', 'player_donation')
            ->whereIn('cwj.division', $divisions)
            ->where('cwj.date', '>=', $cutoffDate)
            ->select('cwj.*', 'ci.name as character_name')
            ->get();

        // Get dismissed transaction IDs
        $dismissedIds = DB::table('mining_manager_dismissed_transactions')
            ->pluck('transaction_id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        // Filter out donations that have matching tax codes or are dismissed
        $unmatched = [];

        foreach ($donations as $donation) {
            $foundMatch = false;
            $matchedTaxId = null;

            // Try to extract any tax code pattern from reason or description
            $text = ($donation->reason ?? '') . ' ' . ($donation->description ?? '');
            $length = TaxCode::getCodeLength();

            // Collect all known prefixes: current setting + any stored in DB
            $prefixes = collect([TaxCode::getPrefix()]);
            $storedPrefixes = TaxCode::select('prefix')->distinct()->whereNotNull('prefix')->pluck('prefix');
            $prefixes = $prefixes->merge($storedPrefixes)->unique();

            foreach ($prefixes as $tryPrefix) {
                $escapedPrefix = preg_quote($tryPrefix, '/');
                if (preg_match('/' . $escapedPrefix . '([A-Z0-9]{' . $length . '})/i', $text, $matches)) {
                    $code = strtoupper($matches[1]);

                    // Check if this code exists in our database
                    $taxCode = TaxCode::where('code', $code)->first();

                    if ($taxCode) {
                        $foundMatch = true;
                        $matchedTaxId = $taxCode->mining_tax_id;
                        break;
                    }
                }
            }

            if (!$foundMatch) {
                // Skip dismissed transactions
                if (in_array((int) $donation->id, $dismissedIds)) {
                    continue;
                }

                // Add computed fields expected by the view
                $donation->verified = false;
                $donation->mismatch = false;
                $donation->matched_tax_id = $matchedTaxId;
                $unmatched[] = $donation;
            }
        }

        return collect($unmatched);
    }

    /**
     * Get payment statistics.
     *
     * @return array
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
     * Calculate average time between tax calculation and payment.
     *
     * @return float Days
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
