<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MiningEvent;
use MiningManager\Services\Events\EventManagementService;
use MiningManager\Services\Events\EventMiningAggregator;
use MiningManager\Services\Events\EventTrackingService;
use MiningManager\Services\Ledger\PersonalMiningReconciler;
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
        $lock = Cache::lock('mining-manager:update-events', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
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
                // Every event that can still change: active ones, whenever
                // they were made, those that ended in the last few days, and
                // anything made in the last 30 days.
                $query->where(function ($q) {
                    $q->where('status', 'active')
                        ->orWhere(fn ($ended) => $ended->where('status', 'completed')
                            ->where('end_time', '>=', Carbon::now()->subDays(PersonalMiningReconciler::DEFAULT_DAYS)))
                        ->orWhere('created_at', '>=', Carbon::now()->subDays(30));
                });
                $this->info("Updating active, recently ended and recently made events");
            }

            $events = $query->get();

            if ($events->isEmpty()) {
                $this->warn('No events found to update');
                return Command::SUCCESS;
            }

            $this->info("Found {$events->count()} events to update");

            $updated = 0;
            $errors = 0;

            $aggregator = app(EventMiningAggregator::class);

            foreach ($events as $event) {
                try {
                    $this->line("Processing event: {$event->name}");

                    // Gather what arrived since the last tick into
                    // event_mining_records, while the event is active and for
                    // a few days after it ends: a refinery reports a day's
                    // mining only once the day is over, so the last day of an
                    // event always arrives after it has ended. An event set
                    // up after its days had passed is gathered once. Older
                    // events only change when their scope is edited (the
                    // MiningEvent saved() hook).
                    if ($event->status === 'active' || $this->stillReporting($event) || $this->neverGathered($event)) {
                        $agg = $aggregator->aggregate($event);
                        if (($agg['created'] ?? 0) > 0 || ($agg['updated'] ?? 0) > 0) {
                            $this->line("  → aggregated {$agg['created']} new record(s), refreshed {$agg['updated']}, days built again {$agg['days']}");
                        }
                    }

                    // Roll event_mining_records into event_participants and
                    // event aggregate counters.
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
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a finished event's mining can still be arriving: it ended in
     * the last few days, the window the plugin gives late refinery reports
     * everywhere else.
     */
    private function stillReporting(MiningEvent $event): bool
    {
        return $event->status === 'completed'
            && $event->end_time !== null
            && $event->end_time->gte(Carbon::now()->subDays(PersonalMiningReconciler::DEFAULT_DAYS));
    }

    /**
     * Whether a finished event has never been gathered: one set up after its
     * days had passed goes from planned to completed on its first tick
     * without ever being active. Every tick stamps last_updated on the
     * events it looks at, so this holds once.
     */
    private function neverGathered(MiningEvent $event): bool
    {
        return $event->status === 'completed' && $event->last_updated === null;
    }
}
