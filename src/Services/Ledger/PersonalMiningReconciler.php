<?php

namespace MiningManager\Services\Ledger;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Tax\InvoiceCoverage;

/**
 * Clears out personal mining rows that a corporation observer has already
 * accounted for.
 *
 * The two sources overlap. Character ESI reports everything a pilot mined of
 * one ore in one system that day; the corporation observer reports what came
 * off our own refinery. The importer subtracts one from the other the first
 * time it sees an observer entry, and only then. When that single attempt does
 * not land, the same mining stays in the ledger twice: once as a taxed
 * observer row and once as an untaxed personal copy. Nothing ever tries again.
 *
 * This is the second attempt, and it can run as often as it likes.
 *
 * It only removes a personal row whose quantity is **exactly** the observer
 * total for the same pilot, ore and day. That equality is the one thing that
 * can be proved: every unit the pilot mined was seen by our own refinery, so
 * there is no share from anybody else's moon hiding in the row. Anything less
 * than exact is left alone, because a smaller personal figure is what a
 * successful subtraction looks like and a partial one is indistinguishable
 * from a pilot who mined a neighbour's rock as well.
 */
class PersonalMiningReconciler
{
    /**
     * Default window. Deliberately shorter than a billing period, so the sweep
     * can only ever reach days that are still open.
     */
    public const DEFAULT_DAYS = 14;

    /**
     * @return array{examined:int,removed:int,billed_skipped:int,quantity:int,value:float,rows:array}
     */
    public function reconcile(int $days = self::DEFAULT_DAYS, bool $dryRun = false): array
    {
        $since = Carbon::now()->subDays(max(1, $days))->startOfDay();

        $result = [
            'examined' => 0,
            'removed' => 0,
            'billed_skipped' => 0,
            'quantity' => 0,
            'value' => 0.0,
            'rows' => [],
        ];

        // Only personal rows that have an observer row beside them. Paged by
        // id, because the loop deletes rows the query itself selects on.
        MiningLedger::query()
            ->whereNull('observer_id')
            ->where('date', '>=', $since)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('mining_ledger as o')
                    ->whereColumn('o.character_id', 'mining_ledger.character_id')
                    ->whereColumn('o.date', 'mining_ledger.date')
                    ->whereColumn('o.type_id', 'mining_ledger.type_id')
                    ->whereNotNull('o.observer_id');
            })
            ->chunkById(500, function ($rows) use (&$result, $dryRun) {
                foreach ($rows as $row) {
                    $result['examined']++;

                    // A day somebody has already been billed for is evidence,
                    // not working data. It is never touched, whatever it holds.
                    if (InvoiceCoverage::coversRow((int) $row->character_id, $row->date)) {
                        $result['billed_skipped']++;
                        continue;
                    }

                    if ((int) $row->quantity !== $this->observerTotal($row)) {
                        continue;
                    }

                    $result['removed']++;
                    $result['quantity'] += (int) $row->quantity;
                    $result['value'] += (float) $row->total_value;

                    // Enough to show in the report and to find the row again
                    // afterwards if somebody asks what went.
                    if (count($result['rows']) < 25) {
                        $result['rows'][] = [
                            'id' => (int) $row->id,
                            'character_id' => (int) $row->character_id,
                            'date' => $row->date instanceof Carbon
                                ? $row->date->toDateString()
                                : (string) $row->date,
                            'type_id' => (int) $row->type_id,
                            'quantity' => (int) $row->quantity,
                            'value' => (float) $row->total_value,
                        ];
                    }

                    if (! $dryRun) {
                        Log::info('Mining Manager: removed a personal mining row the observer already covered', [
                            'ledger_id' => $row->id,
                            'character_id' => $row->character_id,
                            'date' => (string) $row->date,
                            'type_id' => $row->type_id,
                            'quantity' => $row->quantity,
                        ]);

                        $row->delete();
                    }
                }
            });

        return $result;
    }

    /**
     * What our own observer rows already account for, for the same pilot, ore
     * and day.
     */
    protected function observerTotal(MiningLedger $row): int
    {
        return (int) MiningLedger::where('character_id', $row->character_id)
            ->whereDate('date', $row->date)
            ->where('type_id', $row->type_id)
            ->whereNotNull('observer_id')
            ->sum('quantity');
    }
}
