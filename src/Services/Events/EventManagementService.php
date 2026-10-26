<?php

namespace MiningManager\Services\Events;

use MiningManager\Models\EventHistory;
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
     *   held    → completed when applies_at <= now (fires 'event_completed', with its figures, and locks it)
     *   planned → active   when start_time <= now  (fires 'event_started' notification)
     *   active  → completed when end_time  <= now  (fires 'event_completed' notification)
     *
     * Cancelled events are never touched.
     *
     * @return array ['applied' => int, 'started' => int, 'completed' => int]
     */
    public function updateEventStatuses(): array
    {
        $now = Carbon::now();
        $applied = $this->applyHeldEvents($now);
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
                EventHistory::record($event, EventHistory::STARTED);

                // Dispatch 'event_started' notification, unless the event is
                // already over: one set up after its days completes below in
                // this same run, and a start announced now would send people
                // to mine for days that have passed.
                if ($event->end_time === null || $event->end_time->gt($now)) {
                    try {
                        $participantIds = $event->participants()->pluck('character_id')->toArray();
                        app(NotificationService::class)->sendEventStarted($event->fresh(), $participantIds);
                    } catch (\Exception $e) {
                        Log::warning("Mining Manager: Failed to send event_started notification for event {$event->id}: " . $e->getMessage());
                    }
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
                // Gather and track once more before closing, so Event
                // Completed carries the figures. This is also where an event
                // set up after its days gets gathered: the tracking run stamps
                // it, so the update that follows no longer sees it as never
                // gathered.
                try {
                    app(EventMiningAggregator::class)->aggregate($event);
                    $this->trackingService->updateEventTracking($event);
                } catch (\Exception $e) {
                    Log::warning("Mining Manager: Final gathering failed for event {$event->id}: " . $e->getMessage());
                }

                $claimed = MiningEvent::where('id', $event->id)
                    ->where('status', 'active')
                    ->update(['status' => 'completed']);

                if ($claimed === 0) {
                    Log::debug("Mining Manager: Event {$event->id} no longer active at claim time; skipping cron complete (already completed by another path)");
                    continue;
                }

                Log::info("Mining Manager: Event '{$event->name}' (id={$event->id}) auto-completed");
                EventHistory::record($event, EventHistory::COMPLETED);

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

        return ['applied' => $applied, 'started' => $started, 'completed' => $completed];
    }

    /**
     * Apply the events set up for days that had passed whose hold is over:
     * gather what arrived since the preview, put the discount on their days
     * not billed yet, announce them with their figures, and lock them.
     *
     * @return int events applied
     */
    private function applyHeldEvents(Carbon $now): int
    {
        if (!MiningEvent::hasGovernance()) {
            return 0;
        }

        $applied = 0;
        $due = MiningEvent::where('status', MiningEvent::STATUS_HELD)
            ->where('applies_at', '<=', $now)
            ->get();

        foreach ($due as $event) {
            try {
                // Claimed first, so a run that overlaps this one cannot apply
                // and announce it again.
                $claimed = MiningEvent::where('id', $event->id)
                    ->where('status', MiningEvent::STATUS_HELD)
                    ->update(['status' => MiningEvent::STATUS_COMPLETED, 'locked_at' => $now]);

                if ($claimed === 0) {
                    continue;
                }

                $event = $event->fresh();
                $aggregator = app(EventMiningAggregator::class);
                $aggregator->aggregate($event);
                // The claim changes no row through the model, so its days
                // are worked out here, now that the rate applies.
                $aggregator->rebuildDays($event);
                $this->trackingService->updateEventTracking($event);
                EventHistory::record($event, EventHistory::APPLIED);
                Log::info("Mining Manager: Event '{$event->name}' (id={$event->id}) applied to the days that had passed");

                try {
                    $participantIds = $event->participants()->pluck('character_id')->toArray();
                    app(NotificationService::class)->sendEventCompleted($event->fresh(), $participantIds);
                } catch (\Exception $e) {
                    Log::warning("Mining Manager: Failed to send event_completed notification for event {$event->id}: " . $e->getMessage());
                }

                $applied++;
            } catch (\Exception $e) {
                Log::error("Mining Manager: Failed to apply held event {$event->id}: " . $e->getMessage());
            }
        }

        return $applied;
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
