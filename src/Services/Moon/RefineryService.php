<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use Seat\Eveapi\Models\Corporation\CorporationStructure;

/**
 * What Mining Manager knows about a corporation's moon refineries, in one
 * place: which ones count, whether the drill is fitted and online, whether
 * they are being unanchored or have gone, which moon each sits on, and which
 * moon drilling rigs are fitted.
 *
 * The planner, its reminders, the Refinery Gone check, the extraction pages and
 * the simulator all ask here, so none of them can describe a refinery
 * differently from the others.
 *
 * Refineries are Athanors and Tataras. Metenox drills are left out on purpose:
 * they mine continuously and have no chunk to plan around.
 */
class RefineryService
{
    public const ATHANOR = 35835;
    public const TATARA = 35836;

    /** Athanor + Tatara, the only structures that run plannable chunk extractions. */
    public const REFINERY_TYPE_IDS = [self::ATHANOR, self::TATARA];

    /** SeAT's name for the service module that pulls moon chunks on a refinery. */
    public const MOON_DRILL_SERVICE = 'Moon Drilling';

    /**
     * Why a refinery on the planner or in a blueprint cannot pull, with the
     * words shown when you hover its warning mark. Gone is red; the other two
     * are yellow, because the structure is still there and both can change.
     */
    public const FLAG_GONE = 'gone';
    public const FLAG_UNANCHORING = 'unanchoring';
    public const FLAG_NO_DRILL = 'no_drill';

    /**
     * Only ever set on the Blueprints tab: the refinery's last pull is marked
     * final on the planner, so no blueprint may plan it until that changes.
     */
    public const FLAG_FINAL = 'final';

    public const FLAG_LABELS = [
        self::FLAG_GONE => 'Structure gone, not cleared yet',
        self::FLAG_UNANCHORING => 'Unanchoring in progress',
        self::FLAG_NO_DRILL => 'No moon drill fitted',
        self::FLAG_FINAL => 'Final pull planned. Resume it on the planner, or take it out of this blueprint, before applying',
    ];

    /**
     * Resolved structure_id => moon_id, memoised for the request.
     *
     * @var array<int,int|null>
     */
    protected array $moonIdCache = [];

    /**
     * Every refinery (Athanor/Tatara) belonging to a corporation that has a
     * Moon Drilling service fitted.
     *
     * An Athanor or Tatara without one is a reprocessing or reaction station,
     * not a moon refinery: it cannot pull a chunk, so nothing should plan pulls
     * on it, remind anyone about it or count it as unplanned. SeAT stores a
     * structure's services from the same answer as the structure itself, and
     * removes them when the module comes off, so a refinery with no Moon
     * Drilling row really has no drill. Its state does not matter here: an
     * offline drill is still fitted and comes back when the power does.
     *
     * Each structure comes back with a resolved `moon_id`. SeAT's
     * corporation_structures table has no such column, so callers reading
     * `$refinery->moon_id` straight off the model silently got null forever
     * (Eloquent returns null for an attribute that was never selected, rather
     * than complaining). Resolving it here means every consumer of this method
     * gets a real moon without having to know where moons actually live.
     *
     * For what is physically still there, drill or not, see presentRefineryIds().
     *
     * @return \Illuminate\Support\Collection<int,CorporationStructure>
     */
    public function refineriesForCorporation(int $corporationId): Collection
    {
        $refineries = CorporationStructure::whereIn('type_id', self::REFINERY_TYPE_IDS)
            ->where('corporation_id', $corporationId)
            ->get();

        if ($refineries->isEmpty()) {
            return $refineries;
        }

        $drilled = DB::table('corporation_structure_services')
            ->whereIn('structure_id', $refineries->pluck('structure_id')->all())
            ->where('name', self::MOON_DRILL_SERVICE)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $refineries = $refineries
            ->filter(fn ($refinery) => in_array((int) $refinery->structure_id, $drilled, true))
            ->values();

        if ($refineries->isEmpty()) {
            return $refineries;
        }

        $moonIds = $this->moonIdsForStructures(
            $refineries->pluck('structure_id')->map(fn ($id) => (int) $id)->all()
        );

        foreach ($refineries as $refinery) {
            $refinery->moon_id = $moonIds[(int) $refinery->structure_id] ?? null;
        }

        return $refineries;
    }

