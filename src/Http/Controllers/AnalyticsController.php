<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Analytics\MiningAnalyticsService;
use MiningManager\Services\Moon\MoonAnalyticsService;
use MiningManager\Models\MiningLedger;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Analytics service
     *
     * @var MiningAnalyticsService
     */
    protected $analyticsService;

    /**
     * Constructor
     *
     * @param MiningAnalyticsService $analyticsService
     */
    public function __construct(MiningAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Display analytics overview
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Get date range from request or default to last 30 days
        $startDate = $request->input('start_date') 
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->subDays(30);
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now();

        // Get group_by and ore_category params
        $groupBy = $request->input('group_by', 'account');
        $oreCategory = $request->input('ore_category');

        // Get top miners based on grouping
        $topMiners = $groupBy === 'character'
            ? $this->analyticsService->getTopMiners($startDate, $endDate, 20)
            : $this->analyticsService->getTopMinersByAccount($startDate, $endDate, 20);

        // Get analytics data
        $analytics = [
            'total_volume' => $this->analyticsService->getTotalVolume($startDate, $endDate),
            'total_value' => $this->analyticsService->getTotalValue($startDate, $endDate),
            'unique_miners' => $this->analyticsService->getUniqueMinerCount($startDate, $endDate),
            'top_miners' => $topMiners,
            'ore_breakdown' => $this->analyticsService->getOreBreakdown($startDate, $endDate, $oreCategory),
            'system_breakdown' => $this->analyticsService->getSystemBreakdown($startDate, $endDate),
            'daily_trends' => $this->analyticsService->getDailyTrends($startDate, $endDate),
        ];

        return view('mining-manager::analytics.index', compact('analytics', 'startDate', 'endDate', 'groupBy', 'oreCategory'));
    }

    /**
     * Display charts page
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function charts(Request $request)
    {
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->subDays(30);
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now();

        // Get chart data
        $chartData = [
            'mining_trends' => $this->analyticsService->getMiningTrendData($startDate, $endDate),
            'ore_distribution' => $this->analyticsService->getOreDistributionData($startDate, $endDate),
            'miner_activity' => $this->analyticsService->getMinerActivityData($startDate, $endDate),
            'system_activity' => $this->analyticsService->getSystemActivityData($startDate, $endDate),
        ];

        return view('mining-manager::analytics.charts', compact('chartData', 'startDate', 'endDate'));
    }

    /**
     * Display detailed tables
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function tables(Request $request)
    {
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->subDays(30);
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now();

        // Get detailed table data
        $tableData = [
            'miner_stats' => $this->analyticsService->getMinerStatistics($startDate, $endDate),
            'ore_stats' => $this->analyticsService->getOreStatistics($startDate, $endDate),
            'system_stats' => $this->analyticsService->getSystemStatistics($startDate, $endDate),
        ];

        return view('mining-manager::analytics.tables', compact('tableData', 'startDate', 'endDate'));
    }

    /**
     * Display comparative analysis
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function compare(Request $request)
    {
        // Check if comparison data should be generated
        if (!$request->has('comparison_type')) {
            return view('mining-manager::analytics.compare');
        }

        $comparisonType = $request->input('comparison_type');
        $comparisonData = [];

        switch ($comparisonType) {
            case 'periods':
                $comparisonData = $this->comparePeriods($request);
                break;
            case 'miners':
                $comparisonData = $this->compareMiners($request);
                break;
            case 'systems':
                $comparisonData = $this->compareSystems($request);
                break;
            case 'ores':
                $comparisonData = $this->compareOres($request);
                break;
        }

        return view('mining-manager::analytics.compare', compact('comparisonData', 'comparisonType'));
    }
    
    /**
     * Compare two time periods
     *
     * @param Request $request
     * @return array
     */
    private function comparePeriods(Request $request)
    {
        $period1Start = Carbon::parse($request->input('period1_start'));
        $period1End = Carbon::parse($request->input('period1_end'));
        $period2Start = Carbon::parse($request->input('period2_start'));
        $period2End = Carbon::parse($request->input('period2_end'));

        // Get data for both periods
        $period1Data = $this->getPeriodData($period1Start, $period1End);
        $period2Data = $this->getPeriodData($period2Start, $period2End);

        // Calculate differences and percentages (change = P2 relative to P1)
        $metrics = $this->calculateMetricComparisons($period1Data, $period2Data);

        // Determine which period performed better
        $topPerformerPeriod = $period1Data['total_value'] > $period2Data['total_value'] ? 1 : 2;

        return [
            'labels' => [
                $period1Start->format('M d') . ' - ' . $period1End->format('M d, Y'),
                $period2Start->format('M d') . ' - ' . $period2End->format('M d, Y'),
            ],
            'metrics' => $metrics,
            'volume_data' => [$period1Data['total_volume'], $period2Data['total_volume']],
            'volume_m3_data' => [$period1Data['total_volume_m3'], $period2Data['total_volume_m3']],
            'value_data' => [$period1Data['total_value'], $period2Data['total_value']],
            'trend_labels' => $this->getTrendLabels($period1Start, $period1End),
            'trend_datasets' => $this->getTrendDatasets($period1Start, $period1End, $period2Start, $period2End),
            'detailed' => $this->getDetailedComparison($period1Data, $period2Data),
            'top_period_1' => $this->analyticsService->getTopMiners($period1Start, $period1End, 5),
            'top_period_2' => $this->analyticsService->getTopMiners($period2Start, $period2End, 5),
            'top_performer_period' => $topPerformerPeriod,
            'insights' => $this->generateInsights($period1Data, $period2Data),
        ];
    }
    
    /**
     * Get data for a specific period
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getPeriodData($startDate, $endDate)
    {
        return [
            'total_volume' => $this->analyticsService->getTotalVolume($startDate, $endDate),
            'total_volume_m3' => $this->analyticsService->getTotalVolumeM3($startDate, $endDate),
            'total_value' => $this->analyticsService->getTotalValue($startDate, $endDate),
            'unique_miners' => $this->analyticsService->getUniqueMinerCount($startDate, $endDate),
            'ore_breakdown' => $this->analyticsService->getOreBreakdown($startDate, $endDate),
            'system_breakdown' => $this->analyticsService->getSystemBreakdown($startDate, $endDate),
        ];
    }
    
    /**
     * Calculate metric comparisons
     *
     * @param array $period1
     * @param array $period2
     * @return array
     */
    private function calculateMetricComparisons($period1, $period2)
    {
        $metrics = [];

        // Quantity comparison (units)
        $qtyDiff = $period2['total_volume'] - $period1['total_volume'];
        $qtyChange = $period1['total_volume'] > 0
            ? (($qtyDiff / $period1['total_volume']) * 100)
            : 0;

        $metrics[] = [
            'label' => trans('mining-manager::analytics.total_quantity_units'),
            'value_1' => number_format($period1['total_volume'], 0) . ' units',
            'value_2' => number_format($period2['total_volume'], 0) . ' units',
            'change' => number_format(abs($qtyChange), 1) . '%',
            'change_type' => $qtyChange > 0 ? 'positive' : ($qtyChange < 0 ? 'negative' : 'neutral'),
        ];

        // Volume comparison (m³)
        $m3Diff = $period2['total_volume_m3'] - $period1['total_volume_m3'];
        $m3Change = $period1['total_volume_m3'] > 0
            ? (($m3Diff / $period1['total_volume_m3']) * 100)
            : 0;

        $metrics[] = [
            'label' => trans('mining-manager::analytics.total_volume_m3'),
            'value_1' => number_format($period1['total_volume_m3'], 0) . ' m³',
            'value_2' => number_format($period2['total_volume_m3'], 0) . ' m³',
            'change' => number_format(abs($m3Change), 1) . '%',
            'change_type' => $m3Change > 0 ? 'positive' : ($m3Change < 0 ? 'negative' : 'neutral'),
        ];

        // Value comparison
        $valueDiff = $period2['total_value'] - $period1['total_value'];
        $valueChange = $period1['total_value'] > 0
            ? (($valueDiff / $period1['total_value']) * 100)
            : 0;

        $metrics[] = [
            'label' => trans('mining-manager::analytics.total_value'),
            'value_1' => number_format($period1['total_value'] / 1000000, 2) . 'M ISK',
            'value_2' => number_format($period2['total_value'] / 1000000, 2) . 'M ISK',
            'change' => number_format(abs($valueChange), 1) . '%',
            'change_type' => $valueChange > 0 ? 'positive' : ($valueChange < 0 ? 'negative' : 'neutral'),
        ];

        // Miners comparison
        $minersDiff = $period2['unique_miners'] - $period1['unique_miners'];
        $minersChange = $period1['unique_miners'] > 0
            ? (($minersDiff / $period1['unique_miners']) * 100)
            : 0;

        $metrics[] = [
            'label' => trans('mining-manager::analytics.unique_miners'),
            'value_1' => number_format($period1['unique_miners']),
            'value_2' => number_format($period2['unique_miners']),
            'change' => number_format(abs($minersChange), 1) . '%',
            'change_type' => $minersChange > 0 ? 'positive' : ($minersChange < 0 ? 'negative' : 'neutral'),
        ];

        return $metrics;
    }
    
    /**
     * Generate insights based on comparison
     *
     * @param array $period1
     * @param array $period2
     * @return array
     */
    private function generateInsights($period1, $period2)
    {
        $insights = [];

        // Value insight: change from P1 to P2
        $valueDiff = $period1['total_value'] > 0
            ? (($period2['total_value'] - $period1['total_value']) / $period1['total_value']) * 100
            : 0;
        if (abs($valueDiff) > 10) {
            $insights[] = [
                'type' => $valueDiff > 0 ? 'success' : 'warning',
                'icon' => $valueDiff > 0 ? 'arrow-up' : 'arrow-down',
                'title' => $valueDiff > 0
                    ? trans('mining-manager::analytics.significant_increase')
                    : trans('mining-manager::analytics.significant_decrease'),
                'message' => trans('mining-manager::analytics.value_changed_by', [
                    'percent' => number_format(abs($valueDiff), 1)
                ]),
            ];
        }

        // Miner activity insight: change from P1 to P2
        $minerDiff = $period1['unique_miners'] > 0
            ? (($period2['unique_miners'] - $period1['unique_miners']) / $period1['unique_miners']) * 100
            : 0;
        if (abs($minerDiff) > 15) {
            $insights[] = [
                'type' => 'info',
                'icon' => 'users',
                'title' => trans('mining-manager::analytics.miner_activity_change'),
                'message' => trans('mining-manager::analytics.miner_count_changed_by', [
                    'percent' => number_format(abs($minerDiff), 1)
                ]),
            ];
        }

        return $insights;
    }
    
    /**
     * Compare miners - show top miners by quantity and value
     */
    private function compareMiners(Request $request)
    {
        $startDate = $request->input('miner_start')
            ? Carbon::parse($request->input('miner_start'))
            : Carbon::now()->subDays(30);
        $endDate = $request->input('miner_end')
            ? Carbon::parse($request->input('miner_end'))
            : Carbon::now();
        $limit = $request->input('limit', 10);

        // Get top miners by quantity
        $topByQuantity = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('character_id, SUM(quantity) as total_quantity, SUM(total_value) as total_value, COUNT(DISTINCT date) as days_active')
            ->groupBy('character_id')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->with('character')
            ->get()
            ->map(function($ledger) {
                return [
                    'character_id' => $ledger->character_id,
                    'character_name' => $ledger->character->name ?? 'Unknown',
                    'total_quantity' => $ledger->total_quantity,
                    'total_value' => $ledger->total_value,
                    'days_active' => $ledger->days_active,
                    'avg_per_day' => $ledger->days_active > 0 ? $ledger->total_quantity / $ledger->days_active : 0,
                ];
            });

        // Get top miners by value
        $topByValue = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('character_id, SUM(total_value) as total_value, SUM(quantity) as total_quantity, COUNT(DISTINCT date) as days_active')
            ->groupBy('character_id')
            ->orderByDesc('total_value')
            ->limit($limit)
            ->with('character')
            ->get()
            ->map(function($ledger) {
                return [
                    'character_id' => $ledger->character_id,
                    'character_name' => $ledger->character->name ?? 'Unknown',
                    'total_value' => $ledger->total_value,
                    'total_quantity' => $ledger->total_quantity,
                    'days_active' => $ledger->days_active,
                    'avg_value_per_day' => $ledger->days_active > 0 ? $ledger->total_value / $ledger->days_active : 0,
                ];
            });

        return [
            'by_quantity' => $topByQuantity,
            'by_value' => $topByValue,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Compare systems - show most productive solar systems
     */
    private function compareSystems(Request $request)
    {
        $startDate = $request->input('system_start')
            ? Carbon::parse($request->input('system_start'))
            : Carbon::now()->subDays(30);
        $endDate = $request->input('system_end')
            ? Carbon::parse($request->input('system_end'))
            : Carbon::now();
        $limit = $request->input('limit', 10);

        // Get mining activity by system
        $systemStats = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('solar_system_id')
            ->selectRaw('solar_system_id, SUM(quantity) as total_quantity, SUM(total_value) as total_value, COUNT(DISTINCT character_id) as unique_miners, COUNT(DISTINCT date) as days_active')
            ->groupBy('solar_system_id')
            ->orderByDesc('total_value')
            ->limit($limit)
            ->get()
            ->map(function($stat) {
                // Get system name from SeAT's database
                $systemName = \DB::table('solar_systems')
                    ->where('system_id', $stat->solar_system_id)
                    ->value('name');

                return [
                    'solar_system_id' => $stat->solar_system_id,
                    'system_name' => $systemName ?? 'Unknown System',
                    'total_quantity' => $stat->total_quantity,
                    'total_value' => $stat->total_value,
                    'unique_miners' => $stat->unique_miners,
                    'days_active' => $stat->days_active,
                    'avg_value_per_day' => $stat->days_active > 0 ? $stat->total_value / $stat->days_active : 0,
                ];
            });

        return [
            'systems' => $systemStats,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Compare ore types - show most valuable and most mined ores
     */
    private function compareOres(Request $request)
    {
        $startDate = $request->input('ore_start')
            ? Carbon::parse($request->input('ore_start'))
            : Carbon::now()->subDays(30);
        $endDate = $request->input('ore_end')
            ? Carbon::parse($request->input('ore_end'))
            : Carbon::now();
        $limit = $request->input('limit', 15);

        // Get ore statistics
        $oreStats = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('type_id, SUM(quantity) as total_quantity, SUM(total_value) as total_value, COUNT(DISTINCT character_id) as miners_count')
            ->groupBy('type_id')
            ->orderByDesc('total_value')
            ->limit($limit)
            ->get()
            ->map(function($stat) {
                // Get ore name from SeAT's database
                $oreName = \DB::table('invTypes')
                    ->where('typeID', $stat->type_id)
                    ->value('typeName');

                return [
                    'type_id' => $stat->type_id,
                    'ore_name' => $oreName ?? 'Unknown Ore',
                    'total_quantity' => $stat->total_quantity,
                    'total_value' => $stat->total_value,
                    'miners_count' => $stat->miners_count,
                    'avg_value_per_unit' => $stat->total_quantity > 0 ? $stat->total_value / $stat->total_quantity : 0,
                ];
            });

        // Get most mined ores (by quantity)
        $mostMined = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('type_id, SUM(quantity) as total_quantity')
            ->groupBy('type_id')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get()
            ->map(function($stat) {
                $oreName = \DB::table('invTypes')
                    ->where('typeID', $stat->type_id)
                    ->value('typeName');

                return [
                    'type_id' => $stat->type_id,
                    'ore_name' => $oreName ?? 'Unknown Ore',
                    'total_quantity' => $stat->total_quantity,
                ];
            });

        return [
            'by_value' => $oreStats,
            'by_quantity' => $mostMined,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Display moon analytics
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function moons(Request $request)
    {
        $moonService = app(MoonAnalyticsService::class);

        $viewMode = $request->input('view_mode', 'monthly');
        $month = $request->input('month')
            ? Carbon::parse($request->input('month') . '-01')
            : Carbon::now()->startOfMonth();

        $extractionId = $request->input('extraction_id');

        $data = [
            'viewMode' => $viewMode,
            'month' => $month,
            'availableExtractions' => $moonService->getAvailableExtractions($month),
        ];

        if ($viewMode === 'extraction' && $extractionId) {
            $data['extractionData'] = $moonService->getExtractionUtilization((int) $extractionId);
            $data['selectedExtraction'] = $extractionId;
        } else {
            $data['summary'] = $moonService->getSummaryStats($month);
            $data['utilization'] = $moonService->getMoonUtilization($month);
            $data['popularity'] = $moonService->getMoonPopularity($month);
            $data['orePopularity'] = $moonService->getOrePopularity($month);
        }

        return view('mining-manager::analytics.moons', $data);
    }

    /**
     * Export analytics data
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function export(Request $request)
    {
        $format = $request->input('format', 'csv');
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->subDays(30);
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now();

        // Get export data
        $data = $this->analyticsService->getExportData($startDate, $endDate);

        // Generate export based on format
        switch ($format) {
            case 'csv':
                return $this->exportCsv($data, $startDate, $endDate);
            case 'json':
                return $this->exportJson($data);
            default:
                return redirect()->back()->with('error', 'Invalid export format');
        }
    }

    /**
     * Export data as CSV
     *
     * @param array $data
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Http\Response
     */
    private function exportCsv($data, $startDate, $endDate)
    {
        $filename = "mining_analytics_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}.csv";
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($data) {
            $file = fopen('php://output', 'w');
            
            // Write headers
            fputcsv($file, ['Character', 'Ore Type', 'Quantity', 'Value (ISK)', 'System', 'Date']);
            
            // Write data
            foreach ($data as $row) {
                fputcsv($file, $row);
            }
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export data as JSON
     *
     * @param array $data
     * @return \Illuminate\Http\JsonResponse
     */
    private function exportJson($data)
    {
        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="mining_analytics.json"',
        ]);
    }

    /**
     * Get analytics data via AJAX
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function data(Request $request)
    {
        $type = $request->input('type');
        $startDate = Carbon::parse($request->input('start_date'));
        $endDate = Carbon::parse($request->input('end_date'));

        $data = [];

        switch ($type) {
            case 'miners':
                $data = $this->analyticsService->getTopMiners($startDate, $endDate, 50);
                break;
            case 'ore':
                $data = $this->analyticsService->getOreBreakdown($startDate, $endDate);
                break;
            case 'systems':
                $data = $this->analyticsService->getSystemBreakdown($startDate, $endDate);
                break;
            case 'trends':
                $data = $this->analyticsService->getDailyTrends($startDate, $endDate);
                break;
        }

        return response()->json($data);
    }

    /**
     * Get trend labels (date labels) for a period.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getTrendLabels(Carbon $startDate, Carbon $endDate): array
    {
        $labels = [];
        $current = $startDate->copy();

        while ($current->lte($endDate)) {
            $labels[] = $current->format('M d');
            $current->addDay();
        }

        return $labels;
    }

    /**
     * Get trend datasets for two periods for chart comparison.
     *
     * @param Carbon $p1Start
     * @param Carbon $p1End
     * @param Carbon $p2Start
     * @param Carbon $p2End
     * @return array
     */
    private function getTrendDatasets(Carbon $p1Start, Carbon $p1End, Carbon $p2Start, Carbon $p2End): array
    {
        $trends1 = $this->analyticsService->getDailyTrends($p1Start, $p1End);
        $trends2 = $this->analyticsService->getDailyTrends($p2Start, $p2End);

        return [
            [
                'label' => $p1Start->format('M d') . ' - ' . $p1End->format('M d'),
                'data' => $trends1->pluck('total_value')->map(fn($v) => round($v / 1000000, 2))->toArray(),
                'borderColor' => 'rgba(78, 115, 223, 1)',
                'backgroundColor' => 'rgba(78, 115, 223, 0.1)',
                'borderWidth' => 2,
                'pointRadius' => 3,
                'pointBackgroundColor' => 'rgba(78, 115, 223, 1)',
                'tension' => 0.4,
                'fill' => true,
            ],
            [
                'label' => $p2Start->format('M d') . ' - ' . $p2End->format('M d'),
                'data' => $trends2->pluck('total_value')->map(fn($v) => round($v / 1000000, 2))->toArray(),
                'borderColor' => 'rgba(28, 200, 138, 1)',
                'backgroundColor' => 'rgba(28, 200, 138, 0.1)',
                'borderWidth' => 2,
                'pointRadius' => 3,
                'pointBackgroundColor' => 'rgba(28, 200, 138, 1)',
                'tension' => 0.4,
                'fill' => true,
            ],
        ];
    }

    /**
     * Get detailed comparison between two periods as flat rows for the table view.
     *
     * @param array $period1Data
     * @param array $period2Data
     * @return array
     */
    private function getDetailedComparison(array $period1Data, array $period2Data): array
    {
        $rows = [];

        // Compare top ores between periods (change = P2 - P1)
        $ores1 = collect($period1Data['ore_breakdown'] ?? []);
        $ores2 = collect($period2Data['ore_breakdown'] ?? []);

        // Merge all ore type_ids from both periods
        $allOreIds = $ores1->pluck('type_id')->merge($ores2->pluck('type_id'))->unique();

        foreach ($allOreIds->take(8) as $typeId) {
            $ore1 = $ores1->firstWhere('type_id', $typeId);
            $ore2 = $ores2->firstWhere('type_id', $typeId);
            $val1 = $ore1->total_value ?? 0;
            $val2 = $ore2->total_value ?? 0;
            $oreName = ($ore1->ore_name ?? null) ?: ($ore2->ore_name ?? 'Unknown');

            // Change direction: P2 - P1 (positive = P2 is higher)
            $diff = $val2 - $val1;
            $pct = $val1 > 0 ? ($diff / $val1) * 100 : 0;

            $rows[] = [
                'metric' => $oreName . ' (Value)',
                'values' => [
                    number_format($val1 / 1000000, 1) . 'M ISK',
                    number_format($val2 / 1000000, 1) . 'M ISK',
                ],
                'difference' => ($diff >= 0 ? '+' : '') . number_format($diff / 1000000, 1) . 'M',
                'diff_color' => $diff > 0 ? 'success' : ($diff < 0 ? 'danger' : 'muted'),
                'change_percent' => number_format(abs($pct), 1) . '%',
                'change_color' => $pct > 0 ? 'success' : ($pct < 0 ? 'danger' : 'secondary'),
            ];
        }

        // Compare top systems between periods (change = P2 - P1)
        $systems1 = collect($period1Data['system_breakdown'] ?? []);
        $systems2 = collect($period2Data['system_breakdown'] ?? []);

        $allSystemIds = $systems1->pluck('solar_system_id')->merge($systems2->pluck('solar_system_id'))->unique();

        foreach ($allSystemIds->take(5) as $sysId) {
            $sys1 = $systems1->firstWhere('solar_system_id', $sysId);
            $sys2 = $systems2->firstWhere('solar_system_id', $sysId);
            $val1 = $sys1->total_value ?? 0;
            $val2 = $sys2->total_value ?? 0;
            $sysName = ($sys1->system_name ?? null) ?: ($sys2->system_name ?? 'Unknown');

            // Change direction: P2 - P1 (positive = P2 is higher)
            $diff = $val2 - $val1;
            $pct = $val1 > 0 ? ($diff / $val1) * 100 : 0;

            $rows[] = [
                'metric' => $sysName . ' (System)',
                'values' => [
                    number_format($val1 / 1000000, 1) . 'M ISK',
                    number_format($val2 / 1000000, 1) . 'M ISK',
                ],
                'difference' => ($diff >= 0 ? '+' : '') . number_format($diff / 1000000, 1) . 'M',
                'diff_color' => $diff > 0 ? 'success' : ($diff < 0 ? 'danger' : 'muted'),
                'change_percent' => number_format(abs($pct), 1) . '%',
                'change_color' => $pct > 0 ? 'success' : ($pct < 0 ? 'danger' : 'secondary'),
            ];
        }

        return $rows;
    }
}
