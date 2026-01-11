<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Analytics\DashboardMetricsService;
use MiningManager\Services\Pricing\MarketDataService;
use MiningManager\Services\Character\CharacterInfoService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MoonExtraction;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Carbon\Carbon;

class DashboardController extends Controller
{
    protected $metricsService;
    protected $marketDataService;
    protected $characterInfoService;

    public function __construct(
        DashboardMetricsService $metricsService,
        MarketDataService $marketDataService,
        CharacterInfoService $characterInfoService
    ) {
        $this->metricsService = $metricsService;
        $this->marketDataService = $marketDataService;
        $this->characterInfoService = $characterInfoService;
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
        $miningIncomeChart = $this->getMiningIncomeLast12Months($characterIds);

        return view('mining-manager::dashboard.member', compact(
            'currentMonthStats',
            'last12MonthsStats',
            'topMinersAllOre',
            'topMinersMoonOre',
            'userRankAllOre',
            'userRankMoonOre',
            'miningPerformanceChart',
            'miningVolumeByGroupChart',
            'miningIncomeChart'
        ));
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
        $miningTaxChart = $this->getMiningTaxLast12Months($corporationId);
        $eventTaxChart = $this->getEventTaxLast12Months($corporationId);

        return view('mining-manager::dashboard.combined-director', compact(
            // Personal stats
            'personalCurrentMonthStats',
            'personalLast12MonthsStats',
            'userRankAllOre',
            'userRankMoonOre',
            'personalMiningPerformanceChart',
            'personalMiningVolumeByGroupChart',
            'personalMiningIncomeChart',
            // Corporation stats
            'corpCurrentMonthStats',
            'corpLast12MonthsStats',
            'topMinersAllOre',
            'topMinersMoonOre',
            'topMinersOverallAllOre',
            'topMinersOverallMoonOre',
            'topMinersLastMonthAllOre',
            'topMinersLastMonthMoonOre',
            'corpMiningPerformanceChart',
            'moonMiningPerformanceChart',
            'miningTaxChart',
            'eventTaxChart'
        ));
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
     * Get last 12 months statistics for member
     */
    private function getMemberLast12MonthsStats($characterIds, $startDate)
    {
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
        $months = [];
        $data = [];

        // Loop from 11 months ago to current month (i=0 is current month)
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            // For current month, use today as end date instead of end of month
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
     * Get mining volume by group chart
     */
    private function getMiningVolumeByGroup($characterIds, $startDate)
    {
        $miningData = MiningLedger::whereIn('character_id', $characterIds)
            ->where('date', '>=', $startDate)
            ->whereNotNull('processed_at')
            ->get();

        $groups = [
            'Ice' => [],
            'Moon' => [],
            'Ore' => [],
            'Gas' => [],
            'Abyssal' => []
        ];

        foreach ($miningData as $entry) {
            $group = $this->getOreGroup($entry->type_id);
            if (isset($groups[$group])) {
                $groups[$group][] = $entry;
            }
        }

        $labels = [];
        $data = [];

        foreach ($groups as $groupName => $entries) {
            if (count($entries) > 0) {
                $labels[] = $groupName;
                $data[] = collect($entries)->sum('quantity');
            }
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    /**
     * Get mining income chart data with refined value, tax, and events
     */
    private function getMiningIncomeLast12Months($characterIds)
    {
        $months = [];
        $refinedValue = [];
        $taxPaid = [];
        $eventBonus = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();
            
            // Refined value
            $value = MiningLedger::whereIn('character_id', $characterIds)
                ->whereBetween('date', [$month, $monthEnd])
                ->whereNotNull('processed_at')
                ->get()
                ->sum(function($entry) {
                    return $this->calculateEntryValue($entry);
                });

            // Tax paid
            $tax = MiningTax::whereIn('character_id', $characterIds)
                ->where('month', $month->format('Y-m-01'))
                ->where('status', 'paid')
                ->sum('amount_paid');

            // Event bonus (placeholder - implement based on your event system)
            $events = 0;

            $months[] = $month->format('Y-m');
            $refinedValue[] = $value;
            $taxPaid[] = $tax;
            $eventBonus[] = $events;
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
     */
    private function getCorpMiningPerformanceLast12Months($corporationId)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);
        return $this->getMiningPerformanceLast12Months($characterIds);
    }

    /**
     * Get corporation moon mining performance chart
     * FIXED: Current month (i=0) is included in the loop, no need to add it twice
     */
    private function getCorpMoonMiningPerformanceLast12Months($corporationId)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);
        $moonOreTypeIds = $this->getMoonOreTypeIds();

        $months = [];
        $data = [];

        // Loop from 11 months ago to current month (i=0 is current month)
        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            // For current month, use today as end date instead of end of month
            if ($i === 0) {
                $monthEnd = Carbon::now();
            }

            $totalValue = MiningLedger::whereIn('character_id', $characterIds)
                ->whereIn('type_id', $moonOreTypeIds)
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
     * Get mining tax chart for last 12 months
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
     */
    private function getEventTaxLast12Months($corporationId)
    {
        // Placeholder - implement based on your event tax system
        $months = [];
        $data = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $months[] = $month->format('Y-m');
            $data[] = 0; // Replace with actual event tax calculation
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
}
