<?php

namespace MiningManager\Services\Ledger;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MiningLedger;

/**
 * How much of a pilot's personal moon mining no refinery accounts for.
 *
 * SeAT's personal figure for a pilot, ore, day and system is everything they
 * mined there, and a refinery's report is what came off that refinery. The
 * difference is the only mining a personal row should still hold, whatever it
 * holds now. It is always worked out from those two figures rather than from
 * the row, which was reduced once by whatever the report said the first time
 * they met, so working it out again can never take the same report off twice.
 *
 * The nightly clean-up acts on it and the character ledger explains it, so they
 * cannot disagree about a row.
 */
final class RefineryCoverage
{
    /**
     * Each personal row's units that no refinery accounts for: 0 when they
     * account for all of it, null when there is nothing to compare it with.
     *
     * @param iterable<MiningLedger> $rows personal moon ore rows
     * @param string|null $profilesFrom the first day taxed by profiles, Y-m-d
     * @return array<int, int|null> row id => units
     */
    public static function uncovered(iterable $rows, ?int $moonOwnerCorpId, ?string $profilesFrom): array
    {
        $rows = collect($rows);
        if ($rows->isEmpty()) {
            return [];
        }

        $dayOf = fn ($date) => Carbon::parse($date)->toDateString();
        $keyOf = fn ($row) => implode('|', [(int) $row->character_id, $dayOf($row->date), (int) $row->solar_system_id, (int) $row->type_id]);
        $characterIds = $rows->pluck('character_id')->unique()->values()->all();
        $days = $rows->map(fn ($row) => $dayOf($row->date));

        // SeAT stores how much a day has grown at each fetch, so a day's figure
        // is the sum of its rows, the same sum the personal import takes.
        $personal = [];
        $figures = DB::table('character_minings')
            ->whereIn('character_id', $characterIds)
            ->whereBetween('date', [$days->min(), $days->max()])
            ->select('character_id', 'date', 'solar_system_id', 'type_id', DB::raw('SUM(quantity) as quantity'))
            ->groupBy('character_id', 'date', 'solar_system_id', 'type_id')
            ->get();
        foreach ($figures as $figure) {
            $personal[$keyOf($figure)] = (int) $figure->quantity;
        }

        // The ledger's refinery rows rather than SeAT's reports: they are what
        // is taxed, and one still catching up only leaves the row too big for a
        // night. A refinery SeAT has not placed in a system yet says nothing
        // about any one system's row.
        $reports = [];
        $refineryRows = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$days->min(), $days->max()])
            ->whereNotNull('observer_id')
            ->whereNotNull('solar_system_id')
            ->get(['character_id', 'date', 'solar_system_id', 'type_id', 'corporation_id', 'quantity']);
        foreach ($refineryRows as $report) {
            $reports[$keyOf($report)][] = ['corporation_id' => (int) $report->corporation_id, 'quantity' => (int) $report->quantity];
        }

        $uncovered = [];
        foreach ($rows as $row) {
            $key = $keyOf($row);
            $uncovered[(int) $row->id] = self::remainder(
                $personal[$key] ?? null,
                $reports[$key] ?? [],
                $moonOwnerCorpId,
                $profilesFrom !== null && $dayOf($row->date) >= $profilesFrom
            );
        }

        return $uncovered;
    }

    /**
     * What is left of SeAT's figure once the reports in the same system are
     * taken off it. Nothing to compare means no figure, or no report that counts.
     *
     * @param int|null $personal SeAT's figure for the pilot, ore, day and system
     * @param array<int, array{corporation_id: int, quantity: int}> $reports refinery rows in that system
     */
    public static function remainder(?int $personal, array $reports, ?int $moonOwnerCorpId, bool $taxedByProfiles): ?int
    {
        // Before tax profiles the daily summaries leave other corporations'
        // refineries out, so what came off them has to stay in the personal row
        // or it would count nowhere at all.
        $counted = array_filter(
            $reports,
            fn (array $report) => $taxedByProfiles || $report['corporation_id'] === $moonOwnerCorpId
        );

        if ($personal === null || !$counted) {
            return null;
        }

        return max(0, $personal - array_sum(array_column($counted, 'quantity')));
    }
}
