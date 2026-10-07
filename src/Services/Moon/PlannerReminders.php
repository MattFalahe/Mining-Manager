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
 * Neither reminder speaks about a refinery that cannot pull:
 *   - one the corporation no longer has, or with no moon drill fitted, both
 *     already left out by RefineryService::refineriesForCorporation()
 *   - one the game has reported destroyed, before SeAT's list catches up
 *   - one being unanchored with no extraction running. Unanchoring takes
 *     seven days, too short for a new pull to arrive and be mined before the
 *     structure goes. While an extraction is still running, it is treated
 *     like any other refinery.
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
     * started.
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
            // either, so the count is kept for when it can.
            if (in_array($structureId, $drillDown, true)
                || in_array($structureId, $destroyed, true)
                || in_array($structureId, $unanchoring, true)) {
                continue;
            }

            $idleFor = ($now->getTimestamp() - $arrival->getTimestamp()) / 3600;
            if ($idleFor < $idleHours) {
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

            $alert->started_at = $arrival;
            $alert->last_at = $now;
            $alert->count = $alert->count + 1;
            $alert->save();

            $next = MoonExtractionPlan::where('structure_id', $structureId)
                ->active()
                ->where('planned_arrival_time', '>', $now)
                ->orderBy('planned_arrival_time')
                ->value('planned_arrival_time');

            $reminders[] = $this->refineries->names($structureId) + array_filter([
                'structure_id' => $structureId,
                'arrived_at' => $arrival->format('Y-m-d H:i'),
                'hours_since' => (int) floor($idleFor),
                'next_planned' => $next ? Carbon::parse($next)->format('Y-m-d H:i') : null,
                'reminder_number' => $alert->count,
            ], fn ($value) => $value !== null);
        }

        return $reminders;
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
        $short = [];

        foreach ($owned as $structureId) {
            if (in_array($structureId, $destroyed, true) || in_array($structureId, $unanchoring, true)) {
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
     * The latest chunk that has actually arrived on each refinery, live or
     * archived. A cancelled extraction never arrived, so it does not count.
     *
     * @return array<int, Carbon>
     */
    protected function lastArrivals(array $structureIds, Carbon $now): array
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
