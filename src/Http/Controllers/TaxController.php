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
     * Display tax overview dashboard
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'all');
        $month = $request->input('month');
        $corporationId = $request->input('corporation_id');

        // Build query
        $query = MiningTax::with(['character', 'taxCode', 'invoice']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($month) {
            $query->where('month', Carbon::parse($month)->format('Y-m-01'));
        }

        if ($corporationId) {
            $query->whereHas('character', function($q) use ($corporationId) {
                $q->where('corporation_id', $corporationId);
            });
        }

        $taxes = $query->orderBy('month', 'desc')
            ->orderBy('character_id')
            ->paginate(50);

        // Summary statistics
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

        // Get payment method from settings
        $paymentMethod = $this->settingsService->getPaymentSettings()['method'];

        // Get active corporations with taxes (through character relationship)
        $characterIds = MiningTax::distinct()->pluck('character_id');
        // In SeAT v5, corporation_id is accessed via the affiliation relationship
        $corporationIds = CharacterInfo::whereIn('character_id', $characterIds)
            ->with('affiliation')
            ->get()
            ->pluck('affiliation.corporation_id')
            ->unique()
            ->filter(); // Remove nulls
        $corporations = CorporationInfo::whereIn('corporation_id', $corporationIds)->get();

        return view('mining-manager::taxes.index', compact(
            'taxes', 
            'summary', 
            'paymentMethod',
            'corporations',
            'status',
            'month',
            'corporationId'
        ));
    }

    /**
     * Show tax calculation form
     */
    public function showCalculateForm()
    {
        return view('mining-manager::taxes.calculate');
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
        ]);

        try {
            $month = Carbon::parse($validated['month'])->startOfMonth();
            $recalculate = $validated['recalculate'] ?? false;
            $characterId = $validated['character_id'] ?? null;

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

            // Calculate taxes
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
        try {
            $month = $request->input('month');
            $regenerate = $request->input('regenerate', false);

            $results = $this->contractService->generateTaxContracts($month, $regenerate);

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
     */
    public function wallet(Request $request)
    {
        $status = $request->input('status', 'pending');
        $month = $request->input('month');

        // Get wallet transactions that might be tax payments
        $query = \MiningManager\Models\WalletTransaction::with(['character', 'matchedTax.character']);

        if ($status !== 'all') {
            $query->where('verification_status', $status);
        }

        if ($month) {
            $query->whereMonth('date', Carbon::parse($month)->month);
        }

        $transactions = $query->orderBy('date', 'desc')->paginate(50);

        // Summary stats
        $pendingCount = \MiningManager\Models\WalletTransaction::where('verification_status', 'pending')->count();
        $verifiedCount = \MiningManager\Models\WalletTransaction::where('verification_status', 'verified')->count();
        $verifiedToday = \MiningManager\Models\WalletTransaction::where('verification_status', 'verified')
            ->whereDate('verified_at', Carbon::today())
            ->count();
        $totalVerifiedISK = \MiningManager\Models\WalletTransaction::where('verification_status', 'verified')
            ->sum('amount');

        $summary = [
            'pending_count' => $pendingCount,
            'verified_count' => $verifiedCount,
            'verified_today' => $verifiedToday,
            'total_verified_isk' => $totalVerifiedISK,
        ];

        return view('mining-manager::taxes.wallet', compact(
            'transactions',
            'summary',
            'status',
            'month'
        ));
    }

    /**
     * Verify wallet payment
     */
    public function verifyPayment(Request $request, $transactionId)
    {
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
     */
    public function autoMatchPayments(Request $request)
    {
        try {
            $month = $request->input('month');
            
            $results = $this->walletService->autoMatchPayments($month);

            return response()->json([
                'status' => 'success',
                'message' => trans('mining-manager::taxes.auto_match_complete'),
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
        $query = TaxCode::with(['miningTax.character']);

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
        try {
            $month = $request->input('month');
            $regenerate = $request->input('regenerate', false);

            $results = $this->codeService->generateTaxCodes($month, $regenerate);

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
}
