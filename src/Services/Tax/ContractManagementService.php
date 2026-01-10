<?php

namespace MiningManager\Services\Tax;

use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxInvoice;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ContractManagementService
{
    /**
     * Tax code generator service
     *
     * @var TaxCodeGeneratorService
     */
    protected $taxCodeService;

    /**
     * Constructor
     *
     * @param TaxCodeGeneratorService $taxCodeService
     */
    public function __construct(TaxCodeGeneratorService $taxCodeService)
    {
        $this->taxCodeService = $taxCodeService;
    }

    /**
     * Generate invoices for unpaid taxes.
     *
     * @param Carbon|null $month
     * @return array
     */
    public function generateInvoices(?Carbon $month = null): array
    {
        Log::info("Mining Manager: Starting invoice generation");

        // Build query for unpaid taxes
        $query = MiningTax::where('status', 'unpaid')
            ->where('amount_owed', '>', 0);

        if ($month) {
            $query->where('month', $month->format('Y-m-01'));
            Log::info("Mining Manager: Generating invoices for month: {$month->format('Y-m')}");
        }

        $unpaidTaxes = $query->get();

        if ($unpaidTaxes->isEmpty()) {
            Log::info("Mining Manager: No unpaid taxes found to generate invoices for");
            return [
                'count' => 0,
                'total' => 0,
                'errors' => [],
            ];
        }

        $generated = 0;
        $totalAmount = 0;
        $errors = [];

        foreach ($unpaidTaxes as $tax) {
            try {
                // Check if invoice already exists
                $existingInvoice = TaxInvoice::where('mining_tax_id', $tax->id)
                    ->whereIn('status', ['pending', 'sent'])
                    ->first();

                if ($existingInvoice) {
                    Log::debug("Mining Manager: Invoice already exists for tax {$tax->id}");
                    continue;
                }

                // Create invoice
                $invoice = $this->createInvoice($tax);

                // Generate tax code if enabled
                if (config('mining-manager.wallet.enable_tax_codes', true)) {
                    $this->taxCodeService->generateTaxCode($tax, $invoice);
                }

                $generated++;
                $totalAmount += $tax->amount_owed;

                Log::info("Mining Manager: Generated invoice for character {$tax->character_id}: " . number_format($tax->amount_owed, 2) . " ISK");

            } catch (\Exception $e) {
                Log::error("Mining Manager: Error generating invoice for tax {$tax->id}: " . $e->getMessage());
                $errors[] = [
                    'tax_id' => $tax->id,
                    'character_id' => $tax->character_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::info("Mining Manager: Invoice generation complete. Generated: {$generated}, Total: " . number_format($totalAmount, 2) . " ISK");

        return [
            'count' => $generated,
            'total' => $totalAmount,
            'errors' => $errors,
        ];
    }

    /**
     * Create an invoice for a tax record.
     *
     * @param MiningTax $tax
     * @return TaxInvoice
     */
    private function createInvoice(MiningTax $tax): TaxInvoice
    {
        $expirationDays = config('mining-manager.tax_payment.grace_period_days', 7) + 14; // Grace period + 2 weeks

        return TaxInvoice::create([
            'mining_tax_id' => $tax->id,
            'character_id' => $tax->character_id,
            'amount' => $tax->amount_owed,
            'status' => 'pending',
            'generated_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays($expirationDays),
        ]);
    }

    /**
     * Create an in-game contract for an invoice.
     *
     * @param TaxInvoice $invoice
     * @return bool
     */
    public function createIngameContract(TaxInvoice $invoice): bool
    {
        // This would integrate with ESI to create an actual in-game contract
        // For now, this is a placeholder that marks the invoice as sent

        try {
            // In a real implementation, you would:
            // 1. Get corporation token
            // 2. Call ESI contract creation endpoint
            // 3. Store the contract ID

            // Placeholder contract creation
            $invoice->update([
                'status' => 'sent',
                'sent_at' => Carbon::now(),
                // 'contract_id' => $contractId, // from ESI response
            ]);

            Log::info("Mining Manager: Created in-game contract for invoice {$invoice->id}");

            return true;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error creating in-game contract for invoice {$invoice->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update invoice status based on contract status.
     *
     * @param TaxInvoice $invoice
     * @param string $contractStatus
     * @return bool
     */
    public function updateInvoiceFromContract(TaxInvoice $invoice, string $contractStatus): bool
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

        $newStatus = $statusMap[$contractStatus] ?? null;

        if (!$newStatus || $invoice->status === $newStatus) {
            return false;
        }

        $invoice->update(['status' => $newStatus]);

        // If contract accepted, mark tax as paid
        if ($newStatus === 'accepted' && $invoice->miningTax) {
            $invoice->miningTax->update([
                'status' => 'paid',
                'amount_paid' => $invoice->amount,
                'paid_at' => Carbon::now(),
            ]);

            Log::info("Mining Manager: Marked tax {$invoice->mining_tax_id} as paid via contract");
        }

        return true;
    }

    /**
     * Cancel an invoice.
     *
     * @param int $invoiceId
     * @param string $reason
     * @return bool
     */
    public function cancelInvoice(int $invoiceId, string $reason): bool
    {
        try {
            $invoice = TaxInvoice::findOrFail($invoiceId);

            $invoice->update([
                'status' => 'rejected',
                'notes' => ($invoice->notes ? $invoice->notes . "\n" : '') . 
                          Carbon::now()->toDateTimeString() . " - Invoice cancelled. Reason: {$reason}",
            ]);

            Log::info("Mining Manager: Cancelled invoice {$invoiceId}");

            return true;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error cancelling invoice: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Resend an invoice.
     *
     * @param int $invoiceId
     * @return bool
     */
    public function resendInvoice(int $invoiceId): bool
    {
        try {
            $invoice = TaxInvoice::findOrFail($invoiceId);

            if (!in_array($invoice->status, ['pending', 'rejected', 'expired'])) {
                throw new \Exception("Cannot resend invoice in status: {$invoice->status}");
            }

            // Extend expiration date
            $expirationDays = config('mining-manager.tax_payment.grace_period_days', 7) + 14;
            $invoice->update([
                'status' => 'pending',
                'expires_at' => Carbon::now()->addDays($expirationDays),
                'notes' => ($invoice->notes ? $invoice->notes . "\n" : '') . 
                          Carbon::now()->toDateTimeString() . " - Invoice resent",
            ]);

            // Recreate in-game contract if applicable
            if (config('mining-manager.features.tax_invoices', true)) {
                $this->createIngameContract($invoice);
            }

            Log::info("Mining Manager: Resent invoice {$invoiceId}");

            return true;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error resending invoice: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Expire old pending invoices.
     *
     * @return int
     */
    public function expireOldInvoices(): int
    {
        $expired = TaxInvoice::whereIn('status', ['pending', 'sent'])
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            Log::info("Mining Manager: Expired {$expired} old invoices");
        }

        return $expired;
    }

    /**
     * Get invoice statistics.
     *
     * @return array
     */
    public function getInvoiceStatistics(): array
    {
        return [
            'pending' => TaxInvoice::where('status', 'pending')->count(),
            'sent' => TaxInvoice::where('status', 'sent')->count(),
            'accepted' => TaxInvoice::where('status', 'accepted')->count(),
            'rejected' => TaxInvoice::where('status', 'rejected')->count(),
            'expired' => TaxInvoice::where('status', 'expired')->count(),
            'total_amount_pending' => TaxInvoice::whereIn('status', ['pending', 'sent'])->sum('amount'),
            'total_amount_accepted' => TaxInvoice::where('status', 'accepted')->sum('amount'),
        ];
    }

    /**
     * Bulk generate invoices for multiple taxes.
     *
     * @param array $taxIds
     * @return array
     */
    public function bulkGenerateInvoices(array $taxIds): array
    {
        $generated = 0;
        $errors = [];

        foreach ($taxIds as $taxId) {
            try {
                $tax = MiningTax::findOrFail($taxId);

                if ($tax->status !== 'unpaid' || $tax->amount_owed <= 0) {
                    continue;
                }

                $invoice = $this->createInvoice($tax);

                if (config('mining-manager.wallet.enable_tax_codes', true)) {
                    $this->taxCodeService->generateTaxCode($tax, $invoice);
                }

                $generated++;

            } catch (\Exception $e) {
                $errors[] = [
                    'tax_id' => $taxId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'generated' => $generated,
            'errors' => $errors,
        ];
    }
}
