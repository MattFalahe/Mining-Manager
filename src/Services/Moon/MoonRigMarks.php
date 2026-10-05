<?php

namespace MiningManager\Services\Moon;

/**
 * The moon rig marks Moon Analytics puts on refineries and chunks.
 *
 * A refinery is marked with what it has fitted now. A chunk is marked with
 * what it was pulled with, read from its own timer and the record kept with
 * it, because rigs can be fitted or pulled at any time and today's fit says
 * nothing about last month's chunks.
 */
final class MoonRigMarks
{
    /**
     * "Stability I" for "Standup M-Set Moon Drilling Stability I", where the
     * full name does not fit.
     */
    public static function shortName(?int $typeId): ?string
    {
        $rig = $typeId ? (MoonDrillingRigs::RIGS[$typeId] ?? null) : null;

        return $rig ? ucfirst($rig['kind']) . ' ' . ($rig['tier'] === 2 ? 'II' : 'I') : null;
    }

    /**
     * The moon rigs a refinery has fitted now, by short name. An empty list
     * when SeAT can see its fittings and there are none, null when it cannot
     * see them at all.
     *
     * @param array|null $summary MoonDrillingRigs::summarise() of what is fitted
     * @return array<int, string>|null
     */
    public static function fitted(?array $summary, bool $visible): ?array
    {
        if (!$visible) {
            return null;
        }

        return array_values(array_filter(array_map(
            fn ($rig) => self::shortName((int) $rig['type_id']),
            $summary['rigs'] ?? []
        )));
    }

    /**
     * The rigs one chunk was pulled with: their short names and a line saying
     * what they did to it.
     *
     * @param \MiningManager\Models\MoonExtraction|\MiningManager\Models\MoonExtractionHistory $extraction
     * @param int|null $hullType the refinery's type id, Athanor or Tatara, when known
     * @return array{rigged: bool, short: array<int, string>, text: string}
     */
    public static function chunk($extraction, ?int $hullType): array
    {
        $bonuses = ChunkBonuses::describe($extraction, $hullType, 0.0, 0.0);
        $short = [];
        $parts = [];

        if ($bonuses['timer_tier'] > 0) {
            $name = self::shortName($bonuses['timer_rig_type'])
                ?? trans('mining-manager::analytics.rig_mark_unknown_hull', ['tier' => $bonuses['timer_tier'] === 2 ? 'II' : 'I']);
            $short[] = $name;

            // A Tatara's one Proficiency rig carries the timers and the yield.
            $parts[] = $bonuses['yield_rig_type'] && $bonuses['yield_rig_type'] === $bonuses['timer_rig_type']
                ? trans('mining-manager::analytics.rig_mark_window_yield', [
                    'rig' => $name,
                    'hours' => $bonuses['window_hours'],
                    'bonus' => self::percent($bonuses['yield']),
                ])
                : trans('mining-manager::analytics.rig_mark_window', ['rig' => $name, 'hours' => $bonuses['window_hours']]);
        }

        if ($bonuses['yield_rig_type'] && $bonuses['yield_rig_type'] !== $bonuses['timer_rig_type']) {
            $name = self::shortName($bonuses['yield_rig_type']);
            $short[] = $name;
            $parts[] = trans('mining-manager::analytics.rig_mark_yield', ['rig' => $name, 'bonus' => self::percent($bonuses['yield'])]);
        }

        // An Athanor's yield rig is only known from the record, and SeAT may
        // not have been able to see its fittings.
        if ($bonuses['yield'] === null) {
            $parts[] = $parts
                ? trans('mining-manager::analytics.rig_mark_yield_unknown')
                : trans('mining-manager::analytics.rig_mark_no_timer_yield_unknown');
        } elseif (!$parts) {
            $parts[] = trans('mining-manager::analytics.rig_mark_none');
        }

        return [
            'rigged' => $short !== [],
            'short' => $short,
            'text' => implode('; ', $parts),
        ];
    }

    /**
     * 2.0 as "2", 2.4 as "2.4".
     */
    private static function percent(?float $bonus): string
    {
        return rtrim(rtrim(number_format((float) $bonus, 1, '.', ''), '0'), '.');
    }
}
