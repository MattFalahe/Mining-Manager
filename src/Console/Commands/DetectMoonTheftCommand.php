<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\TheftIncident;
use MiningManager\Services\Theft\TheftDetectionService;
use MiningManager\Services\Notification\TheftNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Command to detect moon ore theft incidents
 *
 * Moon ore mined at the moon owner's refineries by characters outside every
 * configured corporation, while a bill on their account is more than the
 * allowed days overdue, or at all while non-members may not mine.
 */
class DetectMoonTheftCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:detect-theft
                            {--days=15 : How far back a new incident may reach, in days}
                            {--notify : Send notifications for new incidents}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect moon ore theft: non-members mining our moons with tax long overdue, or while they may not mine here';

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
        $lock = Cache::lock('mining-manager:detect-theft', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
            // Check feature flag
            $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
            $features = $settingsService->getFeatureFlags();
            if (!($features['enable_moon_tracking'] ?? true)) {
                $this->info('Feature disabled in settings. Skipping.');
                return Command::SUCCESS;
            }

            $this->info('Starting Moon Theft Detection...');
            $this->line('');

            try {
                $rules = $this->detectionService->rules();
                $this->displayRules($rules);

                $results = $this->detectionService->detectThefts((int) $this->option('days'), $rules);

                if (isset($results['error'])) {
                    $this->error('Error: ' . $results['error']);
                    Log::error('DetectMoonTheftCommand: ' . $results['error']);
                    return Command::FAILURE;
                }

                $this->displayTheftListReport($results);

                foreach ($results['incidents'] as $incident) {
                    $this->announce($incident);
                }

                if ($this->option('notify') && $results['incidents']->isNotEmpty()) {
                    $this->line('');
                    $this->info('Sending notifications...');
                    $this->notify($results['incidents']);
                }

                $this->line('');
                $this->displayStatistics($this->detectionService->getStatistics());

                $this->line('');
                $this->info('Theft detection completed successfully!');

                return Command::SUCCESS;

            } catch (\Exception $e) {
                $this->error('An error occurred during theft detection:');
                $this->error($e->getMessage());
                Log::error('DetectMoonTheftCommand: Exception occurred', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return Command::FAILURE;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Say what the run checks against, so a surprising result can be traced
     * to a setting.
     *
     * @param array $rules
     */
    protected function displayRules(array $rules)
    {
        $this->line("Days overdue before theft: {$rules['overdue_days']}");
        $this->line('Non-members: ' . ($rules['guests_allowed']
            ? 'may mine, and are flagged once a bill is overdue'
            : 'kept out since ' . $rules['guests_off_since']->toDateString() . ', flagged as soon as their mining arrives'));
        $this->line('');
    }

    /**
     * Display theft list management report
     *
     * @param array $results
     */
    protected function displayTheftListReport(array $results)
    {
        $this->line('<fg=cyan>📊 Theft List Management</>');
        $this->line('<fg=cyan>========================</>');

        $this->line("✓ Open incidents with new mining counted: <fg=yellow>{$results['counted']}</>");

        $this->line("✓ Removed from theft list: <fg=green>{$results['removed']->count()}</> (nothing overdue)");
        foreach ($results['removed'] as $incident) {
            $this->line("   • {$incident->getCharacterName()}");
        }

        $this->line("✓ Added to theft list: <fg=yellow>{$results['new_incidents']}</>");
        foreach ($results['incidents'] as $incident) {
            $this->line(sprintf(
                '   • %s, %s ISK mined, %s',
                $incident->getCharacterName(),
                number_format((float) $incident->ore_value, 0),
                $incident->severity
            ));
        }

        $currentTheftListCount = TheftIncident::onTheftList()->count();
        $this->line("📋 Current theft list: <fg=cyan>{$currentTheftListCount}</> incidents");
    }

    /**
     * Tell other plugins through Manager Core. Topics adds the publisher and
     * the idempotency key (theft_detected:{incident_id}).
     *
     * @param TheftIncident $incident
     */
    protected function announce(TheftIncident $incident)
    {
        if (!class_exists(\ManagerCore\Topics::class)) {
            return;
        }

        try {
            \ManagerCore\Topics::publish('mining.theft_detected', [
                'incident_id'    => $incident->id,
                'character_id'   => $incident->character_id,
                'severity'       => $incident->severity,
                'tax_owed'       => (float) $incident->tax_owed,
                'ore_value'      => (float) $incident->ore_value,
                'quantity_mined' => $incident->quantity_mined,
                'period_start'   => $incident->mining_date_from?->toDateString(),
                'period_end'     => $incident->mining_date_to?->toDateString(),
                'reason'         => $incident->mining_tax_id ? 'overdue_tax' : 'guest_mining_off',
                'corporation_id' => null, // visibility scoping
                'role_id'        => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('DetectMoonTheftCommand: Failed to publish theft incident', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * One notification per new incident, the critical alert for critical ones.
     *
     * @param \Illuminate\Support\Collection $incidents
     */
    protected function notify($incidents)
    {
        $sent = 0;

        foreach ($incidents as $incident) {
            try {
                if ($incident->severity === 'critical') {
                    $this->notificationService->notifyCriticalTheft($incident);
                } else {
                    $this->notificationService->notifyTheftDetected($incident);
                }
                $sent++;
            } catch (\Exception $e) {
                Log::error('DetectMoonTheftCommand: Failed to send notification', [
                    'incident_id' => $incident->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->comment("Sent {$sent} new theft detection notifications");
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
