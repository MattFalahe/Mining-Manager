<?php

namespace MiningManager\Services\Diagnostic;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MiningManager\Models\MiningTax;
use MiningManager\Models\TaxCode;
use MiningManager\Services\Character\AffiliationResolutionService;
use MiningManager\Services\Character\CharacterNames;
use MiningManager\Services\Tax\TaxPeriodHelper;

/**
 * What a Tax Trace can be pointed at: a character found by id or by name, one
 * of an account's bills, or one of the periods the account is billed on.
 *
 * Read-only.
 */
class TaxTraceTargets
{
    /** The most characters a name search offers. */
    public const MATCHES = 20;

    /** How many months of periods are offered, this one included. */
    public const MONTHS = 12;

    protected TaxPeriodHelper $periods;

    public function __construct(TaxPeriodHelper $periods)
    {
        $this->periods = $periods;
    }

    /**
     * Characters whose name holds the text: SeAT's own, then miners outside
     * SeAT the plugin has looked up. A number finds that character id.
     *
     * @return array<int, array{character_id: int, name: string, registered: bool, main_name: string|null}>
     */
    public function findCharacters(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        if (ctype_digit($search)) {
            $id = (int) $search;
            $known = DB::table('character_infos')->where('character_id', $id)->exists()
                || DB::table('mining_ledger')->where('character_id', $id)->exists()
                || DB::table('mining_taxes')->where('character_id', $id)->exists();

            return $known ? $this->describe([$id => null]) : [];
        }

        $like = '%' . addcslashes($search, '%_\\') . '%';

        $found = DB::table('character_infos')
            ->where('name', 'like', $like)
            ->limit(self::MATCHES * 2)
            ->pluck('name', 'character_id')
            ->all();

        // Miners from other corporations are often not in SeAT at all, and
        // their bills are traced as often as anyone's.
        if (Schema::hasTable(AffiliationResolutionService::TABLE)) {
            $outside = DB::table(AffiliationResolutionService::TABLE)
                ->where('character_name', 'like', $like)
                ->limit(self::MATCHES * 2)
                ->pluck('character_name', 'character_id')
                ->all();
            $found += $outside;
        }

        $needle = mb_strtolower($search);
        $rank = function (string $name) use ($needle): int {
            $name = mb_strtolower($name);

            return $name === $needle ? 0 : (strncmp($name, $needle, strlen($needle)) === 0 ? 1 : 2);
        };

        uksort($found, function ($a, $b) use ($found, $rank) {
            return [$rank((string) $found[$a]), (string) $found[$a]] <=> [$rank((string) $found[$b]), (string) $found[$b]];
        });

        return $this->describe(array_slice($found, 0, self::MATCHES, true));
    }

    /**
     * The one character an id or a name points at. A name has to match one
     * character exactly; otherwise the reason comes back with what it did
     * match, to pick from.
     *
     * @return array{character_id: int|null, error: string|null, matches: array}
     */
    public function resolveCharacter($characterId, $name): array
    {
        if ((int) $characterId > 0) {
            return ['character_id' => (int) $characterId, 'error' => null, 'matches' => []];
        }

        $name = trim((string) $name);
        if ($name === '') {
            return ['character_id' => null, 'error' => 'Pick a character, by id or by name, or a bill.', 'matches' => []];
        }

        if (ctype_digit($name)) {
            return ['character_id' => (int) $name, 'error' => null, 'matches' => []];
        }

        $matches = $this->findCharacters($name);
        $exact = array_values(array_filter($matches, fn (array $match) => mb_strtolower($match['name']) === mb_strtolower($name)));

        if (count($exact) === 1) {
            return ['character_id' => $exact[0]['character_id'], 'error' => null, 'matches' => []];
        }

        if (!$matches) {
            return ['character_id' => null, 'error' => "No character is called \"{$name}\" or has it in their name.", 'matches' => []];
        }

        return [
            'character_id' => null,
            'error' => count($exact) > 1
                ? "More than one character is called \"{$name}\". Pick one by id."
                : "No character is called exactly \"{$name}\". Pick one of the characters whose name holds it.",
            'matches' => $matches,
        ];
    }

