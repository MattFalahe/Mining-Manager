<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MoonExtractionPlan;
use MiningManager\Models\MoonExtractionPlanAudit;
use MiningManager\Models\MoonRotation;
use MiningManager\Models\MoonRotationSlot;
use MiningManager\Models\RefineryAlert;

/**
 * Takes planned pulls off the calendar for refineries the corporation no
 * longer owns, but only once it is sure they are gone.
 *
 * A pull on a refinery you do not own cannot happen, so leaving it planned only
 * misleads. A refinery that is still there is never touched, even with its moon
 * drill unfitted or an unanchor running: both can change back, and the planner
 * marks them instead.
 *
 * A structure can also drop out of SeAT's list for an afternoon when ESI or the
 * server has a bad day, and a month of planning should not vanish because of
 * one bad sync. So a refinery has to be missing on three sightings at least
 * twelve hours apart, a day at the very least, and seen back in between starts
 * the count again.
 *
 * Covers every pull still ahead, blueprint or planned by hand. Blueprint slots
 * are left where they are: a pattern is built by hand, and clearing it is the
 * Blueprints tab's button.
 */
class MissingRefineryWatch
{
    /** Sightings a refinery has to be missing on before anything is removed. */
    public const SIGHTINGS_REQUIRED = 3;

    /** Hours that have to pass between two sightings for both to count. */
    public const HOURS_BETWEEN_SIGHTINGS = 12;

    /**
     * Game notifications that say why a structure left, in the words the
     * notification uses. The newest one found wins.
     */
    protected const REASONS = [
        'StructureDestroyed' => 'Destroyed',
        'StructureUnanchoring' => 'Unanchored',
        'OwnershipTransferred' => 'Handed over to another corporation',
    ];

    protected MoonPlannerService $planner;

    public function __construct(MoonPlannerService $planner)
    {
        $this->planner = $planner;
    }

    /**
     * One pass over every corporation that plans pulls or keeps blueprints.
     *
     * @return array<int, array> one entry per refinery whose pulls came off on
     *                           this pass, ready to hand to the notification
     */
    public function run(): array
    {
        $now = Carbon::now();
        $acted = [];

        foreach ($this->corporations($now) as $corporationId) {
            try {
                $acted = array_merge($acted, $this->runForCorporation($corporationId, $now));
            } catch (\Throwable $e) {
                Log::error("Mining Manager: missing refinery watch failed for corporation {$corporationId}: " . $e->getMessage());
            }
        }

        return $acted;
    }

    /**
     * @return array<int, array>
     */
    public function runForCorporation(int $corporationId, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        $owned = $this->planner->presentRefineryIds($corporationId);

        // No refineries at all is a gap in what SeAT has on file, not every rig
        // going at once. Nothing is counted either way, so an empty list can
        // never add a sighting.
        if (!$owned) {
            return [];
        }

        $missing = array_values(array_diff($this->referencedStructures($corporationId, $now), $owned));

        // Seen again: forget it, whatever had been counted.
        RefineryAlert::where('corporation_id', $corporationId)
            ->where('kind', RefineryAlert::KIND_MISSING)
            ->whereIn('structure_id', $owned)
            ->delete();

        // Nothing planned on it and no blueprint holding it any more, and it was
        // never acted on: there is nothing left to protect.
        RefineryAlert::where('corporation_id', $corporationId)
            ->where('kind', RefineryAlert::KIND_MISSING)
            ->whereNull('acted_at')
            ->when($missing, fn ($query) => $query->whereNotIn('structure_id', $missing))
            ->delete();

        $acted = [];

        foreach ($missing as $structureId) {
            $alert = RefineryAlert::firstOrNew([
                'corporation_id' => $corporationId,
                'structure_id' => $structureId,
                'kind' => RefineryAlert::KIND_MISSING,
            ]);

            if (!$alert->exists) {
                $alert->fill(['started_at' => $now, 'last_at' => $now, 'count' => 1])->save();
                continue;
            }

            // Already taken off the calendar; the row stays until it comes back,
            // so the same refinery is not reported twice.
            if ($alert->acted_at) {
                continue;
            }

            $hoursSinceLast = ($now->getTimestamp() - $alert->last_at->getTimestamp()) / 3600;
            if ($hoursSinceLast >= self::HOURS_BETWEEN_SIGHTINGS) {
                $alert->count = $alert->count + 1;
                $alert->last_at = $now;
                $alert->save();
            }

            if ($alert->count >= self::SIGHTINGS_REQUIRED) {
                $acted[] = $this->act($corporationId, $structureId, $alert, $now);
            }
        }

        return $acted;
    }

