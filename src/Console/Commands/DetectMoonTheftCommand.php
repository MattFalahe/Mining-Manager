<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Theft\TheftDetectionService;
use MiningManager\Services\Notification\TheftNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Command to detect moon ore theft incidents
 *
 * Identifies characters who:
 * - Mined moon ore
 * - Have unpaid/overdue taxes
 * - Grace period has passed
 * - Are not corp members or not registered
 */
class DetectMoonTheftCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:detect-theft
                            {--days=15 : Number of days to look back for mining activity}
                            {--notify : Send notifications for detected thefts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect moon ore theft incidents based on unpaid taxes and mining activity';

    /**
     * @var TheftDetectionService
     */
    protected $detectionService;

    /**
     * @var TheftNotificationService
     */
    protected $notificationService;

    /**
     * Create a new command instance.
     *
     * @param TheftDetectionService $detectionService
     * @param TheftNotificationService $notificationService
     */
    public function __construct(
        TheftDetectionService $detectionService,
        TheftNotificationService $notificationService
    ) {
        parent::__construct();
        $this->detectionService = $detectionService;
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting Moon Theft Detection...');
        $this->line('');

        // Get options
        $days = (int) $this->option('days');
        $notify = $this->option('notify');

        // Calculate date range
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays($days);

        $this->info("Analyzing mining activity from {$startDate->toDateString()} to {$endDate->toDateString()}");
        $this->line('');

        try {
            // Run regular theft detection first
            $results = $this->detectionService->detectThefts($startDate, $endDate);

            // Check for errors
            if (isset($results['error'])) {
                $this->error('Error: ' . $results['error']);
                Log::error('DetectMoonTheftCommand: ' . $results['error']);
                return 1;
            }

            // Check if any characters on theft list have now paid their taxes
            $this->line('');
            $this->info('Checking for paid taxes...');
            $paidCharacters = $this->detectionService->checkForPaidTaxes();

            // Display theft list management report
            $this->line('');
            $this->displayTheftListReport($results, $paidCharacters);

            // Run active theft detection
            $this->line('');
            $this->info('Checking for active thefts in progress...');
            $activeTheftResults = $this->detectionService->detectActiveThefts();

            // Display enhanced report
            $this->line('');
            $this->displayEnhancedReport($results, $activeTheftResults);

            // Get statistics
            $stats = $this->detectionService->getStatistics();
            $this->line('');
            $this->displayStatistics($stats);

            // Notification handling
            if ($notify) {
                $this->line('');
                $this->info('Sending notifications...');

                // Send notifications for active thefts
                if ($activeTheftResults['count'] > 0) {
                    foreach ($activeTheftResults['active_thefts'] as $activeTheft) {
                        $this->notificationService->notifyActiveTheft(
                            $activeTheft['incident'],
                            $activeTheft['new_value']
                        );
                    }
                    $this->comment("Sent {$activeTheftResults['count']} active theft notifications");
                }

                $this->comment('Note: Full notification system with webhooks will be configured in settings.');
            }

            // Log success
            Log::info('DetectMoonTheftCommand: Completed successfully', array_merge($results, [
                'active_thefts' => $activeTheftResults['count']
            ]));

            $this->line('');
            $this->info('Theft detection completed successfully!');

            return 0;

        } catch (\Exception $e) {
            $this->error('An error occurred during theft detection:');
            $this->error($e->getMessage());
            Log::error('DetectMoonTheftCommand: Exception occurred', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Display theft list management report
     *
     * @param array $results
     * @param \Illuminate\Support\Collection $paidCharacters
     */
    protected function displayTheftListReport(array $results, $paidCharacters)
    {
        $this->line('');
        $this->line('<fg=cyan>📊 Theft List Management</>');
        $this->line('<fg=cyan>========================</>');

        if ($results['new_incidents'] > 0) {
            $this->line("✓ Added to theft list: <fg=yellow>{$results['new_incidents']}</> new characters");
        }

        if ($paidCharacters->count() > 0) {
            $this->line("✓ Removed from theft list: <fg=green>{$paidCharacters->count()}</> (taxes paid)");
            foreach ($paidCharacters as $incident) {
                $this->line("   • {$incident->character_name}");
            }
        } else {
            $this->line("✓ Removed from theft list: <fg=green>0</> (taxes paid)");
        }

        $currentTheftListCount = \MiningManager\Models\TheftIncident::onTheftList()->count();
        $this->line("📋 Current theft list: <fg=cyan>{$currentTheftListCount}</> characters");
        $this->line('');
        $this->line("💡 Active monitoring will check these {$currentTheftListCount} characters every 6 hours");
    }

    /**
     * Display enhanced detection report
     *
     * @param array $results
     * @param array $activeTheftResults
     */
    protected function displayEnhancedReport(array $results, array $activeTheftResults)
    {
        $this->line('');
        $this->line('<fg=cyan>🔍 Moon Theft Detection Report</>');
        $this->line('<fg=cyan>================================</>');
        $this->line("✓ Scanned last {$this->option('days')} days of mining activity");
        $this->line('');

        $this->info('📊 Results:');
        $this->line("  • New theft incidents: <fg=yellow>{$results['new_incidents']}</>");

        if ($results['new_incidents'] > 0) {
            $stats = $this->detectionService->getStatistics();
            $newCritical = $stats['incidents_by_severity']['critical'] ?? 0;
            $newHigh = $stats['incidents_by_severity']['high'] ?? 0;

            if ($newCritical > 0) {
                $this->line("    - <fg=red>Critical: {$newCritical}</>");
            }
            if ($newHigh > 0) {
                $this->line("    - <fg=yellow>High: {$newHigh}</>");
            }
        }

        $this->line('');

        // Display active thefts prominently
        if ($activeTheftResults['count'] > 0) {
            $this->line('<fg=red>⚠️  ACTIVE THEFTS IN PROGRESS: ' . $activeTheftResults['count'] . '</>');

            foreach ($activeTheftResults['active_thefts'] as $activeTheft) {
                $incident = $activeTheft['incident'];
                $newValue = $activeTheft['new_value'];
                $totalValue = $incident->ore_value;

                $occurrence = $this->getOccurrenceSuffix($incident->activity_count);

                $this->line("  - <fg=red>{$incident->character_name}</> continues mining ({$occurrence}) - " .
                           number_format($totalValue / 1000000, 0) . 'M ISK total');
            }
            $this->line('');
        } else {
            $this->line('<fg=green>✓ No active thefts detected - all incidents are being monitored</>');
            $this->line('');
        }

        // Display total value at risk
        $stats = $this->detectionService->getStatistics();
        $totalValue = $stats['total_value_at_risk'];
        $this->line("💰 Total value at risk: <fg=yellow>" . number_format($totalValue / 1000000000, 2) . "B ISK</>");
    }

    /**
     * Get occurrence suffix for activity count
     *
     * @param int $count
     * @return string
     */
    protected function getOccurrenceSuffix(int $count): string
    {
        if ($count == 1) {
            return '1st occurrence';
        } elseif ($count == 2) {
            return '2nd occurrence';
        } elseif ($count == 3) {
            return '3rd occurrence';
        } else {
            return "{$count}th occurrence";
        }
    }

    /**
     * Display detection results (legacy method for backward compatibility)
     *
     * @param array $results
     */
    protected function displayResults(array $results)
    {
        $this->info('Detection Results:');
        $this->line('==================');

        $headers = ['Metric', 'Count'];
        $rows = [
            ['Total Incidents Detected', $results['incidents_detected']],
            ['New Incidents', $results['new_incidents']],
            ['Updated Incidents', $results['updated_incidents']],
        ];

        $this->table($headers, $rows);
    }

    /**
     * Display theft statistics
     *
     * @param array $stats
     */
    protected function displayStatistics(array $stats)
    {
        $this->info('Overall Statistics:');
        $this->line('===================');

        // Summary stats
        $this->line('Total Incidents: ' . $stats['total_incidents']);
        $this->line('Unresolved Incidents: ' . $stats['unresolved_incidents']);
        $this->line('Critical Incidents: ' . $stats['critical_incidents']);
        $this->line('Total Value at Risk: ' . number_format($stats['total_value_at_risk'], 2) . ' ISK');

        $this->line('');

        // By severity
        $this->info('Incidents by Severity:');
        $severityHeaders = ['Severity', 'Count'];
        $severityRows = [];

        foreach ($stats['incidents_by_severity'] as $severity => $count) {
            $severityRows[] = [ucfirst($severity), $count];
        }

        $this->table($severityHeaders, $severityRows);

        $this->line('');

        // By status
        $this->info('Incidents by Status:');
        $statusHeaders = ['Status', 'Count'];
        $statusRows = [];

        foreach ($stats['incidents_by_status'] as $status => $count) {
            $statusRows[] = [ucfirst(str_replace('_', ' ', $status)), $count];
        }

        $this->table($statusHeaders, $statusRows);
    }
}
