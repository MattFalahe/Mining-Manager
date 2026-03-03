<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Analytics\DashboardMetricsService;
use MiningManager\Services\Pricing\MarketDataService;
use MiningManager\Services\Character\CharacterInfoService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MonthlyStatistic;
use MiningManager\Models\MiningLedgerDailySummary;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Carbon\Carbon;

class DashboardController extends Controller
{
    protected $metricsService;
    protected $marketDataService;
    protected $characterInfoService;
    protected SettingsManagerService $settingsService;

    public function __construct(
        DashboardMetricsService $metricsService,
        MarketDataService $marketDataService,
        CharacterInfoService $characterInfoService,
        SettingsManagerService $settingsService
    ) {
        $this->metricsService = $metricsService;
        $this->marketDataService = $marketDataService;
        $this->characterInfoService = $characterInfoService;
        $this->settingsService = $settingsService;
    }
    
    /**
     * Display the appropriate dashboard based on user permissions
     *
     * Directors see BOTH their personal stats AND corporation overview
     * Regular members see only their personal stats
     */
    public function index()
    {
        $user = auth()->user();
        $isDirector = $this->hasDirectorPermissions();

        if ($isDirector) {
            // Directors get both personal and corporation data
            return $this->combinedDirectorDashboard();
        }

        return $this->memberDashboard();
    }

    /**
     * Member Dashboard - Shows personal mining stats
     *
     * Performance: Cached for 15 minutes to improve load times
     */
    public function memberDashboard()
    {
        $user = auth()->user();
        $characterIds = $this->getUserCharacterIds($user);

        // Current month stats
        $currentMonthStart = Carbon::now()->startOfMonth();
        $currentMonthEnd = Carbon::now();

        // Last 12 months period
        $last12MonthsStart = Carbon::now()->subMonths(12)->startOfMonth();

        // Cache key based on user ID and current month
        $cacheKey = 'dashboard.member.' . $user->id . '.' . $currentMonthStart->format('Y-m');

        // Cache dashboard data for 30 minutes (1800 seconds)
        $dashboardData = Cache::remember($cacheKey, 1800, function () use ($characterIds, $currentMonthStart, $currentMonthEnd, $last12MonthsStart, $user) {
            // === CURRENT MONTH STATISTICS ===
            $currentMonthStats = $this->getMemberCurrentMonthStats($characterIds, $currentMonthStart, $currentMonthEnd);

            // === LAST 12 MONTHS STATISTICS ===
            $last12MonthsStats = $this->getMemberLast12MonthsStats($characterIds, $last12MonthsStart);

            // === TOP MINER RANKINGS ===
            // Get user's main character for ranking
            $mainCharacterId = $this->getMainCharacterId($user);

            $topMinersAllOre = $this->getTopMinersRanking('all_ore', $currentMonthStart, $currentMonthEnd);
            $topMinersMoonOre = $this->getTopMinersRanking('moon_ore', $currentMonthStart, $currentMonthEnd);

            $userRankAllOre = $this->getUserRank($mainCharacterId, $topMinersAllOre);
            $userRankMoonOre = $this->getUserRank($mainCharacterId, $topMinersMoonOre);

            // === CHARTS DATA ===
            $miningPerformanceChart = $this->getMiningPerformanceLast12Months($characterIds);
            $miningVolumeByGroupChart = $this->getMiningVolumeByGroup($characterIds, $last12MonthsStart);
            $miningByTypeChart = $this->getMiningByType($characterIds, $last12MonthsStart);
            $miningIncomeChart = $this->getMiningIncomeLast12Months($characterIds);

            return [
                'currentMonthStats' => $currentMonthStats,
                'last12MonthsStats' => $last12MonthsStats,
                'topMinersAllOre' => $topMinersAllOre,
                'topMinersMoonOre' => $topMinersMoonOre,
                'userRankAllOre' => $userRankAllOre,
                'userRankMoonOre' => $userRankMoonOre,
                'miningPerformanceChart' => $miningPerformanceChart,
                'miningVolumeByGroupChart' => $miningVolumeByGroupChart,
                'miningByTypeChart' => $miningByTypeChart,
                'miningIncomeChart' => $miningIncomeChart,
            ];
        });

        return view('mining-manager::dashboard.member', $dashboardData);
    }

    /**
     * Director Dashboard - Shows corporation-wide stats
     */
    public function directorDashboard()
    {
        $corporationId = $this->getUserCorporationId();

        // Current month stats
        $currentMonthStart = Carbon::now()->startOfMonth();
        $currentMonthEnd = Carbon::now();

        // Last 12 months period
        $last12MonthsStart = Carbon::now()->subMonths(12)->startOfMonth();

        // === CURRENT MONTH STATISTICS ===
        $currentMonthStats = $this->getDirectorCurrentMonthStats($corporationId, $currentMonthStart, $currentMonthEnd);

        // === LAST 12 MONTHS STATISTICS ===
        $last12MonthsStats = $this->getDirectorLast12MonthsStats($corporationId, $last12MonthsStart);

        // === TOP 5 MINERS (OVERALL) ===
        $topMinersOverallAllOre = $this->getTopMinersOverall($corporationId, 'all_ore', 5);
        $topMinersOverallMoonOre = $this->getTopMinersOverall($corporationId, 'moon_ore', 5);

        // === TOP 5 MINERS (LAST MONTH) ===
        $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();
        
        $topMinersLastMonthAllOre = $this->getTopMinersForPeriod($corporationId, 'all_ore', $lastMonthStart, $lastMonthEnd, 5);
        $topMinersLastMonthMoonOre = $this->getTopMinersForPeriod($corporationId, 'moon_ore', $lastMonthStart, $lastMonthEnd, 5);

        // === CHARTS DATA ===
        $miningPerformanceChart = $this->getCorpMiningPerformanceLast12Months($corporationId);
        $moonMiningPerformanceChart = $this->getCorpMoonMiningPerformanceLast12Months($corporationId);
        $miningTaxChart = $this->getMiningTaxLast12Months($corporationId);
        $eventTaxChart = $this->getEventTaxLast12Months($corporationId);

        return view('mining-manager::dashboard.director', compact(
            'currentMonthStats',
            'last12MonthsStats',
            'topMinersOverallAllOre',
            'topMinersOverallMoonOre',
            'topMinersLastMonthAllOre',
            'topMinersLastMonthMoonOre',
            'miningPerformanceChart',
            'moonMiningPerformanceChart',
            'miningTaxChart',
            'eventTaxChart'
        ));
    }

