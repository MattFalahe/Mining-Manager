<?php

namespace MiningManager\Services\Character;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The corporation a character was in on a given day.
 *
 * Mining is taxed, and judged as theft or not, as of the day it happened. Read
 * from the corporation a character is in now, a member who left would have
 * every day rebuilt after leaving re-rated as a guest's, mining they did as a
 * member included.
 *
 * SeAT's corporation history comes first. When SeAT's affiliation already
 * shows a move the history has not caught up with, the move counts from the
 * time the affiliation last changed. A character with no history at all, such
 * as one outside SeAT, is placed by their current corporation for every day,
 * which is all anyone knows about them.
 */
class CorporationOnDate
{
    /**
     * How long a character's timeline is reused, in seconds. Queue workers
     * live for days, and a move made today has to reach today's mining.
     */
    private const CACHE_SECONDS = 300;

    /** Timelines kept at once before the cache starts over. */
    private const CACHE_LIMIT = 5000;

    /** @var array<int, array{at: int, entries: array<int, array{0: int, 1: int}>}> */
    private static array $timelines = [];

    /**
     * The character's corporation on that day, or null when nothing places them.
     *
     * On the day a character changes corporation they count as a member if they
     * were in one of the member corporations at any point that day, since the
     * ledger records a day and not the hour each unit was mined.
     *
     * @param mixed $date
     * @param array<int, int> $memberCorporationIds corporations whose members are taxed as members
     */
    public function forDay(int $characterId, $date, array $memberCorporationIds = []): ?int
    {
        $entries = $this->timeline($characterId);
        if (!$entries) {
            return null;
        }

        $dayStart = Carbon::parse($date)->startOfDay()->getTimestamp();
        $dayEnd = $dayStart + 86400;

        $atStart = null;
        $during = [];
        foreach ($entries as [$from, $corporationId]) {
            if ($from <= $dayStart) {
                $atStart = $corporationId;
            } elseif ($from < $dayEnd) {
                $during[] = $corporationId;
            } else {
                break;
            }
        }

        // A day before the first corporation on record: the first one known.
        $atStart ??= $entries[0][1];

        foreach (array_merge([$atStart], $during) as $corporationId) {
            if (in_array($corporationId, $memberCorporationIds, true)) {
                return $corporationId;
            }
        }

        return $atStart;
    }

    /**
     * Each corporation the character has been in, with when it started,
     * oldest first.
     *
     * @return array<int, array{0: int, 1: int}> [start timestamp, corporation_id]
     */
    private function timeline(int $characterId): array
    {
        $cached = self::$timelines[$characterId] ?? null;
        if ($cached && $cached['at'] > time() - self::CACHE_SECONDS) {
            return $cached['entries'];
        }

        $entries = DB::table('character_corporation_histories')
            ->where('character_id', $characterId)
            ->orderBy('start_date')
            ->orderBy('record_id')
            ->get(['start_date', 'corporation_id'])
            ->map(fn ($row) => [Carbon::parse($row->start_date)->getTimestamp(), (int) $row->corporation_id])
            ->all();

        $current = DB::table('character_affiliations')
            ->where('character_id', $characterId)
            ->first(['corporation_id', 'updated_at']);

        $currentCorporation = (int) ($current->corporation_id ?? 0);
        $changedAt = $current && $current->updated_at ? Carbon::parse($current->updated_at)->getTimestamp() : null;

        if ($currentCorporation === 0) {
            $currentCorporation = (int) DB::table(AffiliationResolutionService::TABLE)
                ->where('character_id', $characterId)
                ->value('corporation_id');
            $changedAt = null;
        }

        if ($currentCorporation > 0) {
            $last = $entries ? $entries[count($entries) - 1] : null;

            if ($last === null) {
                $entries = [[PHP_INT_MIN, $currentCorporation]];
            } elseif ($last[1] !== $currentCorporation) {
                $entries[] = [max($changedAt ?? time(), $last[0] + 1), $currentCorporation];
            }
        }

        if (count(self::$timelines) >= self::CACHE_LIMIT) {
            self::$timelines = [];
        }
        self::$timelines[$characterId] = ['at' => time(), 'entries' => $entries];

        return $entries;
    }
}
