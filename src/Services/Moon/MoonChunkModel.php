<?php

namespace MiningManager\Services\Moon;

/**
 * How much ore a moon extraction produces.
 *
 * The simulator, Find Moons and the quality ratings all take the rate from
 * here, so a correction to the model changes every figure at once instead of
 * leaving two pages that disagree.
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
     */
    public static function ratePerHour(float $moonOreShare): int
    {
        if ($moonOreShare >= 0.70) {
            $rate = 21000 + (($moonOreShare - 0.70) / 0.30) * 10000;
        } else {
            $rate = max(15000, 21000 * ($moonOreShare / 0.70));
        }

        return (int) round($rate);
    }

    /**
     * Total volume in m³ of an extraction lasting $days days.
     */
    public static function volume(float $moonOreShare, int $days): int
    {
        return self::ratePerHour($moonOreShare) * $days * 24;
    }
}
