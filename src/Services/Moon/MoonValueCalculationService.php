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
     * MOON ORE VARIANTS ONLY - No compressed ores (moons don't produce compressed)
     * Compressed ore Type IDs remain in TypeIdRegistry for future reprocessing calculator
     * 
     * Uses ACCURATE CCP data with Type IDs verified against TypeIdRegistry.
     * Values are per 100-unit batch (standard reprocessing batch size).
     * Moon ores are 16 m³ per unit, so 100 units = 1600 m³.
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
        
        // ============================================================================
        // MOON ORE YIELDS - 60 UNCOMPRESSED VARIANTS ONLY
        // ============================================================================
        // Includes: Base (20) + Improved +15% (20) + Jackpot +100% (20)
        // Excludes: Compressed ores (60) - not found in moon extractions
        // Note: Compressed ore Type IDs are in TypeIdRegistry for reprocessing calculator
        // Type IDs verified against TypeIdRegistry
        // Reference: https://wiki.eveuniversity.org/Moon_mining#Moon_Ore_Refining
        // ============================================================================
        
        $moonOreYields = [];
        
        // ============================================================================
        // R4 (UBIQUITOUS) MOON ORES
        // R4 ores give: Pyerite + Mexallon + R4 Moon Material
        // ============================================================================
        
        // ---------- BITUMENS FAMILY (produces Hydrocarbons) ----------
        
        // Base Bitumens (45492)
        $moonOreYields[45492] = [35 => 9600, 36 => 640, 16633 => 104];
        // Brimful Bitumens +15% (46284)
        $moonOreYields[46284] = [35 => 11040, 36 => 736, 16633 => 120];
        // Glistening Bitumens +100% (46285)
        $moonOreYields[46285] = [35 => 19200, 36 => 1280, 16633 => 208];
        
        // ---------- COESITE FAMILY (produces Silicates) ----------
        
        // Base Coesite (45493)
        $moonOreYields[45493] = [35 => 3200, 36 => 640, 16636 => 104];
        // Brimful Coesite +15% (46286)
        $moonOreYields[46286] = [35 => 3680, 36 => 736, 16636 => 120];
        // Glistening Coesite +100% (46287)
        $moonOreYields[46287] = [35 => 6400, 36 => 1280, 16636 => 208];
        
        // ---------- SYLVITE FAMILY (produces Evaporite Deposits) ----------
        
        // Base Sylvite (45491)
        $moonOreYields[45491] = [35 => 6400, 36 => 640, 16635 => 104];
        // Brimful Sylvite +15% (46282)
        $moonOreYields[46282] = [35 => 7360, 36 => 736, 16635 => 120];
        // Glistening Sylvite +100% (46283)
        $moonOreYields[46283] = [35 => 12800, 36 => 1280, 16635 => 208];
        
        // ---------- ZEOLITES FAMILY (produces Atmospheric Gases) ----------
        
        // Base Zeolites (45490)
        $moonOreYields[45490] = [35 => 12800, 36 => 640, 16638 => 104];
        // Brimful Zeolites +15% (46280)
        $moonOreYields[46280] = [35 => 14720, 36 => 736, 16638 => 120];
        // Glistening Zeolites +100% (46281)
        $moonOreYields[46281] = [35 => 25600, 36 => 1280, 16638 => 208];
        
        // ============================================================================
        // R8 (COMMON) MOON ORES
        // R8 ores give: ONLY R8 Moon Materials (NO regular minerals)
        // ============================================================================
        
        // ---------- COBALTITE FAMILY (produces Cobalt) ----------
        
        // Base Cobaltite (45494)
        $moonOreYields[45494] = [16634 => 64];
        // Copious Cobaltite +15% (46288)
        $moonOreYields[46288] = [16634 => 74];
        // Twinkling Cobaltite +100% (46289)
        $moonOreYields[46289] = [16634 => 128];
        
        // ---------- EUXENITE FAMILY (produces Scandium) ----------
        
        // Base Euxenite (45495)
        $moonOreYields[45495] = [16655 => 64];
        // Copious Euxenite +15% (46290)
        $moonOreYields[46290] = [16655 => 74];
        // Twinkling Euxenite +100% (46291)
        $moonOreYields[46291] = [16655 => 128];
        
        // ---------- SCHEELITE FAMILY (produces Tungsten) ----------
        
        // Base Scheelite (45497)
        $moonOreYields[45497] = [16637 => 64];
        // Copious Scheelite +15% (46294)
        $moonOreYields[46294] = [16637 => 74];
        // Twinkling Scheelite +100% (46295)
        $moonOreYields[46295] = [16637 => 128];
        
        // ---------- TITANITE FAMILY (produces Titanium) ----------
        
        // Base Titanite (45496)
        $moonOreYields[45496] = [16639 => 64];
        // Copious Titanite +15% (46292)
        $moonOreYields[46292] = [16639 => 74];
        // Twinkling Titanite +100% (46293)
        $moonOreYields[46293] = [16639 => 128];
        
        // ============================================================================
        // R16 (UNCOMMON) MOON ORES
        // R16 ores give: R16 Moon Material + small amount of R4
        // ============================================================================
        
        // ---------- CHROMITE FAMILY (produces Chromium + Hydrocarbons) ----------
        
        // Base Chromite (45501)
        $moonOreYields[45501] = [16641 => 64, 16633 => 16];
        // Lavish Chromite +15% (46302)
        $moonOreYields[46302] = [16641 => 74, 16633 => 18];
        // Shimmering Chromite +100% (46303)
        $moonOreYields[46303] = [16641 => 128, 16633 => 32];
        
        // ---------- OTAVITE FAMILY (produces Cadmium + Atmospheric Gases) ----------
        
        // Base Otavite (45498)
        $moonOreYields[45498] = [16640 => 64, 16638 => 16];
        // Lavish Otavite +15% (46296)
        $moonOreYields[46296] = [16640 => 74, 16638 => 18];
        // Shimmering Otavite +100% (46297)
        $moonOreYields[46297] = [16640 => 128, 16638 => 32];
        
        // ---------- SPERRYLITE FAMILY (produces Platinum + Evaporite Deposits) ----------
        
        // Base Sperrylite (45499)
        $moonOreYields[45499] = [16647 => 64, 16635 => 16];
        // Lavish Sperrylite +15% (46298)
        $moonOreYields[46298] = [16647 => 74, 16635 => 18];
        // Shimmering Sperrylite +100% (46299)
        $moonOreYields[46299] = [16647 => 128, 16635 => 32];
        
        // ---------- VANADINITE FAMILY (produces Vanadium + Silicates) ----------
        
        // Base Vanadinite (45500)
        $moonOreYields[45500] = [16644 => 64, 16636 => 16];
        // Lavish Vanadinite +15% (46300)
        $moonOreYields[46300] = [16644 => 74, 16636 => 18];
        // Shimmering Vanadinite +100% (46301)
        $moonOreYields[46301] = [16644 => 128, 16636 => 32];
        
        // ============================================================================
        // R32 (RARE) MOON ORES
        // R32 ores give: R32 + R8 + R4 Moon Materials
        // ============================================================================
        
        // ---------- CARNOTITE FAMILY (produces Technetium + Cobalt + Atmospheric Gases) ----------
        
        // Base Carnotite (45502)
        $moonOreYields[45502] = [16643 => 80, 16634 => 16, 16638 => 24];
        // Replete Carnotite +15% (46304)
        $moonOreYields[46304] = [16643 => 92, 16634 => 18, 16638 => 28];
        // Glowing Carnotite +100% (46305)
        $moonOreYields[46305] = [16643 => 160, 16634 => 32, 16638 => 48];
        
        // ---------- CINNABAR FAMILY (produces Mercury + Tungsten + Evaporite Deposits) ----------
        
        // Base Cinnabar (45506)
        $moonOreYields[45506] = [16646 => 80, 16637 => 16, 16635 => 24];
        // Replete Cinnabar +15% (46310)
        $moonOreYields[46310] = [16646 => 92, 16637 => 18, 16635 => 28];
        // Glowing Cinnabar +100% (46311)
        $moonOreYields[46311] = [16646 => 160, 16637 => 32, 16635 => 48];
        
        // ---------- POLLUCITE FAMILY (produces Caesium + Scandium + Hydrocarbons) ----------
        
        // Base Pollucite (45504)
        $moonOreYields[45504] = [16642 => 80, 16655 => 16, 16633 => 24];
        // Replete Pollucite +15% (46308)
        $moonOreYields[46308] = [16642 => 92, 16655 => 18, 16633 => 28];
        // Glowing Pollucite +100% (46309)
        $moonOreYields[46309] = [16642 => 160, 16655 => 32, 16633 => 48];
        
        // ---------- ZIRCON FAMILY (produces Hafnium + Titanium + Silicates) ----------
        
        // Base Zircon (45503)
        $moonOreYields[45503] = [16648 => 80, 16639 => 16, 16636 => 24];
        // Replete Zircon +15% (46306)
        $moonOreYields[46306] = [16648 => 92, 16639 => 18, 16636 => 28];
        // Glowing Zircon +100% (46307)
        $moonOreYields[46307] = [16648 => 160, 16639 => 32, 16636 => 48];
        
        // ============================================================================
        // R64 (EXCEPTIONAL) MOON ORES
        // R64 ores give: R64 + R16 + R8 + R4 Moon Materials
        // ============================================================================
        
        // ---------- XENOTIME FAMILY (produces Dysprosium + Vanadium + Cobalt + Atmospheric Gases) ----------
        
        // Base Xenotime (45510)
        $moonOreYields[45510] = [16650 => 35, 16644 => 16, 16634 => 32, 16638 => 32];
        // Bountiful Xenotime +15% (46312)
        $moonOreYields[46312] = [16650 => 40, 16644 => 18, 16634 => 37, 16638 => 37];
        // Shining Xenotime +100% (46313)
        $moonOreYields[46313] = [16650 => 70, 16644 => 32, 16634 => 64, 16638 => 64];
        
        // ---------- MONAZITE FAMILY (produces Neodymium + Chromium + Tungsten + Evaporite Deposits) ----------
        
        // Base Monazite (45511)
        $moonOreYields[45511] = [16649 => 35, 16641 => 16, 16637 => 32, 16635 => 32];
        // Bountiful Monazite +15% (46314)
        $moonOreYields[46314] = [16649 => 40, 16641 => 18, 16637 => 37, 16635 => 37];
        // Shining Monazite +100% (46315)
        $moonOreYields[46315] = [16649 => 70, 16641 => 32, 16637 => 64, 16635 => 64];
        
        // ---------- LOPARITE FAMILY (produces Promethium + Platinum + Scandium + Hydrocarbons) ----------
        
        // Base Loparite (45512)
        $moonOreYields[45512] = [16652 => 35, 16647 => 16, 16655 => 32, 16633 => 32];
        // Bountiful Loparite +15% (46316)
        $moonOreYields[46316] = [16652 => 40, 16647 => 18, 16655 => 37, 16633 => 37];
        // Shining Loparite +100% (46317)
        $moonOreYields[46317] = [16652 => 70, 16647 => 32, 16655 => 64, 16633 => 64];
        
        // ---------- YTTERBITE FAMILY (produces Thulium + Cadmium + Titanium + Silicates) ----------
        
        // Base Ytterbite (45513)
        $moonOreYields[45513] = [16651 => 35, 16640 => 16, 16639 => 32, 16636 => 32];
        // Bountiful Ytterbite +15% (46318)
        $moonOreYields[46318] = [16651 => 40, 16640 => 18, 16639 => 37, 16636 => 37];
        // Shining Ytterbite +100% (46319)
        $moonOreYields[46319] = [16651 => 70, 16640 => 32, 16639 => 64, 16636 => 64];
        
        // ============================================================================
        // TOTAL: 60 moon ore variants defined (uncompressed only)
        // ============================================================================
        // Compressed ore yields excluded - moons don't produce compressed ores
        // Compressed Type IDs remain in TypeIdRegistry for reprocessing calculator
        // ============================================================================
        
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
