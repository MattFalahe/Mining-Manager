<?php

namespace MiningManager\Services\Events;

use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventParticipant;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Configuration\SettingsManagerService;
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
            'bonus_percentage' => $data['bonus_percentage'] ?? 0,
            'status' => $data['status'] ?? 'planned',
            'created_by' => $data['created_by'] ?? auth()->id(),
        ]);

        Log::info("Mining Manager: Created mining event {$event->id}: {$event->name}");

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

        return $event->fresh();
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

        // Update event data one final time
        $this->updateEventData($event);

        // Calculate bonuses if enabled
        if ($event->bonus_percentage > 0 && config('mining-manager.events.enable_bonuses', false)) {
            $this->calculateBonuses($event);
        }

        $event->update([
            'status' => 'completed',
            'end_time' => Carbon::now(),
        ]);

        Log::info("Mining Manager: Event {$eventId} completed. Total mined: " . number_format($event->total_mined) . ", Participants: {$event->participant_count}");

        return $event->fresh();
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
                    'joined_at' => $joined_at ?? $records->min('date'),
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

        Log::info("Mining Manager: Updated event {$event->id} data: {$participantCount} participants, " . number_format($totalMined) . " ore mined");

        return [
            'participants' => $participantCount,
            'total_mined' => $totalMined,
        ];
    }

    /**
     * Calculate bonuses for event participants.
     *
     * @param MiningEvent $event
     * @return array
     */
    public function calculateBonuses(MiningEvent $event): array
    {
        if ($event->bonus_percentage <= 0) {
            return [
                'participants' => 0,
                'total_bonus' => 0,
            ];
        }

        Log::info("Mining Manager: Calculating bonuses for event {$event->id} ({$event->bonus_percentage}% bonus)");

        $generalSettings = $this->settingsService->getGeneralSettings();
        $pricingSettings = $this->settingsService->getPricingSettings();
        $regionId = $generalSettings['default_region_id'] ?? 10000002;
        $priceType = $pricingSettings['price_type'] ?? 'sell';
        
        $priceColumn = match ($priceType) {
            'buy' => 'buy_price',
            'average' => 'average_price',
            default => 'sell_price',
        };

        $participants = $event->participants;
        $bonusRate = $event->bonus_percentage / 100;
        $totalBonus = 0;

        foreach ($participants as $participant) {
            // Get mining details for this participant during the event
            $query = MiningLedger::where('character_id', $participant->character_id)
                ->whereBetween('date', [
                    $event->start_time,
                    $event->end_time ?? Carbon::now()
                ]);

            if ($event->solar_system_id) {
                $query->where('solar_system_id', $event->solar_system_id);
            }

            // Calculate value with prices
            $value = $query->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                    $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                        ->where('mining_price_cache.region_id', '=', $regionId);
                })
                ->select(DB::raw("SUM(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as total_value"))
                ->value('total_value') ?? 0;

            $bonus = $value * $bonusRate;

            $participant->update([
                'bonus_earned' => $bonus,
            ]);

            $totalBonus += $bonus;
        }

        Log::info("Mining Manager: Calculated bonuses for event {$event->id}: " . number_format($totalBonus, 2) . " ISK total");

        return [
            'participants' => $participants->count(),
            'total_bonus' => $totalBonus,
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
                'bonus_earned' => 0,
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
            'total_bonuses' => $participants->sum('bonus_earned'),
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
     * @return \Illuminate\Support\Collection
     */
    public function getUpcomingEvents(int $days = 7)
    {
        return MiningEvent::whereIn('status', ['planned', 'active'])
            ->where('start_time', '>=', Carbon::now())
            ->where('start_time', '<=', Carbon::now()->addDays($days))
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Get active events.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getActiveEvents()
    {
        return MiningEvent::where('status', 'active')
            ->with(['participants.character'])
            ->orderBy('start_time', 'desc')
            ->get();
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

        // Check bonus percentage
        if (isset($data['bonus_percentage'])) {
            if ($data['bonus_percentage'] < 0 || $data['bonus_percentage'] > 100) {
                $errors[] = "Bonus percentage must be between 0 and 100";
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
        $event = MiningEvent::with(['participants.character', 'solarSystem'])->findOrFail($eventId);

        return [
            'event' => [
                'name' => $event->name,
                'description' => $event->description,
                'start_time' => $event->start_time->toIso8601String(),
                'end_time' => $event->end_time?->toIso8601String(),
                'system' => $event->solarSystem?->name,
                'status' => $event->status,
                'bonus_percentage' => $event->bonus_percentage,
            ],
            'statistics' => $this->getEventStatistics($eventId),
            'participants' => $event->participants->map(function ($participant) {
                return [
                    'character' => $participant->character->name,
                    'quantity_mined' => $participant->quantity_mined,
                    'bonus_earned' => $participant->bonus_earned,
                    'joined_at' => $participant->joined_at?->toIso8601String(),
                ];
            })->toArray(),
        ];
    }
}