    /**
     * Combined Director Dashboard - Shows BOTH personal stats AND corporation overview
     * This ensures directors can track their own mining while managing the corporation
     *
     * Performance: Cached for 15 minutes to improve load times
     */
    public function combinedDirectorDashboard()
    {
        $user = auth()->user();
        $characterIds = $this->getUserCharacterIds($user);
        $corporationId = $this->getUserCorporationId();

        // Current month stats
        $currentMonthStart = Carbon::now()->startOfMonth();
        $currentMonthEnd = Carbon::now();

        // Last 12 months period
        $last12MonthsStart = Carbon::now()->subMonths(12)->startOfMonth();

        // Cache key based on user ID, corporation ID and current month
        $cacheKey = 'dashboard.director.' . $user->id . '.' . $corporationId . '.' . $currentMonthStart->format('Y-m');

        // Only compute Personal tab data server-side.
        // Corporation tab data is loaded via AJAX when user clicks the tab.
        $personalCacheKey = 'dashboard.director.personal.' . $user->id . '.' . $currentMonthStart->format('Y-m');

        $dashboardData = Cache::remember($personalCacheKey, 1800, function () use ($characterIds, $currentMonthStart, $currentMonthEnd, $last12MonthsStart, $user) {
            // === PERSONAL STATISTICS (Director's own mining) ===
            $personalCurrentMonthStats = $this->getMemberCurrentMonthStats($characterIds, $currentMonthStart, $currentMonthEnd);
            $personalLast12MonthsStats = $this->getMemberLast12MonthsStats($characterIds, $last12MonthsStart);

            // Personal rankings
            $mainCharacterId = $this->getMainCharacterId($user);
            $topMinersAllOre = $this->getTopMinersRanking('all_ore', $currentMonthStart, $currentMonthEnd);
            $topMinersMoonOre = $this->getTopMinersRanking('moon_ore', $currentMonthStart, $currentMonthEnd);
            $userRankAllOre = $this->getUserRank($mainCharacterId, $topMinersAllOre);
            $userRankMoonOre = $this->getUserRank($mainCharacterId, $topMinersMoonOre);

            // Personal charts
            $personalMiningPerformanceChart = $this->getMiningPerformanceLast12Months($characterIds);
            $personalMiningVolumeByGroupChart = $this->getMiningVolumeByGroup($characterIds, $last12MonthsStart);
            $personalMiningByTypeChart = $this->getMiningByType($characterIds, $last12MonthsStart);
            $personalMiningIncomeChart = $this->getMiningIncomeLast12Months($characterIds);

            return [
                'personalCurrentMonthStats' => $personalCurrentMonthStats,
                'personalLast12MonthsStats' => $personalLast12MonthsStats,
                'topMinersAllOre' => $topMinersAllOre,
                'topMinersMoonOre' => $topMinersMoonOre,
                'userRankAllOre' => $userRankAllOre,
                'userRankMoonOre' => $userRankMoonOre,
                'personalMiningPerformanceChart' => $personalMiningPerformanceChart,
                'personalMiningVolumeByGroupChart' => $personalMiningVolumeByGroupChart,
                'personalMiningByTypeChart' => $personalMiningByTypeChart,
                'personalMiningIncomeChart' => $personalMiningIncomeChart,
            ];
        });

        // Pass corporation tab AJAX URL to view
        $dashboardData['corpTabUrl'] = route('mining-manager.dashboard.tab.corporation');
        $dashboardData['guestTabUrl'] = route('mining-manager.dashboard.tab.guest-miners');

        return view('mining-manager::dashboard.combined-director', $dashboardData);
    }

    /**
     * AJAX endpoint: Corporation tab data for the combined director dashboard.
     *
     * Loaded lazily when the director clicks the Corporation tab,
     * so the page shell renders instantly with only personal data.
     */
    public function getCorporationTabData()
    {
        $corporationId = $this->getUserCorporationId();

        $currentMonthStart = Carbon::now()->startOfMonth();
        $currentMonthEnd = Carbon::now();
        $last12MonthsStart = Carbon::now()->subMonths(12)->startOfMonth();

        $cacheKey = 'dashboard.corp-tab.' . $corporationId . '.' . $currentMonthStart->format('Y-m');

        $data = Cache::remember($cacheKey, 1800, function () use ($corporationId, $currentMonthStart, $currentMonthEnd, $last12MonthsStart) {
            $corpCurrentMonthStats = $this->getDirectorCurrentMonthStats($corporationId, $currentMonthStart, $currentMonthEnd);
            $corpLast12MonthsStats = $this->getDirectorLast12MonthsStats($corporationId, $last12MonthsStart);

            $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
            $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

            $topMinersOverallAllOre = $this->getTopMinersOverall($corporationId, 'all_ore', 5);
            $topMinersOverallMoonOre = $this->getTopMinersOverall($corporationId, 'moon_ore', 5);
            $topMinersLastMonthAllOre = $this->getTopMinersForPeriod($corporationId, 'all_ore', $lastMonthStart, $lastMonthEnd, 5);
            $topMinersLastMonthMoonOre = $this->getTopMinersForPeriod($corporationId, 'moon_ore', $lastMonthStart, $lastMonthEnd, 5);

            $corpMiningPerformanceChart = $this->getCorpMiningPerformanceLast12Months($corporationId);
            $moonMiningPerformanceChart = $this->getCorpMoonMiningPerformanceLast12Months($corporationId);
            $corpMiningByGroupChart = $this->getCorpMiningByGroup($corporationId, $last12MonthsStart);
            $corpMiningByTypeChart = $this->getCorpMiningByType($corporationId, $last12MonthsStart);
            $miningTaxChart = $this->getMiningTaxLast12Months($corporationId);
            $eventTaxChart = $this->getEventTaxLast12Months($corporationId);

            return [
                'corpCurrentMonthStats' => $corpCurrentMonthStats,
                'corpLast12MonthsStats' => $corpLast12MonthsStats,
                'topMinersOverallAllOre' => $topMinersOverallAllOre,
                'topMinersOverallMoonOre' => $topMinersOverallMoonOre,
                'topMinersLastMonthAllOre' => $topMinersLastMonthAllOre,
                'topMinersLastMonthMoonOre' => $topMinersLastMonthMoonOre,
                'corpMiningPerformanceChart' => $corpMiningPerformanceChart,
                'moonMiningPerformanceChart' => $moonMiningPerformanceChart,
                'corpMiningByGroupChart' => $corpMiningByGroupChart,
                'corpMiningByTypeChart' => $corpMiningByTypeChart,
                'miningTaxChart' => $miningTaxChart,
                'eventTaxChart' => $eventTaxChart,
            ];
        });

        return response()->json($data);
    }

