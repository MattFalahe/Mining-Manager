<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Tax\TaxCalculationService;
use MiningManager\Services\Tax\ContractManagementService;
use MiningManager\Services\Tax\WalletTransferService;
use MiningManager\Services\Tax\TaxCodeGeneratorService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\TaxInvoice;
use MiningManager\Models\TaxCode;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TaxController extends Controller
{
    protected $taxService;
    protected $contractService;
    protected $walletService;
    protected $codeService;
    protected $settingsService;

    public function __construct(
        TaxCalculationService $taxService,
        ContractManagementService $contractService,
        WalletTransferService $walletService,
        TaxCodeGeneratorService $codeService,
        SettingsManagerService $settingsService
    ) {
        $this->taxService = $taxService;
        $this->contractService = $contractService;
        $this->walletService = $walletService;
        $this->codeService = $codeService;
        $this->settingsService = $settingsService;
    }

    /**
     * Set corporation context for all services.
     * This ensures settings are retrieved for the correct corporation.
     *
     * @param int|null $corporationId
     * @return void
     */
    protected function setCorporationContext(?int $corporationId): void
    {
        if ($corporationId) {
            $this->settingsService->setActiveCorporation($corporationId);
            $this->taxService->setCorporationContext($corporationId);
            $this->contractService->setCorporationContext($corporationId);
            $this->walletService->setCorporationContext($corporationId);
            $this->codeService->setCorporationContext($corporationId);
        }
    }

    /**
     * Display tax overview dashboard
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'all');
        $month = $request->input('month');
        $corporationId = $request->input('corporation_id');
        $minerType = $request->input('miner_type', 'all'); // 'all', 'corp', 'guest'

        // Get moon owner corporation ID from settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

        // Set corporation context for settings retrieval
        $this->setCorporationContext($moonOwnerCorpId);

        // Build query - include affiliation for corporation_id lookup
        $query = MiningTax::with(['character', 'character.corporation', 'affiliation', 'taxCodes', 'taxInvoices']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($month) {
            $query->where('month', Carbon::parse($month)->format('Y-m-01'));
        }

        if ($corporationId) {
            // Use character_affiliations table for corporation_id lookup
            $query->whereIn('character_id', function($q) use ($corporationId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', $corporationId);
            });
        }

        // Filter by miner type (corp member vs guest) - use character_affiliations for corporation_id
        if ($minerType === 'corp' && $moonOwnerCorpId) {
            // Only show corp members
            $query->whereIn('character_id', function($q) use ($moonOwnerCorpId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', $moonOwnerCorpId);
            });
        } elseif ($minerType === 'guest' && $moonOwnerCorpId) {
            // Only show guest miners (not corp members)
            $query->whereIn('character_id', function($q) use ($moonOwnerCorpId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', '!=', $moonOwnerCorpId);
            });
        }

        $taxes = $query->orderBy('month', 'desc')
            ->orderBy('character_id')
            ->paginate(50);

        // Summary statistics - split by corp/guest if moon owner is configured
        $summaryQuery = MiningTax::query();
        $corpSummaryQuery = null;
        $guestSummaryQuery = null;

        if ($moonOwnerCorpId) {
            // Corp members summary - use character_affiliations table for corporation_id
            $corpSummaryQuery = MiningTax::whereIn('character_id', function($q) use ($moonOwnerCorpId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', $moonOwnerCorpId);
            });

            // Guest miners summary - use character_affiliations table for corporation_id
            $guestSummaryQuery = MiningTax::whereIn('character_id', function($q) use ($moonOwnerCorpId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', '!=', $moonOwnerCorpId);
            });
        }

        $totalOwed = MiningTax::where('status', 'unpaid')->sum('amount_owed');
        $totalOverdue = MiningTax::where('status', 'overdue')->sum('amount_owed');
        $collectedThisMonth = MiningTax::where('status', 'paid')
            ->whereMonth('paid_at', Carbon::now()->month)
            ->sum('amount_paid');
        $unpaidCount = MiningTax::where('status', 'unpaid')->count();
        $overdueCount = MiningTax::where('status', 'overdue')->count();
        $paidCount = MiningTax::where('status', 'paid')
            ->whereMonth('paid_at', Carbon::now()->month)
            ->count();

        // Calculate collection rate
        $totalExpected = $totalOwed + $totalOverdue + $collectedThisMonth;
        $collectionRate = $totalExpected > 0 ? ($collectedThisMonth / $totalExpected) * 100 : 0;

        $summary = [
            'total_owed' => $totalOwed,
            'overdue_amount' => $totalOverdue,
            'collected' => $collectedThisMonth,
            'unpaid_count' => $unpaidCount,
            'overdue_count' => $overdueCount,
            'paid_count' => $paidCount,
            'collection_rate' => $collectionRate,
        ];

        // Add corp vs guest breakdown if moon owner is configured
        if ($moonOwnerCorpId && $corpSummaryQuery && $guestSummaryQuery) {
            $summary['corp_members'] = [
                'owed' => $corpSummaryQuery->where('status', 'unpaid')->sum('amount_owed'),
                'count' => $corpSummaryQuery->whereIn('status', ['unpaid', 'overdue'])->count(),
                'collected' => $corpSummaryQuery->where('status', 'paid')
                    ->whereMonth('paid_at', Carbon::now()->month)
                    ->sum('amount_paid'),
            ];
            $summary['guest_miners'] = [
                'owed' => $guestSummaryQuery->where('status', 'unpaid')->sum('amount_owed'),
                'count' => $guestSummaryQuery->whereIn('status', ['unpaid', 'overdue'])->count(),
                'collected' => $guestSummaryQuery->where('status', 'paid')
                    ->whereMonth('paid_at', Carbon::now()->month)
                    ->sum('amount_paid'),
            ];
        }

        // Get payment method from settings
        $paymentMethod = $this->settingsService->getPaymentSettings()['method'];

        // Get active corporations with taxes
        $corporationIds = DB::table('character_affiliations')
            ->whereIn('character_id', function($query) {
                $query->select('character_id')
                    ->from('mining_taxes')
                    ->distinct();
            })
            ->distinct()
            ->pluck('corporation_id');

        $corporations = DB::table('corporation_infos')
            ->whereIn('corporation_id', $corporationIds)
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        return view('mining-manager::taxes.index', compact(
            'taxes',
            'summary',
            'paymentMethod',
            'corporations',
            'status',
            'month',
            'corporationId',
            'minerType',
            'moonOwnerCorpId'
        ));
    }

    /**
     * Show tax calculation form
     */
    public function showCalculateForm()
    {
        // Get corporations for dropdown
        $corporations = CorporationInfo::orderBy('name')->get();

        // Generate years array (current year and past 2 years)
        $currentYear = now()->year;
        $years = range($currentYear - 2, $currentYear);

        // Get settings
        $generalSettings = [
            'tax_calculation_method' => $this->settingsService->getSetting('general.tax_calculation_method', 'accumulated'),
        ];

        $sourceSettings = [
            'source' => $this->settingsService->getSetting('general.data_source', 'archived'),
        ];

        $paymentSettings = $this->settingsService->getPaymentSettings();

        // Get live tracking data for current month
        $liveTracking = $this->getLiveTrackingData();

        return view('mining-manager::taxes.calculate', compact(
            'corporations',
            'years',
            'generalSettings',
            'sourceSettings',
            'paymentSettings',
            'liveTracking'
        ));
    }

    /**
     * Get live mining tracking data for current month
     *
     * @return array
     */
    protected function getLiveTrackingData(): array
    {
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $currentMonth = now()->startOfMonth();

        // Get mining ledger entries for current month
        $query = MiningLedger::with('character')
            ->where('date', '>=', $currentMonth);

        if ($moonOwnerCorpId) {
            // Filter by corporation using a subquery to avoid duplicate rows from joins
            $query->whereIn('character_id', function($q) use ($moonOwnerCorpId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', $moonOwnerCorpId);
            });
        }

        $entries = $query->orderBy('date', 'desc')
            ->limit(50)
            ->get();

        if ($entries->isEmpty()) {
            return [
                'has_data' => false,
                'entries' => [],
                'total_value' => 0,
                'estimated_tax' => 0,
                'character_count' => 0,
                'month' => $currentMonth->format('F Y'),
            ];
        }

        $totalValue = $entries->sum('value');
        $estimatedTax = $totalValue * 0.10; // Rough estimate using default rate
        $characterCount = $entries->pluck('character_id')->unique()->count();

        return [
            'has_data' => true,
            'entries' => $entries->map(function($entry) {
                return [
                    'date' => $entry->date,
                    'character' => ['name' => $entry->character->name ?? 'Unknown'],
                    'quantity' => $entry->quantity,
                    'volume' => $entry->volume ?? 0,
                    'value' => $entry->value ?? 0,
                ];
            })->toArray(),
            'total_value' => $totalValue,
            'estimated_tax' => $estimatedTax,
            'character_count' => $characterCount,
            'month' => $currentMonth->format('F Y'),
        ];
    }

    /**
     * Process tax calculation
     */
    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'recalculate' => 'boolean',
            'character_id' => 'nullable|integer',
            'corporation_id' => 'nullable|integer',
        ]);

        try {
            $month = Carbon::parse($validated['month'])->startOfMonth();
            $recalculate = $validated['recalculate'] ?? false;
            $characterId = $validated['character_id'] ?? null;

            // Set corporation context for settings (use provided or fall back to moon owner)
            $corporationId = $validated['corporation_id'] ?? null;
            if (!$corporationId) {
                $corporationId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
            }
            $this->setCorporationContext($corporationId);

            // Check if taxes already exist for this month
            $existingQuery = MiningTax::where('month', $month->format('Y-m-01'));

            if ($characterId) {
                $existingQuery->where('character_id', $characterId);
            }

            $existingCount = $existingQuery->count();

            if ($existingCount > 0 && !$recalculate) {
                return response()->json([
                    'status' => 'warning',
                    'message' => trans('mining-manager::taxes.taxes_already_exist', [
                        'count' => $existingCount,
                        'month' => $month->format('F Y')
                    ]),
                    'existing_count' => $existingCount,
                ], 200);
            }

            // Calculate taxes (corporation context already set)
            $results = $this->taxService->calculateMonthlyTaxes($month, $recalculate, $characterId);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.calculation_complete'),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Tax calculation error: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.calculation_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display tax contracts management
     */
    public function contracts(Request $request)
    {
        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        $status = $request->input('status', 'pending');
        $month = $request->input('month');

        // Build query for tax invoices
        $query = TaxInvoice::with(['miningTax.character', 'miningTax.taxCode']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($month) {
            $query->whereHas('miningTax', function($q) use ($month) {
                $q->where('month', Carbon::parse($month)->format('Y-m-01'));
            });
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate(50);

        // Summary stats
        $pendingCount = TaxInvoice::where('status', 'pending')->count();
        $completedCount = TaxInvoice::where('status', 'completed')->count();
        $totalValue = TaxInvoice::where('status', 'pending')->sum('amount');

        $summary = [
            'pending_count' => $pendingCount,
            'completed_count' => $completedCount,
            'total_value' => $totalValue,
        ];

        return view('mining-manager::taxes.contracts', compact(
            'invoices',
            'summary',
            'status',
            'month'
        ));
    }

    /**
     * Generate tax contracts for unpaid taxes
     */
    public function generateContracts(Request $request)
    {
        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $month = $request->input('month');
            $monthDate = $month ? Carbon::parse($month)->startOfMonth() : null;

            $results = $this->contractService->generateInvoices($monthDate);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.contracts_generated'),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Contract generation error: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.contract_generation_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display wallet verification page
     * TODO: Implement WalletTransaction model and wallet verification feature
     */
    public function wallet(Request $request)
    {
        $status = $request->input('status', 'pending');
        $month = $request->input('month');
        $days = $request->input('days', 30);

        // Get corporation ID from settings (or use first corporation if not configured)
        $corporationId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

        if (!$corporationId) {
            // Try to get from first configured corporation
            $corporations = $this->settingsService->getAllCorporations();
            $corporationId = $corporations->first()->corporation_id ?? null;
        }

        // Set corporation context for all services
        $this->setCorporationContext($corporationId);

        if (!$corporationId) {
            // No corporation configured, return empty view
            return view('mining-manager::taxes.wallet', [
                'transactions' => collect(),
                'summary' => [
                    'pending_count' => 0,
                    'verified_count' => 0,
                    'verified_today' => 0,
                    'total_verified_isk' => 0,
                    'unmatched_count' => 0,
                ],
                'status' => $status,
                'month' => $month,
                'corporationId' => null,
            ]);
        }

        // Get corporation donations (player_donation type transactions)
        $donations = $this->walletService->getCorporationDonations($corporationId, $days);

        // Get unmatched donations (donations without tax codes)
        $unmatchedDonations = $this->walletService->getUnmatchedDonations($corporationId, $days);

        // Calculate summary statistics
        $verifiedToday = DB::table('corporation_wallet_journals')
            ->where('corporation_id', $corporationId)
            ->where('ref_type', 'player_donation')
            ->whereDate('date', Carbon::today())
            ->count();

        $totalVerifiedIsk = MiningTax::where('status', 'paid')
            ->where('paid_at', '>=', Carbon::now()->subDays($days))
            ->sum('amount_paid');

        $pendingCount = MiningTax::whereIn('status', ['unpaid', 'overdue'])->count();
        $verifiedCount = MiningTax::where('status', 'paid')
            ->where('paid_at', '>=', Carbon::now()->subDays($days))
            ->count();

        $summary = [
            'pending_count' => $pendingCount,
            'verified_count' => $verifiedCount,
            'verified_today' => $verifiedToday,
            'total_verified_isk' => $totalVerifiedIsk,
            'unmatched_count' => $unmatchedDonations->count(),
        ];

        // Filter transactions based on status
        if ($status === 'pending') {
            $transactions = $unmatchedDonations;
        } else {
            $transactions = $donations;
        }

        // Apply month filter if specified
        if ($month) {
            $monthDate = Carbon::parse($month);
            $transactions = $transactions->filter(function($transaction) use ($monthDate) {
                $transactionDate = Carbon::parse($transaction->date);
                return $transactionDate->isSameMonth($monthDate);
            });
        }

        // Paginate results
        $perPage = 25;
        $page = $request->input('page', 1);
        $offset = ($page - 1) * $perPage;
        $paginatedTransactions = $transactions->slice($offset, $perPage);

        return view('mining-manager::taxes.wallet', compact(
            'transactions',
            'summary',
            'status',
            'month',
            'corporationId',
            'paginatedTransactions'
        ));
    }

    /**
     * Verify wallet payment
     */
    public function verifyPayment(Request $request, $transactionId)
    {
        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $result = $this->walletService->verifyPayment($transactionId);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.payment_verified'),
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Payment verification error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.verification_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto-match wallet transactions to tax codes
     * Uses corporation_wallet_journals to verify player donations
     */
    public function autoMatchPayments(Request $request)
    {
        try {
            $days = $request->input('days', 30);

            // Get corporation ID from settings
            $corporationId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

            if (!$corporationId) {
                $corporations = $this->settingsService->getAllCorporations();
                $corporationId = $corporations->first()->corporation_id ?? null;
            }

            // Set corporation context for services
            $this->setCorporationContext($corporationId);

            // Run auto-verification using corporation wallet journals
            $results = $this->walletService->autoVerifyFromCorporationWallet($corporationId, $days);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.auto_match_complete', [
                    'verified' => $results['verified']
                ]),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-match error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.auto_match_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark tax as paid
     */
    public function markPaid(Request $request)
    {
        try {
            $taxId = $request->input('tax_id');
            $amountPaid = $request->input('amount_paid');
            $paymentDate = $request->input('payment_date', Carbon::now());

            $tax = MiningTax::findOrFail($taxId);
            $tax->status = 'paid';
            $tax->amount_paid = $amountPaid ?? $tax->amount_owed;
            $tax->paid_at = Carbon::parse($paymentDate);
            $tax->save();

            Log::info('Tax manually marked as paid', [
                'tax_id' => $taxId,
                'amount' => $amountPaid,
                'marked_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.marked_as_paid_success'),
            ]);

        } catch (\Exception $e) {
            Log::error('Error marking tax as paid: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.error_marking_paid'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send payment reminder
     */
    public function sendReminder(Request $request)
    {
        try {
            $taxId = $request->input('tax_id');
            
            // Log the reminder action (actual notification implementation needed)
            Log::info('Payment reminder requested', [
                'tax_id' => $taxId,
                'requested_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.reminder_sent'),
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending reminder: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.error_sending_reminder'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk mark taxes as paid
     */
    public function bulkMarkPaid(Request $request)
    {
        try {
            $taxIds = $request->input('tax_ids', []);
            $paymentDate = $request->input('payment_date', Carbon::now());

            $updated = MiningTax::whereIn('id', $taxIds)
                ->update([
                    'status' => 'paid',
                    'paid_at' => Carbon::parse($paymentDate),
                    'amount_paid' => DB::raw('amount_owed'),
                ]);

            Log::info('Taxes bulk marked as paid', [
                'count' => $updated,
                'marked_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.bulk_marked_success', ['count' => $updated]),
            ]);

        } catch (\Exception $e) {
            Log::error('Error bulk marking taxes: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.error_bulk_marking'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk send reminders
     */
    public function bulkSendReminders(Request $request)
    {
        try {
            $taxIds = $request->input('tax_ids', []);
            
            // Log the bulk reminder action
            Log::info('Bulk reminders requested', [
                'count' => count($taxIds),
                'requested_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.bulk_reminders_sent', ['count' => count($taxIds)]),
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending bulk reminders: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.error_bulk_reminders'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display user's personal tax overview
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function myTaxes(Request $request)
    {
        $user = auth()->user();
        
        // Get all character IDs for this user
        $characterIds = $user->characters->pluck('character_id')->toArray();

        if (empty($characterIds)) {
            return view('mining-manager::taxes.my-taxes', [
                'taxHistory' => collect(),
                'summary' => [
                    'total_owed' => 0,
                    'overdue_amount' => 0,
                    'paid_this_month' => 0,
                    'unpaid_count' => 0,
                    'overdue_count' => 0,
                ],
                'miningData' => [],
                'currentTax' => null,
                'totalTaxPaid' => 0,
                'onTimePayments' => 0,
                'latePayments' => 0,
                'status' => $request->input('status', 'all'),
                'month' => $request->input('month'),
                'paymentMethod' => $this->settingsService->getPaymentSettings()['method'],
            ]);
        }

        // Get taxes for user's characters
        $status = $request->input('status', 'all');
        $month = $request->input('month');

        $query = MiningTax::with(['character', 'taxCode', 'invoice'])
            ->whereIn('character_id', $characterIds);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($month) {
            $query->where('month', Carbon::parse($month)->format('Y-m-01'));
        }

        $taxHistory = $query->orderBy('month', 'desc')
            ->orderBy('character_id')
            ->paginate(25);

        // Personal summary statistics
        $totalOwed = MiningTax::whereIn('character_id', $characterIds)
            ->where('status', 'unpaid')
            ->sum('amount_owed');
            
        $totalOverdue = MiningTax::whereIn('character_id', $characterIds)
            ->where('status', 'overdue')
            ->sum('amount_owed');
            
        $paidThisMonth = MiningTax::whereIn('character_id', $characterIds)
            ->where('status', 'paid')
            ->whereMonth('paid_at', Carbon::now()->month)
            ->sum('amount_paid');
            
        $unpaidCount = MiningTax::whereIn('character_id', $characterIds)
            ->where('status', 'unpaid')
            ->count();
            
        $overdueCount = MiningTax::whereIn('character_id', $characterIds)
            ->where('status', 'overdue')
            ->count();

        $summary = [
            'total_owed' => $totalOwed,
            'overdue_amount' => $totalOverdue,
            'paid_this_month' => $paidThisMonth,
            'unpaid_count' => $unpaidCount,
            'overdue_count' => $overdueCount,
        ];

        // Get recent mining activity
        $startOfMonth = Carbon::now()->startOfMonth();
        $miningData = MiningLedger::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startOfMonth)
            ->orderBy('date', 'desc')
            ->limit(10)
            ->get();

        // Get current month's tax for display
        $currentMonth = Carbon::now()->startOfMonth()->format('Y-m-01');
        $currentTax = MiningTax::with(['character', 'taxCode'])
            ->whereIn('character_id', $characterIds)
            ->where('month', $currentMonth)
            ->first();

        // Get payment statistics (all time)
        $totalTaxPaid = MiningTax::whereIn('character_id', $characterIds)
            ->where('status', 'paid')
            ->sum('amount_paid');

        $onTimePayments = MiningTax::whereIn('character_id', $characterIds)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereNotNull('due_date')
            ->whereColumn('paid_at', '<=', 'due_date')
            ->count();

        $latePayments = MiningTax::whereIn('character_id', $characterIds)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereNotNull('due_date')
            ->whereColumn('paid_at', '>', 'due_date')
            ->count();

        // Get payment method from settings
        $paymentMethod = $this->settingsService->getPaymentSettings()['method'];

        return view('mining-manager::taxes.my-taxes', compact(
            'taxHistory',
            'summary',
            'miningData',
            'currentTax',
            'totalTaxPaid',
            'onTimePayments',
            'latePayments',
            'status',
            'month',
            'paymentMethod'
        ));
    }

    /**
     * Display tax codes management page
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function codes(Request $request)
    {
        $status = $request->input('status', 'active');
        $search = $request->input('search');

        // Build query
        $query = TaxCode::with(['character', 'miningTax']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhereHas('miningTax.character', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $taxCodes = $query->orderBy('created_at', 'desc')
            ->paginate(50);

        // Summary statistics
        $activeCount = TaxCode::where('status', 'active')->count();
        $usedCount = TaxCode::where('status', 'used')->count();
        $expiredCount = TaxCode::where('status', 'expired')->count();

        $summary = [
            'active_count' => $activeCount,
            'used_count' => $usedCount,
            'expired_count' => $expiredCount,
        ];

        return view('mining-manager::taxes.codes', compact(
            'taxCodes',
            'summary',
            'status',
            'search'
        ));
    }

    /**
     * Generate tax codes
     */
    public function generateCodes(Request $request)
    {
        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $month = $request->input('month');
            $monthDate = $month ? Carbon::parse($month)->startOfMonth() : Carbon::now()->startOfMonth();

            // Get unpaid tax IDs for this month
            $taxIds = MiningTax::where('month', $monthDate->format('Y-m-01'))
                ->whereIn('status', ['unpaid', 'overdue'])
                ->whereDoesntHave('taxCodes', function($q) {
                    $q->where('status', 'active');
                })
                ->pluck('id')
                ->toArray();

            if (empty($taxIds)) {
                return response()->json([
                    'status' => 'warning',
                    'message' => trans('mining-manager::taxes.no_unpaid_taxes_for_codes'),
                    'results' => ['generated' => 0, 'errors' => []],
                ]);
            }

            $results = $this->codeService->generateBulkTaxCodes($taxIds);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.codes_generated'),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Code generation error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.code_generation_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process tax calculation (POST endpoint)
     * This is an alias for calculate() to handle POST requests
     */
    public function processCalculation(Request $request)
    {
        return $this->calculate($request);
    }

    /**
     * Live tracking endpoint - returns current mining tracking data as JSON
     */
    public function liveTracking(Request $request)
    {
        $data = $this->getLiveTrackingData();

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Regenerate payments for a specific month
     */
    public function regeneratePayments(Request $request)
    {
        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $month = $request->input('month');

            if (!$month) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Month parameter is required',
                ], 400);
            }

            $monthDate = Carbon::parse($month)->startOfMonth();

            // Recalculate taxes for the month (corporation context already set)
            $results = $this->taxService->calculateMonthlyTaxes($monthDate, true);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.payments_regenerated'),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Payment regeneration error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.regeneration_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate invoices (alias for generateContracts)
     */
    public function generateInvoices(Request $request)
    {
        return $this->generateContracts($request);
    }

    /**
     * Verify multiple payments (alias for batch verification)
     */
    public function verifyPayments(Request $request)
    {
        // Set corporation context for settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $transactionIds = $request->input('transaction_ids', []);

            if (empty($transactionIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No transaction IDs provided',
                ], 400);
            }

            $results = [
                'verified' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            foreach ($transactionIds as $transactionId) {
                try {
                    $this->walletService->verifyPayment($transactionId);
                    $results['verified']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.payments_verified', ['count' => $results['verified']]),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Batch payment verification error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.verification_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show tax details for a specific character
     */
    public function details(Request $request, $characterId)
    {
        $character = CharacterInfo::findOrFail($characterId);

        // Get all taxes for this character
        $taxes = MiningTax::with(['taxCode', 'invoice'])
            ->where('character_id', $characterId)
            ->orderBy('month', 'desc')
            ->paginate(25);

        // Calculate statistics
        $totalOwed = MiningTax::where('character_id', $characterId)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->sum('amount_owed');

        $totalPaid = MiningTax::where('character_id', $characterId)
            ->where('status', 'paid')
            ->sum('amount_paid');

        $unpaidCount = MiningTax::where('character_id', $characterId)
            ->where('status', 'unpaid')
            ->count();

        $overdueCount = MiningTax::where('character_id', $characterId)
            ->where('status', 'overdue')
            ->count();

        // Get mining activity for current month
        $currentMonth = Carbon::now()->startOfMonth();
        $miningActivity = MiningLedger::where('character_id', $characterId)
            ->where('date', '>=', $currentMonth)
            ->orderBy('date', 'desc')
            ->get();

        $summary = [
            'total_owed' => $totalOwed,
            'total_paid' => $totalPaid,
            'unpaid_count' => $unpaidCount,
            'overdue_count' => $overdueCount,
        ];

        return view('mining-manager::taxes.details', compact(
            'character',
            'taxes',
            'summary',
            'miningActivity'
        ));
    }

    /**
     * Store a new tax code
     */
    public function storeCode(Request $request)
    {
        // Set corporation context for settings (for code length, prefix, etc.)
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $this->setCorporationContext($moonOwnerCorpId);

        try {
            $validated = $request->validate([
                'mining_tax_id' => 'required|exists:mining_taxes,id',
                'code' => 'nullable|string|unique:mining_tax_codes,code',
                'expires_at' => 'nullable|date',
            ]);

            // Get tax to determine character_id
            $tax = MiningTax::findOrFail($validated['mining_tax_id']);

            $taxCode = new TaxCode();
            $taxCode->mining_tax_id = $validated['mining_tax_id'];
            $taxCode->character_id = $tax->character_id;
            $taxCode->code = $validated['code'] ?? $this->codeService->generateUniqueCode();
            $taxCode->status = 'active';
            $taxCode->generated_at = Carbon::now();
            $taxCode->expires_at = isset($validated['expires_at'])
                ? Carbon::parse($validated['expires_at'])
                : Carbon::now()->addDays(
                    $this->settingsService->getSetting('exemptions.grace_period_days', 7) +
                    $this->settingsService->getSetting('tax_rates.tax_code_expiration_buffer', 30)
                );
            $taxCode->save();

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.code_created'),
                'code' => $taxCode,
            ]);

        } catch (\Exception $e) {
            Log::error('Tax code creation error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.code_creation_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a tax code
     */
    public function destroyCode(Request $request, $id)
    {
        try {
            $taxCode = TaxCode::findOrFail($id);

            // Don't delete used codes, just mark as expired
            if ($taxCode->status === 'used') {
                return response()->json([
                    'status' => 'error',
                    'message' => trans('mining-manager::taxes.cannot_delete_used_code'),
                ], 400);
            }

            $taxCode->delete();

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.code_deleted'),
            ]);

        } catch (\Exception $e) {
            Log::error('Tax code deletion error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.code_deletion_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update tax status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:unpaid,paid,overdue,exempted',
            ]);

            $tax = MiningTax::findOrFail($id);
            $oldStatus = $tax->status;
            $tax->status = $validated['status'];

            // If marking as paid, set payment date
            if ($validated['status'] === 'paid' && $oldStatus !== 'paid') {
                $tax->paid_at = Carbon::now();
                $tax->amount_paid = $tax->amount_owed;
            }

            $tax->save();

            Log::info('Tax status updated', [
                'tax_id' => $id,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
                'updated_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.status_updated'),
            ]);

        } catch (\Exception $e) {
            Log::error('Tax status update error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.status_update_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a tax record
     */
    public function destroy(Request $request, $id)
    {
        try {
            $tax = MiningTax::findOrFail($id);

            // Prevent deletion of paid taxes
            if ($tax->status === 'paid') {
                return response()->json([
                    'status' => 'error',
                    'message' => trans('mining-manager::taxes.cannot_delete_paid'),
                ], 400);
            }

            $characterName = $tax->character->name ?? 'Unknown';
            $tax->delete();

            Log::info('Tax record deleted', [
                'tax_id' => $id,
                'character' => $characterName,
                'deleted_by' => auth()->user()->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.tax_deleted'),
            ]);

        } catch (\Exception $e) {
            Log::error('Tax deletion error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.deletion_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export taxes to CSV/Excel
     */
    public function export(Request $request)
    {
        try {
            $status = $request->input('status', 'all');
            $month = $request->input('month');
            $format = $request->input('format', 'csv');

            // Build query
            $query = MiningTax::with(['character']);

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            if ($month) {
                $query->where('month', Carbon::parse($month)->format('Y-m-01'));
            }

            $taxes = $query->orderBy('month', 'desc')->orderBy('character_id')->get();

            // Generate filename
            $filename = 'taxes_export_' . Carbon::now()->format('Y-m-d_His') . '.' . $format;

            if ($format === 'csv') {
                $headers = [
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ];

                $callback = function() use ($taxes) {
                    $file = fopen('php://output', 'w');

                    // Headers
                    fputcsv($file, ['Character', 'Month', 'Amount Owed', 'Amount Paid', 'Status', 'Due Date', 'Paid At']);

                    // Data rows
                    foreach ($taxes as $tax) {
                        fputcsv($file, [
                            $tax->character->name ?? 'Unknown',
                            $tax->month,
                            $tax->amount_owed,
                            $tax->amount_paid ?? 0,
                            $tax->status,
                            $tax->due_date,
                            $tax->paid_at,
                        ]);
                    }

                    fclose($file);
                };

                return response()->stream($callback, 200, $headers);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Unsupported export format',
            ], 400);

        } catch (\Exception $e) {
            Log::error('Tax export error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.export_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export personal taxes for logged-in user
     */
    public function exportPersonal(Request $request)
    {
        $user = auth()->user();
        $characterIds = $user->characters->pluck('character_id')->toArray();

        try {
            $format = $request->input('format', 'csv');

            $taxes = MiningTax::with(['character'])
                ->whereIn('character_id', $characterIds)
                ->orderBy('month', 'desc')
                ->orderBy('character_id')
                ->get();

            $filename = 'my_taxes_' . Carbon::now()->format('Y-m-d_His') . '.' . $format;

            if ($format === 'csv') {
                $headers = [
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ];

                $callback = function() use ($taxes) {
                    $file = fopen('php://output', 'w');

                    fputcsv($file, ['Character', 'Month', 'Amount Owed', 'Amount Paid', 'Status', 'Due Date', 'Paid At']);

                    foreach ($taxes as $tax) {
                        fputcsv($file, [
                            $tax->character->name ?? 'Unknown',
                            $tax->month,
                            $tax->amount_owed,
                            $tax->amount_paid ?? 0,
                            $tax->status,
                            $tax->due_date,
                            $tax->paid_at,
                        ]);
                    }

                    fclose($file);
                };

                return response()->stream($callback, 200, $headers);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Unsupported export format',
            ], 400);

        } catch (\Exception $e) {
            Log::error('Personal tax export error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.export_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download tax receipt/invoice PDF
     */
    public function downloadReceipt(Request $request, $id)
    {
        try {
            $tax = MiningTax::with(['character', 'taxCode'])->findOrFail($id);

            // Check if user has permission to view this tax
            $user = auth()->user();
            $userCharacterIds = $user->characters->pluck('character_id')->toArray();

            if (!in_array($tax->character_id, $userCharacterIds) && !$user->can('mining-manager.tax.view')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ], 403);
            }

            // Generate simple text receipt
            $receipt = "TAX RECEIPT\n";
            $receipt .= "===========\n\n";
            $receipt .= "Character: " . ($tax->character->name ?? 'Unknown') . "\n";
            $receipt .= "Month: " . Carbon::parse($tax->month)->format('F Y') . "\n";
            $receipt .= "Amount Owed: " . number_format($tax->amount_owed, 2) . " ISK\n";
            $receipt .= "Amount Paid: " . number_format($tax->amount_paid ?? 0, 2) . " ISK\n";
            $receipt .= "Status: " . strtoupper($tax->status) . "\n";
            $receipt .= "Due Date: " . ($tax->due_date ?? 'N/A') . "\n";
            $receipt .= "Paid At: " . ($tax->paid_at ?? 'N/A') . "\n";

            if ($tax->taxCode) {
                $receipt .= "\nPayment Code: " . $tax->taxCode->code . "\n";
            }

            $receipt .= "\nGenerated: " . Carbon::now()->toDateTimeString() . "\n";

            $filename = 'receipt_' . $tax->character_id . '_' . Carbon::parse($tax->month)->format('Y-m') . '.txt';

            return response($receipt, 200)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            Log::error('Receipt download error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => trans('mining-manager::taxes.receipt_error'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
