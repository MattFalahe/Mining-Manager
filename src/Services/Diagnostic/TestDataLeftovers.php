<?php

namespace MiningManager\Services\Diagnostic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MiningManager\Services\Character\AffiliationResolutionService;

/**
 * What the test data's characters leave in the plugin's own tables once the
 * scheduled jobs have seen their mining: the summaries built from it, the
 * events it was counted in, and the name lookups for them. The Diagnostic
 * page's Cleanup and the command's --cleanup both remove these along with
 * the ledger rows, so nothing goes on counting mining that is gone.
 */
class TestDataLeftovers
{
    /** Each kind of leftover, and the tables it is kept in. */
    public const TABLES = [
        'summaries' => ['mining_ledger_daily_summaries', 'mining_ledger_monthly_summaries'],
        'event rows' => ['event_mining_records', 'event_participants'],
        'name lookups' => [AffiliationResolutionService::TABLE],
    ];

    /**
     * Delete them for these characters.
     *
     * @param iterable<int|string> $characterIds
     * @return array<string, int> rows deleted, by kind
     */
    public static function remove(iterable $characterIds): array
    {
        $ids = [];
        foreach ($characterIds as $id) {
            if ((int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        $deleted = [];
        foreach (self::TABLES as $kind => $tables) {
            $deleted[$kind] = 0;
            foreach ($tables as $table) {
                if (!$ids || !Schema::hasTable($table)) {
                    continue;
                }
                foreach (array_chunk($ids, 1000) as $chunk) {
                    $deleted[$kind] += DB::table($table)->whereIn('character_id', $chunk)->delete();
                }
            }
        }

        return $deleted;
    }

    /**
     * The counts for a message, "12 summaries, 3 name lookups", leaving out
     * the kinds there were none of.
     *
     * @param array<string, int> $deleted
     */
    public static function describe(array $deleted): string
    {
        $parts = [];
        foreach ($deleted as $kind => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$kind}";
            }
        }

        return implode(', ', $parts);
    }
}