    /**
     * AJAX endpoint for the Guest Miners tab.
     * Returns miners who used corp structures but are confirmed to be in other corporations.
     * Accepts optional ?month=YYYY-MM query parameter (defaults to current month).
     */
    public function getGuestMinersTabData(Request $request)
    {
        $corporationId = $this->getUserCorporationId();

        // Parse requested month (defaults to current month)
        $monthInput = $request->input('month');
        $monthDate = $monthInput
            ? Carbon::parse($monthInput . '-01')->startOfMonth()
            : Carbon::now()->startOfMonth();
        $monthKey = $monthDate->format('Y-m');

        $cacheKey = 'dashboard.guest-tab.' . $corporationId . '.' . $monthKey;

        $data = Cache::remember($cacheKey, 1800, function () use ($corporationId, $monthDate) {
            $guestIds = $this->getGuestMinerCharacterIds($corporationId, $monthDate);

            if (empty($guestIds)) {
                return [
                    'guestMiners' => [],
                    'totalValue' => 0,
                    'totalMoonOreValue' => 0,
                    'guestCount' => 0,
                ];
            }

            // Get aggregated mining data per guest from daily summaries for the selected month
            $minerStats = MiningLedgerDailySummary::whereIn('character_id', $guestIds)
                ->whereYear('date', $monthDate->year)
                ->whereMonth('date', $monthDate->month)
                ->selectRaw('
                    character_id,
                    SUM(total_value) as total_value,
                    SUM(moon_ore_value) as moon_ore_value,
                    SUM(total_quantity) as total_quantity,
                    SUM(total_tax) as total_tax,
                    MIN(date) as first_seen,
                    MAX(date) as last_seen,
                    COUNT(DISTINCT date) as active_days
                ')
                ->groupBy('character_id')
                ->orderByDesc(DB::raw('SUM(total_value)'))
                ->get();

            // Resolve character names/corporations in batch
            $charIds = $minerStats->pluck('character_id')->toArray();
            $charInfo = $this->characterInfoService->getBatchCharacterInfo($charIds);

            $guestMiners = [];
            $totalValue = 0;
            $totalMoonOreValue = 0;

            foreach ($minerStats as $stat) {
                $info = $charInfo[$stat->character_id] ?? null;
                $guestMiners[] = [
                    'character_id' => $stat->character_id,
                    'character_name' => $info['name'] ?? "Character {$stat->character_id}",
                    'corporation_name' => $info['corporation_name'] ?? 'Unknown',
                    'total_value' => (float) $stat->total_value,
                    'moon_ore_value' => (float) $stat->moon_ore_value,
                    'total_quantity' => (float) $stat->total_quantity,
                    'total_tax' => (float) $stat->total_tax,
                    'first_seen' => $stat->first_seen,
                    'last_seen' => $stat->last_seen,
                    'active_days' => $stat->active_days,
                    'is_registered' => $info['is_registered'] ?? false,
                ];
                $totalValue += (float) $stat->total_value;
                $totalMoonOreValue += (float) $stat->moon_ore_value;
            }

            return [
                'guestMiners' => $guestMiners,
                'totalValue' => $totalValue,
                'totalMoonOreValue' => $totalMoonOreValue,
                'guestCount' => count($guestMiners),
            ];
        });

        $data['month'] = $monthDate->format('Y-m');

        return response()->json($data);
    }

    /**
     * Get character IDs of guest miners: people who mined at corp structures
     * but are confirmed to be in a DIFFERENT corporation.
     */
    private function getGuestMinerCharacterIds($corporationId, ?Carbon $monthDate = null)
    {
        if (!$corporationId) {
            return [];
        }

        // Gather all characters who mined at corp structures (Sources 2 + 3)
        $allMinerIds = [];

        try {
            $query = DB::table('mining_ledger')
                ->where('corporation_id', $corporationId);
            if ($monthDate) {
                $query->whereYear('date', $monthDate->year)
                      ->whereMonth('date', $monthDate->month);
            }
            $ids = $query->distinct()
                ->pluck('character_id')
                ->toArray();
            $allMinerIds = array_merge($allMinerIds, $ids);
        } catch (\Exception $e) {}

        try {
            $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
            $observerCorpId = $moonOwnerCorpId ?: $corporationId;

            $query = DB::table('corporation_industry_mining_observer_data as d')
                ->join('corporation_industry_mining_observers as o', 'd.observer_id', '=', 'o.observer_id')
                ->where('o.corporation_id', $observerCorpId);
            if ($monthDate) {
                $query->whereYear('d.last_updated', $monthDate->year)
                      ->whereMonth('d.last_updated', $monthDate->month);
            }
            $ids = $query->distinct()
                ->pluck('d.character_id')
                ->toArray();
            $allMinerIds = array_merge($allMinerIds, $ids);
        } catch (\Exception $e) {}

        $allMinerIds = array_values(array_unique($allMinerIds));

        if (empty($allMinerIds)) {
            return [];
        }

        // Keep ONLY characters confirmed to be in a different corporation
        try {
            $guestIds = DB::table('character_affiliations')
                ->whereIn('character_id', $allMinerIds)
                ->where('corporation_id', '!=', $corporationId)
                ->pluck('character_id')
                ->toArray();

            return array_values(array_unique($guestIds));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * API endpoint for live chart updates
     */
    public function getLiveChartData(Request $request)
    {
        $chartType = $request->input('chart_type');
        $isDirector = $this->hasDirectorPermissions();

        if ($isDirector) {
            $corporationId = $this->getUserCorporationId();
            $data = $this->getDirectorChartData($chartType, $corporationId);
        } else {
            $user = auth()->user();
            $characterIds = $this->getUserCharacterIds($user);
            $data = $this->getMemberChartData($chartType, $characterIds);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'updated_at' => now()->toIso8601String()
        ]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get or calculate monthly statistics (uses stored data for closed months)
     *
     * @param int $userId
     * @param array $characterIds
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array|null Returns stored stats if available, null if needs calculation
     */
    private function getStoredMonthlyStats($userId, $characterIds, $startDate, $endDate)
    {
        // Only use stored stats for complete closed months
        $isCurrentMonth = $startDate->isSameMonth(Carbon::now());
        if ($isCurrentMonth) {
            return null; // Current month data changes, calculate live
        }

        // Check if we have stored statistics for this closed month
        $storedStats = MonthlyStatistic::where('user_id', $userId)
            ->where('year', $startDate->year)
            ->where('month', $startDate->month)
            ->where('is_closed', true)
            ->first();

        if ($storedStats) {
            // Return stats in the format expected by the dashboard
            return [
                'total_quantity' => $storedStats->total_quantity,
                'total_value' => $storedStats->total_value,
                'total_isk' => $storedStats->total_value,
                'ore_value' => $storedStats->ore_value,
                'mineral_value' => $storedStats->mineral_value,
                'tax_isk' => $storedStats->tax_owed,
                'mining_days' => $storedStats->mining_days,
                'moon_ore_value' => $storedStats->moon_ore_value,
                'ice_value' => $storedStats->ice_value,
                'gas_value' => $storedStats->gas_value,
                'regular_ore_value' => $storedStats->regular_ore_value,
                'daily_chart_data' => $storedStats->daily_chart_data,
                'ore_type_chart_data' => $storedStats->ore_type_chart_data,
                'top_systems' => $storedStats->top_systems,
            ];
        }

        return null; // No stored stats, need to calculate
    }

    /**
     * Get current month statistics for member
     */
    private function getMemberCurrentMonthStats($characterIds, $startDate, $endDate)
    {
        $stats = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw('
                SUM(total_quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(total_tax) as tax_isk,
                COUNT(DISTINCT date) as mining_days
            ')
            ->first();

        $totalQuantity = $stats->total_quantity ?? 0;
        $totalValue = $stats->total_value ?? 0;

        return [
            'total_quantity' => $totalQuantity,
            'total_volume' => $totalQuantity * 0.1,
            'total_value' => $totalValue,
            'total_isk' => $totalValue,
            'tax_isk' => $stats->tax_isk ?? 0,
            'mining_days' => $stats->mining_days ?? 0,
        ];
    }

    /**
     * Get last 12 months statistics for member (uses stored data)
     */
    private function getMemberLast12MonthsStats($characterIds, $startDate)
    {
        $user = auth()->user();

        // Try to get stored statistics for closed months
        $storedStats = MonthlyStatistic::where('user_id', $user->id)
            ->where('is_closed', true)
            ->where('month_start', '>=', $startDate)
            ->get();

        // If we have stored stats for most months, use them
        if ($storedStats->count() >= 10) {
            $totalQuantity = $storedStats->sum('total_quantity');
            $totalValue = $storedStats->sum('total_value');

            // For current month (not in stored stats), calculate live and add
            $currentMonthStart = Carbon::now()->startOfMonth();
            $currentMonthEnd = Carbon::now();

            $currentMonthSummary = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
                ->whereBetween('date', [$currentMonthStart, $currentMonthEnd])
                ->selectRaw('SUM(total_quantity) as total_quantity, SUM(total_value) as total_value')
                ->first();

            $currentMonthQuantity = $currentMonthSummary->total_quantity ?? 0;
            $currentMonthValue = $currentMonthSummary->total_value ?? 0;

            $totalQuantity += $currentMonthQuantity;
            $totalValue += $currentMonthValue;
            $totalVolume = $totalQuantity * 1000; // Approximate

            $avgPerMonth = $totalValue / 12;

            return [
                'total_quantity' => $totalQuantity,
                'total_volume' => $totalVolume,
                'total_value' => $totalValue,
                'avg_per_month' => $avgPerMonth,
            ];
        }

        // Fallback: Use daily summaries for all months (fast aggregate)
        $summaryData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('
                SUM(total_quantity) as total_quantity,
                SUM(total_value) as total_value
            ')
            ->first();

        $totalQuantity = $summaryData->total_quantity ?? 0;
        $totalValue = $summaryData->total_value ?? 0;
        $totalVolume = $totalQuantity * 0.1;

        // Calculate average per month
        $avgPerMonth = $totalValue / 12;

        return [
            'total_quantity' => $totalQuantity,
            'total_volume' => $totalVolume,
            'total_value' => $totalValue,
            'avg_per_month' => $avgPerMonth,
        ];
    }

    /**
     * Get current month statistics for director
     */
    private function getDirectorCurrentMonthStats($corporationId, $startDate, $endDate)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        $stats = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw('
                SUM(total_value) as all_ore_value,
                SUM(total_quantity) as all_ore_quantity,
                SUM(moon_ore_value) as moon_ore_value,
                COUNT(DISTINCT character_id) as active_miners
            ')
            ->first();

        $allOreValue = $stats->all_ore_value ?? 0;
        $moonOreValue = $stats->moon_ore_value ?? 0;

        // Tax stats still from MiningTax table
        $taxAmount = MiningTax::whereIn('character_id', $characterIds)
            ->where('month', $startDate->format('Y-m-01'))
            ->sum('amount_owed');

        $taxCollected = MiningTax::whereIn('character_id', $characterIds)
            ->where('month', $startDate->format('Y-m-01'))
            ->where('status', 'paid')
            ->sum('amount_paid');

        return [
            'all_ore_value' => $allOreValue,
            'all_ore_quantity' => $stats->all_ore_quantity ?? 0,
            'moon_ore_value' => $moonOreValue,
            'moon_ore_quantity' => 0, // Not tracked separately in daily summaries
            'tax_amount' => $taxAmount,
            'tax_collected' => $taxCollected,
            'active_miners' => $stats->active_miners ?? 0,
        ];
    }

    /**
     * Get last 12 months statistics for director
     */
    private function getDirectorLast12MonthsStats($corporationId, $startDate)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        $stats = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('
                SUM(total_value) as all_ore_value,
                SUM(total_quantity) as all_ore_quantity,
                SUM(moon_ore_value) as moon_ore_value,
                COUNT(DISTINCT character_id) as active_miners
            ')
            ->first();

        $allOreValue = $stats->all_ore_value ?? 0;
        $moonOreValue = $stats->moon_ore_value ?? 0;

        $endDate = Carbon::now();
        $taxCollected = MiningTax::whereIn('character_id', $characterIds)
            ->whereBetween('month', [$startDate->format('Y-m-01'), $endDate->format('Y-m-01')])
            ->where('status', 'paid')
            ->sum('amount_paid');

        return [
            'all_ore_value' => $allOreValue,
            'all_ore_total_value' => $allOreValue,
            'all_ore_quantity' => $stats->all_ore_quantity ?? 0,
            'moon_ore_value' => $moonOreValue,
            'moon_ore_total_value' => $moonOreValue,
            'moon_ore_quantity' => 0, // Not tracked separately in daily summaries
            'tax_collected' => $taxCollected,
            'active_miners' => $stats->active_miners ?? 0,
        ];
    }

    /**
     * Get top miners ranking by account (not individual characters)
     * UPDATED: Uses CharacterInfoService for proper character/corp names and unregistered character support
     * UPDATED: Now supports corporation filtering based on dashboard settings
     */
    private function getTopMinersRanking($oreType, $startDate, $endDate, $limit = 20)
    {
        $query = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at');

        // Filter by ore type
        if ($oreType === 'moon_ore') {
            $moonOreTypeIds = $this->getMoonOreTypeIds();
            $query->whereIn('type_id', $moonOreTypeIds);
        }

        // Apply corporation filter from dashboard settings
        $corporationFilter = $this->settingsService->getSetting('dashboard_leaderboard_corporation_filter', 'all');

        if ($corporationFilter === 'specific') {
            $corporationIdsJson = $this->settingsService->getSetting('dashboard_leaderboard_corporation_ids', '[]');
            $corporationIds = json_decode($corporationIdsJson, true);

            if (!empty($corporationIds)) {
                $query->whereIn('character_id', function($subQuery) use ($corporationIds) {
                    $subQuery->select('character_id')
                        ->from('character_affiliations')
                        ->whereIn('corporation_id', $corporationIds);
                });
            }
        }

        $miningData = $query->get();
        
        // Get all unique character IDs
        $characterIds = $miningData->pluck('character_id')->unique()->toArray();
        
        // Get character info in batch (optimized)
        $charactersInfo = $this->characterInfoService->getBatchCharacterInfo($characterIds);
        
        // Group by main character (account) - this sums all alts together
        $grouped = $miningData->groupBy(function($entry) use ($charactersInfo) {
            $charInfo = $charactersInfo[$entry->character_id] ?? null;
            
            // Use main_character_id if available, otherwise use character_id
            if ($charInfo && $charInfo['main_character_id']) {
                return $charInfo['main_character_id'];
            }
            
            return $entry->character_id;
        });

        $rankings = [];
        foreach ($grouped as $mainCharId => $entries) {
            $totalValue = $this->calculateTotalValue($entries);
            
            // ALWAYS get fresh info for the main character (not from cache of alts)
            // This ensures we show the MAIN's corporation, not an alt's corporation
            $mainCharInfo = $this->characterInfoService->getCharacterInfo($mainCharId);
            
            $rankings[] = [
                'main_character_id' => $mainCharId,
                'character_name' => $mainCharInfo['name'],
                'corporation_name' => $mainCharInfo['corporation_name'],
                'total_value' => $totalValue,
                'total_quantity' => $entries->sum('quantity'),
                'is_registered' => $mainCharInfo['is_registered'],
                // Count how many alts contributed to this total
                'alt_count' => $entries->pluck('character_id')->unique()->count() - 1,
            ];
        }

        // Sort by total value descending
        usort($rankings, function($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });

        return array_slice($rankings, 0, $limit);
    }

    /**
     * Get top miners for corporation (overall or for period)
     */
    private function getTopMinersOverall($corporationId, $oreType, $limit = 5)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        $query = MiningLedger::whereIn('character_id', $characterIds)
            ->whereNotNull('processed_at');

        if ($oreType === 'moon_ore') {
            $moonOreTypeIds = $this->getMoonOreTypeIds();
            $query->whereIn('type_id', $moonOreTypeIds);
        }

        $miningData = $query->with('character')->get();

        return $this->aggregateTopMiners($miningData, $limit);
    }

    /**
     * Get top miners for specific period
     */
    private function getTopMinersForPeriod($corporationId, $oreType, $startDate, $endDate, $limit = 5)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        $query = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at');

        if ($oreType === 'moon_ore') {
            $moonOreTypeIds = $this->getMoonOreTypeIds();
            $query->whereIn('type_id', $moonOreTypeIds);
        }

        $miningData = $query->with('character')->get();

        return $this->aggregateTopMiners($miningData, $limit);
    }

    /**
     * Aggregate top miners from mining data
     * UPDATED: Uses CharacterInfoService for proper character/corp names
     */
    private function aggregateTopMiners($miningData, $limit)
    {
        // Get all unique character IDs
        $characterIds = $miningData->pluck('character_id')->unique()->toArray();
        
        // Get character info in batch (optimized)
        $charactersInfo = $this->characterInfoService->getBatchCharacterInfo($characterIds);
        
        // Group by main character
        $grouped = $miningData->groupBy(function($entry) use ($charactersInfo) {
            $charInfo = $charactersInfo[$entry->character_id] ?? null;
            
            // Use main_character_id if available, otherwise use character_id
            if ($charInfo && $charInfo['main_character_id']) {
                return $charInfo['main_character_id'];
            }
            
            return $entry->character_id;
        });

        $miners = [];
        foreach ($grouped as $mainCharId => $entries) {
            $totalValue = $this->calculateTotalValue($entries);
            
            // ALWAYS get fresh info for the main character (not from cache of alts)
            // This ensures we show the MAIN's corporation, not an alt's corporation
            $mainCharInfo = $this->characterInfoService->getCharacterInfo($mainCharId);
            
            $miners[] = [
                'character_id' => $mainCharId,
                'character_name' => $mainCharInfo['name'],
                'corporation_name' => $mainCharInfo['corporation_name'],
                'total_value' => $totalValue,
                'total_quantity' => $entries->sum('quantity'),
                'is_registered' => $mainCharInfo['is_registered'],
                'alt_count' => $entries->pluck('character_id')->unique()->count() - 1,
            ];
        }

        usort($miners, function($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });

        return array_slice($miners, 0, $limit);
    }

    /**
     * Get mining performance chart data for last 12 months
     * FIXED: Current month (i=0) is included in the loop, no need to add it twice
     */
    private function getMiningPerformanceLast12Months($characterIds)
    {
        $user = auth()->user();
        $months = [];
        $data = [];

        // Get stored statistics for closed months
        $storedStats = MonthlyStatistic::where('user_id', $user->id)
            ->where('is_closed', true)
            ->where('month_start', '>=', Carbon::now()->subMonths(12))
            ->get()
            ->keyBy(function($stat) {
                return $stat->year . '-' . str_pad($stat->month, 2, '0', STR_PAD_LEFT);
            });

        // Loop from 11 months ago to current month (i=0 is current month)
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();
            $monthKey = $month->format('Y-m');

            // For current month, use today as end date instead of end of month
            if ($i === 0) {
                $monthEnd = Carbon::now();
            }

            // Use stored stats for closed months
            if (isset($storedStats[$monthKey]) && $i > 0) {
                $totalValue = $storedStats[$monthKey]->total_value;
            } else {
                // Calculate live for current month or if no stored stats
                $totalValue = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
                    ->whereBetween('date', [$month, $monthEnd])
                    ->sum('total_value');
            }

            $months[] = $monthKey;
            $data[] = $totalValue;
        }

        return [
            'labels' => $months,
            'data' => $data,
        ];
    }

    /**
     * Get mining value by group chart (ISK value per ore group)
     */
    private function getMiningVolumeByGroup($characterIds, $startDate)
    {
        $breakdown = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startDate)
            ->selectRaw('
                SUM(moon_ore_value) as moon,
                SUM(regular_ore_value) as regular,
                SUM(ice_value) as ice,
                SUM(gas_value) as gas
            ')
            ->first();

        $groups = [
            'Moon Ore' => $breakdown->moon ?? 0,
            'Regular Ore' => $breakdown->regular ?? 0,
            'Ice' => $breakdown->ice ?? 0,
            'Gas' => $breakdown->gas ?? 0,
        ];

        $labels = [];
        $data = [];

        foreach ($groups as $groupName => $value) {
            if ($value > 0) {
                $labels[] = $groupName;
                $data[] = $value;
            }
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    /**
     * Get mining by specific ore type chart (top N ores by ISK value)
     */
    private function getMiningByType($characterIds, $startDate, $limit = 10)
    {
        // Use pre-calculated daily summaries (ore_types JSON) instead of loading all raw records
        $summaries = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startDate)
            ->whereNotNull('ore_types')
            ->select('ore_types')
            ->get();

        // Aggregate per type_id from the ore_types JSON breakdown
        $byType = [];

        foreach ($summaries as $summary) {
            $oreTypes = is_array($summary->ore_types) ? $summary->ore_types : json_decode($summary->ore_types, true);
            if (!is_array($oreTypes)) continue;

            foreach ($oreTypes as $ore) {
                $typeId = $ore['type_id'] ?? null;
                if (!$typeId) continue;

                if (!isset($byType[$typeId])) {
                    $byType[$typeId] = [
                        'name' => $ore['ore_name'] ?? "Type {$typeId}",
                        'quantity' => 0,
                        'value' => 0,
                        'group' => $this->getOreGroupFromCategory($ore['category'] ?? null, $typeId),
                    ];
                }

                $byType[$typeId]['quantity'] += (float) ($ore['quantity'] ?? 0);
                $byType[$typeId]['value'] += (float) ($ore['total_value'] ?? 0);
            }
        }

        // Fallback: if no daily summaries exist yet, use raw ledger with SQL aggregation
        if (empty($byType)) {
            $rawData = MiningLedger::whereIn('character_id', $characterIds)
                ->where('date', '>=', $startDate)
                ->whereNotNull('processed_at')
                ->selectRaw('type_id, SUM(quantity) as total_quantity, SUM(total_value) as total_value')
                ->groupBy('type_id')
                ->orderByDesc(DB::raw('SUM(total_value)'))
                ->limit($limit)
                ->get();

            // Batch-load ore names
            $typeIds = $rawData->pluck('type_id')->toArray();
            $typeNames = !empty($typeIds)
                ? DB::table('invTypes')->whereIn('typeID', $typeIds)->pluck('typeName', 'typeID')->toArray()
                : [];

            foreach ($rawData as $entry) {
                $typeId = $entry->type_id;
                $byType[$typeId] = [
                    'name' => $typeNames[$typeId] ?? "Type {$typeId}",
                    'quantity' => (float) $entry->total_quantity,
                    'value' => (float) $entry->total_value,
                    'group' => $this->getOreGroup($typeId),
                ];
            }
        }

        // Sort by value descending and take top N
        usort($byType, function($a, $b) {
            return $b['value'] <=> $a['value'];
        });

        $topTypes = array_slice($byType, 0, $limit);

        $labels = [];
        $data = [];
        $colors = [];

        // Color map for ore groups
        $groupColors = [
            'Moon' => 'rgba(255, 206, 86, 0.8)',
            'Ore' => 'rgba(54, 162, 235, 0.8)',
            'Ice' => 'rgba(75, 192, 192, 0.8)',
            'Gas' => 'rgba(153, 102, 255, 0.8)',
            'Abyssal' => 'rgba(255, 99, 132, 0.8)',
        ];

        foreach ($topTypes as $type) {
            $labels[] = $type['name'];
            $data[] = $type['value'];
            $colors[] = $groupColors[$type['group']] ?? 'rgba(201, 203, 207, 0.8)';
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'colors' => $colors,
        ];
    }

    /**
     * Map ore_types JSON category to chart group name.
     */
    private function getOreGroupFromCategory(?string $category, int $typeId): string
    {
        switch ($category) {
            case 'moon_ore': return 'Moon';
            case 'ice': return 'Ice';
            case 'gas': return 'Gas';
            default: return $this->getOreGroup($typeId);
        }
    }

    /**
     * Get corporation mining value by group chart
     */
    private function getCorpMiningByGroup($corporationId, $startDate)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        return $this->getMiningVolumeByGroup($characterIds, $startDate);
    }

    /**
     * Get corporation mining by specific ore type chart
     */
    private function getCorpMiningByType($corporationId, $startDate, $limit = 10)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        return $this->getMiningByType($characterIds, $startDate, $limit);
    }

    /**
     * Get mining income chart data with refined value, tax, and events (uses stored data)
     */
    private function getMiningIncomeLast12Months($characterIds)
    {
        $user = auth()->user();
        $months = [];
        $refinedValue = [];
        $taxPaid = [];
        $eventBonus = [];

        // Get stored statistics for closed months
        $storedStats = MonthlyStatistic::where('user_id', $user->id)
            ->where('is_closed', true)
            ->where('month_start', '>=', Carbon::now()->subMonths(12))
            ->get()
            ->keyBy(function($stat) {
                return $stat->year . '-' . str_pad($stat->month, 2, '0', STR_PAD_LEFT);
            });

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();
            $monthKey = $month->format('Y-m');

            // Use stored stats for closed months
            if (isset($storedStats[$monthKey]) && $i > 0) {
                $value = $storedStats[$monthKey]->total_value;
                $tax = $storedStats[$monthKey]->tax_paid;
            } else {
                // Calculate live for current month or if no stored stats
                $value = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
                    ->whereBetween('date', [$month, $monthEnd])
                    ->sum('total_value');

                // Tax paid
                $tax = MiningTax::whereIn('character_id', $characterIds)
                    ->where('month', $month->format('Y-m-01'))
                    ->where('status', 'paid')
                    ->sum('amount_paid');
            }

            // Event bonus - calculate tax savings from events with negative tax modifiers
            $eventBonusAmount = 0;
            $characterEvents = MiningEvent::whereIn('status', ['completed', 'active'])
                ->where(function($query) use ($month, $monthEnd) {
                    $query->whereBetween('start_time', [$month, $monthEnd])
                        ->orWhereBetween('end_time', [$month, $monthEnd]);
                })
                ->where('tax_modifier', '<', 0)
                ->get();

            foreach ($characterEvents as $event) {
                if ($event->total_mined > 0) {
                    $baseTaxRate = (float) $this->settingsService->getSetting('tax_rates.ore', 10);
                    $normalTax = $event->total_mined * ($baseTaxRate / 100);
                    $modifiedTax = $normalTax * (1 + ($event->tax_modifier / 100));
                    $eventBonusAmount += $normalTax - $modifiedTax;
                }
            }

            $months[] = $monthKey;
            $refinedValue[] = $value;
            $taxPaid[] = $tax;
            $eventBonus[] = $eventBonusAmount;
        }

        return [
            'labels' => $months,
            'refined_value' => $refinedValue,
            'tax_paid' => $taxPaid,
            'event_bonus' => $eventBonus,
        ];
    }

    /**
     * Get corporation mining performance chart
     * Shows sum of ALL mining (all ore types) by corporation characters
     * FIXED: Uses SQL SUM aggregate instead of loading all entries into PHP memory
     */
    private function getCorpMiningPerformanceLast12Months($corporationId)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        // Single query for all 12 months instead of 12 separate queries
        $monthlyData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as month_key, SUM(total_value) as total_value")
            ->groupBy('month_key')
            ->get()
            ->keyBy('month_key');

        $months = [];
        $data = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthKey = $month->format('Y-m');

            $months[] = $monthKey;
            $data[] = (float) ($monthlyData->get($monthKey)->total_value ?? 0);
        }

        return [
            'labels' => $months,
            'data' => $data,
        ];
    }

