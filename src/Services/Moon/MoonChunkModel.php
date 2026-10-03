<?php

namespace MiningManager\Services\Moon;

/**
 * How much ore a moon extraction produces.
 *
 * The simulator, Find Moons and the quality ratings all take the rate from
 * here, so a correction to the model changes every figure at once instead of
 * leaving two pages that disagree.
 *
 * A structure's fitted Moon Drilling Efficiency / Proficiency rig adds a small
 * volume-per-time bonus (+2% / +2.4%, dogma attribute 2710). Callers that know
 * which refinery is drilling pass its multiplier through; callers that only
 * value a moon on its own — Find Moons, quality ratings — leave it at 1.0,
 * because the rig belongs to the structure, not the moon.
 */
final class MoonChunkModel
{
    /**
     * Extraction rate in m³ per hour for a moon whose scanned ore shares add
     * up to $moonOreShare (0.70 for a moon scanned at 70% moon ore).
     *
     * Linear between two observed rates, about 21,000 m³/h at 70% moon ore
     * and 31,000 m³/h at 100%. Below 70% the rate falls in proportion, with a
     * floor of 15,000 m³/h.
     *
     * @param float $yieldMultiplier Moon Drilling Efficiency bonus (1.02 for a T1
     *                               rig); 1.0 leaves the base rate untouched.
     */
    public static function ratePerHour(float $moonOreShare, float $yieldMultiplier = 1.0): int
    {
        if ($moonOreShare >= 0.70) {
            $rate = 21000 + (($moonOreShare - 0.70) / 0.30) * 10000;
        } else {
            $rate = max(15000, 21000 * ($moonOreShare / 0.70));
        }

        return (int) round($rate * max(0.01, $yieldMultiplier));
    }

    /**
     * Total volume in m³ of an extraction lasting $days days.
     *
     * @param float $yieldMultiplier Moon Drilling Efficiency bonus; 1.0 for
     *                               callers with no structure attached.
     */
    public static function volume(float $moonOreShare, int $days, float $yieldMultiplier = 1.0): int
    {
        return (int) round(self::ratePerHour($moonOreShare, $yieldMultiplier) * $days * 24);
    }
}
