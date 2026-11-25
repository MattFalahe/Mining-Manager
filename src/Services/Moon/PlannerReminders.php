<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use MiningManager\Models\MoonExtractionPlan;
use MiningManager\Models\RefineryAlert;
use MiningManager\Services\Configuration\SettingsManagerService;

/**
 * The Moon Planner's reminders: things nobody has done yet that somebody
 * should.
 *
 * None of them speaks about a refinery that cannot pull:
 *   - one the corporation no longer has, or with no moon drill fitted, both
 *     already left out by RefineryService::refineriesForCorporation()
 *   - one the game has reported destroyed, before SeAT's list catches up
 *   - one being unanchored with no extraction running. Unanchoring takes
 *     seven days, too short for a new pull to arrive and be mined before the
 *     structure goes. While an extraction is still running, it is treated
 *     like any other refinery.
 * Moon Not Rescheduled and Moons Need Planning also leave out one whose final
 * pull is marked on the planner: nobody is meant to plan or restart it. If
 * somebody starts an extraction there anyway, restartedAfterFinal() says so.
 * nowStartable() does not, because a final pull still has to be started.
 *
 * Settings are read without a corporation context. These run from scheduled
 * commands, where the settings service can be left pointing at whichever
 * corporation the run touched last.
 */
class PlannerReminders
{
    /**
     * Refineries one Moons Need Planning message lists before it says how many
     * more there are. Keeps the message inside what Discord and Slack accept.
     */
    public const LIST_LIMIT = 25;

    /** When Moons Need Planning last went out, so it keeps to its cadence. */
    public const NEEDS_PLANNING_LAST_SENT = 'notifications.schedule_needs_filling_last_sent';

    protected const RARITY_RANK = ['R64' => 5, 'R32' => 4, 'R16' => 3, 'R8' => 2, 'R4' => 1];

    /**
     * Where an entry keeps its record as it was before this pass, for undo().
     * Taken off by message().
     */
    protected const WAS = '_was';

    protected MoonPlannerService $planner;
    protected SettingsManagerService $settings;
    protected RefineryService $refineries;

    public function __construct(MoonPlannerService $planner, SettingsManagerService $settings, RefineryService $refineries)
    {
        $this->planner = $planner;
        $this->settings = $settings;
        $this->refineries = $refineries;
    }

    /**
     * Refineries whose chunk arrived a while ago with nothing started since.
     *
     * Once the drill has been idle for the configured hours a reminder goes;
     * with repeats on, again every that many hours, until an extraction is
     * started. When the next planned pull is further ahead than an extraction
     * can run, the hours count from when it can be started instead.
     *
     * A refinery that drops out of SeAT's list for a sync, has its drill go
     * offline, is reported destroyed or is being unanchored is skipped without
     * losing its count, so coming back does not start it again at reminder one.
     * The count is cleared when an extraction starts, or once the refinery is
     * certainly gone.
     *
     * @return array<int, array> one entry per reminder to send on this pass
     */
    public function notRescheduled(int $corporationId, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $idleHours = max(1, (int) $this->setting('moon_not_rescheduled_hours', 48));
        $repeat = (bool) $this->setting('moon_not_rescheduled_repeat', true);

        $refineries = $this->refineries->refineriesForCorporation($corporationId);
        $owned = $refineries->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$owned) {
            return [];
        }

        $this->forgetGone($corporationId, $owned);

        $running = $this->refineries->running($owned);
        $lastArrival = $this->lastArrivals($owned, $now);
        $finals = app(FinalPulls::class)->forStructures($owned, $now);
        $drillDown = $this->refineries->drillsOffline($owned);
        $destroyed = $this->refineries->reportedDestroyed($owned);
        $unanchoring = $this->refineries->unanchoringIdle($refineries, $running);

        $reminders = [];

