<?php

namespace MiningManager\Services\Analytics;

use MiningManager\Models\MiningReport;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ReportGenerationService
{
    /**
     * Mining analytics service
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
     * Generate a comprehensive mining report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string $type
     * @param string $format
     * @return MiningReport
     */
    public function generateReport(Carbon $startDate, Carbon $endDate, string $type = 'custom', string $format = 'json'): MiningReport
    {
        // Collect report data
        $reportData = $this->collectReportData($startDate, $endDate);

        // Create report record
        $report = MiningReport::create([
            'report_type' => $type,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'format' => $format,
            'data' => json_encode($reportData),
            'generated_at' => Carbon::now(),
            'generated_by' => auth()->user()->name ?? 'system',
        ]);

        // Generate file if not JSON
        if ($format !== 'json') {
            $filePath = $this->generateReportFile($report, $reportData, $format);
            $report->update(['file_path' => $filePath]);
        }

        return $report;
    }

    /**
     * Collect all report data.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function collectReportData(Carbon $startDate, Carbon $endDate): array
    {
        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
                'days' => $startDate->diffInDays($endDate) + 1,
            ],
            'summary' => $this->getSummary($startDate, $endDate),
            'miners' => $this->getMinerData($startDate, $endDate),
            'ore_types' => $this->getOreTypeData($startDate, $endDate),
            'systems' => $this->getSystemData($startDate, $endDate),
            'daily_breakdown' => $this->getDailyBreakdown($startDate, $endDate),
            'taxes' => $this->getTaxData($startDate, $endDate),
        ];
    }

    /**
     * Get summary statistics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getSummary(Carbon $startDate, Carbon $endDate): array
    {
        $totalQuantity = $this->analyticsService->getTotalVolume($startDate, $endDate);
        $totalValue = $this->analyticsService->getTotalValue($startDate, $endDate);
        $uniqueMiners = $this->analyticsService->getUniqueMinerCount($startDate, $endDate);

        return [
            'total_quantity' => $totalQuantity,
            'total_value' => $totalValue,
            'unique_miners' => $uniqueMiners,
            'average_per_miner' => $uniqueMiners > 0 ? $totalQuantity / $uniqueMiners : 0,
            'average_value_per_miner' => $uniqueMiners > 0 ? $totalValue / $uniqueMiners : 0,
        ];
    }

    /**
     * Get miner data for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getMinerData(Carbon $startDate, Carbon $endDate): array
    {
        $topMinersCount = config('mining-manager.reports.top_miners_count', 10);
        $topMiners = $this->analyticsService->getTopMiners($startDate, $endDate, $topMinersCount);

        return [
            'top_miners' => $topMiners->map(function ($miner) {
                return [
                    'name' => $miner->name,
                    'quantity' => $miner->total_quantity,
                    'value' => $miner->total_value ?? 0,
                ];
            })->toArray(),
            'total_count' => $this->analyticsService->getUniqueMinerCount($startDate, $endDate),
        ];
    }

    /**
     * Get ore type data for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getOreTypeData(Carbon $startDate, Carbon $endDate): array
    {
        $oreBreakdown = $this->analyticsService->getOreBreakdown($startDate, $endDate);

        return $oreBreakdown->map(function ($ore) {
            return [
                'name' => $ore->ore_name,
                'quantity' => $ore->total_quantity,
                'value' => $ore->total_value ?? 0,
            ];
        })->toArray();
    }

    /**
     * Get system data for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getSystemData(Carbon $startDate, Carbon $endDate): array
    {
        $systemBreakdown = $this->analyticsService->getSystemBreakdown($startDate, $endDate);

        return $systemBreakdown->map(function ($system) {
            return [
                'name' => $system->system_name,
                'quantity' => $system->total_quantity,
                'unique_miners' => $system->unique_miners,
            ];
        })->toArray();
    }

    /**
     * Get daily breakdown for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getDailyBreakdown(Carbon $startDate, Carbon $endDate): array
    {
        $dailyTrends = $this->analyticsService->getDailyTrends($startDate, $endDate);

        return $dailyTrends->map(function ($day) {
            return [
                'date' => $day->date,
                'quantity' => $day->total_quantity,
                'value' => $day->total_value ?? 0,
                'miners' => $day->unique_miners,
            ];
        })->toArray();
    }

    /**
     * Get tax data for report.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getTaxData(Carbon $startDate, Carbon $endDate): array
    {
        $taxes = MiningTax::whereBetween('month', [$startDate->startOfMonth(), $endDate->endOfMonth()])
            ->get();

        return [
            'total_owed' => $taxes->sum('amount_owed'),
            'total_paid' => $taxes->sum('amount_paid'),
            'unpaid' => $taxes->where('status', 'unpaid')->sum('amount_owed'),
            'overdue' => $taxes->where('status', 'overdue')->sum('amount_owed'),
            'collection_rate' => $taxes->sum('amount_owed') > 0 
                ? ($taxes->sum('amount_paid') / $taxes->sum('amount_owed')) * 100 
                : 0,
        ];
    }

    /**
     * Generate report file in specified format.
     *
     * @param MiningReport $report
     * @param array $data
     * @param string $format
     * @return string
     */
    private function generateReportFile(MiningReport $report, array $data, string $format): string
    {
        $filename = sprintf(
            'mining_report_%s_%s_%s.%s',
            $report->report_type,
            $report->start_date->format('Ymd'),
            $report->id,
            $format
        );

        $path = "reports/{$filename}";

        switch ($format) {
            case 'csv':
                $this->generateCsvFile($path, $data);
                break;
            case 'pdf':
                $this->generatePdfFile($path, $data);
                break;
        }

        return $path;
    }

    /**
     * Generate CSV file.
     *
     * @param string $path
     * @param array $data
     * @return void
     */
    private function generateCsvFile(string $path, array $data): void
    {
        $csv = fopen('php://temp', 'r+');

        // Write miners data
        fputcsv($csv, ['Top Miners']);
        fputcsv($csv, ['Name', 'Quantity', 'Value (ISK)']);
        foreach ($data['miners']['top_miners'] as $miner) {
            fputcsv($csv, [
                $miner['name'],
                $miner['quantity'],
                number_format($miner['value'], 2),
            ]);
        }

        fputcsv($csv, []); // Empty line

        // Write ore types data
        fputcsv($csv, ['Ore Types']);
        fputcsv($csv, ['Ore Name', 'Quantity', 'Value (ISK)']);
        foreach ($data['ore_types'] as $ore) {
            fputcsv($csv, [
                $ore['name'],
                $ore['quantity'],
                number_format($ore['value'], 2),
            ]);
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        Storage::put($path, $content);
    }

    /**
     * Generate PDF file.
     *
     * @param string $path
     * @param array $data
     * @return void
     */
    private function generatePdfFile(string $path, array $data): void
    {
        // PDF generation would require a library like DomPDF or TCPDF
        // This is a placeholder for now
        throw new \Exception('PDF generation not yet implemented');
    }

    /**
     * Export report to CSV.
     *
     * @param MiningReport $report
     * @param string $path
     * @return void
     */
    public function exportToCsv(MiningReport $report, string $path): void
    {
        $data = json_decode($report->data, true);
        $this->generateCsvFile($path, $data);
    }

    /**
     * Export report to PDF.
     *
     * @param MiningReport $report
     * @param string $path
     * @return void
     */
    public function exportToPdf(MiningReport $report, string $path): void
    {
        $data = json_decode($report->data, true);
        $this->generatePdfFile($path, $data);
    }
}
