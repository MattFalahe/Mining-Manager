<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningReport;
use MiningManager\Services\Analytics\ReportGenerationService;
use Carbon\Carbon;

class GenerateReportsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:generate-reports
                            {--type=monthly : Report type (daily|weekly|monthly|custom)}
                            {--start= : Start date for custom report (YYYY-MM-DD)}
                            {--end= : End date for custom report (YYYY-MM-DD)}
                            {--format=json : Output format (json|csv|pdf)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate mining reports and analytics summaries';

    /**
     * Report generation service
     *
     * @var ReportGenerationService
     */
    protected $reportService;

    /**
     * Create a new command instance.
     *
     * @param ReportGenerationService $reportService
     */
    public function __construct(ReportGenerationService $reportService)
    {
        parent::__construct();
        $this->reportService = $reportService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting report generation...');

        $type = $this->option('type');
        $format = $this->option('format');

        // Determine date range
        [$startDate, $endDate] = $this->getDateRange($type);

        $this->info("Generating {$type} report");
        $this->info("Period: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");
        $this->info("Format: {$format}");

        try {
            // Generate report data
            $reportData = $this->reportService->generateReport($startDate, $endDate, $type);

            // Save report to database
            $report = MiningReport::create([
                'report_type' => $type,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'format' => $format,
                'data' => json_encode($reportData),
                'generated_at' => Carbon::now(),
                'generated_by' => 'system',
            ]);

            $this->info("Report generated successfully!");
            $this->info("Report ID: {$report->id}");

            // Display summary
            $this->displayReportSummary($reportData);

            // Export if requested
            if ($format !== 'json') {
                $exportPath = $this->exportReport($report, $format);
                $this->info("Report exported to: {$exportPath}");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error generating report: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Get date range based on report type
     *
     * @param string $type
     * @return array
     */
    private function getDateRange(string $type): array
    {
        $now = Carbon::now();

        switch ($type) {
            case 'daily':
                return [
                    $now->copy()->subDay()->startOfDay(),
                    $now->copy()->subDay()->endOfDay()
                ];

            case 'weekly':
                return [
                    $now->copy()->subWeek()->startOfWeek(),
                    $now->copy()->subWeek()->endOfWeek()
                ];

            case 'monthly':
                return [
                    $now->copy()->subMonth()->startOfMonth(),
                    $now->copy()->subMonth()->endOfMonth()
                ];

            case 'custom':
                $start = $this->option('start') 
                    ? Carbon::parse($this->option('start'))
                    : $now->copy()->subMonth();
                $end = $this->option('end')
                    ? Carbon::parse($this->option('end'))
                    : $now;
                return [$start, $end];

            default:
                throw new \InvalidArgumentException("Invalid report type: {$type}");
        }
    }

    /**
     * Display report summary to console
     *
     * @param array $reportData
     * @return void
     */
    private function displayReportSummary(array $reportData): void
    {
        $this->info("\n=== Report Summary ===");
        $this->line("Total Miners: " . ($reportData['total_miners'] ?? 0));
        $this->line("Total Ore Mined: " . number_format($reportData['total_quantity'] ?? 0));
        $this->line("Total Value: " . number_format($reportData['total_value'] ?? 0, 2) . " ISK");
        $this->line("Total Tax Collected: " . number_format($reportData['total_tax'] ?? 0, 2) . " ISK");

        if (!empty($reportData['top_miners'])) {
            $this->info("\nTop 5 Miners:");
            foreach (array_slice($reportData['top_miners'], 0, 5) as $miner) {
                $this->line("  - {$miner['name']}: " . number_format($miner['quantity']) . " ore");
            }
        }
    }

    /**
     * Export report to file
     *
     * @param MiningReport $report
     * @param string $format
     * @return string
     */
    private function exportReport(MiningReport $report, string $format): string
    {
        $filename = "mining_report_{$report->report_type}_{$report->start_date->format('Ymd')}_{$report->id}.{$format}";
        $path = storage_path("app/reports/{$filename}");

        // Create directory if it doesn't exist
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // Export based on format
        switch ($format) {
            case 'csv':
                $this->reportService->exportToCsv($report, $path);
                break;
            case 'pdf':
                $this->reportService->exportToPdf($report, $path);
                break;
        }

        return $path;
    }
}
