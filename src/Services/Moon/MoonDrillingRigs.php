<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;

/**
 * The moon drilling rigs and what they do to a chunk.
 *
 * An Athanor takes two: Moon Drilling Efficiency for the yield and Moon
 * Drilling Stability for the timers. A Tatara gets all of it from one Moon
 * Drilling Proficiency rig. A structure holds one rig of each kind at most,
 * Tech I and Tech II counting as the same kind.
 *
 * The values live here by type id rather than being read from the SDE tables,
 * the same as the plugin's other ore and moon rules.
 *
 * Where each bonus lands in Mining Manager's chunk cycle:
 *   - auto-fracture: 3 hours after arrival, +20% / +24%. EVE already puts it
 *     in the auto-fracture time it gives us, so it only matters where that
 *     time is missing.
 *   - mining window: 48 hours from fracture, +50% / +100%, so 72 or 96 hours.
 *     The 2 hour unstable tail after it stays 2 hours.
 *   - yield: +2% / +2.4%. Already in the ore volumes EVE's notices carry.
 */
final class MoonDrillingRigs
{
    public const EFFICIENCY = 'efficiency';
    public const STABILITY = 'stability';
    public const PROFICIENCY = 'proficiency';

    public const BASE_AUTO_FRACTURE_MINUTES = 180;
    public const BASE_READY_HOURS = 48;
    public const UNSTABLE_HOURS = 2;

    /**
     * The longest a chunk can last from arrival: a Tech II rig's 3h 43m to
     * fracture on its own, 96 hours of mining and the 2 hour tail. Look-backs
     * that have to catch every live chunk reach this far.
     */
    public const LONGEST_CHUNK_HOURS = 102;

    /**
     * Every moon drilling rig, by type id. Bonuses in percent.
     */
    public const RIGS = [
        46323 => ['kind' => self::EFFICIENCY, 'tier' => 1, 'name' => 'Standup M-Set Moon Drilling Efficiency I', 'yield' => 2.0, 'auto_fracture' => 0.0, 'belt' => 0.0],
        46324 => ['kind' => self::EFFICIENCY, 'tier' => 2, 'name' => 'Standup M-Set Moon Drilling Efficiency II', 'yield' => 2.4, 'auto_fracture' => 0.0, 'belt' => 0.0],
        46325 => ['kind' => self::STABILITY, 'tier' => 1, 'name' => 'Standup M-Set Moon Drilling Stability I', 'yield' => 0.0, 'auto_fracture' => 20.0, 'belt' => 50.0],
        46326 => ['kind' => self::STABILITY, 'tier' => 2, 'name' => 'Standup M-Set Moon Drilling Stability II', 'yield' => 0.0, 'auto_fracture' => 24.0, 'belt' => 100.0],
        46327 => ['kind' => self::PROFICIENCY, 'tier' => 1, 'name' => 'Standup L-Set Moon Drilling Proficiency I', 'yield' => 2.0, 'auto_fracture' => 20.0, 'belt' => 50.0],
        46328 => ['kind' => self::PROFICIENCY, 'tier' => 2, 'name' => 'Standup L-Set Moon Drilling Proficiency II', 'yield' => 2.4, 'auto_fracture' => 24.0, 'belt' => 100.0],
    ];

    /**
     * The rig kinds each refinery hull takes: medium rigs on an Athanor
     * (35835), large on a Tatara (35836).
     */
    public const KINDS_BY_HULL = [
        35835 => [self::EFFICIENCY, self::STABILITY],
        35836 => [self::PROFICIENCY],
    ];

    /**
     * Bonuses by the tier of the rig carrying them: 0 none, 1 Tech I, 2 Tech II.
     * The timer bonuses come from Stability or Proficiency, the yield from
     * Efficiency or Proficiency.
     */
    public const AUTO_FRACTURE_BONUS = [0 => 0.0, 1 => 20.0, 2 => 24.0];
    public const BELT_BONUS = [0 => 0.0, 1 => 50.0, 2 => 100.0];
    public const YIELD_BONUS = [0 => 0.0, 1 => 2.0, 2 => 2.4];

    /**
     * The tier of the rig that set a chunk's timers, read from the auto-fracture
     * time EVE scheduled for it: 180 minutes after arrival unrigged, 216 with a
     * Tech I rig, 223 with a Tech II. The cut-offs sit halfway between, so a
     * minute of rounding cannot tip it either way.
     *
     * This is what the game applied to that very chunk, which is why it decides
     * the chunk's mining window rather than whatever is fitted now. Null when a
     * time is missing or the gap is not one any fit produces.
     *
     * @param \DateTimeInterface|string|null $arrival
     * @param \DateTimeInterface|string|null $autoFracture
     */
    public static function timerTier($arrival, $autoFracture): ?int
    {
        if (!$arrival || !$autoFracture) {
            return null;
        }

        $minutes = (Carbon::parse($autoFracture)->getTimestamp() - Carbon::parse($arrival)->getTimestamp()) / 60;

        if ($minutes < 150 || $minutes > 260) {
            return null;
        }

        if ($minutes < 198) {
            return 0;
        }

        return $minutes < 219.6 ? 1 : 2;
    }

    /**
     * Hours of mining after fracture, before the unstable tail.
     */
    public static function readyHours(int $timerTier): int
    {
        return (int) round(self::BASE_READY_HOURS * (1 + (self::BELT_BONUS[$timerTier] ?? 0.0) / 100));
    }

    /**
     * Minutes from arrival until the chunk fractures on its own.
     */
    public static function autoFractureMinutes(int $timerTier): float
    {
        return self::BASE_AUTO_FRACTURE_MINUTES * (1 + (self::AUTO_FRACTURE_BONUS[$timerTier] ?? 0.0) / 100);
    }

    /**
     * What a set of fitted moon rigs adds up to. Type ids that are not moon
     * drilling rigs are ignored.
     *
     * @param array<int, int> $typeIds
     * @return array{timer_tier: int, yield_tier: int, yield: float, auto_fracture: float, belt: float, rigs: array<int, array{type_id: int, name: string, kind: string, tier: int}>}
     */
    public static function summarise(array $typeIds): array
    {
        $timerTier = 0;
        $yieldTier = 0;
        $rigs = [];

        foreach (array_unique(array_map('intval', $typeIds)) as $typeId) {
            $rig = self::RIGS[$typeId] ?? null;
            if (!$rig) {
                continue;
            }

            $rigs[] = ['type_id' => $typeId, 'name' => $rig['name'], 'kind' => $rig['kind'], 'tier' => $rig['tier']];

            if ($rig['belt'] > 0) {
                $timerTier = max($timerTier, $rig['tier']);
            }
            if ($rig['yield'] > 0) {
                $yieldTier = max($yieldTier, $rig['tier']);
            }
        }

        return [
            'timer_tier' => $timerTier,
            'yield_tier' => $yieldTier,
            'yield' => self::YIELD_BONUS[$yieldTier],
            'auto_fracture' => self::AUTO_FRACTURE_BONUS[$timerTier],
            'belt' => self::BELT_BONUS[$timerTier],
            'rigs' => $rigs,
        ];
    }

    /**
     * The rig of a kind at a tier, or null for none.
     */
    public static function typeFor(string $kind, int $tier): ?int
    {
        foreach (self::RIGS as $typeId => $rig) {
            if ($rig['kind'] === $kind && $rig['tier'] === $tier) {
                return $typeId;
            }
        }

        return null;
    }
}
