<?php

namespace MiningManager\Services\Tax;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Services\ReprocessingRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Seat\Eveapi\Models\Character\CharacterInfo;

class TaxCalculationService
{
    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Constructor
     *
     * @param SettingsManagerService $settingsService
     */
    public function __construct(SettingsManagerService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Calculate taxes for all miners in a month.
     * Handles both individual and accumulated calculation methods.
     *
     * @param Carbon $month
     * @param bool $recalculate
     * @return array
     */
    public function calculateMonthlyTaxes(Carbon $month, bool $recalculate = false): array
    {
        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();

        Log::info("Mining Manager: Starting tax calculation for {$startDate->format('Y-m')}");

        $generalSettings = $this->settingsService->getGeneralSettings();
        $calculationMethod = $generalSettings['tax_calculation_method'];

        if ($calculationMethod === 'accumulated') {
            return $this->calculateAccumulatedTaxes($startDate, $endDate, $recalculate);
        } else {
            return $this->calculateIndividualTaxes($startDate, $endDate, $recalculate);
        }
    }

    /**
     * Calculate taxes individually for each character.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param bool $recalculate
     * @return array
     */
    private function calculateIndividualTaxes(Carbon $startDate, Carbon $endDate, bool $recalculate): array
    {
        // Get all characters who mined this month
        $characters = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at')
            ->distinct('character_id')
            ->pluck('character_id');

        $calculated = 0;
        $totalTaxAmount = 0;
        $errors = [];

        foreach ($characters as $characterId) {
            try {
                // Check if tax already exists
                $existingTax = MiningTax::where('character_id', $characterId)
                    ->where('month', $startDate->format('Y-m-01'))
                    ->first();

                if ($existingTax && !$recalculate) {
                    Log::debug("Mining Manager: Skipping character {$characterId} - tax already calculated");
                    continue;
                }

                // Calculate tax for this character
                $taxAmount = $this->calculateCharacterTax($characterId, $startDate, $endDate);

                if ($taxAmount <= 0) {
                    Log::debug("Mining Manager: Character {$characterId} has no tax liability");
                    continue;
                }

                if ($existingTax) {
                    // Update existing
                    $existingTax->update([
                        'amount_owed' => $taxAmount,
                        'calculated_at' => Carbon::now(),
                    ]);
                    Log::info("Mining Manager: Recalculated tax for character {$characterId}: " . number_format($taxAmount, 2) . " ISK");
                } else {
                    // Create new
                    MiningTax::create([
                        'character_id' => $characterId,
                        'month' => $startDate->format('Y-m-01'),
                        'amount_owed' => $taxAmount,
                        'amount_paid' => 0,
                        'status' => 'unpaid',
                        'calculated_at' => Carbon::now(),
                    ]);
                    Log::info("Mining Manager: Calculated tax for character {$characterId}: " . number_format($taxAmount, 2) . " ISK");
                }

                $calculated++;
                $totalTaxAmount += $taxAmount;

            } catch (\Exception $e) {
                Log::error("Mining Manager: Error calculating tax for character {$characterId}: " . $e->getMessage());
                $errors[] = [
                    'character_id' => $characterId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::info("Mining Manager: Individual tax calculation complete. Calculated: {$calculated}, Total: " . number_format($totalTaxAmount, 2) . " ISK");

        return [
            'method' => 'individually',
            'count' => $calculated,
            'total' => $totalTaxAmount,
            'errors' => $errors,
        ];
    }

    /**
     * Calculate taxes accumulated to main characters.
     * All alts' taxes are summed and assigned to the main character.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param bool $recalculate
     * @return array
     */
    private function calculateAccumulatedTaxes(Carbon $startDate, Carbon $endDate, bool $recalculate): array
    {
        // Get all characters who mined this month
        $characters = MiningLedger::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at')
            ->distinct('character_id')
            ->pluck('character_id');

        // Group characters by main character (user)
        $groupedByMain = $this->groupCharactersByMain($characters);

        $calculated = 0;
        $totalTaxAmount = 0;
        $errors = [];

        foreach ($groupedByMain as $mainCharacterId => $characterIds) {
            try {
                // Check if tax already exists for main character
                $existingTax = MiningTax::where('character_id', $mainCharacterId)
                    ->where('month', $startDate->format('Y-m-01'))
                    ->first();

                if ($existingTax && !$recalculate) {
                    Log::debug("Mining Manager: Skipping main character {$mainCharacterId} - tax already calculated");
                    continue;
                }

                // Calculate combined tax for all characters in this group
                $combinedTaxAmount = 0;
                $taxBreakdown = [];

                foreach ($characterIds as $characterId) {
                    $characterTax = $this->calculateCharacterTax($characterId, $startDate, $endDate);
                    $combinedTaxAmount += $characterTax;
                    
                    if ($characterTax > 0) {
                        $taxBreakdown[] = [
                            'character_id' => $characterId,
                            'tax_amount' => $characterTax,
                        ];
                    }
                }

                if ($combinedTaxAmount <= 0) {
                    Log::debug("Mining Manager: Main character {$mainCharacterId} group has no tax liability");
                    continue;
                }

                // Create notes explaining the breakdown
                $notes = "Accumulated tax from " . count($taxBreakdown) . " character(s):\n";
                foreach ($taxBreakdown as $item) {
                    $charInfo = CharacterInfo::find($item['character_id']);
                    $charName = $charInfo ? $charInfo->name : "Character {$item['character_id']}";
                    $notes .= "- {$charName}: " . number_format($item['tax_amount'], 2) . " ISK\n";
                }

                if ($existingTax) {
                    // Update existing
                    $existingTax->update([
                        'amount_owed' => $combinedTaxAmount,
                        'calculated_at' => Carbon::now(),
                        'notes' => $notes,
                    ]);
                    Log::info("Mining Manager: Recalculated accumulated tax for main character {$mainCharacterId}: " . number_format($combinedTaxAmount, 2) . " ISK (from " . count($characterIds) . " characters)");
                } else {
                    // Create new
                    MiningTax::create([
                        'character_id' => $mainCharacterId,
                        'month' => $startDate->format('Y-m-01'),
                        'amount_owed' => $combinedTaxAmount,
                        'amount_paid' => 0,
                        'status' => 'unpaid',
                        'calculated_at' => Carbon::now(),
                        'notes' => $notes,
                    ]);
                    Log::info("Mining Manager: Calculated accumulated tax for main character {$mainCharacterId}: " . number_format($combinedTaxAmount, 2) . " ISK (from " . count($characterIds) . " characters)");
                }

                $calculated++;
                $totalTaxAmount += $combinedTaxAmount;

            } catch (\Exception $e) {
                Log::error("Mining Manager: Error calculating accumulated tax for main character {$mainCharacterId}: " . $e->getMessage());
                $errors[] = [
                    'character_id' => $mainCharacterId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::info("Mining Manager: Accumulated tax calculation complete. Calculated: {$calculated} main characters, Total: " . number_format($totalTaxAmount, 2) . " ISK");

        return [
            'method' => 'accumulated',
            'count' => $calculated,
            'total' => $totalTaxAmount,
            'errors' => $errors,
        ];
    }

    /**
     * Group characters by their main character (same user).
     * 
     * @param \Illuminate\Support\Collection $characterIds
     * @return array [mainCharacterId => [characterIds]]
     */
    private function groupCharactersByMain($characterIds): array
    {
        $grouped = [];

        foreach ($characterIds as $characterId) {
            // Get the user_id for this character
            $character = CharacterInfo::find($characterId);
            
            if (!$character) {
                // Character not found, treat as standalone
                $grouped[$characterId] = [$characterId];
                continue;
            }

            // Get user_id (this links all alts to the same player)
            $userId = DB::table('character_infos')
                ->where('character_id', $characterId)
                ->value('user_id');

            if (!$userId) {
                // No user association, treat as standalone
                $grouped[$characterId] = [$characterId];
                continue;
            }

            // Find the main character for this user
            $mainCharacterId = $this->getMainCharacterForUser($userId);
            
            if (!$mainCharacterId) {
                // No main character defined, use this character as main
                $mainCharacterId = $characterId;
            }

            // Add this character to the main character's group
            if (!isset($grouped[$mainCharacterId])) {
                $grouped[$mainCharacterId] = [];
            }
            
            $grouped[$mainCharacterId][] = $characterId;
        }

        return $grouped;
    }

    /**
     * Get the main character ID for a user.
     * 
     * @param int $userId
     * @return int|null
     */
    private function getMainCharacterForUser(int $userId): ?int
    {
        // Try to get the main character from user settings
        // SeAT stores main character in users table as 'main_character_id'
        $mainCharacterId = DB::table('users')
            ->where('id', $userId)
            ->value('main_character_id');

        if ($mainCharacterId) {
            return $mainCharacterId;
        }

        // Fallback: Get the oldest/first character for this user
        $firstCharacter = DB::table('character_infos')
            ->where('user_id', $userId)
            ->orderBy('character_id', 'asc')
            ->value('character_id');

        return $firstCharacter;
    }

    /**
     * Calculate tax for a single character.
     *
     * @param int $characterId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return float
     */
    private function calculateCharacterTax(int $characterId, Carbon $startDate, Carbon $endDate): float
    {
        $miningData = MiningLedger::where('character_id', $characterId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at')
            ->get();

        if ($miningData->isEmpty()) {
            return 0;
        }

        // Get character's corporation ID for tax rate determination
        $character = CharacterInfo::find($characterId);
        $characterCorpId = $character ? $character->corporation_id : null;

        // Pre-fetch all corp moon data for this character and date range (batch optimization)
        $corpMoonCache = $this->batchLoadCorpMoonData($characterId, $startDate, $endDate);

        // Calculate total value and weighted tax
        $totalValue = 0;
        $totalTax = 0;

        foreach ($miningData as $entry) {
            // Skip if this ore type should not be taxed
            if (!$this->shouldTaxOre($entry->type_id, $entry, $corpMoonCache)) {
                continue;
            }

            $value = $this->calculateOreValue($entry);
            $taxRate = $this->getTaxRateForOre($entry->type_id, $characterCorpId) / 100;

            $totalValue += $value;
            $totalTax += $value * $taxRate;
        }

        // Check exemption threshold (only if enabled)
        $exemptions = $this->settingsService->getExemptions();
        if ($exemptions['enabled'] && $totalTax < $exemptions['threshold']) {
            Log::debug("Mining Manager: Character {$characterId} exempt from tax (tax below threshold: {$totalTax} < {$exemptions['threshold']})");
            return 0;
        }

        // Apply minimum tax (only for individual characters, not when accumulating)
        // Note: Minimum tax only applies if exemption check passes
        $contractSettings = $this->settingsService->getContractSettings();
        $minimumAmount = $contractSettings['minimum_tax_value'];

        if ($totalTax > 0 && $totalTax < $minimumAmount) {
            Log::debug("Mining Manager: Adjusting tax for character {$characterId} to minimum ({$totalTax} -> {$minimumAmount})");
            $totalTax = $minimumAmount;
        }

        return round($totalTax, 2);
    }

    /**
     * Calculate value of a single ore entry.
     * Now uses OreValuationService for consistency across the application.
     *
     * @param object $entry Mining ledger entry
     * @return float
     */
    private function calculateOreValue($entry): float
    {
        try {
            // Use OreValuationService for consistent valuation logic
            $valuationService = app(\MiningManager\Services\Pricing\OreValuationService::class);

            $values = $valuationService->calculateOreValue($entry->type_id, $entry->quantity);

            return $values['total_value'];
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error calculating ore value for type_id {$entry->type_id}", [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    // REMOVED: calculateRefinedMineralValue() method
    // This method was never used. All ore valuation is handled by OreValuationService::calculateOreValue()

    /**
     * Get ore price from cache.
     *
     * @param int $typeId
     * @param int $regionId
     * @param string $priceType
     * @return float|null
     */
    private function getOrePrice(int $typeId, int $regionId, string $priceType): ?float
    {
        $priceCache = MiningPriceCache::where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->latest('cached_at')
            ->first();

        if (!$priceCache) {
            return null;
        }

        return match ($priceType) {
            'buy' => $priceCache->buy_price,
            'average' => $priceCache->average_price,
            default => $priceCache->sell_price,
        };
    }

    /**
     * Get tax rate for specific ore type.
     * Now supports per-corporation tax rates (guest miner multiplier).
     *
     * @param int $typeId
     * @param int|null $characterCorpId Corporation ID of the character being taxed
     * @return float
     */
    private function getTaxRateForOre(int $typeId, ?int $characterCorpId = null): float
    {
        // Get tax rates for this corporation (applies guest multiplier if applicable)
        $taxRates = $this->settingsService->getTaxRatesForCorporation($characterCorpId);

        // Check if it's moon ore first
        $moonRarity = $this->getMoonOreRarity($typeId);
        if ($moonRarity) {
            return $taxRates['moon_ore'][$moonRarity];
        }

        // Check other categories
        $category = $this->getOreCategory($typeId);
        return $taxRates[$category] ?? 10.0;
    }

    /**
     * Determine if ore should be taxed based on settings.
     *
     * @param int $typeId
     * @param object|null $miningEntry Optional mining ledger entry for corp moon checking
     * @param array|null $corpMoonCache Pre-loaded corp moon data cache for batch optimization
     * @return bool
     */
    private function shouldTaxOre(int $typeId, $miningEntry = null, ?array $corpMoonCache = null): bool
    {
        $taxSelector = $this->settingsService->getTaxSelector();

        // Check if it's moon ore
        if ($this->isMoonOre($typeId)) {
            // If no_moon_ore is set, skip all moon ore taxation
            if (!empty($taxSelector['no_moon_ore'])) {
                Log::debug("Mining Manager: Skipping moon ore type {$typeId} - no_moon_ore is enabled");
                return false;
            }

            // If only_corp_moon_ore is enabled, check if it's from corp moon
            if ($taxSelector['only_corp_moon_ore'] && $miningEntry) {
                // Use cached corp moon data if available (batch optimization)
                if ($corpMoonCache !== null) {
                    $cacheKey = $this->getCorpMoonCacheKey(
                        $miningEntry->character_id,
                        $typeId,
                        $miningEntry->solar_system_id,
                        $miningEntry->date->format('Y-m-d')
                    );
                    $isCorpMoon = isset($corpMoonCache[$cacheKey]);
                } else {
                    // Fallback to individual query (slower)
                    $isCorpMoon = $this->checkIfCorpMoon(
                        $miningEntry->character_id,
                        $typeId,
                        $miningEntry->solar_system_id,
                        $miningEntry->date->format('Y-m-d')
                    );
                }

                if (!$isCorpMoon) {
                    Log::debug("Mining Manager: Skipping moon ore type {$typeId} - not from corp moon");
                    return false;
                }

                Log::debug("Mining Manager: Taxing moon ore type {$typeId} - confirmed corp moon");
                return true;
            }

            // Otherwise, use the all_moon_ore setting
            return $taxSelector['all_moon_ore'];
        }

        // Check other categories
        $category = $this->getOreCategory($typeId);

        return match($category) {
            'ice' => $taxSelector['ice'],
            'gas' => $taxSelector['gas'],
            'abyssal_ore' => $taxSelector['abyssal_ore'],
            'ore' => $taxSelector['ore'],
            default => false,
        };
    }

    /**
     * Get moon ore rarity level.
     * Now uses TypeIdRegistry instead of config
     *
     * @param int $typeId
     * @return string|null
     */
    private function getMoonOreRarity(int $typeId): ?string
    {
        // Use TypeIdRegistry for moon ore rarity mapping
        $rarityMap = TypeIdRegistry::getMoonOreRarityMap();
        
        foreach ($rarityMap as $rarity => $typeIds) {
            if (in_array($typeId, $typeIds)) {
                return strtolower($rarity); // Return as lowercase (r64, r32, etc.)
            }
        }
        
        return null;
    }

    /**
     * Get ore category.
     * Now uses TypeIdRegistry instead of config
     *
     * @param int $typeId
     * @return string
     */
    private function getOreCategory(int $typeId): string
    {
        // Check each category using TypeIdRegistry
        if (TypeIdRegistry::isMoonOre($typeId)) {
            return 'moon';
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
        
        // Check if it's one of the new ore types
        if (TypeIdRegistry::isOreProspectingArrayOre($typeId)) {
            return 'ore';
        }
        
        if (TypeIdRegistry::isDeepSpaceSurveyOre($typeId)) {
            return 'ore';
        }
        
        // Check if it's a regular ore
        if (TypeIdRegistry::isRegularOre($typeId)) {
            return 'ore';
        }
        
        // Default fallback
        return 'ore';
    }

    /**
     * Check if type is moon ore.
     * Now uses TypeIdRegistry instead of config
     *
     * @param int $typeId
     * @return bool
     */
    private function isMoonOre(int $typeId): bool
    {
        return TypeIdRegistry::isMoonOre($typeId);
    }

    /**
     * Batch load all corp moon data for a character and date range (performance optimization)
     *
     * @param int $characterId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array Keyed by cache key for fast lookups
     */
    private function batchLoadCorpMoonData(int $characterId, Carbon $startDate, Carbon $endDate): array
    {
        try {
            $results = DB::table('corporation_industry_mining_observer_data as d')
                ->select('d.character_id', 'd.type_id', 'u.solar_system_id', 'd.last_updated')
                ->join('universe_structures as u', 'd.observer_id', '=', 'u.structure_id')
                ->where('d.character_id', '=', $characterId)
                ->whereBetween('d.last_updated', [
                    $startDate->format('Y-m-d') . ' 00:00:00',
                    $endDate->format('Y-m-d') . ' 23:59:59'
                ])
                ->get();

            // Build cache keyed by composite key for fast lookups
            $cache = [];
            foreach ($results as $row) {
                $date = substr($row->last_updated, 0, 10); // Extract 'Y-m-d' from timestamp
                $cacheKey = $this->getCorpMoonCacheKey(
                    $row->character_id,
                    $row->type_id,
                    $row->solar_system_id,
                    $date
                );
                $cache[$cacheKey] = true;
            }

            Log::debug("Mining Manager: Batch loaded " . count($cache) . " corp moon entries for character {$characterId}");
            return $cache;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error batch loading corp moon data: " . $e->getMessage());
            return [];  // Return empty cache on error
        }
    }

    /**
     * Build corp moon cache from a collection of mining entries.
     * Uses batchLoadCorpMoonData for each unique character/date range.
     *
     * @param \Illuminate\Support\Collection $miningData
     * @return array
     */
    private function buildCorpMoonCache($miningData): array
    {
        $cache = [];

        // Group by character_id to batch-load per character
        $grouped = $miningData->groupBy('character_id');

        foreach ($grouped as $characterId => $entries) {
            $dates = $entries->pluck('date');
            $startDate = $dates->min();
            $endDate = $dates->max();

            if ($startDate && $endDate) {
                $charCache = $this->batchLoadCorpMoonData(
                    $characterId,
                    $startDate instanceof \Carbon\Carbon ? $startDate : \Carbon\Carbon::parse($startDate),
                    $endDate instanceof \Carbon\Carbon ? $endDate : \Carbon\Carbon::parse($endDate)
                );
                $cache = array_merge($cache, $charCache);
            }
        }

        return $cache;
    }

    /**
     * Generate cache key for corp moon lookup
     *
     * @param int $characterId
     * @param int $typeId
     * @param int $solarSystemId
     * @param string $date Date in 'Y-m-d' format
     * @return string
     */
    private function getCorpMoonCacheKey(int $characterId, int $typeId, int $solarSystemId, string $date): string
    {
        return "{$characterId}_{$typeId}_{$solarSystemId}_{$date}";
    }

    /**
     * Check if moon ore was mined from YOUR corporation's moon.
     *
     * This method queries the corporation_industry_mining_observer_data table
     * which ONLY contains mining data from YOUR corporation's observers/refineries.
     * If the mining event exists in this table, it means it was mined from your corp's moon.
     *
     * @param int $characterId
     * @param int $typeId
     * @param int $solarSystemId
     * @param string $date Date in 'Y-m-d' format
     * @return bool True if mined from corp moon, false otherwise
     */
    private function checkIfCorpMoon(int $characterId, int $typeId, int $solarSystemId, string $date): bool
    {
        // Format date to match the database timestamp format
        $mDate = $date . " 00:00:00";
        
        try {
            // Query the corporation mining observer data
            // This table is populated by SeAT from ESI API and ONLY contains
            // mining data from YOUR corporation's moon mining structures
            $result = DB::table('corporation_industry_mining_observer_data as d')
                ->select('d.*')
                ->join('universe_structures as u', 'd.observer_id', '=', 'u.structure_id')
                ->where('d.last_updated', '=', $mDate)
                ->where('d.character_id', '=', $characterId)
                ->where('d.type_id', '=', $typeId)
                ->where('u.solar_system_id', '=', $solarSystemId)
                ->first();
            
            // If found, this mining event is from YOUR corp's moon
            if (!is_null($result)) {
                Log::debug("Mining Manager: Moon ore type {$typeId} confirmed as CORP MOON for character {$characterId}");
                return true;
            } else {
                Log::debug("Mining Manager: Moon ore type {$typeId} is from OTHER corp's moon for character {$characterId}");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error checking corp moon: " . $e->getMessage());
            // On error, default to false (don't tax)
            return false;
        }
    }
    
    /**
     * Update overdue tax statuses.
     *
     * @return int Number of taxes marked as overdue
     */
    public function updateOverdueTaxes(): int
    {
        $exemptions = $this->settingsService->getExemptions();
        $gracePeriod = $exemptions['grace_period_days'];
        $overdueDate = Carbon::now()->subDays($gracePeriod);

        $updated = MiningTax::where('status', 'unpaid')
            ->where('month', '<', $overdueDate->startOfMonth())
            ->update(['status' => 'overdue']);

        if ($updated > 0) {
            Log::info("Mining Manager: Marked {$updated} taxes as overdue");
        }

        return $updated;
    }

    /**
     * Recalculate tax for a specific character and month.
     *
     * @param int $characterId
     * @param Carbon $month
     * @return float
     */
    public function recalculateTax(int $characterId, Carbon $month): float
    {
        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();
    
        $taxAmount = $this->calculateCharacterTax($characterId, $startDate, $endDate);
    
        $tax = MiningTax::where('character_id', $characterId)
            ->where('month', $startDate->format('Y-m-01'))
            ->first();
    
        if ($tax) {
            $tax->update([
                'amount_owed' => $taxAmount,
                'calculated_at' => Carbon::now(),
            ]);
        }
    
        return $taxAmount;
    }

    /**
     * Get tax breakdown for a character.
     *
     * @param int $characterId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getTaxBreakdown(int $characterId, Carbon $startDate, Carbon $endDate): array
    {
        $miningData = MiningLedger::where('character_id', $characterId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('processed_at')
            ->get();

        // Get character's corporation ID for tax rate determination
        $character = CharacterInfo::find($characterId);
        $characterCorpId = $character ? $character->corporation_id : null;

        // Pre-build corp moon cache for batch optimization (avoids N+1 queries)
        $corpMoonCache = $this->buildCorpMoonCache($miningData);

        $breakdown = [];
        $totalValue = 0;
        $totalTax = 0;

        foreach ($miningData as $entry) {
            if (!$this->shouldTaxOre($entry->type_id, $entry, $corpMoonCache)) {
                continue;
            }

            $value = $this->calculateOreValue($entry);
            $taxRate = $this->getTaxRateForOre($entry->type_id, $characterCorpId) / 100;
            $tax = $value * $taxRate;

            $totalValue += $value;
            $totalTax += $tax;

            // Get ore name from type_id (you might need to join with invTypes table)
            $oreName = $this->getOreName($entry->type_id);
            $oreCategory = $this->getOreCategory($entry->type_id);
            $moonRarity = $this->getMoonOreRarity($entry->type_id);

            $key = $oreName;

            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'type_id' => $entry->type_id,
                    'name' => $oreName,
                    'category' => $oreCategory,
                    'rarity' => $moonRarity,
                    'quantity' => 0,
                    'value' => 0,
                    'tax_rate' => $this->getTaxRateForOre($entry->type_id, $characterCorpId),
                    'tax' => 0,
                ];
            }

            $breakdown[$key]['quantity'] += $entry->quantity;
            $breakdown[$key]['value'] += $value;
            $breakdown[$key]['tax'] += $tax;
        }

        return [
            'breakdown' => array_values($breakdown),
            'total_value' => $totalValue,
            'total_tax' => $totalTax,
            'calculation_method' => $this->settingsService->getGeneralSettings()['tax_calculation_method'],
        ];
    }

    /**
     * Get ore name from type_id.
     * 
     * @param int $typeId
     * @return string
     */
    private function getOreName(int $typeId): string
    {
        // Try to get from invTypes table (SeAT has this)
        try {
            $type = DB::table('invTypes')
                ->where('typeID', $typeId)
                ->first();

            return $type ? $type->typeName : "Unknown Ore ({$typeId})";
        } catch (\Exception $e) {
            return "Type {$typeId}";
        }
    }

    /**
     * Get tax statistics for a period.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getTaxStatistics(Carbon $startDate, Carbon $endDate): array
    {
        $taxes = MiningTax::whereBetween('month', [$startDate->format('Y-m-01'), $endDate->format('Y-m-01')])
            ->get();

        $statistics = [
            'total_owed' => $taxes->sum('amount_owed'),
            'total_paid' => $taxes->sum('amount_paid'),
            'outstanding' => $taxes->sum(function($tax) {
                return $tax->amount_owed - $tax->amount_paid;
            }),
            'count_unpaid' => $taxes->where('status', 'unpaid')->count(),
            'count_partial' => $taxes->where('status', 'partial')->count(),
            'count_paid' => $taxes->where('status', 'paid')->count(),
            'count_overdue' => $taxes->where('status', 'overdue')->count(),
            'count_waived' => $taxes->where('status', 'waived')->count(),
        ];

        return $statistics;
    }

    /**
     * Apply manual tax adjustment.
     *
     * @param int $taxId
     * @param float $adjustmentAmount
     * @param string $reason
     * @return bool
     */
    public function applyTaxAdjustment(int $taxId, float $adjustmentAmount, string $reason): bool
    {
        try {
            $tax = MiningTax::findOrFail($taxId);

            $newAmount = max(0, $tax->amount_owed + $adjustmentAmount);

            $tax->update([
                'amount_owed' => $newAmount,
                'notes' => ($tax->notes ? $tax->notes . "\n" : '') . 
                          Carbon::now()->toDateTimeString() . " - Adjustment: " . 
                          number_format($adjustmentAmount, 2) . " ISK. Reason: {$reason}",
            ]);

            Log::info("Mining Manager: Applied tax adjustment for tax {$taxId}: " . number_format($adjustmentAmount, 2) . " ISK");

            return true;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error applying tax adjustment: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Waive tax for a character.
     *
     * @param int $taxId
     * @param string $reason
     * @return bool
     */
    public function waiveTax(int $taxId, string $reason): bool
    {
        try {
            $tax = MiningTax::findOrFail($taxId);

            $tax->update([
                'status' => 'waived',
                'notes' => ($tax->notes ? $tax->notes . "\n" : '') . 
                          Carbon::now()->toDateTimeString() . " - Tax waived. Reason: {$reason}",
            ]);

            Log::info("Mining Manager: Waived tax {$taxId} for character {$tax->character_id}");

            return true;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error waiving tax: " . $e->getMessage());
            return false;
        }
    }
}
