<?php

namespace MiningManager\Services\Moon;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads what's currently sitting in every Metenox Moon Drill's MoonMaterialBay.
 *
 * Data source: SeAT's `corporation_assets` table (mirrored from ESI's
 * `/corporations/{corp_id}/assets/` endpoint), joined to the corp-owned
 * `corporation_structures` to filter to Metenox type, plus `universe_structures`
 * for the operator-visible name and the SDE's `invTypes` for the human-readable
 * ore name.
 *
 * Read-only — never writes. Refresh cadence is whatever SeAT's existing
 * corp-assets poller does (~1h ESI cache).
 *
 * Permission model: caller is expected to be gated by `mining-manager.director`
 * middleware. The service itself filters by corporation_id when supplied, so
 * per-corp visibility is enforced by passing only the corps the viewer can
 * actually see.
 */
class MetenoxCargoService
{
    /**
     * Metenox Moon Drill type ID. Mirrored from
     * \StructureManager\Helpers\TypeIdRegistry::METENOX to keep MM
     * standalone-installable (no hard dependency on Structure Manager).
     */
    public const METENOX_TYPE_ID = 81826;

    /**
     * Location flag CCP uses for the Metenox's moon-ore output bay.
     * Confirmed empirically against live data on a v2.0.0 SeAT install
     * (2026-05-26); rows in corporation_assets with this flag, scoped to
     * type_id=81826 structures, are the only contents of the drilling bay.
     */
    public const MOON_MATERIAL_BAY_FLAG = 'MoonMaterialBay';

    /**
     * Metenox Moon Drill MoonMaterialBay capacity in m³.
     *
     * Source: dgmTypeAttributes.attributeID=5693 on typeID=81826 returns
     * 500,000. This attribute is Metenox-only (does not appear on Athanor
     * or Tatara), which fits — MoonMaterialBay is a Metenox-specific bay
     * for passive long-term accumulation; refineries process chunks
     * through a different output bay altogether.
     *
     * Confirmed against EVE Ref (https://everef.net/types/81826) which
     * lists "Moon Material Output Bay Capacity: 500,000 m³". SeAT v5
     * doesn't bundle dgmAttributeTypes (the catalog of attribute names)
     * so we identified the attribute by its Metenox-only presence + the
     * value matching the published spec.
     *
     * Operational implication: at typical Metenox production rates
     * (~1,500-2,000 m³/hour depending on moon richness), 500,000 m³ fills
     * in ~10-14 days. The default 85% notification threshold = ~425,000
     * m³ = ~10 days into a 12-day cycle = ~2 days operator lead time
     * before the bay caps and drilling stops.
     *
     * Note: attribute 552 (general structure cargo capacity — Athanor
     * 50k / Metenox 32k / Tatara 100k) was an earlier guess; that's the
     * structure's basic cargo hold, NOT the MoonMaterialBay.
     */
    public const MOON_MATERIAL_BAY_CAPACITY_M3 = 500000;

    /**
     * Standard volume of every moon material in m³ per unit.
     *
     * Confirmed 2026-05-27 via:
     *   SELECT volume FROM invTypes WHERE typeID IN (16633, 16634, 16635,
     *                                                 16636, 16640, 16641);
     * All R4-R64 moon materials in EVE share this volume (CCP convention).
     * Kept as a constant for fast in-PHP m³ computation; the per-type
     * SQL JOIN against invTypes.volume in forCorporation() is the
     * defensive path in case CCP ever introduces a different-volume
     * moon material type.
     */
    public const MOON_MATERIAL_VOLUME_M3 = 0.05;

