<?php

namespace MiningManager\Services\Moon;

use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationStructure;

/**
 * The moon-rig bonuses of a refinery.
 *
 * A refinery's Moon Drilling Stability / Proficiency rig changes three things
 * about the chunks it pulls up, all three carried as dogma attributes on the
 * fitted rig and applied to the hull:
 *
 *   2707  Chunk Stability Bonus            +20 / +24 %   delays auto-fracture
 *   2708  Extracted Asteroid Decay Bonus   +50 / +100 %  extends belt lifetime
 *   2710  Moon Drilling Efficiency         +2 / +2.4 %   raises yield over time
 *
 * The base values live on the hull:
 *   48 h belt lifetime and a 3 h auto-fracture delay.
 *
 * So a Stability/Proficiency I rig gives 72 h and 3.6 h; a II gives 96 h and
 * 3.72 h — the game's documented "up to four days" with a T2 stability rig.
 *
 * These attributes are NOT scaled by the system's security band. The in-game
 * info window and the SDE report the same values everywhere, unlike the
 * reprocessing / manufacturing / combat rigs whose bonuses do vary by space.
 *
 * Read through SeAT's corporation assets (`CorporationStructure::rig_slots`),
 * the same source Structure Manager uses for doctrine compliance, so there is
 * no ESI call and no new scope. Falls back to the base values when an install
 * has no asset sync, or the SDE is missing the attributes.
 */
final class StructureMoonRigs
{
    public const DGM_CHUNK_STABILITY = 2707;
    public const DGM_ASTEROID_DECAY = 2708;
    public const DGM_DRILLING_YIELD = 2710;

    public const BASE_LIFETIME_HOURS = 48;
    public const BASE_AUTO_FRACTURE_MINUTES = 180;

    /**
     * Bonus percent by tier: 1 = Tech I, 2 = Tech II. Mirrors the dogma values
     * (2710 yield, 2708 decay, 2707 stability) so the simulator's manual rig
     * toggles share the resolver's maths.
     */
    public const EFFICIENCY_BONUS = [1 => 2.0, 2 => 2.4];
    public const DECAY_BONUS = [1 => 50.0, 2 => 100.0];
    public const STABILITY_BONUS = [1 => 20.0, 2 => 24.0];

    /**
     * Resolved values by structure id, for the lifetime of the request.
     *
     * @var array<int, array>
     */
    private static array $cache = [];

    /**
     * Bonuses for one structure. Always returns a usable array — a structure
     * with no detectable rig resolves to the in-game base values.
     *
     * @return array{lifetime_hours: int, auto_fracture_minutes: int, yield_multiplier: float, decay_bonus: float, stability_bonus: float, yield_bonus: float, rig_name: ?string, source: string}
     */
    public static function forStructure(?int $structureId): array
    {
        if (!$structureId) {
            return self::base();
        }

        if (!array_key_exists($structureId, self::$cache)) {
            self::$cache[$structureId] = self::resolve($structureId);
        }

        return self::$cache[$structureId];
    }

    /**
     * Short display label for the resolved fit, e.g. "Moon Drilling Stability
     * II". Null when only the base (unrigged) values apply.
     */
    public static function describe(array $rigs): ?string
    {
        return $rigs['rig_name'] ?? null;
    }

    /**
     * @return array
     */
    public static function base(): array
    {
        return self::compose(0.0, 0.0, 0.0);
    }

    /**
     * Build a result from explicit bonus percents. Used by the simulator's
     * manual rig toggles, and as the shared shape for the fitted resolver.
     *
     * @return array{lifetime_hours: int, auto_fracture_minutes: int, yield_multiplier: float, decay_bonus: float, stability_bonus: float, yield_bonus: float, rig_name: ?string, source: string}
     */
    public static function compose(float $decayBonus, float $stabilityBonus, float $yieldBonus, ?string $rigName = null, string $source = 'fitted'): array
    {
        return [
            'lifetime_hours' => (int) round(self::BASE_LIFETIME_HOURS * (1 + $decayBonus / 100)),
            'auto_fracture_minutes' => (int) round(self::BASE_AUTO_FRACTURE_MINUTES * (1 + $stabilityBonus / 100)),
            'yield_multiplier' => 1 + $yieldBonus / 100,
            'decay_bonus' => $decayBonus,
            'stability_bonus' => $stabilityBonus,
            'yield_bonus' => $yieldBonus,
            'rig_name' => $rigName,
            'source' => $source,
        ];
    }

    /**
     * The bonuses a chosen rig tier would give: 0 none, 1 Tech I, 2 Tech II.
     * A null tier counts as none. Used when the simulator overrides the fit.
     */
    public static function fromTiers(?int $efficiencyTier, ?int $stabilityTier): array
    {
        $efficiencyTier = self::tier($efficiencyTier);
        $stabilityTier = self::tier($stabilityTier);

        return self::compose(
            self::DECAY_BONUS[$stabilityTier] ?? 0.0,
            self::STABILITY_BONUS[$stabilityTier] ?? 0.0,
            self::EFFICIENCY_BONUS[$efficiencyTier] ?? 0.0,
            null,
            'manual'
        );
    }

    private static function tier(?int $tier): int
    {
        return in_array((int) $tier, [1, 2], true) ? (int) $tier : 0;
    }

    private static function resolve(int $structureId): array
    {
        try {
            if (!Schema::hasTable('corporation_assets') || !Schema::hasTable('dgmTypeAttributes')) {
                return self::base();
            }

            $structure = CorporationStructure::with([
                'items' => function ($query) {
                    $query->where('location_flag', 'like', 'RigSlot%')
                        ->with('type.dogma_attributes');
                },
            ])->find($structureId);

            if (!$structure) {
                return self::base();
            }

            $decay = 0.0;
            $stability = 0.0;
            $yield = 0.0;
            $rigName = null;

            foreach ($structure->rig_slots as $item) {
                $attributes = optional($item->type)->dogma_attributes;
                if (!$attributes) {
                    continue;
                }

                $decay = max($decay, self::attribute($attributes, self::DGM_ASTEROID_DECAY));
                $stability = max($stability, self::attribute($attributes, self::DGM_CHUNK_STABILITY));
                $yield = max($yield, self::attribute($attributes, self::DGM_DRILLING_YIELD));

                if ($decay > 0 && $rigName === null) {
                    $rigName = $item->type->typeName ?? null;
                }
            }

            if ($decay <= 0 && $stability <= 0 && $yield <= 0) {
                return self::base();
            }

            return self::compose($decay, $stability, $yield, $rigName, 'fitted');
        } catch (\Throwable $e) {
            // A broken asset mirror or a missing SDE table must never take the
            // moon pages down; fall back to the base values and carry on.
            return self::base();
        }
    }

    /**
     * One dogma attribute's value from a rig's attribute collection.
     *
     * @param \Illuminate\Support\Collection $attributes
     */
    private static function attribute($attributes, int $attributeId): float
    {
        $row = $attributes->firstWhere('attributeID', $attributeId);

        return $row ? (float) $row->valueFloat : 0.0;
    }
}
