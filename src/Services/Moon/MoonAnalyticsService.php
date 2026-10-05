<?php

namespace MiningManager\Services\Moon;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Moon Analytics Service
 *
 * Provides analytics comparing moon extraction pools against actual mining activity.
 * Links moon_extractions (pool data) to mining_ledger (mined data) via structure_id = observer_id.
 */
class MoonAnalyticsService
{
    protected RefineryService $refineries;

    public function __construct(RefineryService $refineries)
    {
        $this->refineries = $refineries;
    }

    /**
     * Get summary statistics for the 4 top-level cards.
     */
    public function getSummaryStats(Carbon $month): array
    {
        $cacheKey = "moon-analytics:summary:{$month->format('Ym')}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($month) {
            $extractions = $this->getExtractionsForMonth($month);

            if ($extractions->isEmpty()) {
                return [
                    'total_pool_m3' => 0,
                    'total_mined_m3' => 0,
                    'utilization_pct' => 0,
                    'total_pool_isk' => 0,
                    'total_mined_isk' => 0,
                    'value_pct' => 0,
                    'unique_miners' => 0,
                    'extraction_count' => 0,
                ];
            }

            // Pool totals from ore_composition JSON
            $totalPoolM3 = 0;
            $totalPoolIsk = 0;
            foreach ($extractions as $extraction) {
                $composition = $extraction->ore_composition;
                if (is_array($composition)) {
                    foreach ($composition as $ore) {
                        $totalPoolM3 += (float) ($ore['volume_m3'] ?? 0);
                        $totalPoolIsk += (float) ($ore['value'] ?? 0);
                    }
                }
            }

            // Mined totals from mining_ledger
            $mined = $this->getMinedTotalsForExtractions($extractions);

            $utilPct = $totalPoolM3 > 0 ? ($mined['total_m3'] / $totalPoolM3) * 100 : 0;
            $valuePct = $totalPoolIsk > 0 ? ($mined['total_isk'] / $totalPoolIsk) * 100 : 0;

            return [
                'total_pool_m3' => $totalPoolM3,
                'total_mined_m3' => $mined['total_m3'],
                'utilization_pct' => min(round($utilPct, 1), 100),
                'total_pool_isk' => $totalPoolIsk,
                'total_mined_isk' => $mined['total_isk'],
                'value_pct' => min(round($valuePct, 1), 100),
                'unique_miners' => $mined['unique_miners'],
                'extraction_count' => $extractions->count(),
            ];
        });
    }

    /**
     * Get per-moon utilization data for the monthly table.
     */
    public function getMoonUtilization(Carbon $month): Collection
    {
        $cacheKey = "moon-analytics:utilization:{$month->format('Ym')}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($month) {
            $extractions = $this->getExtractionsForMonth($month);

            if ($extractions->isEmpty()) {
                return collect();
            }

            // Group extractions by moon_id
            $byMoon = $extractions->groupBy('moon_id');

            // Get mined data per structure
            $minedByStructure = $this->getMinedByStructure($extractions);

            // Batch-load moon names
            $moonIds = $byMoon->keys()->filter()->toArray();
            $moonNames = !empty($moonIds)
                ? DB::table('moons')->whereIn('moon_id', $moonIds)->pluck('name', 'moon_id')->toArray()
                : [];

            // Batch-load structure names from universe_structures
            $structureIds = $extractions->pluck('structure_id')->unique()->filter()->toArray();
            $structureNames = !empty($structureIds)
                ? DB::table('universe_structures')->whereIn('structure_id', $structureIds)->pluck('name', 'structure_id')->toArray()
                : [];

            [$hulls, $rigsNow] = $this->refineryRigs($structureIds);

            $results = [];
            foreach ($byMoon as $moonId => $moonExtractions) {
                $poolM3 = 0;
                $poolIsk = 0;
                $minedM3 = 0;
                $minedIsk = 0;
                $minerIds = [];
                $counted = [];
                $chunks = [];

                foreach ($moonExtractions as $extraction) {
                    // Pool data from ore_composition
                    $composition = $extraction->ore_composition;
                    if (is_array($composition)) {
                        foreach ($composition as $ore) {
                            $poolM3 += (float) ($ore['volume_m3'] ?? 0);
                            $poolIsk += (float) ($ore['value'] ?? 0);
                        }
                    }

                    // What the chunk was pulled with, from its own timer.
                    $mark = MoonRigMarks::chunk($extraction, $hulls[(int) $extraction->structure_id] ?? null);
                    $chunks[] = [
                        'at' => $extraction->chunk_arrival_time ? $extraction->chunk_arrival_time->getTimestamp() : 0,
                        'date' => $extraction->chunk_arrival_time ? $extraction->chunk_arrival_time->format('M d') : '?',
                        'rigged' => $mark['rigged'],
                        'text' => $mark['text'],
                    ];

                    // A refinery's ledger rows already cover every chunk it had
                    // this month, so they count once however many chunks there were.
                    if (isset($counted[(int) $extraction->structure_id])) {
                        continue;
                    }
                    $counted[(int) $extraction->structure_id] = true;

                    // Mined data
                    $structureData = $minedByStructure[$extraction->structure_id] ?? null;
                    if ($structureData) {
                        $minedM3 += (float) $structureData->mined_m3;
                        $minedIsk += (float) $structureData->mined_isk;
                        if (!empty($structureData->miner_ids)) {
                            $minerIds = array_merge($minerIds, explode(',', $structureData->miner_ids));
                        }
                    }
                }

                $uniqueMiners = count(array_unique($minerIds));
                usort($chunks, fn ($a, $b) => $a['at'] <=> $b['at']);

                // Get structure name from the first extraction's structure_id
                $firstStructureId = $moonExtractions->first()?->structure_id ?? null;
                $structureName = $firstStructureId ? ($structureNames[$firstStructureId] ?? null) : null;

                $results[] = (object) [
                    'moon_id' => $moonId,
                    'moon_name' => $moonNames[$moonId] ?? "Moon {$moonId}",
                    'structure_name' => $structureName,
                    'extraction_count' => $moonExtractions->count(),
                    'pool_m3' => $poolM3,
                    'mined_m3' => $minedM3,
                    'util_pct' => $poolM3 > 0 ? min(round(($minedM3 / $poolM3) * 100, 1), 100) : 0,
                    'pool_isk' => $poolIsk,
                    'mined_isk' => $minedIsk,
                    'value_pct' => $poolIsk > 0 ? min(round(($minedIsk / $poolIsk) * 100, 1), 100) : 0,
                    'unique_miners' => $uniqueMiners,
                    'rigs_now' => $firstStructureId ? ($rigsNow[(int) $firstStructureId] ?? null) : null,
                    'rig_chunks' => $chunks,
                    'rigged_chunks' => count(array_filter($chunks, fn ($chunk) => $chunk['rigged'])),
                ];
            }

            // Sort by pool ISK descending
            return collect($results)->sortByDesc('pool_isk')->values();
        });
    }

    /**
     * Get utilization data for a single extraction.
     */
    public function getExtractionUtilization(int $extractionId): ?array
    {
        $extraction = MoonExtraction::find($extractionId);
        if (!$extraction) {
            // Check history
            $extraction = MoonExtractionHistory::find($extractionId);
        }
        if (!$extraction) {
            return null;
        }

        // Pool data
        $poolM3 = 0;
        $poolIsk = 0;
        $poolOres = [];
        $composition = $extraction->ore_composition;
        if (is_array($composition)) {
            foreach ($composition as $oreName => $ore) {
                $volumeM3 = (float) ($ore['volume_m3'] ?? 0);
                $value = (float) ($ore['value'] ?? 0);
                $poolM3 += $volumeM3;
                $poolIsk += $value;
                $poolOres[] = [
                    'name' => $oreName,
                    'type_id' => $ore['type_id'] ?? null,
                    'volume_m3' => $volumeM3,
                    'value' => $value,
                ];
            }
        }

        // Mined data
        $startDate = $extraction->chunk_arrival_time ?? $extraction->extraction_start_time;
        $endDate = $this->miningWindowEnd($extraction, $startDate);

        $minedData = MiningLedger::where('observer_id', $extraction->structure_id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('is_moon_ore', true)
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->select(
                'mining_ledger.type_id',
                'invTypes.typeName',
                DB::raw('SUM(mining_ledger.quantity) as qty'),
                DB::raw('SUM(mining_ledger.quantity * invTypes.volume) as mined_m3'),
                DB::raw('SUM(mining_ledger.total_value) as mined_isk'),
                DB::raw('COUNT(DISTINCT mining_ledger.character_id) as miners')
            )
            ->groupBy('mining_ledger.type_id', 'invTypes.typeName')
            ->orderByDesc('mined_isk')
            ->get();

        $totalMinedM3 = $minedData->sum('mined_m3');
        $totalMinedIsk = $minedData->sum('mined_isk');

        // Unique miners across all ores
        $uniqueMiners = MiningLedger::where('observer_id', $extraction->structure_id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('is_moon_ore', true)
            ->distinct('character_id')
            ->count('character_id');

        [$hulls, $rigsNow] = $this->refineryRigs([(int) $extraction->structure_id]);

        // Moon name
        $moonName = 'Unknown Moon';
        if ($extraction->moon_id) {
            $moon = DB::table('moons')->where('moon_id', $extraction->moon_id)->first();
            $moonName = $moon ? $moon->name : "Moon {$extraction->moon_id}";
        }

        return [
            'extraction' => $extraction,
            'moon_name' => $moonName,
            'pool_m3' => $poolM3,
            'pool_isk' => $poolIsk,
            'pool_ores' => $poolOres,
            'mined_m3' => $totalMinedM3,
            'mined_isk' => $totalMinedIsk,
            'mined_ores' => $minedData,
            'util_pct' => $poolM3 > 0 ? min(round(($totalMinedM3 / $poolM3) * 100, 1), 100) : 0,
            'value_pct' => $poolIsk > 0 ? min(round(($totalMinedIsk / $poolIsk) * 100, 1), 100) : 0,
            'unique_miners' => $uniqueMiners,
            'ends_at' => $endDate,
            'rigs' => MoonRigMarks::chunk($extraction, $hulls[(int) $extraction->structure_id] ?? null),
            'rigs_now' => $rigsNow[(int) $extraction->structure_id] ?? null,
        ];
    }

    /**
     * When nobody can mine the chunk any more: fracture, its mining window and
     * the 2 hour unstable tail, the same cycle for live and archived rows.
     * natural_decay_time is only when the chunk would fracture on its own, a
     * few hours after arrival, so ending the window there counted the arrival
     * day and nothing after it.
     *
     * @param MoonExtraction|MoonExtractionHistory $extraction
     */
    private function miningWindowEnd($extraction, Carbon $start): Carbon
    {
        return $extraction->getExpiryTime()
            ?? $start->copy()->addHours(MoonDrillingRigs::BASE_READY_HOURS + MoonDrillingRigs::UNSTABLE_HOURS);
    }

    /**
     * The ledger days a set of chunks could have been mined on: from the first
     * arrival to the end of the last chunk's life. The last auto-fracture time
     * is not the end, a chunk is mined for days after it, longer with a rig.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function minedWindow(Collection $extractions): ?array
    {
        $from = null;
        $to = null;

        foreach ($extractions as $extraction) {
            $arrival = $extraction->chunk_arrival_time;
            if (!$arrival) {
                continue;
            }

            $end = $this->miningWindowEnd($extraction, $arrival);
            $from = $from === null || $arrival->lt($from) ? $arrival : $from;
            $to = $to === null || $end->gt($to) ? $end : $to;
        }

        return $from && $to ? [$from, $to] : null;
    }

    /**
     * The hull of each refinery behind a set of chunks, and the moon rigs it
     * has fitted now (null where SeAT cannot see its fittings).
     *
     * @param array<int, int> $structureIds
     * @return array{0: array<int, int>, 1: array<int, array<int, string>|null>}
     */
    private function refineryRigs(array $structureIds): array
    {
        $hulls = $this->refineries->hullTypes($structureIds);
        $visible = $this->refineries->assetsVisible($structureIds);
        $fitted = $this->refineries->fittedRigSummary($structureIds);
        $now = [];

        foreach ($visible as $structureId => $seen) {
            $now[$structureId] = MoonRigMarks::fitted($fitted[$structureId] ?? null, $seen);
        }

        return [$hulls, $now];
    }

    /**
     * "Moon (Oct 03 - Oct 06) · Stability I": the days the chunk could be
     * mined on and the rigs it was pulled with.
     */
    private function pickerLabel($extraction, ?int $hullType): string
    {
        $arrival = $extraction->chunk_arrival_time;
        $label = ($extraction->moon_name ?? 'Unknown') . ' ('
            . ($arrival ? $arrival->format('M d') : '?') . ' - '
            . ($arrival ? $this->miningWindowEnd($extraction, $arrival)->format('M d') : '?') . ')';
        $rigs = MoonRigMarks::chunk($extraction, $hullType)['short'];

        return $rigs ? $label . ' · ' . implode(', ', $rigs) : $label;
    }

    /**
     * Get moon popularity data (miners per moon) for chart.
     */
    public function getMoonPopularity(Carbon $month): Collection
    {
        $cacheKey = "moon-analytics:popularity:{$month->format('Ym')}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($month) {
            $extractions = $this->getExtractionsForMonth($month);

            if ($extractions->isEmpty()) {
                return collect();
            }

            // Build structure_id → moon_id mapping
            $structureToMoon = [];
            foreach ($extractions as $e) {
                $structureToMoon[$e->structure_id] = $e->moon_id;
            }

            $structureIds = array_keys($structureToMoon);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            // Get unique miners per observer
            $minerData = MiningLedger::whereIn('observer_id', $structureIds)
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->where('is_moon_ore', true)
                ->select(
                    'observer_id',
                    DB::raw('COUNT(DISTINCT character_id) as unique_miners'),
                    DB::raw('COUNT(DISTINCT date) as active_days'),
                    DB::raw('SUM(total_value) as total_isk')
                )
                ->groupBy('observer_id')
                ->get();

            // Group by moon_id (multiple structures may point to same moon)
            $byMoon = [];
            foreach ($minerData as $row) {
                $moonId = $structureToMoon[$row->observer_id] ?? 0;
                if (!isset($byMoon[$moonId])) {
                    $byMoon[$moonId] = ['miners' => [], 'active_days' => 0, 'total_isk' => 0];
                }
                // We can't just sum unique_miners across structures — need to collect and dedupe
                // For simplicity, sum them (they're likely the same structure per moon)
                $byMoon[$moonId]['miners'][] = $row->unique_miners;
                $byMoon[$moonId]['active_days'] += $row->active_days;
                $byMoon[$moonId]['total_isk'] += $row->total_isk;
            }

            // Resolve moon names
            $moonIds = array_keys($byMoon);
            $moonNames = !empty($moonIds)
                ? DB::table('moons')->whereIn('moon_id', $moonIds)->pluck('name', 'moon_id')->toArray()
                : [];

            // Resolve structure names for chart labels
            $structureNames = !empty($structureIds)
                ? DB::table('universe_structures')->whereIn('structure_id', $structureIds)->pluck('name', 'structure_id')->toArray()
                : [];
            // Map moon_id → structure_name from first matching extraction
            $moonStructureNames = [];
            foreach ($extractions as $e) {
                if (!isset($moonStructureNames[$e->moon_id]) && isset($structureNames[$e->structure_id])) {
                    $moonStructureNames[$e->moon_id] = $structureNames[$e->structure_id];
                }
            }

            $results = [];
            foreach ($byMoon as $moonId => $data) {
                $results[] = (object) [
                    'moon_id' => $moonId,
                    'moon_name' => $moonNames[$moonId] ?? "Moon {$moonId}",
                    'structure_name' => $moonStructureNames[$moonId] ?? null,
                    'unique_miners' => max($data['miners']),
                    'active_days' => $data['active_days'],
                    'total_isk' => $data['total_isk'],
                ];
            }

            return collect($results)->sortByDesc('unique_miners')->values();
        });
    }

    /**
     * Get ore popularity data (which ores are mined from moons).
     */
    public function getOrePopularity(Carbon $month, ?int $structureId = null): Collection
    {
        $cacheKey = "moon-analytics:ore-pop:{$month->format('Ym')}:" . ($structureId ?? 'all');

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($month, $structureId) {
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            $query = MiningLedger::whereBetween('date', [$monthStart, $monthEnd])
                ->where('is_moon_ore', true)
                ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID');

            if ($structureId) {
                $query->where('observer_id', $structureId);
            } else {
                // Limit to known moon structures
                $extractions = $this->getExtractionsForMonth($month);
                $structureIds = $extractions->pluck('structure_id')->unique()->toArray();
                if (empty($structureIds)) {
                    return collect();
                }
                $query->whereIn('observer_id', $structureIds);
            }

            return $query->select(
                'mining_ledger.type_id',
                'invTypes.typeName as ore_name',
                DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                DB::raw('SUM(mining_ledger.quantity * invTypes.volume) as total_m3'),
                DB::raw('SUM(mining_ledger.total_value) as total_isk')
            )
                ->groupBy('mining_ledger.type_id', 'invTypes.typeName')
                ->orderByDesc('total_isk')
                ->get();
        });
    }

    /**
     * Get pool ore distribution from extraction compositions, grouped by R-category.
     */
    public function getPoolOreDistribution(Carbon $month): array
    {
        $cacheKey = "moon-analytics:pool-ore-dist:{$month->format('Ym')}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($month) {
            $extractions = $this->getExtractionsForMonth($month);

            $byCategory = [];
            foreach ($extractions as $extraction) {
                $composition = $extraction->ore_composition;
                if (!is_array($composition)) {
                    continue;
                }

                foreach ($composition as $ore) {
                    $typeId = $ore['type_id'] ?? 0;
                    $rarity = \MiningManager\Services\TypeIdRegistry::getMoonOreRarity($typeId) ?? 'unknown';

                    $categoryLabels = [
                        'r4' => 'Moon R4', 'r8' => 'Moon R8', 'r16' => 'Moon R16',
                        'r32' => 'Moon R32', 'r64' => 'Moon R64', 'unknown' => 'Other',
                    ];
                    $label = $categoryLabels[$rarity] ?? ucfirst($rarity);

                    if (!isset($byCategory[$label])) {
                        $byCategory[$label] = ['isk' => 0, 'volume' => 0];
                    }
                    $byCategory[$label]['isk'] += ($ore['value'] ?? 0);
                    $byCategory[$label]['volume'] += ($ore['volume_m3'] ?? 0);
                }
            }

            // Sort by ISK descending
            uasort($byCategory, fn($a, $b) => $b['isk'] <=> $a['isk']);

            return $byCategory;
        });
    }

    /**
     * Get list of available extractions for the dropdown picker.
     */
    public function getAvailableExtractions(?Carbon $month = null): Collection
    {
        $query = MoonExtraction::query();

        if ($month) {
            $query->whereMonth('chunk_arrival_time', $month->month)
                ->whereYear('chunk_arrival_time', $month->year);
        }

        $extractions = $query->orderByDesc('chunk_arrival_time')->get();

        // Load display names
        MoonExtraction::loadDisplayNames($extractions);
        $hulls = $this->refineries->hullTypes($extractions->pluck('structure_id')->all());

        return $extractions->map(function ($e) use ($hulls) {
            return (object) [
                'id' => $e->id,
                'label' => $this->pickerLabel($e, $hulls[(int) $e->structure_id] ?? null),
                'moon_name' => $e->moon_name ?? 'Unknown',
                'structure_name' => $e->structure_name ?? 'Unknown',
                'status' => $e->status,
                'chunk_arrival_time' => $e->chunk_arrival_time,
            ];
        });
    }

    /**
     * Get all extractions for a given month (active + history).
     */
    protected function getExtractionsForMonth(Carbon $month): Collection
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        // Active extractions whose chunk arrived in this month
        $active = MoonExtraction::where(function ($q) use ($monthStart, $monthEnd) {
            $q->whereBetween('chunk_arrival_time', [$monthStart, $monthEnd]);
        })->get();

        // Historical extractions for this month
        $history = MoonExtractionHistory::where(function ($q) use ($monthStart, $monthEnd) {
            $q->whereBetween('chunk_arrival_time', [$monthStart, $monthEnd]);
        })->get();

        // Merge, preferring history (has more complete data) by deduplicating on structure_id + extraction_start_time
        $seen = [];
        $merged = collect();

        foreach ($history as $h) {
            $key = $h->structure_id . ':' . ($h->extraction_start_time ? $h->extraction_start_time->format('YmdHis') : '');
            $seen[$key] = true;
            $merged->push($h);
        }

        foreach ($active as $a) {
            $key = $a->structure_id . ':' . ($a->extraction_start_time ? $a->extraction_start_time->format('YmdHis') : '');
            if (!isset($seen[$key])) {
                $merged->push($a);
            }
        }

        return $merged;
    }

    /**
     * Get aggregate mined totals for a set of extractions.
     */
    protected function getMinedTotalsForExtractions(Collection $extractions): array
    {
        $structureIds = $extractions->pluck('structure_id')->unique()->toArray();

        if (empty($structureIds)) {
            return ['total_m3' => 0, 'total_isk' => 0, 'unique_miners' => 0];
        }

        $window = $this->minedWindow($extractions);

        if (!$window) {
            return ['total_m3' => 0, 'total_isk' => 0, 'unique_miners' => 0];
        }

        $result = MiningLedger::whereIn('observer_id', $structureIds)
            ->whereBetween('date', [$window[0]->toDateString(), $window[1]->toDateString()])
            ->where('is_moon_ore', true)
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->select(
                DB::raw('COALESCE(SUM(mining_ledger.quantity * invTypes.volume), 0) as total_m3'),
                DB::raw('COALESCE(SUM(mining_ledger.total_value), 0) as total_isk'),
                DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners')
            )
            ->first();

        return [
            'total_m3' => (float) ($result->total_m3 ?? 0),
            'total_isk' => (float) ($result->total_isk ?? 0),
            'unique_miners' => (int) ($result->unique_miners ?? 0),
        ];
    }

    /**
     * Get mined data grouped by structure_id.
     */
    protected function getMinedByStructure(Collection $extractions): array
    {
        $structureIds = $extractions->pluck('structure_id')->unique()->toArray();

        if (empty($structureIds)) {
            return [];
        }

        $window = $this->minedWindow($extractions);

        if (!$window) {
            return [];
        }

        $results = MiningLedger::whereIn('observer_id', $structureIds)
            ->whereBetween('date', [$window[0]->toDateString(), $window[1]->toDateString()])
            ->where('is_moon_ore', true)
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->select(
                'mining_ledger.observer_id',
                DB::raw('COALESCE(SUM(mining_ledger.quantity * invTypes.volume), 0) as mined_m3'),
                DB::raw('COALESCE(SUM(mining_ledger.total_value), 0) as mined_isk'),
                DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners'),
                DB::raw('GROUP_CONCAT(DISTINCT mining_ledger.character_id) as miner_ids')
            )
            ->groupBy('mining_ledger.observer_id')
            ->get();

        return $results->keyBy('observer_id')->all();
    }
}
