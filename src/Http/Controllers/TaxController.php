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
        $summary = [
            'total_owed' => MiningTax::where('status', 'unpaid')->sum('amount_owed'),
            'total_overdue' => MiningTax::where('status', 'overdue')->sum('amount_owed'),
            'total_collected_this_month' => MiningTax::where('status', 'paid')
                ->whereMonth('paid_at', Carbon::now()->month)
                ->sum('amount_paid'),
            'unpaid_count' => MiningTax::where('status', 'unpaid')->count(),
            'overdue_count' => MiningTax::where('status', 'overdue')->count(),
        ];

        // Get payment method from settings
        $paymentMethod = $this->settingsService->getPaymentSettings()['method'];

        return view('mining-manager::taxes.index', compact(
            'taxes', 
            'summary', 
            'status', 
            'month', 
            'corporationId',
            'paymentMethod'
        ));
    }

    /**
     * Display tax calculation page with live tracking
     */
    public function calculate()
    {
        // Prepare month/year options
        $months = [];
        $currentMonth = Carbon::now();
        for ($i = 0; $i < 24; $i++) {
            $month = $currentMonth->copy()->subMonths($i);
            $months[] = [
                'value' => $month->format('Y-m'),
                'label' => $month->format('F Y'),
            ];
        }

        // Get years
        $years = range(Carbon::now()->year, Carbon::now()->year - 5);

        // Get corporations
        $corporations = CorporationInfo::whereHas('characters')->get();

        // Get current calculation settings
        $generalSettings = $this->settingsService->getGeneralSettings();
        $paymentSettings = $this->settingsService->getPaymentSettings();
        $sourceSettings = config('mining-manager.calculation_source');

        // Get live tracking data for current month
        $liveTracking = $this->getCurrentMonthLiveTracking();

        return view('mining-manager::taxes.calculate', compact(
            'months',
            'years',
            'corporations',
            'generalSettings',
            'paymentSettings',
            'sourceSettings',
            'liveTracking'
        ));
    }

    /**
     * Process tax calculation request
     */
    public function processCalculation(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer',
            'corporation_id' => 'nullable|integer',
            'character_id' => 'nullable|integer',
            'calculation_method' => 'required|in:accumulated,individually',
            'data_source' => 'required|in:archived,live',
            'recalculate' => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            $month = Carbon::create($request->year, $request->month, 1);
            $recalculate = $request->boolean('recalculate', false);

            // Update calculation method temporarily if different
            $currentMethod = $this->settingsService->getGeneralSettings()['tax_calculation_method'];
            if ($request->calculation_method !== $currentMethod) {
                $this->settingsService->updateSetting('general.tax_calculation_method', $request->calculation_method);
            }

            // Calculate taxes based on scope
            if ($request->character_id) {
                // Calculate for specific character
                $result = $this->taxService->calculateCharacterTaxForMonth(
                    $request->character_id,
                    $month,
                    $recalculate
                );
                $scope = 'character';
            } elseif ($request->corporation_id) {
                // Calculate for specific corporation
                $result = $this->taxService->calculateCorporationTaxForMonth(
                    $request->corporation_id,
                    $month,
                    $recalculate
                );
                $scope = 'corporation';
            } else {
                // Calculate for all characters
                $result = $this->taxService->calculateMonthlyTaxes($month, $recalculate);
                $scope = 'all';
            }

            // Generate payment mechanisms based on active method
            $paymentMethod = $this->settingsService->getPaymentSettings()['method'];
            
            if ($paymentMethod === 'contract') {
                // Generate contracts
                $contractResult = $this->generatePaymentContracts($month, $request->corporation_id, $request->character_id);
                $result['contracts'] = $contractResult;
            } else {
                // Generate tax codes
                $codeResult = $this->generatePaymentCodes($month, $request->corporation_id, $request->character_id);
                $result['tax_codes'] = $codeResult;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tax calculation completed successfully',
                'data' => [
                    'scope' => $scope,
                    'month' => $month->format('F Y'),
                    'count' => $result['count'],
                    'total' => $result['total'],
                    'errors' => $result['errors'] ?? [],
                    'payment_method' => $paymentMethod,
                    'payment_data' => $result[$paymentMethod === 'contract' ? 'contracts' : 'tax_codes'] ?? [],
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tax calculation failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Tax calculation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Regenerate payment mechanisms (contracts or tax codes)
     */
    public function regeneratePayments(Request $request)
    {
        $request->validate([
            'month' => 'required|date_format:Y-m',
            'corporation_id' => 'nullable|integer',
            'character_id' => 'nullable|integer',
            'payment_method' => 'required|in:contract,wallet',
        ]);

        try {
            DB::beginTransaction();

            $month = Carbon::parse($request->month);

            if ($request->payment_method === 'contract') {
                $result = $this->generatePaymentContracts(
                    $month, 
                    $request->corporation_id, 
                    $request->character_id,
                    true // Force regenerate
                );
                $message = 'Contracts regenerated successfully';
            } else {
                $result = $this->generatePaymentCodes(
                    $month, 
                    $request->corporation_id, 
                    $request->character_id,
                    true // Force regenerate
                );
                $message = 'Tax codes regenerated successfully';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Regeneration failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get live tracking data for current month
     */
    public function getLiveTracking()
    {
        $data = $this->getCurrentMonthLiveTracking();
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Manual payment marking
     */
    public function markAsPaid(Request $request, $taxId)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'reason' => 'required_if:manual,true|string|max:500',
            'paid_at' => 'nullable|date',
        ]);

        try {
            $tax = MiningTax::findOrFail($taxId);

            $tax->update([
                'amount_paid' => $request->amount,
                'status' => $request->amount >= $tax->amount_owed ? 'paid' : 'partial',
                'paid_at' => $request->paid_at ? Carbon::parse($request->paid_at) : Carbon::now(),
                'payment_notes' => $request->reason,
            ]);

            Log::info("Tax #{$taxId} manually marked as paid", [
                'amount' => $request->amount,
                'user' => auth()->user()->name,
                'reason' => $request->reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tax marked as paid successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * View tax details
     */
    public function show($taxId)
    {
        $tax = MiningTax::with([
            'character',
            'taxCode',
            'invoice',
            'miningActivity' => function($query) {
                $query->orderBy('date', 'desc');
            }
        ])->findOrFail($taxId);

        return view('mining-manager::taxes.show', compact('tax'));
    }

    /**
     * Delete tax record
     */
    public function destroy($taxId)
    {
        try {
            $tax = MiningTax::findOrFail($taxId);
            
            // Delete associated records
            if ($tax->taxCode) {
                $tax->taxCode->delete();
            }
            if ($tax->invoice) {
                $tax->invoice->delete();
            }

            $tax->delete();

            Log::info("Tax #{$taxId} deleted by " . auth()->user()->name);

            return response()->json([
                'success' => true,
                'message' => 'Tax record deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export taxes to CSV
     */
    public function export(Request $request)
    {
        $month = $request->input('month');
        $corporationId = $request->input('corporation_id');

        $query = MiningTax::with('character');

        if ($month) {
            $query->where('month', Carbon::parse($month)->format('Y-m-01'));
        }

        if ($corporationId) {
            $query->whereHas('character', function($q) use ($corporationId) {
                $q->where('corporation_id', $corporationId);
            });
        }

        $taxes = $query->get();

        $filename = 'mining-taxes-' . ($month ? $month : 'all') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($taxes) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Character', 'Corporation', 'Month', 'Amount Owed', 'Amount Paid', 'Status', 'Tax Code', 'Calculated At', 'Paid At']);

            foreach ($taxes as $tax) {
                fputcsv($file, [
                    $tax->character->name ?? 'Unknown',
                    $tax->character->corporation->name ?? 'Unknown',
                    $tax->month,
                    $tax->amount_owed,
                    $tax->amount_paid,
                    $tax->status,
                    $tax->taxCode->code ?? 'N/A',
                    $tax->calculated_at,
                    $tax->paid_at ?? 'Not Paid',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * HELPER: Generate payment contracts for taxes
     */
    private function generatePaymentContracts($month, $corporationId = null, $characterId = null, $forceRegenerate = false)
    {
        $query = MiningTax::where('month', $month->format('Y-m-01'))
            ->where('status', '!=', 'paid');

        if ($characterId) {
            $query->where('character_id', $characterId);
        } elseif ($corporationId) {
            $query->whereHas('character', function($q) use ($corporationId) {
                $q->where('corporation_id', $corporationId);
            });
        }

        $taxes = $query->get();
        $generated = 0;
        $errors = [];

        foreach ($taxes as $tax) {
            try {
                // Check if contract already exists
                if (!$forceRegenerate && $tax->invoice && $tax->invoice->status !== 'expired') {
                    continue;
                }

                // Delete old invoice if regenerating
                if ($forceRegenerate && $tax->invoice) {
                    $tax->invoice->delete();
                }

                $this->contractService->createTaxContract($tax);
                $generated++;
            } catch (\Exception $e) {
                $errors[] = [
                    'character_id' => $tax->character_id,
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
     * HELPER: Generate tax codes for wallet payments
     */
    private function generatePaymentCodes($month, $corporationId = null, $characterId = null, $forceRegenerate = false)
    {
        $query = MiningTax::where('month', $month->format('Y-m-01'))
            ->where('status', '!=', 'paid');

        if ($characterId) {
            $query->where('character_id', $characterId);
        } elseif ($corporationId) {
            $query->whereHas('character', function($q) use ($corporationId) {
                $q->where('corporation_id', $corporationId);
            });
        }

        $taxes = $query->get();
        $generated = 0;
        $errors = [];

        foreach ($taxes as $tax) {
            try {
                // Check if code already exists
                if (!$forceRegenerate && $tax->taxCode && !$tax->taxCode->used) {
                    continue;
                }

                // Mark old code as invalidated if regenerating
                if ($forceRegenerate && $tax->taxCode) {
                    $tax->taxCode->update(['invalidated' => true]);
                }

                $this->codeService->generateTaxCode($tax);
                $generated++;
            } catch (\Exception $e) {
                $errors[] = [
                    'character_id' => $tax->character_id,
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
     * HELPER: Get live tracking data for current month
     */
    private function getCurrentMonthLiveTracking()
    {
        $startDate = Carbon::now()->startOfMonth();
        $endDate = Carbon::now();

        // Get mining activity
        $miningData = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at')
            ->with('character')
            ->get();

        if ($miningData->isEmpty()) {
            return [
                'has_data' => false,
                'total_value' => 0,
                'estimated_tax' => 0,
                'character_count' => 0,
                'entries' => [],
                'top_miners' => [],
            ];
        }

        // Calculate total value and estimated tax
        $totalValue = 0;
        $characterData = [];

        foreach ($miningData as $entry) {
            $value = $this->taxService->calculateEntryValue($entry);
            $totalValue += $value;

            if (!isset($characterData[$entry->character_id])) {
                $characterData[$entry->character_id] = [
                    'character_id' => $entry->character_id,
                    'character_name' => $entry->character->name ?? 'Unknown',
                    'total_value' => 0,
                    'entries' => [],
                ];
            }

            $characterData[$entry->character_id]['total_value'] += $value;
            $characterData[$entry->character_id]['entries'][] = [
                'date' => $entry->date,
                'type_id' => $entry->type_id,
                'quantity' => $entry->quantity,
                'value' => $value,
            ];
        }

        // Calculate estimated tax
        $taxRate = $this->settingsService->getGeneralSettings()['tax_rates']['ore'] ?? 10.0;
        $estimatedTax = $totalValue * ($taxRate / 100);

        // Get top miners
        uasort($characterData, function($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });

        $topMiners = array_slice($characterData, 0, 10);

        return [
            'has_data' => true,
            'total_value' => $totalValue,
            'estimated_tax' => $estimatedTax,
            'character_count' => count($characterData),
            'entries' => $miningData->take(10)->toArray(), // Latest 10 entries for table
            'top_miners' => $topMiners,
            'month' => $startDate->format('F Y'),
        ];
    }
}
