<?php

namespace MiningManager\Services\Ledger;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\Tax\InvoiceCoverage;

/**
 * Keeps personal moon mining rows to what no refinery accounts for.
 *
 * The two sources overlap. Character ESI reports everything a pilot mined of
 * one ore in one system that day; a refinery reports what came off it. The
 * importer subtracts one from the other the first time it meets a report, and
 * only then, and a report can still be partial at that point. When it catches
 * up the refinery row grows and the difference stays behind on the personal
 * row: the same mining in the ledger twice, and charged twice by a profile that
 * taxes all moon ore.
 *
 * So each night a finished day's personal rows are worked out again from SeAT's
 * full figure, never from what the row holds. Refineries that account for all
 * of it mean the row goes. Refineries that account for part of it mean the row
 * is set to the rest, which was mined somewhere else. A row with no report in
 * its own system is left alone.
 *
 * This is the second attempt, and unlike the first it can run as often as it
 * likes without doing damage. Subtracting from the row on every pass took the
 * same report off twice and destroyed a legitimate remainder, which is what the
 * version this replaces did on the second day.
 *
 * Until settings moved into tax profiles only our own refineries count, because
 * the daily summaries leave other corporations' out. See RefineryCoverage.
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
     * @return array{examined:int,removed:int,adjusted:int,billed_skipped:int,quantity:int,value:float,summaries:int,rows:array,moon_owner:?int}
     */
    public function reconcile(int $days = self::DEFAULT_DAYS, bool $dryRun = false): array
    {
        $result = [
            'examined' => 0,
            'removed' => 0,
            'adjusted' => 0,
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

        // Yesterday backwards. Today is left alone on purpose: once a refinery
        // has reported a pilot's ore for the day, the personal import leaves
        // that system's row to this check, so a row removed while the day is
        // still being mined would lose whatever they mine afterwards somewhere
        // else in that system.
        $until = Carbon::yesterday()->endOfDay();
        $since = Carbon::today()->subDays(max(1, $days))->startOfDay();
        $profilesFrom = $this->settings->profilesTaxFrom()?->toDateString();

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
                    ->whereNotNull('o.observer_id');
            })
            // Paged by id, because the loop deletes rows this very query
            // selects on and offset paging would walk straight past the ones
            // that shuffle back.
            ->chunkById(500, function ($rows) use (&$result, &$touched, $dryRun, $moonOwnerCorpId, $profilesFrom) {
                $uncovered = RefineryCoverage::uncovered($rows, $moonOwnerCorpId, $profilesFrom);

                foreach ($rows as $row) {
                    $result['examined']++;

                    // A day somebody has already been billed for is evidence,
                    // not working data. Never touched, whatever it holds.
                    if (InvoiceCoverage::coversRow((int) $row->character_id, $row->date)) {
                        $result['billed_skipped']++;
                        continue;
                    }

                    $left = $uncovered[(int) $row->id] ?? null;
                    if ($left === null || $left === (int) $row->quantity) {
                        continue;
                    }

                    $day = $row->date instanceof Carbon
                        ? $row->date->toDateString()
                        : (string) $row->date;

                    $values = $left > 0
                        ? app(OreValuationService::class)->calculateOreValue((int) $row->type_id, $left, ['mining_date' => $row->date])
                        : [];

                    $result[$left > 0 ? 'adjusted' : 'removed']++;
                    $result['quantity'] += max(0, (int) $row->quantity - $left);
                    $result['value'] += max(0.0, (float) $row->total_value - (float) ($values['total_value'] ?? 0));
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
                            'left' => $left,
                            'value' => (float) $row->total_value,
                        ];
                    }

                    if ($dryRun) {
                        continue;
                    }

                    Log::info('Mining Manager: set a personal mining row to what no refinery accounts for', [
                        'ledger_id' => $row->id,
                        'character_id' => $row->character_id,
                        'date' => $day,
                        'type_id' => $row->type_id,
                        'quantity' => $row->quantity,
                        'left' => $left,
                    ]);

                    if ($left === 0) {
                        $row->delete();
                    } else {
                        $row->update([
                            'quantity' => $left,
                            'unit_price' => $values['unit_price'] ?? 0,
                            'ore_value' => $values['ore_value'] ?? 0,
                            'mineral_value' => $values['mineral_value'] ?? 0,
                            'total_value' => $values['total_value'] ?? 0,
                            'tax_rate' => 0,
                            'tax_amount' => 0,
                            'processed_at' => Carbon::now(),
                        ]);
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
