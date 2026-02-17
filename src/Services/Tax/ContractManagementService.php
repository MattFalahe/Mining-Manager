<?php

namespace MiningManager\Services\Tax;

use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxInvoice;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Facades\DB;
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
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settings;

    /**
     * Constructor
     *
     * @param TaxCodeGeneratorService $taxCodeService
     * @param SettingsManagerService $settings
     */
    public function __construct(TaxCodeGeneratorService $taxCodeService, SettingsManagerService $settings)
    {
        $this->taxCodeService = $taxCodeService;
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
                if ($this->settings->getSetting('tax_rates.auto_generate_tax_codes', true)) {
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
        $gracePeriodDays = $this->settings->getSetting('exemptions.grace_period_days', 7);
        $invoiceBuffer = $this->settings->getSetting('contract.invoice_expiration_buffer', 14);
        $expirationDays = $gracePeriodDays + $invoiceBuffer;

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
            $gracePeriodDays = $this->settings->getSetting('exemptions.grace_period_days', 7);
            $invoiceBuffer = $this->settings->getSetting('contract.invoice_expiration_buffer', 14);
            $expirationDays = $gracePeriodDays + $invoiceBuffer;
            $invoice->update([
                'status' => 'pending',
                'expires_at' => Carbon::now()->addDays($expirationDays),
                'notes' => ($invoice->notes ? $invoice->notes . "\n" : '') . 
                          Carbon::now()->toDateTimeString() . " - Invoice resent",
            ]);

            // Recreate in-game contract if applicable
            $featureFlags = $this->settings->getFeatureFlags();
            if ($featureFlags['tax_invoices'] ?? true) {
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

                if ($this->settings->getSetting('tax_rates.auto_generate_tax_codes', true)) {
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

    /**
     * Scan corporation contracts for tax code matches.
     *
     * Matches manually-created contracts (by holding corp) against active tax codes.
     * When a match is found and contract is completed, marks the tax as paid.
     *
     * Flow: Holding corp creates contract with tax code in title -> Player accepts ->
     * SeAT pulls corp contracts from ESI -> This method matches and updates status.
     *
     * @param int|null $corporationId Corporation ID to scan contracts for
     * @return array ['scanned' => int, 'matched' => int, 'paid' => int, 'errors' => []]
     */
    public function scanCorporationContracts(?int $corporationId = null): array
    {
        $corpId = $corporationId ?? $this->settings->getSetting('general.moon_owner_corporation_id');

        if (!$corpId) {
            Log::warning('Mining Manager: Cannot scan contracts - no corporation ID configured');
            return ['scanned' => 0, 'matched' => 0, 'paid' => 0, 'errors' => []];
        }

        $taxCodePrefix = $this->settings->getSetting('tax_rates.tax_code_prefix', 'TAX-');

        Log::info("Mining Manager: Scanning corporation contracts for corp {$corpId} with prefix '{$taxCodePrefix}'");

        // Try multiple possible SeAT contract table names
        $contracts = collect();
        $tableName = null;

        // SeAT v5.x typically uses 'corporation_contracts'
        $possibleTables = ['corporation_contracts', 'contract_details'];

        foreach ($possibleTables as $table) {
            try {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $tableName = $table;
                    $contracts = DB::table($table)
                        ->where(function ($query) use ($corpId) {
                            $query->where('issuer_corporation_id', $corpId)
                                  ->orWhere('corporation_id', $corpId);
                        })
                        ->where('updated_at', '>=', Carbon::now()->subDays(14))
                        ->get();

                    Log::info("Mining Manager: Found {$contracts->count()} contracts in '{$table}' table");
                    break;
                }
            } catch (\Exception $e) {
                Log::debug("Mining Manager: Table '{$table}' not available: " . $e->getMessage());
            }
        }

        // Also try character_contracts as fallback (issued by corp characters)
        if ($contracts->isEmpty()) {
            try {
                $contracts = DB::table('character_contracts')
                    ->where('issuer_corporation_id', $corpId)
                    ->where('updated_at', '>=', Carbon::now()->subDays(14))
                    ->get();

                if ($contracts->isNotEmpty()) {
                    $tableName = 'character_contracts';
                    Log::info("Mining Manager: Found {$contracts->count()} contracts in 'character_contracts' table");
                }
            } catch (\Exception $e) {
                Log::debug("Mining Manager: character_contracts fallback failed: " . $e->getMessage());
            }
        }

        if ($contracts->isEmpty()) {
            Log::info('Mining Manager: No corporation contracts found to scan');
            return ['scanned' => 0, 'matched' => 0, 'paid' => 0, 'errors' => []];
        }

        $scanned = $contracts->count();
        $matched = 0;
        $paid = 0;
        $errors = [];

        foreach ($contracts as $contract) {
            try {
                // Extract tax code from contract title
                $title = $contract->title ?? '';
                $code = $this->extractTaxCodeFromText($title, $taxCodePrefix);

                // Also check description if title didn't match
                if (!$code && !empty($contract->description)) {
                    $code = $this->extractTaxCodeFromText($contract->description, $taxCodePrefix);
                }

                if (!$code) {
                    continue;
                }

                Log::debug("Mining Manager: Found tax code '{$code}' in contract {$contract->contract_id}");

                // Find matching TaxCode record (full code including prefix)
                $fullCode = $taxCodePrefix . $code;
                $taxCodeRecord = TaxCode::where('code', $fullCode)->first();

                // Also try without prefix in case the whole thing was in the title
                if (!$taxCodeRecord) {
                    $taxCodeRecord = TaxCode::where('code', $code)->first();
                }

                // Try matching the raw title against any active tax code
                if (!$taxCodeRecord) {
                    $taxCodeRecord = TaxCode::where('code', 'LIKE', "%{$code}%")
                        ->whereIn('status', ['active', 'used'])
                        ->first();
                }

                if (!$taxCodeRecord) {
                    Log::debug("Mining Manager: No tax code record found for code '{$code}'");
                    continue;
                }

                // Find the invoice for this tax
                $invoice = TaxInvoice::where('mining_tax_id', $taxCodeRecord->mining_tax_id)
                    ->whereIn('status', ['pending', 'sent'])
                    ->first();

                // If no pending invoice, check if we need to create a link
                if (!$invoice) {
                    // Check if invoice already processed for this contract
                    $existingLink = TaxInvoice::where('contract_id', $contract->contract_id)->first();
                    if ($existingLink) {
                        continue; // Already processed
                    }

                    // No invoice exists yet, check if there's any invoice for this tax
                    $anyInvoice = TaxInvoice::where('mining_tax_id', $taxCodeRecord->mining_tax_id)->first();
                    if ($anyInvoice && in_array($anyInvoice->status, ['accepted'])) {
                        continue; // Already paid via another method
                    }

                    Log::debug("Mining Manager: No pending invoice found for tax code {$taxCodeRecord->code}");
                    continue;
                }

                // Update invoice with contract ID
                $contractStatus = $contract->status ?? 'outstanding';
                $updated = $this->updateInvoiceFromContract($invoice, $contractStatus);

                if ($updated) {
                    $invoice->update(['contract_id' => $contract->contract_id]);
                    $matched++;

                    // If accepted/finished, mark tax code as used
                    if (in_array($contractStatus, ['finished', 'finished_issuer', 'finished_contractor'])) {
                        $taxCodeRecord->update([
                            'status' => 'used',
                            'used_at' => Carbon::now(),
                        ]);
                        $paid++;

                        Log::info("Mining Manager: Contract {$contract->contract_id} matched and paid - tax code {$taxCodeRecord->code}");
                    } else {
                        Log::info("Mining Manager: Contract {$contract->contract_id} matched - status '{$contractStatus}' - tax code {$taxCodeRecord->code}");
                    }
                }

            } catch (\Exception $e) {
                $contractId = $contract->contract_id ?? 'unknown';
                Log::error("Mining Manager: Error processing contract {$contractId}: " . $e->getMessage());
                $errors[] = [
                    'contract_id' => $contractId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::info("Mining Manager: Contract scan complete. Scanned: {$scanned}, Matched: {$matched}, Paid: {$paid}");

        return [
            'scanned' => $scanned,
            'matched' => $matched,
            'paid' => $paid,
            'errors' => $errors,
        ];
    }

    /**
     * Extract a tax code from text (contract title or description).
     *
     * Looks for patterns like "TAX-ABCD1234" or just the code portion after the prefix.
     *
     * @param string $text The text to search
     * @param string $prefix The tax code prefix (e.g. "TAX-")
     * @return string|null The extracted code (without prefix), or null if not found
     */
    public function extractTaxCodeFromText(string $text, string $prefix = 'TAX-'): ?string
    {
        if (empty($text)) {
            return null;
        }

        // Try to match with prefix first (e.g. "TAX-K7F3MP9A")
        $escapedPrefix = preg_quote($prefix, '/');
        if (preg_match('/' . $escapedPrefix . '([A-Z0-9]{4,16})/i', $text, $matches)) {
            return strtoupper($matches[1]);
        }

        // Try to match a standalone alphanumeric code that looks like a tax code (8+ chars)
        if (preg_match('/\b([A-Z0-9]{8,16})\b/', strtoupper($text), $matches)) {
            // Verify it's actually a tax code in our database
            $potentialCode = $matches[1];
            $exists = TaxCode::where('code', $prefix . $potentialCode)
                ->orWhere('code', $potentialCode)
                ->exists();

            if ($exists) {
                return $potentialCode;
            }
        }

        return null;
    }
}
