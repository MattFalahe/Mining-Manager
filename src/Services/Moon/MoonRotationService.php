<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MoonExtractionPlan;
use MiningManager\Models\MoonExtractionPlanAudit;
use MiningManager\Models\MoonRotation;
use MiningManager\Models\MoonRotationSlot;

/**
 * Turns a rotation into planned pulls, and carries an edit across the ones
 * that came from the same pattern.
 *
 * The rotation is intent. It says nothing about what a refinery can actually
 * do: chunk size and ore decide that in game, and the planner has never
 * controlled a structure. So a slot whose spacing disagrees with the moon's
 * observed cadence is reported, never refused.
 */
class MoonRotationService
{
    /**
     * A rotation may be laid down a year ahead at most. Far enough to plan a
     * quarter in one go, short of filling the table with intent nobody chose.
     */
    public const MAX_WEEKS_AHEAD = 52;

    /**
     * Two pulls for one refinery closer together than this are the same pull,
     * so the second is not written again. Matches the planner's own tolerance
     * for calling a real extraction the one a plan meant.
     */
    public const DUPLICATE_TOLERANCE_MINUTES = 30;

    protected MoonPlannerService $planner;

    public function __construct(MoonPlannerService $planner)
    {
        $this->planner = $planner;
    }

    /**
     * The corporation's blueprints, shaped for anything that has to offer a
     * choice of them: the planner's apply dialog and the blueprints page both
     * read this, so they cannot describe the same blueprint differently.
     */
    public function listForCorporation(int $corporationId): array
    {
        return MoonRotation::forCorporation($corporationId)
            ->withCount('slots')
            ->orderBy('name')
            ->get()
            ->map(function (MoonRotation $rotation) {
                return [
                    'id' => (int) $rotation->id,
                    'name' => $rotation->name,
                    'weeks' => (int) $rotation->weeks,
                    'slot_count' => (int) $rotation->slots_count,
                    'planned_ahead' => MoonExtractionPlan::where('rotation_id', $rotation->id)
                        ->active()
                        ->where('planned_arrival_time', '>', Carbon::now())
                        ->count(),
                ];
            })
            ->all();
    }

    /**
     * How many cycles of this rotation fit inside the year-ahead cap.
     */
    public function maxCycles(MoonRotation $rotation): int
    {
        $weeks = max(1, (int) $rotation->weeks);

        return max(1, (int) floor(self::MAX_WEEKS_AHEAD / $weeks));
    }

    /**
     * What applying this rotation would write, without writing it.
     *
     * Every occurrence is returned, including the ones that would be skipped,
     * so the operator sees the whole picture before committing: which pulls
     * land, which are already planned, and which sit within the minimum gap of
     * another moon.
     *
     * @return array{rows: array<int, array>, summary: array{plan:int,skip:int,clash:int}}
     */
    public function preview(MoonRotation $rotation, Carbon $startDate, int $cycles): array
    {
        $cycles = max(1, min($cycles, $this->maxCycles($rotation)));
        $slots = $rotation->slots()->get();
        $now = Carbon::now();

        // Monday of the week the operator started from. Slots keep their
        // weekday, so a rotation started mid-week begins with whatever is left
        // of that week rather than shifting the whole pattern.
        $anchor = $startDate->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        // A refinery that has been unanchored or blown up is gone from the
        // corporation's structures, and nothing can pull from it.
        $owned = $this->planner->refineriesForCorporation((int) $rotation->corporation_id)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rows = [];
        $placed = [];  // structure_id => Carbon[] of times placed in this preview

        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            foreach ($slots as $slot) {
                $weekOffset = ($cycle * max(1, (int) $rotation->weeks)) + (max(1, (int) $slot->week_number) - 1);

                $arrival = $anchor->copy()
                    ->addWeeks($weekOffset)
                    ->addDays(max(1, min(7, (int) $slot->day_of_week)) - 1);

                [$hour, $minute] = $this->timeParts($slot->time_of_day);
                $arrival->setTime($hour, $minute);

                $structureId = (int) $slot->structure_id;
                $skip = null;

                if (!in_array($structureId, $owned, true)) {
                    $skip = 'that refinery is gone';
                } elseif ($arrival->lt($startDate)) {
                    $skip = 'before the start date';
                } elseif ($arrival->lt($now)) {
                    $skip = 'in the past';
                } elseif ($this->alreadyPlanned($structureId, $arrival, $placed[$structureId] ?? [])) {
                    $skip = 'already planned';
                }

                $clashes = $skip ? [] : $this->planner->detectConflicts(
                    (int) $rotation->corporation_id,
                    $arrival,
                    null,
                    $structureId
                );

                if (!$skip) {
                    $placed[$structureId][] = $arrival->copy();
                }

                $rows[] = [
                    'cycle' => $cycle + 1,
                    'slot_id' => (int) $slot->id,
                    'structure_id' => $structureId,
                    'moon_id' => $slot->moon_id ? (int) $slot->moon_id : null,
                    'arrival' => $arrival,
                    'skip' => $skip,
                    'clashes' => count($clashes),
                ];
            }
        }

