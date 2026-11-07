<?php

namespace MiningManager\Services\Events;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Moon\RefineryService;

/**
 * The refineries a moon event can be narrowed to, and their chunks.
 *
 * Only our own refineries can be picked: refinery reports, which say whose
 * mining was at which moon, only exist for our structures. Each chunk comes
 * with the day it arrives and the latest day its belt can last to, so an
 * event covering those days covers the whole belt however early or late it
 * is fractured. A refinery has one belt at a time, so its mining on those
 * days is that chunk's.
 */
class EventRefineries
{
    protected SettingsManagerService $settings;
    protected RefineryService $refineries;

    public function __construct(SettingsManagerService $settings, RefineryService $refineries)
    {
        $this->settings = $settings;
        $this->refineries = $refineries;
    }

    /**
     * Every configured corporation's moon refineries, with the chunks due
     * from two weeks ago to two months ahead, for the event form's picker.
     *
     * @param array<int, int> $alsoInclude refineries already on an event, kept even once gone
     * @return array<int, array{structure_id: int, name: string, system: ?string, moon: ?string, moon_id: ?int, chunks: array<int, array<string, string>>}>
     */
    public function choices(array $alsoInclude = []): array
    {
        $moons = [];

        foreach ($this->settings->getHomeCorporationIds() as $corporationId) {
            foreach ($this->refineries->refineriesForCorporation((int) $corporationId) as $refinery) {
                $moons[(int) $refinery->structure_id] = $refinery->moon_id ? (int) $refinery->moon_id : null;
            }
        }

        foreach ($alsoInclude as $structureId) {
            $moons[(int) $structureId] ??= null;
        }

        $chunks = $this->chunks(array_keys($moons), Carbon::now()->subDays(14), Carbon::now()->addDays(60));

        return $this->describe($moons, $chunks);
    }

    /**
     * The event's own refineries, with the chunks that fall in its days,
     * archived ones included, for the event page.
     *
     * @param array<int, int> $structureIds
     */
    public function forEvent(array $structureIds, Carbon $from, ?Carbon $to): array
    {
        if ($structureIds === []) {
            return [];
        }

        $moons = array_fill_keys(array_map('intval', $structureIds), null);
        $chunks = $this->chunks(array_keys($moons), $from->copy()->subDays(5), ($to ?? Carbon::now())->copy()->addDay(), true);

        return $this->describe($moons, $chunks);
    }

    /**
     * Chunks arriving between two times on these refineries, oldest first.
     *
     * @param array<int, int> $structureIds
     * @return array<int, array<int, array<string, string>>> by structure id
     */
    protected function chunks(array $structureIds, Carbon $from, Carbon $to, bool $withArchived = false): array
    {
        if ($structureIds === []) {
            return [];
        }

        $found = MoonExtraction::whereIn('structure_id', $structureIds)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('chunk_arrival_time', [$from, $to])
            ->get();

        if ($withArchived) {
            $found = $found->concat(MoonExtractionHistory::whereIn('structure_id', $structureIds)
                ->where(fn ($query) => $query->whereNull('final_status')->orWhere('final_status', '!=', 'cancelled'))
                ->whereBetween('chunk_arrival_time', [$from, $to])
                ->get());
        }

        $byRefinery = [];
        $seen = [];

        foreach ($found->sortBy('chunk_arrival_time') as $chunk) {
            $key = $chunk->structure_id . '|' . $chunk->chunk_arrival_time->getTimestamp();
            $gone = $chunk->getLatestExpiryTime();

            // An archived chunk can still be in the live table for a moment.
            if (isset($seen[$key]) || $gone === null) {
                continue;
            }

            $seen[$key] = true;
            $byRefinery[(int) $chunk->structure_id][] = [
                'arrival' => $chunk->chunk_arrival_time->toIso8601String(),
                'arrival_day' => $chunk->chunk_arrival_time->toDateString(),
                'gone' => $gone->toIso8601String(),
                'gone_day' => $gone->toDateString(),
                'moon_id' => $chunk->moon_id ? (string) $chunk->moon_id : '',
            ];
        }

        return $byRefinery;
    }

    /**
     * Names for the refineries, their systems and moons, read in one go.
     *
     * @param array<int, int|null> $moons moon id by structure id, where known
     * @param array<int, array<int, array<string, string>>> $chunks
     */
    protected function describe(array $moons, array $chunks): array
    {
        $ids = array_keys($moons);
        $structures = DB::table('universe_structures')->whereIn('structure_id', $ids)->get(['structure_id', 'name', 'solar_system_id'])->keyBy('structure_id');

        $unknown = array_keys(array_filter($moons, fn ($moonId) => $moonId === null));
        foreach ($unknown ? $this->refineries->moonIdsForStructures($unknown) : [] as $id => $moonId) {
            $moons[(int) $id] = $moonId ? (int) $moonId : null;
        }

        foreach ($ids as $id) {
            if ($moons[$id] === null && !empty($chunks[$id])) {
                $last = end($chunks[$id]);
                $moons[$id] = $last['moon_id'] !== '' ? (int) $last['moon_id'] : null;
            }
        }

        $systems = DB::table('solar_systems')->whereIn('system_id', $structures->pluck('solar_system_id')->filter()->all())->pluck('name', 'system_id');
        $moonNames = DB::table('moons')->whereIn('moon_id', array_values(array_filter($moons)))->pluck('name', 'moon_id');

        $choices = [];

        foreach ($ids as $id) {
            $structure = $structures->get($id);
            $moonId = $moons[$id];

            $choices[] = [
                'structure_id' => $id,
                'name' => $structure->name ?? "Structure {$id}",
                'system' => $structure && $structure->solar_system_id ? ($systems[$structure->solar_system_id] ?? null) : null,
                'moon' => $moonId ? ($moonNames[$moonId] ?? "Moon {$moonId}") : null,
                'moon_id' => $moonId,
                'chunks' => $chunks[$id] ?? [],
            ];
        }

        usort($choices, fn ($a, $b) => [$a['system'] ?? '~', $a['moon'] ?? $a['name']] <=> [$b['system'] ?? '~', $b['moon'] ?? $b['name']]);

        return $choices;
    }
}