    /**
     * Corporations that plan pulls or keep blueprints.
     *
     * @return array<int, int>
     */
    protected function corporations(Carbon $now): array
    {
        return MoonExtractionPlan::query()
            ->active()
            ->where('planned_arrival_time', '>', $now)
            ->distinct()
            ->pluck('corporation_id')
            ->merge(MoonRotation::query()->distinct()->pluck('corporation_id'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Refineries a corporation still has pulls planned on, or keeps in a blueprint.
     *
     * @return array<int, int>
     */
    protected function referencedStructures(int $corporationId, Carbon $now): array
    {
        $planned = MoonExtractionPlan::forCorporation($corporationId)
            ->active()
            ->where('planned_arrival_time', '>', $now)
            ->distinct()
            ->pluck('structure_id');

        $rotationIds = MoonRotation::forCorporation($corporationId)->pluck('id')->all();
        $slotted = $rotationIds
            ? MoonRotationSlot::whereIn('rotation_id', $rotationIds)->distinct()->pluck('structure_id')
            : collect();

        return $planned->merge($slotted)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Take the refinery's pulls still ahead off the calendar and describe what
     * happened, for the notification.
     */
    protected function act(int $corporationId, int $structureId, RefineryAlert $alert, Carbon $now): array
    {
        $removed = 0;

        DB::transaction(function () use ($corporationId, $structureId, $alert, $now, &$removed) {
            $plans = MoonExtractionPlan::forCorporation($corporationId)
                ->where('structure_id', $structureId)
                ->active()
                ->where('planned_arrival_time', '>', $now)
                ->get();

            foreach ($plans as $plan) {
                // A pull already matched to a real extraction is history,
                // whatever happened to the structure afterwards.
                if ($plan->status !== MoonExtractionPlan::STATUS_PLANNED || $plan->linked_extraction_id) {
                    continue;
                }

                MoonExtractionPlanAudit::record([
                    'corporation_id' => $plan->corporation_id,
                    'plan_id' => $plan->id,
                    'structure_id' => $plan->structure_id,
                    'moon_id' => $plan->moon_id,
                    'action' => MoonExtractionPlanAudit::ACTION_DELETED,
                    'character_id' => null,
                    'character_name' => null,
                    'old_arrival' => $plan->planned_arrival_time,
                    'detail' => 'its refinery has been missing from the corporation for over a day',
                ]);
                $plan->delete();
                $removed++;
            }

            $alert->acted_at = $now;
            $alert->save();
        });

        $reason = $this->reasonFor($structureId, $alert->started_at);

        return $this->describe($structureId) + [
            'corporation_id' => $corporationId,
            'structure_id' => $structureId,
            'reason' => $reason ?? 'Missing from your corporation\'s structures; the game has not said why',
            'missing_since' => $alert->started_at->format('Y-m-d H:i'),
            'pulls_removed' => $removed,
            'blueprints' => $this->blueprintNames($corporationId, $structureId),
        ];
    }

    /**
     * Why the structure left, if the game told anyone in SeAT.
     */
    protected function reasonFor(int $structureId, Carbon $firstMissing): ?string
    {
        try {
            // Unanchoring runs for days before the structure is gone, so its
            // notice can be well before the first sighting.
            $query = DB::table('character_notifications')
                ->whereIn('type', array_keys(self::REASONS))
                ->where('timestamp', '>=', $firstMissing->copy()->subDays(14))
                ->orderByDesc('timestamp');

            $rows = StructureNotificationText::whereMayMention($query, [$structureId])->get(['type', 'text']);

            foreach ($rows as $row) {
                if (StructureNotificationText::mentions((string) $row->text, $structureId)) {
                    return self::REASONS[$row->type] ?? null;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Mining Manager: could not read why structure {$structureId} left: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Names for the message. SeAT keeps a structure in universe_structures
     * after it leaves the corporation's list, which is why it is read there.
     */
    protected function describe(int $structureId): array
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

    /**
     * The blueprints still holding this refinery, short enough for one field.
     */
    protected function blueprintNames(int $corporationId, int $structureId): string
    {
        $rotationIds = MoonRotationSlot::where('structure_id', $structureId)->pluck('rotation_id')->all();
        if (!$rotationIds) {
            return 'None';
        }

        $names = MoonRotation::forCorporation($corporationId)
            ->whereIn('id', $rotationIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if (!$names) {
            return 'None';
        }

        $list = implode(', ', $names);

        return strlen($list) > 1000 ? substr($list, 0, 990) . '... (' . count($names) . ' in all)' : $list;
    }
}