    /**
     * Return every ore-stack row across the corp's Metenox drills.
     *
     * Each row in the result represents one type_id stack in one drill's
     * MoonMaterialBay:
     *   - structure_id      (Metenox structure id)
     *   - structure_name    (universe_structures.name; may be null for newly-onlined drills)
     *   - system_id         (solar system the drill is in)
     *   - type_id           (the ore variant)
     *   - type_name         (human-readable name from invTypes)
     *   - total_quantity    (units in cargo)
     *   - updated_at        (when SeAT last polled this asset row from ESI)
     *
     * Ordered by structure_id then quantity descending so blade can group by
     * drill in a single pass.
     *
     * @return Collection<int,\stdClass>
     */
    public function forCorporation(int $corporationId): Collection
    {
        return DB::table('corporation_assets as ca')
            ->select([
                'ca.location_id as structure_id',
                'us.name as structure_name',
                'cs.system_id',
                'ss.name as system_name',
                'ca.type_id',
                'it.typeName as type_name',
                DB::raw('SUM(ca.quantity) as total_quantity'),
                DB::raw('MAX(ca.updated_at) as updated_at'),
            ])
            ->join('corporation_structures as cs', 'cs.structure_id', '=', 'ca.location_id')
            ->leftJoin('universe_structures as us', 'us.structure_id', '=', 'ca.location_id')
            ->leftJoin('solar_systems as ss', 'ss.system_id', '=', 'cs.system_id')
            ->leftJoin('invTypes as it', 'it.typeID', '=', 'ca.type_id')
            ->where('ca.corporation_id', $corporationId)
            ->where('ca.location_flag', self::MOON_MATERIAL_BAY_FLAG)
            ->where('cs.type_id', self::METENOX_TYPE_ID)
            ->groupBy('ca.location_id', 'us.name', 'cs.system_id', 'ss.name', 'ca.type_id', 'it.typeName')
            ->orderBy('ca.location_id')
            ->orderByDesc('total_quantity')
            ->get();
    }

    /**
     * Return per-Metenox summary rows for the corp (one row per drill,
     * regardless of how many ore types are in it).
     *
     * Used by the page header card grid: each card needs one summary row
     * (structure name, system, distinct ore types, total units + m³,
     * fill %, last poll). The m³ and fill_pct are computed in PHP after
     * the SQL because the JOIN to invTypes.volume already happens in
     * forCorporation() and we want to avoid duplicating it; here we use
     * the MOON_MATERIAL_VOLUME_M3 constant for the multiplication.
     *
     * @return Collection<int,\stdClass>
     */
    public function summaryForCorporation(int $corporationId): Collection
    {
        $rows = DB::table('corporation_assets as ca')
            ->select([
                'ca.location_id as structure_id',
                'us.name as structure_name',
                'cs.system_id',
                'ss.name as system_name',
                DB::raw('COUNT(DISTINCT ca.type_id) as distinct_ore_types'),
                DB::raw('SUM(ca.quantity) as total_units'),
                DB::raw('MAX(ca.updated_at) as last_polled_at'),
            ])
            ->join('corporation_structures as cs', 'cs.structure_id', '=', 'ca.location_id')
            ->leftJoin('universe_structures as us', 'us.structure_id', '=', 'ca.location_id')
            ->leftJoin('solar_systems as ss', 'ss.system_id', '=', 'cs.system_id')
            ->where('ca.corporation_id', $corporationId)
            ->where('ca.location_flag', self::MOON_MATERIAL_BAY_FLAG)
            ->where('cs.type_id', self::METENOX_TYPE_ID)
            ->groupBy('ca.location_id', 'us.name', 'cs.system_id', 'ss.name')
            ->orderByDesc('total_units')
            ->get();

        // Decorate each row with computed m³ + fill % so the blade can
        // render the cargo capacity bar without doing math.
        foreach ($rows as $row) {
            $row->total_m3       = (float) ($row->total_units * self::MOON_MATERIAL_VOLUME_M3);
            $row->capacity_m3    = (float) self::MOON_MATERIAL_BAY_CAPACITY_M3;
            $row->fill_pct       = $row->capacity_m3 > 0
                ? round(min(100, $row->total_m3 / $row->capacity_m3 * 100), 1)
                : 0.0;
            // Fill state is what drives the colour coding in the UI.
            // Aligned with the notification threshold default (85%) so
            // operators see a visual warning at the same point the
            // notification would fire.
            if ($row->fill_pct >= 85) {
                $row->fill_state = 'critical';
            } elseif ($row->fill_pct >= 60) {
                $row->fill_state = 'warning';
            } else {
                $row->fill_state = 'ok';
            }
        }

        return $rows;
    }

