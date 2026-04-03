<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Tax\WalletTransferService;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class VerifyWalletPaymentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:verify-payments
                            {--days=7 : Number of days to check back}
                            {--character_id= : Verify payments for specific character}
                            {--auto-match : Automatically match payments to taxes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify wallet transfers against outstanding tax payments';

    /**
     * Wallet transfer service
     *
     * @var WalletTransferService
     */
    protected $walletService;

    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Create a new command instance.
     *
     * @param WalletTransferService $walletService
     * @param SettingsManagerService $settingsService
     */
    public function __construct(WalletTransferService $walletService, SettingsManagerService $settingsService)
    {
        parent::__construct();
        $this->walletService = $walletService;
        $this->settingsService = $settingsService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check feature flag
        $features = $this->settingsService->getFeatureFlags();
        if (!($features['verify_wallet_transactions'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $this->info('Starting payment verification...');

        $days = (int) $this->option('days');
        $characterId = $this->option('character_id');
        $autoMatch = $this->option('auto-match');
        $cutoffDate = Carbon::now()->subDays($days);

        // Get payment settings for division info
        $paymentSettings = $this->settingsService->getPaymentSettings();
        $division = $paymentSettings['wallet_division'] ?? 1;
        $divisionName = $this->settingsService->getWalletDivisionName();

        $this->info("Checking transactions from the last {$days} days");
        $this->info("Primary wallet division: {$divisionName} (division {$division})" .
            ($division !== 1 ? ' + Master Wallet (fallback)' : ''));

        // Get corporation ID for filtering
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

        // Build the divisions to check (primary + master wallet fallback)
        $divisions = [$division];
        if ($division !== 1) {
            $divisions[] = 1; // Always check master wallet as fallback
        }

        // Query corporation wallet journals with division filtering
        $query = DB::table('corporation_wallet_journals')
            ->where('date', '>=', $cutoffDate)
            ->where('ref_type', 'player_donation')
            ->whereIn('division', $divisions);

        if ($moonOwnerCorpId) {
            $query->where('corporation_id', $moonOwnerCorpId);
        }

        if ($characterId) {
            $query->where('first_party_id', $characterId);
            $this->info("Verifying payments for character ID: {$characterId}");
        }

        $transactions = $query->orderBy('date', 'desc')->get();

        if ($transactions->isEmpty()) {
            $this->warn('No relevant transactions found in configured wallet divisions');
            return Command::SUCCESS;
        }

        $this->info("Found {$transactions->count()} transactions to check");

        $matched = 0;
        $unmatched = 0;
        $errors = 0;

        foreach ($transactions as $transaction) {
            try {
                // Check if transaction has a tax code in reason or description
                $text = ($transaction->reason ?? '') . ' ' . ($transaction->description ?? '');
                $taxCode = $this->extractTaxCode($text);

                if (!$taxCode) {
                    $unmatched++;
                    continue;
                }

                // Find tax code record
                $taxCodeRecord = TaxCode::where('code', $taxCode)
                    ->where('character_id', $transaction->first_party_id)
                    ->first();

                if (!$taxCodeRecord) {
                    $this->warn("Tax code '{$taxCode}' not found for character {$transaction->first_party_id}");
                    $unmatched++;
                    continue;
                }

                // Find associated tax record
                $tax = MiningTax::find($taxCodeRecord->mining_tax_id);

                if (!$tax) {
                    $this->warn("Tax record not found for code '{$taxCode}'");
                    $unmatched++;
                    continue;
                }

                // Verify amount matches
                $amount = abs($transaction->amount);
                $tolerance = $paymentSettings['match_tolerance'] ?? 100;

                if (abs($amount - $tax->amount_owed) > $tolerance) {
                    $this->warn("Amount mismatch for tax code '{$taxCode}': " .
                               "Expected " . number_format($tax->amount_owed, 2) . ", Got " . number_format($amount, 2));
                }

                $divLabel = $transaction->division == 1 ? 'Master Wallet' : "Division {$transaction->division}";

                if ($autoMatch) {
                    // Update tax record
                    $previousPaid = $tax->amount_paid ?? 0;
                    $newPaid = $previousPaid + $amount;

                    $tax->update([
                        'amount_paid' => $newPaid,
                        'paid_at' => $transaction->date,
                        'status' => $newPaid >= $tax->amount_owed ? 'paid' : 'partial',
                        'transaction_id' => $transaction->id,
                    ]);

                    // Mark tax code as used
                    $taxCodeRecord->update([
                        'used_at' => Carbon::now(),
                        'transaction_id' => $transaction->id,
                        'status' => 'used',
                    ]);

                    $this->line("Matched payment for character {$transaction->first_party_id}: " .
                               number_format($amount, 2) . " ISK [{$divLabel}]");
                    $matched++;
                } else {
                    $this->line("Found potential match for character {$transaction->first_party_id}: " .
                               number_format($amount, 2) . " ISK (code: {$taxCode}) [{$divLabel}]");
                    $matched++;
                }

            } catch (\Exception $e) {
                $this->error("Error processing transaction {$transaction->id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("\nVerification complete!");
        $this->info("Matched: {$matched} payments");
        if ($unmatched > 0) {
            $this->info("Unmatched: {$unmatched} transactions");
        }
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        if (!$autoMatch && $matched > 0) {
            $this->info("\nRun with --auto-match to automatically apply these payments");
        }

        return Command::SUCCESS;
    }

    /**
     * Extract tax code from transaction text (reason + description)
     *
     * @param string $text
     * @return string|null
     */
    private function extractTaxCode(?string $text): ?string
    {
        if (!$text) {
            return null;
        }

        $length = TaxCode::getCodeLength();

        // Try all known prefixes: current setting + any stored in DB
        $prefixes = collect([TaxCode::getPrefix()]);
        $storedPrefixes = TaxCode::select('prefix')->distinct()->whereNotNull('prefix')->pluck('prefix');
        $prefixes = $prefixes->merge($storedPrefixes)->unique();

        foreach ($prefixes as $tryPrefix) {
            $escapedPrefix = preg_quote($tryPrefix, '/');
            if (preg_match('/' . $escapedPrefix . '([A-Z0-9]{' . $length . '})/', $text, $matches)) {
                return strtoupper($matches[1]);
            }
        }

        return null;
    }
}
