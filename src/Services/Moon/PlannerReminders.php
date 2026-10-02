<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use MiningManager\Models\MoonExtractionPlan;
use MiningManager\Models\RefineryAlert;
use MiningManager\Services\Configuration\SettingsManagerService;

/**
 * The Moon Planner's reminders: things nobody has done yet that somebody
 * should.
 *
 * Settings are read without a corporation context. These run from scheduled
 * commands, where the settings service can be left pointing at whichever
 * corporation the run touched last.
 */
class PlannerReminders
{
    /** SeAT's name for the service a refinery needs to start an extraction. */
    protected const DRILL_SERVICE = 'Moon Drilling';

    protected MoonPlannerService $planner;
    protected SettingsManagerService $settings;

    public function __construct(MoonPlannerService $planner, SettingsManagerService $settings)
    {
        $this->planner = $planner;
        $this->settings = $settings;
    }

    /**
     * Refineries whose chunk arrived a while ago with nothing started since.
     *
     * Once the drill has been idle for the configured hours a reminder goes;
     * with repeats on, again every that many hours, until an extraction is
     * started, the refinery leaves the corporation, or its drill goes offline.
     * A refinery SeAT has no service data for keeps being reminded about: not
     * knowing is not a reason to go quiet.
     *
     * @return array<int, array> one entry per reminder to send on this pass
     */
    public function notRescheduled(int $corporationId, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $idleHours = max(1, (int) $this->setting('moon_not_rescheduled_hours', 48));
        $repeat = (bool) $this->setting('moon_not_rescheduled_repeat', true);

        $owned = $this->planner->refineriesForCorporation($corporationId)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$owned) {
            return [];
        }

        // Gone from the corporation: stop, and forget what was counted.
        RefineryAlert::where('corporation_id', $corporationId)
            ->where('kind', RefineryAlert::KIND_NOT_RESCHEDULED)
            ->whereNotIn('structure_id', $owned)
            ->delete();

        $running = MoonExtraction::whereIn('structure_id', $owned)
            ->where('status', 'extracting')
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $lastArrival = $this->lastArrivals($owned, $now);
        $drillDown = $this->drillsKnownOffline($owned);

        $reminders = [];

        foreach ($owned as $structureId) {
            $alert = RefineryAlert::firstOrNew([
                'corporation_id' => $corporationId,
                'structure_id' => $structureId,
                'kind' => RefineryAlert::KIND_NOT_RESCHEDULED,
            ]);

            $arrival = $lastArrival[$structureId] ?? null;

            // Started again, never pulled, or the drill cannot run: nothing to
            // say, and a later idle spell starts its count from scratch.
            if (in_array($structureId, $running, true) || !$arrival || in_array($structureId, $drillDown, true)) {
                if ($alert->exists) {
                    $alert->delete();
                }
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

            $due = $alert->count === 0
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

            $reminders[] = $this->names($structureId) + array_filter([
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

    /**
     * Refineries SeAT knows the services of and whose drill is not online.
     * One SeAT has no service rows for is not in this list.
     *
     * @return array<int, int>
     */
    protected function drillsKnownOffline(array $structureIds): array
    {
        $services = DB::table('corporation_structure_services')
            ->whereIn('structure_id', $structureIds)
            ->get(['structure_id', 'name', 'state']);

        $known = [];
        $online = [];

        foreach ($services as $service) {
            $id = (int) $service->structure_id;
            $known[$id] = true;

            if ($service->name === self::DRILL_SERVICE && $service->state === 'online') {
                $online[$id] = true;
            }
        }

        return array_values(array_map('intval', array_keys(array_diff_key($known, $online))));
    }

    /**
     * Refinery, system and moon names for a message.
     */
    protected function names(int $structureId): array
    {
        $row = DB::table('universe_structures')
            ->where('structure_id', $structureId)
            ->first(['name', 'solar_system_id']);

        $system = $row && $row->solar_system_id
            ? DB::table('solar_systems')->where('system_id', $row->solar_system_id)->value('name')
            : null;

        $moonId = $this->planner->resolveMoonId($structureId);
        $moon = $moonId ? DB::table('moons')->where('moon_id', $moonId)->value('name') : null;

        return array_filter([
            'structure_name' => $row->name ?? "Structure {$structureId}",
            'system_name' => $system,
            'moon_name' => $moon ?? ($moonId ? "Moon {$moonId}" : null),
        ], fn ($value) => $value !== null);
    }

    protected function setting(string $key, $default)
    {
        return $this->settings->getSettingForCorporation('notifications.' . $key, null, $default);
    }
}
