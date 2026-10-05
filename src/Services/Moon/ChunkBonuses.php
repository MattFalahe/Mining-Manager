<?php

namespace MiningManager\Services\Moon;

/**
 * What the moon drilling rigs did for one chunk, for the extraction page.
 *
 * The timer rig is read from the chunk itself and always known. The yield is
 * known in two ways: on a Tatara the one Proficiency rig carries both, so the
 * chunk's timer tier is also its yield tier; on an Athanor the Efficiency rig
 * is separate, so only the record of what was fitted can say, and when SeAT
 * could not see the refinery's fittings the yield is left unknown rather than
 * guessed.
 *
 * The extra the yield rig brought is worked back out of the game's own
 * figures: the chunk's volume already includes the bonus, so the share it
 * added is volume x bonus / (100 + bonus).
 */
final class ChunkBonuses
{
    /**
     * @param \MiningManager\Models\MoonExtraction|\MiningManager\Models\MoonExtractionHistory $extraction
     * @param int|null $hullType the refinery's type id, Athanor or Tatara, when known
     * @param float $volumeM3 the chunk's ore volume as the game reported it
     * @param float $value what that ore is worth
     * @return array{timer_tier: int, timer_rig: ?string, timer_rig_type: ?int, window_hours: int, auto_fracture_minutes: float, yield: ?float, yield_rig: ?string, yield_rig_type: ?int, extra_m3: ?float, extra_value: ?float, seen_at: ?string}
     */
    public static function describe($extraction, ?int $hullType, float $volumeM3, float $value): array
    {
        $timerTier = $extraction->timerRigTier();
        $record = $extraction->moonRigSummary();

        $timerRig = null;
        $timerType = null;
        if ($timerTier > 0) {
            $kind = $hullType === RefineryService::TATARA ? MoonDrillingRigs::PROFICIENCY
                : ($hullType === RefineryService::ATHANOR ? MoonDrillingRigs::STABILITY : null);
            $timerType = $kind ? MoonDrillingRigs::typeFor($kind, $timerTier) : null;
            $timerRig = $timerType
                ? MoonDrillingRigs::RIGS[$timerType]['name']
                : 'Moon drilling rig, Tech ' . ($timerTier === 1 ? 'I' : 'II');
        }

        $yield = null;
        $yieldRig = null;
        $yieldType = null;

        if ($hullType === RefineryService::TATARA) {
            $yield = MoonDrillingRigs::YIELD_BONUS[$timerTier];
            $yieldRig = $timerTier > 0 ? $timerRig : null;
            $yieldType = $timerTier > 0 ? $timerType : null;
        } elseif ($record && $record['assets_visible']) {
            $yield = $record['yield'];
            foreach ($record['rigs'] as $rig) {
                if ((MoonDrillingRigs::RIGS[$rig['type_id']]['yield'] ?? 0) > 0) {
                    $yieldRig = $rig['name'];
                    $yieldType = (int) $rig['type_id'];
                }
            }
        }

        $extraShare = $yield ? $yield / (100 + $yield) : null;

        return [
            'timer_tier' => $timerTier,
            'timer_rig' => $timerRig,
            'timer_rig_type' => $timerType,
            'window_hours' => $extraction->getReadyDurationHours(),
            'auto_fracture_minutes' => $extraction->getAutoFractureDelayMinutes(),
            'yield' => $yield,
            'yield_rig' => $yieldRig,
            'yield_rig_type' => $yieldType,
            'extra_m3' => $extraShare !== null ? $volumeM3 * $extraShare : null,
            'extra_value' => $extraShare !== null ? $value * $extraShare : null,
            'seen_at' => is_array($extraction->moon_rigs) ? ($extraction->moon_rigs['seen_at'] ?? null) : null,
        ];
    }
}
