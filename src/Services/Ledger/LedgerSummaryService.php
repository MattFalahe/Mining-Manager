<?php

namespace MiningManager\Services\Ledger;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningLedgerMonthlySummary;
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventMiningRecord;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use Seat\Eveapi\Models\Sde\SolarSystem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Tax\InvoiceCoverage;
use MiningManager\Services\OreClassifier;
use MiningManager\Services\Character\CorporationMembers;
use MiningManager\Services\Character\CorporationOnDate;
use MiningManager\Services\Analytics\ChartFilter;

class LedgerSummaryService
{
    /** The Mining Summary's two questions: what a corporation's miners mined... */
    public const SCOPE_MEMBERS = 'members';

    /** ...and what came off a corporation's refineries. */
    public const SCOPE_MOONS = 'moons';

    /** Moon ore classes, as the ledger stamps them (moon_r4 to moon_r64). */
    public const MOON_CLASSES = ['r4', 'r8', 'r16', 'r32', 'r64'];

    /**
     * What the members' view can be narrowed to, as the Performance Charts
     * slice it: where moon ore came from, or one kind of ore.
     *
     * @var array<string, array{0: string, 1: string|null}> key => [moon source, ore category]
     */
    public const ORE_FILTERS = [
        'all' => [ChartFilter::MOON_ALL, null],
        'my_moons' => [ChartFilter::MOON_MINE, null],
        'other_moons' => [ChartFilter::MOON_OTHER, null],
        'any_moons' => [ChartFilter::MOON_ANY, null],
        'ore' => [ChartFilter::MOON_ALL, 'ore'],
        'ice' => [ChartFilter::MOON_ALL, 'ice'],
        'gas' => [ChartFilter::MOON_ALL, 'gas'],
        'abyssal' => [ChartFilter::MOON_ALL, 'abyssal'],
        'triglavian' => [ChartFilter::MOON_ALL, 'triglavian'],
    ];

    /**
     * Settings manager for tax rate lookups.
     *
     * @var SettingsManagerService
     */
    protected SettingsManagerService $settingsService;