    /**
     * A bill by its id or by its payment code, with or without the prefix.
     * Deleted bills are found too: a deleted bill still holds its period.
     */
    public function findBill(string $billOrCode): ?MiningTax
    {
        $billOrCode = trim($billOrCode);
        if ($billOrCode === '') {
            return null;
        }

        if (ctype_digit($billOrCode)) {
            return MiningTax::withTrashed()->find((int) $billOrCode);
        }

        $code = strtoupper(ltrim($billOrCode, '#'));
        $billId = TaxCode::where('code', $code)
            ->orWhereRaw("CONCAT(COALESCE(prefix, ''), code) = ?", [$code])
            ->orderByDesc('id')
            ->value('mining_tax_id');

        return $billId ? MiningTax::withTrashed()->find($billId) : null;
    }

    /**
     * Every bill of an account, newest first, deleted ones included. Bills go
     * to the main, but a bill from before the main changed sits on another of
     * the account's characters, so all of them are asked.
     *
     * @param array<int> $characterIds
     */
    public function bills(array $characterIds): array
    {
        $bills = MiningTax::withTrashed()
            ->whereIn('character_id', $characterIds)
            ->orderByRaw('COALESCE(period_start, month) DESC')
            ->orderByDesc('id')
            ->get();

        return $this->describeBills($bills);
    }