        usort($rows, fn ($a, $b) => $a['arrival'] <=> $b['arrival']);

        return [
            'rows' => $rows,
            'summary' => [
                'plan' => count(array_filter($rows, fn ($r) => $r['skip'] === null)),
                'skip' => count(array_filter($rows, fn ($r) => $r['skip'] !== null)),
                'clash' => count(array_filter($rows, fn ($r) => $r['skip'] === null && $r['clashes'] > 0)),
            ],
        ];
    }

    /**
     * Write the pulls a preview described. Skipped rows stay skipped; a row
     * that clashes with another moon is still written, because the gap is a
     * warning the operator has already seen, not a rule.
     *
     * @return array{created:int,skipped:int}
     */
    public function apply(MoonRotation $rotation, Carbon $startDate, int $cycles, ?int $createdBy = null, ?string $actorName = null): array
    {
        $preview = $this->preview($rotation, $startDate, $cycles);
        $created = 0;

        foreach ($preview['rows'] as $row) {
            if ($row['skip'] !== null) {
                continue;
            }

            $moonId = $row['moon_id'] ?? $this->planner->resolveMoonId($row['structure_id']);

            $plan = MoonExtractionPlan::create([
                'corporation_id' => (int) $rotation->corporation_id,
                'structure_id' => $row['structure_id'],
                'moon_id' => $moonId ? (int) $moonId : null,
                'planned_arrival_time' => $row['arrival'],
                'source' => MoonExtractionPlan::SOURCE_ROTATION,
                'status' => MoonExtractionPlan::STATUS_PLANNED,
                'rotation_id' => (int) $rotation->id,
                'rotation_slot_id' => $row['slot_id'],
                'rotation_cycle' => $row['cycle'],
                'created_by' => $createdBy,
            ]);

            MoonExtractionPlanAudit::record([
                'corporation_id' => (int) $rotation->corporation_id,
                'plan_id' => $plan->id,
                'structure_id' => $plan->structure_id,
                'moon_id' => $plan->moon_id,
                'action' => MoonExtractionPlanAudit::ACTION_ROTATION,
                'character_id' => $createdBy,
                'character_name' => $actorName,
                'new_arrival' => $row['arrival'],
                'detail' => $rotation->name . ', cycle ' . $row['cycle'],
            ]);

            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $preview['summary']['skip'],
        ];
    }

    /**
     * Bring the pulls a blueprint already wrote back in line with it.
     *
     * Only what has not happened yet: a pull in the past is a record, and one
     * already matched to a real extraction is what the drill is actually
     * doing, so neither moves. Everything still ahead follows the pattern as
     * it now reads, including slots added to it and slots taken out of it.
     *
     * The dates are rebuilt from the same anchor the pulls were written from,
     * worked out backwards from what each one was before the edit. That is why
     * the caller hands over the slots as they were: after the save they are
     * gone, and without them there is no way to tell where the pattern started.
     *
     * @param  array<int, object> $oldSlots slot rows as they were, keyed by id
     * @return array{moved:int,added:int,removed:int,kept:int}
     */
    public function resync(
        MoonRotation $rotation,
        array $oldSlots,
        int $oldWeeks,
        ?int $actorId = null,
        ?string $actorName = null
    ): array {
        $summary = ['moved' => 0, 'added' => 0, 'removed' => 0, 'kept' => 0];

        $now = Carbon::now();
        $newWeeks = max(1, (int) $rotation->weeks);
        $newSlots = $rotation->slots()->get()->keyBy('id');

        $future = MoonExtractionPlan::where('rotation_id', $rotation->id)
            ->active()
            ->where('planned_arrival_time', '>', $now)
            ->get();

        if ($future->isEmpty()) {
            return $summary;
        }

        $anchor = $this->deriveAnchor($future, $oldSlots, max(1, $oldWeeks));
        if (!$anchor) {
            return $summary;
        }

        // Rebuild only the cycles that are already on the calendar. Applying a
        // blueprint says how far ahead to plan; editing one does not extend it.
        $cycles = array_values(array_unique(array_filter(
            $future->pluck('rotation_cycle')->map(fn ($c) => (int) $c)->all()
        )));
        sort($cycles);

        $expected = [];
        foreach ($cycles as $cycle) {
            foreach ($newSlots as $slotId => $slot) {
                $expected[$cycle . ':' . $slotId] = $this->slotTime($anchor, $cycle, $slot, $newWeeks);
            }
        }

        DB::transaction(function () use ($rotation, $future, $newSlots, $expected, $now, $actorId, $actorName, &$summary) {
            foreach ($future as $plan) {
                $key = ((int) $plan->rotation_cycle) . ':' . ((int) $plan->rotation_slot_id);

                // Already matched to a real extraction: that is what is
                // happening, whatever the pattern now says.
                if ($plan->status !== MoonExtractionPlan::STATUS_PLANNED || $plan->linked_extraction_id) {
                    unset($expected[$key]);
                    $summary['kept']++;
                    continue;
                }

                if (!$newSlots->has((int) $plan->rotation_slot_id)) {
                    $this->audit($plan, MoonExtractionPlanAudit::ACTION_DELETED, $actorId, $actorName, [
                        'old_arrival' => $plan->planned_arrival_time,
                        'detail' => 'its pull was taken out of the blueprint',
                    ]);
                    $plan->delete();
                    $summary['removed']++;
                    continue;
                }

                $want = $expected[$key] ?? null;
                unset($expected[$key]);

                if (!$want || $want->lt($now)) {
                    // The pattern now puts this one in the past. Leave it
                    // where it is rather than planning something behind us.
                    $summary['kept']++;
                    continue;
                }

                if ($want->format('Y-m-d H:i') === $plan->planned_arrival_time->format('Y-m-d H:i')) {
                    $summary['kept']++;
                    continue;
                }

                $from = $plan->planned_arrival_time->copy();
                $plan->update(['planned_arrival_time' => $want]);

                $this->audit($plan, MoonExtractionPlanAudit::ACTION_MOVED, $actorId, $actorName, [
                    'old_arrival' => $from,
                    'new_arrival' => $want,
                    'detail' => 'followed a change to the blueprint',
                ]);
                $summary['moved']++;
            }

            // Whatever the pattern gained: slots with no pull on the calendar.
            foreach ($expected as $key => $when) {
                if ($when->lt($now)) {
                    continue;
                }

                [$cycle, $slotId] = array_map('intval', explode(':', $key));
                $slot = $newSlots->get($slotId);

                $plan = MoonExtractionPlan::create([
                    'corporation_id' => (int) $rotation->corporation_id,
                    'structure_id' => (int) $slot->structure_id,
                    'moon_id' => $slot->moon_id ? (int) $slot->moon_id : $this->planner->resolveMoonId((int) $slot->structure_id),
                    'planned_arrival_time' => $when,
                    'source' => MoonExtractionPlan::SOURCE_ROTATION,
                    'status' => MoonExtractionPlan::STATUS_PLANNED,
                    'rotation_id' => (int) $rotation->id,
                    'rotation_slot_id' => $slotId,
                    'rotation_cycle' => $cycle,
                    'created_by' => $actorId,
                ]);

                $this->audit($plan, MoonExtractionPlanAudit::ACTION_ROTATION, $actorId, $actorName, [
                    'new_arrival' => $when,
                    'detail' => 'added to the blueprint, cycle ' . $cycle,
                ]);
                $summary['added']++;
            }
        });

        return $summary;
    }

    /**
     * Take the refineries a corporation no longer owns out of its blueprints,
     * along with the pulls they had planned ahead.
     *
     * Deliberately not automatic on every page load. A structure missing from
     * SeAT for a moment during an ESI wobble would otherwise quietly delete a
     * pattern somebody spent time building, and a blueprint is cheap to keep
     * and expensive to rebuild. The page flags them and this runs when asked.
     *
     * @return array{slots:int,plans:int}
     */
    public function pruneMissingRefineries(int $corporationId, ?int $actorId = null, ?string $actorName = null): array
    {
        $owned = $this->planner->refineriesForCorporation($corporationId)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rotationIds = MoonRotation::forCorporation($corporationId)->pluck('id')->all();
        if (!$rotationIds) {
            return ['slots' => 0, 'plans' => 0];
        }

        $gone = MoonRotationSlot::whereIn('rotation_id', $rotationIds)
            ->when($owned, fn ($query) => $query->whereNotIn('structure_id', $owned))
            ->get();

        if ($gone->isEmpty()) {
            return ['slots' => 0, 'plans' => 0];
        }

        $plans = 0;

        DB::transaction(function () use ($gone, $actorId, $actorName, &$plans) {
            foreach ($gone as $slot) {
                $planned = MoonExtractionPlan::where('rotation_slot_id', $slot->id)
                    ->active()
                    ->where('planned_arrival_time', '>', Carbon::now())
                    ->get();

                foreach ($planned as $plan) {
                    // A pull already matched to a real extraction is history,
                    // whatever happened to the structure afterwards.
                    if ($plan->status !== MoonExtractionPlan::STATUS_PLANNED || $plan->linked_extraction_id) {
                        continue;
                    }

                    $this->audit($plan, MoonExtractionPlanAudit::ACTION_DELETED, $actorId, $actorName, [
                        'old_arrival' => $plan->planned_arrival_time,
                        'detail' => 'its refinery is no longer owned',
                    ]);
                    $plan->delete();
                    $plans++;
                }

                $slot->delete();
            }
        });

        return ['slots' => $gone->count(), 'plans' => $plans];
    }

    /**
     * Where the pattern started, read back from the pulls it wrote.
     *
     * Each pull knows its cycle and which slot made it, so subtracting that
     * slot's offset gives the week the blueprint was applied from. Taking the
     * commonest answer rather than the first means a pull somebody dragged by
     * hand cannot drag the whole pattern with it.
     */
    protected function deriveAnchor($plans, array $oldSlots, int $oldWeeks): ?Carbon
    {
        $votes = [];

        foreach ($plans as $plan) {
            $slot = $oldSlots[(int) $plan->rotation_slot_id] ?? null;
            if (!$slot || !$plan->rotation_cycle) {
                continue;
            }

            $weekOffset = ((int) $plan->rotation_cycle - 1) * $oldWeeks + (max(1, (int) $slot->week_number) - 1);

            $candidate = $plan->planned_arrival_time->copy()
                ->subWeeks($weekOffset)
                ->subDays(max(1, min(7, (int) $slot->day_of_week)) - 1)
                ->startOfDay();

            $key = $candidate->format('Y-m-d');
            $votes[$key] = ($votes[$key] ?? 0) + 1;
        }

        if (!$votes) {
            return null;
        }

        arsort($votes);
        $winner = array_key_first($votes);

        return Carbon::parse($winner . ' 00:00:00');
    }

    /**
     * When a slot lands in a given cycle, counted from the anchor.
     */
    protected function slotTime(Carbon $anchor, int $cycle, $slot, int $weeks): Carbon
    {
        $weekOffset = ($cycle - 1) * $weeks + (max(1, (int) $slot->week_number) - 1);

        $when = $anchor->copy()
            ->addWeeks($weekOffset)
            ->addDays(max(1, min(7, (int) $slot->day_of_week)) - 1);

        [$hour, $minute] = $this->timeParts($slot->time_of_day);
        $when->setTime($hour, $minute);

        return $when;
    }

    protected function audit(MoonExtractionPlan $plan, string $action, ?int $actorId, ?string $actorName, array $extra): void
    {
        MoonExtractionPlanAudit::record([
            'corporation_id' => $plan->corporation_id,
            'plan_id' => $plan->id,
            'structure_id' => $plan->structure_id,
            'moon_id' => $plan->moon_id,
            'action' => $action,
            'character_id' => $actorId,
            'character_name' => $actorName,
        ] + $extra);
    }

    /**
     * The later pulls an edit to this one could carry to: same refinery, same
     * rotation, still ahead of it. A moon can sit in a rotation twice, on two
     * different weekdays, and both are this moon's later pulls.
     */
    public function laterInSeries(MoonExtractionPlan $plan)
    {
        if (!$plan->rotation_id) {
            return collect();
        }

        return MoonExtractionPlan::query()
            ->active()
            ->where('rotation_id', $plan->rotation_id)
            ->where('structure_id', $plan->structure_id)
            ->where('planned_arrival_time', '>', $plan->planned_arrival_time)
            ->where('id', '!=', $plan->id)
            ->orderBy('planned_arrival_time')
            ->get();
    }

    /**
     * Move every later pull in the series by the same amount this one moved.
     *
     * By the same amount, not to the same time: moving Tuesday's pull two days
     * later means the rest of the rotation slides two days, which is what
     * moving a rotation means. Setting them all to one time would collapse the
     * pattern into a single date.
     *
     * @return int how many were moved
     */
    public function shiftLater(MoonExtractionPlan $plan, int $minutes, ?int $actorId = null, ?string $actorName = null): int
    {
        if ($minutes === 0) {
            return 0;
        }

        $moved = 0;

        DB::transaction(function () use ($plan, $minutes, $actorId, $actorName, &$moved) {
            foreach ($this->laterInSeries($plan) as $later) {
                $from = $later->planned_arrival_time->copy();
                $to = $from->copy()->addMinutes($minutes);

                $later->update(['planned_arrival_time' => $to]);

                MoonExtractionPlanAudit::record([
                    'corporation_id' => $later->corporation_id,
                    'plan_id' => $later->id,
                    'structure_id' => $later->structure_id,
                    'moon_id' => $later->moon_id,
                    'action' => MoonExtractionPlanAudit::ACTION_MOVED,
                    'character_id' => $actorId,
                    'character_name' => $actorName,
                    'old_arrival' => $from,
                    'new_arrival' => $to,
                    'detail' => 'carried from an earlier pull in the rotation',
                ]);

                $moved++;
            }
        });

        return $moved;
    }

    /**
     * Remove every later pull in the series.
     *
     * @return int how many were removed
     */
    public function deleteLater(MoonExtractionPlan $plan, ?int $actorId = null, ?string $actorName = null): int
    {
        $removed = 0;

        DB::transaction(function () use ($plan, $actorId, $actorName, &$removed) {
            foreach ($this->laterInSeries($plan) as $later) {
                MoonExtractionPlanAudit::record([
                    'corporation_id' => $later->corporation_id,
                    'plan_id' => $later->id,
                    'structure_id' => $later->structure_id,
                    'moon_id' => $later->moon_id,
                    'action' => MoonExtractionPlanAudit::ACTION_DELETED,
                    'character_id' => $actorId,
                    'character_name' => $actorName,
                    'old_arrival' => $later->planned_arrival_time,
                    'detail' => 'removed with an earlier pull in the rotation',
                ]);

                $later->delete();
                $removed++;
            }
        });

        return $removed;
    }

    /**
     * Is this refinery already planned at this moment, in the database or
     * earlier in the same run?
     *
     * @param  Carbon[] $pending times placed in this preview
     */
    protected function alreadyPlanned(int $structureId, Carbon $arrival, array $pending): bool
    {
        foreach ($pending as $time) {
            if (abs($time->diffInMinutes($arrival)) <= self::DUPLICATE_TOLERANCE_MINUTES) {
                return true;
            }
        }

        return MoonExtractionPlan::where('structure_id', $structureId)
            ->active()
            ->whereBetween('planned_arrival_time', [
                $arrival->copy()->subMinutes(self::DUPLICATE_TOLERANCE_MINUTES),
                $arrival->copy()->addMinutes(self::DUPLICATE_TOLERANCE_MINUTES),
            ])
            ->exists();
    }

    /**
     * @return array{0:int,1:int} hour and minute of a stored time_of_day
     */
    protected function timeParts($timeOfDay): array
    {
        $text = $timeOfDay instanceof \DateTimeInterface
            ? $timeOfDay->format('H:i')
            : (string) $timeOfDay;

        $parts = explode(':', $text);

        return [
            max(0, min(23, (int) ($parts[0] ?? 0))),
            max(0, min(59, (int) ($parts[1] ?? 0))),
        ];
    }
}
