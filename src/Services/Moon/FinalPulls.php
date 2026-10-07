<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionPlan;
use MiningManager\Models\MoonExtractionPlanAudit;
use MiningManager\Models\RefineryAlert;

/**
 * Refineries whose last pull is planned, for a relocation or an unanchor.
 *
 * The final pull is marked on the planner, and only there, by whoever runs
 * it. Nothing can be planned after it, and the planner stops asking anyone to
 * plan or restart the refinery: Moons Need Planning and Moon Not Rescheduled
 * leave it out, and when its last chunk arrives the planner says that was the
 * last one instead of announcing a next pull. The chunk itself is reported
 * like any other.
 *
 * None of this shows on a page members see. Where a refinery goes next is the
 * corporation's business.
 */
class FinalPulls
{
    /** Extractions still going: on the way, arrived, or being mined. */
    protected const LIVE_STATUSES = ['extracting', 'ready', 'fractured'];

    /**
     * Each of these refineries' final pull, keyed by structure id.
     *
     * Its time is its extraction's arrival once one is linked, the planned
     * time until then. "restarted" is the arrival of an extraction somebody
     * started after the final pull had arrived.
     *
     * @param array<int, int> $structureIds
     * @return array<int, array{plan_id: int, time: Carbon, arrived: bool, restarted: ?Carbon, marked_by: ?int, marked_at: ?Carbon}>
     */
    public function forStructures(array $structureIds, ?Carbon $now = null): array
    {
        $structureIds = array_values(array_unique(array_filter(array_map('intval', $structureIds))));
        if (!$structureIds) {
            return [];
        }

        $now = $now ?? Carbon::now();

        $plans = MoonExtractionPlan::whereIn('structure_id', $structureIds)
            ->final()
            ->orderBy('planned_arrival_time')
            ->get();

        if ($plans->isEmpty()) {
            return [];
        }

        $linked = $plans->pluck('linked_extraction_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $arrivals = $linked ? MoonExtraction::whereIn('id', $linked)->pluck('chunk_arrival_time', 'id')->all() : [];

        $finals = [];
        foreach ($plans as $plan) {
            $structureId = (int) $plan->structure_id;
            if (isset($finals[$structureId])) {
                continue;
            }

            $arrival = $arrivals[(int) $plan->linked_extraction_id] ?? null;
            $time = $arrival ? Carbon::parse($arrival) : $plan->planned_arrival_time->copy();

            $finals[$structureId] = [
                'plan_id' => (int) $plan->id,
                'time' => $time,
                'arrived' => $time->lte($now),
                'restarted' => null,
                'marked_by' => $plan->final_marked_by,
                'marked_at' => $plan->final_marked_at,
            ];
        }

        foreach (MoonExtraction::whereIn('structure_id', array_keys($finals))
            ->whereIn('status', self::LIVE_STATUSES)
            ->get() as $extraction) {
            $structureId = (int) $extraction->structure_id;
            $final = $finals[$structureId];

            if ($final['arrived'] && $extraction->chunk_arrival_time
                && $this->roleOf($extraction->chunk_arrival_time, $final['time']) === 'after'
                && (!$final['restarted'] || $extraction->chunk_arrival_time->gt($final['restarted']))) {
                $finals[$structureId]['restarted'] = $extraction->chunk_arrival_time->copy();
            }
        }

        return $finals;
    }

    /**
     * Where an arrival on this refinery stands against its final pull:
     * "final" when it is the final pull, "after" when it came later, "before"
     * when the final pull is still to come, null when there is none.
     */
    public function arrivalRole(int $structureId, Carbon $arrival): ?string
    {
        $final = $this->forStructures([$structureId])[$structureId] ?? null;

        return $final ? $this->roleOf($arrival, $final['time']) : null;
    }

    /**
     * Planned pulls on the refinery after this time, which a final pull at
     * this time would leave nothing to do.
     */
    public function laterPulls(int $structureId, Carbon $after, ?int $exceptPlanId = null): Collection
    {
        return MoonExtractionPlan::where('structure_id', $structureId)
            ->where('status', MoonExtractionPlan::STATUS_PLANNED)
            ->where('planned_arrival_time', '>', $after)
            ->when($exceptPlanId, fn ($query) => $query->where('id', '!=', $exceptPlanId))
            ->orderBy('planned_arrival_time')
            ->get();
    }

    /**
     * Whether an extraction set in game comes after this time. A pull before
     * it cannot be the last one.
     */
    public function runningAfter(int $structureId, Carbon $time): bool
    {
        foreach (MoonExtraction::where('structure_id', $structureId)
            ->whereIn('status', self::LIVE_STATUSES)
            ->get() as $extraction) {
            if ($extraction->chunk_arrival_time && $this->roleOf($extraction->chunk_arrival_time, $time) === 'after') {
                return true;
            }
        }

        return false;
    }

    /**
     * The planned pull an extraction set in game belongs to, or a new one
     * recording it, so marking it final works the same as marking a plan.
     */
    public function planForExtraction(MoonExtraction $extraction, int $corporationId, ?int $actorId = null): MoonExtractionPlan
    {
        $arrival = $extraction->chunk_arrival_time;

        $existing = MoonExtractionPlan::where('structure_id', $extraction->structure_id)
            ->whereIn('status', MoonExtractionPlan::FINAL_STATUSES)
            ->get()
            ->filter(fn ($plan) => (int) $plan->linked_extraction_id === (int) $extraction->id
                || $this->roleOf($plan->planned_arrival_time, $arrival) === 'final')
            ->sortBy(fn ($plan) => abs($plan->planned_arrival_time->getTimestamp() - $arrival->getTimestamp()))
            ->first();

        if ($existing) {
            return $existing;
        }

        return MoonExtractionPlan::create([
            'corporation_id' => $corporationId,
            'structure_id' => (int) $extraction->structure_id,
            'moon_id' => $extraction->moon_id ? (int) $extraction->moon_id : null,
            'planned_arrival_time' => $arrival,
            'source' => MoonExtractionPlan::SOURCE_MANUAL,
            'status' => MoonExtractionPlan::STATUS_CONFIRMED,
            'linked_extraction_id' => (int) $extraction->id,
            'variance_hours' => 0,
            'created_by' => $actorId,
        ]);
    }

    /**
     * Mark a pull as its refinery's last. The pulls planned after it go, and
     * so does a final mark on any other pull of the refinery. Returns how many
     * pulls were taken off.
     */
    public function mark(MoonExtractionPlan $plan, ?int $actorId = null, ?string $actorName = null): int
    {
        $removed = 0;

        DB::transaction(function () use ($plan, $actorId, $actorName, &$removed) {
            foreach ($this->laterPulls((int) $plan->structure_id, $plan->planned_arrival_time, (int) $plan->id) as $later) {
                MoonExtractionPlanAudit::record([
                    'corporation_id' => $plan->corporation_id,
                    'plan_id' => $later->id,
                    'structure_id' => $later->structure_id,
                    'moon_id' => $later->moon_id,
                    'action' => MoonExtractionPlanAudit::ACTION_DELETED,
                    'character_id' => $actorId,
                    'character_name' => $actorName,
                    'old_arrival' => $later->planned_arrival_time,
                    'detail' => 'planned after the final pull',
                ]);
                $later->delete();
                $removed++;
            }

            MoonExtractionPlan::where('structure_id', $plan->structure_id)
                ->where('id', '!=', $plan->id)
                ->where('is_final', true)
                ->update(['is_final' => false]);

            $plan->update([
                'is_final' => true,
                'final_marked_by' => $actorId,
                'final_marked_at' => Carbon::now(),
            ]);

            MoonExtractionPlanAudit::record([
                'corporation_id' => $plan->corporation_id,
                'plan_id' => $plan->id,
                'structure_id' => $plan->structure_id,
                'moon_id' => $plan->moon_id,
                'action' => MoonExtractionPlanAudit::ACTION_MARKED_FINAL,
                'character_id' => $actorId,
                'character_name' => $actorName,
                'new_arrival' => $plan->planned_arrival_time,
                'detail' => $removed > 0 ? "{$removed} pull(s) planned after it removed" : null,
            ]);
        });

        return $removed;
    }

    /**
     * The refinery carries on after all: its final mark is cleared, so it can
     * be planned again and the planner reminds about it again. Pulls removed
     * when it was marked are not brought back.
     */
    public function resume(int $corporationId, int $structureId, ?int $actorId = null, ?string $actorName = null): bool
    {
        $plan = MoonExtractionPlan::where('corporation_id', $corporationId)
            ->where('structure_id', $structureId)
            ->where('is_final', true)
            ->orderByDesc('planned_arrival_time')
            ->first();

        if (!$plan) {
            return false;
        }

        MoonExtractionPlan::where('corporation_id', $corporationId)
            ->where('structure_id', $structureId)
            ->where('is_final', true)
            ->update(['is_final' => false]);

        RefineryAlert::where('corporation_id', $corporationId)
            ->where('structure_id', $structureId)
            ->where('kind', RefineryAlert::KIND_FINAL_RESTARTED)
            ->delete();

        MoonExtractionPlanAudit::record([
            'corporation_id' => $corporationId,
            'plan_id' => $plan->id,
            'structure_id' => $structureId,
            'moon_id' => $plan->moon_id,
            'action' => MoonExtractionPlanAudit::ACTION_RESUMED,
            'character_id' => $actorId,
            'character_name' => $actorName,
            'old_arrival' => $plan->planned_arrival_time,
        ]);

        return true;
    }

    /**
     * The same cycle as the final pull is the planner's own idea of the same
     * pull: within its match window either way.
     */
    protected function roleOf(Carbon $arrival, Carbon $finalTime): string
    {
        $window = MoonPlannerService::CYCLE_MATCH_WINDOW_HOURS * 3600;
        $gap = $arrival->getTimestamp() - $finalTime->getTimestamp();

        if (abs($gap) <= $window) {
            return 'final';
        }

        return $gap > 0 ? 'after' : 'before';
    }
}
