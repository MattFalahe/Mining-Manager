<?php

namespace MiningManager\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Seat\Eveapi\Events\CharacterWalletJournalUpdated;
use Seat\Eveapi\Models\Wallet\CharacterWalletJournal;
use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessWalletJournalListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param CharacterWalletJournalUpdated $event
     * @return void
     */
    public function handle(CharacterWalletJournalUpdated $event)
    {
        // Check if wallet verification feature is enabled
        if (!config('mining-manager.features.wallet_verification', true)) {
            return;
        }

        $characterId = $event->character_id;

        Log::debug("Mining Manager: Processing wallet journal for character {$characterId}");

        try {
            // Get recent wallet transactions for this character
            // Look for potential tax payments in the last 30 days
            $transactions = CharacterWalletJournal::where('first_party_id', $characterId)
                ->where('date', '>=', Carbon::now()->subDays(30))
                ->whereIn('ref_type', ['player_donation', 'corporation_account_withdrawal'])
                ->whereNull('processed_by_mining_manager')
                ->get();

            if ($transactions->isEmpty()) {
                Log::debug("Mining Manager: No relevant transactions found for character {$characterId}");
                return;
            }

            $matched = 0;

            foreach ($transactions as $transaction) {
                // Check if transaction has a tax code in description
                $taxCode = $this->extractTaxCode($transaction->description);

                if (!$taxCode) {
                    continue;
                }

                Log::debug("Mining Manager: Found tax code '{$taxCode}' in transaction {$transaction->id}");

                // Find tax code record
                $taxCodeRecord = TaxCode::where('code', $taxCode)
                    ->where('character_id', $characterId)
                    ->where('status', 'active')
                    ->first();

                if (!$taxCodeRecord) {
                    Log::warning("Mining Manager: Tax code '{$taxCode}' not found or not active for character {$characterId}");
                    continue;
                }

                // Find associated tax record
                $tax = MiningTax::find($taxCodeRecord->mining_tax_id);

                if (!$tax) {
                    Log::warning("Mining Manager: Tax record not found for code '{$taxCode}'");
                    continue;
                }

                // Verify amount matches (within tolerance)
                $amount = abs($transaction->amount);
                $tolerance = 0.01; // 1 cent tolerance for rounding

                if (abs($amount - $tax->amount_owed) > $tolerance) {
                    Log::warning("Mining Manager: Amount mismatch for tax code '{$taxCode}': Expected {$tax->amount_owed}, Got {$amount}");
                }

                // Auto-match if enabled in config
                if (config('mining-manager.tax.auto_match_payments', true)) {
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
                        'status' => 'used',
                    ]);

                    // Mark transaction as processed
                    $transaction->update([
                        'processed_by_mining_manager' => true,
                    ]);

                    Log::info("Mining Manager: Auto-matched payment for character {$characterId}: " . number_format($amount, 2) . " ISK (code: {$taxCode})");
                    $matched++;
                }
            }

            if ($matched > 0) {
                Log::info("Mining Manager: Processed {$matched} tax payments for character {$characterId}");
            }

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error processing wallet journal for character {$characterId}: " . $e->getMessage());
        }
    }

    /**
     * Extract tax code from transaction description
     *
     * @param string|null $description
     * @return string|null
     */
    private function extractTaxCode(?string $description): ?string
    {
        if (!$description) {
            return null;
        }

        $prefix = config('mining-manager.tax.tax_code_prefix', 'TAX-');

        // Look for tax code pattern (e.g., TAX-XXXXXX)
        $pattern = '/' . preg_quote($prefix, '/') . '([A-Z0-9]{6})/';
        
        if (preg_match($pattern, $description, $matches)) {
            return $matches[1]; // Return just the code part without prefix
        }

        return null;
    }

    /**
     * Handle a job failure.
     *
     * @param CharacterWalletJournalUpdated $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(CharacterWalletJournalUpdated $event, $exception)
    {
        Log::error("Mining Manager: Failed to process wallet journal for character {$event->character_id}: " . $exception->getMessage());
    }
}
