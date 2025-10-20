<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Analytics\DashboardMetricsService;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MoonExtraction;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Carbon\Carbon;

class DashboardController extends Controller
{
    protected $metricsService;

    public function __construct(DashboardMetricsService $metricsService)
    {
        $this->metricsService = $metricsService;
    }

    /**
     * Display the appropriate dashboard based on user permissions
     */
    public function index()
    {
        // Check if user has director permissions
        if ($this->hasDirectorPermissions()) {
            return $this->directorDashboard();
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

        return [
            'all_ore_value' => $this->calculateTotalValue($miningData),
            'all_ore_quantity' => $miningData->sum('quantity'),
            'moon_ore_value' => $this->calculateTotalValue($moonOreData),
            'moon_ore_quantity' => $moonOreData->sum('quantity'),
        ];
    }

    /**
     * Get top miners ranking by account (not individual characters)
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

        $miningData = $query->with('character')->get();

        // Group by main character (account)
        $grouped = $miningData->groupBy(function($entry) {
            return $this->getMainCharacterIdForCharacter($entry->character_id);
        });

        $rankings = [];
        foreach ($grouped as $mainCharId => $entries) {
            $totalValue = $this->calculateTotalValue($entries);
            
            $character = CharacterInfo::find($mainCharId);
            
            $rankings[] = [
                'main_character_id' => $mainCharId,
                'character_name' => $character->name ?? 'Unknown',
                'corporation_name' => $character->corporation->name ?? 'Unknown',
                'total_value' => $totalValue,
                'total_quantity' => $entries->sum('quantity'),
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
     */
    private function aggregateTopMiners($miningData, $limit)
    {
        // Group by main character
        $grouped = $miningData->groupBy(function($entry) {
            return $this->getMainCharacterIdForCharacter($entry->character_id);
        });

        $miners = [];
        foreach ($grouped as $mainCharId => $entries) {
            $totalValue = $this->calculateTotalValue($entries);
            $character = CharacterInfo::find($mainCharId);
            
            $miners[] = [
                'character_id' => $mainCharId,
                'character_name' => $character->name ?? 'Unknown',
                'total_value' => $totalValue,
                'total_quantity' => $entries->sum('quantity'),
            ];
        }

        usort($miners, function($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });

        return array_slice($miners, 0, $limit);
    }

    /**
     * Get mining performance chart data for last 12 months
     */
    private function getMiningPerformanceLast12Months($characterIds)
    {
        $months = [];
        $data = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();
            
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

        // Add current month (partial)
        $currentMonth = Carbon::now()->startOfMonth();
        $currentValue = MiningLedger::whereIn('character_id', $characterIds)
            ->where('date', '>=', $currentMonth)
            ->whereNotNull('processed_at')
            ->get()
            ->sum(function($entry) {
                return $this->calculateEntryValue($entry);
            });

        $months[] = $currentMonth->format('Y-m');
        $data[] = $currentValue;

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
            'datasets' => [
                [
                    'label' => 'Refined Value',
                    'data' => $refinedValue,
                ],
                [
                    'label' => 'Tax Paid',
                    'data' => $taxPaid,
                ],
                [
                    'label' => 'Event Bonus',
                    'data' => $eventBonus,
                ]
            ],
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
     */
    private function getCorpMoonMiningPerformanceLast12Months($corporationId)
    {
        $characterIds = $this->getCorporationCharacterIds($corporationId);
        $moonOreTypeIds = $this->getMoonOreTypeIds();

        $months = [];
        $data = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();
            
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

        // Add current month
        $currentMonth = Carbon::now()->startOfMonth();
        $currentValue = MiningLedger::whereIn('character_id', $characterIds)
            ->whereIn('type_id', $moonOreTypeIds)
            ->where('date', '>=', $currentMonth)
            ->whereNotNull('processed_at')
            ->get()
            ->sum(function($entry) {
                return $this->calculateEntryValue($entry);
            });

        $months[] = $currentMonth->format('Y-m');
        $data[] = $currentValue;

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
        $data = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            
            $tax = MiningTax::whereIn('character_id', $characterIds)
                ->where('month', $month->format('Y-m-01'))
                ->sum('amount_owed');

            $months[] = $month->format('Y-m');
            $data[] = $tax;
        }

        return [
            'labels' => $months,
            'data' => $data,
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

    // ==================== UTILITY METHODS ====================

    private function hasDirectorPermissions()
    {
        return auth()->user()->hasPermission('mining-manager.director');
    }

    private function getUserCharacterIds($user)
    {
        return CharacterInfo::where('user_id', $user->id)->pluck('character_id')->toArray();
    }

    private function getMainCharacterId($user)
    {
        return $user->main_character_id ?? $this->getUserCharacterIds($user)[0] ?? null;
    }

    private function getUserCorporationId()
    {
        $mainCharId = auth()->user()->main_character_id;
        $character = CharacterInfo::find($mainCharId);
        return $character->corporation_id ?? null;
    }

    private function getCorporationCharacterIds($corporationId)
    {
        return CharacterInfo::where('corporation_id', $corporationId)->pluck('character_id')->toArray();
    }

    private function getMainCharacterIdForCharacter($characterId)
    {
        $character = CharacterInfo::find($characterId);
        if (!$character) return $characterId;
        
        $userId = $character->user_id;
        $mainCharId = DB::table('users')->where('id', $userId)->value('main_character_id');
        
        return $mainCharId ?? $characterId;
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

    private function calculateTotalValue($miningData)
    {
        return $miningData->sum(function($entry) {
            return $this->calculateEntryValue($entry);
        });
    }

    private function calculateEntryValue($entry)
    {
        // Get price from cache or calculate
        $price = $this->metricsService->getOrePrice($entry->type_id);
        return $entry->quantity * $price;
    }

    private function calculateTaxForPeriod($characterIds, $startDate, $endDate)
    {
        return MiningTax::whereIn('character_id', $characterIds)
            ->whereBetween('month', [$startDate->format('Y-m-01'), $endDate->format('Y-m-01')])
            ->sum('amount_owed');
    }

    private function isMoonOre($typeId)
    {
        $moonOreTypeIds = $this->getMoonOreTypeIds();
        return in_array($typeId, $moonOreTypeIds);
    }

    private function getMoonOreTypeIds()
    {
        $rarities = config('mining-manager.moon_ore_rarity', []);
        $typeIds = [];
        foreach ($rarities as $rarity => $ids) {
            $typeIds = array_merge($typeIds, $ids);
        }
        return $typeIds;
    }

    private function getOreGroup($typeId)
    {
        if ($this->isMoonOre($typeId)) return 'Moon';
        
        $iceTypeIds = config('mining-manager.ore_categories.ice', []);
        if (in_array($typeId, $iceTypeIds)) return 'Ice';
        
        $gasTypeIds = config('mining-manager.ore_categories.gas', []);
        if (in_array($typeId, $gasTypeIds)) return 'Gas';
        
        $abyssalTypeIds = config('mining-manager.ore_categories.abyssal_ore', []);
        if (in_array($typeId, $abyssalTypeIds)) return 'Abyssal';
        
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
}
