<?php

namespace MiningManager\Services\Events;

use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventParticipant;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EventManagementService
{
    /**
     * Event tracking service
     *
     * @var EventTrackingService
     */
    protected $trackingService;

    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Constructor
     *
     * @param EventTrackingService $trackingService
     * @param SettingsManagerService $settingsService
     */
    public function __construct(EventTrackingService $trackingService, SettingsManagerService $settingsService)
    {
        $this->trackingService = $trackingService;
        $this->settingsService = $settingsService;
    }

    /**
     * Automatically transition event statuses and dispatch webhook notifications.
     *
     * Called by the `mining-manager:update-events` cron every 2 hours.
     *
     * Transitions:
     *   planned → active   when start_time <= now  (fires 'event_started' notification)
     *   active  → completed when end_time  <= now  (fires 'event_completed' notification)
     *
     * Cancelled events are never touched.
     *
     * @return array ['started' => int, 'completed' => int]
     */
    public function updateEventStatuses(): array
    {
        $now = Carbon::now();
        $started = 0;
        $completed = 0;

        // 1. planned → active (start_time has passed)
        //
        // ATOMIC CLAIM via compare-and-swap. Pre-fix: the cron read
        // status='planned' rows then `$event->update(['status' => 'active'])`
        // unconditionally. Manual start path (EventController::start) had
        // the same shape — a director clicking "Start" the same minute the
        // cron fires both passed their respective reads + writes, both
        // dispatched event_started. Now: UPDATE WHERE status='planned'
        // returns the count of rows updated. Only the path that flips
        // planned→active gets back claimed=1 and proceeds to dispatch.
        $shouldStart = MiningEvent::where('status', 'planned')
            ->where('start_time', '<=', $now)
            ->get();

        foreach ($shouldStart as $event) {
            try {
                $claimed = MiningEvent::where('id', $event->id)
                    ->where('status', 'planned')
                    ->update(['status' => 'active']);

                if ($claimed === 0) {
                    // Lost the race to a parallel manual start. Already-active
                    // event would have fired its own notification on whichever
                    // path won; skipping ours avoids the duplicate ping.
                    Log::debug("Mining Manager: Event {$event->id} no longer planned at claim time; skipping cron start (already started by another path)");
                    continue;
                }

                Log::info("Mining Manager: Event '{$event->name}' (id={$event->id}) auto-started");

                // Dispatch 'event_started' notification
                try {
                    $participantIds = $event->participants()->pluck('character_id')->toArray();
                    app(NotificationService::class)->sendEventStarted($event->fresh(), $participantIds);
                } catch (\Exception $e) {
                    Log::warning("Mining Manager: Failed to send event_started notification for event {$event->id}: " . $e->getMessage());
                }

                $started++;
            } catch (\Exception $e) {
                Log::error("Mining Manager: Failed to auto-start event {$event->id}: " . $e->getMessage());
            }
        }

        // 2. active → completed (end_time has passed)
        //
        // Same CAS pattern — UPDATE WHERE status='active' guarantees only one
        // path (cron auto-complete OR manual EventController::complete) flips
        // active→completed and fires the notification.
        $shouldComplete = MiningEvent::where('status', 'active')
            ->whereNotNull('end_time')
            ->where('end_time', '<=', $now)
            ->get();

        foreach ($shouldComplete as $event) {
            try {
                // Final participant tracking update before closing
                try {
                    $this->trackingService->updateEventTracking($event);
                } catch (\Exception $e) {
                    Log::warning("Mining Manager: Final tracking update failed for event {$event->id}: " . $e->getMessage());
                }

                $claimed = MiningEvent::where('id', $event->id)
                    ->where('status', 'active')
                    ->update(['status' => 'completed']);

                if ($claimed === 0) {
                    Log::debug("Mining Manager: Event {$event->id} no longer active at claim time; skipping cron complete (already completed by another path)");
                    continue;
                }

                Log::info("Mining Manager: Event '{$event->name}' (id={$event->id}) auto-completed");

                // Dispatch 'event_completed' notification
                try {
                    $participantIds = $event->participants()->pluck('character_id')->toArray();
                    app(NotificationService::class)->sendEventCompleted($event->fresh(), $participantIds);
                } catch (\Exception $e) {
                    Log::warning("Mining Manager: Failed to send event_completed notification for event {$event->id}: " . $e->getMessage());
                }

                $completed++;
            } catch (\Exception $e) {
                Log::error("Mining Manager: Failed to auto-complete event {$event->id}: " . $e->getMessage());
            }
        }

        return ['started' => $started, 'completed' => $completed];
    }

    /**
     * Create a new mining event.
     *
     * @param array $data
     * @return MiningEvent
     */
    public function createEvent(array $data): MiningEvent
    {
        Log::info("Mining Manager: Creating new mining event: {$data['name']}");

        $event = MiningEvent::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'] ?? null,
            'solar_system_id' => $data['solar_system_id'] ?? null,
            'tax_modifier' => $data['tax_modifier'] ?? 0,
            'corporation_id' => $data['corporation_id'] ?? null,
            'status' => $data['status'] ?? 'planned',
            'created_by' => $data['created_by'] ?? auth()->id(),
        ]);

        Log::info("Mining Manager: Created mining event {$event->id}: {$event->name} (Tax Modifier: {$event->tax_modifier}%)");

        return $event;
    }

    /**
     * Update an existing event.
     *
     * @param int $eventId
     * @param array $data
     * @return MiningEvent
     */
    public function updateEvent(int $eventId, array $data): MiningEvent
    {
        $event = MiningEvent::findOrFail($eventId);

        Log::info("Mining Manager: Updating event {$eventId}: {$event->name}");

        $event->update($data);

        return $event->fresh();
    }

    /**
     * Delete an event.
     *
     * @param int $eventId
     * @return bool
     */
    public function deleteEvent(int $eventId): bool
    {
        try {
            $event = MiningEvent::findOrFail($eventId);

            Log::info("Mining Manager: Deleting event {$eventId}: {$event->name}");

            $event->delete();

            return true;

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error deleting event {$eventId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Start a mining event.
     *
     * @param int $eventId
     * @return MiningEvent
     */
    public function startEvent(int $eventId): MiningEvent
    {
        $event = MiningEvent::findOrFail($eventId);

        Log::info("Mining Manager: Starting event {$eventId}: {$event->name}");

        $event->update([
            'status' => 'active',
            'start_time' => Carbon::now(),
        ]);

        $event = $event->fresh();

        // Dispatch 'event_started' webhook notification
        try {
            $participantIds = $event->participants()->pluck('character_id')->toArray();
            app(NotificationService::class)->sendEventStarted($event, $participantIds);
        } catch (\Exception $e) {
            Log::warning("Mining Manager: Failed to send event_started notification for event {$eventId}: " . $e->getMessage());
        }

        return $event;
    }

    /**
     * Complete a mining event.
     *
     * @param int $eventId
     * @return MiningEvent
     */
    public function completeEvent(int $eventId): MiningEvent
    {
        $event = MiningEvent::findOrFail($eventId);

        Log::info("Mining Manager: Completing event {$eventId}: {$event->name}");

        DB::transaction(function () use ($event) {
            // Update event data one final time
            $this->updateEventData($event);

            $event->update([
                'status' => 'completed',
                'end_time' => Carbon::now(),
            ]);
        });

        $event = $event->fresh();

        Log::info("Mining Manager: Event {$eventId} completed. Total mined: " . number_format($event->total_mined) . ", Participants: {$event->participant_count}, Tax Modifier: {$event->tax_modifier}%");

        // Dispatch 'event_completed' webhook notification
        try {
            $participantIds = $event->participants()->pluck('character_id')->toArray();
            app(NotificationService::class)->sendEventCompleted($event, $participantIds);
        } catch (\Exception $e) {
            Log::warning("Mining Manager: Failed to send event_completed notification for event {$eventId}: " . $e->getMessage());
        }

        return $event;
    }

    /**
     * Cancel a mining event.
     *
     * @param int $eventId
     * @param string $reason
     * @return MiningEvent
     */
    public function cancelEvent(int $eventId, string $reason = ''): MiningEvent
    {
        $event = MiningEvent::findOrFail($eventId);

        Log::info("Mining Manager: Cancelling event {$eventId}: {$event->name}. Reason: {$reason}");

        $event->update([
            'status' => 'cancelled',
            'description' => $event->description . "\n\nCancelled: " . Carbon::now()->toDateTimeString() . " - {$reason}",
        ]);

        return $event->fresh();
    }

    /**
     * Update event data (participants and statistics).
     *
     * @param MiningEvent $event
     * @return array
     */
    public function updateEventData(MiningEvent $event): array
    {
        Log::debug("Mining Manager: Updating data for event {$event->id}");

        // Get mining activity during event period
        $query = MiningLedger::whereBetween('date', [
            $event->start_time,
            $event->end_time ?? Carbon::now()
        ]);

        // Filter by location if specified
        if ($event->solar_system_id) {
            $query->where('solar_system_id', $event->solar_system_id);
        }

        $miningActivity = $query->get();

        if ($miningActivity->isEmpty()) {
            Log::debug("Mining Manager: No mining activity found for event {$event->id}");
            return [
                'participants' => 0,
                'total_mined' => 0,
            ];
        }

        // Update or create participant records
        $participantCount = 0;
        $totalMined = 0;

        DB::transaction(function () use ($event, $miningActivity, &$participantCount, &$totalMined) {
            foreach ($miningActivity->groupBy('character_id') as $characterId => $records) {
                $quantity = $records->sum('quantity');
                $totalMined += $quantity;

                EventParticipant::updateOrCreate(
                    [
                        'event_id' => $event->id,
                        'character_id' => $characterId,
                    ],
                    [
                        'quantity_mined' => $quantity,
                        'last_updated' => Carbon::now(),
                        'joined_at' => $records->min('date'),
                    ]
                );

                $participantCount++;
            }

            // Update event statistics
            $event->update([
                'participant_count' => $participantCount,
                'total_mined' => $totalMined,
                'last_updated' => Carbon::now(),
            ]);
        });

        Log::info("Mining Manager: Updated event {$event->id} data: {$participantCount} participants, " . number_format($totalMined) . " ore mined");

        return [
            'participants' => $participantCount,
            'total_mined' => $totalMined,
        ];
    }

    /**
     * Add participant to event.
     *
     * @param int $eventId
     * @param int $characterId
     * @return EventParticipant
     */
    public function addParticipant(int $eventId, int $characterId): EventParticipant
    {
        $event = MiningEvent::findOrFail($eventId);

        Log::info("Mining Manager: Adding character {$characterId} to event {$eventId}");

        $participant = EventParticipant::firstOrCreate(
            [
                'event_id' => $eventId,
                'character_id' => $characterId,
            ],
            [
                'quantity_mined' => 0,
                'joined_at' => Carbon::now(),
            ]
        );

        return $participant;
    }

    /**
     * Remove participant from event.
     *
     * @param int $eventId
     * @param int $characterId
     * @return bool
     */
    public function removeParticipant(int $eventId, int $characterId): bool
    {
        try {
            Log::info("Mining Manager: Removing character {$characterId} from event {$eventId}");

            $deleted = EventParticipant::where('event_id', $eventId)
                ->where('character_id', $characterId)
                ->delete();

            return $deleted > 0;

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error removing participant: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update participant data.
     *
     * @param int $participantId
     * @param array $data
     * @return EventParticipant
     */
    public function updateParticipant(int $participantId, array $data): EventParticipant
    {
        $participant = EventParticipant::findOrFail($participantId);

        Log::debug("Mining Manager: Updating participant {$participantId}");

        $participant->update($data);

        return $participant->fresh();
    }

    /**
     * Get event leaderboard.
     *
     * @param int $eventId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getEventLeaderboard(int $eventId, int $limit = 10)
    {
        return EventParticipant::where('event_id', $eventId)
            ->with('character')
            ->orderByDesc('quantity_mined')
            ->limit($limit)
            ->get();
    }

    /**
     * Get event statistics.
     *
     * @param int $eventId
     * @return array
     */
    public function getEventStatistics(int $eventId): array
    {
        $event = MiningEvent::with('participants')->findOrFail($eventId);

        $participants = $event->participants;

        return [
            'total_participants' => $participants->count(),
            'total_mined' => $participants->sum('quantity_mined'),
            'tax_modifier' => $event->tax_modifier,
            'tax_modifier_label' => $event->getTaxModifierLabel(),
            'average_per_participant' => $participants->count() > 0
                ? $participants->sum('quantity_mined') / $participants->count()
                : 0,
            'top_miner' => $participants->sortByDesc('quantity_mined')->first(),
            'duration_hours' => $event->end_time
                ? $event->start_time->diffInHours($event->end_time)
                : null,
        ];
    }

    /**
     * Auto-close inactive events.
     *
     * @return int Number of events closed
     */
    public function autoCloseInactiveEvents(): int
    {
        $autoCloseHours = config('mining-manager.events.auto_close_after_hours', 24);
        $cutoffTime = Carbon::now()->subHours($autoCloseHours);

        $inactiveEvents = MiningEvent::where('status', 'active')
            ->where('last_updated', '<', $cutoffTime)
            ->get();

        $closed = 0;

        foreach ($inactiveEvents as $event) {
            try {
                $this->completeEvent($event->id);
                $closed++;
                Log::info("Mining Manager: Auto-closed inactive event {$event->id}: {$event->name}");
            } catch (\Exception $e) {
                Log::error("Mining Manager: Error auto-closing event {$event->id}: " . $e->getMessage());
            }
        }

        if ($closed > 0) {
            Log::info("Mining Manager: Auto-closed {$closed} inactive events");
        }

        return $closed;
    }

    /**
     * Clone an event.
     *
     * @param int $eventId
     * @param array $overrides
     * @return MiningEvent
     */
    public function cloneEvent(int $eventId, array $overrides = []): MiningEvent
    {
        $originalEvent = MiningEvent::findOrFail($eventId);

        Log::info("Mining Manager: Cloning event {$eventId}: {$originalEvent->name}");

        $newEvent = $originalEvent->replicate();
        $newEvent->status = 'planned';
        $newEvent->participant_count = 0;
        $newEvent->total_mined = 0;
        $newEvent->created_by = auth()->id() ?? $originalEvent->created_by;
        $newEvent->name = ($overrides['name'] ?? $originalEvent->name) . ' (Copy)';

        // Apply overrides
        foreach ($overrides as $key => $value) {
            $newEvent->$key = $value;
        }

        $newEvent->save();

        Log::info("Mining Manager: Cloned event to new event {$newEvent->id}");

        return $newEvent;
    }

    /**
     * Get upcoming events.
     *
     * @param int $days
     * @param int|null $corporationId
     * @return \Illuminate\Support\Collection
     */
    public function getUpcomingEvents(int $days = 7, ?int $corporationId = null)
    {
        $query = MiningEvent::whereIn('status', ['planned', 'active'])
            ->where('start_time', '>=', Carbon::now())
            ->where('start_time', '<=', Carbon::now()->addDays($days))
            ->orderBy('start_time');

        if ($corporationId) {
            $query->forCorporation($corporationId);
        }

        return $query->get();
    }

    /**
     * Get active events.
     *
     * @param int|null $corporationId
     * @return \Illuminate\Support\Collection
     */
    public function getActiveEvents(?int $corporationId = null)
    {
        $query = MiningEvent::where('status', 'active')
            ->with(['participants.character'])
            ->orderBy('start_time', 'desc');

        if ($corporationId) {
            $query->forCorporation($corporationId);
        }

        return $query->get();
    }

    /**
     * Validate event constraints.
     *
     * @param array $data
     * @return array Validation errors
     */
    public function validateEventConstraints(array $data): array
    {
        $errors = [];

        // Check minimum participants if starting
        if (isset($data['status']) && $data['status'] === 'active') {
            $minimumParticipants = config('mining-manager.events.minimum_participants', 3);

            if (isset($data['id'])) {
                $event = MiningEvent::find($data['id']);
                if ($event && $event->participant_count < $minimumParticipants) {
                    $errors[] = "Event requires at least {$minimumParticipants} participants";
                }
            }
        }

        // Check date logic
        if (isset($data['start_time']) && isset($data['end_time'])) {
            $startTime = Carbon::parse($data['start_time']);
            $endTime = Carbon::parse($data['end_time']);

            if ($endTime->lessThanOrEqualTo($startTime)) {
                $errors[] = "End time must be after start time";
            }
        }

        // Check tax modifier range
        if (isset($data['tax_modifier'])) {
            if ($data['tax_modifier'] < -100 || $data['tax_modifier'] > 100) {
                $errors[] = "Tax modifier must be between -100 and +100";
            }
        }

        return $errors;
    }

    /**
     * Export event data.
     *
     * @param int $eventId
     * @return array
     */
    public function exportEventData(int $eventId): array
    {
        $event = MiningEvent::with(['participants.character', 'solarSystem', 'corporation'])->findOrFail($eventId);

        return [
            'event' => [
                'name' => $event->name,
                'description' => $event->description,
                'start_time' => $event->start_time->toIso8601String(),
                'end_time' => $event->end_time?->toIso8601String(),
                'system' => $event->solarSystem?->name,
                'corporation' => $event->corporation?->name,
                'status' => $event->status,
                'tax_modifier' => $event->tax_modifier,
                'tax_modifier_label' => $event->getTaxModifierLabel(),
            ],
            'statistics' => $this->getEventStatistics($eventId),
            'participants' => $event->participants->map(function ($participant) {
                return [
                    'character' => $participant->character->name ?? 'Unknown',
                    'quantity_mined' => $participant->quantity_mined,
                    'joined_at' => $participant->joined_at?->toIso8601String(),
                ];
            })->toArray(),
        ];
    }
}
