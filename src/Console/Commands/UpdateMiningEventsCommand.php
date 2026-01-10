<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventParticipant;
use MiningManager\Models\MiningLedger;
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
        $this->info('Starting event update...');

        // Build query for events
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

                // Get mining activity during event period
                $miningQuery = MiningLedger::whereBetween('date', [
                    $event->start_time,
                    $event->end_time ?? Carbon::now()
                ]);

                // Filter by location if specified
                if ($event->solar_system_id) {
                    $miningQuery->where('solar_system_id', $event->solar_system_id);
                }

                $miningActivity = $miningQuery->get();

                // Update or create participant records
                $participantCount = 0;
                foreach ($miningActivity->groupBy('character_id') as $characterId => $records) {
                    $totalQuantity = $records->sum('quantity');
                    
                    EventParticipant::updateOrCreate(
                        [
                            'event_id' => $event->id,
                            'character_id' => $characterId,
                        ],
                        [
                            'quantity_mined' => $totalQuantity,
                            'last_updated' => Carbon::now(),
                        ]
                    );
                    $participantCount++;
                }

                // Update event statistics
                $event->update([
                    'participant_count' => $participantCount,
                    'total_mined' => $miningActivity->sum('quantity'),
                    'last_updated' => Carbon::now(),
                ]);

                $this->info("Updated event '{$event->name}': {$participantCount} participants, " . 
                           number_format($miningActivity->sum('quantity')) . " ore mined");
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
