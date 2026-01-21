<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Theft\TheftDetectionService;
use MiningManager\Services\Notification\TheftNotificationService;
use MiningManager\Models\TheftIncident;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Command to monitor active thefts
 *
 * This is the FAST check - only monitors characters already on the theft list.
 * Runs every 6 hours to catch ongoing mining by known offenders.
 */
class MonitorActiveTheftsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:monitor-active-thefts
                            {--hours=6 : Number of hours to look back for activity}
                            {--notify : Send notifications for active thefts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor characters on theft list for continued mining (Fast check - every 6 hours)';

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
        $this->line('');
        $this->info('🔍 Active Theft Monitoring');
        $this->line('==========================');

        // Get options
        $hours = (int) $this->option('hours');
        $notify = $this->option('notify');

        // Get characters on theft list
        $theftListCount = TheftIncident::onTheftList()->count();

        if ($theftListCount === 0) {
            $this->line('');
            $this->info('✓ No characters currently on theft list');
            $this->line('');
            Log::info('MonitorActiveTheftsCommand: No characters on theft list to monitor');
            return 0;
        }

        $this->line("👁️  Monitoring {$theftListCount} characters on theft list");
        $this->line("⏱️  Checking last {$hours} hours of activity");
        $this->line('');

        try {
            // Monitor active thefts
            $activeThefts = $this->detectionService->monitorActiveThefts($hours);

            // Display results
            if ($activeThefts->count() > 0) {
                $this->line("<fg=red>⚠️  ACTIVE THEFTS DETECTED: {$activeThefts->count()}</>");
                $this->line('');

                foreach ($activeThefts as $theftData) {
                    $incident = $theftData['incident'];
                    $newValue = $theftData['new_value'];
                    $totalValue = $incident->ore_value;

                    $occurrence = $this->getOccurrenceSuffix($incident->activity_count);

                    $this->line("  🔴 <fg=red>{$incident->character_name}</> mined " .
                               number_format($newValue / 1000000, 0) . "M ISK ({$occurrence})");
                    $this->line("     Total unpaid: " . number_format($totalValue / 1000000, 0) . "M ISK");
                    $this->line('');
                }

                // Send notifications if requested
                if ($notify) {
                    $notificationsSent = 0;
                    foreach ($activeThefts as $theftData) {
                        try {
                            $this->notificationService->notifyActiveTheft(
                                $theftData['incident'],
                                $theftData['new_value']
                            );
                            $notificationsSent++;
                        } catch (\Exception $e) {
                            Log::error('MonitorActiveTheftsCommand: Failed to send notification', [
                                'incident_id' => $theftData['incident']->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    $this->info("✓ {$notificationsSent} notifications sent");
                }

                Log::info('MonitorActiveTheftsCommand: Active thefts detected', [
                    'count' => $activeThefts->count(),
                    'total_characters_monitored' => $theftListCount
                ]);

            } else {
                $this->line('<fg=green>✓ No active thefts detected in the last ' . $hours . ' hours</>');
                $this->line('');

                Log::info('MonitorActiveTheftsCommand: No active thefts detected', [
                    'characters_monitored' => $theftListCount,
                    'hours_checked' => $hours
                ]);
            }

            $this->line('');
            $this->info('Active theft monitoring completed successfully!');

            return 0;

        } catch (\Exception $e) {
            $this->error('An error occurred during active theft monitoring:');
            $this->error($e->getMessage());
            Log::error('MonitorActiveTheftsCommand: Exception occurred', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
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
}
