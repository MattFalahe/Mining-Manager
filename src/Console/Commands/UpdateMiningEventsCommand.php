<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningEvent;
use MiningManager\Services\Events\EventManagementService;
use MiningManager\Services\Events\EventTrackingService;
use Carbon\Carbon;

class UpdateMiningEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:update-events
                            {--event_id= : Update specific event}
                            {--active : Only update active events}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update mining event participant data and statistics';

    /**
     * Event tracking service
     *
     * @var EventTrackingService
     */
    protected $eventService;

    /**
     * Create a new command instance.
     *
     * @param EventTrackingService $eventService
     */
    public function __construct(EventTrackingService $eventService)
    {
        parent::__construct();
        $this->eventService = $eventService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check feature flag
        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $features = $settingsService->getFeatureFlags();
        if (!($features['enable_events'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $this->info('Starting event update...');

        // Transition event statuses: planned → active → completed.
        // This fires 'event_started' and 'event_completed' webhook notifications
        // on each transition (see EventManagementService::updateEventStatuses).
        $managementService = app(EventManagementService::class);
        $statusResult = $managementService->updateEventStatuses();

        if ($statusResult['started'] > 0) {
            $this->info("Auto-started {$statusResult['started']} event(s)");
        }
        if ($statusResult['completed'] > 0) {
            $this->info("Auto-completed {$statusResult['completed']} event(s)");
        }

        // Build query for participant tracking
        $query = MiningEvent::query();

        if ($eventId = $this->option('event_id')) {
            $query->where('id', $eventId);
            $this->info("Updating event ID: {$eventId}");
        } elseif ($this->option('active')) {
            $query->where('status', 'active')
                ->where('start_time', '<=', Carbon::now())
                ->where(function ($q) {
                    $q->whereNull('end_time')
                        ->orWhere('end_time', '>=', Carbon::now());
                });
            $this->info("Updating active events");
        } else {
            // Update events from last 30 days by default
            $query->where('created_at', '>=', Carbon::now()->subDays(30));
            $this->info("Updating events from last 30 days");
        }

        $events = $query->get();

        if ($events->isEmpty()) {
            $this->warn('No events found to update');
            return Command::SUCCESS;
        }

        $this->info("Found {$events->count()} events to update");

        $updated = 0;
        $errors = 0;

        foreach ($events as $event) {
            try {
                $this->line("Processing event: {$event->name}");

                // Delegate to EventTrackingService (handles DB::transaction, participant
                // updates, and event statistics in a single atomic operation)
                $result = $this->eventService->updateEventTracking($event);

                $participants = ($result['new_participants'] ?? 0) + ($result['updated_participants'] ?? 0);
                $this->info("Updated event '{$event->name}': {$participants} participants tracked");
                $updated++;

            } catch (\Exception $e) {
                $this->error("Error updating event '{$event->name}': {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("Event update complete!");
        $this->info("Updated: {$updated} events");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        return Command::SUCCESS;
    }
}