    /**
     * Every Athanor and Tatara the corporation still has in SeAT's structure
     * list, with a moon drill or without.
     *
     * Only a refinery missing from this list is gone, and only then may the
     * pulls planned on it or its blueprint slots be taken away. One with its
     * drill unfitted, or being unanchored, is still there and can change back.
     *
     * @return array<int,int>
     */
    public function presentRefineryIds(int $corporationId): array
    {
        return CorporationStructure::whereIn('type_id', self::REFINERY_TYPE_IDS)
            ->where('corporation_id', $corporationId)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * What stops each of these refineries pulling, for the warning marks on
     * the planner and the blueprints. Refineries with nothing wrong are left
     * out.
     *
     * With no refineries on file at all, nothing is marked gone: that is a gap
     * in what SeAT has for the corporation, not every structure going at once.
     *
     * @param  array<int,int>  $structureIds
     * @return array<int,string> structure_id => one of the FLAG_ constants
     */
    public function refineryFlags(int $corporationId, array $structureIds): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $structureIds))));

        if (empty($wanted)) {
            return [];
        }

        $present = CorporationStructure::whereIn('type_id', self::REFINERY_TYPE_IDS)
            ->where('corporation_id', $corporationId)
            ->get(['structure_id', 'unanchors_at'])
            ->keyBy(fn ($structure) => (int) $structure->structure_id);

        $drilled = DB::table('corporation_structure_services')
            ->whereIn('structure_id', $wanted)
            ->where('name', self::MOON_DRILL_SERVICE)
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $flags = [];

        foreach ($wanted as $id) {
            $structure = $present->get($id);

            if (!$structure) {
                if ($present->isNotEmpty()) {
                    $flags[$id] = self::FLAG_GONE;
                }
            } elseif (!empty($structure->unanchors_at)) {
                // SeAT has no unanchoring state: ESI gives the date the timer
                // runs out, and SeAT clears it when the unanchor is cancelled.
                $flags[$id] = self::FLAG_UNANCHORING;
            } elseif (!in_array($id, $drilled, true)) {
                $flags[$id] = self::FLAG_NO_DRILL;
            }
        }

        return $flags;
    }

    /**
     * Refineries with an extraction running right now.
     *
     * @return array<int, int>
     */
    public function running(array $structureIds): array
    {
        return MoonExtraction::whereIn('structure_id', $structureIds)
            ->where('status', 'extracting')
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Refineries being unanchored with no extraction running.
     *
     * SeAT has no unanchoring state. ESI gives an unanchors_at date while the
     * timer runs, and SeAT clears it on the next structure sync when the
     * unanchor is cancelled, so the refinery comes back by itself.
     *
     * @return array<int, int>
     */
    public function unanchoringIdle($refineries, array $running): array
    {
        return $refineries
            ->filter(fn ($refinery) => !empty($refinery->unanchors_at))
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => !in_array($id, $running, true))
            ->values()
            ->all();
    }

    /**
     * Refineries whose moon drill is fitted but not online, most often for want
     * of fuel. Every refinery from refineriesForCorporation() has one fitted.
     *
     * @return array<int, int>
     */
    public function drillsOffline(array $structureIds): array
    {
        return DB::table('corporation_structure_services')
            ->whereIn('structure_id', $structureIds)
            ->where('name', self::MOON_DRILL_SERVICE)
            ->where('state', '!=', 'online')
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Refineries the game has reported destroyed.
     *
     * SeAT drops a destroyed structure from the corporation's list on its next
     * structure sync. If that sync is not running, the list goes stale and a
     * dead refinery would keep being reminded about, so this is checked before
     * anything is sent. A destroyed structure's id is never used again, which
     * is why any report at all is enough.
     *
     * @return array<int, int>
     */
    public function reportedDestroyed(array $structureIds): array
    {
        if (!$structureIds) {
            return [];
        }

        try {
            $query = DB::table('character_notifications')->where('type', 'StructureDestroyed');
            $reports = StructureNotificationText::whereMayMention($query, $structureIds)->get(['text']);
        } catch (\Throwable $e) {
            return [];
        }

        $destroyed = [];

        foreach ($reports as $report) {
            foreach ($structureIds as $structureId) {
                if (StructureNotificationText::mentions((string) $report->text, $structureId)) {
                    $destroyed[$structureId] = true;
                }
            }
        }

        return array_keys($destroyed);
    }

    /**
     * Refinery, system and moon names for a message. SeAT keeps a structure in
     * universe_structures after it leaves the corporation's list, which is why
     * it is read there.
     */
    public function names(int $structureId): array
    {
        $row = DB::table('universe_structures')
            ->where('structure_id', $structureId)
            ->first(['name', 'solar_system_id']);

        $system = $row && $row->solar_system_id
            ? DB::table('solar_systems')->where('system_id', $row->solar_system_id)->value('name')
            : null;

        $moonId = $this->resolveMoonId($structureId);
        $moon = $moonId ? DB::table('moons')->where('moon_id', $moonId)->value('name') : null;

        return array_filter([
            'structure_name' => $row->name ?? "Structure {$structureId}",
            'system_name' => $system,
            'moon_name' => $moon ?? ($moonId ? "Moon {$moonId}" : null),
        ], fn ($value) => $value !== null);
    }

    /**
     * Whether each refinery is an Athanor or a Tatara, which decides the moon
     * rigs it can take. From the corporation's own structures first, then
     * SeAT's universe copy, which also covers another corporation's refinery
     * our extractions point at.
     *
     * @param array<int, int> $structureIds
     * @return array<int, int> structure_id => hull type id
     */
    public function hullTypes(array $structureIds): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $structureIds))));

        if (!$wanted) {
            return [];
        }

        $types = [];

        foreach (['corporation_structures', 'universe_structures'] as $table) {
            $missing = array_values(array_diff($wanted, array_keys($types)));
            if (!$missing) {
                break;
            }

            try {
                foreach (DB::table($table)->whereIn('structure_id', $missing)->pluck('type_id', 'structure_id') as $id => $type) {
                    if ($type) {
                        $types[(int) $id] = (int) $type;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Mining Manager: could not read refinery types from {$table}: " . $e->getMessage());
            }
        }

        return $types;
    }

    /**
     * The moon drilling rigs fitted in each refinery's rig slots, as type ids.
     *
     * Read from SeAT's copy of the corporation's assets: a fitted rig sits in
     * the structure with a RigSlot flag, while a spare one in a hangar has a
     * different flag and does not count. That copy needs a Director token
     * with the corporation assets scope, and another corporation's refinery
     * never shows up in it, so an empty answer means "nothing seen", not
     * "nothing fitted". A chunk's own timers say which timer rig it had; see
     * MoonDrillingRigs::timerTier().
     *
     * @param array<int, int> $structureIds
     * @return array<int, array<int, int>> structure_id => moon rig type ids
     */
    public function fittedMoonRigs(array $structureIds): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $structureIds))));

        if (!$wanted) {
            return [];
        }

        try {
            $rows = DB::table('corporation_assets')
                ->whereIn('location_id', $wanted)
                ->where('location_flag', 'LIKE', 'RigSlot%')
                ->whereIn('type_id', array_keys(MoonDrillingRigs::RIGS))
                ->get(['location_id', 'type_id']);
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not read fitted moon rigs: ' . $e->getMessage());

            return [];
        }

        $fitted = [];

        foreach ($rows as $row) {
            $fitted[(int) $row->location_id][] = (int) $row->type_id;
        }

        return $fitted;
    }

    /**
     * Whether SeAT can see each refinery's assets at all. A structure in use
     * always holds something (fuel, service modules), so no rows at all means
     * SeAT cannot see in, rather than an empty refinery.
     *
     * @param array<int, int> $structureIds
     * @return array<int, bool>
     */
    public function assetsVisible(array $structureIds): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $structureIds))));

        if (!$wanted) {
            return [];
        }

        try {
            $seen = DB::table('corporation_assets')
                ->whereIn('location_id', $wanted)
                ->distinct()
                ->pluck('location_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            $seen = [];
        }

        $visible = [];

        foreach ($wanted as $id) {
            $visible[$id] = in_array($id, $seen, true);
        }

        return $visible;
    }

    /**
     * The record an extraction keeps of the rigs it was pulled with: the moon
     * rig type ids seen in the rig slots, whether SeAT could see the
     * refinery's assets at all, and when it looked.
     *
     * A look that cannot see in never replaces one that could: a Director
     * token lapsing halfway through an extraction must not wipe what was
     * already seen.
     */
    public function rigSnapshot(int $structureId, ?array $previous = null): array
    {
        $visible = $this->assetsVisible([$structureId])[$structureId] ?? false;

        if (!$visible && is_array($previous) && !empty($previous['assets_visible'])) {
            return $previous;
        }

        return [
            'rigs' => $this->fittedMoonRigs([$structureId])[$structureId] ?? [],
            'assets_visible' => $visible,
            'seen_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * What the fitted moon rigs add up to on each refinery. A refinery with
     * none seen still gets an entry, with every bonus at zero.
     *
     * @param array<int, int> $structureIds
     * @return array<int, array> structure_id => MoonDrillingRigs::summarise()
     */
    public function fittedRigSummary(array $structureIds): array
    {
        $fitted = $this->fittedMoonRigs($structureIds);
        $summary = [];

        foreach (array_values(array_unique(array_filter(array_map('intval', $structureIds)))) as $id) {
            $summary[$id] = MoonDrillingRigs::summarise($fitted[$id] ?? []);
        }

        return $summary;
    }

    /**
     * The moon a refinery is anchored on, or null if nothing knows yet.
     *
     * An Upwell structure is anchored on exactly one moon and cannot move, so
     * this mapping is stable once anything has observed it.
     */
    public function resolveMoonId(int $structureId): ?int
    {
        return $this->moonIdsForStructures([$structureId])[$structureId] ?? null;
    }

    /**
     * Bulk structure_id => moon_id.
     *
     * Three sources, in order of how much we trust them to be current:
     * our own live extractions, our archived history, and finally SeAT's raw
     * extraction table for a refinery we have not imported yet. A refinery
     * that has never run an extraction resolves to null, which is a legitimate
     * answer and why the plan column is nullable.
     *
     * @param  array<int,int>  $structureIds
     * @return array<int,int|null>
     */
    public function moonIdsForStructures(array $structureIds): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $structureIds))));

        if (empty($wanted)) {
            return [];
        }

        $resolved = [];
        $outstanding = [];

        foreach ($wanted as $id) {
            if (array_key_exists($id, $this->moonIdCache)) {
                $resolved[$id] = $this->moonIdCache[$id];
            } else {
                $outstanding[] = $id;
            }
        }

        if (empty($outstanding)) {
            return $resolved;
        }

        // Ordered oldest first on purpose: pluck() keys by structure_id and the
        // last row processed wins, so ascending order leaves the most recently
        // observed moon in place. A refinery's moon never changes, but a
        // structure id can be reused after an unanchor, and the newest
        // observation is the right answer if it ever is.
        $lookups = [
            fn (array $ids) => MoonExtraction::whereIn('structure_id', $ids)
                ->whereNotNull('moon_id')
                ->orderBy('chunk_arrival_time')
                ->pluck('moon_id', 'structure_id'),

            fn (array $ids) => MoonExtractionHistory::whereIn('structure_id', $ids)
                ->whereNotNull('moon_id')
                ->orderBy('chunk_arrival_time')
                ->pluck('moon_id', 'structure_id'),

            fn (array $ids) => DB::table('corporation_industry_mining_extractions')
                ->whereIn('structure_id', $ids)
                ->whereNotNull('moon_id')
                ->orderBy('chunk_arrival_time')
                ->pluck('moon_id', 'structure_id'),
        ];

        foreach ($lookups as $lookup) {
            if (empty($outstanding)) {
                break;
            }

            try {
                foreach ($lookup($outstanding) as $structureId => $moonId) {
                    $resolved[(int) $structureId] = (int) $moonId;
                }
            } catch (\Exception $e) {
                Log::warning('Mining Manager: a moon lookup failed, falling through to the next source', [
                    'error' => $e->getMessage(),
                ]);
            }

            $outstanding = array_values(array_filter(
                $outstanding,
                fn ($id) => !isset($resolved[$id])
            ));
        }

        // Remember the misses too, so a refinery with no extraction history
        // does not re-run all three lookups on every call within a request.
        foreach ($outstanding as $id) {
            $resolved[$id] = null;
        }

        foreach ($wanted as $id) {
            $this->moonIdCache[$id] = $resolved[$id] ?? null;
        }

        return $resolved;
    }
}
