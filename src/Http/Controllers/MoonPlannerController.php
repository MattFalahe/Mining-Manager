<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionPlan;
use MiningManager\Services\Moon\MoonPlannerService;
use MiningManager\Services\Configuration\SettingsManagerService;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MoonPlannerController — the Moon Extraction Planner.
 *
 * A corp-internal coordination tool (it does NOT control the game). Lets a
 * moon manager / director lay each refinery's projected next pull onto a
 * month calendar and STAGGER arrivals so a small crew can actually mine
 * each chunk before the next lands.
 *
 * Authorization: every action is gated by `mining-manager.moon_manager` OR
 * `mining-manager.director` (admins bypass via SeAT's can() superuser path).
 * The OR can't be expressed with a single `can:` route middleware, so it's
 * enforced here in the constructor.
 */
class MoonPlannerController extends Controller
{
    protected MoonPlannerService $planner;
    protected SettingsManagerService $settings;

    public function __construct(MoonPlannerService $planner, SettingsManagerService $settings)
    {
        $this->planner = $planner;
        $this->settings = $settings;

        // moon_manager OR director (admin bypasses both via can()).
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            if ($user
                && ($user->can('mining-manager.moon_manager') || $user->can('mining-manager.director'))) {
                return $next($request);
            }
            abort(403, 'You need the Moon Manager or Director role to access the planner.');
        });
    }

    /**
     * Resolve the corporation the planner operates on. The moon owner corp
     * (the corp whose refineries MM tracks for extractions) is the canonical
     * scope, mirroring CheckExtractionArrivalsCommand. Returns null when the
     * operator hasn't configured one yet.
     */
    protected function plannerCorporationId(): ?int
    {
        return $this->settings->getTaxProgramCorporationId();
    }

    /**
     * Render the planner calendar for a month.
     */
    public function index(Request $request)
    {
        $features = $this->settings->getFeatureFlags();
        if (!($features['enable_moon_tracking'] ?? true)) {
            return redirect()->route('mining-manager.dashboard')
                ->with('warning', 'Moon tracking is currently disabled. Enable it in Settings > Features.');
        }

        // Anchor month — the first of the three shown. Moons are set in EVE
        // (UTC) time in-game, so the planner works entirely in UTC.
        $anchor = $request->input('month')
            ? Carbon::parse($request->input('month'))->startOfMonth()
            : Carbon::now()->startOfMonth();

        // Three-month window: anchor + next 2.
        $months = [$anchor->copy(), $anchor->copy()->addMonth(), $anchor->copy()->addMonths(2)];
        $rangeStart = $anchor->copy()->startOfMonth();
        $rangeEnd = $anchor->copy()->addMonths(2)->endOfMonth();

        $corporationId = $this->plannerCorporationId();

        $calendar = [];
        $warnings = [];
        $refinerySummaries = [];
        $minGapHours = $this->planner->getMinGapHours();

        if ($corporationId) {
            // Reconcile planned ↔ actual before display so confirmed/superseded
            // states are fresh.
            $this->planner->reconcile($corporationId);

            $built = $this->buildCalendar($corporationId, $rangeStart, $rangeEnd);
            $calendar = $built['calendar'];
            $warnings = $built['warnings'];
            $refinerySummaries = $this->buildRefinerySummaries($corporationId);
        }

        return view('mining-manager::moon.planner', [
            'anchor' => $anchor,
            'months' => $months,
            'calendar' => $calendar,
            'warnings' => $warnings,
            'refinerySummaries' => $refinerySummaries,
            'minGapHours' => $minGapHours,
            'corporationId' => $corporationId,
        ]);
    }

    /**
     * JSON feed of the planner calendar for a month (AJAX month navigation).
     */
    public function data(Request $request)
    {
        $corporationId = $this->plannerCorporationId();
        if (!$corporationId) {
            return response()->json(['calendar' => [], 'refineries' => []]);
        }

        $month = $request->input('month')
            ? Carbon::parse($request->input('month'))->startOfMonth()
            : Carbon::now()->startOfMonth();

        $built = $this->buildCalendar(
            $corporationId,
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth()
        );

        return response()->json([
            'month' => $month->format('Y-m'),
            'min_gap_hours' => $this->planner->getMinGapHours(),
            'calendar' => $built['calendar'],
            'warnings' => $built['warnings'],
            'refineries' => $this->buildRefinerySummaries($corporationId),
        ]);
    }

    /**
     * Live <gap> conflict check for a proposed slot. Drives the JS warning
     * modal before save; the store/update endpoints re-run it server-side so
     * the warning can't be bypassed.
     */
    public function checkConflicts(Request $request)
    {
        $corporationId = $this->plannerCorporationId();
        if (!$corporationId) {
            return response()->json(['conflicts' => [], 'min_gap_hours' => $this->planner->getMinGapHours()]);
        }

        $validated = $request->validate([
            'planned_arrival_time' => 'required|date',
            'structure_id' => 'nullable|integer',
            'ignore_plan_id' => 'nullable|integer',
        ]);

        $plannedAt = Carbon::parse($validated['planned_arrival_time']);
        $conflicts = $this->planner->detectConflicts(
            $corporationId,
            $plannedAt,
            $validated['ignore_plan_id'] ?? null,
            $validated['structure_id'] ?? null
        );

        return response()->json([
            'conflicts' => $conflicts,
            'min_gap_hours' => $this->planner->getMinGapHours(),
        ]);
    }

    /**
     * Auto-fill projected slots for the month (optionally spread to honour
     * the gap).
     */
    public function autoFill(Request $request)
    {
        $corporationId = $this->plannerCorporationId();
        if (!$corporationId) {
            return back()->with('error', 'Configure a Moon Owner Corporation in Settings before using the planner.');
        }

        $validated = $request->validate([
            'month' => 'nullable|date',
            'spread' => 'nullable|boolean',
        ]);

        $anchor = !empty($validated['month'])
            ? Carbon::parse($validated['month'])->startOfMonth()
            : Carbon::now()->startOfMonth();

        $spread = $request->boolean('spread', true);
        $createdBy = auth()->user()->main_character_id ?? null;

        // Fill all three visible months. Calling per-month in sequence dedups
        // progressively — plans placed for the anchor month are visible to the
        // next month's pass (existingPlanTimes re-reads the DB each call).
        $created = 0;
        $fallback = 0;
        $spreadAdjusted = 0;
        foreach ([0, 1, 2] as $offset) {
            $s = $this->planner->autoFill($corporationId, $anchor->copy()->addMonths($offset), $spread, $createdBy);
            $created += $s['created'];
            $fallback += $s['fallback'];
            $spreadAdjusted += $s['spread_adjusted'];
        }

        if ($created > 0) {
            [$actorId, $actorName] = $this->actor();
            \MiningManager\Models\MoonExtractionPlanAudit::record([
                'corporation_id' => $corporationId,
                'action' => \MiningManager\Models\MoonExtractionPlanAudit::ACTION_AUTOFILLED,
                'character_id' => $actorId,
                'character_name' => $actorName,
                'detail' => sprintf(
                    'Auto-filled %d pull%s across %s–%s',
                    $created,
                    $created === 1 ? '' : 's',
                    $anchor->format('M Y'),
                    $anchor->copy()->addMonths(2)->format('M Y')
                ),
            ]);
        }

        $msg = sprintf(
            'Auto-fill complete: %d pull%s planned across %s–%s%s%s.',
            $created,
            $created === 1 ? '' : 's',
            $anchor->format('M Y'),
            $anchor->copy()->addMonths(2)->format('M Y'),
            $fallback > 0 ? " ({$fallback} estimated from corp cadence — limited history)" : '',
            $spreadAdjusted > 0 ? ", {$spreadAdjusted} nudged to keep the {$this->planner->getMinGapHours()}h gap" : ''
        );

        return redirect()
            ->route('mining-manager.moon.planner', ['month' => $anchor->format('Y-m')])
            ->with('success', $msg);
    }

    /**
     * Create a new planned pull. Returns 409 with the conflict list when the
     * slot clashes within the gap window and the operator hasn't confirmed.
     */
    public function store(Request $request)
    {
        $corporationId = $this->plannerCorporationId();
        if (!$corporationId) {
            return response()->json(['error' => 'No Moon Owner Corporation configured.'], 422);
        }

        $validated = $request->validate([
            'structure_id' => 'required|integer',
            'moon_id' => 'nullable|integer',
            'planned_arrival_time' => 'required|date',
            'notes' => 'nullable|string|max:500',
            'confirmed' => 'nullable|boolean',
        ]);

        $plannedAt = Carbon::parse($validated['planned_arrival_time']);

        // Server-side gap guard — refuse unconfirmed clashes.
        if (!$request->boolean('confirmed')) {
            $conflicts = $this->planner->detectConflicts(
                $corporationId,
                $plannedAt,
                null,
                (int) $validated['structure_id']
            );
            if (!empty($conflicts)) {
                return response()->json([
                    'requires_confirmation' => true,
                    'conflicts' => $conflicts,
                    'min_gap_hours' => $this->planner->getMinGapHours(),
                ], 409);
            }
        }

        // Resolve the refinery's anchored moon if the caller didn't pass one.
        $moonId = $validated['moon_id'] ?? null;
        if (!$moonId) {
            $moonId = CorporationStructure::where('structure_id', $validated['structure_id'])->value('moon_id');
        }

        [$actorId, $actorName] = $this->actor();

        $plan = MoonExtractionPlan::create([
            'corporation_id' => $corporationId,
            'structure_id' => (int) $validated['structure_id'],
            'moon_id' => $moonId ? (int) $moonId : null,
            'planned_arrival_time' => $plannedAt,
            'source' => MoonExtractionPlan::SOURCE_MANUAL,
            'status' => MoonExtractionPlan::STATUS_PLANNED,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $actorId,
        ]);

        \MiningManager\Models\MoonExtractionPlanAudit::record([
            'corporation_id' => $corporationId,
            'plan_id' => $plan->id,
            'structure_id' => $plan->structure_id,
            'moon_id' => $plan->moon_id,
            'action' => \MiningManager\Models\MoonExtractionPlanAudit::ACTION_CREATED,
            'character_id' => $actorId,
            'character_name' => $actorName,
            'new_arrival' => $plannedAt,
            'detail' => $this->planLabel($plan),
        ]);

        return response()->json(['success' => true, 'plan_id' => $plan->id]);
    }

    /**
     * Move/edit an existing planned pull (re-anchor). Same gap guard as store.
     */
    public function update(Request $request, $id)
    {
        $corporationId = $this->plannerCorporationId();
        $plan = MoonExtractionPlan::where('id', $id)
            ->where('corporation_id', $corporationId)
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Plan not found.'], 404);
        }

        // Confirmed (reconciled-to-actual) plans are a record of reality —
        // don't let the planner shove them around.
        if ($plan->status === MoonExtractionPlan::STATUS_CONFIRMED) {
            return response()->json(['error' => 'This pull is already confirmed against a real extraction and cannot be moved.'], 422);
        }

        $validated = $request->validate([
            'planned_arrival_time' => 'required|date',
            'notes' => 'nullable|string|max:500',
            'confirmed' => 'nullable|boolean',
        ]);

        $plannedAt = Carbon::parse($validated['planned_arrival_time']);

        if (!$request->boolean('confirmed')) {
            $conflicts = $this->planner->detectConflicts(
                $corporationId,
                $plannedAt,
                $plan->id,
                $plan->structure_id
            );
            if (!empty($conflicts)) {
                return response()->json([
                    'requires_confirmation' => true,
                    'conflicts' => $conflicts,
                    'min_gap_hours' => $this->planner->getMinGapHours(),
                ], 409);
            }
        }

        $oldArrival = $plan->planned_arrival_time->copy();

        $plan->update([
            'planned_arrival_time' => $plannedAt,
            'notes' => $validated['notes'] ?? $plan->notes,
            // A hand-moved slot becomes a manual placement going forward.
            'source' => MoonExtractionPlan::SOURCE_MANUAL,
        ]);

        // Only log an actual time change as a "move".
        if ($oldArrival->ne($plannedAt)) {
            [$actorId, $actorName] = $this->actor();
            \MiningManager\Models\MoonExtractionPlanAudit::record([
                'corporation_id' => $corporationId,
                'plan_id' => $plan->id,
                'structure_id' => $plan->structure_id,
                'moon_id' => $plan->moon_id,
                'action' => \MiningManager\Models\MoonExtractionPlanAudit::ACTION_MOVED,
                'character_id' => $actorId,
                'character_name' => $actorName,
                'old_arrival' => $oldArrival,
                'new_arrival' => $plannedAt,
                'detail' => $this->planLabel($plan),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Remove a planned pull.
     */
    public function destroy($id)
    {
        $corporationId = $this->plannerCorporationId();
        $plan = MoonExtractionPlan::where('id', $id)
            ->where('corporation_id', $corporationId)
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Plan not found.'], 404);
        }

        [$actorId, $actorName] = $this->actor();
        \MiningManager\Models\MoonExtractionPlanAudit::record([
            'corporation_id' => $corporationId,
            'plan_id' => $plan->id,
            'structure_id' => $plan->structure_id,
            'moon_id' => $plan->moon_id,
            'action' => \MiningManager\Models\MoonExtractionPlanAudit::ACTION_DELETED,
            'character_id' => $actorId,
            'character_name' => $actorName,
            'old_arrival' => $plan->planned_arrival_time,
            'detail' => $this->planLabel($plan),
        ]);

        $plan->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Build the day-grouped calendar of planned pulls + actual extractions for
     * a date range, plus a list of scheduling mismatches.
     *
     * Dedup rule (per Matt): a plan and a real pull for the same refinery
     * within MATCH_TOLERANCE_MINUTES are the SAME pull — the plan is hidden and
     * only the real pull (locked) shows. If they're further apart but still in
     * the same cycle (within CYCLE_MATCH_WINDOW_HOURS), the in-game schedule
     * diverged from the plan: the plan is still hidden (no double render) but
     * the real pull is flagged, and the pair is added to $warnings for the
     * "wrong scheduled moon" banner.
     *
     * @return array{calendar: array<string,array<int,array>>, warnings: array<int,array>}
     */
    protected function buildCalendar(int $corporationId, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $tolerance = \MiningManager\Services\Moon\MoonPlannerService::MATCH_TOLERANCE_MINUTES;
        $cycleWindow = \MiningManager\Services\Moon\MoonPlannerService::CYCLE_MATCH_WINDOW_HOURS * 60;

        $plans = MoonExtractionPlan::forCorporation($corporationId)
            ->active()
            ->whereBetween('planned_arrival_time', [$rangeStart, $rangeEnd])
            ->orderBy('planned_arrival_time')
            ->get();
        MoonExtractionPlan::loadDisplayNames($plans);

        $extractions = MoonExtraction::where('corporation_id', $corporationId)
            ->whereBetween('chunk_arrival_time', [$rangeStart, $rangeEnd])
            ->orderBy('chunk_arrival_time')
            ->get();
        MoonExtraction::loadDisplayNames($extractions);

        // Archived/completed pulls live in moon_extraction_history once they age
        // out of moon_extractions. Show them too (locked) so a refinery whose
        // chunk already arrived + got archived doesn't vanish from the planner.
        $history = \MiningManager\Models\MoonExtractionHistory::where('corporation_id', $corporationId)
            ->whereBetween('chunk_arrival_time', [$rangeStart, $rangeEnd])
            ->orderBy('chunk_arrival_time')
            ->get();
        MoonExtraction::loadDisplayNames($history);

        // Merge live + archived into a single "actuals" list (deduped between
        // the two tables by structure + minute).
        $actuals = [];
        $liveKeys = [];
        foreach ($extractions as $ext) {
            if (!$ext->chunk_arrival_time) {
                continue;
            }
            $liveKeys[$ext->structure_id . '@' . $ext->chunk_arrival_time->format('Y-m-d H:i')] = true;
            $actuals[] = [
                'id' => (string) $ext->id,
                'structure_id' => (int) $ext->structure_id,
                'moon_id' => $ext->moon_id,
                'moon_name' => $ext->moon_name ?? "Moon {$ext->moon_id}",
                'structure_name' => $ext->structure_name ?? "Structure {$ext->structure_id}",
                'time' => $ext->chunk_arrival_time,
                'status' => $ext->status,
                'archived' => false,
            ];
        }
        foreach ($history as $h) {
            if (!$h->chunk_arrival_time) {
                continue;
            }
            $key = $h->structure_id . '@' . $h->chunk_arrival_time->format('Y-m-d H:i');
            if (isset($liveKeys[$key])) {
                continue;
            }
            $actuals[] = [
                'id' => 'h' . $h->id,
                'structure_id' => (int) $h->structure_id,
                'moon_id' => $h->moon_id,
                'moon_name' => $h->moon_name ?? "Moon {$h->moon_id}",
                'structure_name' => $h->structure_name ?? "Structure {$h->structure_id}",
                'time' => $h->chunk_arrival_time,
                'status' => $h->final_status ?? 'archived',
                'archived' => true,
            ];
        }

        $calendar = [];
        $warnings = [];

        // Index which actuals get a mismatch flag (keyed by actual id).
        $mismatchByActual = [];

        // Process plans: hide any that map to a real pull; flag off-tolerance
        // matches as scheduling mismatches.
        foreach ($plans as $plan) {
            $nearest = null;
            $nearestOffset = null;
            foreach ($actuals as $a) {
                if ($a['structure_id'] !== (int) $plan->structure_id) {
                    continue;
                }
                $offset = abs($plan->planned_arrival_time->diffInMinutes($a['time']));
                if ($nearestOffset === null || $offset < $nearestOffset) {
                    $nearestOffset = $offset;
                    $nearest = $a;
                }
            }

            // A real pull covers this plan's cycle → don't render the plan.
            if ($nearest !== null && $nearestOffset <= $cycleWindow) {
                if ($nearestOffset > $tolerance) {
                    // Same cycle but the times diverged — flag it.
                    $mismatchByActual[$nearest['id']] = true;
                    $warnings[] = [
                        'moon_name' => $plan->moon_name ?? "Moon {$plan->moon_id}",
                        'structure_name' => $plan->structure_name ?? "Structure {$plan->structure_id}",
                        'planned' => $plan->planned_arrival_time->format('M d, Y H:i') . ' EVE',
                        'actual' => $nearest['time']->format('M d, Y H:i') . ' EVE',
                        'offset_hours' => round($nearestOffset / 60, 1),
                    ];
                }
                continue;
            }

            $day = $plan->planned_arrival_time->format('Y-m-d');
            $calendar[$day][] = [
                'kind' => 'plan',
                'id' => $plan->id,
                'structure_id' => $plan->structure_id,
                'moon_id' => $plan->moon_id,
                'moon_name' => $plan->moon_name ?? "Moon {$plan->moon_id}",
                'structure_name' => $plan->structure_name ?? "Structure {$plan->structure_id}",
                'time' => $plan->planned_arrival_time->format('H:i'),
                'iso' => $plan->planned_arrival_time->toIso8601String(),
                'source' => $plan->source,
                'status' => $plan->status,
                'cadence_days' => $plan->cadence_days,
                'notes' => $plan->notes,
            ];
        }

        // Render actuals (locked), tagging any flagged as a mismatch.
        foreach ($actuals as $a) {
            $day = $a['time']->format('Y-m-d');
            $calendar[$day][] = [
                'kind' => 'actual',
                'id' => $a['id'],
                'structure_id' => $a['structure_id'],
                'moon_id' => $a['moon_id'],
                'moon_name' => $a['moon_name'],
                'structure_name' => $a['structure_name'],
                'time' => $a['time']->format('H:i'),
                'iso' => $a['time']->toIso8601String(),
                'status' => $a['status'],
                'archived' => $a['archived'],
                'mismatch' => isset($mismatchByActual[$a['id']]),
            ];
        }

        // Sort each day's entries by time.
        foreach ($calendar as &$entries) {
            usort($entries, fn ($x, $y) => strcmp($x['time'], $y['time']));
        }
        unset($entries);

        ksort($calendar);

        return ['calendar' => $calendar, 'warnings' => $warnings];
    }

    /**
     * Per-refinery summary panel: cadence, last arrival, projected next pull,
     * and whether a plan already exists. Powers the "Refineries" sidebar and
     * the manual add-slot dropdown.
     *
     * @return array<int,array>
     */
    protected function buildRefinerySummaries(int $corporationId): array
    {
        $refineries = $this->planner->refineriesForCorporation($corporationId);
        if ($refineries->isEmpty()) {
            return [];
        }

        // Batch moon + structure names.
        $structureIds = $refineries->pluck('structure_id')->all();
        $names = DB::table('universe_structures')
            ->whereIn('structure_id', $structureIds)
            ->pluck('name', 'structure_id');
        $moonNames = DB::table('moons')
            ->whereIn('moon_id', $refineries->pluck('moon_id')->filter()->all())
            ->pluck('name', 'moon_id');

        $summaries = [];
        foreach ($refineries as $refinery) {
            $sid = (int) $refinery->structure_id;
            $cadence = $this->planner->cadence($sid);
            $projected = $this->planner->projectNextArrival($sid);

            $summaries[] = [
                'structure_id' => $sid,
                'moon_id' => $refinery->moon_id,
                'structure_name' => $names[$sid] ?? "Structure {$sid}",
                'moon_name' => $refinery->moon_id ? ($moonNames[$refinery->moon_id] ?? "Moon {$refinery->moon_id}") : null,
                'cadence_days' => $cadence['cadence_days'],
                'arrival_count' => $cadence['arrival_count'],
                'last_arrival' => $cadence['last_arrival'] ? $cadence['last_arrival']->format('M d, Y H:i') : null,
                'projected_next' => $projected ? $projected->format('M d, Y H:i') : null,
                'projected_iso' => $projected ? $projected->toIso8601String() : null,
                'has_history' => $cadence['cadence_days'] !== null,
                // Coverage: how many upcoming pulls are planned for this rig
                // (0 = skipped moon), + its highest ore tier for the badge.
                'future_plan_count' => $this->planner->futurePlanCount($sid),
                'rarity' => $this->planner->highestRarityForStructure($sid),
            ];
        }

        // Skipped (uncovered) refineries first, then by rarity (richest first),
        // so the operator's attention lands on gaps + valuable moons.
        $rarityRank = ['R64' => 5, 'R32' => 4, 'R16' => 3, 'R8' => 2, 'R4' => 1];
        usort($summaries, function ($a, $b) use ($rarityRank) {
            $aCov = $a['future_plan_count'] === 0 ? 0 : 1;
            $bCov = $b['future_plan_count'] === 0 ? 0 : 1;
            if ($aCov !== $bCov) {
                return $aCov <=> $bCov; // uncovered (0) first
            }
            return ($rarityRank[$b['rarity']] ?? 0) <=> ($rarityRank[$a['rarity']] ?? 0);
        });

        return $summaries;
    }

    /**
     * Recent planner change history for the corp (who did what) — JSON for the
     * History modal.
     */
    public function history(Request $request)
    {
        $corporationId = $this->plannerCorporationId();
        if (!$corporationId) {
            return response()->json(['entries' => []]);
        }

        $rows = \MiningManager\Models\MoonExtractionPlanAudit::forCorporation($corporationId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $entries = $rows->map(function ($r) {
            return [
                'action' => $r->action,
                'actor' => $r->character_name ?: 'System',
                'detail' => $r->detail,
                'old_arrival' => $r->old_arrival ? $r->old_arrival->toIso8601String() : null,
                'new_arrival' => $r->new_arrival ? $r->new_arrival->toIso8601String() : null,
                'when' => $r->created_at ? $r->created_at->toIso8601String() : null,
            ];
        });

        return response()->json(['entries' => $entries]);
    }

    /**
     * Resolve the acting operator: [character_id, character_name].
     */
    protected function actor(): array
    {
        $user = auth()->user();
        $charId = $user->main_character_id ?? null;
        $name = null;
        if ($charId) {
            $name = DB::table('character_infos')->where('character_id', $charId)->value('name');
        }
        return [$charId, $name];
    }

    /**
     * "Moon (Structure)" label for a plan, for audit detail lines.
     */
    protected function planLabel(MoonExtractionPlan $plan): string
    {
        $moon = $plan->moon_id
            ? (DB::table('moons')->where('moon_id', $plan->moon_id)->value('name') ?? "Moon {$plan->moon_id}")
            : 'Unknown Moon';
        $struct = DB::table('universe_structures')->where('structure_id', $plan->structure_id)->value('name')
            ?? "Structure {$plan->structure_id}";
        return "{$moon} ({$struct})";
    }
}
