<?php

namespace MiningManager\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Counting miners as players rather than characters.
 *
 * A player is a SeAT account, main and alts together. A character SeAT has no
 * account for counts on its own. The Top Miners boards group the same way, so
 * a page's miner count agrees with the board beside it.
 */
class Players
{
    /**
     * How many players the characters belong to.
     */
    public static function count(iterable $characterIds): int
    {
        $ids = [];
        foreach ($characterIds as $id) {
            $ids[(int) $id] = true;
        }

        return count(array_unique(self::accounts(array_keys($ids))));
    }

    /**
     * How many players are in each group of rows. Each row carries a
     * character_id, and $groupOf gives the group it belongs to.
     *
     * @return array<string, int> group => players
     */
    public static function perGroup(iterable $rows, callable $groupOf): array
    {
        $pairs = [];
        $ids = [];
        foreach ($rows as $row) {
            $characterId = (int) $row->character_id;
            $pairs[] = [(string) $groupOf($row), $characterId];
            $ids[$characterId] = true;
        }

        $accounts = self::accounts(array_keys($ids));
        $players = [];
        foreach ($pairs as [$group, $characterId]) {
            $players[$group][$accounts[$characterId]] = true;
        }

        return array_map('count', $players);
    }

    /**
     * Each character's account: its SeAT user, or the character itself.
     *
     * @param int[] $characterIds
     * @return array<int, string>
     */
    private static function accounts(array $characterIds): array
    {
        $users = [];
        foreach (array_chunk($characterIds, 1000) as $chunk) {
            $users += DB::table('refresh_tokens')->whereIn('character_id', $chunk)->pluck('user_id', 'character_id')->all();
        }

        $accounts = [];
        foreach ($characterIds as $id) {
            $accounts[$id] = isset($users[$id]) ? 'user:' . $users[$id] : 'character:' . $id;
        }

        return $accounts;
    }
}
