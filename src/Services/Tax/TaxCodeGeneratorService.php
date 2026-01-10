<?php

namespace MiningManager\Services\Tax;

use MiningManager\Models\TaxCode;
use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxInvoice;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TaxCodeGeneratorService
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
     * Generate a unique tax code for a tax record.
     *
     * @param MiningTax $tax
     * @param TaxInvoice|null $invoice
     * @return TaxCode
     */
    public function generateTaxCode(MiningTax $tax, ?TaxInvoice $invoice = null): TaxCode
    {
        $code = $this->generateUniqueCode();

        $gracePeriodDays = $this->settings->getSetting('exemptions.grace_period_days', 7);
        $expirationBuffer = $this->settings->getSetting('tax_rates.tax_code_expiration_buffer', 30);
        $expirationDays = $gracePeriodDays + $expirationBuffer;

        $taxCode = TaxCode::create([
            'mining_tax_id' => $tax->id,
            'character_id' => $tax->character_id,
            'code' => $code,
            'prefix' => TaxCode::getPrefix(),
            'status' => 'active',
            'generated_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays($expirationDays),
        ]);

        Log::info("Mining Manager: Generated tax code {$code} for character {$tax->character_id}");

        return $taxCode;
    }

    /**
     * Generate a unique code.
     *
     * @return string
     */
    public function generateUniqueCode(): string
    {
        $length = $this->settings->getSetting('tax_rates.tax_code_length', 8);
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed ambiguous characters (I, O, 0, 1)

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[random_int(0, strlen($characters) - 1)];
            }
        } while (TaxCode::where('code', $code)->exists());

        return $code;
    }

    /**
     * Get full tax code with prefix.
     *
     * @param string $code
     * @return string
     */
    public function getFullCode(string $code): string
    {
        $prefix = $this->settings->getSetting('tax_rates.tax_code_prefix', 'TAX-');
        return $prefix . $code;
    }

    /**
     * Get payment instructions for a tax code.
     *
     * @param TaxCode $taxCode
     * @return string
     */
    public function getPaymentInstructions(TaxCode $taxCode): string
    {
        $fullCode = $this->getFullCode($taxCode->code);
        $amount = number_format($taxCode->miningTax->amount_owed, 2);

        return "Please send {$amount} ISK to [Corporation Wallet] with the following in the description: {$fullCode}";
    }

    /**
     * Generate bulk tax codes for multiple taxes.
     *
     * @param array $taxIds
     * @return array
     */
    public function generateBulkTaxCodes(array $taxIds): array
    {
        $generated = 0;
        $errors = [];

        foreach ($taxIds as $taxId) {
            try {
                $tax = MiningTax::findOrFail($taxId);

                // Check if active code already exists
                $existingCode = TaxCode::where('mining_tax_id', $tax->id)
                    ->where('status', 'active')
                    ->first();

                if ($existingCode) {
                    continue;
                }

                $this->generateTaxCode($tax);
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
     * Expire old tax codes.
     *
     * @return int
     */
    public function expireOldCodes(): int
    {
        $expired = TaxCode::where('status', 'active')
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            Log::info("Mining Manager: Expired {$expired} tax codes");
        }

        return $expired;
    }

    /**
     * Regenerate a tax code.
     *
     * @param int $taxCodeId
     * @return TaxCode
     */
    public function regenerateTaxCode(int $taxCodeId): TaxCode
    {
        $oldCode = TaxCode::findOrFail($taxCodeId);

        // Cancel old code
        $oldCode->update(['status' => 'cancelled']);

        // Generate new code
        $newCode = $this->generateTaxCode($oldCode->miningTax);

        Log::info("Mining Manager: Regenerated tax code for tax {$oldCode->mining_tax_id}");

        return $newCode;
    }

    /**
     * Validate a tax code.
     *
     * @param string $code
     * @param int $characterId
     * @return array
     */
    public function validateTaxCode(string $code, int $characterId): array
    {
        $taxCode = TaxCode::where('code', $code)
            ->where('character_id', $characterId)
            ->first();

        if (!$taxCode) {
            return [
                'valid' => false,
                'message' => 'Tax code not found',
            ];
        }

        if ($taxCode->status !== 'active') {
            return [
                'valid' => false,
                'message' => "Tax code is {$taxCode->status}",
            ];
        }

        if ($taxCode->expires_at && Carbon::now()->greaterThan($taxCode->expires_at)) {
            return [
                'valid' => false,
                'message' => 'Tax code has expired',
            ];
        }

        return [
            'valid' => true,
            'tax_code' => $taxCode,
            'amount' => $taxCode->miningTax->amount_owed,
            'message' => 'Tax code is valid',
        ];
    }

    /**
     * Get tax code statistics.
     *
     * @return array
     */
    public function getTaxCodeStatistics(): array
    {
        return [
            'active' => TaxCode::where('status', 'active')->count(),
            'used' => TaxCode::where('status', 'used')->count(),
            'expired' => TaxCode::where('status', 'expired')->count(),
            'cancelled' => TaxCode::where('status', 'cancelled')->count(),
            'usage_rate' => $this->calculateUsageRate(),
        ];
    }

    /**
     * Calculate tax code usage rate.
     *
     * @return float Percentage
     */
    private function calculateUsageRate(): float
    {
        $total = TaxCode::whereIn('status', ['active', 'used', 'expired'])->count();

        if ($total === 0) {
            return 0;
        }

        $used = TaxCode::where('status', 'used')->count();

        return ($used / $total) * 100;
    }

    /**
     * Send tax code notification to character.
     *
     * @param TaxCode $taxCode
     * @return bool
     */
    public function sendTaxCodeNotification(TaxCode $taxCode): bool
    {
        // This would integrate with notification service to send EVE mail
        // For now, this is a placeholder

        try {
            $fullCode = $this->getFullCode($taxCode->code);
            $instructions = $this->getPaymentInstructions($taxCode);

            Log::info("Mining Manager: Tax code notification sent to character {$taxCode->character_id}: {$fullCode}");

            return true;

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error sending tax code notification: " . $e->getMessage());
            return false;
        }
    }
}