    /**
     * Constructor.
     *
     * @param SettingsManagerService $settingsService
     */
    public function __construct(SettingsManagerService $settingsService)
    {
        $this->settingsService = $settingsService;
    }
    /**
     * Generate monthly summary for a specific character and month
     *
     * @param int $characterId
     * @param string $month YYYY-MM format
     * @return MiningLedgerMonthlySummary
     */
    public function generateMonthlySummary(int $characterId, string $month): MiningLedgerMonthlySummary
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        // Aggregate data from mining_ledger (only processed entries with calculated values)
        $summary = MiningLedger::where('character_id', $characterId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->whereNotNull('processed_at')
            ->selectRaw('
                character_id,
                MAX(corporation_id) as corporation_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(CASE WHEN is_moon_ore = 1 THEN total_value ELSE 0 END) as moon_ore_value,
                SUM(CASE WHEN is_ice = 1 THEN total_value ELSE 0 END) as ice_value,
                SUM(CASE WHEN is_gas = 1 THEN total_value ELSE 0 END) as gas_value,
                SUM(CASE WHEN is_abyssal = 1 THEN total_value ELSE 0 END) as abyssal_ore_value,
                SUM(CASE WHEN is_triglavian = 1 THEN total_value ELSE 0 END) as triglavian_ore_value,
                SUM(CASE WHEN is_moon_ore = 0 AND is_ice = 0 AND is_gas = 0 AND is_abyssal = 0 AND is_triglavian = 0 THEN total_value ELSE 0 END) as regular_ore_value
            ')
            ->groupBy('character_id')
            ->first();

        if (!$summary) {
            // No mining data for this character/month
            return MiningLedgerMonthlySummary::create([
                'character_id' => $characterId,
                'month' => $monthDate,
                'corporation_id' => null,
                'total_quantity' => 0,
                'total_value' => 0,
                'total_tax' => 0,
                'moon_ore_value' => 0,
                'regular_ore_value' => 0,
                'ice_value' => 0,
                'gas_value' => 0,
                'abyssal_ore_value' => 0,
                'triglavian_ore_value' => 0,
                'ore_breakdown' => [],
                'is_finalized' => true,
                'finalized_at' => now(),
            ]);
        }

        // Get ore breakdown (only processed entries)
        $oreBreakdown = MiningLedger::where('character_id', $characterId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->whereNotNull('processed_at')
            ->selectRaw('ore_type, type_id, SUM(quantity) as total_quantity, SUM(total_value) as total_value')
            ->groupBy('ore_type', 'type_id')
            ->get()
            ->toArray();

        // Get total_tax from daily summaries (single source of truth)
        $totalTax = (float) MiningLedgerDailySummary::where('character_id', $characterId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->sum('total_tax');

        // Create or update monthly summary
        return MiningLedgerMonthlySummary::updateOrCreate(
            [
                'character_id' => $characterId,
                'month' => $monthDate,
            ],
            [
                'corporation_id' => $summary->corporation_id,
                'total_quantity' => $summary->total_quantity,
                'total_value' => $summary->total_value,
                'total_tax' => $totalTax,
                'moon_ore_value' => $summary->moon_ore_value,
                'regular_ore_value' => $summary->regular_ore_value,
                'ice_value' => $summary->ice_value,
                'gas_value' => $summary->gas_value,
                'abyssal_ore_value' => $summary->abyssal_ore_value,
                'triglavian_ore_value' => $summary->triglavian_ore_value,
                'ore_breakdown' => $oreBreakdown,
                'is_finalized' => true,
                'finalized_at' => now(),
            ]
        );
    }

    /**
     * Generate daily summary for a specific character and date.
     *
     * Builds a rich ore breakdown with per-ore estimated tax calculations
     * and stores it in the ore_types JSON column. This enables live tax
     * tracking — players can see what they mined, what it was worth at
     * the day's market price, and how much tax they'll owe for that day.
     *
     * Daily summaries are the SINGLE SOURCE OF TRUTH for tax calculations.
     * All consumers (My Taxes, Calculate Taxes, Tax Overview) read from here.
     * Tax rates are always resolved from current settings, not from stored
     * mining_ledger.tax_amount values.
     *
     * @param int $characterId
     * @param string $date YYYY-MM-DD format
     * @return MiningLedgerDailySummary
     */
    public function generateDailySummary(int $characterId, string $date): MiningLedgerDailySummary
    {
        // Rates are read in the corporation each entry belongs to, so the
        // settings context moves while a day is built. It goes back to the
        // caller's afterwards: Recalculate rebuilds a month of days and then
        // reads the exemption threshold and minimum for the moon owner, and a
        // context left on the last character's corporation answered instead.
        $callerContext = $this->settingsService->getActiveCorporation();

        try {
            return $this->buildDailySummary($characterId, $date);
        } finally {
            $this->settingsService->setActiveCorporation($callerContext);
        }
    }

    protected function buildDailySummary(int $characterId, string $date): MiningLedgerDailySummary
    {
        $dateCarbon = Carbon::parse($date);

        // Only aggregate entries that have been processed (have prices calculated).
        // Unprocessed entries have total_value = 0 and would produce zero-value summaries.
        $baseQuery = MiningLedger::where('character_id', $characterId)
            ->whereDate('date', $dateCarbon)
            ->whereNotNull('processed_at');

        $hasData = (clone $baseQuery)->exists();

        if (!$hasData) {
            // No processed mining data — create/update empty summary
            return MiningLedgerDailySummary::updateOrCreate(
                [
                    'character_id' => $characterId,
                    'date' => $dateCarbon,
                ],
                [
                    'corporation_id' => null,
                    'total_quantity' => 0,
                    'total_value' => 0,
                    'total_tax' => 0,
                    'moon_ore_value' => 0,
                    'regular_ore_value' => 0,
                    'ice_value' => 0,
                    'gas_value' => 0,
                    'abyssal_ore_value' => 0,
                    'triglavian_ore_value' => 0,
                    'ore_types' => [],
                    'is_finalized' => !$dateCarbon->isSameMonth(now()),
                ]
            );
        }

        // Moon Owner Corporation is a GLOBAL setting (not corp-context dependent).
        // Read it directly to avoid dependency on active corporation context.
        $moonOwnerCorpId = $this->settingsService->getSettingForCorporation(
            'general.moon_owner_corporation_id', null
        );

        // Ensure corporation context is set for reading tax rates and other corp-specific settings
        if (!$this->settingsService->getActiveCorporation() && $moonOwnerCorpId) {
            $this->settingsService->setActiveCorporation((int) $moonOwnerCorpId);
        }

        // Get list of configured corporations (for character ledger tax filtering)
        $homeCorporationIds = $this->settingsService->getHomeCorporationIds();

        // The corporation the character was in that day, not the one they are
        // in now. It decides member or guest rates, whether personal mining is
        // taxed at all, and which corporation's events apply, so a member who
        // leaves keeps a member's tax on the days they mined as one, however
        // often those days are rebuilt afterwards.
        $characterCorpId = app(CorporationOnDate::class)->forDay($characterId, $dateCarbon, $homeCorporationIds);

        // Get corporation_id for the summary — prefer Moon Owner Corp if entries exist
        $corporationId = (clone $baseQuery)
            ->whereNotNull('corporation_id')
            ->when($moonOwnerCorpId, function ($q) use ($moonOwnerCorpId) {
                return $q->where('corporation_id', $moonOwnerCorpId);
            })
            ->value('corporation_id');

        // Event modifier is now fetched INSIDE the ore-entry loop so that each
        // ore category is evaluated against the event's type. A "Moon Extraction"
        // event applies only to moon ore entries, "Ice Mining" only to ice, etc.
        // "Special" events apply to everything (see MiningEvent::appliesToOreCategory).

        // Aggregate per ore type AND corporation for the day (only processed entries)
        // Group by corporation_id so entries from different structure owners get correct tax rates
        $oreEntries = (clone $baseQuery)
            ->selectRaw('
                type_id,
                ore_type,
                corporation_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value
            ')
            ->groupBy('type_id', 'ore_type', 'corporation_id')
            ->get();

        // Build rich ore breakdown with tax from current settings
        $oreBreakdown = [];
        $totalQuantity = 0;
        $totalValue = 0;
        $totalTax = 0;
        $eventDiscountTotal = 0;
        $moonOreValue = 0;
        $regularOreValue = 0;
        $iceValue = 0;
        $gasValue = 0;
        $abyssalOreValue = 0;
        $triglavianOreValue = 0;

        foreach ($oreEntries as $entry) {
            $typeId = $entry->type_id;
            $quantity = $entry->total_quantity;
            $value = $entry->total_value;
            $unitPrice = $quantity > 0 ? ($value / $quantity) : 0;

            // Determine ore category and name
            $oreName = $this->getOreName($typeId);
            $category = $this->getOreCategory($typeId);
            $moonRarity = TypeIdRegistry::isMoonOre($typeId)
                ? TypeIdRegistry::getMoonOreRarity($typeId)
                : null;

            $entryCorporationId = $entry->corporation_id;

            // ================================================================
            // Corporation filtering: only process relevant entries
            // ================================================================
            if ($entryCorporationId !== null) {
                // Observer data — ONLY process from Moon Owner Corp's structures
                if ((int) $entryCorporationId !== (int) ($moonOwnerCorpId ?? 0)) {
                    continue; // Skip entries from other corps' observers (e.g. Mount Othrys)
                }
                // Moon observer entry: use Moon Owner Corp context for settings
                if ($moonOwnerCorpId) {
                    $this->settingsService->setActiveCorporation((int) $moonOwnerCorpId);
                }
            } else {
                // Character ledger entry (NULL corporation_id)
                // Only tax if the character belongs to a configured corporation
                if (!$characterCorpId || (
                    !empty($homeCorporationIds)
                    && !in_array((int) $characterCorpId, $homeCorporationIds, true)
                )) {
                    continue; // Character not in SeAT or not in any configured corp — skip
                }
                // Use character's own corp context for rates
                $this->settingsService->setActiveCorporation((int) $characterCorpId);
            }

            // Determine taxability based on ore type and tax selector settings
            if ($entryCorporationId !== null) {
                // Moon observer entry — use shouldTaxType (handles only_corp_moon_ore etc.)
                $isTaxable = $this->shouldTaxType($typeId, $entryCorporationId, $moonOwnerCorpId);
            } else {
                // Character ledger — moon ore without observer data is not taxable yet
                // (it will become taxable when observer data arrives and reconciliation runs)
                // Non-moon ore types are taxable based on tax selector
                $isTaxable = !TypeIdRegistry::isMoonOre($typeId)
                    && $this->shouldTaxType($typeId, null, $moonOwnerCorpId);
            }
            $baseTaxRate = $isTaxable ? $this->getTaxRateForType($typeId, $characterCorpId) : 0;

            // Phase 2 per-row attribution: look up how much of this (char, date, type)
            // mining is event-qualified via event_mining_records. The event modifier
            // applies ONLY to that qualified slice — the rest of the day's mining
            // of the same ore type pays normal tax. Previously the modifier was
            // a day-wide boolean (char in event_participants + ore category match),
            // so all of a participant's matching-category mining that day got the
            // discount — too broad for sub-day events.
            $attribution = $isTaxable
                ? $this->getEventAttributionForLedgerRow(
                    $characterId, $dateCarbon, $typeId, $characterCorpId, $category
                )
                : null;

            $eventModifier = 0;
            $eventQualifiedValue = 0;
            $eventDiscountAmount = 0;
            $eventId = null;

            if ($attribution === null) {
                // No event overlap — full base rate on the whole entry.
                $effectiveRate = $baseTaxRate;
                $estimatedTax = $isTaxable ? $value * ($baseTaxRate / 100) : 0;
            } else {
                $eventModifier = $attribution['modifier'];
                $eventId = $attribution['event_id'];

                // Cap qualified value at the ledger row's total — event_mining_records
                // is built from character_minings which may not perfectly equal the
                // aggregated mining_ledger.total_value (rounding, reconciliation gaps).
                $eventQualifiedValue = min((float) $attribution['qualified_value'], (float) $value);
                $eventQualifiedValue = max(0.0, $eventQualifiedValue);
                $nonEventValue = max(0.0, (float) $value - $eventQualifiedValue);

                $modifiedRate = max(0, $baseTaxRate * (1 + ($eventModifier / 100)));

                $eventPortionTax = $eventQualifiedValue * ($modifiedRate / 100);
                $nonEventPortionTax = $nonEventValue * ($baseTaxRate / 100);
                $estimatedTax = $eventPortionTax + $nonEventPortionTax;

                // Effective rate is blended across event + non-event portions.
                $effectiveRate = $value > 0 ? ($estimatedTax / $value) * 100 : $baseTaxRate;

                // Discount amount = what we would have charged at base rate minus
                // what we actually charge, on the event-qualified slice only.
                $eventDiscountAmount = $eventQualifiedValue * (($baseTaxRate - $modifiedRate) / 100);
                // Guard against inverted sign when an event is a tax INCREASE (positive modifier).
                if ($eventModifier > 0) {
                    $eventDiscountAmount = 0; // "Discount" is zero when tax was raised, not reduced.
                }
            }

            $eventDiscountTotal += $eventDiscountAmount;

            $oreBreakdown[] = [
                'type_id' => $typeId,
                'ore_name' => $oreName,
                'category' => $category,
                'moon_rarity' => $moonRarity,
                'quantity' => (float) $quantity,
                'unit_price' => round($unitPrice, 2),
                'total_value' => round((float) $value, 2),
                'tax_rate' => $baseTaxRate,
                'event_modifier' => $eventModifier,
                'event_id' => $eventId,
                'event_qualified_value' => round($eventQualifiedValue, 2),
                'event_discount_amount' => round($eventDiscountAmount, 2),
                'effective_rate' => round($effectiveRate, 2),
                'is_taxable' => $isTaxable,
                'estimated_tax' => round($estimatedTax, 2),
            ];

            // Accumulate totals
            $totalQuantity += $quantity;
            $totalValue += $value;
            $totalTax += $estimatedTax;

            // Category breakdown. Matches the specific-rarity output from
            // getOreCategory (moon_r4..moon_r64 instead of the old 'moon_ore').
            // Abyssal + Triglavian got dedicated columns in migration 000009 —
            // before that they were silently rolled into regular_ore_value
            // and invisible in dashboard charts.
            if (str_starts_with($category, 'moon_')) {
                $moonOreValue += $value;
            } elseif ($category === 'ice') {
                $iceValue += $value;
            } elseif ($category === 'gas') {
                $gasValue += $value;
            } elseif ($category === 'abyssal') {
                $abyssalOreValue += $value;
            } elseif ($category === 'triglavian') {
                $triglavianOreValue += $value;
            } else {
                // True regular ore — vanilla high/low/null sec ores
                $regularOreValue += $value;
            }
        }

        // Current month = not finalized (still in progress)
        $isFinalized = !$dateCarbon->isSameMonth(now());

        // Once an invoice covering this day has gone out, the tax on it is a
        // fact rather than a calculation. Volume and value still refresh, so
        // late-arriving mining shows up as the real thing it is, but the tax
        // stays at what the member was actually billed.
        //
        // Freezing the whole row instead would leave the day's tonnage looking
        // wrong, which is its own kind of confusion. This keeps the numbers
        // honest and the money settled.
        //
        // is_finalized is no use for this: it is set from the calendar month,
        // so on a fortnightly cycle a period can be billed and paid a fortnight
        // before the flag turns over. InvoiceCoverage reads the invoice periods
        // themselves and does not care how long they are.
        $billed = InvoiceCoverage::coversRow($characterId, $dateCarbon->toDateString());

        if ($billed) {
            $existing = MiningLedgerDailySummary::where('character_id', $characterId)
                ->whereDate('date', $dateCarbon->toDateString())
                ->first();

            if ($existing) {
                $totalTax = (float) $existing->total_tax;
                $eventDiscountTotal = (float) $existing->event_discount_total;
            }
        }

        // Create or update daily summary
        return MiningLedgerDailySummary::updateOrCreate(
            [
                'character_id' => $characterId,
                'date' => $dateCarbon,
            ],
            [
                'corporation_id' => $corporationId,
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue,
                'total_tax' => round($totalTax, 2),
                'event_discount_total' => round($eventDiscountTotal, 2),
                'moon_ore_value' => $moonOreValue,
                'regular_ore_value' => $regularOreValue,
                'ice_value' => $iceValue,
                'gas_value' => $gasValue,
                'abyssal_ore_value' => $abyssalOreValue,
                'triglavian_ore_value' => $triglavianOreValue,
                'ore_types' => $oreBreakdown,
                'is_finalized' => $isFinalized,
            ]
        );
    }

    /**
     * Phase 2: look up event attribution for a specific ledger slice.
     *
     * Given a (character, date, type_id, corp, ore_category) tuple
     * representing one aggregated ore entry within a daily summary,
     * return the best-matching event's modifier alongside the exact
     * quantity and ISK value of mining that was event-qualified
     * (from event_mining_records, which EventMiningAggregator populated
     * with time/location/corp/category filters already applied).
     *
     * Event picking: if multiple active events apply, the one with the
     * best (most negative) modifier wins — mirrors the prior behaviour
     * of getEventModifierForDate's ORDER BY tax_modifier ASC.
     *
     * Returns null when no event applies to this slice, so callers can
     * short-circuit and charge full base tax.
     *
     * @return array{event_id:int, modifier:int, qualified_quantity:int, qualified_value:float}|null
     */
    private function getEventAttributionForLedgerRow(
        int $characterId,
        Carbon $date,
        int $typeId,
        ?int $characterCorpId,
        ?string $oreCategory
    ): ?array {
        if (!$oreCategory) {
            return null;
        }

        try {
            // Candidate events: any event whose lifecycle covers this date,
            // corp-compatible. We pick the one with the best modifier that
            // also has actual qualified mining in event_mining_records for
            // this (char, date, type).
            //
            // IMPORTANT: include 'completed' events here, not just 'active'.
            // Daily summaries get regenerated retroactively (via cron or
            // `mining-manager:update-daily-summaries --days=N`), and by the
            // time that happens for a past date, the event that covered that
            // date is almost always 'completed'. Filtering to 'active' only
            // meant the modifier silently stopped applying the moment an
            // event ended — retroactive summary rebuilds returned zero
            // event_discount_total, which then zeroed out the dashboard
            // chart too. Exclude only 'planned' (hasn't started) and
            // 'cancelled'.
            //
            // $date is the start of the mining day, so an event counts if it
            // starts any time that day. Compared with midnight, an evening op
            // missed its own first day and a one-day op never applied.
            $events = MiningEvent::whereIn('status', ['active', 'completed'])
                ->where('start_time', '<=', $date->copy()->endOfDay())
                ->where(function ($q) use ($date) {
                    $q->whereNull('end_time')->orWhere('end_time', '>=', $date);
                })
                ->where(function ($q) use ($characterCorpId) {
                    $q->whereNull('corporation_id')
                      ->orWhere('corporation_id', $characterCorpId);
                })
                ->orderBy('tax_modifier', 'asc')
                ->get();

            if ($events->isEmpty()) {
                return null;
            }

            foreach ($events as $event) {
                if (!$event->appliesToOreCategory($oreCategory)) {
                    continue;
                }

                // Sum qualified quantity + value from event_mining_records for
                // this specific slice. Indexed by (character_id, mining_date,
                // type_id, solar_system_id) per migration 000005.
                $agg = EventMiningRecord::where('event_id', $event->id)
                    ->where('character_id', $characterId)
                    ->where('mining_date', $date->toDateString())
                    ->where('type_id', $typeId)
                    ->selectRaw('SUM(quantity) as qty, SUM(value_isk) as val')
                    ->first();

                if ($agg && $agg->qty > 0) {
                    return [
                        'event_id' => (int) $event->id,
                        'modifier' => (int) $event->tax_modifier,
                        'qualified_quantity' => (int) $agg->qty,
                        'qualified_value' => (float) $agg->val,
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            // event_mining_records may not exist on installs that haven't
            // migrated yet — fall back to "no attribution" silently.
            Log::debug("Mining Manager: getEventAttributionForLedgerRow soft-failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get tax rate for a specific ore type.
     * Respects per-rarity moon ore rates and guest miner multiplier.
     *
     * Public, with shouldTaxType(), so the Extraction Simulator's income
     * estimate charges what an invoice would rather than keeping a copy.
     *
     * @param int $typeId
     * @param int|null $characterCorpId
     * @return float Tax rate as percentage (0-100)
     */
    public function getTaxRateForType(int $typeId, ?int $characterCorpId = null): float
    {
        $taxRates = $this->settingsService->getTaxRatesForCorporation($characterCorpId);

        if (TypeIdRegistry::isMoonOre($typeId)) {
            $rarity = TypeIdRegistry::getMoonOreRarity($typeId);
            if ($rarity) {
                $rarityKey = strtolower($rarity);
                return (float) ($taxRates['moon_ore'][$rarityKey] ?? $taxRates['moon_ore']['r4'] ?? 5.0);
            }
            return (float) ($taxRates['moon_ore']['r4'] ?? 5.0);
        }

        if (TypeIdRegistry::isIce($typeId)) {
            return (float) ($taxRates['ice'] ?? 10.0);
        }

        if (TypeIdRegistry::isGas($typeId)) {
            return (float) ($taxRates['gas'] ?? 10.0);
        }

        if (OreClassifier::isAbyssal($typeId)) {
            return (float) ($taxRates['abyssal_ore'] ?? 15.0);
        }

        if (TypeIdRegistry::isTriglavianOre($typeId)) {
            return (float) ($taxRates['triglavian_ore'] ?? 10.0);
        }

        return (float) ($taxRates['ore'] ?? 10.0);
    }

    /**
     * Check if an ore type should be taxed based on settings.
     *
     * Supports only_corp_moon_ore by comparing the entry's structure owner
     * corporation against the configured moon owner corporation.
     *
     * @param int $typeId
     * @param int|null $entryCorporationId Structure owner corporation
     * @param int|null $moonOwnerCorpId Configured moon owner corporation
     * @return bool
     */
    public function shouldTaxType(int $typeId, ?int $entryCorporationId = null, ?int $moonOwnerCorpId = null): bool
    {
        $taxSelector = $this->settingsService->getTaxSelector();

        if (TypeIdRegistry::isMoonOre($typeId)) {
            if (!empty($taxSelector['no_moon_ore'])) {
                return false;
            }

            // only_corp_moon_ore: only tax moon ore from the configured moon owner corporation
            if (!empty($taxSelector['only_corp_moon_ore'])) {
                if (!$entryCorporationId || !$moonOwnerCorpId) {
                    return false;
                }
                return (int) $entryCorporationId === (int) $moonOwnerCorpId;
            }

            return $taxSelector['all_moon_ore'] ?? true;
        }

        if (TypeIdRegistry::isIce($typeId)) {
            return $taxSelector['ice'] ?? true;
        }

        if (TypeIdRegistry::isGas($typeId)) {
            // false to match getTaxSelector(), which is the only source this
            // reads from. Unreachable while that returns every key, but two
            // opposite defaults for the same setting read as disagreement.
            return $taxSelector['gas'] ?? false;
        }

        if (OreClassifier::isAbyssal($typeId)) {
            return $taxSelector['abyssal_ore'] ?? false;
        }

        if (TypeIdRegistry::isTriglavianOre($typeId)) {
            return $taxSelector['triglavian_ore'] ?? false;
        }

        return $taxSelector['ore'] ?? true;
    }

    // NOTE: the prior getEventModifierForDate() method was removed in
    // Phase 2 of the event_mining_records refactor. It used a day-wide
    // gate ("is this character in event_participants and did they mine
    // this category?") which caused the modifier to apply to ALL of a
    // participant's matching-category mining on an event day — too
    // broad for sub-day events. Replaced by getEventAttributionForLedgerRow()
    // above, which joins against event_mining_records to apply the
    // modifier only to the actually-event-qualified slice.

    /**
     * Get ore name from invTypes table.
     *
     * @param int $typeId
     * @return string
     */
    private function getOreName(int $typeId): string
    {
        try {
            $name = DB::table('invTypes')
                ->where('typeID', $typeId)
                ->value('typeName');

            return $name ?: "Unknown Ore ({$typeId})";
        } catch (\Exception $e) {
            return "Type {$typeId}";
        }
    }

    /**
     * Get ore category string for a type ID.
     *
     * @param int $typeId
     * @return string
     */
    /**
     * Classify an ore type into the canonical category string.
     *
     * MUST match the values stored in mining_ledger.ore_category by
     * ProcessMiningLedgerCommand / ImportCharacterMiningCommand::classifyOreCategory,
     * AND the values enumerated in MiningEvent::EVENT_TYPE_ORE_CATEGORIES.
     * A drift caused event tax attribution to silently return null for
     * moon / abyssal / triglavian rows (this helper returned "moon_ore" /
     * "abyssal_ore" / "triglavian_ore", but MiningEvent::appliesToOreCategory
     * checked against "moon_r4"..."moon_r64" / "abyssal" / "triglavian").
     *
     * Output format:
     *   moon_r4, moon_r8, moon_r16, moon_r32, moon_r64 — specific rarity
     *   ice
     *   gas
     *   abyssal
     *   triglavian
     *   ore (default — regular asteroid ore)
     */
    private function getOreCategory(int $typeId): string
    {
        return OreClassifier::category($typeId);
    }

    /**
     * Finalize all summaries for a given month
     * Run this on the 2nd of each month for the previous month
     *
     * @param string $month YYYY-MM format
     * @return array Statistics about finalization
     */
    public function finalizeMonth(string $month): array
    {
        $monthDate = Carbon::parse($month)->startOfMonth();
        $startDate = $monthDate->copy()->startOfMonth();
        $endDate = $monthDate->copy()->endOfMonth();
        $stats = [
            'month' => $month,
            'monthly_summaries' => 0,
            'daily_summaries' => 0,
            'characters_processed' => 0,
            'errors' => [],
        ];

        try {
            // Get all unique characters who mined in this month
            $characters = MiningLedger::whereYear('date', $monthDate->year)
                ->whereMonth('date', $monthDate->month)
                ->distinct()
                ->pluck('character_id');

            $stats['characters_processed'] = $characters->count();

            // Pre-query all character+date pairs that actually have data
            // This avoids iterating every day of the month for every character
            $pairs = MiningLedger::whereBetween('date', [$startDate, $endDate])
                ->select('character_id', DB::raw('DATE(date) as mining_date'))
                ->distinct()
                ->get();

            // Group dates by character for efficient lookup
            $datesByCharacter = $pairs->groupBy('character_id');

            foreach ($characters as $characterId) {
                try {
                    // Generate monthly summary
                    $this->generateMonthlySummary($characterId, $month);
                    $stats['monthly_summaries']++;

                    // Generate daily summaries only for days with actual data
                    $characterDates = $datesByCharacter->get($characterId, collect());
                    foreach ($characterDates as $pair) {
                        $this->generateDailySummary($characterId, $pair->mining_date);
                        $stats['daily_summaries']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors'][] = "Character {$characterId}: " . $e->getMessage();
                    Log::error("Failed to finalize summaries for character {$characterId}", [
                        'month' => $month,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info("Finalized summaries for month {$month}", $stats);
        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
            Log::error("Failed to finalize month {$month}", [
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Get monthly summaries with fallback to live calculation
     *
     * Members scope answers "what did this corporation's miners mine", every
     * ore wherever they mined it, with a corporation's members counted the way
     * the dashboard and Analytics count them. Moons scope answers "what came
     * off this corporation's refineries", whoever mined it.
     *
     * @param string $month YYYY-MM format
     * @param int|null $corporationId
     * @param string $scope self::SCOPE_MEMBERS or self::SCOPE_MOONS
     * @param array|null $period [start, end] inside the month, or null for all of it
     * @param ChartFilter|null $filter The slice of ore shown, or null for every ore
     * @return \Illuminate\Support\Collection
     */
    public function getMonthlySummaries(string $month, ?int $corporationId = null, string $scope = self::SCOPE_MEMBERS, ?array $period = null, ?ChartFilter $filter = null)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        if ($scope !== self::SCOPE_MOONS) {
            $characterIds = $this->memberCharacterIds($corporationId);

            if ($characterIds === []) {
                return collect();
            }

            // A finalized month row covers everything one character mined, so
            // the members of a corporation are simply the rows of its members.
            if ($this->usesFinalizedSummaries($month, $scope, $period, $filter)) {
                $summaries = MiningLedgerMonthlySummary::forMonth($monthDate)
                    ->finalized()
                    ->when($characterIds !== null, fn ($query) => $query->whereIn('character_id', $characterIds))
                    ->get();

                return $this->attachEventDiscounts($summaries, ...$this->periodBounds($monthDate, null));
            }
        }

        return $this->calculateLiveMonthlySummaries($month, $corporationId, $scope, $period, $filter);
    }

    /**
     * Whether a month's summaries come from the finalized monthly rows.
     *
     * Only the members scope can use them, and only for a whole month of every
     * ore: they hold each character's month as one figure, and cannot say which
     * part of it came off whose refinery, which half it fell in or what it was.
     */
    public function usesFinalizedSummaries(string $month, string $scope = self::SCOPE_MEMBERS, ?array $period = null, ?ChartFilter $filter = null): bool
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        if ($scope === self::SCOPE_MOONS || $period !== null || ($filter && $filter->isActive()) || $monthDate->isSameMonth(now())) {
            return false;
        }

        return MiningLedgerMonthlySummary::forMonth($monthDate)->finalized()->exists();
    }

    /**
     * Calculate live monthly summaries from raw ledger data
     *
     * @param string $month YYYY-MM format
     * @param int|null $corporationId
     * @param string $scope self::SCOPE_MEMBERS or self::SCOPE_MOONS
     * @param array|null $period [start, end] inside the month, or null for all of it
     * @param ChartFilter|null $filter The slice of ore shown, or null for every ore
     * @return \Illuminate\Support\Collection
     */
    protected function calculateLiveMonthlySummaries(string $month, ?int $corporationId = null, string $scope = self::SCOPE_MEMBERS, ?array $period = null, ?ChartFilter $filter = null)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();
        [$from, $to] = $this->periodBounds($monthDate, $period);

        $moonClassValues = '';
        if ($scope === self::SCOPE_MOONS) {
            foreach (self::MOON_CLASSES as $class) {
                $moonClassValues .= ",\n                SUM(CASE WHEN ore_category = 'moon_{$class}' THEN total_value ELSE 0 END) as moon_{$class}_value";
            }
        }

        // Group by character_id only — a character's total should be aggregated
        // across all corporations they mined at. Use MAX(corporation_id) to pick
        // the primary corporation for display purposes.
        $summaries = $this->scopedLedger($from, $to, $corporationId, $scope, $filter)
            ->selectRaw('
                character_id,
                MAX(corporation_id) as corporation_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(CASE WHEN is_moon_ore = 1 THEN total_value ELSE 0 END) as moon_ore_value,
                SUM(CASE WHEN is_ice = 1 THEN total_value ELSE 0 END) as ice_value,
                SUM(CASE WHEN is_gas = 1 THEN total_value ELSE 0 END) as gas_value,
                SUM(CASE WHEN is_abyssal = 1 THEN total_value ELSE 0 END) as abyssal_ore_value,
                SUM(CASE WHEN is_triglavian = 1 THEN total_value ELSE 0 END) as triglavian_ore_value,
                SUM(CASE WHEN is_moon_ore = 0 AND is_ice = 0 AND is_gas = 0 AND is_abyssal = 0 AND is_triglavian = 0 THEN total_value ELSE 0 END) as regular_ore_value' . $moonClassValues . '
            ')
            ->groupBy('character_id')
            ->get();

        // A bill covers all of a character's mining, so tax only means
        // something next to the members view. The moons view covers only what
        // refineries recorded, and shows no tax.
        if ($scope === self::SCOPE_MOONS) {
            $summaries->each(function ($summary) {
                $summary->total_tax = 0;
            });

            return $summaries;
        }

        $tax = $this->periodTax($summaries->pluck('character_id')->all(), $from, $to, $filter);

        $summaries->each(function ($summary) use ($tax) {
            $summary->total_tax = $tax[$summary->character_id]['tax'] ?? 0.0;
            $summary->event_discount_total = $tax[$summary->character_id]['discount'] ?? 0.0;
        });

        return $summaries;
    }

    /**
     * The first and last day a view covers: the whole month, or the period
     * asked for.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodBounds(Carbon $monthDate, ?array $period): array
    {
        if ($period === null) {
            return [$monthDate->copy()->startOfMonth(), $monthDate->copy()->endOfMonth()->startOfDay()];
        }

        return [Carbon::parse($period[0])->startOfDay(), Carbon::parse($period[1])->startOfDay()];
    }

    /**
     * The ledger rows a scope covers over these days.
     *
     * The moons scope reads only what a refinery's observer recorded. Personal
     * mining is left out of it, since nothing in it says whose moon it came off.
     */
    protected function scopedLedger(Carbon $from, Carbon $to, ?int $corporationId, string $scope, ?ChartFilter $filter = null)
    {
        $query = MiningLedger::whereBetween('mining_ledger.date', [$from->toDateString(), $to->toDateString()]);

        if ($scope === self::SCOPE_MOONS) {
            $query->whereNotNull('mining_ledger.observer_id');

            if ($corporationId) {
                $query->where('mining_ledger.corporation_id', $corporationId);
            }
        } else {
            $characterIds = $this->memberCharacterIds($corporationId);
            if ($characterIds !== null) {
                $query->whereIn('mining_ledger.character_id', $characterIds);
            }
        }

        if ($filter) {
            $filter->applyTo($query);
        }

        return $query;
    }

    /**
     * A corporation's miners, as every page that filters by one counts them,
     * or null for everyone.
     *
     * @return array<int, int>|null
     */
    protected function memberCharacterIds(?int $corporationId): ?array
    {
        return $corporationId ? app(CorporationMembers::class)->characterIds($corporationId) : null;
    }

    /**
     * Add what event modifiers took off each character's tax over these days,
     * which the Total Tax card shows beside the tax it is already part of.
     *
     * @param \Illuminate\Support\Collection $summaries
     * @return \Illuminate\Support\Collection
     */
    protected function attachEventDiscounts($summaries, Carbon $from, Carbon $to)
    {
        if ($summaries->isEmpty()) {
            return $summaries;
        }

        $tax = $this->periodTax($summaries->pluck('character_id')->all(), $from, $to);

        $summaries->each(function ($summary) use ($tax) {
            $summary->event_discount_total = $tax[$summary->character_id]['discount'] ?? 0.0;
        });

        return $summaries;
    }

    /**
     * Each character's tax over these days, and the event discount inside it,
     * from the daily summaries the bills are built from.
     *
     * For a slice of the ore it adds up each day's per-ore breakdown. Moon ore
     * is only ever taxed where the moon owner's refineries recorded it, so the
     * moon slices that include those refineries all read the same tax, and
     * other moons read none. A day an invoice covers keeps the tax it was
     * billed at as its total, while its breakdown follows the rates of the
     * day it was last built, so a slice can stray from the bill once rates
     * have changed.
     *
     * @param array<int, int|string> $characterIds
     * @return array<int, array{tax: float, discount: float}> character_id => figures
     */
    public function periodTax(array $characterIds, Carbon $from, Carbon $to, ?ChartFilter $filter = null): array
    {
        $kinds = $this->taxedKinds($filter ?? ChartFilter::none());
        $ids = array_values(array_unique(array_map('intval', $characterIds)));
        $figures = [];

        if (!$ids || $kinds === []) {
            return $figures;
        }

        foreach (array_chunk($ids, 1000) as $chunk) {
            $days = MiningLedgerDailySummary::whereIn('character_id', $chunk)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

            if ($kinds === null) {
                $rows = $days->selectRaw('character_id, SUM(total_tax) as tax, SUM(event_discount_total) as discount')
                    ->groupBy('character_id')
                    ->get();

                foreach ($rows as $row) {
                    $figures[(int) $row->character_id] = ['tax' => (float) $row->tax, 'discount' => (float) $row->discount];
                }

                continue;
            }

            foreach ($days->get(['character_id', 'ore_types']) as $day) {
                $id = (int) $day->character_id;
                $figures[$id] ??= ['tax' => 0.0, 'discount' => 0.0];

                foreach ((array) $day->ore_types as $entry) {
                    if (in_array($this->oreKind((string) ($entry['category'] ?? '')), $kinds, true)) {
                        $figures[$id]['tax'] += (float) ($entry['estimated_tax'] ?? 0);
                        $figures[$id]['discount'] += (float) ($entry['event_discount_amount'] ?? 0);
                    }
                }
            }
        }

        return $figures;
    }

    /**
     * The kinds of ore whose tax a slice counts: null for every kind, none at
     * all for other moons.
     *
     * @return array<int, string>|null
     */
    protected function taxedKinds(ChartFilter $filter): ?array
    {
        if ($filter->moonSource() === ChartFilter::MOON_OTHER) {
            return [];
        }

        $kinds = in_array($filter->moonSource(), [ChartFilter::MOON_MINE, ChartFilter::MOON_ANY], true) ? ['moon'] : null;

        if ($filter->oreCategory() !== null) {
            $kinds = $kinds === null
                ? [$filter->oreCategory()]
                : array_values(array_intersect($kinds, [$filter->oreCategory()]));
        }

        return $kinds;
    }

    /**
     * The ore category, as the Performance Charts name them, of a category
     * stored in a day's breakdown. Days built before the categories were
     * renamed still say moon_ore, abyssal_ore and triglavian_ore.
     */
    protected function oreKind(string $category): string
    {
        return match (true) {
            str_starts_with($category, 'moon') => 'moon',
            str_starts_with($category, 'abyssal') => 'abyssal',
            str_starts_with($category, 'triglavian') => 'triglavian',
            $category === 'ice', $category === 'gas' => $category,
            default => 'ore',
        };
    }

    /**
     * The tax on each ledger row, read from the daily summaries the bills are
     * built from.
     *
     * A day's breakdown is built under the settings that tax that mining: the
     * Moon Owner Corporation's for its refineries, the miner's own
     * corporation's for everything else, and guest rates for a miner outside
     * the corporations this install looks after. Each row takes its share of
     * its ore's tax that day, so a day's rows add up to the day, and a row the
     * breakdown leaves out says why.
     *
     * @param iterable<MiningLedger> $rows
     * @return array<int, array{class: string, taxed: bool, rate: float, tax: float, discount: float, reason: string|null}> row id => figures
     */
    public function rowTaxes(iterable $rows): array
    {
        $rows = collect($rows);
        if ($rows->isEmpty()) {
            return [];
        }

        $dayOf = fn ($date) => Carbon::parse($date)->toDateString();
        $moonOwnerCorpId = (int) $this->settingsService->getSettingForCorporation('general.moon_owner_corporation_id', null) ?: null;
        $characterIds = $rows->pluck('character_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $dates = $rows->map(fn ($row) => $dayOf($row->date));

        // character|date => type id => that ore's entries in the day's breakdown
        $days = [];
        $summaries = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$dates->min(), $dates->max()])
            ->get(['character_id', 'date', 'ore_types']);
        foreach ($summaries as $summary) {
            $key = $summary->character_id . '|' . $dayOf($summary->date);
            $days[$key] = [];
            foreach ((array) $summary->ore_types as $entry) {
                $days[$key][(int) ($entry['type_id'] ?? 0)][] = $entry;
            }
        }

        // Personal mining is only taxed for members of the corporations this
        // install looks after, on the day they mined, as the breakdown decides it.
        $homeCorporationIds = $this->settingsService->getHomeCorporationIds();
        $onDate = app(CorporationOnDate::class);
        $taxedMember = function (int $characterId, string $day) use ($onDate, $homeCorporationIds) {
            $corporationId = (int) $onDate->forDay($characterId, $day, $homeCorporationIds);

            return $corporationId > 0 && (!$homeCorporationIds || in_array($corporationId, $homeCorporationIds, true));
        };

        // Moon ore in a personal ledger is taxed once our refinery reports it,
        // and reports that come in late are matched this many days back.
        $stillReporting = Carbon::today()->subDays(PersonalMiningReconciler::DEFAULT_DAYS)->toDateString();

        $figures = [];
        foreach ($rows as $row) {
            $typeId = (int) $row->type_id;
            $day = $dayOf($row->date);
            $refinery = $row->corporation_id !== null ? (int) $row->corporation_id : null;
            $figure = ['class' => OreClassifier::category($typeId), 'taxed' => false, 'rate' => 0.0, 'tax' => 0.0, 'discount' => 0.0, 'reason' => null];

            if ($refinery !== null && $refinery !== $moonOwnerCorpId) {
                $figure['reason'] = 'other_refinery';
            } elseif ($refinery === null && TypeIdRegistry::isMoonOre($typeId)) {
                $figure['reason'] = $day >= $stillReporting ? 'moon_waiting' : 'moon_elsewhere';
            } elseif ($refinery === null && !$taxedMember((int) $row->character_id, $day)) {
                $figure['reason'] = 'not_member';
            } elseif ($row->processed_at === null || empty($days[$row->character_id . '|' . $day][$typeId])) {
                $figure['reason'] = 'not_counted';
            } else {
                // Our refinery's entry is the only one for a moon ore that can
                // carry tax, since moon ore from a personal ledger never does.
                $taxable = array_filter(
                    $days[$row->character_id . '|' . $day][$typeId],
                    fn ($entry) => !empty($entry['is_taxable'])
                );
                $value = array_sum(array_map(fn ($entry) => (float) ($entry['total_value'] ?? 0), $taxable));
                $share = $value > 0 ? (float) $row->total_value / $value : 0.0;
                $rate = $taxable ? (float) (reset($taxable)['tax_rate'] ?? 0) : 0.0;

                if (!$taxable) {
                    $figure['reason'] = 'settings';
                } elseif ($rate <= 0) {
                    $figure['reason'] = 'zero_rate';
                } else {
                    $figure['taxed'] = true;
                    $figure['rate'] = $rate;
                    $figure['tax'] = round($share * array_sum(array_map(fn ($entry) => (float) ($entry['estimated_tax'] ?? 0), $taxable)), 2);
                    $figure['discount'] = round($share * array_sum(array_map(fn ($entry) => (float) ($entry['event_discount_amount'] ?? 0), $taxable)), 2);
                }
            }

            $figures[(int) $row->id] = $figure;
        }

        return $figures;
    }

    /**
     * Get daily summaries for a character in a specific month.
     *
     * For the current month, reads stored summaries (which contain rich
     * ore breakdown with estimated taxes from the daily update command).
     * Falls back to live SQL aggregation only if no summaries exist yet.
     *
     * @param int $characterId
     * @param string $month YYYY-MM format
     * @return \Illuminate\Support\Collection
     */
    public function getDailySummaries(int $characterId, string $month)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();
        $isCurrentMonth = $monthDate->isSameMonth(now());

        if (!$isCurrentMonth) {
            // Past months: prefer finalized summaries
            $summaries = MiningLedgerDailySummary::forCharacter($characterId)
                ->forMonth($monthDate)
                ->finalized()
                ->orderBy('date')
                ->get();

            if ($summaries->isNotEmpty()) {
                return $summaries;
            }
        } else {
            // Current month: use stored summaries (may be non-finalized)
            // These contain rich ore_types JSON with estimated taxes
            $summaries = MiningLedgerDailySummary::forCharacter($characterId)
                ->forMonth($monthDate)
                ->orderBy('date')
                ->get();

            if ($summaries->isNotEmpty()) {
                return $summaries;
            }
        }

        // Fallback: no stored summaries yet, calculate live from raw ledger
        return $this->calculateLiveDailySummaries($characterId, $month);
    }

    /**
     * Calculate live daily summaries from raw ledger data
     *
     * @param int $characterId
     * @param string $month YYYY-MM format
     * @return \Illuminate\Support\Collection
     */
    protected function calculateLiveDailySummaries(int $characterId, string $month)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        $summaries = MiningLedger::where('character_id', $characterId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->selectRaw('
                DATE(date) as date,
                character_id,
                corporation_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(CASE WHEN is_moon_ore = 1 THEN total_value ELSE 0 END) as moon_ore_value,
                SUM(CASE WHEN is_ice = 1 THEN total_value ELSE 0 END) as ice_value,
                SUM(CASE WHEN is_gas = 1 THEN total_value ELSE 0 END) as gas_value,
                SUM(CASE WHEN is_abyssal = 1 THEN total_value ELSE 0 END) as abyssal_ore_value,
                SUM(CASE WHEN is_triglavian = 1 THEN total_value ELSE 0 END) as triglavian_ore_value,
                SUM(CASE WHEN is_moon_ore = 0 AND is_ice = 0 AND is_gas = 0 AND is_abyssal = 0 AND is_triglavian = 0 THEN total_value ELSE 0 END) as regular_ore_value
            ')
            ->groupBy(DB::raw('DATE(date)'), 'character_id', 'corporation_id')
            ->orderBy('date')
            ->get();

        // Get total_tax from daily summaries (single source of truth) instead of
        // the stale mining_ledger.tax_amount column
        $dailySummaryTax = MiningLedgerDailySummary::where('character_id', $characterId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->pluck('total_tax', 'date');

        // Merge daily summary tax into each day's result
        $summaries->each(function ($summary) use ($dailySummaryTax) {
            $dateKey = Carbon::parse($summary->date)->toDateString();
            $summary->total_tax = (float) ($dailySummaryTax->get($dateKey) ?? 0);
        });

        return $summaries;
    }

    /**
     * Get enhanced monthly summaries with ore types and systems
     *
     * PERFORMANCE: Uses batch queries instead of per-character N+1 queries.
     * Previously ran 2+ queries per character (200+ queries for 50 miners).
     * Now uses 2 batch queries + 1 system name lookup regardless of miner count.
     *
     * @param string $month YYYY-MM format
     * @param int|null $corporationId
     * @param string $scope self::SCOPE_MEMBERS or self::SCOPE_MOONS
     * @param array|null $period [start, end] inside the month, or null for all of it
     * @param ChartFilter|null $filter The slice of ore shown, or null for every ore
     * @return \Illuminate\Support\Collection
     */
    public function getEnhancedMonthlySummaries(string $month, ?int $corporationId = null, string $scope = self::SCOPE_MEMBERS, ?array $period = null, ?ChartFilter $filter = null)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();
        [$from, $to] = $this->periodBounds($monthDate, $period);

        // Get base summaries
        $summaries = $this->getMonthlySummaries($month, $corporationId, $scope, $period, $filter);

        if ($summaries->isEmpty()) {
            return $summaries;
        }

        // Collect all character IDs once
        $characterIds = $summaries->pluck('character_id')->unique()->toArray();

        // The ore, volume and system columns cover the same rows as the value
        // beside them: a member's mining, or what one refinery owner's
        // observers recorded, over the days and ore shown.
        $rows = fn () => $this->scopedLedger($from, $to, $scope === self::SCOPE_MOONS ? $corporationId : null, $scope, $filter)
            ->whereIn('mining_ledger.character_id', $characterIds);

        // --- BATCH QUERY 1: Get all ore type_ids for all characters in one query ---
        $allOreTypes = $rows()
            ->select('character_id', 'type_id')
            ->distinct()
            ->get()
            ->groupBy('character_id')
            ->map(fn($group) => $group->pluck('type_id')->toArray());

        // --- BATCH QUERY 2: Get total volume (m³) for all characters in one query ---
        $allVolumes = $rows()
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->selectRaw('mining_ledger.character_id, SUM(mining_ledger.quantity * invTypes.volume) as total_volume_m3')
            ->groupBy('mining_ledger.character_id')
            ->pluck('total_volume_m3', 'character_id');

        // --- BATCH QUERY 3: Get all system data for all characters in one query ---
        $allSystemData = $rows()
            ->select('character_id', 'solar_system_id', DB::raw('SUM(total_value) as system_value'))
            ->groupBy('character_id', 'solar_system_id')
            ->get()
            ->groupBy('character_id');

        // --- BATCH QUERY 4: Load all unique solar system names in one query ---
        $allSystemIds = $allSystemData->flatten()->pluck('solar_system_id')->unique()->filter()->toArray();
        $solarSystems = [];
        if (!empty($allSystemIds)) {
            try {
                $pk = \MiningManager\Models\MiningLedger::getSolarSystemPrimaryKey();
                $solarSystems = SolarSystem::whereIn($pk, $allSystemIds)->get()->keyBy($pk);
            } catch (\Exception $e) {
                Log::debug('LedgerSummaryService: Failed to batch load solar systems', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Enrich each summary from the pre-loaded batch data (no extra queries)
        $summaries = $summaries->map(function ($summary) use ($allOreTypes, $allVolumes, $allSystemData, $solarSystems) {
            $charId = $summary->character_id;

            // Ore types from batch
            $summary->ore_type_ids = $allOreTypes->get($charId, []);

            // Volume from batch
            $summary->total_volume_m3 = (float) $allVolumes->get($charId, 0);

            // System data from batch with pre-loaded names
            $systemData = $allSystemData->get($charId, collect())->sortByDesc('system_value')->values();

            $systems = $systemData->map(function ($item) use ($solarSystems) {
                $item->solarSystem = $solarSystems->get($item->solar_system_id);
                return $item;
            });

            $summary->systems = $systems;
            $summary->primary_system = $systems->first();
            $summary->system_count = $systems->count();

            return $summary;
        });

        return $summaries;
    }

    /**
     * Get character mining details in a specific system
     *
     * @param int $characterId
     * @param string $month YYYY-MM format
     * @param int $systemId
     * @return \Illuminate\Support\Collection
     */
    public function getCharacterSystemDetails(int $characterId, string $month, int $systemId)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        return MiningLedger::with(['type', 'solarSystem'])
            ->where('character_id', $characterId)
            ->where('solar_system_id', $systemId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * Group character summaries by main character
     * Uses SeAT v5 structure (refresh_tokens table for character-user mapping)
     *
     * @param \Illuminate\Support\Collection $summaries
     * @param int|null $corporationId
     * @param string $scope self::SCOPE_MEMBERS or self::SCOPE_MOONS
     * @return \Illuminate\Support\Collection
     */
    public function groupByMainCharacter($summaries, ?int $corporationId = null, string $scope = self::SCOPE_MEMBERS)
    {
        // Get all character IDs from mining data
        $characterIds = $summaries->pluck('character_id')->unique()->toArray();

        // Get user IDs for these characters from refresh_tokens table (SeAT v5.x standard)
        $characterUserMapping = DB::table('refresh_tokens')
            ->whereIn('character_id', $characterIds)
            ->select('character_id', 'user_id')
            ->get()
            ->pluck('user_id', 'character_id');

        // Also fetch ALL registered users for the corporation(s) so main accounts
        // with zero mining still appear in the summary. Members scope only: in
        // the moons scope the corporation owns the refinery, and its members who
        // never mined there are not missing from anything.
        $corpIds = [];
        if ($scope !== self::SCOPE_MOONS) {
            $corpIds = $corporationId
                ? [$corporationId]
                : $this->settingsService->getHomeCorporationIds();
        }

        if (!empty($corpIds)) {
            // Find all character_ids affiliated with these corporations
            $allCorpCharIds = DB::table('character_affiliations')
                ->whereIn('corporation_id', $corpIds)
                ->pluck('character_id')
                ->toArray();

            // Map them to user IDs via refresh_tokens
            $allCorpUserMapping = DB::table('refresh_tokens')
                ->whereIn('character_id', $allCorpCharIds)
                ->select('character_id', 'user_id')
                ->get()
                ->pluck('user_id', 'character_id');

            // Merge into characterUserMapping (mining chars take precedence)
            foreach ($allCorpUserMapping as $charId => $userId) {
                if (!$characterUserMapping->has($charId)) {
                    $characterUserMapping[$charId] = $userId;
                }
            }
        }

        // Get main character IDs for all users
        $userIds = $characterUserMapping->values()->unique()->toArray();
        $mainCharacterMapping = DB::table('users')
            ->whereIn('id', $userIds)
            ->pluck('main_character_id', 'id');

        // Build character-to-main-character mapping
        $characterToMain = [];
        foreach ($characterUserMapping as $charId => $userId) {
            $mainCharId = $mainCharacterMapping->get($userId, $charId);
            $characterToMain[$charId] = $mainCharId;
        }

        // Group characters by their main character
        $groupedByMain = [];
        foreach ($summaries as $summary) {
            $charId = $summary->character_id;
            $mainCharId = $characterToMain[$charId] ?? $charId;

            if (!isset($groupedByMain[$mainCharId])) {
                $groupedByMain[$mainCharId] = collect();
            }
            $groupedByMain[$mainCharId]->push($summary);
        }

        // Add main characters with zero mining (not yet in groupedByMain)
        $allMainCharIds = collect($mainCharacterMapping->values())->unique();
        foreach ($allMainCharIds as $mainCharId) {
            if (!isset($groupedByMain[$mainCharId])) {
                $groupedByMain[$mainCharId] = collect();
            }
        }

        // Build final grouped summaries
        $grouped = collect();

        foreach ($groupedByMain as $mainCharId => $userSummaries) {
            if ($userSummaries->isEmpty()) {
                // Main account with zero mining — create an empty summary object
                $emptySummary = new \stdClass();
                $emptySummary->character_id = $mainCharId;
                $emptySummary->corporation_id = $corporationId;
                $emptySummary->total_value = 0;
                $emptySummary->total_tax = 0;
                $emptySummary->total_quantity = 0;
                $emptySummary->moon_ore_value = 0;
                $emptySummary->regular_ore_value = 0;
                $emptySummary->ice_value = 0;
                $emptySummary->gas_value = 0;
                $emptySummary->abyssal_ore_value = 0;
                $emptySummary->triglavian_ore_value = 0;
                $emptySummary->event_discount_total = 0;
                foreach (self::MOON_CLASSES as $class) {
                    $emptySummary->{"moon_{$class}_value"} = 0;
                }
                $emptySummary->total_volume_m3 = 0;
                $emptySummary->alt_characters = collect();
                $emptySummary->alt_count = 0;
                $emptySummary->ore_type_ids = [];
                $emptySummary->systems = collect();
                $emptySummary->primary_system = null;
                $emptySummary->system_count = 0;
                $grouped->push($emptySummary);
                continue;
            }

            // Find the main character's own summary (they may or may not have mined)
            $mainCharSummary = $userSummaries->where('character_id', $mainCharId)->first();

            if ($mainCharSummary) {
                // Main character mined — use their summary as the base, alts are the rest
                $mainSummary = $mainCharSummary;
                $mainSummary->alt_characters = $userSummaries->where('character_id', '!=', $mainCharId)->values();
            } else {
                // Main character didn't mine — create a stub with their character_id
                // so their name/portrait displays correctly. All miners are alts.
                $mainSummary = new \stdClass();
                $mainSummary->character_id = $mainCharId;
                $mainSummary->corporation_id = $userSummaries->first()?->corporation_id ?? $corporationId;
                $mainSummary->alt_characters = $userSummaries->values();
                $mainSummary->ore_type_ids = [];
                $mainSummary->systems = collect();
                $mainSummary->primary_system = null;
                $mainSummary->system_count = 0;
            }

            $mainSummary->alt_count = $mainSummary->alt_characters->count();

            // Aggregate totals from all characters (main + alts)
            $mainSummary->total_value = $userSummaries->sum('total_value');
            $mainSummary->total_tax = $userSummaries->sum('total_tax');
            $mainSummary->total_quantity = $userSummaries->sum('total_quantity');
            $mainSummary->total_volume_m3 = $userSummaries->sum('total_volume_m3');
            $mainSummary->moon_ore_value = $userSummaries->sum('moon_ore_value');
            $mainSummary->regular_ore_value = $userSummaries->sum('regular_ore_value');
            $mainSummary->ice_value = $userSummaries->sum('ice_value');
            $mainSummary->gas_value = $userSummaries->sum('gas_value');
            $mainSummary->abyssal_ore_value = $userSummaries->sum('abyssal_ore_value');
            $mainSummary->triglavian_ore_value = $userSummaries->sum('triglavian_ore_value');
            $mainSummary->event_discount_total = $userSummaries->sum('event_discount_total');
            foreach (self::MOON_CLASSES as $class) {
                $mainSummary->{"moon_{$class}_value"} = $userSummaries->sum("moon_{$class}_value");
            }

            // Merge ore types from all characters
            $allOreTypes = $userSummaries->pluck('ore_type_ids')->flatten()->unique()->values();
            $mainSummary->ore_type_ids = $allOreTypes->toArray();

            // Merge and re-aggregate systems from all characters
            $allSystems = $userSummaries->pluck('systems')->filter()->flatten();
            if ($allSystems->isNotEmpty()) {
                $systemsById = $allSystems->groupBy('solar_system_id')->map(function($group) {
                    $first = $group->first();
                    $first->system_value = $group->sum('system_value');
                    return $first;
                })->sortByDesc('system_value')->values();

                $mainSummary->systems = $systemsById;
                $mainSummary->primary_system = $systemsById->first();
                $mainSummary->system_count = $systemsById->count();
            }

            $grouped->push($mainSummary);
        }

        return $grouped->sortByDesc('total_value')->values();
    }

    /**
     * Sum event tax savings per event across a character set.
     *
     * Walks mining_ledger_daily_summaries.ore_types JSON for the given
     * characters (and optional date range) and aggregates
     * event_discount_amount values, grouped by event_id. Returns a map
     * of [event_id => total_saved_isk].
     *
     * Used by My Events page + My Mining "Total Event Savings" info box
     * to attribute tax savings back to the specific event that produced
     * them. Each ore entry in the JSON carries event_id / event_modifier
     * / event_qualified_value / event_discount_amount (written by
     * generateDailySummary's per-row attribution — Phase 2).
     *
     * @param array<int> $characterIds
     * @param \Carbon\Carbon|null $startDate inclusive, null = no lower bound
     * @param \Carbon\Carbon|null $endDate inclusive, null = no upper bound
     * @return array<int, float> event_id => total discount in ISK
     */
    public function getEventSavingsByEvent(
        array $characterIds,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): array {
        if (empty($characterIds)) {
            return [];
        }

        $query = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereNotNull('ore_types')
            ->where('event_discount_total', '>', 0);

        if ($startDate) {
            $query->where('date', '>=', $startDate->toDateString());
        }
        if ($endDate) {
            $query->where('date', '<=', $endDate->toDateString());
        }

        // Only fetch the column we care about (ore_types is potentially large JSON).
        $rows = $query->select('ore_types')->get();

        $byEvent = [];
        foreach ($rows as $row) {
            $oreTypes = $row->ore_types ?? [];
            if (!is_array($oreTypes)) {
                continue;
            }

            foreach ($oreTypes as $entry) {
                $eventId = $entry['event_id'] ?? null;
                $discount = (float) ($entry['event_discount_amount'] ?? 0);

                if ($eventId === null || $discount <= 0) {
                    continue;
                }

                $eventId = (int) $eventId;
                $byEvent[$eventId] = ($byEvent[$eventId] ?? 0) + $discount;
            }
        }

        return $byEvent;
    }

    /**
     * Total event tax savings across a character set (optional date bounds).
     *
     * Sum of mining_ledger_daily_summaries.event_discount_total — faster
     * than walking ore_types JSON when you don't need per-event
     * attribution, just the grand total.
     *
     * @param array<int> $characterIds
     * @param \Carbon\Carbon|null $startDate
     * @param \Carbon\Carbon|null $endDate
     * @return float
     */
    public function getTotalEventSavings(
        array $characterIds,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): float {
        if (empty($characterIds)) {
            return 0.0;
        }

        $query = MiningLedgerDailySummary::whereIn('character_id', $characterIds);

        if ($startDate) {
            $query->where('date', '>=', $startDate->toDateString());
        }
        if ($endDate) {
            $query->where('date', '<=', $endDate->toDateString());
        }

        return (float) $query->sum('event_discount_total');
    }
}