    /**
     * Bills as the trace shows them.
     *
     * @param iterable<MiningTax> $bills
     */
    public function describeBills(iterable $bills): array
    {
        $bills = collect($bills);
        if ($bills->isEmpty()) {
            return [];
        }

        $codes = TaxCode::whereIn('mining_tax_id', $bills->pluck('id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy('mining_tax_id');

        $profiles = $bills->pluck('tax_profile')
            ->filter(fn ($profile) => $profile !== null && $profile !== MiningTax::GLOBAL_PROFILE)
            ->map(fn ($profile) => (int) $profile)
            ->unique()
            ->values()
            ->all();
        $corporations = $profiles
            ? DB::table('corporation_infos')->whereIn('corporation_id', $profiles)->get(['corporation_id', 'name', 'ticker'])->keyBy('corporation_id')
            : collect();

        $letOffBy = $bills->filter(fn (MiningTax $bill) => $bill->isWrittenOff() && $bill->written_off_by)
            ->pluck('written_off_by')
            ->unique()
            ->values()
            ->all();
        $userNames = $letOffBy
            ? DB::table('users')->whereIn('id', $letOffBy)->pluck('name', 'id')
            : collect();

        return $bills->map(function (MiningTax $bill) use ($codes, $corporations, $userNames) {
            $start = Carbon::parse($bill->period_start ?? $bill->month);
            $end = $bill->period_end ? Carbon::parse($bill->period_end) : $start->copy()->endOfMonth();
            $type = $bill->period_type ?? 'monthly';
            $code = $codes->get($bill->id);

            if ($bill->tax_profile === null) {
                $billedBy = 'Before tax profiles';
            } elseif ($bill->tax_profile === MiningTax::GLOBAL_PROFILE) {
                $billedBy = 'Global';
            } else {
                $corporation = $corporations->get((int) $bill->tax_profile);
                $billedBy = $corporation ? "[{$corporation->ticker}] {$corporation->name}" : "Corporation {$bill->tax_profile}";
            }

            return [
                'id' => $bill->id,
                'character_id' => (int) $bill->character_id,
                'amount_owed' => (float) $bill->amount_owed,
                'amount_paid' => (float) $bill->amount_paid,
                'status' => $bill->status,
                'written_off' => $bill->getWrittenOffAmount(),
                'written_off_at' => $bill->isWrittenOff() && $bill->written_off_at ? $bill->written_off_at->format('Y-m-d H:i') : null,
                'written_off_by' => $bill->isWrittenOff() && $bill->written_off_by ? ($userNames[$bill->written_off_by] ?? null) : null,
                'written_off_reason' => $bill->isWrittenOff() ? $bill->written_off_reason : null,
                'deleted' => $bill->trashed(),
                'due_date' => $bill->due_date ? $bill->due_date->format('Y-m-d') : null,
                'paid_at' => $bill->paid_at ? $bill->paid_at->format('Y-m-d H:i') : null,
                'calculated_at' => $bill->calculated_at ? $bill->calculated_at->format('Y-m-d H:i') : null,
                'triggered_by' => $bill->triggered_by,
                'tax_code' => $code ? $code->getFullCode() : null,
                'billed_by' => $billedBy,
                'notes' => $bill->notes,
                'period_type' => $type,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'period_label' => $this->periods->formatPeriod($start, $end, $type),
            ];
        })->values()->all();
    }

    /**
     * The periods an account is billed on, newest first, to trace one of. The
     * periods of its bills as they were billed, and for the days no bill
     * covers, the periods its tax profile bills on, back MONTHS months.
     *
     * @param array $bills from bills()
     */
    public function periods(int $characterId, array $bills): array
    {
        $today = Carbon::today();
        $derived = [];

        for ($back = self::MONTHS - 1; $back >= 0; $back--) {
            $month = $today->copy()->startOfMonth()->subMonthsNoOverflow($back);
            $type = $this->periods->periodTypeForCharacter($characterId, $month);

            foreach ($this->periods->getPeriodsInMonth($month, $type) as [$start, $end]) {
                $derived[] = ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'type' => $type];
            }
        }

        return array_map(function (array $period) {
            $period['label'] = $this->periods->formatPeriod(Carbon::parse($period['start']), Carbon::parse($period['end']), $period['type']);

            return $period;
        }, self::periodChoices($bills, $derived, $today->toDateString()));
    }

    /**
     * Bills' periods, then the profile's periods for the days none of them
     * covers. A month billed once as a whole is offered as that month, even
     * when the profile bills twice a month now. Periods not started yet are
     * left out; one still running is marked open.
     *
     * @param array<int, array{id: int, period_start: string, period_end: string, period_type: string}> $bills
     * @param array<int, array{start: string, end: string, type: string}> $derived
     * @return array<int, array{start: string, end: string, type: string, bills: array<int>, open: bool}>
     */
    public static function periodChoices(array $bills, array $derived, string $today): array
    {
        $choices = [];

        foreach ($bills as $bill) {
            $key = $bill['period_start'] . '|' . $bill['period_end'];
            $choices[$key] ??= ['start' => $bill['period_start'], 'end' => $bill['period_end'], 'type' => $bill['period_type'], 'bills' => []];
            $choices[$key]['bills'][] = (int) $bill['id'];
        }

        $billed = array_values($choices);

        foreach ($derived as $period) {
            if ($period['start'] > $today) {
                continue;
            }

            foreach ($billed as $choice) {
                if ($period['start'] <= $choice['end'] && $period['end'] >= $choice['start']) {
                    continue 2;
                }
            }

            $choices[$period['start'] . '|' . $period['end']] ??= $period + ['bills' => []];
        }

        $choices = array_values($choices);
        usort($choices, fn (array $a, array $b) => [$b['start'], $b['end']] <=> [$a['start'], $a['end']]);

        return array_map(fn (array $choice) => $choice + ['open' => $choice['end'] >= $today], $choices);
    }

    /**
     * Names, and the main of each alt, for characters found.
     *
     * @param array<int, string|null> $found character_id => name, when known
     */
    private function describe(array $found): array
    {
        $ids = array_map('intval', array_keys($found));
        $names = app(CharacterNames::class);
        $names->preload($ids);

        $registered = DB::table('character_infos')->whereIn('character_id', $ids)->pluck('character_id')->map(fn ($id) => (int) $id)->all();
        $mains = DB::table('refresh_tokens as rt')
            ->join('users as u', 'u.id', '=', 'rt.user_id')
            ->whereIn('rt.character_id', $ids)
            ->pluck('u.main_character_id', 'rt.character_id')
            ->all();
        $names->preload(array_filter(array_map('intval', $mains)));

        $described = [];
        foreach ($found as $id => $name) {
            $id = (int) $id;
            $main = (int) ($mains[$id] ?? 0);

            $described[] = [
                'character_id' => $id,
                'name' => (string) ($name ?: $names->nameOrId($id)),
                'registered' => in_array($id, $registered, true),
                'main_name' => $main && $main !== $id ? $names->nameOrId($main) : null,
            ];
        }

        return $described;
    }
}
