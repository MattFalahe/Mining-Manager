<?php

namespace MiningManager\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Seat\Eveapi\Events\CharacterWalletJournalUpdated;
use Seat\Eveapi\Models\Wallet\CharacterWalletJournal;
use MiningManager\Models\MiningTax;
use MiningManager\Models\ProcessedTransaction;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Configuration\SettingsManagerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWalletJournalListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $tries = 3;
    public $timeout = 120;
    public $maxExceptions = 2;

    protected SettingsManagerService $settingsService;

    public function __construct(SettingsManagerService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Handle the event.
     *
     * @param CharacterWalletJournalUpdated $event
     * @return void
     */
    public function handle(CharacterWalletJournalUpdated $event)
    {
        // Check if wallet verification feature is enabled
        if (!$this->settingsService->getSetting('features.verify_wallet_transactions', true)) {
            return;
        }

        $characterId = $event->character_id;

        Log::debug("Mining Manager: Processing wallet journal for character {$characterId}");

        try {
            // Get recent wallet transactions for this character
            // Look for potential tax payments in the last 30 days
            $transactions = CharacterWalletJournal::where('first_party_id', $characterId)
                ->where('date', '>=', Carbon::now()->subDays(30))
                ->where('ref_type', 'player_donation')
                ->whereNotIn('id', function ($query) {
                    $query->select('transaction_id')
                        ->from('mining_manager_processed_transactions');
                })
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
                $paymentSettings = $this->settingsService->getPaymentSettings();
                $tolerance = (float) ($paymentSettings['match_tolerance'] ?? config('mining-manager.wallet.match_tolerance', 1000));

                if (abs($amount - $tax->amount_owed) > $tolerance) {
                    Log::warning("Mining Manager: Amount mismatch for tax code '{$taxCode}': Expected " . number_format($tax->amount_owed, 2) . ", Got " . number_format($amount, 2) . " (tolerance: " . number_format($tolerance, 2) . ")");
                    continue;
                }

                // Auto-match if enabled (settings → General → Payment Settings).
                //
                // Canonical key is `payment.auto_match_payments` to match the
                // sibling payment.* setting keys (match_tolerance,
                // grace_period_hours, minimum_tax_amount). Default is true
                // — operator can turn it off for installs that want a
                // human-review step before any tax row is updated.
                if ($this->settingsService->getSetting('payment.auto_match_payments', true)) {
                    DB::transaction(function () use ($tax, $taxCodeRecord, $transaction, $amount, $characterId, $taxCode, &$matched) {
                        // ATOMIC CLAIM via the dedup table — same canonical
                        // pattern as WalletTransferService::applyPayment (H1
                        // fix, commit 27cf560). The transaction_id column on
                        // mining_manager_processed_transactions is unique;
                        // attempting a duplicate insert throws QueryException
                        // and we bail before touching the tax row.
                        //
                        // Why FIRST and not last (the previous order):
                        //   Two queue workers (Laravel queue retry, parallel
                        //   workers, deadlock-retry) processing the same
                        //   CharacterWalletJournalUpdated event both reach
                        //   this method with the same $transaction->id. With
                        //   the old "insert last" order, both workers locked
                        //   the tax row sequentially, both accumulated
                        //   amount_paid (double-credit), and only the second
                        //   worker's dedup-table insert failed. Insert FIRST
                        //   → only one worker wins the claim; the loser bails
                        //   before any tax mutation.
                        try {
                            ProcessedTransaction::create([
                                'transaction_id' => $transaction->id,
                                'character_id' => $characterId,
                                'tax_id' => $tax->id,
                                'matched_at' => now(),
                            ]);
                        } catch (QueryException $e) {
                            Log::info("Mining Manager: Transaction {$transaction->id} already processed; skipping double-credit attempt for character {$characterId}");
                            return;
                        }

                        // Re-fetch with lock to prevent race conditions
                        $tax = MiningTax::where('id', $tax->id)->lockForUpdate()->first();
                        if (!$tax) {
                            // Tax disappeared between the lookup above and
                            // here — let the dedup row stand so we don't loop,
                            // but log it as anomalous.
                            Log::warning("Mining Manager: Tax not found during auto-match for transaction {$transaction->id}; dedup row inserted, no payment applied");
                            return;
                        }

                        // Support partial payments — accumulate amount_paid
                        $newPaid = ($tax->amount_paid ?? 0) + $amount;

                        $tax->update([
                            'amount_paid' => $newPaid,
                            'paid_at' => $transaction->date,
                            'status' => ($tax->amount_owed - $newPaid) < 1 ? 'paid' : 'partial',
                            'transaction_id' => $transaction->id,
                        ]);

                        // Mark tax code as used only when fully paid
                        if (($tax->amount_owed - $newPaid) < 1) {
                            $taxCodeRecord->update([
                                'used_at' => Carbon::now(),
                                'transaction_id' => $transaction->id,
                                'status' => 'used',
                            ]);
                        }

                        Log::info("Mining Manager: Auto-matched payment for character {$characterId}: " . number_format($amount, 2) . " ISK (code: {$taxCode}), total paid: " . number_format($newPaid, 2));
                        $matched++;
                    });
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
     * Extract tax code from transaction description.
     * Delegates to TaxCode::extractCodeFromText() which handles mixed code lengths
     * (e.g., old 8-char codes still match after admin changes length to 12).
     *
     * @param string|null $description
     * @return string|null
     */
    private function extractTaxCode(?string $description): ?string
    {
        return TaxCode::extractCodeFromText($description);
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
