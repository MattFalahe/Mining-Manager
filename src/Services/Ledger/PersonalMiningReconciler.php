<?php

namespace MiningManager\Services\Ledger;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Tax\InvoiceCoverage;

/**
 * Clears out personal mining rows that our own corporation observer has
 * already accounted for.
 *
 * The two sources overlap. Character ESI reports everything a pilot mined of
 * one ore in one system that day; the corporation observer reports what came
 * off our refinery. The importer subtracts one from the other the first time
 * it meets an observer entry, and only then, so a single missed match leaves
 * the same mining in the ledger twice: a taxed observer row and an untaxed
 * personal copy, with nothing to notice or correct it afterwards.
 *
 * This is the second attempt, and unlike the first it can run as often as it
 * likes without doing damage.
 *
 * **It only removes a row whose quantity is exactly the observer total.** That
 * equality is the one thing that can be proved: every unit the pilot mined was
 * seen by our refinery, so no share of anybody else's moon is hiding in it.
 * Anything less than exact is left where it is, for two reasons. A smaller
 * personal figure is what a subtraction that already worked looks like, and is
 * indistinguishable from a pilot who also mined a neighbour's rock. And
 * subtracting again on a later pass would take the same observer quantity off
 * twice and destroy a legitimate remainder, which is what the version this
 * replaces did on the second day.
 */
class PersonalMiningReconciler
{
    /**
     * Default window, in days back from yesterday.
     *
     * Long enough for observer data that arrives a couple of days late, short
     * enough that it cannot wander into a closed billing period or re-touch
     * mining whose prices have long settled.
     */
    public const DEFAULT_DAYS = 4;

    protected SettingsManagerService $settings;
    protected LedgerSummaryService $summaries;

    public function __construct(SettingsManagerService $settings, LedgerSummaryService $summaries)
    {
        $this->settings = $settings;
        $this->summaries = $summaries;
    }

    /**
     * @return array{examined:int,removed:int,billed_skipped:int,quantity:int,value:float,summaries:int,rows:array,moon_owner:?int}
     */
    public function reconcile(int $days = self::DEFAULT_DAYS, bool $dryRun = false): array
    {
        $result = [
            'examined' => 0,
            'removed' => 0,
            'billed_skipped' => 0,
            'quantity' => 0,
            'value' => 0.0,
            'summaries' => 0,
            'rows' => [],
            'moon_owner' => null,
        ];

        // The Moon Owner Corporation is a global setting, so it is read without
        // a corporation context. Reading it through getSetting() resolves it
        // against whatever corporation the settings service was last pointed
        // at, which by this point in a run is the last character processed.
        $moonOwnerCorpId = $this->settings->getSettingForCorporation(
            'general.moon_owner_corporation_id',
            null
        );
        $moonOwnerCorpId = $moonOwnerCorpId ? (int) $moonOwnerCorpId : null;
        $result['moon_owner'] = $moonOwnerCorpId;

        // Without knowing whose refinery is ours, every observer row in the
        // ledger looks equally like ours, including other corporations'. Those
        // are deliberately left out of the daily summaries, so matching against
        // them would delete a personal row whose mining then appears nowhere at
        // all. Better to do nothing.
        if ($moonOwnerCorpId === null) {
            Log::warning('Mining Manager: no Moon Owner Corporation set, skipping personal mining reconciliation');

            return $result;
        }

        // Yesterday backwards. Today is left alone on purpose: the personal
        // import will not recreate a row once an observer row exists for that
        // pilot, ore and day, so removing one while the day is still being
        // mined would lose whatever they mine afterwards somewhere else.
        $until = Carbon::yesterday()->endOfDay();
        $since = Carbon::today()->subDays(max(1, $days))->startOfDay();

        $touched = [];

        MiningLedger::query()
            ->whereNull('observer_id')
            ->whereNull('corporation_id')
            ->where('is_moon_ore', true)
            ->whereBetween('date', [$since, $until])
            ->whereExists(function ($query) use ($moonOwnerCorpId) {
                $query->select(DB::raw(1))
                    ->from('mining_ledger as o')
                    ->whereColumn('o.character_id', 'mining_ledger.character_id')
                    ->whereColumn('o.date', 'mining_ledger.date')
                    ->whereColumn('o.type_id', 'mining_ledger.type_id')
                    ->whereNotNull('o.observer_id')
                    ->where('o.corporation_id', $moonOwnerCorpId);
            })
            // Paged by id, because the loop deletes rows this very query
            // selects on and offset paging would walk straight past the ones
            // that shuffle back.
            ->chunkById(500, function ($rows) use (&$result, &$touched, $dryRun, $moonOwnerCorpId) {
                foreach ($rows as $row) {
                    $result['examined']++;

                    // A day somebody has already been billed for is evidence,
                    // not working data. Never touched, whatever it holds.
                    if (InvoiceCoverage::coversRow((int) $row->character_id, $row->date)) {
                        $result['billed_skipped']++;
                        continue;
                    }

                    if ((int) $row->quantity !== $this->observerTotal($row, $moonOwnerCorpId)) {
                        continue;
                    }

                    $day = $row->date instanceof Carbon
                        ? $row->date->toDateString()
                        : (string) $row->date;

                    $result['removed']++;
                    $result['quantity'] += (int) $row->quantity;
                    $result['value'] += (float) $row->total_value;
                    $touched[$row->character_id . '|' . $day] = [
                        'character_id' => (int) $row->character_id,
                        'date' => $day,
                    ];

                    if (count($result['rows']) < 25) {
                        $result['rows'][] = [
                            'id' => (int) $row->id,
                            'character_id' => (int) $row->character_id,
                            'date' => $day,
                            'type_id' => (int) $row->type_id,
                            'quantity' => (int) $row->quantity,
                            'value' => (float) $row->total_value,
                        ];
                    }

                    if (! $dryRun) {
                        Log::info('Mining Manager: removed a personal mining row the observer already covered', [
                            'ledger_id' => $row->id,
                            'character_id' => $row->character_id,
                            'date' => $day,
                            'type_id' => $row->type_id,
                            'quantity' => $row->quantity,
                        ]);

                        $row->delete();
                    }
                }
            });

        // The ledger and the summaries have to agree. A day left holding its
        // old total is the drift that makes two sources of truth worse than
        // one.
        if (! $dryRun && $touched) {
            $result['summaries'] = $this->regenerate($touched);
        }

        return $result;
    }

    /**
     * What our own refinery already accounts for, for the same pilot, ore and
     * day. Other corporations' observers are not ours to match against.
     */
    protected function observerTotal(MiningLedger $row, int $moonOwnerCorpId): int
    {
        return (int) MiningLedger::where('character_id', $row->character_id)
            ->whereDate('date', $row->date)
            ->where('type_id', $row->type_id)
            ->whereNotNull('observer_id')
            ->where('corporation_id', $moonOwnerCorpId)
            ->sum('quantity');
    }

    /**
     * @param  array<string, array{character_id:int,date:string}> $pairs
     * @return int summaries rebuilt
     */
    protected function regenerate(array $pairs): int
    {
        $rebuilt = 0;

        foreach ($pairs as $pair) {
            try {
                $this->summaries->generateDailySummary($pair['character_id'], $pair['date']);
                $rebuilt++;
            } catch (\Throwable $e) {
                Log::error('Mining Manager: could not rebuild a daily summary after reconciliation', [
                    'character_id' => $pair['character_id'],
                    'date' => $pair['date'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $rebuilt;
    }
}
