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

        // Cache dashboard data for 15 minutes (900 seconds)
        $dashboardData = Cache::remember($cacheKey, 900, function () use ($characterIds, $currentMonthStart, $currentMonthEnd, $last12MonthsStart, $user) {
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

        // Cache dashboard data for 15 minutes (900 seconds)
        $dashboardData = Cache::remember($cacheKey, 900, function () use ($characterIds, $corporationId, $currentMonthStart, $currentMonthEnd, $last12MonthsStart, $user) {
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

            // === CORPORATION STATISTICS ===
            $corpCurrentMonthStats = $this->getDirectorCurrentMonthStats($corporationId, $currentMonthStart, $currentMonthEnd);
            $corpLast12MonthsStats = $this->getDirectorLast12MonthsStats($corporationId, $last12MonthsStart);

            // Corporation top miners
            $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
            $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

            $topMinersOverallAllOre = $this->getTopMinersOverall($corporationId, 'all_ore', 5);
            $topMinersOverallMoonOre = $this->getTopMinersOverall($corporationId, 'moon_ore', 5);
            $topMinersLastMonthAllOre = $this->getTopMinersForPeriod($corporationId, 'all_ore', $lastMonthStart, $lastMonthEnd, 5);
            $topMinersLastMonthMoonOre = $this->getTopMinersForPeriod($corporationId, 'moon_ore', $lastMonthStart, $lastMonthEnd, 5);

            // Corporation charts
            $corpMiningPerformanceChart = $this->getCorpMiningPerformanceLast12Months($corporationId);
            $moonMiningPerformanceChart = $this->getCorpMoonMiningPerformanceLast12Months($corporationId);
            $corpMiningByGroupChart = $this->getCorpMiningByGroup($corporationId, $last12MonthsStart);
            $corpMiningByTypeChart = $this->getCorpMiningByType($corporationId, $last12MonthsStart);
            $miningTaxChart = $this->getMiningTaxLast12Months($corporationId);
            $eventTaxChart = $this->getEventTaxLast12Months($corporationId);

            return [
                // Personal stats
                'personalCurrentMonthStats' => $personalCurrentMonthStats,
                'personalLast12MonthsStats' => $personalLast12MonthsStats,
                'userRankAllOre' => $userRankAllOre,
                'userRankMoonOre' => $userRankMoonOre,
                'personalMiningPerformanceChart' => $personalMiningPerformanceChart,
                'personalMiningVolumeByGroupChart' => $personalMiningVolumeByGroupChart,
                'personalMiningByTypeChart' => $personalMiningByTypeChart,
                'personalMiningIncomeChart' => $personalMiningIncomeChart,
                // Corporation stats
                'corpCurrentMonthStats' => $corpCurrentMonthStats,
                'corpLast12MonthsStats' => $corpLast12MonthsStats,
                'topMinersAllOre' => $topMinersAllOre,
                'topMinersMoonOre' => $topMinersMoonOre,
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

        return view('mining-manager::dashboard.combined-director', $dashboardData);
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
        $miningData = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at')
            ->get();

        $totalQuantity = $miningData->sum('quantity');
        $totalVolume = $this->calculateTotalVolume($miningData);
        $totalValue = $this->calculateTotalValue($miningData);
        
        // Calculate tax
        $taxAmount = $this->calculateTaxForPeriod($characterIds, $startDate, $endDate);

        return [
            'total_quantity' => $totalQuantity,
            'total_volume' => $totalVolume,
            'total_value' => $totalValue,
            'total_isk' => $totalValue,
            'tax_isk' => $taxAmount,
            'mining_days' => $miningData->pluck('date')->unique()->count(),
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

            $currentMonthData = MiningLedger::whereIn('character_id', $characterIds)
                ->whereBetween('date', [$currentMonthStart, $currentMonthEnd])
                ->whereNotNull('processed_at')
                ->get();

            $currentMonthQuantity = $currentMonthData->sum('quantity');
            $currentMonthValue = $this->calculateTotalValue($currentMonthData);

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

        // Fallback: No stored stats, calculate from raw data (slower)
        $miningData = MiningLedger::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startDate)
            ->whereNotNull('processed_at')
            ->get();

        $totalQuantity = $miningData->sum('quantity');
        $totalVolume = $this->calculateTotalVolume($miningData);
        $totalValue = $this->calculateTotalValue($miningData);

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

        $miningData = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at')
            ->get();

        // Separate corp moon ore from all ore
        $moonOreData = $miningData->filter(function($entry) {
            return $this->isMoonOre($entry->type_id);
        });

        $allOreValue = $this->calculateTotalValue($miningData);
        $moonOreValue = $this->calculateTotalValue($moonOreData);

        // Calculate taxes
        $taxAmount = MiningTax::whereIn('character_id', $characterIds)
            ->where('month', $startDate->format('Y-m-01'))
            ->sum('amount_owed');

        $taxCollected = MiningTax::whereIn('character_id', $characterIds)
            ->where('month', $startDate->format('Y-m-01'))
            ->where('status', 'paid')
            ->sum('amount_paid');

        return [
            'all_ore_value' => $allOreValue,
            'all_ore_quantity' => $miningData->sum('quantity'),
            'moon_ore_value' => $moonOreValue,
            'moon_ore_quantity' => $moonOreData->sum('quantity'),
            'tax_amount' => $taxAmount,
            'tax_collected' => $taxCollected,
            'active_miners' => $miningData->pluck('character_id')->unique()->count(),
        ];
    }

    /**
     * Get last 12 months statistics for director
     */
    private function getDirectorLast12MonthsStats($corporationId, $startDate)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        $miningData = MiningLedger::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startDate)
            ->whereNotNull('processed_at')
            ->get();

        $moonOreData = $miningData->filter(function($entry) {
            return $this->isMoonOre($entry->type_id);
        });

        $endDate = Carbon::now();
        $taxCollected = MiningTax::whereIn('character_id', $characterIds)
            ->whereBetween('month', [$startDate->format('Y-m-01'), $endDate->format('Y-m-01')])
            ->where('status', 'paid')
            ->sum('amount_paid');

        return [
            'all_ore_value' => $this->calculateTotalValue($miningData),
            'all_ore_total_value' => $this->calculateTotalValue($miningData),
            'all_ore_quantity' => $miningData->sum('quantity'),
            'moon_ore_value' => $this->calculateTotalValue($moonOreData),
            'moon_ore_total_value' => $this->calculateTotalValue($moonOreData),
            'moon_ore_quantity' => $moonOreData->sum('quantity'),
            'tax_collected' => $taxCollected,
            'active_miners' => $miningData->pluck('character_id')->unique()->count(),
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
                $totalValue = MiningLedger::whereIn('character_id', $characterIds)
                    ->whereBetween('date', [$month, $monthEnd])
                    ->whereNotNull('processed_at')
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
        $miningData = MiningLedger::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startDate)
            ->whereNotNull('processed_at')
            ->get();

        $groups = [
            'Moon Ore' => [],
            'Regular Ore' => [],
            'Ice' => [],
            'Gas' => [],
            'Abyssal' => []
        ];

        foreach ($miningData as $entry) {
            $group = $this->getOreGroup($entry->type_id);
            $groupLabel = $group === 'Moon' ? 'Moon Ore' : ($group === 'Ore' ? 'Regular Ore' : $group);
            if (isset($groups[$groupLabel])) {
                $groups[$groupLabel][] = $entry;
            }
        }

        $labels = [];
        $data = [];

        foreach ($groups as $groupName => $entries) {
            if (count($entries) > 0) {
                $labels[] = $groupName;
                $data[] = $this->calculateTotalValue(collect($entries));
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
        $miningData = MiningLedger::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startDate)
            ->whereNotNull('processed_at')
            ->get();

        // Group by type_id and calculate value per type
        $byType = [];
        $valuationService = app(\MiningManager\Services\Pricing\OreValuationService::class);

        foreach ($miningData as $entry) {
            $typeId = $entry->type_id;
            if (!isset($byType[$typeId])) {
                // Get ore name from invTypes table
                $typeName = DB::table('invTypes')
                    ->where('typeID', $typeId)
                    ->value('typeName') ?? "Type {$typeId}";

                $byType[$typeId] = [
                    'name' => $typeName,
                    'quantity' => 0,
                    'value' => 0,
                    'group' => $this->getOreGroup($typeId),
                ];
            }

            $byType[$typeId]['quantity'] += $entry->quantity;
            $values = $valuationService->calculateOreValue($typeId, $entry->quantity);
            $byType[$typeId]['value'] += $values['total_value'];
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
                $value = MiningLedger::whereIn('character_id', $characterIds)
                    ->whereBetween('date', [$month, $monthEnd])
                    ->whereNotNull('processed_at')
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
     */
    private function getCorpMiningPerformanceLast12Months($corporationId)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);

        $months = [];
        $data = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            if ($i === 0) {
                $monthEnd = Carbon::now();
            }

            $totalValue = MiningLedger::whereIn('character_id', $characterIds)
                ->whereBetween('date', [$month, $monthEnd])
                ->whereNotNull('processed_at')
                ->get()
                ->sum(function($entry) {
                    return $this->calculateEntryValue($entry);
                });

            $months[] = $month->format('Y-m');
            $data[] = $totalValue;
        }

        return [
            'labels' => $months,
            'data' => $data,
        ];
    }

    /**
     * Get corporation moon mining performance chart
     * Uses corporation_industry_mining_observer_data which contains mining activity
     * from the holding corporation's moon mining structures only.
     * This is the authoritative data source for moon mining (same as tax calculation).
     */
    private function getCorpMoonMiningPerformanceLast12Months($corporationId)
    {
        $moonOreTypeIds = $this->getMoonOreTypeIds();

        // Get the moon owner corporation ID from settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

        $months = [];
        $data = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            if ($i === 0) {
                $monthEnd = Carbon::now();
            }

            $totalValue = 0;

            if ($moonOwnerCorpId) {
                // Query observer data from the holding corporation's moon structures
                // This table only contains mining from YOUR corp's refineries/observers
                $observerData = DB::table('corporation_industry_mining_observer_data as d')
                    ->join('corporation_industry_mining_observers as o', 'd.observer_id', '=', 'o.observer_id')
                    ->where('o.corporation_id', $moonOwnerCorpId)
                    ->whereIn('d.type_id', $moonOreTypeIds)
                    ->whereBetween('d.last_updated', [$month, $monthEnd])
                    ->select('d.type_id', DB::raw('SUM(d.quantity) as total_quantity'))
                    ->groupBy('d.type_id')
                    ->get();

                // Calculate value using OreValuationService for each ore type
                $valuationService = app(\MiningManager\Services\Pricing\OreValuationService::class);
                foreach ($observerData as $ore) {
                    $values = $valuationService->calculateOreValue($ore->type_id, $ore->total_quantity);
                    $totalValue += $values['total_value'];
                }
            } else {
                // Fallback: if no moon owner configured, use mining_ledger data for corp characters
                $characterIds = $this->getCorporationCharacterIds($corporationId);
                $totalValue = MiningLedger::whereIn('character_id', $characterIds)
                    ->whereIn('type_id', $moonOreTypeIds)
                    ->whereBetween('date', [$month, $monthEnd])
                    ->whereNotNull('processed_at')
                    ->get()
                    ->sum(function($entry) {
                        return $this->calculateEntryValue($entry);
                    });
            }

            $months[] = $month->format('Y-m');
            $data[] = $totalValue;
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

            // For current month, if no tax records exist yet, estimate from mining ledger
            if ($i === 0 && $owedAmount == 0) {
                $monthEnd = Carbon::now();
                $currentMonthMining = MiningLedger::whereIn('character_id', $characterIds)
                    ->whereBetween('date', [$month, $monthEnd])
                    ->whereNotNull('processed_at')
                    ->sum('tax_amount');

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
        
        // Method 3: Direct database query (most reliable fallback)
        try {
            $characterIds = DB::table('character_infos')
                ->where('user_id', $user->id)
                ->pluck('character_id')
                ->toArray();
            
            if (!empty($characterIds)) {
                \Log::debug('getUserCharacterIds: Found via direct table query', [
                    'user_id' => $user->id,
                    'count' => count($characterIds)
                ]);
                return $characterIds;
            }
        } catch (\Exception $e) {
            \Log::error('getUserCharacterIds: Failed to get user characters from database', [
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
     * FIXED: Get all characters for a corporation with multiple fallback methods
     */
    private function getCorporationCharacterIds($corporationId)
    {
        if (!$corporationId) {
            \Log::warning('getCorporationCharacterIds: No corporation ID provided');
            return [];
        }
        
        // Method 1: Try affiliation relationship (preferred method)
        try {
            $ids = CharacterInfo::whereHas('affiliation', function($query) use ($corporationId) {
                $query->where('corporation_id', $corporationId);
            })->pluck('character_id')->toArray();
            
            if (!empty($ids)) {
                \Log::debug('getCorporationCharacterIds: Found via affiliation relationship', [
                    'corporation_id' => $corporationId,
                    'count' => count($ids)
                ]);
                return $ids;
            }
        } catch (\Exception $e) {
            \Log::warning('getCorporationCharacterIds: Failed to query via affiliation relationship', [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 2: Direct query on character_affiliations table
        try {
            $ids = DB::table('character_affiliations')
                ->where('corporation_id', $corporationId)
                ->pluck('character_id')
                ->toArray();
            
            if (!empty($ids)) {
                \Log::debug('getCorporationCharacterIds: Found via direct table query', [
                    'corporation_id' => $corporationId,
                    'count' => count($ids)
                ]);
                return $ids;
            }
        } catch (\Exception $e) {
            \Log::error('getCorporationCharacterIds: Failed to query affiliations table', [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 3: Try direct corporation_id field on character_infos (legacy support)
        try {
            $ids = DB::table('character_infos')
                ->where('corporation_id', $corporationId)
                ->pluck('character_id')
                ->toArray();
            
            if (!empty($ids)) {
                \Log::debug('getCorporationCharacterIds: Found via character_infos table', [
                    'corporation_id' => $corporationId,
                    'count' => count($ids)
                ]);
                return $ids;
            }
        } catch (\Exception $e) {
            \Log::error('getCorporationCharacterIds: Failed to query character_infos table', [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage()
            ]);
        }
        
        \Log::warning('getCorporationCharacterIds: No characters found for corporation', [
            'corporation_id' => $corporationId
        ]);
        
        return [];
    }

    /**
     * FIXED: Get main character ID for a character (for ranking purposes)
     */
    private function getMainCharacterIdForCharacter($characterId)
    {
        try {
            $character = CharacterInfo::find($characterId);
            if (!$character) {
                \Log::debug('getMainCharacterIdForCharacter: Character not found', [
                    'character_id' => $characterId
                ]);
                return $characterId;
            }
            
            // Try to get user_id
            $userId = $character->user_id;
            if (!$userId) {
                \Log::debug('getMainCharacterIdForCharacter: No user_id on character', [
                    'character_id' => $characterId
                ]);
                return $characterId;
            }
            
            // Try to get main_character_id from users table
            $mainCharId = DB::table('users')
                ->where('id', $userId)
                ->value('main_character_id');
            
            // Return the main character ID if found, otherwise return the original character ID
            return $mainCharId ?? $characterId;
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
        if ($userId) {
            // Clear member dashboard cache for specific user
            $currentMonth = Carbon::now()->startOfMonth()->format('Y-m');
            Cache::forget('dashboard.member.' . $userId . '.' . $currentMonth);

            // Clear director dashboard cache if corporation ID provided
            if ($corporationId) {
                Cache::forget('dashboard.director.' . $userId . '.' . $corporationId . '.' . $currentMonth);
            }
        } else {
            // Clear all dashboard caches (nuclear option - use sparingly)
            Cache::flush();
        }
    }
}
