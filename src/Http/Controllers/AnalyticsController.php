<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Analytics\MiningAnalyticsService;
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

        // Get analytics data
        $analytics = [
            'total_volume' => $this->analyticsService->getTotalVolume($startDate, $endDate),
            'total_value' => $this->analyticsService->getTotalValue($startDate, $endDate),
            'unique_miners' => $this->analyticsService->getUniqueMinerCount($startDate, $endDate),
            'top_miners' => $this->analyticsService->getTopMiners($startDate, $endDate, 20),
            'ore_breakdown' => $this->analyticsService->getOreBreakdown($startDate, $endDate),
            'system_breakdown' => $this->analyticsService->getSystemBreakdown($startDate, $endDate),
            'daily_trends' => $this->analyticsService->getDailyTrends($startDate, $endDate),
        ];

        return view('mining-manager::analytics.index', compact('analytics', 'startDate', 'endDate'));
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
    
        return view('mining-manager::analytics.compare', compact('comparisonData'));
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
    
        // Calculate differences and percentages
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
    
        // Volume comparison
        $volumeDiff = $period1['total_volume'] - $period2['total_volume'];
        $volumeChange = $period2['total_volume'] > 0 
            ? (($volumeDiff / $period2['total_volume']) * 100) 
            : 0;
        
        $metrics[] = [
            'label' => trans('mining-manager::analytics.total_volume'),
            'value_1' => number_format($period1['total_volume'], 0) . ' m³',
            'value_2' => number_format($period2['total_volume'], 0) . ' m³',
            'change' => number_format(abs($volumeChange), 1) . '%',
            'change_type' => $volumeChange > 0 ? 'positive' : ($volumeChange < 0 ? 'negative' : 'neutral'),
        ];
    
        // Value comparison
        $valueDiff = $period1['total_value'] - $period2['total_value'];
        $valueChange = $period2['total_value'] > 0 
            ? (($valueDiff / $period2['total_value']) * 100) 
            : 0;
        
        $metrics[] = [
            'label' => trans('mining-manager::analytics.total_value'),
            'value_1' => number_format($period1['total_value'] / 1000000, 2) . 'M ISK',
            'value_2' => number_format($period2['total_value'] / 1000000, 2) . 'M ISK',
            'change' => number_format(abs($valueChange), 1) . '%',
            'change_type' => $valueChange > 0 ? 'positive' : ($valueChange < 0 ? 'negative' : 'neutral'),
        ];
    
        // Miners comparison
        $minersDiff = $period1['unique_miners'] - $period2['unique_miners'];
        $minersChange = $period2['unique_miners'] > 0 
            ? (($minersDiff / $period2['unique_miners']) * 100) 
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
    
        // Value insight
        $valueDiff = (($period1['total_value'] - $period2['total_value']) / $period2['total_value']) * 100;
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
    
        // Miner activity insight
        $minerDiff = (($period1['unique_miners'] - $period2['unique_miners']) / $period2['unique_miners']) * 100;
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
     * Compare miners (placeholder - implement based on your needs)
     */
    private function compareMiners(Request $request)
    {
        // TODO: Implement miner comparison logic
        return [];
    }
    
    /**
     * Compare systems (placeholder - implement based on your needs)
     */
    private function compareSystems(Request $request)
    {
        // TODO: Implement system comparison logic
        return [];
    }
    
    /**
     * Compare ore types (placeholder - implement based on your needs)
     */
    private function compareOres(Request $request)
    {
        // TODO: Implement ore comparison logic
        return [];
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
}