    /**
     * Get corporation moon mining performance chart
     * Uses daily summaries (same source as Corp Mining chart) for consistency.
     * Moon ore value is pre-computed in daily summaries via is_moon_ore boolean flag.
     */
    private function getCorpMoonMiningPerformanceLast12Months($corporationId)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        // Single query for all 12 months — same pattern as getCorpMiningPerformanceLast12Months
        $monthlyData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as month_key, SUM(moon_ore_value) as total_value")
            ->groupBy('month_key')
            ->get()
            ->keyBy('month_key');

        $months = [];
        $data = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthKey = $month->format('Y-m');

            $months[] = $monthKey;
            $data[] = (float) ($monthlyData->get($monthKey)->total_value ?? 0);
        }

        return [
            'labels' => $months,
            'data' => $data,
        ];
    }

    /**
     * Get mining tax chart for last 12 months
     * Current month shows live running totals that update as the month progresses
     */
    private function getMiningTaxLast12Months($corporationId)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        $months = [];
        $collected = [];
        $owed = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();

            $collectedAmount = MiningTax::whereIn('character_id', $characterIds)
                ->where('month', $month->format('Y-m-01'))
                ->where('status', 'paid')
                ->sum('amount_paid');

            $owedAmount = MiningTax::whereIn('character_id', $characterIds)
                ->where('month', $month->format('Y-m-01'))
                ->sum('amount_owed');

            // For current month, if no tax records exist yet, estimate from daily summaries
            if ($i === 0 && $owedAmount == 0) {
                $monthEnd = Carbon::now();
                $currentMonthMining = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
                    ->whereBetween('date', [$month, $monthEnd])
                    ->sum('total_tax');

                if ($currentMonthMining > 0) {
                    $owedAmount = $currentMonthMining;
                }
            }

            $months[] = $month->format('Y-m');
            $collected[] = $collectedAmount;
            $owed[] = $owedAmount;
        }

        return [
            'labels' => $months,
            'collected' => $collected,
            'owed' => $owed,
        ];
    }

    /**
     * Get event tax chart for last 12 months
     * Shows the tax impact from mining events (tax modifier adjustments)
     * Events with negative modifiers reduce tax, positive modifiers increase tax
     * Current month shows running total as events progress
     */
    private function getEventTaxLast12Months($corporationId)
    {
        $months = [];
        $data = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            if ($i === 0) {
                $monthEnd = Carbon::now();
            }

            // Get completed and active events for this corporation in this month
            $events = MiningEvent::where('corporation_id', $corporationId)
                ->whereIn('status', ['completed', 'active'])
                ->where(function($query) use ($month, $monthEnd) {
                    $query->whereBetween('start_time', [$month, $monthEnd])
                        ->orWhereBetween('end_time', [$month, $monthEnd]);
                })
                ->where('tax_modifier', '!=', 0)
                ->get();

            $eventTaxTotal = 0;
            foreach ($events as $event) {
                // Calculate the tax impact from this event's modifier
                // total_mined is the ISK value mined during the event
                // tax_modifier is the percentage adjustment (e.g. -50 = half tax, +100 = double tax)
                if ($event->total_mined > 0) {
                    // Get the base tax rate to calculate the modifier impact
                    $baseTaxRate = (float) $this->settingsService->getSetting('tax_rates.ore', 10);
                    $normalTax = $event->total_mined * ($baseTaxRate / 100);
                    $modifiedTax = $normalTax * (1 + ($event->tax_modifier / 100));
                    $eventTaxTotal += $modifiedTax - $normalTax;
                }
            }

            $months[] = $month->format('Y-m');
            $data[] = abs($eventTaxTotal);
        }

        return [
            'labels' => $months,
            'data' => $data,
        ];
    }

    // ==================== UTILITY METHODS (ALL FIXED FOR SeAT v5.x) ====================

    private function hasDirectorPermissions()
    {
        return auth()->user()->can('mining-manager.director');
    }

    /**
     * FIXED: Get user's character IDs with multiple fallback methods
     * Addresses SeAT v5.x relationship issues
     */
    private function getUserCharacterIds($user)
    {
        if (!$user) {
            \Log::warning('getUserCharacterIds: No user provided');
            return [];
        }
        
        // Method 1: Try the characters relationship (preferred)
        try {
            if (method_exists($user, 'characters')) {
                $characters = $user->characters;
                if ($characters && $characters->count() > 0) {
                    $ids = $characters->pluck('character_id')->toArray();
                    \Log::debug('getUserCharacterIds: Found via characters relationship', [
                        'user_id' => $user->id,
                        'count' => count($ids)
                    ]);
                    return $ids;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('getUserCharacterIds: Failed to load characters via relationship', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 2: Try to load the relationship explicitly
        try {
            $user->load('characters');
            if ($user->relationLoaded('characters') && $user->characters->count() > 0) {
                $ids = $user->characters->pluck('character_id')->toArray();
                \Log::debug('getUserCharacterIds: Found via explicit relationship load', [
                    'user_id' => $user->id,
                    'count' => count($ids)
                ]);
                return $ids;
            }
        } catch (\Exception $e) {
            \Log::warning('getUserCharacterIds: Failed to explicitly load characters', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 3: Direct database query via refresh_tokens (SeAT v5 stores
        // the user↔character link in refresh_tokens, NOT character_infos)
        try {
            $characterIds = DB::table('refresh_tokens')
                ->where('user_id', $user->id)
                ->pluck('character_id')
                ->toArray();

            if (!empty($characterIds)) {
                \Log::debug('getUserCharacterIds: Found via refresh_tokens table query', [
                    'user_id' => $user->id,
                    'count' => count($characterIds)
                ]);
                return $characterIds;
            }
        } catch (\Exception $e) {
            \Log::error('getUserCharacterIds: Failed to get user characters from refresh_tokens', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
        
        \Log::warning('getUserCharacterIds: No characters found for user', [
            'user_id' => $user->id
        ]);
        
        return [];
    }

    /**
     * FIXED: Get main character ID for a user
     */
    private function getMainCharacterId($user)
    {
        if (!$user) {
            \Log::warning('getMainCharacterId: No user provided');
            return null;
        }
        
        // Method 1: Check if user has main_character_id property
        if (isset($user->main_character_id) && $user->main_character_id) {
            return $user->main_character_id;
        }
        
        // Method 2: Get the first character from the user's characters
        $characterIds = $this->getUserCharacterIds($user);
        
        if (!empty($characterIds)) {
            return $characterIds[0];
        }
        
        \Log::warning('getMainCharacterId: No characters found for user', [
            'user_id' => $user->id
        ]);
        
        return null;
    }

    /**
     * FIXED: Get user's corporation ID with multiple fallback methods
     * Addresses SeAT v5.x affiliation relationship issues
     */
    private function getUserCorporationId()
    {
        $user = auth()->user();
        
        if (!$user) {
            \Log::warning('getUserCorporationId: No authenticated user');
            return null;
        }
        
        // Get the main/first character ID using our helper method
        $mainCharId = $this->getMainCharacterId($user);
        
        if (!$mainCharId) {
            \Log::warning('getUserCorporationId: No main character found', ['user_id' => $user->id]);
            return null;
        }
        
        // Try multiple methods to get corporation ID
        $character = CharacterInfo::find($mainCharId);
        
        if (!$character) {
            \Log::warning('getUserCorporationId: Character not found', ['character_id' => $mainCharId]);
            return null;
        }
        
        // Method 1: Direct property (most reliable in SeAT v5)
        if (isset($character->corporation_id) && $character->corporation_id) {
            \Log::debug('getUserCorporationId: Found via direct property', [
                'character_id' => $mainCharId,
                'corporation_id' => $character->corporation_id
            ]);
            return $character->corporation_id;
        }
        
        // Method 2: Try affiliation relationship if it exists
        try {
            // Check if relationship is already loaded
            if ($character->relationLoaded('affiliation') && $character->affiliation) {
                $corpId = $character->affiliation->corporation_id ?? null;
                if ($corpId) {
                    \Log::debug('getUserCorporationId: Found via loaded affiliation', [
                        'character_id' => $mainCharId,
                        'corporation_id' => $corpId
                    ]);
                    return $corpId;
                }
            }
            
            // Try to load the relationship
            $character->load('affiliation');
            if ($character->affiliation && isset($character->affiliation->corporation_id)) {
                $corpId = $character->affiliation->corporation_id;
                \Log::debug('getUserCorporationId: Found via affiliation relationship', [
                    'character_id' => $mainCharId,
                    'corporation_id' => $corpId
                ]);
                return $corpId;
            }
        } catch (\Exception $e) {
            \Log::warning('getUserCorporationId: Failed to load affiliation relationship', [
                'character_id' => $mainCharId,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 3: Query the character_affiliations table directly
        try {
            $affiliation = DB::table('character_affiliations')
                ->where('character_id', $mainCharId)
                ->first();
            
            if ($affiliation && isset($affiliation->corporation_id)) {
                \Log::debug('getUserCorporationId: Found via direct table query', [
                    'character_id' => $mainCharId,
                    'corporation_id' => $affiliation->corporation_id
                ]);
                return $affiliation->corporation_id;
            }
        } catch (\Exception $e) {
            \Log::error('getUserCorporationId: Failed to query affiliations table', [
                'character_id' => $mainCharId,
                'error' => $e->getMessage()
            ]);
        }
        
        \Log::warning('getUserCorporationId: No corporation found for character', [
            'character_id' => $mainCharId,
            'user_id' => $user->id
        ]);
        
        return null;
    }

    /**
     * Get all characters for a corporation from multiple sources.
     *
     * Corporation mining data comes from ESI mining observers, which record
     * mining activity for ALL pilots at corp structures — including unregistered
     * pilots who aren't in character_affiliations. We merge results from every
     * available source so the corp charts include everyone.
     */
    /**
     * Get character IDs belonging to a corporation.
     *
     * Gathers candidates from 3 sources then filters out characters confirmed
     * to be in a different corporation (via character_affiliations).
     *
     * Sources:
     *  1. character_affiliations — confirmed corp members registered in SeAT
     *  2. mining_ledger rows tagged with this corporation_id
     *  3. corporation mining observer data (anyone who mined at corp structures)
     *
     * Sources 2 & 3 include non-corp miners at your structures. To keep only
     * corp members, we exclude characters who have an affiliation entry for a
     * DIFFERENT corporation. Characters with no affiliation data are kept
     * (likely unregistered corp members).
     */
    private function getCorporationCharacterIds($corporationId)
    {
        if (!$corporationId) {
            \Log::warning('getCorporationCharacterIds: No corporation ID provided');
            return [];
        }

        $allIds = [];

        // Source 1: character_affiliations (confirmed corp members)
        try {
            $ids = DB::table('character_affiliations')
                ->where('corporation_id', $corporationId)
                ->pluck('character_id')
                ->toArray();

            if (!empty($ids)) {
                $allIds = array_merge($allIds, $ids);
            }
        } catch (\Exception $e) {
            \Log::warning('getCorporationCharacterIds: character_affiliations query failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Source 2: mining_ledger rows tagged with this corporation_id
        try {
            $ids = DB::table('mining_ledger')
                ->where('corporation_id', $corporationId)
                ->distinct()
                ->pluck('character_id')
                ->toArray();

            if (!empty($ids)) {
                $allIds = array_merge($allIds, $ids);
            }
        } catch (\Exception $e) {
            \Log::warning('getCorporationCharacterIds: mining_ledger query failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Source 3: corporation mining observer data
        try {
            $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
            $observerCorpId = $moonOwnerCorpId ?: $corporationId;

            $ids = DB::table('corporation_industry_mining_observer_data as d')
                ->join('corporation_industry_mining_observers as o', 'd.observer_id', '=', 'o.observer_id')
                ->where('o.corporation_id', $observerCorpId)
                ->distinct()
                ->pluck('d.character_id')
                ->toArray();

            if (!empty($ids)) {
                $allIds = array_merge($allIds, $ids);
            }
        } catch (\Exception $e) {
            \Log::warning('getCorporationCharacterIds: mining observer query failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $uniqueIds = array_values(array_unique($allIds));

        // Filter: exclude characters confirmed to be in a DIFFERENT corporation.
        // Characters with NO affiliation entry are kept (unregistered corp members).
        if (!empty($uniqueIds)) {
            try {
                $nonCorpIds = DB::table('character_affiliations')
                    ->whereIn('character_id', $uniqueIds)
                    ->where('corporation_id', '!=', $corporationId)
                    ->pluck('character_id')
                    ->toArray();

                if (!empty($nonCorpIds)) {
                    $uniqueIds = array_values(array_diff($uniqueIds, $nonCorpIds));
                    \Log::debug('getCorporationCharacterIds: Excluded non-corp members', [
                        'excluded_count' => count($nonCorpIds),
                    ]);
                }
            } catch (\Exception $e) {
                \Log::warning('getCorporationCharacterIds: Non-corp filter failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        \Log::debug('getCorporationCharacterIds: Final count', [
            'corporation_id' => $corporationId,
            'count' => count($uniqueIds),
        ]);

        return $uniqueIds;
    }

    /**
     * FIXED: Get main character ID for a character (for ranking purposes)
     * SeAT v5 stores user↔character mapping in refresh_tokens, not on character_infos.
     */
    private function getMainCharacterIdForCharacter($characterId)
    {
        try {
            // In SeAT v5, look up the owning user via refresh_tokens
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->value('user_id');

            if (!$userId) {
                \Log::debug('getMainCharacterIdForCharacter: No user found in refresh_tokens', [
                    'character_id' => $characterId
                ]);
                return $characterId;
            }

            // Try to get main_character_id from users table
            $mainCharId = DB::table('users')
                ->where('id', $userId)
                ->value('main_character_id');

            // Return the main character ID if found, otherwise return the original character ID
            return ($mainCharId && $mainCharId > 0) ? $mainCharId : $characterId;
        } catch (\Exception $e) {
            \Log::error('Error getting main character ID', [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
            return $characterId;
        }
    }

    private function getUserRank($mainCharacterId, $rankings)
    {
        foreach ($rankings as $index => $rank) {
            if ($rank['main_character_id'] == $mainCharacterId) {
                return $index + 1;
            }
        }
        return null;
    }

    private function calculateTotalVolume($miningData)
    {
        // Implement volume calculation based on type_id
        return $miningData->sum('quantity') * 0.1; // Placeholder
    }

    /**
     * FIXED METHOD - Now uses OreValuationService to respect ore_valuation_method setting
     */
    private function calculateTotalValue($miningData)
    {
        if ($miningData->isEmpty()) {
            return 0;
        }

        // Use OreValuationService for proper valuation
        $valuationService = app(\MiningManager\Services\Pricing\OreValuationService::class);

        // Calculate total value using the configured valuation method
        return $miningData->sum(function($entry) use ($valuationService) {
            $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);
            return $values['total_value'];
        });
    }

    /**
     * FIXED: Calculate entry value using OreValuationService
     * Respects ore_valuation_method setting (ore_price vs mineral_price)
     * This is used by chart methods and performance calculations
     */
    private function calculateEntryValue($entry)
    {
        try {
            // Use OreValuationService for proper valuation
            $valuationService = app(\MiningManager\Services\Pricing\OreValuationService::class);

            $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);

            return $values['total_value'];
        } catch (\Exception $e) {
            \Log::error('Error calculating entry value', [
                'type_id' => $entry->type_id ?? 'unknown',
                'entry_id' => $entry->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    private function calculateTaxForPeriod($characterIds, $startDate, $endDate)
    {
        return MiningTax::whereIn('character_id', $characterIds)
            ->whereBetween('month', [$startDate->format('Y-m-01'), $endDate->format('Y-m-01')])
            ->sum('amount_owed');
    }

    private function isMoonOre($typeId)
    {
        return TypeIdRegistry::isMoonOre($typeId);
    }

    private function getMoonOreTypeIds()
    {
        return TypeIdRegistry::getAllMoonOres();
    }

    private function getOreGroup($typeId)
    {
        // Check moon ore first (highest priority for categorization)
        if (TypeIdRegistry::isMoonOre($typeId)) {
            return 'Moon';
        }
        
        // Check ice
        if (TypeIdRegistry::isIce($typeId)) {
            return 'Ice';
        }
        
        // Check gas
        if (TypeIdRegistry::isGas($typeId)) {
            return 'Gas';
        }
        
        // Check abyssal ore
        if (in_array($typeId, TypeIdRegistry::ABYSSAL_ORES)) {
            return 'Abyssal';
        }
        
        // Everything else is regular ore (including new ores)
        return 'Ore';
    }

    private function getMemberChartData($chartType, $characterIds)
    {
        switch ($chartType) {
            case 'mining_performance':
                return $this->getMiningPerformanceLast12Months($characterIds);
            case 'mining_volume':
                return $this->getMiningVolumeByGroup($characterIds, Carbon::now()->subMonths(12));
            case 'mining_income':
                return $this->getMiningIncomeLast12Months($characterIds);
            default:
                return [];
        }
    }

    private function getDirectorChartData($chartType, $corporationId)
    {
        switch ($chartType) {
            case 'mining_performance':
                return $this->getCorpMiningPerformanceLast12Months($corporationId);
            case 'moon_mining_performance':
                return $this->getCorpMoonMiningPerformanceLast12Months($corporationId);
            case 'mining_tax':
                return $this->getMiningTaxLast12Months($corporationId);
            case 'event_tax':
                return $this->getEventTaxLast12Months($corporationId);
            default:
                return [];
        }
    }

    // ==================== DIAGNOSTIC METHOD ====================

    /**
     * NEW: Diagnostic method to check affiliation table structure
     * Call this from a route or tinker to verify your setup
     * 
     * To use: Add a temporary route or run in tinker:
     * app(MiningManager\Http\Controllers\DashboardController::class)->diagnoseAffiliation()
     */
    public function diagnoseAffiliation()
    {
        $diagnostics = [
            'tables' => [],
            'columns' => [],
            'sample_data' => [],
            'relationships' => []
        ];
        
        // Check if tables exist
        $tables = ['character_affiliations', 'character_infos', 'users'];
        foreach ($tables as $table) {
            try {
                $exists = \Schema::hasTable($table);
                $diagnostics['tables'][$table] = $exists;
                
                if ($exists) {
                    $diagnostics['columns'][$table] = \Schema::getColumnListing($table);
                    $diagnostics['sample_data'][$table] = DB::table($table)->first();
                }
            } catch (\Exception $e) {
                $diagnostics['tables'][$table] = 'ERROR: ' . $e->getMessage();
            }
        }
        
        // Check CharacterInfo model
        try {
            $char = CharacterInfo::first();
            if ($char) {
                $diagnostics['relationships']['character_has_affiliation'] = method_exists($char, 'affiliation');
                $diagnostics['relationships']['character_has_corporation'] = method_exists($char, 'corporation');
                $diagnostics['relationships']['character_keys'] = [
                    'primary_key' => $char->getKeyName(),
                    'attributes' => array_keys($char->getAttributes())
                ];
            }
        } catch (\Exception $e) {
            $diagnostics['relationships']['character_error'] = $e->getMessage();
        }
        
        return response()->json($diagnostics, 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Clear dashboard cache for a specific user
     * Called after processing new ledger data
     *
     * @param int $userId
     * @param int|null $corporationId
     * @return void
     */
    public static function clearDashboardCache($userId = null, $corporationId = null)
    {
        $currentMonth = Carbon::now()->startOfMonth()->format('Y-m');

        if ($userId) {
            // Clear member dashboard cache for specific user
            Cache::forget('dashboard.member.' . $userId . '.' . $currentMonth);

            // Clear director dashboard cache if corporation ID provided
            if ($corporationId) {
                Cache::forget('dashboard.director.' . $userId . '.' . $corporationId . '.' . $currentMonth);
            }
        } else {
            // Clear dashboard caches for all users with active sessions
            // Instead of Cache::flush() which nukes ALL application caches,
            // clear known dashboard keys for users who have mining data this month.
            try {
                $userIds = \Seat\Web\Models\User::pluck('id');
                foreach ($userIds as $uid) {
                    Cache::forget('dashboard.member.' . $uid . '.' . $currentMonth);
                    // Director caches use corporation_id in key — clear common patterns
                    // These will naturally expire via TTL if not explicitly cleared
                }
            } catch (\Exception $e) {
                // If user lookup fails, caches will expire via 30-min TTL
                Log::warning('Mining Manager: Could not clear dashboard caches: ' . $e->getMessage());
            }

            // Clear DashboardMetricsService caches
            Cache::forget('mining-manager:dashboard:summary-metrics');
        }
    }
}
