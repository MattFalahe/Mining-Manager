<?php

namespace MiningManager\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Seat\Eveapi\Events\CharacterContractsUpdated;
use Seat\Eveapi\Models\Contracts\CharacterContract;
use MiningManager\Models\TaxInvoice;
use MiningManager\Models\MiningTax;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessContractUpdatesListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param CharacterContractsUpdated $event
     * @return void
     */
    public function handle(CharacterContractsUpdated $event)
    {
        // Check if tax invoices feature is enabled
        if (!config('mining-manager.features.tax_invoices', true)) {
            return;
        }

        $characterId = $event->character_id;

        Log::debug("Mining Manager: Processing contracts for character {$characterId}");

        try {
            // Get contracts for this character
            $contracts = CharacterContract::where('acceptor_id', $characterId)
                ->orWhere('assignee_id', $characterId)
                ->where('updated_at', '>=', Carbon::now()->subDays(7))
                ->get();

            if ($contracts->isEmpty()) {
                Log::debug("Mining Manager: No contracts found for character {$characterId}");
                return;
            }

            $processed = 0;

            foreach ($contracts as $contract) {
                // Look for tax invoice contracts
                // These should be marked with a specific identifier in the title
                if (!$this->isTaxInvoiceContract($contract)) {
                    continue;
                }

                Log::debug("Mining Manager: Found potential tax invoice contract {$contract->contract_id} for character {$characterId}");

                // Find corresponding tax invoice
                $invoice = TaxInvoice::where('contract_id', $contract->contract_id)
                    ->first();

                if (!$invoice) {
                    // Try to match by character and amount
                    $invoice = $this->findMatchingInvoice($contract, $characterId);
                }

                if (!$invoice) {
                    Log::warning("Mining Manager: Could not find matching tax invoice for contract {$contract->contract_id}");
                    continue;
                }

                // Update invoice status based on contract status
                $newStatus = $this->mapContractStatusToInvoiceStatus($contract->status);

                if ($newStatus && $invoice->status !== $newStatus) {
                    $invoice->update([
                        'status' => $newStatus,
                        'contract_id' => $contract->contract_id,
                    ]);

                    Log::info("Mining Manager: Updated tax invoice {$invoice->id} status to '{$newStatus}' for contract {$contract->contract_id}");

                    // If contract is completed, mark tax as paid
                    if ($newStatus === 'accepted' && $invoice->miningTax) {
                        $invoice->miningTax->update([
                            'status' => 'paid',
                            'amount_paid' => $invoice->amount,
                            'paid_at' => Carbon::now(),
                        ]);

                        Log::info("Mining Manager: Marked tax {$invoice->mining_tax_id} as paid via contract {$contract->contract_id}");
                    }

                    $processed++;
                }
            }

            if ($processed > 0) {
                Log::info("Mining Manager: Processed {$processed} tax invoice contracts for character {$characterId}");
            }

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error processing contracts for character {$characterId}: " . $e->getMessage());
        }
    }

    /**
     * Check if contract is a tax invoice contract
     *
     * @param CharacterContract $contract
     * @return bool
     */
    private function isTaxInvoiceContract(CharacterContract $contract): bool
    {
        // Check if contract title contains tax invoice identifier
        if (!$contract->title) {
            return false;
        }

        $identifiers = [
            'Mining Tax',
            'Tax Invoice',
            'Mining Manager Tax',
        ];

        foreach ($identifiers as $identifier) {
            if (stripos($contract->title, $identifier) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find matching tax invoice for a contract
     *
     * @param CharacterContract $contract
     * @param int $characterId
     * @return TaxInvoice|null
     */
    private function findMatchingInvoice(CharacterContract $contract, int $characterId): ?TaxInvoice
    {
        // Try to find invoice by character and approximate amount
        $tolerance = 1000; // 1000 ISK tolerance

        $invoice = TaxInvoice::where('character_id', $characterId)
            ->whereIn('status', ['pending', 'sent'])
            ->whereNull('contract_id')
            ->whereBetween('amount', [
                $contract->price - $tolerance,
                $contract->price + $tolerance
            ])
            ->orderBy('generated_at', 'desc')
            ->first();

        return $invoice;
    }

    /**
     * Map contract status to invoice status
     *
     * @param string $contractStatus
     * @return string|null
     */
    private function mapContractStatusToInvoiceStatus(string $contractStatus): ?string
    {
        $statusMap = [
            'outstanding' => 'sent',
            'in_progress' => 'sent',
            'finished_issuer' => 'accepted',
            'finished_contractor' => 'accepted',
            'finished' => 'accepted',
            'cancelled' => 'rejected',
            'rejected' => 'rejected',
            'failed' => 'rejected',
            'deleted' => 'rejected',
            'expired' => 'expired',
        ];

        return $statusMap[$contractStatus] ?? null;
    }

    /**
     * Handle a job failure.
     *
     * @param CharacterContractsUpdated $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(CharacterContractsUpdated $event, $exception)
    {
        Log::error("Mining Manager: Failed to process contracts for character {$event->character_id}: " . $exception->getMessage());
    }
}
