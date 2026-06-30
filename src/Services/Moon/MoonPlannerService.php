<?php

namespace MiningManager\Services\Moon;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use MiningManager\Models\MoonExtractionPlan;
use MiningManager\Services\Configuration\SettingsManagerService;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use Carbon\Carbon;

/**
 * MoonPlannerService — the brain behind the Moon Extraction Planner.
 *
 * Responsibilities:
 *
 *   - cadence(): derive a refinery's natural pull rhythm from its arrival
 *     history (needs >= 2 arrivals = >= 1 interval).
 *   - projectNextArrival(): chain the next projected pull off the latest
 *     anchor (last actual arrival OR last active plan — whichever is later,
 *     so a manual re-anchor sticks going forward).
 *   - detectConflicts(): the <24h guard. Returns every other planned/actual
 *     arrival within the configured gap window so the UI can warn + require
 *     confirmation, and the save endpoint can refuse unconfirmed clashes.
 *   - autoFill(): project every refinery's next slot for a month and, on
 *     request, greedily spread them so none violate the gap.
 *   - reconcile(): pair planned slots with real ESI extractions once they
 *     appear, recording variance.
 *
 * Refineries = Athanor (35835) + Tatara (35836). Metenox (81826) drills are
 * deliberately excluded — they accumulate continuously and have no chunk
 * cadence to plan around.
 */
class MoonPlannerService
{
    /** Athanor + Tatara — the only structures that run plannable chunk extractions. */
    public const REFINERY_TYPE_IDS = [35835, 35836];

    protected SettingsManagerService $settings;

