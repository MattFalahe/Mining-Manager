<?php

namespace MiningManager\Services\Ledger;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningLedgerMonthlySummary;
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Models\MiningEvent;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use Seat\Eveapi\Models\Sde\SolarSystem;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerSummaryService
{
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
                SUM(CASE WHEN is_moon_ore = 0 AND is_ice = 0 AND is_gas = 0 THEN total_value ELSE 0 END) as regular_ore_value
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
                    'ore_types' => [],
                    'is_finalized' => !$dateCarbon->isSameMonth(now()),
                ]
            );
        }

        // Get the character's corporation ID for guest mining detection
        $character = CharacterInfo::find($characterId);
        $characterCorpId = $character ? $character->corporation_id : null;

        // Ensure corporation context is set for reading settings
        // Without this, commands running without web context won't find corp-scoped settings
        $configuredCorpId = $this->settingsService->getActiveCorporation();
        if (!$configuredCorpId) {
            // Bootstrap from database: find the first configured corporation
            $firstCorpSetting = \MiningManager\Models\Setting::where('key', 'general.moon_owner_corporation_id')
                ->whereNotNull('corporation_id')
                ->first();
            if ($firstCorpSetting) {
                $this->settingsService->setActiveCorporation((int) $firstCorpSetting->corporation_id);
            }
        }

        // Get moon owner corporation for observer filtering and tax selector
        $moonOwnerCorpId = $this->settingsService->getGeneralSettings()['moon_owner_corporation_id'] ?? null;

        // Get list of configured corporations (for character ledger tax filtering)
        $homeCorporationIds = $this->settingsService->getHomeCorporationIds();

        // Get corporation_id for the summary — prefer Moon Owner Corp if entries exist
        $corporationId = (clone $baseQuery)
            ->whereNotNull('corporation_id')
            ->when($moonOwnerCorpId, function ($q) use ($moonOwnerCorpId) {
                return $q->where('corporation_id', $moonOwnerCorpId);
            })
            ->value('corporation_id');

        // Get event tax modifier for this character on this date
        $eventModifier = $this->getEventModifierForDate($characterId, $dateCarbon, $characterCorpId);

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
        $moonOreValue = 0;
        $regularOreValue = 0;
        $iceValue = 0;
        $gasValue = 0;

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

            // Apply event modifier
            $effectiveRate = $baseTaxRate;
            if ($isTaxable && $eventModifier !== 0) {
                $effectiveRate = max(0, $baseTaxRate * (1 + ($eventModifier / 100)));
            }
            $estimatedTax = $isTaxable ? $value * ($effectiveRate / 100) : 0;

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
                'effective_rate' => round($effectiveRate, 2),
                'is_taxable' => $isTaxable,
                'estimated_tax' => round($estimatedTax, 2),
            ];

            // Accumulate totals
            $totalQuantity += $quantity;
            $totalValue += $value;
            $totalTax += $estimatedTax;

            // Category breakdown
            switch ($category) {
                case 'moon_ore':
                    $moonOreValue += $value;
                    break;
                case 'ice':
                    $iceValue += $value;
                    break;
                case 'gas':
                    $gasValue += $value;
                    break;
                default:
                    $regularOreValue += $value;
                    break;
            }
        }

        // Current month = not finalized (still in progress)
        $isFinalized = !$dateCarbon->isSameMonth(now());

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
                'moon_ore_value' => $moonOreValue,
                'regular_ore_value' => $regularOreValue,
                'ice_value' => $iceValue,
                'gas_value' => $gasValue,
                'ore_types' => $oreBreakdown,
                'is_finalized' => $isFinalized,
            ]
        );
    }

    /**
     * Get tax rate for a specific ore type.
     * Respects per-rarity moon ore rates and guest miner multiplier.
     *
     * @param int $typeId
     * @param int|null $characterCorpId
     * @return float Tax rate as percentage (0-100)
     */
    private function getTaxRateForType(int $typeId, ?int $characterCorpId = null): float
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

        if (in_array($typeId, TypeIdRegistry::ABYSSAL_ORES)) {
            return (float) ($taxRates['abyssal_ore'] ?? 15.0);
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
    private function shouldTaxType(int $typeId, ?int $entryCorporationId = null, ?int $moonOwnerCorpId = null): bool
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
            return $taxSelector['gas'] ?? true;
        }

        if (in_array($typeId, TypeIdRegistry::ABYSSAL_ORES)) {
            return $taxSelector['abyssal_ore'] ?? false;
        }

        return $taxSelector['ore'] ?? true;
    }

    /**
     * Get the best event tax modifier for a character on a specific date.
     *
     * @param int $characterId
     * @param Carbon $date
     * @param int|null $characterCorpId
     * @return int Modifier (-100 to +100, 0 = no event)
     */
    private function getEventModifierForDate(int $characterId, Carbon $date, ?int $characterCorpId = null): int
    {
        try {
            $events = MiningEvent::where('status', 'active')
                ->where('start_time', '<=', $date)
                ->where(function ($query) use ($date) {
                    $query->whereNull('end_time')
                          ->orWhere('end_time', '>=', $date);
                })
                ->whereHas('participants', function ($query) use ($characterId) {
                    $query->where('character_id', $characterId);
                })
                ->where(function ($query) use ($characterCorpId) {
                    $query->whereNull('corporation_id')
                          ->orWhere('corporation_id', $characterCorpId);
                })
                ->orderBy('tax_modifier', 'asc')
                ->first();

            return $events ? (int) $events->tax_modifier : 0;
        } catch (\Exception $e) {
            // MiningEvent table may not exist in early setup
            return 0;
        }
    }

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
    private function getOreCategory(int $typeId): string
    {
        if (TypeIdRegistry::isMoonOre($typeId)) {
            return 'moon_ore';
        }
        if (TypeIdRegistry::isIce($typeId)) {
            return 'ice';
        }
        if (TypeIdRegistry::isGas($typeId)) {
            return 'gas';
        }
        if (in_array($typeId, TypeIdRegistry::ABYSSAL_ORES)) {
            return 'abyssal_ore';
        }
        return 'ore';
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

            foreach ($characters as $characterId) {
                try {
                    // Generate monthly summary
                    $this->generateMonthlySummary($characterId, $month);
                    $stats['monthly_summaries']++;

                    // Generate daily summaries for each day in the month
                    $start = $monthDate->copy()->startOfMonth();
                    $end = $monthDate->copy()->endOfMonth();

                    for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                        $this->generateDailySummary($characterId, $date->format('Y-m-d'));
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
     * @param string $month YYYY-MM format
     * @param int|null $corporationId
     * @return \Illuminate\Support\Collection
     */
    public function getMonthlySummaries(string $month, ?int $corporationId = null)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();
        $isCurrentMonth = $monthDate->isSameMonth(now());

        // If it's the current month, always calculate live
        if ($isCurrentMonth) {
            return $this->calculateLiveMonthlySummaries($month, $corporationId);
        }

        // For past months, try to get finalized summaries first
        $summaries = MiningLedgerMonthlySummary::forMonth($monthDate)
            ->forCorporation($corporationId)
            ->finalized()
            ->with('character')
            ->get();

        // If no finalized summaries exist, calculate live
        if ($summaries->isEmpty()) {
            return $this->calculateLiveMonthlySummaries($month, $corporationId);
        }

        return $summaries;
    }

    /**
     * Calculate live monthly summaries from raw ledger data
     *
     * @param string $month YYYY-MM format
     * @param int|null $corporationId
     * @return \Illuminate\Support\Collection
     */
    protected function calculateLiveMonthlySummaries(string $month, ?int $corporationId = null)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        $query = MiningLedger::whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month);

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        // Group by character_id only — a character's total should be aggregated
        // across all corporations they mined at. Use MAX(corporation_id) to pick
        // the primary corporation for display purposes.
        $summaries = $query->selectRaw('
                character_id,
                MAX(corporation_id) as corporation_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(CASE WHEN is_moon_ore = 1 THEN total_value ELSE 0 END) as moon_ore_value,
                SUM(CASE WHEN is_ice = 1 THEN total_value ELSE 0 END) as ice_value,
                SUM(CASE WHEN is_gas = 1 THEN total_value ELSE 0 END) as gas_value,
                SUM(CASE WHEN is_moon_ore = 0 AND is_ice = 0 AND is_gas = 0 THEN total_value ELSE 0 END) as regular_ore_value
            ')
            ->groupBy('character_id')
            ->with('character')
            ->get();

        // Get total_tax from daily summaries (single source of truth) instead of
        // the stale mining_ledger.tax_amount column
        $dailySummaryTax = MiningLedgerDailySummary::whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->selectRaw('character_id, SUM(total_tax) as total_tax')
            ->groupBy('character_id')
            ->pluck('total_tax', 'character_id');

        // Merge daily summary tax into each character's monthly summary
        $summaries->each(function ($summary) use ($dailySummaryTax) {
            $summary->total_tax = (float) ($dailySummaryTax->get($summary->character_id) ?? 0);
        });

        return $summaries;
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
                SUM(CASE WHEN is_moon_ore = 0 AND is_ice = 0 AND is_gas = 0 THEN total_value ELSE 0 END) as regular_ore_value
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
     * @return \Illuminate\Support\Collection
     */
    public function getEnhancedMonthlySummaries(string $month, ?int $corporationId = null)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        // Get base summaries
        $summaries = $this->getMonthlySummaries($month, $corporationId);

        if ($summaries->isEmpty()) {
            return $summaries;
        }

        // Collect all character IDs once
        $characterIds = $summaries->pluck('character_id')->unique()->toArray();

        // --- BATCH QUERY 1: Get all ore type_ids for all characters in one query ---
        $allOreTypes = MiningLedger::whereIn('character_id', $characterIds)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->select('character_id', 'type_id')
            ->distinct()
            ->get()
            ->groupBy('character_id')
            ->map(fn($group) => $group->pluck('type_id')->toArray());

        // --- BATCH QUERY 2: Get total volume (m³) for all characters in one query ---
        $allVolumes = MiningLedger::whereIn('character_id', $characterIds)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->selectRaw('character_id, SUM(mining_ledger.quantity * invTypes.volume) as total_volume_m3')
            ->groupBy('character_id')
            ->pluck('total_volume_m3', 'character_id');

        // --- BATCH QUERY 3: Get all system data for all characters in one query ---
        $allSystemData = MiningLedger::whereIn('character_id', $characterIds)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
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
     * @return \Illuminate\Support\Collection
     */
    public function groupByMainCharacter($summaries, ?int $corporationId = null)
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
        // with zero mining still appear in the summary
        $corpIds = $corporationId
            ? [$corporationId]
            : $summaries->pluck('corporation_id')->filter()->unique()->toArray();

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
}
