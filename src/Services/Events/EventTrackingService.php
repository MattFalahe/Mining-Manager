<?php

namespace MiningManager\Services\Events;

use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventParticipant;
use MiningManager\Models\EventMiningRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EventTrackingService
{
    /**
     * Update tracking data for a specific event.
     *
     * Rolls up event_mining_records (populated by EventMiningAggregator) into
     * event_participants rows and refreshes aggregate counters on the event
     * itself. All filtering — corporation, location or refineries, ore
     * category, time window — has already been applied at aggregation time,
     * so this method does a plain per-character sum and nothing else. The
     * sums are all of a character's event mining, whether or not it gets the
     * event's rate, so the event shows everything mined for it.
     *
     * Idempotent via updateOrCreate — re-runs don't double-count.
     *
     * @param MiningEvent $event
     * @return array ['new_participants' => int, 'updated_participants' => int]
     */
    public function updateEventTracking(MiningEvent $event): array
    {
        Log::debug("Mining Manager: Updating tracking for event {$event->id}");

        $records = EventMiningRecord::where('event_id', $event->id)->get();

        if ($records->isEmpty()) {
            Log::info("Mining Manager: Event {$event->id} tracking run — no event_mining_records present (run EventMiningAggregator first)");

            // Even with no records, refresh the event's aggregate counters
            // so stale totals get zeroed out (e.g. after a scope edit).
            $event->participant_count = $event->participants()->count();
            $event->total_mined = $event->participants()->sum('quantity_mined');
            $event->total_mined_value = $event->participants()->sum('value_mined');
            $event->last_updated = Carbon::now();
            $event->save();

            return [
                'new_participants' => 0,
                'updated_participants' => 0,
            ];
        }

        $newParticipants = 0;
        $updatedParticipants = 0;

        DB::transaction(function () use ($event, $records, &$newParticipants, &$updatedParticipants) {
            foreach ($records->groupBy('character_id') as $characterId => $charRecords) {
                // Sums for this character across all their event-qualifying records.
                // updateOrCreate SETS these totals (not increments), so rerunning
                // the tracker is safe.
                $quantity = (int) $charRecords->sum('quantity');
                $valueIsk = $charRecords->sum('value_isk'); // decimal

                $existed = EventParticipant::where('event_id', $event->id)
                    ->where('character_id', $characterId)
                    ->exists();

                EventParticipant::updateOrCreate(
                    [
                        'event_id' => $event->id,
                        'character_id' => $characterId,
                    ],
                    [
                        'quantity_mined' => $quantity,
                        'value_mined' => (int) round($valueIsk),
                        'last_updated' => Carbon::now(),
                    ]
                );

                $existed ? $updatedParticipants++ : $newParticipants++;
            }

            // Prune participants who no longer have any qualifying records
            // (e.g. after a scope edit that narrowed the event). Signing up
            // lives in the sign-ups table, so a row here is only ever mining.
            // Rows with a joined_at come from before that, when a Join click
            // and the first mining day were both stamped here; they stay.
            $currentCharIds = $records->pluck('character_id')->unique()->values();
            EventParticipant::where('event_id', $event->id)
                ->whereNotIn('character_id', $currentCharIds)
                ->whereNull('joined_at')
                ->delete();

            // Recompute event-level aggregates from participant rows.
            $event->participant_count = $event->participants()->count();
            $event->total_mined = $event->participants()->sum('quantity_mined');
            $event->total_mined_value = $event->participants()->sum('value_mined');
            $event->last_updated = Carbon::now();
            $event->save();
        });

        Log::info("Mining Manager: Event {$event->id} tracking updated: {$newParticipants} new, {$updatedParticipants} refreshed from " . $records->count() . ' records');

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

        return app(\MiningManager\Services\Character\CharacterNames::class)->remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($eventId) {
            $event = MiningEvent::with(['participants'])->findOrFail($eventId);

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
                    ->orderByDesc('quantity_mined')
                    ->limit(5)
                    ->get()
                    ->map(fn($p) => [
                        'name' => $p->character_name,
                        'quantity' => $p->quantity_mined,
                    ]),
            ];
        });
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
            ->where('last_updated', '<', $cutoffTime)
            ->get()
            ->map(function ($participant) use ($cutoffTime) {
                return [
                    'character_id' => $participant->character_id,
                    'character_name' => $participant->character_name,
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
                    'character_name' => $participant->character_name,
                    'quantity_mined' => $participant->quantity_mined,
                    'percentage_of_total' => $totalMined > 0
                        ? round(($participant->quantity_mined / $totalMined) * 100, 2)
                        : 0,
                    'joined_at' => $participant->joined_at?->toDateTimeString(),
                ];
            })->toArray(),
        ];
    }
}