        foreach ($owned as $structureId) {
            $alert = RefineryAlert::firstOrNew([
                'corporation_id' => $corporationId,
                'structure_id' => $structureId,
                'kind' => RefineryAlert::KIND_NOT_RESCHEDULED,
            ]);

            $arrival = $lastArrival[$structureId] ?? null;

            // Started again, or never pulled: nothing to remind about, and a
            // later idle spell starts its count from scratch.
            if (in_array($structureId, $running, true) || !$arrival) {
                if ($alert->exists) {
                    $alert->delete();
                }
                continue;
            }

            // Cannot be acted on right now, but nothing has been resolved
            // either, so the count is kept for when it can. A refinery whose
            // final pull is planned is not to be restarted at all; its count
            // is kept in case it resumes.
            if (in_array($structureId, $drillDown, true)
                || in_array($structureId, $destroyed, true)
                || in_array($structureId, $unanchoring, true)
                || isset($finals[$structureId])) {
                continue;
            }

            // A pull planned further ahead than the longest extraction cannot
            // be set up yet, so nobody has left the drill idle until it can
            // be: the hours count from then. Next Extraction Planned says when
            // that is, and posts again once it comes.
            $next = $this->nextPlan($structureId, $now);
            $startable = $next ? MoonPlannerService::startWindow($next->planned_arrival_time)[0] : null;
            $waited = $startable !== null && $startable->gt($arrival);

            $idleFor = ($now->getTimestamp() - $arrival->getTimestamp()) / 3600;
            $since = $waited ? $startable : $arrival;
            if (($now->getTimestamp() - $since->getTimestamp()) / 3600 < $idleHours) {
                continue;
            }

            // A newer arrival than the one last reminded about is a new spell.
            if ($alert->exists && (!$alert->started_at || $alert->started_at->getTimestamp() !== $arrival->getTimestamp())) {
                $alert->count = 0;
                $alert->last_at = null;
            }

            // A row not saved yet has no count at all: Eloquent does not fill
            // in the column's default.
            $due = (int) $alert->count === 0
                || ($repeat && $alert->last_at && ($now->getTimestamp() - $alert->last_at->getTimestamp()) / 3600 >= $idleHours);

            if (!$due) {
                continue;
            }

            $was = $this->was($alert);
            $alert->started_at = $arrival;
            $alert->last_at = $now;
            $alert->count = $alert->count + 1;
            $alert->save();

            $reminders[] = $this->refineries->names($structureId) + array_filter([
                self::WAS => $was + ['id' => $alert->id],
                'structure_id' => $structureId,
                'arrived_at' => $arrival->format('Y-m-d H:i'),
                'hours_since' => (int) floor($idleFor),
                'next_planned' => $next ? $next->planned_arrival_time->format('Y-m-d H:i') : null,
                'startable_from' => $waited ? $startable->format('Y-m-d H:i') : null,
                'reminder_number' => $alert->count,
            ], fn ($value) => $value !== null);
        }

