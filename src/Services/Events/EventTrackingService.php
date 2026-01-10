<?php

namespace MiningManager\Services\Events;

use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventParticipant;
use MiningManager\Models\MiningLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class EventTrackingService
{
    /**
     * Track mining activity for active events.
     *
     * @return array
     */
    public function trackActiveEvents(): array
    {
        $activeEvents = MiningEvent::where('status', 'active')
            ->where('start_time', '<=', Carbon::now())
            ->where(function ($query) {
                $query->whereNull('end_time')
                    ->orWhere('end_time', '>=', Carbon::now());
            })
            ->get();

        if ($activeEvents->isEmpty()) {
            return [
                'tracked' => 0,
                'errors' => [],
            ];
        }

        Log::info("Mining Manager: Tracking {$activeEvents->count()} active events");

        $tracked = 0;
        $errors = [];

        foreach ($activeEvents as $event) {
            try {
                $this->updateEventTracking($event);
                $tracked++;
            } catch (\Exception $e) {
                Log::error("Mining Manager: Error tracking event {$event->id}: " . $e->getMessage());
                $errors[] = [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'tracked' => $tracked,
            'errors' => $errors,
        ];
    }

    /**
     * Update tracking data for a specific event.
     *
     * @param MiningEvent $event
     * @return array
     */
    public function updateEventTracking(MiningEvent $event): array
    {
        Log::debug("Mining Manager: Updating tracking for event {$event->id}");

        // Get recent mining activity (since last update or event start)
        $lastUpdate = $event->last_updated ?? $event->start_time;

        $query = MiningLedger::where('date', '>=', $lastUpdate)
            ->whereBetween('date', [
                $event->start_time,
                $event->end_time ?? Carbon::now()
            ]);

        // Filter by location if specified
        if ($event->solar_system_id) {
            $query->where('solar_system_id', $event->solar_system_id);
        }

        $newActivity = $query->get();

        if ($newActivity->isEmpty()) {
            return [
                'new_participants' => 0,
                'updated_participants' => 0,
            ];
        }

        $newParticipants = 0;
        $updatedParticipants = 0;

        DB::transaction(function () use ($event, $newActivity, &$newParticipants, &$updatedParticipants) {
            foreach ($newActivity->groupBy('character_id') as $characterId => $records) {
                $quantity = $records->sum('quantity');

                $participant = EventParticipant::where('event_id', $event->id)
                    ->where('character_id', $characterId)
                    ->first();

                if ($participant) {
                    // Update existing participant
                    $participant->increment('quantity_mined', $quantity);
                    $participant->update(['last_updated' => Carbon::now()]);
                    $updatedParticipants++;
                } else {
                    // Create new participant
                    EventParticipant::create([
                        'event_id' => $event->id,
                        'character_id' => $characterId,
                        'quantity_mined' => $quantity,
                        'joined_at' => $records->min('date'),
                        'last_updated' => Carbon::now(),
                    ]);
                    $newParticipants++;
                }
            }

            // Update event statistics
            $event->participant_count = $event->participants()->count();
            $event->total_mined = $event->participants()->sum('quantity_mined');
            $event->last_updated = Carbon::now();
            $event->save();
        });

        Log::debug("Mining Manager: Event {$event->id} tracking updated: {$newParticipants} new, {$updatedParticipants} updated");

        return [
            'new_participants' => $newParticipants,
            'updated_participants' => $updatedParticipants,
        ];
    }

    /**
     * Get real-time event progress.
     *
     * @param int $eventId
     * @return array
     */
    public function getEventProgress(int $eventId): array
    {
        $cacheKey = "mining-events:progress:{$eventId}";
        $cacheDuration = 5; // 5 minutes

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($eventId) {
            $event = MiningEvent::with(['participants.character'])->findOrFail($eventId);

            $totalMined = $event->participants->sum('quantity_mined');
            $participantCount = $event->participants->count();

            // Calculate time-based metrics
            $elapsedTime = $event->start_time->diffInMinutes(Carbon::now());
            $estimatedTotalTime = $event->end_time 
                ? $event->start_time->diffInMinutes($event->end_time) 
                : null;

            $progress = null;
            if ($estimatedTotalTime) {
                $progress = min(100, ($elapsedTime / $estimatedTotalTime) * 100);
            }

            // Calculate mining rate
            $miningRate = $elapsedTime > 0 ? ($totalMined / $elapsedTime) * 60 : 0; // per hour

            return [
                'event_id' => $event->id,
                'status' => $event->status,
                'total_mined' => $totalMined,
                'participant_count' => $participantCount,
                'elapsed_minutes' => $elapsedTime,
                'progress_percentage' => $progress,
                'mining_rate_per_hour' => round($miningRate, 2),
                'average_per_participant' => $participantCount > 0 ? $totalMined / $participantCount : 0,
                'top_miners' => $event->participants()
                    ->with('character')
                    ->orderByDesc('quantity_mined')
                    ->limit(5)
                    ->get()
                    ->map(fn($p) => [
                        'name' => $p->character->name,
                        'quantity' => $p->quantity_mined,
                    ]),
            ];
        });
    }

    /**
     * Get event participation trends.
     *
     * @param int $eventId
     * @return array
     */
    public function getParticipationTrends(int $eventId): array
    {
        $event = MiningEvent::findOrFail($eventId);

        // Get hourly breakdown of mining activity
        $miningData = MiningLedger::whereBetween('date', [
                $event->start_time,
                $event->end_time ?? Carbon::now()
            ])
            ->when($event->solar_system_id, function ($query) use ($event) {
                return $query->where('solar_system_id', $event->solar_system_id);
            })
            ->selectRaw('DATE_FORMAT(date, "%Y-%m-%d %H:00:00") as hour')
            ->selectRaw('SUM(quantity) as total_quantity')
            ->selectRaw('COUNT(DISTINCT character_id) as active_miners')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return [
            'hourly_data' => $miningData->map(function ($data) {
                return [
                    'hour' => Carbon::parse($data->hour)->format('H:i'),
                    'quantity' => $data->total_quantity,
                    'active_miners' => $data->active_miners,
                ];
            })->toArray(),
        ];
    }

    /**
     * Track participant activity.
     *
     * @param int $participantId
     * @return array
     */
    public function trackParticipantActivity(int $participantId): array
    {
        $participant = EventParticipant::with(['event', 'character'])->findOrFail($participantId);
        $event = $participant->event;

        // Get detailed mining breakdown
        $miningData = MiningLedger::where('character_id', $participant->character_id)
            ->whereBetween('date', [
                $event->start_time,
                $event->end_time ?? Carbon::now()
            ])
            ->when($event->solar_system_id, function ($query) use ($event) {
                return $query->where('solar_system_id', $event->solar_system_id);
            })
            ->with('type')
            ->get();

        // Breakdown by ore type
        $oreBreakdown = $miningData->groupBy('type_id')
            ->map(function ($records, $typeId) {
                $type = $records->first()->type;
                return [
                    'ore_name' => $type ? $type->typeName : "Type {$typeId}",
                    'quantity' => $records->sum('quantity'),
                    'percentage' => 0, // Will calculate after
                ];
            });

        $totalQuantity = $oreBreakdown->sum('quantity');
        $oreBreakdown = $oreBreakdown->map(function ($item) use ($totalQuantity) {
            $item['percentage'] = $totalQuantity > 0 
                ? round(($item['quantity'] / $totalQuantity) * 100, 2) 
                : 0;
            return $item;
        });

        // Activity timeline
        $timeline = $miningData->groupBy(function ($item) {
                return $item->date->format('Y-m-d');
            })
            ->map(function ($records, $date) {
                return [
                    'date' => $date,
                    'quantity' => $records->sum('quantity'),
                ];
            })
            ->values();

        return [
            'participant' => [
                'name' => $participant->character->name,
                'joined_at' => $participant->joined_at,
                'total_mined' => $participant->quantity_mined,
            ],
            'ore_breakdown' => $oreBreakdown->values()->toArray(),
            'timeline' => $timeline->toArray(),
            'rank' => $this->getParticipantRank($participant),
        ];
    }

    /**
     * Get participant rank in event.
     *
     * @param EventParticipant $participant
     * @return int
     */
    private function getParticipantRank(EventParticipant $participant): int
    {
        return EventParticipant::where('event_id', $participant->event_id)
            ->where('quantity_mined', '>', $participant->quantity_mined)
            ->count() + 1;
    }

    /**
     * Compare participant performance to event average.
     *
     * @param int $participantId
     * @return array
     */
    public function compareToAverage(int $participantId): array
    {
        $participant = EventParticipant::findOrFail($participantId);
        $event = $participant->event;

        $averageQuantity = $event->participant_count > 0 
            ? $event->total_mined / $event->participant_count 
            : 0;

        $difference = $participant->quantity_mined - $averageQuantity;
        $percentageDifference = $averageQuantity > 0 
            ? ($difference / $averageQuantity) * 100 
            : 0;

        return [
            'participant_quantity' => $participant->quantity_mined,
            'event_average' => round($averageQuantity, 2),
            'difference' => round($difference, 2),
            'percentage_difference' => round($percentageDifference, 2),
            'above_average' => $difference > 0,
        ];
    }

    /**
     * Detect inactive participants.
     *
     * @param int $eventId
     * @param int $hoursInactive
     * @return \Illuminate\Support\Collection
     */
    public function detectInactiveParticipants(int $eventId, int $hoursInactive = 2)
    {
        $cutoffTime = Carbon::now()->subHours($hoursInactive);

        return EventParticipant::where('event_id', $eventId)
            ->with('character')
            ->where('last_updated', '<', $cutoffTime)
            ->get()
            ->map(function ($participant) use ($cutoffTime) {
                return [
                    'character_id' => $participant->character_id,
                    'character_name' => $participant->character->name,
                    'last_active' => $participant->last_updated,
                    'hours_inactive' => $participant->last_updated->diffInHours($cutoffTime),
                    'quantity_mined' => $participant->quantity_mined,
                ];
            });
    }

    /**
     * Get event leaderboard with detailed stats.
     *
     * @param int $eventId
     * @return array
     */
    public function getDetailedLeaderboard(int $eventId): array
    {
        $event = MiningEvent::findOrFail($eventId);

        $participants = EventParticipant::where('event_id', $eventId)
            ->with('character')
            ->orderByDesc('quantity_mined')
            ->get();

        $totalMined = $participants->sum('quantity_mined');

        return [
            'event' => [
                'name' => $event->name,
                'total_mined' => $totalMined,
                'participant_count' => $participants->count(),
            ],
            'leaderboard' => $participants->map(function ($participant, $index) use ($totalMined) {
                return [
                    'rank' => $index + 1,
                    'character_name' => $participant->character->name,
                    'quantity_mined' => $participant->quantity_mined,
                    'percentage_of_total' => $totalMined > 0
                        ? round(($participant->quantity_mined / $totalMined) * 100, 2)
                        : 0,
                    'joined_at' => $participant->joined_at?->toDateTimeString(),
                ];
            })->toArray(),
        ];
    }

    /**
     * Clear event tracking cache.
     *
     * @param int|null $eventId
     * @return void
     */
    public function clearTrackingCache(?int $eventId = null): void
    {
        if ($eventId) {
            Cache::forget("mining-events:progress:{$eventId}");
        } else {
            try {
                Cache::tags(['mining-events'])->flush();
            } catch (\BadMethodCallException $e) {
                // Fallback for cache drivers that don't support tags
                Cache::flush();
            }
        }
    }

    /**
     * Get event performance metrics.
     *
     * @param int $eventId
     * @return array
     */
    public function getPerformanceMetrics(int $eventId): array
    {
        $event = MiningEvent::with('participants')->findOrFail($eventId);

        if (!$event->end_time || $event->status !== 'completed') {
            return ['error' => 'Event not completed'];
        }

        $duration = $event->start_time->diffInHours($event->end_time);
        $totalMined = $event->total_mined;
        $participantCount = $event->participant_count;

        return [
            'duration_hours' => $duration,
            'total_mined' => $totalMined,
            'ore_per_hour' => $duration > 0 ? round($totalMined / $duration, 2) : 0,
            'ore_per_participant' => $participantCount > 0 ? round($totalMined / $participantCount, 2) : 0,
            'ore_per_participant_per_hour' => ($duration > 0 && $participantCount > 0) 
                ? round($totalMined / ($duration * $participantCount), 2) 
                : 0,
            'participation_efficiency' => $this->calculateParticipationEfficiency($event),
        ];
    }

    /**
     * Calculate participation efficiency.
     *
     * @param MiningEvent $event
     * @return float Percentage
     */
    private function calculateParticipationEfficiency(MiningEvent $event): float
    {
        // Calculate how evenly distributed the mining was
        $participants = $event->participants;
        
        if ($participants->isEmpty()) {
            return 0;
        }

        $average = $participants->avg('quantity_mined');
        $variance = $participants->map(function ($p) use ($average) {
            return pow($p->quantity_mined - $average, 2);
        })->avg();

        $stdDev = sqrt($variance);
        $coefficientOfVariation = $average > 0 ? ($stdDev / $average) * 100 : 0;

        // Lower CoV = more even distribution = higher efficiency
        return max(0, 100 - $coefficientOfVariation);
    }
}
