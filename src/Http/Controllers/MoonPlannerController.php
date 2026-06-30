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

        $month = $request->input('month')
            ? Carbon::parse($request->input('month'))->startOfMonth()
            : Carbon::now()->startOfMonth();

        $corporationId = $this->plannerCorporationId();

        $calendar = [];
        $refinerySummaries = [];
        $minGapHours = $this->planner->getMinGapHours();

        if ($corporationId) {
            // Reconcile planned ↔ actual before display so confirmed/superseded
            // states are fresh.
            $this->planner->reconcile($corporationId);

            $calendar = $this->buildCalendar($corporationId, $month);
            $refinerySummaries = $this->buildRefinerySummaries($corporationId);
        }

        return view('mining-manager::moon.planner', [
            'month' => $month,
            'calendar' => $calendar,
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

        return response()->json([
            'month' => $month->format('Y-m'),
            'min_gap_hours' => $this->planner->getMinGapHours(),
            'calendar' => $this->buildCalendar($corporationId, $month),
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

        $month = !empty($validated['month'])
            ? Carbon::parse($validated['month'])->startOfMonth()
            : Carbon::now()->startOfMonth();

        $spread = $request->boolean('spread', true);

        $summary = $this->planner->autoFill(
            $corporationId,
            $month,
            $spread,
            auth()->user()->main_character_id ?? null
        );

        $msg = sprintf(
            'Auto-fill complete: %d planned, %d skipped, %d without enough history%s.',
            $summary['created'],
            $summary['skipped'],
            $summary['no_cadence'],
            $summary['spread_adjusted'] > 0 ? ", {$summary['spread_adjusted']} nudged to keep the gap" : ''
        );

        return redirect()
            ->route('mining-manager.moon.planner', ['month' => $month->format('Y-m')])
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

        $plan = MoonExtractionPlan::create([
            'corporation_id' => $corporationId,
            'structure_id' => (int) $validated['structure_id'],
            'moon_id' => $moonId ? (int) $moonId : null,
            'planned_arrival_time' => $plannedAt,
            'source' => MoonExtractionPlan::SOURCE_MANUAL,
            'status' => MoonExtractionPlan::STATUS_PLANNED,
            'notes' => $validated['notes'] ?? null,
            'created_by' => auth()->user()->main_character_id ?? null,
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

        $plan->update([
            'planned_arrival_time' => $plannedAt,
            'notes' => $validated['notes'] ?? $plan->notes,
            // A hand-moved slot becomes a manual placement going forward.
            'source' => MoonExtractionPlan::SOURCE_MANUAL,
        ]);

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

        $plan->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Build the day-grouped calendar of planned pulls + actual extractions
     * for the month.
     *
     * @return array<string,array<int,array>>  keyed by Y-m-d
     */
    protected function buildCalendar(int $corporationId, Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $plans = MoonExtractionPlan::forCorporation($corporationId)
            ->active()
            ->forMonth($month)
            ->orderBy('planned_arrival_time')
            ->get();
        MoonExtractionPlan::loadDisplayNames($plans);

        $extractions = MoonExtraction::where('corporation_id', $corporationId)
            ->whereBetween('chunk_arrival_time', [$monthStart, $monthEnd])
            ->orderBy('chunk_arrival_time')
            ->get();
        MoonExtraction::loadDisplayNames($extractions);

        $calendar = [];

        foreach ($plans as $plan) {
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

        foreach ($extractions as $ext) {
            $day = $ext->chunk_arrival_time->format('Y-m-d');
            $calendar[$day][] = [
                'kind' => 'actual',
                'id' => $ext->id,
                'structure_id' => $ext->structure_id,
                'moon_id' => $ext->moon_id,
                'moon_name' => $ext->moon_name ?? "Moon {$ext->moon_id}",
                'structure_name' => $ext->structure_name ?? "Structure {$ext->structure_id}",
                'time' => $ext->chunk_arrival_time->format('H:i'),
                'iso' => $ext->chunk_arrival_time->toIso8601String(),
                'status' => $ext->status,
            ];
        }

        // Sort each day's entries by time.
        foreach ($calendar as &$entries) {
            usort($entries, fn ($a, $b) => strcmp($a['time'], $b['time']));
        }
        unset($entries);

        ksort($calendar);

        return $calendar;
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
            ];
        }

        return $summaries;
    }
}
