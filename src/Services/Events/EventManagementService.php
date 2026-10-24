<?php

namespace MiningManager\Services\Events;

use MiningManager\Models\MiningEvent;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;
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
     * Called by the `mining-manager:update-events` command on every run.
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
        // ATOMIC CLAIM via compare-and-swap. A plain read of status='planned'
        // followed by `$event->update(['status' => 'active'])` would race with
        // the manual start path (EventController::start): a director clicking
        // "Start" the same minute the cron fires means both pass their reads
        // and writes, and both dispatch event_started. Instead: UPDATE WHERE status='planned'
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
     * Gather the event's mining again from scratch and roll it up into its
     * participants, by the same rules the every-minute update uses, so the
     * Update Data button cannot count mining the event does not.
     *
     * @param MiningEvent $event
     * @return array
     */
    public function updateEventData(MiningEvent $event): array
    {
        Log::debug("Mining Manager: Updating data for event {$event->id}");

        app(EventMiningAggregator::class)->aggregate($event, true);
        $this->trackingService->updateEventTracking($event);
        $event->refresh();

        return [
            'participants' => (int) $event->participant_count,
            'total_mined' => (int) $event->total_mined,
        ];
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
}
