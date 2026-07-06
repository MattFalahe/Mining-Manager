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

    /**
     * A plan and a real extraction for the same refinery within this many
     * minutes are the SAME pull — deduped silently. Beyond it (but within the
     * same cycle) the in-game schedule diverged from the plan → flagged as a
     * scheduling mismatch ("wrong scheduled moon").
     */
    public const MATCH_TOLERANCE_MINUTES = 30;

    /**
     * How far from a projected occurrence a real extraction still counts as
     * "this cycle is already covered" (so auto-fill won't add a plan for it,
     * and display dedup matches a plan to its real pull). Kept below realistic
     * cadences so it never bleeds into the previous/next cycle.
     */
    public const CYCLE_MATCH_WINDOW_HOURS = 72;

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
     * Auto-fill projected pulls for a corporation across a target month.
     *
     * Unlike a single "next pull" projection, this walks each refinery's
     * cadence across the WHOLE viewed month and places a plan on every
     * occurrence that lands in it — so a refinery that should pull mid-month
     * (even if the in-game extraction hasn't been fired yet, or its cycle
     * already rolled past `now`) still shows up. Occurrences already covered
     * by a real extraction, an archived pull, or an existing plan are skipped,
     * so re-running is safe and we never duplicate a pull that's already on
     * the board.
     *
     * Cadence comes from history (median interval, ≥2 arrivals). Refineries
     * with too little history of their own fall back to the corp-median
     * cadence so they still get projected (flagged in the `fallback` count) —
     * the operator can drag them to the real day.
     *
     * @return array{created:int,skipped:int,no_cadence:int,spread_adjusted:int,fallback:int}
     */
    public function autoFill(int $corporationId, Carbon $month, bool $spread = true, ?int $createdBy = null): array
    {
        $summary = ['created' => 0, 'skipped' => 0, 'no_cadence' => 0, 'spread_adjusted' => 0, 'fallback' => 0];

        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $gapHours = $this->getMinGapHours();

        $refineries = $this->refineriesForCorporation($corporationId);
        if ($refineries->isEmpty()) {
            return $summary;
        }

        // Corp-median cadence across refineries that HAVE a derivable one —
        // the fallback for rigs without enough of their own history.
        $knownCadences = [];
        foreach ($refineries as $refinery) {
            $c = $this->cadence((int) $refinery->structure_id)['cadence_days'];
            if ($c) {
                $knownCadences[] = $c;
            }
        }
        $fallbackCadence = $this->medianInt($knownCadences) ?? 30;

        // Each candidate: ['structure_id','moon_id','arrival'=>Carbon,'cadence_days','fallback'=>bool]
        $candidates = [];

        foreach ($refineries as $refinery) {
            $structureId = (int) $refinery->structure_id;
            $cad = $this->cadence($structureId);

            $cadenceDays = $cad['cadence_days'];
            $isFallback = false;
            if ($cadenceDays === null) {
                $cadenceDays = $fallbackCadence;
                $isFallback = true;
            }

            // Need an anchor to iterate from and a sane cadence.
            $anchor = $cad['last_arrival'];
            if (!$anchor || $cadenceDays < 1) {
                $summary['no_cadence']++;
                continue;
            }

            // Real pulls (live + archived) block the WHOLE cycle they belong
            // to — if a moon already extracted around this occurrence, that
            // cycle is covered and we don't plan over it. Existing plans only
            // block the immediate gap window so we don't stack plans.
            $actualTimes = $this->existingActualTimes($structureId);
            $planTimes = $this->existingPlanTimes($structureId);

            // Align the anchor to the first occurrence at/just before the
            // month start, then step forward collecting every occurrence in
            // the month window.
            $occ = $anchor->copy();
            $guard = 0;
            while ($occ->gt($monthStart) && $guard < 800) {
                $occ->subDays($cadenceDays);
                $guard++;
            }

            $placedForStructure = 0;
            $guard = 0;
            while ($occ->lte($monthEnd) && $guard < 800 && $placedForStructure < 15) {
                $guard++;
                $coveredByActual = $this->coveredWithin($actualTimes, $occ, self::CYCLE_MATCH_WINDOW_HOURS * 60);
                $coveredByPlan = $this->coveredWithin($planTimes, $occ, $gapHours * 60);
                if ($occ->gte($monthStart) && !$coveredByActual && !$coveredByPlan) {
                    $candidates[] = [
                        'structure_id' => $structureId,
                        'moon_id' => $refinery->moon_id ?? null,
                        'arrival' => $occ->copy(),
                        'cadence_days' => $cadenceDays,
                        'fallback' => $isFallback,
                    ];
                    $placedForStructure++;
                }
                $occ->addDays($cadenceDays);
            }

            if ($placedForStructure === 0) {
                $summary['skipped']++;
            }
        }

        // Spread: sort by time, push any occurrence that violates the gap
        // relative to the previously placed one forward.
        if ($spread && count($candidates) > 1) {
            usort($candidates, fn ($a, $b) => $a['arrival']->getTimestamp() <=> $b['arrival']->getTimestamp());
            $previous = null;
            foreach ($candidates as &$c) {
                if ($previous !== null
                    && ($c['arrival']->lt($previous) || $previous->diffInHours($c['arrival']) < $gapHours)) {
                    $c['arrival'] = $previous->copy()->addHours($gapHours);
                    $summary['spread_adjusted']++;
                }
                $previous = $c['arrival'];
            }
            unset($c);
        }

        foreach ($candidates as $c) {
            MoonExtractionPlan::create([
                'corporation_id' => $corporationId,
                'structure_id' => $c['structure_id'],
                'moon_id' => $c['moon_id'],
                'planned_arrival_time' => $c['arrival'],
                'cadence_days' => $c['cadence_days'],
                'source' => MoonExtractionPlan::SOURCE_AUTO,
                'status' => MoonExtractionPlan::STATUS_PLANNED,
                'notes' => $c['fallback'] ? 'Estimated from corp cadence (limited history)' : null,
                'created_by' => $createdBy,
            ]);
            $summary['created']++;
            if ($c['fallback']) {
                $summary['fallback']++;
            }
        }

        return $summary;
    }

    /**
     * Real chunk-arrival timestamps for a structure — live extractions +
     * archived history. These represent pulls that actually happened / are
     * scheduled in-game.
     *
     * @return \Illuminate\Support\Collection<int,Carbon>
     */
    protected function existingActualTimes(int $structureId): Collection
    {
        $live = MoonExtraction::where('structure_id', $structureId)
            ->whereNotIn('status', ['cancelled'])
            ->pluck('chunk_arrival_time');

        $history = MoonExtractionHistory::where('structure_id', $structureId)
            ->pluck('chunk_arrival_time');

        return $live->merge($history)
            ->filter()
            ->map(fn ($t) => $t instanceof Carbon ? $t : Carbon::parse($t))
            ->values();
    }

    /**
     * Planned-pull timestamps for a structure (active plans only).
     *
     * @return \Illuminate\Support\Collection<int,Carbon>
     */
    protected function existingPlanTimes(int $structureId): Collection
    {
        return MoonExtractionPlan::where('structure_id', $structureId)
            ->active()
            ->pluck('planned_arrival_time')
            ->filter()
            ->map(fn ($t) => $t instanceof Carbon ? $t : Carbon::parse($t))
            ->values();
    }

    /**
     * True when any timestamp in $times is within $toleranceMinutes of $moment.
     *
     * @param \Illuminate\Support\Collection<int,Carbon> $times
     */
    protected function coveredWithin(Collection $times, Carbon $moment, int $toleranceMinutes): bool
    {
        foreach ($times as $t) {
            if (abs($moment->diffInMinutes($t)) < $toleranceMinutes) {
                return true;
            }
        }
        return false;
    }

    /**
     * Integer median of a list, or null when empty.
     *
     * @param array<int,int|float> $values
     */
    protected function medianInt(array $values): ?int
    {
        if (empty($values)) {
            return null;
        }
        sort($values);
        $mid = intdiv(count($values), 2);
        $median = count($values) % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];
        return (int) round($median);
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

        $tol = self::MATCH_TOLERANCE_MINUTES;
        $cycleWindow = self::CYCLE_MATCH_WINDOW_HOURS;

        foreach ($plans as $plan) {
            // Confirm ONLY on a tight (±30 min) match to a live extraction —
            // that's genuinely the same pull. A looser window would wrongly
            // "confirm" a plan against a pull that's actually mis-scheduled,
            // hiding the discrepancy the operator needs to see.
            $windowStart = $plan->planned_arrival_time->copy()->subMinutes($tol);
            $windowEnd = $plan->planned_arrival_time->copy()->addMinutes($tol);

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

            // Only supersede a plan that's well in the past AND has no real
            // pull anywhere near its cycle — i.e. genuinely abandoned. A past
            // plan WITH a nearby (but off-tolerance) actual is a scheduling
            // mismatch we deliberately keep active so the planner can flag it.
            if ($plan->planned_arrival_time->lt($now->copy()->subHours($cycleWindow))) {
                $nearbyActual = $this->coveredWithin(
                    $this->existingActualTimes($plan->structure_id),
                    $plan->planned_arrival_time,
                    $cycleWindow * 60
                );
                if (!$nearbyActual) {
                    $plan->update(['status' => MoonExtractionPlan::STATUS_SUPERSEDED]);
                    $summary['superseded']++;
                }
            }
        }

        return $summary;
    }

    /**
     * Number of still-upcoming planned pulls for a refinery across the whole
     * horizon (not just one month). Powers the sidebar "planned N×" / "not
     * planned" indicator so operators can spot a skipped moon when planning a
     * year out.
     */
    public function futurePlanCount(int $structureId): int
    {
        return MoonExtractionPlan::where('structure_id', $structureId)
            ->active()
            ->where('planned_arrival_time', '>', Carbon::now())
            ->count();
    }

    /**
     * Highest moon-ore rarity tier (R4..R64) in a refinery's most recent known
     * composition, or null when none is available. Reads the latest extraction
     * (live, else archived) — ore_composition stores a type_id per ore entry,
     * which MoonOreHelper::getRarity maps to a tier.
     */
    public function highestRarityForStructure(int $structureId): ?string
    {
        $composition = MoonExtraction::where('structure_id', $structureId)
            ->whereNotNull('ore_composition')
            ->orderByDesc('chunk_arrival_time')
            ->value('ore_composition');

        if (!is_array($composition) || empty($composition)) {
            $composition = MoonExtractionHistory::where('structure_id', $structureId)
                ->whereNotNull('ore_composition')
                ->orderByDesc('chunk_arrival_time')
                ->value('ore_composition');
        }

        if (!is_array($composition) || empty($composition)) {
            return null;
        }

        $rank = ['R4' => 1, 'R8' => 2, 'R16' => 3, 'R32' => 4, 'R64' => 5];
        $best = null;
        $bestRank = 0;
        foreach ($composition as $ore) {
            $typeId = is_array($ore) ? ($ore['type_id'] ?? null) : null;
            if (!$typeId) {
                continue;
            }
            $rarity = \MiningManager\Services\Moon\MoonOreHelper::getRarity((int) $typeId);
            if ($rarity && ($rank[$rarity] ?? 0) > $bestRank) {
                $bestRank = $rank[$rarity];
                $best = $rarity;
            }
        }

        return $best;
    }
}
