<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MiningReport;
use MiningManager\Models\ReportSchedule;
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
                            {--format=json : Output format (json|csv|pdf)}
                            {--scheduled : Process all due scheduled reports}
                            {--force : Regenerate report even if one already exists for this period}';

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
        // Mutex lock to prevent concurrent invocations (schedule entries
        // can overlap; manual artisan runs can coincide with cron). Same
        // pattern as ProcessMiningLedgerCommand, DetectJackpotsCommand,
        // CalculateMonthlyTaxesCommand, etc.
        $lock = Cache::lock('mining-manager:generate-reports', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return self::SUCCESS;
        }

        try {
            return $this->handleWithLock();
        } finally {
            $lock->release();
        }
    }

    private function handleWithLock(): int
    {
        // Check feature flag
        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $features = $settingsService->getFeatureFlags();
        if (!($features['enable_reports'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        // Handle scheduled reports mode
        if ($this->option('scheduled')) {
            return $this->handleScheduledReports();
        }

        $this->info('Starting report generation...');

        $type = $this->option('type');
        $format = $this->option('format');

        // Determine date range
        [$startDate, $endDate] = $this->getDateRange($type);

        $this->info("Generating {$type} report");
        $this->info("Period: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");
        $this->info("Format: {$format}");

        // Dedup guard: if a report for the exact same period+type already exists,
        // skip regeneration unless --force is passed. Prevents the "daily duplicate"
        // problem where a misconfigured cron silently re-dispatches the same report.
        if (!$this->option('force')) {
            $existing = MiningReport::where('report_type', $type)
                ->whereDate('start_date', $startDate->toDateString())
                ->whereDate('end_date', $endDate->toDateString())
                ->orderBy('generated_at', 'desc')
                ->first();

            if ($existing) {
                $this->warn("Report already exists for this period (id={$existing->id}, generated {$existing->generated_at}). Skipping. Use --force to regenerate.");
                return Command::SUCCESS;
            }
        }

        try {
            // Generate report (this returns a MiningReport model)
            $report = $this->reportService->generateReport($startDate, $endDate, $type, $format);

            $this->info("Report generated successfully!");
            $this->info("Report ID: {$report->id}");

            // Decode report data for display
            $reportData = json_decode($report->data, true);

            // Display summary
            $this->displayReportSummary($reportData);

            // Show file path if exported
            if ($report->file_path) {
                $this->info("Report exported to: {$report->file_path}");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error generating report: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Process all due scheduled reports.
     *
     * @return int
     */
    private function handleScheduledReports(): int
    {
        $this->info('Checking for due scheduled reports...');

        $dueSchedules = ReportSchedule::where('is_active', true)
            ->where('next_run', '<=', Carbon::now())
            ->get();

        if ($dueSchedules->isEmpty()) {
            $this->info('No scheduled reports are due.');
            return Command::SUCCESS;
        }

        $this->info("Found {$dueSchedules->count()} due schedule(s).");

        $successCount = 0;
        $failCount = 0;

        foreach ($dueSchedules as $schedule) {
            $this->info("Processing schedule: {$schedule->name} (ID: {$schedule->id})");

            try {
                // Get date range from the schedule's report_type
                [$startDate, $endDate] = $schedule->getDateRangeForRun();

                $this->info("  Period: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");
                $this->info("  Type: {$schedule->report_type}, Format: {$schedule->format}");

                // Generate report
                $report = $this->reportService->generateReport(
                    $startDate,
                    $endDate,
                    $schedule->report_type,
                    $schedule->format
                );

                // Link report to schedule
                $report->update(['schedule_id' => $schedule->id]);

                // Update schedule tracking fields
                $schedule->last_run = Carbon::now();
                $schedule->reports_generated = $schedule->reports_generated + 1;
                $schedule->calculateNextRun();
                $schedule->save();

                $this->info("  Report generated successfully (ID: {$report->id})");
                $this->info("  Next run: {$schedule->next_run->format('Y-m-d H:i')}");

                $successCount++;

            } catch (\Exception $e) {
                $this->error("  Error processing schedule '{$schedule->name}': {$e->getMessage()}");
                $failCount++;
            }
        }

        $this->info("Scheduled reports complete: {$successCount} succeeded, {$failCount} failed.");

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
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

        // Handle nested summary structure
        $summary = $reportData['summary'] ?? $reportData;

        $this->line("Total Miners: " . ($summary['total_miners'] ?? 0));
        $this->line("Total Ore Mined: " . number_format($summary['total_quantity'] ?? 0));
        $this->line("Total Value: " . number_format($summary['total_value'] ?? 0, 2) . " ISK");
        $this->line("Total Tax Collected: " . number_format($summary['total_tax'] ?? 0, 2) . " ISK");

        // Top miners from miners array
        if (!empty($reportData['miners'])) {
            $this->info("\nTop 5 Miners:");
            foreach (array_slice($reportData['miners'], 0, 5) as $miner) {
                $name = $miner['name'] ?? $miner['character_name'] ?? 'Unknown';
                $quantity = $miner['quantity'] ?? $miner['total_quantity'] ?? 0;
                $this->line("  - {$name}: " . number_format($quantity) . " ore");
            }
        }
    }
}
