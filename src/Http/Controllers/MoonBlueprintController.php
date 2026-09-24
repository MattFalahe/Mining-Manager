<?php

namespace MiningManager\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MoonRotation;
use MiningManager\Models\MoonRotationSlot;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Moon\MoonPlannerService;
use MiningManager\Services\Moon\MoonRotationService;
use Seat\Web\Http\Controllers\Controller;

/**
 * Blueprints: a reusable pattern of pulls, laid over the planner.
 *
 * The planner plans one pull at a time, and auto-fill guesses from history.
 * Neither matches how a corp actually runs moons: a set of moons on set days,
 * repeating every week or two. A blueprint is that pattern, and applying it
 * writes ordinary planned pulls from a date you choose, for as many cycles as
 * you ask for.
 */
class MoonBlueprintController extends Controller
{
    /**
     * A pattern longer than this stops being a rotation and becomes a
     * calendar, which is what the planner itself is for.
     */
    public const MAX_WEEKS = 8;

    protected MoonPlannerService $planner;
    protected MoonRotationService $rotations;
    protected SettingsManagerService $settings;

    public function __construct(
        MoonPlannerService $planner,
        MoonRotationService $rotations,
        SettingsManagerService $settings
    ) {
        $this->planner = $planner;
        $this->rotations = $rotations;
        $this->settings = $settings;

        // Same gate as the planner: moon_manager OR director.
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            if ($user
                && ($user->can('mining-manager.moon_manager') || $user->can('mining-manager.director'))) {
                return $next($request);
            }
            abort(403, 'You need the Moon Manager or Director role to access blueprints.');
        });
    }

    public function index()
    {
        $corporationId = $this->corporationId();

        $blueprints = [];
        $blueprintList = [];
        $refineries = [];

        if ($corporationId) {
            $blueprints = $this->blueprintPayload($corporationId);
            $blueprintList = $this->rotations->listForCorporation($corporationId);
            $refineries = $this->refineryOptions($corporationId);
        }

        return view('mining-manager::moon.blueprints', [
            'corporationId' => $corporationId,
            'blueprints' => $blueprints,
            // The same list the planner's apply dialog reads.
            'blueprintList' => $blueprintList,
            'refineries' => $refineries,
            'maxWeeks' => self::MAX_WEEKS,
        ]);
    }

    /**
     * Create or replace a blueprint, slots and all. The whole pattern is sent
     * every time: a grid with a cell removed is only expressible as the grid
     * that remains.
     */
    public function store(Request $request)
    {
        $corporationId = $this->corporationId();
        if (!$corporationId) {
            return response()->json(['error' => 'No Moon Owner Corporation configured.'], 422);
        }

        $validated = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:100',
            'weeks' => 'required|integer|min:1|max:' . self::MAX_WEEKS,
            'slots' => 'array',
            'slots.*.id' => 'nullable|integer',
            'slots.*.week_number' => 'required|integer|min:1',
            'slots.*.day_of_week' => 'required|integer|min:1|max:7',
            'slots.*.time_of_day' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'slots.*.structure_id' => 'required|integer',
        ]);

        $weeks = (int) $validated['weeks'];
        $known = $this->planner->refineriesForCorporation($corporationId)->keyBy('structure_id');

        $slots = [];
        $seen = [];
        foreach ($validated['slots'] ?? [] as $slot) {
            $structureId = (int) $slot['structure_id'];
            $refinery = $known->get($structureId);

            if (!$refinery) {
                return response()->json([
                    'error' => 'One of the slots is not a refinery belonging to this corporation.',
                ], 422);
            }

            if (isset($seen[$structureId])) {
                return response()->json([
                    'error' => 'Each refinery can only appear once in a blueprint. '
                        . ($refinery->moon_id ? 'That moon is' : 'That refinery is') . ' already in this one.',
                ], 422);
            }
            $seen[$structureId] = true;

            if ((int) $slot['week_number'] > $weeks) {
                return response()->json([
                    'error' => 'A slot sits in week ' . $slot['week_number'] . ', past the end of a '
                        . $weeks . '-week blueprint.',
                ], 422);
            }

            [$hour, $minute] = array_map('intval', explode(':', $slot['time_of_day']));

            $slots[] = [
                'id' => isset($slot['id']) ? (int) $slot['id'] : null,
                'week_number' => (int) $slot['week_number'],
                'day_of_week' => (int) $slot['day_of_week'],
                'time_of_day' => sprintf('%02d:%02d:00', min(23, max(0, $hour)), min(59, max(0, $minute))),
                'structure_id' => $structureId,
                'moon_id' => $refinery->moon_id ? (int) $refinery->moon_id : null,
            ];
        }

        $blueprint = null;
        // Kept from before the write: without the slots as they were, there is
        // no way to work out where the pulls already on the calendar started.
        $oldSlots = [];
        $oldWeeks = $weeks;

        DB::transaction(function () use ($validated, $corporationId, $weeks, $slots, &$blueprint, &$oldSlots, &$oldWeeks) {
            if (!empty($validated['id'])) {
                $blueprint = MoonRotation::forCorporation($corporationId)->find($validated['id']);
            }

            if ($blueprint) {
                $oldWeeks = (int) $blueprint->weeks;
                $oldSlots = MoonRotationSlot::where('rotation_id', $blueprint->id)->get()->keyBy('id')->all();
                $blueprint->update(['name' => $validated['name'], 'weeks' => $weeks]);
            } else {
                $blueprint = MoonRotation::create([
                    'corporation_id' => $corporationId,
                    'name' => $validated['name'],
                    'weeks' => $weeks,
                    'created_by' => $this->actor()[0],
                ]);
            }

            // Slots are updated in place rather than replaced, so a pull on
            // the calendar still knows which part of the pattern made it.
            $kept = [];
            foreach ($slots as $slot) {
                $slotId = $slot['id'];
                unset($slot['id']);

                if ($slotId && isset($oldSlots[$slotId])) {
                    $oldSlots[$slotId]->newQuery()->whereKey($slotId)->update($slot);
                    $kept[] = $slotId;
                    continue;
                }

                $kept[] = (int) MoonRotationSlot::create($slot + ['rotation_id' => $blueprint->id])->id;
            }

            MoonRotationSlot::where('rotation_id', $blueprint->id)
                ->when(!empty($kept), fn ($query) => $query->whereNotIn('id', $kept))
                ->delete();
        });

        $resync = null;
        if ($request->boolean('resync') && !empty($validated['id'])) {
            [$actorId, $actorName] = $this->actor();
            $resync = $this->rotations->resync($blueprint, $oldSlots, $oldWeeks, $actorId, $actorName);
        }

        return response()->json([
            'success' => true,
            'blueprint_id' => $blueprint->id,
            'resync' => $resync,
            'blueprints' => $this->blueprintPayload($corporationId),
        ]);
    }

    /**
     * Drop the refineries this corporation no longer owns from its blueprints,
     * and the pulls they had planned ahead.
     */
    public function prune()
    {
        $corporationId = $this->corporationId();
        if (!$corporationId) {
            return response()->json(['error' => 'No Moon Owner Corporation configured.'], 422);
        }

        [$actorId, $actorName] = $this->actor();
        $removed = $this->rotations->pruneMissingRefineries($corporationId, $actorId, $actorName);

        return response()->json([
            'success' => true,
            'removed' => $removed,
            'blueprints' => $this->blueprintPayload($corporationId),
        ]);
    }

    public function destroy($id)
    {
        $corporationId = $this->corporationId();
        $blueprint = MoonRotation::forCorporation($corporationId)->find($id);

        if (!$blueprint) {
            return response()->json(['error' => 'That blueprint does not exist.'], 404);
        }

        DB::transaction(function () use ($blueprint) {
            MoonRotationSlot::where('rotation_id', $blueprint->id)->delete();
            $blueprint->delete();
        });

        // Pulls it already wrote are left alone. They are ordinary planned
        // pulls and somebody is expecting them; removing the pattern is not
        // the same as calling off the mining.
        return response()->json([
            'success' => true,
            'blueprints' => $this->blueprintPayload($corporationId),
        ]);
    }

    /**
     * What applying this blueprint would write. Nothing is saved.
     */
    public function preview(Request $request, $id)
    {
        [$blueprint, $error] = $this->resolveForApply($request, $id);
        if ($error) {
            return $error;
        }

        $preview = $this->rotations->preview(
            $blueprint,
            Carbon::parse($request->input('start_date'))->startOfDay(),
            (int) $request->input('cycles'),
            $request->boolean('take_over')
        );

        return response()->json([
            'success' => true,
            'summary' => $preview['summary'],
            'max_cycles' => $this->rotations->maxCycles($blueprint),
            // What taking over the window would clear out of it, so the
            // operator sees the cost before agreeing to it.
            'removals' => array_map(function ($removal) {
                return [
                    'structure_name' => $this->structureName($removal['structure_id']),
                    'arrival' => $removal['arrival']->format('D d M Y H:i'),
                    'source' => $removal['source'],
                    'from_this_blueprint' => $removal['from_this_blueprint'],
                ];
            }, $preview['removals']),
            'rows' => array_map(function ($row) {
                return [
                    'cycle' => $row['cycle'],
                    'structure_id' => $row['structure_id'],
                    'structure_name' => $this->structureName($row['structure_id']),
                    'arrival' => $row['arrival']->format('D d M Y H:i'),
                    'skip' => $row['skip'],
                    'clashes' => $row['clashes'],
                ];
            }, $preview['rows']),
        ]);
    }

    public function apply(Request $request, $id)
    {
        [$blueprint, $error] = $this->resolveForApply($request, $id);
        if ($error) {
            return $error;
        }

        [$actorId, $actorName] = $this->actor();

        $result = $this->rotations->apply(
            $blueprint,
            Carbon::parse($request->input('start_date'))->startOfDay(),
            (int) $request->input('cycles'),
            $actorId,
            $actorName,
            $request->boolean('take_over')
        );

        return response()->json(['success' => true] + $result);
    }

    /**
     * @return array{0: ?MoonRotation, 1: ?\Illuminate\Http\JsonResponse}
     */
    protected function resolveForApply(Request $request, $id): array
    {
        $request->validate([
            'start_date' => 'required|date',
            'cycles' => 'required|integer|min:1',
        ]);

        $corporationId = $this->corporationId();
        $blueprint = $corporationId ? MoonRotation::forCorporation($corporationId)->find($id) : null;

        if (!$blueprint) {
            return [null, response()->json(['error' => 'That blueprint does not exist.'], 404)];
        }

        if (!$blueprint->slots()->exists()) {
            return [null, response()->json(['error' => 'That blueprint has no pulls in it yet.'], 422)];
        }

        return [$blueprint, null];
    }

    /**
     * Blueprints with their slots, shaped for the grid.
     */
    protected function blueprintPayload(int $corporationId): array
    {
        $owned = $this->planner->refineriesForCorporation($corporationId)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return MoonRotation::forCorporation($corporationId)
            ->with('slots')
            ->orderBy('name')
            ->get()
            ->map(function (MoonRotation $blueprint) use ($owned) {
                return [
                    'id' => $blueprint->id,
                    'name' => $blueprint->name,
                    'weeks' => $blueprint->weeks,
                    'slots' => $blueprint->slots->map(fn (MoonRotationSlot $slot) => [
                        'id' => $slot->id,
                        'week_number' => $slot->week_number,
                        'day_of_week' => $slot->day_of_week,
                        'time_of_day' => substr((string) $slot->time_of_day, 0, 5),
                        'structure_id' => $slot->structure_id,
                        'structure_name' => $this->structureName($slot->structure_id),
                        // Unanchored, destroyed or handed over: nothing can pull
                        // from it, so the page says so rather than planning it.
                        'missing' => !in_array((int) $slot->structure_id, $owned, true),
                    ])->all(),
                    'planned_ahead' => \MiningManager\Models\MoonExtractionPlan::where('rotation_id', $blueprint->id)
                        ->active()
                        ->where('planned_arrival_time', '>', Carbon::now())
                        ->count(),
                ];
            })
            ->all();
    }

    /**
     * Refineries for the slot picker, in system order like the planner's own.
     */
    protected function refineryOptions(int $corporationId): array
    {
        $refineries = $this->planner->refineriesForCorporation($corporationId);
        if ($refineries->isEmpty()) {
            return [];
        }

        $rows = DB::table('universe_structures')
            ->whereIn('structure_id', $refineries->pluck('structure_id')->all())
            ->get(['structure_id', 'name', 'solar_system_id']);

        $systemNames = DB::table('solar_systems')
            ->whereIn('system_id', $rows->pluck('solar_system_id')->filter()->unique()->all())
            ->pluck('name', 'system_id');

        $options = $refineries->map(function ($refinery) use ($rows, $systemNames) {
            $row = $rows->firstWhere('structure_id', $refinery->structure_id);

            return [
                'structure_id' => (int) $refinery->structure_id,
                'structure_name' => $row->name ?? ('Structure ' . $refinery->structure_id),
                'system_name' => $row && $row->solar_system_id ? ($systemNames[$row->solar_system_id] ?? null) : null,
                // Which tier the moon is, so a pattern can be read at a glance
                // and the richest moons are not the ones left out by accident.
                'rarity' => $this->planner->highestRarityForStructure((int) $refinery->structure_id),
            ];
        })->all();

        usort($options, function ($a, $b) {
            return strnatcasecmp(
                ($a['system_name'] ?? "\u{ffff}") . ' ' . $a['structure_name'],
                ($b['system_name'] ?? "\u{ffff}") . ' ' . $b['structure_name']
            );
        });

        return $options;
    }

    protected function structureName(int $structureId): string
    {
        static $names = [];

        if (!array_key_exists($structureId, $names)) {
            $names[$structureId] = DB::table('universe_structures')
                ->where('structure_id', $structureId)
                ->value('name') ?? ('Structure ' . $structureId);
        }

        return $names[$structureId];
    }

    /**
     * Who is doing this, the way the planner records it: the main character's
     * id, and its name from SeAT rather than from the session.
     *
     * @return array{0: ?int, 1: ?string}
     */
    protected function actor(): array
    {
        $characterId = auth()->user()->main_character_id ?? null;
        $name = $characterId
            ? DB::table('character_infos')->where('character_id', $characterId)->value('name')
            : null;

        return [$characterId, $name];
    }

    /**
     * The same scope the planner works in, so a blueprint can only ever place
     * pulls on refineries the planner itself would show.
     */
    protected function corporationId(): ?int
    {
        return $this->settings->getTaxProgramCorporationId();
    }
}
