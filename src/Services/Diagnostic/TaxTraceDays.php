<?php

namespace MiningManager\Services\Diagnostic;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MiningManager\Services\Character\CorporationOnDate;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Ledger\LedgerSummaryService;

/**
 * The days behind a Tax Trace: who taxed each one, and what each would hold
 * if it were built again now, set beside what is stored.
 *
 * Read-only. Days are rebuilt by the same code that builds them for billing,
 * without saving, so a difference here is exactly what a rebuild would
 * change.
 */
class TaxTraceDays
{
    protected SettingsManagerService $settings;
    protected LedgerSummaryService $summaries;

    /** @var array{owner: int|null, from: Carbon|null}|null */
    private ?array $context = null;

    /** @var array<int, object> corporation_id => name and ticker */
    private array $names = [];

    /** @var array<string, array> 'character_id|date' => taxedBy() */
    private array $days = [];

    public function __construct(SettingsManagerService $settings, LedgerSummaryService $summaries)
    {
        $this->settings = $settings;
        $this->summaries = $summaries;
    }

    /**
     * Who taxed a pilot's mining on a day, by the rule the day's summary is
     * built with: from the day settings moved into tax profiles, the profile
     * of the corporation the pilot was in that day; before it, the rules
     * from before.
     *
     * @return array{label: string, selector: array<string, mixed>}
     */
    public function taxedBy(int $characterId, Carbon $day): array
    {
        return $this->days[$characterId . '|' . $day->toDateString()] ??= $this->ruleFor($characterId, $day->copy()->startOfDay());
    }

    private function ruleFor(int $characterId, Carbon $day): array
    {
        $context = $this->context();

        if ($context['from'] === null || $day->lt($context['from'])) {
            // Mining at our refineries was taxed as the Moon Owner taxed it.
            return [
                'label' => 'Rules from before tax profiles',
                'selector' => $this->settings->taxSelectorFor($context['owner']),
            ];
        }

        $members = $this->settings->memberCorporationsOn($day);
        $corporationId = app(CorporationOnDate::class)->forDay($characterId, $day, $members);
        $corporationId = $corporationId ? (int) $corporationId : null;
        $profile = $this->settings->profileFor($corporationId, $day);

        if (!$members) {
            $label = 'Global, which taxes everyone while no corporation is configured';
        } elseif ($profile === null) {
            $label = 'Global, as a non-member';
        } elseif ($corporationId === null) {
            $label = $this->name($profile) . ', as the corporation is not known yet';
        } else {
            $label = $this->name($profile);
        }

        return ['label' => $label, 'selector' => $this->settings->taxSelectorFor($profile)];
    }

    /**
     * Every day the characters mined between two dates, or have a summary
     * for, built again now and set beside what is stored.
     *
     * @param array<int> $characterIds
     * @param Collection $stored the stored daily summaries for those days
     */
    public function rebuild(array $characterIds, Carbon $from, Carbon $to, Collection $stored): array
    {
        $storedDays = [];
        foreach ($stored as $summary) {
            $storedDays[(int) $summary->character_id . '|' . Carbon::parse($summary->date)->toDateString()] = $summary;
        }

        $mined = DB::table('mining_ledger')
            ->whereIn('character_id', $characterIds)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->selectRaw('character_id, DATE(date) AS day')
            ->distinct()
            ->get()
            ->map(fn ($row) => (int) $row->character_id . '|' . $row->day)
            ->all();

        $keys = array_unique(array_merge($mined, array_keys($storedDays)));
        sort($keys);

        $days = [];
        $totals = ['stored_tax' => 0.0, 'rebuilt_tax' => 0.0, 'stored_value' => 0.0, 'rebuilt_value' => 0.0];

        foreach ($keys as $key) {
            [$characterId, $date] = explode('|', $key);
            $characterId = (int) $characterId;

            $rebuilt = $this->summaries->previewDailySummary($characterId, $date);
            $was = $storedDays[$key] ?? null;

            $storedTax = $was ? round((float) $was->total_tax, 2) : null;
            $rebuiltTax = round((float) $rebuilt['total_tax'], 2);

            $totals['stored_tax'] += (float) $storedTax;
            $totals['rebuilt_tax'] += $rebuiltTax;
            $totals['stored_value'] += $was ? (float) $was->total_value : 0;
            $totals['rebuilt_value'] += (float) $rebuilt['total_value'];

            $days[] = [
                'date' => $date,
                'character_id' => $characterId,
                'taxed_by' => $this->taxedBy($characterId, Carbon::parse($date))['label'],
                'stored' => $was !== null,
                'stored_value' => $was ? round((float) $was->total_value, 2) : null,
                'rebuilt_value' => round((float) $rebuilt['total_value'], 2),
                'stored_tax' => $storedTax,
                'rebuilt_tax' => $rebuiltTax,
                'difference' => round($rebuiltTax - (float) $storedTax, 2),
                'ores' => array_values($rebuilt['ore_types'] ?? []),
            ];
        }

        return [
            'days' => $days,
            'totals' => array_map(fn (float $amount) => round($amount, 2), $totals),
        ];
    }

    /**
     * What every day of a trace reads the same: the Moon Owner and the first
     * day taxed by profiles. Names are loaded for the configured corporations
     * and for those taken off the list, whose profiles tax the days before
     * they left.
     */
    private function context(): array
    {
        if ($this->context === null) {
            $this->names = DB::table('corporation_infos')
                ->whereIn('corporation_id', array_merge($this->settings->getHomeCorporationIds(), array_keys($this->settings->configuredUntil())))
                ->get(['corporation_id', 'name', 'ticker'])
                ->keyBy('corporation_id')
                ->all();

            $this->context = [
                'owner' => (int) $this->settings->getSettingForCorporation('general.moon_owner_corporation_id', null) ?: null,
                'from' => $this->settings->profilesTaxFrom(),
            ];
        }

        return $this->context;
    }

    private function name(int $corporationId): string
    {
        $corporation = $this->names[$corporationId] ?? null;

        return $corporation ? "[{$corporation->ticker}] {$corporation->name}" : "Corporation {$corporationId}";
    }
}
