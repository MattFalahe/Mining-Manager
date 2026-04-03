<?php

namespace MiningManager\Services\Tax;

use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Configuration\SettingsManagerService;
use Seat\Eveapi\Models\Wallet\CharacterWalletJournal;
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

        // Get potential tax payment transactions
        $transactions = CharacterWalletJournal::where('date', '>=', $cutoffDate)
            ->whereIn('ref_type', ['player_donation', 'corporation_account_withdrawal', 'contract_reward'])
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
     * Apply payment to tax record.
     *
     * @param MiningTax $tax
     * @param TaxCode $taxCode
     * @param CharacterWalletJournal $transaction
     * @param float $amount
     * @return void
     */
    private function applyPayment(MiningTax $tax, TaxCode $taxCode, CharacterWalletJournal $transaction, float $amount): void
    {
        // Update tax record
        $previousPaid = $tax->amount_paid;
        $newPaid = $previousPaid + $amount;

        $tax->update([
            'amount_paid' => $newPaid,
            'paid_at' => $transaction->date,
            'status' => ($tax->amount_owed - $newPaid) < 1 ? 'paid' : 'partial',
            'transaction_id' => $transaction->id,
        ]);

        // Mark tax code as used
        $taxCode->update([
            'used_at' => Carbon::now(),
            'transaction_id' => $transaction->id,
            'status' => 'used',
        ]);

        Log::info("Mining Manager: Applied payment for character {$tax->character_id}: " . number_format($amount, 2) . " ISK (code: {$taxCode->code})");
    }

    /**
     * Extract tax code from transaction description.
     *
     * @param string|null $description
     * @return string|null
     */
    private function extractTaxCode(?string $description): ?string
    {
        if (!$description) {
            return null;
        }

        $prefix = $this->settings->getSetting('tax_rates.tax_code_prefix', 'TAX-');
        $length = $this->settings->getSetting('tax_rates.tax_code_length', 8);

        // Look for pattern: PREFIX-XXXXXX
        $pattern = '/' . preg_quote($prefix, '/') . '([A-Z0-9]{' . $length . '})/i';

        if (preg_match($pattern, $description, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
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
            ->whereIn('ref_type', ['player_donation', 'corporation_account_withdrawal'])
            ->whereNull('processed_by_mining_manager')
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

        // Get all unpaid taxes
        $unpaidTaxes = MiningTax::whereIn('status', ['unpaid', 'overdue'])
            ->get();

        foreach ($unpaidTaxes as $tax) {
            try {
                $transaction = $this->verifyPaymentFromJournal($tax, $corporationId);

                if ($transaction) {
                    // Mark tax as paid
                    $tax->update([
                        'status' => 'paid',
                        'amount_paid' => $transaction['amount'],
                        'paid_at' => Carbon::parse($transaction['date']),
                        'transaction_id' => $transaction['id'],
                    ]);

                    // Mark tax code as used
                    TaxCode::where('mining_tax_id', $tax->id)
                        ->update([
                            'status' => 'used',
                            'used_at' => Carbon::parse($transaction['date']),
                            'transaction_id' => $transaction['id'],
                        ]);

                    $verified++;

                    Log::info("WalletTransferService: Auto-verified tax payment", [
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

        // Filter out donations that have matching tax codes
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