    /**
     * Compute the page-wide average fill % across every visible drill for
     * a corporation. Returns null when the corp has no drills (so the UI
     * can hide the chip instead of showing "0%").
     */
    public function averageFillPctForCorporation(int $corporationId): ?float
    {
        $summaries = $this->summaryForCorporation($corporationId);
        if ($summaries->isEmpty()) {
            return null;
        }
        return round((float) $summaries->avg('fill_pct'), 1);
    }

    /**
     * Return every known Metenox for the corp, including ones with empty
     * cargo bays (those won't appear in forCorporation() because there are
     * no asset rows to join against).
     *
     * Lets the UI show "Drill X — empty (pull not needed yet)" cards.
     *
     * @return Collection<int,\stdClass>
     */
    public function knownStructuresForCorporation(int $corporationId): Collection
    {
        return DB::table('corporation_structures as cs')
            ->select([
                'cs.structure_id',
                'us.name as structure_name',
                'cs.system_id',
                'ss.name as system_name',
                'cs.fuel_expires',
                'cs.state',
            ])
            ->leftJoin('universe_structures as us', 'us.structure_id', '=', 'cs.structure_id')
            ->leftJoin('solar_systems as ss', 'ss.system_id', '=', 'cs.system_id')
            ->where('cs.corporation_id', $corporationId)
            ->where('cs.type_id', self::METENOX_TYPE_ID)
            ->orderBy('cs.system_id')
            ->orderBy('cs.structure_id')
            ->get();
    }

    /**
     * Cargo snapshot for a single Metenox structure as a flat
     * [type_id => quantity] array. Used by the PluginBridge capability
     * (`mining.metenox.cargoSnapshot`) so Structure Manager or future
     * consumers can read the drill's contents without bouncing through
     * MM's UI layer.
     *
     * Returns null when the structure is not a Metenox or has no recorded
     * cargo (lets consumers distinguish "unknown structure" from "empty
     * drill" if they wrap with knownStructuresForCorporation()).
     *
     * @return array<int,int>|null
     */
    public function cargoSnapshot(int $structureId): ?array
    {
        // First confirm the structure is a Metenox — protects callers that
        // pass an arbitrary structure_id from getting accidental matches
        // on stray rows in corporation_assets.
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->where('type_id', self::METENOX_TYPE_ID)
            ->first(['structure_id']);

        if (!$structure) {
            return null;
        }

        $rows = DB::table('corporation_assets')
            ->select(['type_id', DB::raw('SUM(quantity) as qty')])
            ->where('location_id', $structureId)
            ->where('location_flag', self::MOON_MATERIAL_BAY_FLAG)
            ->groupBy('type_id')
            ->get();

        if ($rows->isEmpty()) {
            // Structure exists and is a Metenox, but bay is empty. Returning
            // an empty array distinguishes this from "unknown structure"
            // (null), which is the more useful API contract for callers.
            return [];
        }

        return $rows->pluck('qty', 'type_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    /**
     * Return every corporation_id that owns at least one Metenox drill.
     *
     * Used by the page-level corp picker for admins: SeAT-wide admins
     * (`mining-manager.admin`) get to see ALL corps with Metenoxes, not
     * just the configured Moon Owner Corp. Directors (non-admin) stay
     * scoped to the moon-owner corp upstream in the controller.
     *
     * The lookup reads `corporation_structures` (the corp-asset structure
     * mirror SeAT already maintains) so it picks up every drill SeAT has
     * ever seen for any configured corp, even if the bay is empty right
     * now. Returns an empty array when no drills exist across the install.
     *
     * Decorated rows include the corp_id + corp_name (resolved from
     * corporation_infos) so the picker can render readable labels.
     *
     * @return Collection<int,\stdClass>  rows: corporation_id, name, drill_count
     */
    public function corporationsWithMetenoxes(): Collection
    {
        return DB::table('corporation_structures as cs')
            ->select([
                'cs.corporation_id',
                DB::raw('COALESCE(ci.name, CONCAT("Corporation #", cs.corporation_id)) as name'),
                DB::raw('COUNT(*) as drill_count'),
            ])
            ->leftJoin('corporation_infos as ci', 'ci.corporation_id', '=', 'cs.corporation_id')
            ->where('cs.type_id', self::METENOX_TYPE_ID)
            ->groupBy('cs.corporation_id', 'ci.name')
            ->orderBy('name')
            ->get();
    }
}