        return $reminders;
    }

    /**
     * Pulls that were planned too far ahead to set up in game, and now can
     * be. One message each, the first time the pull comes within reach, sent
     * as Next Extraction Planned, which said when the chunk arrived that it
     * would follow.
     *
     * Only a pull that was out of reach after the refinery's last chunk
     * arrived counts: one that could have been started all along was never
     * put off. While the refinery cannot pull (drill offline, reported
     * destroyed, unanchoring with nothing running) the message waits, and
     * goes once it can, as long as the pull can still arrive on time. A final
     * pull is no exception: it still has to be started.
     *
     * @return array<int, array> one entry per message to send on this pass
     */
    public function nowStartable(int $corporationId, ?Carbon $now = null): array
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
        $lastArrival = $this->lastArrivals($owned, $now);
        $drillDown = $this->refineries->drillsOffline($owned);
        $destroyed = $this->refineries->reportedDestroyed($owned);
        $unanchoring = $this->refineries->unanchoringIdle($refineries, $running);

        $messages = [];

        foreach ($owned as $structureId) {
            if (in_array($structureId, $running, true)
                || in_array($structureId, $drillDown, true)
                || in_array($structureId, $destroyed, true)
                || in_array($structureId, $unanchoring, true)) {
                continue;
            }

            $plan = $this->nextPlan($structureId, $now);
            if (!$plan) {
                continue;
            }

            [$earliest, $latest] = MoonPlannerService::startWindow($plan->planned_arrival_time);
            $arrival = $lastArrival[$structureId] ?? null;

            if ($now->lt($earliest) || $now->gt($latest) || ($arrival && $earliest->lte($arrival))) {
                continue;
            }

            $alert = RefineryAlert::firstOrNew([
                'corporation_id' => $corporationId,
                'structure_id' => $structureId,
                'kind' => RefineryAlert::KIND_START_WINDOW,
            ]);

            if ($alert->exists && $alert->started_at
                && $alert->started_at->getTimestamp() === $plan->planned_arrival_time->getTimestamp()) {
                continue;
            }

            $was = $this->was($alert);
            $alert->started_at = $plan->planned_arrival_time;
            $alert->last_at = $now;
            $alert->count = (int) $alert->count + 1;
            $alert->save();

            $messages[] = $this->refineries->names($structureId) + array_filter([
                self::WAS => $was + ['id' => $alert->id],
                'variant' => 'startable',
                'structure_id' => $structureId,
                'planned_arrival_time' => $plan->planned_arrival_time->format('Y-m-d H:i'),
                'startable_from' => $earliest->format('Y-m-d H:i'),
                'next_is_final' => $plan->is_final ? true : null,
            ], fn ($value) => $value !== null);
        }

        return $messages;
    }

    /**
     * Every refinery with fewer pulls planned ahead than the corporation asks
     * for, as one message, or null when nothing needs planning or the last one
     * went out too recently.
     *
     * Counted the way the planner's "Not planned" badge counts, so the message
     * and the page agree. Fewest planned first, then the richest moons. Built
     * from the refineries as they are at the moment of sending, so one that
     * has gone since the last message is simply not in it. A refinery whose
     * drill is offline is still listed, and says so: it is still fitted, and
     * pulls can be planned for when the fuel is back.
     */
    public function needsPlanning(int $corporationId, ?Carbon $now = null): ?array
    {
        $now = $now ?? Carbon::now();
        $target = max(1, (int) $this->setting('planned_ahead_target', 1));
        $everyHours = max(1, (int) $this->setting('schedule_needs_filling_hours', 24));

        $lastSent = $this->settings->getSettingForCorporation(self::NEEDS_PLANNING_LAST_SENT, null, null);
        if ($lastSent && ($now->getTimestamp() - Carbon::parse($lastSent)->getTimestamp()) / 3600 < $everyHours) {
            return null;
        }

        $refineries = $this->refineries->refineriesForCorporation($corporationId);
        $owned = $refineries->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$owned) {
            return null;
        }

        $drillDown = $this->refineries->drillsOffline($owned);
        $destroyed = $this->refineries->reportedDestroyed($owned);
        $unanchoring = $this->refineries->unanchoringIdle($refineries, $this->refineries->running($owned));
        $finals = app(FinalPulls::class)->forStructures($owned, $now);
        $short = [];

        foreach ($owned as $structureId) {
            if (in_array($structureId, $destroyed, true)
                || in_array($structureId, $unanchoring, true)
                || isset($finals[$structureId])) {
                continue;
            }

            $planned = $this->planner->futurePlanCount($structureId);
            if ($planned >= $target) {
                continue;
            }

            $short[] = $this->refineries->names($structureId) + [
                'planned' => $planned,
                'rarity' => $this->planner->highestRarityForStructure($structureId),
                'drill_down' => in_array($structureId, $drillDown, true),
            ];
        }

        if (!$short) {
            return null;
        }

        usort($short, function ($a, $b) {
            return [$a['planned'], -(self::RARITY_RANK[$a['rarity']] ?? 0), $a['structure_name']]
                <=> [$b['planned'], -(self::RARITY_RANK[$b['rarity']] ?? 0), $b['structure_name']];
        });

        $lines = array_map(function ($refinery) use ($target) {
            $where = isset($refinery['system_name']) ? $refinery['system_name'] . ': ' : '';
            $tier = $refinery['rarity'] ? ' (' . $refinery['rarity'] . ')' : '';
            $state = $refinery['planned'] === 0 ? 'nothing planned' : sprintf('%d of %d planned', $refinery['planned'], $target);

            return $where . $refinery['structure_name'] . $tier . ', ' . $state . ($refinery['drill_down'] ? ', drill offline' : '');
        }, array_slice($short, 0, self::LIST_LIMIT));

        return [
            'refineries' => $lines,
            'more_count' => max(0, count($short) - self::LIST_LIMIT),
            'total' => count($short),
            'target' => $target,
        ];
    }

    /**
     * Refineries where somebody started an extraction after the final pull
     * had arrived: the plan changed, or nobody told whoever started it. One
     * message per extraction, to the planner's channel and never the one the
     * extraction itself is announced in, since where a refinery goes next is
     * not for every member to read.
     *
     * @return array<int, array> one entry per message to send on this pass
     */
    public function restartedAfterFinal(int $corporationId, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $owned = $this->refineries->refineriesForCorporation($corporationId)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $messages = [];

        foreach (app(FinalPulls::class)->forStructures($owned, $now) as $structureId => $final) {
            if (!$final['restarted']) {
                continue;
            }

            $alert = RefineryAlert::firstOrNew([
                'corporation_id' => $corporationId,
                'structure_id' => $structureId,
                'kind' => RefineryAlert::KIND_FINAL_RESTARTED,
            ]);

            if ($alert->exists && $alert->started_at
                && $alert->started_at->getTimestamp() === $final['restarted']->getTimestamp()) {
                continue;
            }

            $was = $this->was($alert);
            $alert->started_at = $final['restarted'];
            $alert->last_at = $now;
            $alert->count = (int) $alert->count + 1;
            $alert->save();

            $messages[] = $this->refineries->names($structureId) + [
                self::WAS => $was + ['id' => $alert->id],
                'variant' => 'restarted',
                'structure_id' => $structureId,
                'chunk_arrival_time' => $final['restarted']->format('Y-m-d H:i'),
                'final_pull' => $final['time']->format('Y-m-d H:i'),
            ];
        }

        return $messages;
    }

    /**
     * Planned pulls nobody started in game in time, which
     * MoonPlannerService::markMissed() has marked. One message per refinery,
     * sent as Next Extraction Planned, where the pull was announced: which
     * pull was missed, when it had to be started by, and the refinery's next
     * pull that can still happen, or that nothing is planned.
     *
     * Each missed pull is announced once, while its arrival is still ahead.
     * A refinery the game has reported destroyed gets nothing; one that has
     * left the corporation's list is not asked about at all.
     *
     * @return array<int, array> one entry per message to send on this pass
     */
    public function missedPulls(int $corporationId, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        $owned = $this->refineries->refineriesForCorporation($corporationId)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$owned) {
            return [];
        }

        $missed = MoonExtractionPlan::forCorporation($corporationId)
            ->whereIn('structure_id', $owned)
            ->where('status', MoonExtractionPlan::STATUS_MISSED)
            ->where('planned_arrival_time', '>', $now)
            ->orderBy('planned_arrival_time')
            ->get()
            ->groupBy('structure_id');

        if ($missed->isEmpty()) {
            return [];
        }

        $destroyed = $this->refineries->reportedDestroyed($missed->keys()->map(fn ($id) => (int) $id)->all());
        $messages = [];

        foreach ($missed as $structureId => $plans) {
            $structureId = (int) $structureId;
            if (in_array($structureId, $destroyed, true)) {
                continue;
            }

            $alert = RefineryAlert::firstOrNew([
                'corporation_id' => $corporationId,
                'structure_id' => $structureId,
                'kind' => RefineryAlert::KIND_START_MISSED,
            ]);

            $since = $alert->exists ? $alert->started_at : null;
            $fresh = $plans->filter(fn ($plan) => !$since || $plan->planned_arrival_time->gt($since))->values();
            if ($fresh->isEmpty()) {
                continue;
            }

            $was = $this->was($alert);
            $alert->started_at = $fresh->last()->planned_arrival_time;
            $alert->last_at = $now;
            $alert->count = (int) $alert->count + 1;
            $alert->save();

            $next = $this->nextPlan($structureId, $now);
            [$nextFrom, $nextBy] = $next ? MoonPlannerService::startWindow($next->planned_arrival_time) : [null, null];
            $confirmed = $next && $next->status === MoonExtractionPlan::STATUS_CONFIRMED;

            // Somebody may have set the drill on a timer of their own. Then
            // nothing else can be started until that chunk is in.
            $running = MoonExtraction::where('structure_id', $structureId)
                ->where('status', 'extracting')
                ->where('chunk_arrival_time', '>', $now)
                ->min('chunk_arrival_time');

            $messages[] = $this->refineries->names($structureId) + array_filter([
                self::WAS => $was + ['id' => $alert->id],
                'variant' => 'missed',
                'structure_id' => $structureId,
                'missed_pull' => $fresh->map(fn ($plan) => $plan->planned_arrival_time->format('Y-m-d H:i'))->implode(', '),
                'start_deadline' => $fresh->map(fn ($plan) => MoonPlannerService::startWindow($plan->planned_arrival_time)[1]->format('Y-m-d H:i'))->implode(', '),
                'missed_was_final' => $fresh->contains(fn ($plan) => (bool) $plan->is_final) ? true : null,
                'planned_arrival_time' => $next ? $next->planned_arrival_time->format('Y-m-d H:i') : null,
                'next_is_final' => $next && $next->is_final ? true : null,
                'startable_from' => $next && !$confirmed && $nextFrom->gt($now) ? $nextFrom->format('Y-m-d H:i') : null,
                'start_by' => $next && !$confirmed ? $nextBy->format('Y-m-d H:i') : null,
                'running_arrival' => $running ? Carbon::parse($running)->format('Y-m-d H:i') : null,
                'earliest_arrival' => $next || $running ? null : $now->copy()->addDays(MoonPlannerService::MIN_EXTRACTION_DAYS)->format('Y-m-d H:i'),
            ], fn ($value) => $value !== null);
        }

        return $messages;
    }

    /**
     * An entry from notRescheduled(), nowStartable(), restartedAfterFinal()
     * or missedPulls() as it is sent, without what undo() needs.
     */
    public static function message(array $entry): array
    {
        unset($entry[self::WAS]);

        return $entry;
    }

    /**
     * Put an entry's record back as it was before this pass, for one that did
     * not go out: every channel refused it, or the webhooks bound to it are
     * switched off. The next pass finds it due again.
     */
    public function undo(array $entry): void
    {
        $was = $entry[self::WAS] ?? null;
        if (!is_array($was) || empty($was['id'])) {
            return;
        }

        if (!$was['existed']) {
            RefineryAlert::where('id', $was['id'])->delete();

            return;
        }

        RefineryAlert::where('id', $was['id'])->update([
            'started_at' => $was['started_at'],
            'last_at' => $was['last_at'],
            'count' => $was['count'],
        ]);
    }

    /**
     * A record's stored values, before this pass changes them.
     */
    protected function was(RefineryAlert $alert): array
    {
        return [
            'existed' => $alert->exists,
            'started_at' => $alert->getRawOriginal('started_at'),
            'last_at' => $alert->getRawOriginal('last_at'),
            'count' => $alert->getRawOriginal('count'),
        ];
    }

    /**
     * Record that Moons Need Planning went out, install wide, so the cadence
     * holds whichever corporation the run touched last.
     */
    public function markNeedsPlanningSent(?Carbon $now = null): void
    {
        $this->settings->updateGlobalSetting(self::NEEDS_PLANNING_LAST_SENT, ($now ?? Carbon::now())->toDateTimeString());
    }

    /**
     * Forget the reminder count of a refinery that is certainly gone: the
     * Refinery Gone watch has confirmed it, or the game has reported it
     * destroyed. Missing from one sync is not the same thing, and keeps its
     * count.
     */
    protected function forgetGone(int $corporationId, array $owned): void
    {
        $absent = RefineryAlert::where('corporation_id', $corporationId)
            ->where('kind', RefineryAlert::KIND_NOT_RESCHEDULED)
            ->whereNotIn('structure_id', $owned)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$absent) {
            return;
        }

        $confirmed = RefineryAlert::where('corporation_id', $corporationId)
            ->where('kind', RefineryAlert::KIND_MISSING)
            ->whereNotNull('acted_at')
            ->whereIn('structure_id', $absent)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $gone = array_values(array_unique(array_merge($confirmed, $this->refineries->reportedDestroyed($absent))));

        if ($gone) {
            RefineryAlert::where('corporation_id', $corporationId)
                ->where('kind', RefineryAlert::KIND_NOT_RESCHEDULED)
                ->whereIn('structure_id', $gone)
                ->delete();
        }
    }

    /**
     * The next pull planned on a refinery that can still happen, if there is
     * one.
     */
    protected function nextPlan(int $structureId, Carbon $now): ?MoonExtractionPlan
    {
        return $this->planner->nextPlan($structureId, $now);
    }

    /**
     * The latest chunk that has actually arrived on each refinery, live or
     * archived. A cancelled extraction never arrived, so it does not count.
     *
     * @return array<int, Carbon>
     */
    public function lastArrivals(array $structureIds, Carbon $now): array
    {
        $latest = [];

        $sources = [
            MoonExtraction::whereIn('structure_id', $structureIds)->where('status', '!=', 'cancelled'),
            MoonExtractionHistory::whereIn('structure_id', $structureIds)->where('final_status', '!=', 'cancelled'),
        ];

        foreach ($sources as $query) {
            $rows = $query->where('chunk_arrival_time', '<=', $now)->get(['structure_id', 'chunk_arrival_time']);

            foreach ($rows as $row) {
                $at = Carbon::parse($row->chunk_arrival_time);
                $id = (int) $row->structure_id;

                if (!isset($latest[$id]) || $at->getTimestamp() > $latest[$id]->getTimestamp()) {
                    $latest[$id] = $at;
                }
            }
        }

        return $latest;
    }

    protected function setting(string $key, $default)
    {
        return $this->settings->getSettingForCorporation('notifications.' . $key, null, $default);
    }
}
