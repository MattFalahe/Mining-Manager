<?php

namespace MiningManager\Services\Moon;

use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MiningPriceCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MoonValueCalculationService
{
    /**
     * Calculate the estimated ISK value of a moon extraction.
     *
     * @param MoonExtraction $extraction
     * @return float|null
     */
    public function calculateExtractionValue(MoonExtraction $extraction): ?float
    {
        if (!$extraction->ore_composition) {
            Log::debug("Mining Manager: No ore composition data for extraction {$extraction->id}");
            return null;
        }

        $cacheKey = "mining-manager:moon-value:{$extraction->id}";
        $cacheDuration = config('mining-manager.pricing.cache_duration', 60);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($extraction) {
            return $this->calculateValue($extraction->ore_composition);
        });
    }

    /**
     * Calculate value from ore composition array.
     *
     * @param array $oreComposition
     * @return float
     */
    private function calculateValue(array $oreComposition): float
    {
        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');
        
        // Check if refined value calculation is enabled
        // First check database settings, then fall back to config
        $useRefinedValue = $this->getSetting('pricing.use_refined_value', 
            config('mining-manager.pricing.use_refined_value', false));
        
        // Get estimated chunk size from config (default: 150,000 m³)
        $chunkSize = config('mining-manager.moon.estimated_chunk_size', 150000);

        $totalValue = 0;

        foreach ($oreComposition as $oreName => $oreData) {
            // Handle both old and new structure
            if (is_array($oreData) && isset($oreData['type_id'])) {
                $typeId = $oreData['type_id'];
                $percentage = $oreData['percentage'] ?? 0;
                // Don't use stored quantity - calculate it from percentage
            } else {
                // Old structure: $oreName is actually typeId, $oreData is quantity
                $typeId = is_numeric($oreName) ? (int) $oreName : null;
                $percentage = 0;
                // For old structure, use the quantity directly if it exists
                $quantity = is_numeric($oreData) ? $oreData : 0;
            }

            if (!$typeId) {
                Log::warning("Mining Manager: Invalid type_id for ore {$oreName}");
                continue;
            }

            // Calculate quantity from percentage if we have it
            if ($percentage > 0) {
                // Get ore volume (moon ores are typically 16 m³ per unit)
                $oreVolume = $this->getOreVolume($typeId);
                
                if ($oreVolume > 0) {
                    // Quantity = (percentage / 100) × (chunk size / ore volume)
                    $quantity = ($percentage / 100) * ($chunkSize / $oreVolume);
                } else {
                    // Fallback if volume not found
                    $quantity = 0;
                }
            }

            if ($quantity <= 0) {
                continue;
            }

            // Get price based on valuation method
            if ($useRefinedValue) {
                // Calculate value based on refined minerals
                $value = $this->calculateRefinedValue($typeId, $quantity);
            } else {
                // Use raw ore price
                $price = $this->getOrePrice($typeId, $regionId, $priceType);

                if ($price === null) {
                    Log::warning("Mining Manager: No price data for moon ore type_id {$typeId}");
                    continue;
                }
                
                $value = $quantity * $price;
            }

            $totalValue += $value;
        }

        return round($totalValue, 2);
    }

    /**
     * Get ore price from cache.
     *
     * @param int $typeId
     * @param int $regionId
     * @param string $priceType
     * @return float|null
     */
    /**
     * Get price for an ore/mineral/material type.
     * First checks mining_price_cache, then falls back to SeAT's market_prices table.
     *
     * @param int $typeId
     * @param int $regionId
     * @param string $priceType
     * @return float|null
     */
    private function getOrePrice(int $typeId, int $regionId, string $priceType): ?float
    {
        // First, try mining_price_cache
        $priceCache = MiningPriceCache::where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->latest('cached_at')
            ->first();

        if ($priceCache) {
            // Check if cache is fresh
            $cacheDuration = config('mining-manager.pricing.cache_duration', 60);
            if (!$priceCache->cached_at->addMinutes($cacheDuration)->isPast()) {
                // Cache is fresh, use it
                return match ($priceType) {
                    'buy' => $priceCache->buy_price,
                    'average' => $priceCache->average_price,
                    default => $priceCache->sell_price,
                };
            }
        }

        // Fallback: Check SeAT's built-in market_prices table
        // This table has prices for all items (ores, minerals, moon materials, etc.)
        try {
            $marketPrice = \DB::table('market_prices')
                ->where('type_id', $typeId)
                ->first();
            
            if ($marketPrice) {
                Log::debug("Mining Manager: Using market_prices fallback for type_id {$typeId}");
                
                // market_prices table structure: type_id, average_price, adjusted_price
                // For now, use average_price for all price types
                // You could potentially use adjusted_price for 'sell' if you prefer
                return match ($priceType) {
                    'buy' => $marketPrice->average_price,      // Could also use adjusted_price
                    'average' => $marketPrice->average_price,
                    default => $marketPrice->average_price,     // 'sell' - could use adjusted_price
                };
            }
        } catch (\Exception $e) {
            Log::warning("Mining Manager: Could not fetch from market_prices for type_id {$typeId}: " . $e->getMessage());
        }

        // No price found anywhere
        Log::warning("Mining Manager: No price data found for type_id {$typeId} in region {$regionId}");
        return null;
    }

    /**
     * Calculate refined mineral value from ore.
     * This calculates the total value of minerals you get from reprocessing the ore.
     *
     * @param int $typeId Ore type ID
     * @param float $quantity Quantity of ore units
     * @return float Total ISK value of refined minerals
     */
    private function calculateRefinedValue(int $typeId, float $quantity): float
    {
        // Get refining efficiency from settings (database first, then config)
        $refiningEfficiency = $this->getSetting('pricing.refining_efficiency',
            config('mining-manager.pricing.refining_efficiency', 87.5)) / 100;

        // Get mineral yields for this ore type
        $mineralYields = $this->getMineralYields($typeId);

        if (empty($mineralYields)) {
            Log::warning("Mining Manager: No mineral yields found for ore type_id {$typeId}, using fallback");
            
            // Fallback: Try to use raw ore price with efficiency modifier
            $regionId = config('mining-manager.pricing.default_region_id', 10000002);
            $priceType = config('mining-manager.pricing.price_type', 'sell');
            $orePrice = $this->getOrePrice($typeId, $regionId, $priceType);
            
            if ($orePrice) {
                // Apply efficiency as a simple modifier to ore price
                return $quantity * $orePrice * $refiningEfficiency;
            }
            
            return 0;
        }

        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');

        $totalRefinedValue = 0;

        // Calculate value for each mineral yielded
        foreach ($mineralYields as $mineralTypeId => $yieldPerBatch) {
            // Get current market price for this mineral
            $mineralPrice = $this->getOrePrice($mineralTypeId, $regionId, $priceType);
            
            if (!$mineralPrice) {
                Log::warning("Mining Manager: No price data for mineral type_id {$mineralTypeId}");
                continue;
            }
            
            // Calculate actual yield with efficiency
            // Yield = (ore quantity × yield per batch × refining efficiency)
            $actualYield = $quantity * $yieldPerBatch * $refiningEfficiency;
            
            // Calculate value for this mineral
            $mineralValue = $actualYield * $mineralPrice;
            
            $totalRefinedValue += $mineralValue;
            
            Log::debug("Mining Manager: Ore {$typeId} → Mineral {$mineralTypeId}: {$actualYield} units @ {$mineralPrice} ISK = {$mineralValue} ISK");
        }

        return $totalRefinedValue;
    }
    
    /**
     * Get a setting value from database.
     * Helper method to access settings without circular dependency.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getSetting(string $key, $default = null)
    {
        try {
            $setting = \DB::table('mining_manager_settings')
                ->where('key', $key)
                ->first();
            
            if (!$setting) {
                return $default;
            }
            
            // Cast value based on type
            return match($setting->type) {
                'boolean' => (bool) $setting->value,
                'integer' => (int) $setting->value,
                'float' => (float) $setting->value,
                'array' => json_decode($setting->value, true),
                default => $setting->value,
            };
            
        } catch (\Exception $e) {
            Log::debug("Mining Manager: Could not get setting {$key}: " . $e->getMessage());
            return $default;
        }
    }

    /**
     * Get mineral yields for an ore type.
     * Uses ACCURATE CCP data as of 2020+ moon ore changes.
     * First tries to get from EVE SDE database, then falls back to hardcoded values.
     *
     * Values are per 100-unit batch (standard reprocessing batch size).
     * Moon ores are 16 m³ per unit, so 100 units = 1600 m³.
     * Wiki shows yields per 1000 m³ (62.5 units), converted here to per 100 units.
     *
     * @param int $typeId
     * @return array [mineralTypeId => yieldAmount]
     */
    private function getMineralYields(int $typeId): array
    {
        // First, try to get yields from EVE SDE database
        $yields = $this->getMineralYieldsFromDatabase($typeId);
        
        if (!empty($yields)) {
            return $yields;
        }
        
        // Fallback: ACCURATE hardcoded moon ore yields (per 100-unit batch)
        // Based on EVE University Wiki and CCP's 2020 moon ore changes
        // Reference: https://wiki.eveuniversity.org/Moon_mining#Moon_Ore_Refining
        
        $moonOreYields = [
            // ============ R4 (UBIQUITOUS) MOON ORES ============
            // Only R4 ores give common minerals (Pyerite + Mexallon)
            
            // Bitumens (Type ID: 45506)
            45506 => [
                35 => 9600,     // Pyerite
                36 => 640,      // Mexallon
                16633 => 104,   // Hydrocarbons (R4)
            ],
            
            // Coesite (Type ID: 45489)
            45489 => [
                35 => 3200,     // Pyerite
                36 => 640,      // Mexallon
                16636 => 104,   // Silicates (R4)
            ],
            
            // Sylvite (Type ID: 45493)
            45493 => [
                35 => 6400,     // Pyerite
                36 => 640,      // Mexallon
                16635 => 104,   // Evaporite Deposits (R4)
            ],
            
            // Zeolites (Type ID: 45497)
            45497 => [
                35 => 12800,    // Pyerite
                36 => 640,      // Mexallon
                16638 => 104,   // Atmospheric Gases (R4)
            ],
            
            // ============ R8 (COMMON) MOON ORES ============
            // R8 ores give ONLY moon materials, NO common minerals
            
            // Cobaltite (Type ID: 45494)
            45494 => [
                16639 => 64,    // Cobalt (R8)
            ],
            
            // Euxenite (Type ID: 45495)
            45495 => [
                16655 => 64,    // Scandium (R8)
            ],
            
            // Scheelite (Type ID: 46682)
            46682 => [
                16637 => 64,    // Tungsten (R8)
            ],
            
            // Titanite (Type ID: 46683)
            46683 => [
                16634 => 64,    // Titanium (R8)
            ],
            
            // ============ R16 (UNCOMMON) MOON ORES ============
            // R16 ores give R16 moon materials + small amount of R4
            
            // Chromite (Type ID: 45492)
            45492 => [
                16641 => 64,    // Chromium (R16)
                16633 => 16,    // Hydrocarbons (R4)
            ],
            
            // Otavite (Type ID: 46679)
            46679 => [
                16640 => 64,    // Cadmium (R16)
                16638 => 16,    // Atmospheric Gases (R4)
            ],
            
            // Sperrylite (Type ID: 46687)
            46687 => [
                16647 => 64,    // Platinum (R16)
                16635 => 16,    // Evaporite Deposits (R4)
            ],
            
            // Vanadinite (Type ID: 46688)
            46688 => [
                16644 => 64,    // Vanadium (R16)
                16636 => 16,    // Silicates (R4)
            ],
            
            // ============ R32 (RARE) MOON ORES ============
            // R32 ores give R32 + R8 + R4 moon materials
            
            // Carnotite (Type ID: 46677)
            46677 => [
                16643 => 80,    // Technetium (R32)
                16639 => 16,    // Cobalt (R8)
                16638 => 24,    // Atmospheric Gases (R4)
            ],
            
            // Cinnabar (Type ID: 45490)
            45490 => [
                16646 => 80,    // Mercury (R32)
                16637 => 16,    // Tungsten (R8)
                16635 => 24,    // Evaporite Deposits (R4)
            ],
            
            // Pollucite (Type ID: 46680)
            46680 => [
                16642 => 80,    // Caesium (R32)
                16655 => 16,    // Scandium (R8)
                16633 => 24,    // Hydrocarbons (R4)
            ],
            
            // Zircon (Type ID: 46681)
            46681 => [
                16648 => 80,    // Hafnium (R32)
                16634 => 16,    // Titanium (R8)
                16636 => 24,    // Silicates (R4)
            ],
            
            // ============ R64 (EXCEPTIONAL) MOON ORES ============
            // R64 ores give R64 + R16 + R8 + R4 moon materials
            
            // Loparite (Type ID: 46678)
            46678 => [
                16652 => 35,    // Promethium (R64)
                16647 => 16,    // Platinum (R16)
                16655 => 32,    // Scandium (R8)
                16633 => 32,    // Hydrocarbons (R4)
            ],
            
            // Monazite (Type ID: 46676)
            46676 => [
                16649 => 35,    // Neodymium (R64)
                16641 => 16,    // Chromium (R16)
                16637 => 32,    // Tungsten (R8)
                16635 => 32,    // Evaporite Deposits (R4)
            ],
            
            // Xenotime (Type ID: 45491)
            45491 => [
                16650 => 35,    // Dysprosium (R64)
                16644 => 16,    // Vanadium (R16)
                16639 => 32,    // Cobalt (R8)
                16638 => 32,    // Atmospheric Gases (R4)
            ],
            
            // Ytterbite (Type ID: 46689)
            46689 => [
                16651 => 35,    // Thulium (R64)
                16640 => 16,    // Cadmium (R16)
                16634 => 32,    // Titanium (R8)
                16636 => 32,    // Silicates (R4)
            ],
        ];

        return $moonOreYields[$typeId] ?? [];
    }

    /**
     * Get mineral yields directly from EVE SDE database.
     *
     * @param int $typeId
     * @return array
     */
    private function getMineralYieldsFromDatabase(int $typeId): array
    {
        try {
            // Query the invTypeMaterials table which contains reprocessing yields
            $materials = \DB::table('invTypeMaterials')
                ->where('typeID', $typeId)
                ->get();
            
            if ($materials->isEmpty()) {
                return [];
            }
            
            $yields = [];
            foreach ($materials as $material) {
                // materialTypeID is the mineral/material you get
                // quantity is the amount per batch
                $yields[$material->materialTypeID] = $material->quantity;
            }
            
            return $yields;
            
        } catch (\Exception $e) {
            Log::warning("Mining Manager: Could not get mineral yields from database for type {$typeId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Compare extraction values over time.
     *
     * @param int $structureId
     * @param int $numExtractions
     * @return array
     */
    public function compareExtractionValues(int $structureId, int $numExtractions = 10): array
    {
        $extractions = MoonExtraction::where('structure_id', $structureId)
            ->whereNotNull('ore_composition')
            ->whereNotNull('estimated_value')
            ->orderBy('extraction_start_time', 'desc')
            ->limit($numExtractions)
            ->get();

        if ($extractions->isEmpty()) {
            return [
                'average_value' => 0,
                'min_value' => 0,
                'max_value' => 0,
                'value_variance' => 0,
                'extractions' => [],
            ];
        }

        $values = $extractions->pluck('estimated_value');
        $average = $values->avg();
        $min = $values->min();
        $max = $values->max();

        // Calculate variance
        $variance = $values->map(function ($value) use ($average) {
            return pow($value - $average, 2);
        })->avg();

        return [
            'average_value' => round($average, 2),
            'min_value' => $min,
            'max_value' => $max,
            'value_variance' => round($variance, 2),
            'standard_deviation' => round(sqrt($variance), 2),
            'coefficient_of_variation' => $average > 0 ? round((sqrt($variance) / $average) * 100, 2) : 0,
            'extractions' => $extractions->map(fn($e) => [
                'extraction_start_time' => $e->extraction_start_time,
                'value' => $e->estimated_value,
            ])->toArray(),
        ];
    }

    /**
     * Get ore composition breakdown with values.
     *
     * @param MoonExtraction $extraction
     * @return array
     */
    public function getOreCompositionBreakdown(MoonExtraction $extraction): array
    {
        if (!$extraction->ore_composition) {
            return [];
        }

        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');
        $chunkSize = config('mining-manager.moon.estimated_chunk_size', 150000);

        $breakdown = [];
        $totalValue = 0;

        foreach ($extraction->ore_composition as $oreName => $oreData) {
            // Handle both old and new structure
            if (is_array($oreData) && isset($oreData['type_id'])) {
                $typeId = $oreData['type_id'];
                $percentage = $oreData['percentage'] ?? 0;
            } else {
                // Old structure
                $typeId = is_numeric($oreName) ? (int) $oreName : null;
                $percentage = 0;
                $quantity = is_numeric($oreData) ? $oreData : 0;
                $oreName = $this->getOreName($typeId);
            }

            if (!$typeId) {
                continue;
            }

            // Calculate quantity from percentage if we have it
            if ($percentage > 0) {
                $oreVolume = $this->getOreVolume($typeId);
                if ($oreVolume > 0) {
                    $quantity = ($percentage / 100) * ($chunkSize / $oreVolume);
                } else {
                    $quantity = 0;
                }
            }

            $price = $this->getOrePrice($typeId, $regionId, $priceType);
            $value = ($price && $quantity > 0) ? $quantity * $price : 0;
            $totalValue += $value;

            $breakdown[] = [
                'type_id' => $typeId,
                'ore_name' => is_numeric($oreName) ? $this->getOreName($typeId) : $oreName,
                'percentage' => $percentage,
                'quantity' => round($quantity, 2),
                'unit_price' => $price,
                'total_value' => round($value, 2),
            ];
        }

        // Sort by value descending
        usort($breakdown, fn($a, $b) => $b['total_value'] <=> $a['total_value']);

        // Add percentage of total
        foreach ($breakdown as &$item) {
            $item['percentage_of_total'] = $totalValue > 0 
                ? round(($item['total_value'] / $totalValue) * 100, 2) 
                : 0;
        }

        return [
            'total_value' => round($totalValue, 2),
            'ore_types' => $breakdown,
            'chunk_size' => $chunkSize,
        ];
    }

    /**
     * Get ore name from type ID.
     *
     * @param int $typeId
     * @return string
     */
    private function getOreName(int $typeId): string
    {
        // Query the invTypes table (SeAT uses this table from EVE SDE)
        $type = \DB::table('invTypes')
            ->where('typeID', $typeId)
            ->first();

        return $type ? $type->typeName : "Type {$typeId}";
    }

    /**
     * Calculate value per cubic meter.
     *
     * @param MoonExtraction $extraction
     * @return float|null
     */
    public function calculateValuePerM3(MoonExtraction $extraction): ?float
    {
        $totalValue = $this->calculateExtractionValue($extraction);
        
        if ($totalValue === null || !$extraction->ore_composition) {
            return null;
        }

        $chunkSize = config('mining-manager.moon.estimated_chunk_size', 150000);
        $totalVolume = 0;

        foreach ($extraction->ore_composition as $oreName => $oreData) {
            // Handle both old and new structure
            if (is_array($oreData) && isset($oreData['type_id'])) {
                $typeId = $oreData['type_id'];
                $percentage = $oreData['percentage'] ?? 0;
            } else {
                // Old structure
                $typeId = is_numeric($oreName) ? (int) $oreName : null;
                $percentage = 0;
                $quantity = is_numeric($oreData) ? $oreData : 0;
            }

            if (!$typeId) {
                continue;
            }

            // Calculate quantity from percentage if we have it
            if ($percentage > 0) {
                $oreVolume = $this->getOreVolume($typeId);
                if ($oreVolume > 0) {
                    $quantity = ($percentage / 100) * ($chunkSize / $oreVolume);
                    $totalVolume += $quantity * $oreVolume;
                }
            } else if (isset($quantity) && $quantity > 0) {
                $volume = $this->getOreVolume($typeId);
                $totalVolume += $quantity * $volume;
            }
        }

        return $totalVolume > 0 ? round($totalValue / $totalVolume, 2) : null;
    }

    /**
     * Get ore volume per unit.
     *
     * @param int $typeId
     * @return float
     */
    private function getOreVolume(int $typeId): float
    {
        // Query the invTypes table for volume
        $type = \DB::table('invTypes')
            ->where('typeID', $typeId)
            ->first();

        return $type ? (float) $type->volume : 0;
    }

    /**
     * Estimate mining time for extraction.
     *
     * @param MoonExtraction $extraction
     * @param int $numMiners
     * @param float $miningRatePerHour Average m3 per hour per miner
     * @return array
     */
    public function estimateMiningTime(MoonExtraction $extraction, int $numMiners = 10, float $miningRatePerHour = 50000): array
    {
        if (!$extraction->ore_composition) {
            return [
                'total_volume' => 0,
                'estimated_hours' => 0,
                'estimated_hours_per_miner' => 0,
            ];
        }

        $chunkSize = config('mining-manager.moon.estimated_chunk_size', 150000);
        $totalVolume = 0;

        foreach ($extraction->ore_composition as $oreName => $oreData) {
            // Handle both old and new structure
            if (is_array($oreData) && isset($oreData['type_id'])) {
                $typeId = $oreData['type_id'];
                $percentage = $oreData['percentage'] ?? 0;
            } else {
                // Old structure
                $typeId = is_numeric($oreName) ? (int) $oreName : null;
                $percentage = 0;
                $quantity = is_numeric($oreData) ? $oreData : 0;
            }

            if (!$typeId) {
                continue;
            }

            // Calculate quantity from percentage if we have it
            if ($percentage > 0) {
                $oreVolume = $this->getOreVolume($typeId);
                if ($oreVolume > 0) {
                    $quantity = ($percentage / 100) * ($chunkSize / $oreVolume);
                    $totalVolume += $quantity * $oreVolume;
                }
            } else if (isset($quantity) && $quantity > 0) {
                $volume = $this->getOreVolume($typeId);
                $totalVolume += $quantity * $volume;
            }
        }

        $totalMiningRate = $miningRatePerHour * $numMiners;
        $estimatedHours = $totalMiningRate > 0 ? $totalVolume / $totalMiningRate : 0;
        $estimatedHoursPerMiner = $miningRatePerHour > 0 ? $totalVolume / $miningRatePerHour : 0;

        return [
            'total_volume' => round($totalVolume, 2),
            'estimated_hours' => round($estimatedHours, 2),
            'estimated_hours_per_miner' => round($estimatedHoursPerMiner, 2),
            'num_miners' => $numMiners,
            'mining_rate_per_hour' => $miningRatePerHour,
            'chunk_size' => $chunkSize,
        ];
    }

    /**
     * Get high-value moon extractions.
     *
     * @param int $limit
     * @param int $days
     * @return \Illuminate\Support\Collection
     */
    public function getHighValueExtractions(int $limit = 10, int $days = 30)
    {
        $startDate = now()->subDays($days);

        return MoonExtraction::with(['structure', 'moon'])
            ->whereNotNull('estimated_value')
            ->where('extraction_start_time', '>=', $startDate)
            ->orderByDesc('estimated_value')
            ->limit($limit)
            ->get()
            ->map(function ($extraction) {
                return [
                    'id' => $extraction->id,
                    'structure_name' => $extraction->structure->name ?? 'Unknown',
                    'moon_name' => $extraction->moon->name ?? 'Unknown',
                    'estimated_value' => $extraction->estimated_value,
                    'status' => $extraction->status,
                    'chunk_arrival_time' => $extraction->chunk_arrival_time,
                    'value_per_m3' => $this->calculateValuePerM3($extraction),
                ];
            });
    }

    /**
     * Calculate total moon mining income for corporation.
     *
     * @param int $corporationId
     * @param int $days
     * @return array
     */
    public function calculateMoonIncome(int $corporationId, int $days = 30): array
    {
        $startDate = now()->subDays($days);

        $extractions = MoonExtraction::where('corporation_id', $corporationId)
            ->whereNotNull('estimated_value')
            ->whereIn('status', ['fractured', 'expired'])
            ->where('natural_decay_time', '>=', $startDate)
            ->get();

        $totalValue = $extractions->sum('estimated_value');
        $averagePerExtraction = $extractions->count() > 0 
            ? $totalValue / $extractions->count() 
            : 0;

        // Estimate daily income
        $dailyIncome = $days > 0 ? $totalValue / $days : 0;

        return [
            'total_value' => round($totalValue, 2),
            'num_extractions' => $extractions->count(),
            'average_per_extraction' => round($averagePerExtraction, 2),
            'daily_income' => round($dailyIncome, 2),
            'monthly_income_projection' => round($dailyIncome * 30, 2),
            'period_days' => $days,
        ];
    }

    /**
     * Compare moon profitability.
     *
     * @param int $corporationId
     * @return array
     */
    public function compareMoonProfitability(int $corporationId): array
    {
        $extractions = MoonExtraction::where('corporation_id', $corporationId)
            ->whereNotNull('estimated_value')
            ->whereNotNull('moon_id')
            ->with('moon')
            ->get();

        $moonStats = $extractions->groupBy('moon_id')
            ->map(function ($moonExtractions, $moonId) {
                $totalValue = $moonExtractions->sum('estimated_value');
                $count = $moonExtractions->count();
                $averageValue = $count > 0 ? $totalValue / $count : 0;

                return [
                    'moon_id' => $moonId,
                    'moon_name' => $moonExtractions->first()->moon->name ?? "Moon {$moonId}",
                    'total_extractions' => $count,
                    'total_value' => round($totalValue, 2),
                    'average_value' => round($averageValue, 2),
                ];
            })
            ->sortByDesc('average_value')
            ->values();

        return [
            'moon_statistics' => $moonStats->toArray(),
            'most_profitable' => $moonStats->first(),
            'least_profitable' => $moonStats->last(),
        ];
    }

    /**
     * Clear value calculation cache.
     *
     * @param int|null $extractionId
     * @return void
     */
    public function clearValueCache(?int $extractionId = null): void
    {
        if ($extractionId) {
            Cache::forget("mining-manager:moon-value:{$extractionId}");
        } else {
            // Clear all moon value caches
            // This is implementation-specific based on your cache driver
            Cache::tags(['mining-manager', 'moon-values'])->flush();
        }
    }
}
