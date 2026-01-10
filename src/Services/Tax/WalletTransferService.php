<?php

namespace MiningManager\Services\Tax;

use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxCode;
use Seat\Eveapi\Models\Wallet\CharacterWalletJournal;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class WalletTransferService
{
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
        $tolerance = 100; // 100 ISK tolerance

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
            'status' => $newPaid >= $tax->amount_owed ? 'paid' : 'partial',
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

        $prefix = config('mining-manager.wallet.tax_code_prefix', 'TAX-');
        $length = config('mining-manager.wallet.tax_code_length', 6);

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
