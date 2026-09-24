<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MoonExtractionPlan;
use MiningManager\Models\MoonExtractionPlanAudit;
use MiningManager\Models\MoonRotation;

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

                if ($arrival->lt($startDate)) {
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
