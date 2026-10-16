<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Notification\NotificationService;

/**
 * Next Extraction Planned, sent again on request from the Moon Planner for
 * every refinery whose chunk has arrived and whose next pull has not been
 * started in game.
 *
 * Each refinery gets the message it got when its chunk arrived, built by the
 * same method, so a pull too far ahead to set up yet says from when it can be
 * started. Nothing is latched or reset: the arrival latch and the reminder
 * that follows a far pull carry on as they would have.
 */
class NextPullNotices
{
    /**
     * Gap between messages. A webhook takes about five posts every two
     * seconds before Discord starts refusing them, and a batch is every idle
     * refinery at once.
     */
    public const PAUSE_MICROSECONDS = 450000;

    protected RefineryService $refineries;
    protected PlannerReminders $reminders;
    protected MoonExtractionService $extractions;

    public function __construct(RefineryService $refineries, PlannerReminders $reminders, MoonExtractionService $extractions)
    {
        $this->refineries = $refineries;
        $this->reminders = $reminders;
        $this->extractions = $extractions;
    }

    /**
     * Every refinery whose chunk has arrived with nothing started since, with
     * the message it would get or why it gets none. Longest idle first.
     *
     * @return array<int, array>
     */
    public function candidates(int $corporationId, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        $refineries = $this->refineries->refineriesForCorporation($corporationId);
        $owned = $refineries->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$owned) {
            return [];
        }

        $running = $this->refineries->running($owned);
        $lastArrival = $this->reminders->lastArrivals($owned, $now);
        $drillDown = $this->refineries->drillsOffline($owned);
        $destroyed = $this->refineries->reportedDestroyed($owned);
        $unanchoring = $this->refineries->unanchoringIdle($refineries, $running);

        $entries = [];

        foreach ($owned as $structureId) {
            $arrival = $lastArrival[$structureId] ?? null;

            // Never pulled, or already started again: nothing to schedule.
            if (!$arrival || in_array($structureId, $running, true)) {
                continue;
            }

            $names = $this->refineries->names($structureId);
            $entry = $names + [
                'structure_id' => $structureId,
                'arrived_at' => $arrival,
                'skip' => null,
            ];

            // The same refineries Moon Not Rescheduled holds back on: nothing
            // can be started on them for now.
            if (in_array($structureId, $destroyed, true)) {
                $entry['skip'] = 'reported destroyed';
            } elseif (in_array($structureId, $unanchoring, true)) {
                $entry['skip'] = 'being unanchored with nothing running';
            } elseif (in_array($structureId, $drillDown, true)) {
                $entry['skip'] = 'moon drill offline';
            } else {
                $message = $this->extractions->nextPlannedMessage($structureId, $names['moon_name'] ?? 'Unknown Moon', $arrival);

                if ($message['kind'] === 'next') {
                    $entry['message'] = $message['payload'];
                    $entry['planned_at'] = $message['planned_at'];
                    $entry['startable_from'] = $message['startable_from'];
                    $entry['projected'] = $message['source'] === 'auto';
                    $entry['next_is_final'] = !empty($message['payload']['next_is_final']);
                } elseif ($message['kind'] === 'final') {
                    $entry['skip'] = 'its final pull has arrived';
                } elseif ($message['reason'] === 'after_final') {
                    $entry['skip'] = 'started again after its final pull';
                } else {
                    $entry['skip'] = 'nothing planned and no history to project from';
                }
            }

            $entries[] = $entry;
        }

        usort($entries, fn ($a, $b) => $a['arrived_at']->getTimestamp() <=> $b['arrived_at']->getTimestamp());

        return $entries;
    }

    /**
     * Send the message to each refinery that has one. A message counts as
     * delivered when at least one webhook or Slack took it.
     *
     * @return array{entries: array<int, array>, sent: int, undelivered: int}
     */
    public function send(int $corporationId, ?Carbon $now = null): array
    {
        $entries = $this->candidates($corporationId, $now);
        $notifications = app(NotificationService::class);

        $sent = 0;
        $undelivered = 0;
        $first = true;

        foreach ($entries as &$entry) {
            if ($entry['skip'] !== null) {
                continue;
            }

            if (!$first) {
                usleep(self::PAUSE_MICROSECONDS);
            }
            $first = false;

            try {
                $result = $notifications->sendNextExtractionPlanned($entry['message']);
                $entry['delivered'] = self::delivered($result);
                $entry['reason'] = $entry['delivered'] ? null : self::whyNot($result);
            } catch (\Throwable $e) {
                $entry['delivered'] = false;
                $entry['reason'] = $e->getMessage();

                Log::error('Mining Manager: could not send a next-pull notice again', [
                    'structure_id' => $entry['structure_id'],
                    'error' => $e->getMessage(),
                ]);
            }

            $entry['delivered'] ? $sent++ : $undelivered++;
        }
        unset($entry);

        Log::info('Mining Manager: next-pull notices sent again from the planner', [
            'corporation_id' => $corporationId,
            'sent' => $sent,
            'undelivered' => $undelivered,
            'skipped' => count(array_filter($entries, fn ($entry) => $entry['skip'] !== null)),
        ]);

        return ['entries' => $entries, 'sent' => $sent, 'undelivered' => $undelivered];
    }

    /**
     * Discord reports the webhooks it posted to, Slack a success flag.
     */
    protected static function delivered(array $result): bool
    {
        foreach ($result as $channel) {
            if (is_array($channel) && (!empty($channel['sent']) || ($channel['success'] ?? false) === true)) {
                return true;
            }
        }

        return false;
    }

    protected static function whyNot(array $result): string
    {
        if (!empty($result['reason'])) {
            return (string) $result['reason'];
        }

        foreach ($result as $channel) {
            if (is_array($channel) && (!empty($channel['reason']) || !empty($channel['error']))) {
                return (string) ($channel['reason'] ?? $channel['error']);
            }
        }

        return 'No webhook has Next Extraction Planned ticked';
    }
}