    public function __construct(SettingsManagerService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * The minimum acceptable gap (hours) between two chunk arrivals before
     * the planner warns. Configurable in Settings; small one-rig corps may
     * want 36, large groups 12. Default 24.
     */
    public function getMinGapHours(): int
    {
        // Stored under notifications.* so it saves through the existing
        // Notification settings handler (which namespaces keys there).
        return (int) $this->settings->getSetting('notifications.min_extraction_gap_hours', 24);
    }

    /**
     * Every refinery (Athanor/Tatara) belonging to a corporation.
     *
     * @return \Illuminate\Support\Collection<int,CorporationStructure>
     */
    public function refineriesForCorporation(int $corporationId): Collection
    {
        return CorporationStructure::whereIn('type_id', self::REFINERY_TYPE_IDS)
            ->where('corporation_id', $corporationId)
            ->get();
    }

    /**
     * All known chunk-arrival timestamps for a structure, oldest → newest,
     * deduped. Combines archived history with live extractions so the
     * cadence reflects the most recent pulls even before they're archived.
     *
     * @return \Illuminate\Support\Collection<int,Carbon>
     */
    public function arrivalHistory(int $structureId): Collection
    {
        $historical = MoonExtractionHistory::where('structure_id', $structureId)
            ->whereNotNull('chunk_arrival_time')
            ->pluck('chunk_arrival_time');

        $live = MoonExtraction::where('structure_id', $structureId)
            ->whereNotNull('chunk_arrival_time')
            ->whereNotIn('status', ['cancelled'])
            ->pluck('chunk_arrival_time');

        return $historical->merge($live)
            ->map(fn ($t) => $t instanceof Carbon ? $t : Carbon::parse($t))
            // Dedup to the minute — history + live can hold the same arrival.
            ->unique(fn (Carbon $t) => $t->format('Y-m-d H:i'))
            ->sortBy(fn (Carbon $t) => $t->getTimestamp())
            ->values();
    }

    /**
     * Derive cadence for a structure from its arrival history.
     *
     * Returns:
     *   cadence_days   int|null  — null when < 2 arrivals (can't form an interval)
     *   arrival_count  int
     *   last_arrival   Carbon|null
     *   intervals      int       — number of intervals the cadence was drawn from
     *
     * With exactly 2 arrivals we use the single interval. With >= 3 we use
     * the MEDIAN interval (robust against one freak long/short cycle).
     */
    public function cadence(int $structureId): array
    {
        $arrivals = $this->arrivalHistory($structureId);
        $count = $arrivals->count();

        $result = [
            'cadence_days' => null,
            'arrival_count' => $count,
            'last_arrival' => $count > 0 ? $arrivals->last() : null,
            'intervals' => 0,
        ];

        if ($count < 2) {
            return $result;
        }

        $intervals = [];
        for ($i = 1; $i < $count; $i++) {
            $days = $arrivals[$i - 1]->diffInHours($arrivals[$i]) / 24;
            if ($days > 0) {
                $intervals[] = $days;
            }
        }

        if (empty($intervals)) {
            return $result;
        }

        sort($intervals);
        $mid = intdiv(count($intervals), 2);
        $median = count($intervals) % 2 === 0
            ? ($intervals[$mid - 1] + $intervals[$mid]) / 2
            : $intervals[$mid];

        $result['cadence_days'] = (int) round($median);
        $result['intervals'] = count($intervals);

        return $result;
    }

    /**
     * Project the next arrival for a structure.
     *
     * Anchor = the LATER of (last actual arrival, last active plan's arrival)
     * so that a manual re-anchor — drag Monday's pull to Tuesday — carries
     * forward into the next projection instead of snapping back to the old
     * historical day. Projected = anchor + cadence.
     *
     * Returns null when there's no cadence (< 2 arrivals) or no anchor.
     */
    public function projectNextArrival(int $structureId): ?Carbon
    {
        $cadence = $this->cadence($structureId);
        if ($cadence['cadence_days'] === null) {
            return null;
        }

        $anchor = $cadence['last_arrival'];

        // Prefer the latest active PLAN as the anchor when it's later than
        // the last actual arrival — that's the re-anchor the operator chose.
        $lastPlan = MoonExtractionPlan::where('structure_id', $structureId)
            ->active()
            ->orderByDesc('planned_arrival_time')
            ->first();

        if ($lastPlan && (!$anchor || $lastPlan->planned_arrival_time->gt($anchor))) {
            $anchor = $lastPlan->planned_arrival_time;
        }

        if (!$anchor) {
            return null;
        }

        $next = $anchor->copy()->addDays($cadence['cadence_days']);

        // Roll forward until the projection is in the future — a long-dormant
        // refinery shouldn't project a slot in the past.
        $now = Carbon::now();
        $guard = 0;
        while ($next->lt($now) && $guard < 520) { // 520 weeks ~= 10y safety cap
            $next->addDays($cadence['cadence_days']);
            $guard++;
        }

        return $next;
    }

    /**
     * The <24h guard. Find every OTHER active plan and known live extraction
     * for the corp whose arrival falls within the gap window of $plannedAt.
     *
     * @param  int         $corporationId
     * @param  Carbon      $plannedAt      the slot being placed/moved
     * @param  int|null    $ignorePlanId   exclude this plan (when editing it)
     * @param  int|null    $structureId    exclude this structure's own live
     *                                      extraction (re-firing the same rig
     *                                      back-to-back is the operator's call,
     *                                      not a cross-moon clash)
     * @return array<int,array{type:string,moon_name:string,structure_name:string,arrival:string,gap_hours:float}>
     */
    public function detectConflicts(int $corporationId, Carbon $plannedAt, ?int $ignorePlanId = null, ?int $structureId = null): array
    {
        $gap = $this->getMinGapHours();
        $windowStart = $plannedAt->copy()->subHours($gap);
        $windowEnd = $plannedAt->copy()->addHours($gap);

        $conflicts = [];

        // Other active plans within the window.
        $plans = MoonExtractionPlan::forCorporation($corporationId)
            ->active()
            ->whereBetween('planned_arrival_time', [$windowStart, $windowEnd])
            ->when($ignorePlanId, fn ($q) => $q->where('id', '!=', $ignorePlanId))
            ->get();
        MoonExtractionPlan::loadDisplayNames($plans);

        foreach ($plans as $plan) {
            $gapHours = round($plannedAt->diffInMinutes($plan->planned_arrival_time) / 60, 1);
            $conflicts[] = [
                'type' => 'plan',
                'moon_name' => $plan->moon_name ?? "Moon {$plan->moon_id}",
                'structure_name' => $plan->structure_name ?? "Structure {$plan->structure_id}",
                'arrival' => $plan->planned_arrival_time->format('M d, Y H:i'),
                'gap_hours' => $gapHours,
            ];
        }

        // Live extractions within the window (the actual chunks already
        // scheduled in-game). Exclude the same structure — re-firing one rig
        // is fine; we only warn about DIFFERENT moons clustering.
        $extractions = MoonExtraction::where('corporation_id', $corporationId)
            ->whereBetween('chunk_arrival_time', [$windowStart, $windowEnd])
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->when($structureId, fn ($q) => $q->where('structure_id', '!=', $structureId))
            ->get();
        MoonExtraction::loadDisplayNames($extractions);

        foreach ($extractions as $ext) {
            $gapHours = round($plannedAt->diffInMinutes($ext->chunk_arrival_time) / 60, 1);
            $conflicts[] = [
                'type' => 'actual',
                'moon_name' => $ext->moon_name ?? "Moon {$ext->moon_id}",
                'structure_name' => $ext->structure_name ?? "Structure {$ext->structure_id}",
                'arrival' => $ext->chunk_arrival_time->format('M d, Y H:i'),
                'gap_hours' => $gapHours,
            ];
        }

        // Closest clash first.
        usort($conflicts, fn ($a, $b) => $a['gap_hours'] <=> $b['gap_hours']);

        return $conflicts;
    }

    /**
     * Auto-fill projected slots for a corporation across a target month.
     *
     * For each refinery with a derivable cadence and no active plan in the
     * month, create an 'auto' plan at the projected arrival. When $spread is
     * true, greedily push later slots forward so no two land inside the gap
     * window — turning "natural" projections into a clean stagger in one
     * click.
     *
     * Idempotent-ish: skips refineries that already have an active plan in
     * the month, so re-running won't duplicate. Returns a small summary.
     *
     * @return array{created:int,skipped:int,no_cadence:int,spread_adjusted:int}
     */
    public function autoFill(int $corporationId, Carbon $month, bool $spread = true, ?int $createdBy = null): array
    {
        $summary = ['created' => 0, 'skipped' => 0, 'no_cadence' => 0, 'spread_adjusted' => 0];

        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $refineries = $this->refineriesForCorporation($corporationId);

        // Candidate (structure_id => projected Carbon) for refineries that
        // need a plan this month.
        $candidates = [];

        foreach ($refineries as $refinery) {
            $structureId = (int) $refinery->structure_id;

            $hasActivePlan = MoonExtractionPlan::where('structure_id', $structureId)
                ->active()
                ->whereBetween('planned_arrival_time', [$monthStart, $monthEnd])
                ->exists();

            if ($hasActivePlan) {
                $summary['skipped']++;
                continue;
            }

            $cadence = $this->cadence($structureId);
            $projected = $this->projectNextArrival($structureId);

            if ($projected === null) {
                $summary['no_cadence']++;
                continue;
            }

            // Only place it if the projection actually lands in the target
            // month — projecting a refinery whose next pull is months out
            // would clutter the wrong calendar page.
            if ($projected->lt($monthStart) || $projected->gt($monthEnd)) {
                $summary['skipped']++;
                continue;
            }

            $candidates[$structureId] = [
                'projected' => $projected,
                'moon_id' => $refinery->moon_id ?? null,
                'cadence_days' => $cadence['cadence_days'],
            ];
        }

        // Optional spread: sort candidates by time, push any that violate the
        // gap relative to the previous placed slot forward.
        if ($spread && !empty($candidates)) {
            $gapHours = $this->getMinGapHours();
            uasort($candidates, fn ($a, $b) => $a['projected']->getTimestamp() <=> $b['projected']->getTimestamp());

            $previous = null;
            foreach ($candidates as $sid => &$c) {
                if ($previous !== null) {
                    $gapToPrev = $previous->diffInHours($c['projected']);
                    if ($c['projected']->lt($previous) || $gapToPrev < $gapHours) {
                        $c['projected'] = $previous->copy()->addHours($gapHours);
                        $summary['spread_adjusted']++;
                    }
                }
                $previous = $c['projected'];
            }
            unset($c);
        }

        foreach ($candidates as $structureId => $c) {
            MoonExtractionPlan::create([
                'corporation_id' => $corporationId,
                'structure_id' => $structureId,
                'moon_id' => $c['moon_id'],
                'planned_arrival_time' => $c['projected'],
                'cadence_days' => $c['cadence_days'],
                'source' => MoonExtractionPlan::SOURCE_AUTO,
                'status' => MoonExtractionPlan::STATUS_PLANNED,
                'created_by' => $createdBy,
            ]);
            $summary['created']++;
        }

        return $summary;
    }

    /**
     * Reconcile planned slots against real ESI extractions.
     *
     * For each still-'planned' row, look for a live extraction on the same
     * structure whose chunk_arrival_time is within the gap window of the
     * plan. If found, mark the plan confirmed, link it, and record signed
     * variance (planned − actual, in hours). Older planned rows for the same
     * structure that are now in the past with no match get superseded so they
     * stop cluttering the planner + conflict checks.
     *
     * Safe to run repeatedly (cron). Returns a count summary.
     *
     * @return array{confirmed:int,superseded:int}
     */
    public function reconcile(int $corporationId): array
    {
        $summary = ['confirmed' => 0, 'superseded' => 0];
        $gap = $this->getMinGapHours();
        $now = Carbon::now();

        $plans = MoonExtractionPlan::forCorporation($corporationId)
            ->where('status', MoonExtractionPlan::STATUS_PLANNED)
            ->orderBy('planned_arrival_time')
            ->get();

        foreach ($plans as $plan) {
            $windowStart = $plan->planned_arrival_time->copy()->subHours($gap);
            $windowEnd = $plan->planned_arrival_time->copy()->addHours($gap);

            $match = MoonExtraction::where('structure_id', $plan->structure_id)
                ->whereBetween('chunk_arrival_time', [$windowStart, $windowEnd])
                ->whereNotIn('status', ['cancelled'])
                ->orderByRaw('ABS(TIMESTAMPDIFF(MINUTE, chunk_arrival_time, ?))', [$plan->planned_arrival_time])
                ->first();

            if ($match) {
                $variance = (int) round(
                    $match->chunk_arrival_time->diffInMinutes($plan->planned_arrival_time, false) / 60
                );
                $plan->update([
                    'status' => MoonExtractionPlan::STATUS_CONFIRMED,
                    'linked_extraction_id' => $match->id,
                    'variance_hours' => $variance,
                    // Inherit the real moon_id if we didn't have one.
                    'moon_id' => $plan->moon_id ?? $match->moon_id,
                ]);
                $summary['confirmed']++;
                continue;
            }

            // No match and the planned time is well past → supersede so it
            // doesn't haunt the conflict checker forever.
            if ($plan->planned_arrival_time->lt($now->copy()->subHours($gap))) {
                $plan->update(['status' => MoonExtractionPlan::STATUS_SUPERSEDED]);
                $summary['superseded']++;
            }
        }

        return $summary;
    }
}
