<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Tax\TaxCalculationService;
use MiningManager\Services\Tax\WalletTransferService;
use MiningManager\Services\Tax\TaxCodeGeneratorService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Character\CharacterInfoService;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Http\Controllers\Traits\EnrichesCharacterData;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Models\TaxInvoice;
use MiningManager\Models\TaxCode;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TaxController extends Controller
{
    use EnrichesCharacterData;

    protected $taxService;
    protected $walletService;
    protected $codeService;
    protected $settingsService;
    protected $characterInfoService;
    protected $oreValuationService;

    public function __construct(
        TaxCalculationService $taxService,
        WalletTransferService $walletService,
        TaxCodeGeneratorService $codeService,
        SettingsManagerService $settingsService,
        CharacterInfoService $characterInfoService,
        OreValuationService $oreValuationService
    ) {
        $this->taxService = $taxService;
        $this->walletService = $walletService;
        $this->codeService = $codeService;
        $this->settingsService = $settingsService;
        $this->characterInfoService = $characterInfoService;
        $this->oreValuationService = $oreValuationService;
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
            $this->walletService->setCorporationContext($corporationId);
            $this->codeService->setCorporationContext($corporationId);
        }
    }

    /**
     * Check if the current user has admin permissions.
     */
    private function isAdmin(): bool
    {
        return auth()->user()?->can('mining-manager.admin') ?? false;
    }

    /**
     * Check if the current user has director permissions (includes admin).
     */
    private function isDirector(): bool
    {
        return $this->isAdmin() || (auth()->user()?->can('mining-manager.director') ?? false);
    }

    /**
     * Check if the current user has member permissions (includes director/admin).
     */
    private function isMember(): bool
    {
        return $this->isDirector() || (auth()->user()?->can('mining-manager.member') ?? false);
    }

    /**
     * Check if the current user should see all corporation data.
     * Admins always see all. Directors see all when toggled on.
     */
    private function isViewingAll(): bool
    {
        return $this->isAdmin() || ($this->isDirector() && session('mining-manager.view-all', false));
    }

    /**
     * Toggle between viewing own data and all corporation data (directors only).
     */
    public function toggleView(Request $request)
    {
        $current = session('mining-manager.view-all', false);
        session(['mining-manager.view-all' => !$current]);

        $message = !$current
            ? trans('mining-manager::taxes.switched_to_all_data')
            : trans('mining-manager::taxes.switched_to_my_data');

        return redirect()->back()->with('success', $message);
    }

    /**
     * Get feature flags from settings for view visibility.
     */
    private function getFeatureFlags(): array
    {
        return [
            'tax_tracking' => (bool) $this->settingsService->getSetting('features.enable_tax_tracking', true),
            'wallet_verification' => (bool) $this->settingsService->getSetting('features.verify_wallet_transactions', true),
            'tax_codes' => (bool) $this->settingsService->getSetting('tax_rates.auto_generate_tax_codes', true),
            'reminders' => (bool) $this->settingsService->getSetting('tax_rates.send_tax_reminders', false),
        ];
    }

    /**
     * Get all character IDs linked to the authenticated user.
     */
    private function getUserCharacterIds(): array
    {
        $user = auth()->user();
        if (!$user) {
            return [];
        }

        $ids = $user->characters->pluck('character_id')->toArray();
        if (empty($ids) && $user->main_character_id) {
            $ids = [$user->main_character_id];
        }

        return $ids;
    }

    /**
     * Get character IDs relevant for tax queries.
     * Always uses account-level tax — returns only the main character ID
     * since taxes are consolidated under the main character.
     */
    private function getUserTaxCharacterIds(): array
    {
        $characterIds = $this->getUserCharacterIds();
        $mainId = auth()->user()->main_character_id;
        return $mainId ? [$mainId] : $characterIds;
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
        $query = MiningTax::with(['character', 'affiliation', 'taxCodes', 'taxInvoices']);

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

        // Scope data: members/directors (default) see own data; admin/directors (toggled) see all
        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();
        $taxCharacterIds = !$viewAll ? $this->getUserTaxCharacterIds() : [];
        if (!$viewAll) {
            $query->whereIn('character_id', $taxCharacterIds);
        }

        $taxes = $query->orderBy('month', 'desc')
            ->orderBy('character_id')
            ->paginate(50);

        // Enrich with character names and corporation names
        $this->enrichPaginatorWithCharacterInfo($taxes, $this->characterInfoService);

        // Summary statistics - split by corp/guest if moon owner is configured
        // Helper to apply user scoping to summary queries
        $scopeSummary = function($query) use ($viewAll, $taxCharacterIds) {
            if (!$viewAll && !empty($taxCharacterIds)) {
                $query->whereIn('character_id', $taxCharacterIds);
            }
            return $query;
        };

        $summaryQuery = MiningTax::query();
        $corpSummaryQuery = null;
        $guestSummaryQuery = null;

        if ($moonOwnerCorpId && $viewAll) {
            // Corp vs guest breakdown only shown to directors
            $corpSummaryQuery = MiningTax::whereIn('character_id', function($q) use ($moonOwnerCorpId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', $moonOwnerCorpId);
            });

            $guestSummaryQuery = MiningTax::whereIn('character_id', function($q) use ($moonOwnerCorpId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', '!=', $moonOwnerCorpId);
            });
        }

        $totalOwed = $scopeSummary(MiningTax::where('status', 'unpaid'))->sum('amount_owed');
        $totalOverdue = $scopeSummary(MiningTax::where('status', 'overdue'))->sum('amount_owed');
        $collectedThisMonth = $scopeSummary(MiningTax::where('status', 'paid')
            ->whereMonth('paid_at', Carbon::now()->month))->sum('amount_paid');
        $unpaidCount = $scopeSummary(MiningTax::where('status', 'unpaid'))->count();
        $overdueCount = $scopeSummary(MiningTax::where('status', 'overdue'))->count();
        $paidCount = $scopeSummary(MiningTax::where('status', 'paid')
            ->whereMonth('paid_at', Carbon::now()->month))->count();

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

        $features = $this->getFeatureFlags();

        return view('mining-manager::taxes.index', compact(
            'taxes',
            'summary',
            'paymentMethod',
            'corporations',
            'status',
            'month',
            'corporationId',
            'minerType',
            'moonOwnerCorpId',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features'
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

        $sourceSettings = [
            'source' => $this->settingsService->getSetting('general.data_source', 'archived'),
        ];

        $paymentSettings = $this->settingsService->getPaymentSettings();

        // Get tracking data for current month (default: archived/daily summaries)
        $dataSource = $sourceSettings['source'] ?? 'archived';
        $liveTracking = $this->getLiveTrackingData($dataSource);

        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();
        $features = $this->getFeatureFlags();

        return view('mining-manager::taxes.calculate', compact(
            'corporations',
            'years',
            'sourceSettings',
            'paymentSettings',
            'liveTracking',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features'
        ));
    }

    /**
     * Get mining tracking data for current month
     * Uses daily summaries for accurate totals (archived mode)
     * Recent entries loaded separately for the detail table display
     *
     * @param string $mode 'archived' (default) or 'live'
     * @return array
     */
    protected function getLiveTrackingData(string $mode = 'archived'): array
    {
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $currentMonth = now()->startOfMonth();

        // Get character IDs for the corporation
        $characterQuery = MiningLedger::where('date', '>=', $currentMonth);
        if ($moonOwnerCorpId) {
            $characterQuery->whereIn('character_id', function($q) use ($moonOwnerCorpId) {
                $q->select('character_id')
                    ->from('character_affiliations')
                    ->where('corporation_id', $moonOwnerCorpId);
            });
        }
        $characterIds = $characterQuery->distinct()->pluck('character_id')->toArray();

        if (empty($characterIds)) {
            return [
                'has_data' => false,
                'mode' => $mode,
                'entries' => [],
                'total_value' => 0,
                'estimated_tax' => 0,
                'character_count' => 0,
                'month' => $currentMonth->format('F Y'),
            ];
        }

        // === SUMMARY TOTALS ===
        if ($mode === 'live') {
            $summaryData = $this->getLiveSummaryData($characterIds, $currentMonth);
        } else {
            // Archived mode: use pre-calculated daily summaries (fast and accurate)
            $summaryData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
                ->where('date', '>=', $currentMonth->format('Y-m-d'))
                ->where('date', '<=', now()->format('Y-m-d'))
                ->selectRaw('SUM(total_value) as total_value, SUM(total_tax) as total_tax, COUNT(DISTINCT character_id) as character_count')
                ->first();
        }

        $totalValue = (float) ($summaryData->total_value ?? 0);
        $estimatedTax = (float) ($summaryData->total_tax ?? 0);
        $characterCount = (int) ($summaryData->character_count ?? count($characterIds));

        // === DETAIL ENTRIES (limited for display) ===
        $entryQuery = MiningLedger::where('date', '>=', $currentMonth)
            ->whereIn('character_id', $characterIds)
            ->orderBy('date', 'desc')
            ->limit(100);
        $entries = $entryQuery->get();

        // Batch-resolve character names
        $allCharIds = $entries->pluck('character_id')->unique()->toArray();
        $charactersInfo = $this->characterInfoService->getBatchCharacterInfo($allCharIds);

        $mainCharIds = collect($charactersInfo)
            ->pluck('main_character_id')
            ->filter()
            ->unique()
            ->diff(array_keys($charactersInfo))
            ->values()
            ->toArray();

        if (!empty($mainCharIds)) {
            $mainCharsInfo = $this->characterInfoService->getBatchCharacterInfo($mainCharIds);
            $charactersInfo = array_replace($charactersInfo, $mainCharsInfo);
        }

        // Batch-resolve ore type names
        $typeIds = $entries->pluck('type_id')->unique()->toArray();
        $typeNames = DB::table('invTypes')
            ->whereIn('typeID', $typeIds)
            ->pluck('typeName', 'typeID')
            ->toArray();

        // Build account grouping map
        $accountGroups = [];
        foreach ($charactersInfo as $charId => $charInfo) {
            $mainId = $charInfo['main_character_id'] ?? $charId;
            if (!isset($accountGroups[$mainId])) {
                $accountGroups[$mainId] = [
                    'main_character_id' => $mainId,
                    'main_character_name' => $charactersInfo[$mainId]['name'] ?? "Account {$mainId}",
                    'characters' => [],
                ];
            }
            if (!in_array($charId, $accountGroups[$mainId]['characters'])) {
                $accountGroups[$mainId]['characters'][] = $charId;
            }
        }

        // Map entries with enriched data
        $mappedEntries = $entries->map(function($entry) use ($charactersInfo, $accountGroups, $typeNames) {
            $charInfo = $charactersInfo[$entry->character_id] ?? null;
            $mainId = $charInfo['main_character_id'] ?? $entry->character_id;

            return [
                'date' => $entry->date,
                'character_id' => $entry->character_id,
                'character' => [
                    'name' => $charInfo['name'] ?? "Character {$entry->character_id}",
                    'is_registered' => $charInfo['is_registered'] ?? false,
                ],
                'main_character_id' => $mainId,
                'main_character_name' => $accountGroups[$mainId]['main_character_name'] ?? "Account {$mainId}",
                'type_id' => $entry->type_id,
                'ore_name' => $typeNames[$entry->type_id] ?? "Type {$entry->type_id}",
                'quantity' => $entry->quantity,
                'volume' => $entry->volume ?? 0,
                'total_value' => (float) ($entry->total_value ?? 0),
                'tax_amount' => (float) ($entry->tax_amount ?? 0),
                'event_tax' => (float) ($entry->event_tax_amount ?? 0),
            ];
        })->toArray();

        return [
            'has_data' => true,
            'mode' => $mode,
            'entries' => $mappedEntries,
            'account_groups' => $accountGroups,
            'total_value' => $totalValue,
            'estimated_tax' => $estimatedTax,
            'character_count' => $characterCount,
            'account_count' => count($accountGroups),
            'month' => $currentMonth->format('F Y'),
        ];
    }

    /**
     * Get live summary data by recalculating from mining ledger with current cached prices
     * Recalculates ore values using OreValuationService, then derives tax proportionally
     *
     * @return object with total_value, total_tax, character_count
     */
    protected function getLiveSummaryData(array $characterIds, Carbon $currentMonth): object
    {
        // Get all processed ledger entries for the current month
        $entries = MiningLedger::whereIn('character_id', $characterIds)
            ->where('date', '>=', $currentMonth->format('Y-m-d'))
            ->where('date', '<=', now()->format('Y-m-d'))
            ->whereNotNull('processed_at')
            ->get();

        $totalValue = 0;
        $totalTax = 0;
        $activeCharacters = [];

        foreach ($entries as $entry) {
            $storedValue = (float) ($entry->total_value ?? 0);
            $storedTax = (float) ($entry->tax_amount ?? 0);

            // Recalculate value using current cached prices
            try {
                $valuation = $this->oreValuationService->calculateOreValue($entry->type_id, $entry->quantity);
                $liveValue = (float) ($valuation['total_value'] ?? 0);
            } catch (\Exception $e) {
                $liveValue = $storedValue; // fallback to stored if valuation fails
            }

            // Derive tax proportionally: if stored value had X% tax, apply same ratio to live value
            if ($storedValue > 0 && $storedTax > 0) {
                $taxRatio = $storedTax / $storedValue;
                $liveTax = $liveValue * $taxRatio;
            } else {
                // No stored tax ratio — use stored tax_amount as-is
                $liveTax = $storedTax;
            }

            $totalValue += $liveValue;
            $totalTax += $liveTax;
            $activeCharacters[$entry->character_id] = true;
        }

        return (object) [
            'total_value' => $totalValue,
            'total_tax' => $totalTax,
            'character_count' => count($activeCharacters),
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
            // If a specific character is requested, use recalculateTax instead
            if ($characterId) {
                $taxAmount = $this->taxService->recalculateTax((int) $characterId, $month);
                $results = [
                    'method' => 'individual',
                    'count' => $taxAmount > 0 ? 1 : 0,
                    'total' => $taxAmount,
                    'errors' => [],
                ];
            } else {
                $results = $this->taxService->calculateMonthlyTaxes($month, $recalculate);
            }

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
     * Display wallet verification page
     * TODO: Implement WalletTransaction model and wallet verification feature
     */
    public function wallet(Request $request)
    {
        // Check if wallet verification feature is enabled
        $featureFlags = $this->getFeatureFlags();
        if (!$featureFlags['wallet_verification']) {
            return redirect()->route('mining-manager.taxes.index')
                ->with('warning', trans('mining-manager::taxes.feature_disabled'));
        }

        $status = $request->input('status', 'pending');
        $month = $request->input('month');
        $days = $request->input('days', 30);
        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();

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
                'stats' => [
                    'pending' => 0,
                    'verified' => 0,
                    'mismatched' => 0,
                    'total_amount' => 0,
                ],
                'status' => $status,
                'month' => $month,
                'corporationId' => null,
                'isAdmin' => $isAdmin,
                'isDirector' => $isDirector,
                'viewAll' => $viewAll,
                'features' => $this->getFeatureFlags(),
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

        $stats = [
            'pending' => $pendingCount,
            'verified' => $verifiedCount,
            'mismatched' => $unmatchedDonations->count(),
            'total_amount' => $totalVerifiedIsk,
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

        $features = $this->getFeatureFlags();

        return view('mining-manager::taxes.wallet', compact(
            'transactions',
            'stats',
            'status',
            'month',
            'corporationId',
            'paginatedTransactions',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features'
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
     * Supports both accumulated (account) and individual (per-character) tax modes
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function myTaxes(Request $request)
    {
        $user = auth()->user();

        // Get all character IDs for this user
        $characterIds = $user->characters->pluck('character_id')->toArray();

        // Always use account-level tax
        $taxMethod = 'account';
        $mainCharacterId = $user->main_character_id ?? ($characterIds[0] ?? null);

        // Get account characters info
        $accountCharacters = collect();
        if (!empty($characterIds)) {
            $charInfos = $this->characterInfoService->getBatchCharacterInfo($characterIds);
            $accountCharacters = collect($charInfos);
        }
        $mainCharacterName = $accountCharacters[$mainCharacterId]['name'] ?? 'Unknown';

        // Get payment method from settings
        $paymentMethod = $this->settingsService->getPaymentSettings()['method'];

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
                'currentTax' => null,
                'totalTaxPaid' => 0,
                'onTimePayments' => 0,
                'latePayments' => 0,
                'status' => $request->input('status', 'all'),
                'month' => $request->input('month'),
                'paymentMethod' => $paymentMethod,
                'taxMethod' => $taxMethod,
                'accountCharacters' => $accountCharacters,
                'mainCharacterName' => $mainCharacterName,
                'currentMonthBreakdown' => [],
                'isAdmin' => $this->isAdmin(),
                'isDirector' => $this->isDirector(),
                'viewAll' => $this->isViewingAll(),
                'features' => $this->getFeatureFlags(),
            ]);
        }

        // Determine which character IDs to query for taxes
        if ($taxMethod === 'account') {
            // In accumulated mode, taxes are stored under the main character
            $taxCharacterIds = [$mainCharacterId];
        } else {
            // In individual mode, each character has own tax records
            $taxCharacterIds = $characterIds;
        }

        // Get taxes for user's characters
        $status = $request->input('status', 'all');
        $month = $request->input('month');

        $query = MiningTax::with(['character', 'taxCodes', 'taxInvoices'])
            ->whereIn('character_id', $taxCharacterIds);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($month) {
            $query->where('month', Carbon::parse($month)->format('Y-m-01'));
        }

        $taxHistory = $query->orderBy('month', 'desc')
            ->orderBy('character_id')
            ->paginate(25);

        // Personal summary statistics (query all user characters for mining data)
        $totalOwed = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'unpaid')
            ->sum('amount_owed');

        $totalOverdue = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'overdue')
            ->sum('amount_owed');

        $paidThisMonth = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'paid')
            ->whereMonth('paid_at', Carbon::now()->month)
            ->sum('amount_paid');

        $unpaidCount = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'unpaid')
            ->count();

        $overdueCount = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'overdue')
            ->count();

        $summary = [
            'total_owed' => $totalOwed,
            'overdue_amount' => $totalOverdue,
            'paid_this_month' => $paidThisMonth,
            'unpaid_count' => $unpaidCount,
            'overdue_count' => $overdueCount,
        ];

        // Get current month's tax for display
        $currentMonth = Carbon::now()->startOfMonth()->format('Y-m-01');
        $currentTax = MiningTax::with(['character', 'taxCodes'])
            ->whereIn('character_id', $taxCharacterIds)
            ->where('month', $currentMonth)
            ->first();

        // Get current month mining breakdown per character, per ore type
        $currentMonthBreakdown = $this->getMyTaxBreakdownData($characterIds, Carbon::now()->startOfMonth());

        // Get payment statistics (all time)
        $totalTaxPaid = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'paid')
            ->sum('amount_paid');

        $onTimePayments = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereNotNull('due_date')
            ->whereColumn('paid_at', '<=', 'due_date')
            ->count();

        $latePayments = MiningTax::whereIn('character_id', $taxCharacterIds)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereNotNull('due_date')
            ->whereColumn('paid_at', '>', 'due_date')
            ->count();

        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();
        $features = $this->getFeatureFlags();

        return view('mining-manager::taxes.my-taxes', compact(
            'taxHistory',
            'summary',
            'currentTax',
            'totalTaxPaid',
            'onTimePayments',
            'latePayments',
            'status',
            'month',
            'paymentMethod',
            'taxMethod',
            'accountCharacters',
            'mainCharacterName',
            'currentMonthBreakdown',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features'
        ));
    }

    /**
     * Get mining breakdown data for a user's characters for a given month.
     *
     * @param array $characterIds
     * @param Carbon $month
     * @return array Grouped by character_id
     */
    protected function getMyTaxBreakdownData(array $characterIds, Carbon $month): array
    {
        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();
        $breakdowns = [];

        foreach ($characterIds as $characterId) {
            try {
                $breakdown = $this->taxService->getTaxBreakdown($characterId, $startDate, $endDate);
                if (!empty($breakdown['breakdown'])) {
                    $charInfo = $this->characterInfoService->getCharacterInfo($characterId);
                    $breakdowns[$characterId] = [
                        'character_name' => $charInfo['name'] ?? "Character {$characterId}",
                        'character_id' => $characterId,
                        'breakdown' => $breakdown['breakdown'],
                        'total_value' => $breakdown['total_value'],
                        'total_tax' => $breakdown['total_tax'],
                    ];
                }
            } catch (\Exception $e) {
                Log::debug("Mining Manager: Error getting breakdown for character {$characterId}: " . $e->getMessage());
            }
        }

        return $breakdowns;
    }

    /**
     * AJAX endpoint: Get mining breakdown for a given month
     *
     * @param Request $request
     * @param string|null $month
     * @return \Illuminate\Http\JsonResponse
     */
    public function myTaxBreakdown(Request $request, $month = null)
    {
        $user = auth()->user();
        $characterIds = $user->characters->pluck('character_id')->toArray();

        if (empty($characterIds)) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $monthDate = $month ? Carbon::parse($month)->startOfMonth() : Carbon::now()->startOfMonth();
        $breakdowns = $this->getMyTaxBreakdownData($characterIds, $monthDate);

        return response()->json([
            'status' => 'success',
            'data' => $breakdowns,
            'month' => $monthDate->format('F Y'),
        ]);
    }

    /**
     * Display tax codes management page
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function codes(Request $request)
    {
        // Check if tax codes feature is enabled
        $features = $this->getFeatureFlags();
        if (!$features['tax_codes']) {
            return redirect()->route('mining-manager.taxes.index')
                ->with('warning', trans('mining-manager::taxes.feature_disabled'));
        }

        $status = $request->input('status', 'active');
        $search = $request->input('search');
        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();

        // Build query
        $query = TaxCode::with(['character', 'miningTax']);

        // Scope: only show own codes unless viewing all
        $taxCharacterIds = [];
        if (!$viewAll) {
            $taxCharacterIds = $this->getUserTaxCharacterIds();
            $query->whereIn('character_id', $taxCharacterIds);
        }

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

        // Enrich with character names
        $this->enrichPaginatorWithCharacterInfo($taxCodes, $this->characterInfoService);

        // Summary statistics (scoped unless viewing all)
        $summaryBase = TaxCode::query();
        if (!$viewAll && !empty($taxCharacterIds)) {
            $summaryBase = TaxCode::whereIn('character_id', $taxCharacterIds);
        }
        $activeCount = (clone $summaryBase)->where('status', 'active')->count();
        $usedCount = (clone $summaryBase)->where('status', 'used')->count();
        $expiredCount = (clone $summaryBase)->where('status', 'expired')->count();

        $summary = [
            'active_count' => $activeCount,
            'used_count' => $usedCount,
            'expired_count' => $expiredCount,
        ];

        $features = $this->getFeatureFlags();

        // Get tax code prefix from settings for display
        $taxCodePrefix = $this->settingsService->getSetting('tax_rates.tax_code_prefix', 'TAX-');

        return view('mining-manager::taxes.codes', compact(
            'taxCodes',
            'summary',
            'status',
            'search',
            'taxCodePrefix',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features'
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
     * Accepts ?mode=archived (default) or ?mode=live
     * Live mode checks price cache freshness first
     */
    public function liveTracking(Request $request)
    {
        $mode = $request->input('mode', 'archived');

        // For live mode, check price cache freshness first
        if ($mode === 'live') {
            $cacheDuration = (int) $this->settingsService->getSetting('pricing.cache_duration', 60);
            $freshCount = MiningPriceCache::where('cached_at', '>=', now()->subMinutes($cacheDuration))->count();
            $totalCount = MiningPriceCache::count();

            if ($totalCount > 0 && $freshCount === 0) {
                // All cache entries are stale — trigger refresh and warn user
                try {
                    \Artisan::call('mining-manager:cache-prices', ['--type' => 'all']);
                } catch (\Exception $e) {
                    Log::warning('Failed to trigger price cache refresh: ' . $e->getMessage());
                }

                return response()->json([
                    'status' => 'cache_stale',
                    'message' => 'Price cache is stale (last updated ' . $this->getCacheAge() . ' ago). Refreshing prices — please try again in a few minutes.',
                    'data' => $this->getLiveTrackingData('archived'), // Fall back to archived data while cache refreshes
                ]);
            }
        }

        $data = $this->getLiveTrackingData($mode);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Get human-readable age of the oldest price cache entry
     */
    protected function getCacheAge(): string
    {
        $oldest = MiningPriceCache::orderBy('cached_at', 'desc')->first();
        if (!$oldest || !$oldest->cached_at) {
            return 'never';
        }
        return Carbon::parse($oldest->cached_at)->diffForHumans(null, true);
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
     * Show tax details for a specific tax record
     */
    public function details(Request $request, $taxId)
    {
        $tax = MiningTax::with(['character', 'affiliation', 'taxCodes', 'taxInvoices'])->findOrFail($taxId);

        // Enrich with character/corporation names
        $this->enrichModelWithCharacterInfo($tax, $this->characterInfoService);

        $isAdmin = $this->isAdmin();
        $isDirector = $this->isDirector();
        $viewAll = $this->isViewingAll();

        // Ownership check: members and directors (not viewing all) can only see their own
        if (!$viewAll) {
            $taxCharacterIds = $this->getUserTaxCharacterIds();
            if (!in_array($tax->character_id, $taxCharacterIds)) {
                abort(403, trans('mining-manager::taxes.no_permission_view_tax'));
            }
        }

        // Always use accumulated (per-account) mode
        $taxCalculationMethod = 'accumulated';

        // Get mining breakdown for the tax's character and month
        $startDate = Carbon::parse($tax->month)->startOfMonth();
        $endDate = Carbon::parse($tax->month)->endOfMonth();

        $miningBreakdown = [];
        $miningTotal = 0;

        if ($taxCalculationMethod === 'accumulated') {
            // In accumulated mode, get breakdown for all alts in the account
            // Resolve the tax OWNER's characters (not the viewer's) for correct breakdown
            $characterIds = [$tax->character_id];
            try {
                $ownerUserId = DB::table('refresh_tokens')
                    ->where('character_id', $tax->character_id)
                    ->value('user_id');

                if ($ownerUserId) {
                    $ownerUser = \Seat\Web\Models\User::find($ownerUserId);
                    if ($ownerUser) {
                        $characterIds = $ownerUser->characters->pluck('character_id')->toArray();
                    }
                }
            } catch (\Exception $e) {
                Log::debug("Mining Manager: Could not resolve tax owner's characters: " . $e->getMessage());
            }

            if (empty($characterIds)) {
                $characterIds = [$tax->character_id];
            }

            foreach ($characterIds as $charId) {
                try {
                    $breakdown = $this->taxService->getTaxBreakdown($charId, $startDate, $endDate);
                    if (!empty($breakdown['breakdown'])) {
                        $charInfo = $this->characterInfoService->getCharacterInfo($charId);
                        foreach ($breakdown['breakdown'] as $ore) {
                            $miningBreakdown[] = [
                                'character_name' => $charInfo['name'] ?? "Character {$charId}",
                                'type_name' => $ore['name'],
                                'category' => $ore['category'],
                                'rarity' => $ore['rarity'],
                                'quantity' => $ore['quantity'],
                                'total_value' => $ore['value'],
                                'tax_rate' => $ore['effective_rate'],
                                'tax_amount' => $ore['tax'],
                                'event_modifier' => $ore['event_modifier'],
                            ];
                        }
                        $miningTotal += $breakdown['total_value'];
                    }
                } catch (\Exception $e) {
                    Log::debug("Mining Manager: Error getting breakdown for char {$charId}: " . $e->getMessage());
                }
            }
        }

        $features = $this->getFeatureFlags();

        return view('mining-manager::taxes.details', compact(
            'tax',
            'miningBreakdown',
            'miningTotal',
            'taxCalculationMethod',
            'isAdmin',
            'isDirector',
            'viewAll',
            'features'
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

            // Scope: only export own taxes unless viewing all
            if (!$this->isViewingAll()) {
                $query->whereIn('character_id', $this->getUserTaxCharacterIds());
            }

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

            if (!in_array($tax->character_id, $userCharacterIds) && !$this->isViewingAll()) {
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
