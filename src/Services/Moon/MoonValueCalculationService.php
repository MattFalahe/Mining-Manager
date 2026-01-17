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
            // Ensure ore_composition is an array
            $oreComposition = is_string($extraction->ore_composition)
                ? json_decode($extraction->ore_composition, true)
                : $extraction->ore_composition;

            return $this->calculateValue($oreComposition ?? []);
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

        // IMPORTANT: Moon extraction value is ALWAYS calculated based on refined materials
        // This is because the moon composition already shows what ores are present:
        // - If R4 ores (Bitumens, etc.) are in the composition → they refine to minerals
        // - If R8+ ores without R4 → no regular minerals obtained
        // The composition defines what you get, so we calculate refined value for each ore type
        // Note: Individual miner taxation can still use raw ore prices (configured separately)

        // Get estimated chunk size from config (default: 150,000 m³)
        $chunkSize = config('mining-manager.moon.estimated_chunk_size', 150000);

        $totalValue = 0;

        foreach ($oreComposition as $oreName => $oreData) {
            // Handle both old and new structure
            if (is_array($oreData) && isset($oreData['type_id'])) {
                $typeId = $oreData['type_id'];
                $percentage = $oreData['percentage'] ?? 0;

                // PRIORITY 1: Use actual quantity from notification if available (most accurate)
                if (isset($oreData['quantity']) && $oreData['quantity'] > 0) {
                    $quantity = $oreData['quantity'];
                    Log::debug("Mining Manager: Using actual quantity from notification for type_id {$typeId}: {$quantity} units");
                }
                // PRIORITY 2: Calculate from percentage (fallback for old data)
                elseif ($percentage > 0) {
                    $oreVolume = $this->getOreVolume($typeId);
                    if ($oreVolume > 0) {
                        $quantity = ($percentage / 100) * ($chunkSize / $oreVolume);
                        Log::debug("Mining Manager: Calculated quantity from percentage for type_id {$typeId}: {$quantity} units (estimated)");
                    } else {
                        $quantity = 0;
                    }
                } else {
                    $quantity = 0;
                }
            } else {
                // Old structure: $oreName is actually typeId, $oreData is quantity
                $typeId = is_numeric($oreName) ? (int) $oreName : null;
                $quantity = is_numeric($oreData) ? $oreData : 0;
            }

            if (!$typeId) {
                Log::warning("Mining Manager: Invalid type_id for ore {$oreName}");
                continue;
            }

            if ($quantity <= 0) {
                continue;
            }

            // ALWAYS calculate value based on refined materials for moon extractions
            // Each ore type in the composition will refine to its respective materials
            $value = $this->calculateRefinedValue($typeId, $quantity);

            // Fallback to raw ore price if refined value calculation fails
            if ($value <= 0) {
                $price = $this->getOrePrice($typeId, $regionId, $priceType);
                if ($price !== null) {
                    $value = $quantity * $price;
                }
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
            // Yields are per 100-unit batch, so divide quantity by 100 first
            // Yield = (ore quantity / 100) × yield per batch × refining efficiency
            $actualYield = ($quantity / 100) * $yieldPerBatch * $refiningEfficiency;

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
        // Values from invTypeMaterials (100% perfect refining - efficiency applied separately)
        // ============================================================================

        // ---------- BITUMENS FAMILY (produces Hydrocarbons 16633) ----------

        // Base Bitumens (45492)
        $moonOreYields[45492] = [35 => 6000, 36 => 400, 16633 => 65];
        // Brimful Bitumens +15% (46284)
        $moonOreYields[46284] = [35 => 6900, 36 => 460, 16633 => 75];
        // Glistening Bitumens +100% (46285)
        $moonOreYields[46285] = [35 => 12000, 36 => 800, 16633 => 130];

        // ---------- COESITE FAMILY (produces Silicates 16636) ----------

        // Base Coesite (45493)
        $moonOreYields[45493] = [35 => 2000, 36 => 400, 16636 => 65];
        // Brimful Coesite +15% (46286)
        $moonOreYields[46286] = [35 => 2300, 36 => 460, 16636 => 75];
        // Glistening Coesite +100% (46287)
        $moonOreYields[46287] = [35 => 4000, 36 => 800, 16636 => 130];

        // ---------- SYLVITE FAMILY (produces Evaporite Deposits 16635) ----------

        // Base Sylvite (45491)
        $moonOreYields[45491] = [35 => 4000, 36 => 400, 16635 => 65];
        // Brimful Sylvite +15% (46282)
        $moonOreYields[46282] = [35 => 4600, 36 => 460, 16635 => 75];
        // Glistening Sylvite +100% (46283)
        $moonOreYields[46283] = [35 => 8000, 36 => 800, 16635 => 130];

        // ---------- ZEOLITES FAMILY (produces Atmospheric Gases 16634) ----------

        // Base Zeolites (45490)
        $moonOreYields[45490] = [35 => 8000, 36 => 400, 16634 => 65];
        // Brimful Zeolites +15% (46280)
        $moonOreYields[46280] = [35 => 9200, 36 => 460, 16634 => 75];
        // Glistening Zeolites +100% (46281)
        $moonOreYields[46281] = [35 => 16000, 36 => 800, 16634 => 130];
        
        // ============================================================================
        // R8 (COMMON) MOON ORES
        // R8 ores give: ONLY R8 Moon Materials (NO regular minerals)
        // ============================================================================

        // ---------- COBALTITE FAMILY (produces Cobalt 16640) ----------

        // Base Cobaltite (45494)
        $moonOreYields[45494] = [16640 => 40];
        // Copious Cobaltite +15% (46288)
        $moonOreYields[46288] = [16640 => 46];
        // Twinkling Cobaltite +100% (46289)
        $moonOreYields[46289] = [16640 => 80];

        // ---------- EUXENITE FAMILY (produces Scandium 16639) ----------

        // Base Euxenite (45495)
        $moonOreYields[45495] = [16639 => 40];
        // Copious Euxenite +15% (46290)
        $moonOreYields[46290] = [16639 => 46];
        // Twinkling Euxenite +100% (46291)
        $moonOreYields[46291] = [16639 => 80];

        // ---------- SCHEELITE FAMILY (produces Tungsten 16637) ----------

        // Base Scheelite (45497)
        $moonOreYields[45497] = [16637 => 40];
        // Copious Scheelite +15% (46294)
        $moonOreYields[46294] = [16637 => 46];
        // Twinkling Scheelite +100% (46295)
        $moonOreYields[46295] = [16637 => 80];

        // ---------- TITANITE FAMILY (produces Titanium 16638) ----------

        // Base Titanite (45496)
        $moonOreYields[45496] = [16638 => 40];
        // Copious Titanite +15% (46292)
        $moonOreYields[46292] = [16638 => 46];
        // Twinkling Titanite +100% (46293)
        $moonOreYields[46293] = [16638 => 80];
        
        // ============================================================================
        // R16 (UNCOMMON) MOON ORES
        // R16 ores give: R16 Moon Material + small amount of R4 (NO regular minerals)
        // ============================================================================

        // ---------- CHROMITE FAMILY (produces Chromium 16641 + Hydrocarbons 16633) ----------

        // Base Chromite (45501)
        $moonOreYields[45501] = [16641 => 40, 16633 => 10];
        // Lavish Chromite +15% (46302)
        $moonOreYields[46302] = [16641 => 46, 16633 => 12];
        // Shimmering Chromite +100% (46303)
        $moonOreYields[46303] = [16641 => 80, 16633 => 20];

        // ---------- OTAVITE FAMILY (produces Cadmium 16643 + Atmospheric Gases 16634) ----------

        // Base Otavite (45498)
        $moonOreYields[45498] = [16643 => 40, 16634 => 10];
        // Lavish Otavite +15% (46296)
        $moonOreYields[46296] = [16643 => 46, 16634 => 12];
        // Shimmering Otavite +100% (46297)
        $moonOreYields[46297] = [16643 => 80, 16634 => 20];

        // ---------- SPERRYLITE FAMILY (produces Platinum 16644 + Evaporite Deposits 16635) ----------

        // Base Sperrylite (45499)
        $moonOreYields[45499] = [16644 => 40, 16635 => 10];
        // Lavish Sperrylite +15% (46298)
        $moonOreYields[46298] = [16644 => 46, 16635 => 12];
        // Shimmering Sperrylite +100% (46299)
        $moonOreYields[46299] = [16644 => 80, 16635 => 20];

        // ---------- VANADINITE FAMILY (produces Vanadium 16642 + Silicates 16636) ----------

        // Base Vanadinite (45500)
        $moonOreYields[45500] = [16642 => 40, 16636 => 10];
        // Lavish Vanadinite +15% (46300)
        $moonOreYields[46300] = [16642 => 46, 16636 => 12];
        // Shimmering Vanadinite +100% (46301)
        $moonOreYields[46301] = [16642 => 80, 16636 => 20];
        
        // ============================================================================
        // R32 (RARE) MOON ORES
        // R32 ores give: R32 + R8 + R4 Moon Materials
        // ============================================================================
        
        // ---------- CARNOTITE FAMILY (produces Technetium 16649 + Cobalt 16640 + Atmospheric Gases 16634) ----------

        // Base Carnotite (45502)
        $moonOreYields[45502] = [16649 => 50, 16634 => 15, 16640 => 10];
        // Replete Carnotite +15% (46304)
        $moonOreYields[46304] = [16649 => 58, 16634 => 17, 16640 => 12];
        // Glowing Carnotite +100% (46305)
        $moonOreYields[46305] = [16649 => 100, 16634 => 30, 16640 => 20];
        
        // ---------- CINNABAR FAMILY (produces Mercury 16646 + Tungsten 16637 + Evaporite Deposits 16635) ----------

        // Base Cinnabar (45506)
        $moonOreYields[45506] = [16646 => 50, 16637 => 10, 16635 => 15];
        // Replete Cinnabar +15% (46310)
        $moonOreYields[46310] = [16646 => 58, 16637 => 12, 16635 => 17];
        // Glowing Cinnabar +100% (46311)
        $moonOreYields[46311] = [16646 => 100, 16637 => 20, 16635 => 30];
        
        // ---------- POLLUCITE FAMILY (produces Caesium 16647 + Scandium 16639 + Hydrocarbons 16633) ----------

        // Base Pollucite (45504)
        $moonOreYields[45504] = [16647 => 50, 16639 => 10, 16633 => 15];
        // Replete Pollucite +15% (46308)
        $moonOreYields[46308] = [16647 => 58, 16639 => 12, 16633 => 17];
        // Glowing Pollucite +100% (46309)
        $moonOreYields[46309] = [16647 => 100, 16639 => 20, 16633 => 30];
        
        // ---------- ZIRCON FAMILY (produces Hafnium 16648 + Titanium 16638 + Silicates 16636) ----------

        // Base Zircon (45503)
        $moonOreYields[45503] = [16648 => 50, 16638 => 10, 16636 => 15];
        // Replete Zircon +15% (46306)
        $moonOreYields[46306] = [16648 => 58, 16638 => 12, 16636 => 17];
        // Glowing Zircon +100% (46307)
        $moonOreYields[46307] = [16648 => 100, 16638 => 20, 16636 => 30];
        
        // ============================================================================
        // R64 (EXCEPTIONAL) MOON ORES
        // R64 ores give: R64 + R16 + R8 + R4 Moon Materials
        // ============================================================================
        
        // ---------- XENOTIME FAMILY (produces Dysprosium 16650 + Vanadium 16642 + Cobalt 16640 + Atmospheric Gases 16634) ----------

        // Base Xenotime (45510)
        $moonOreYields[45510] = [16650 => 22, 16642 => 10, 16640 => 20, 16634 => 20];
        // Bountiful Xenotime +15% (46312)
        $moonOreYields[46312] = [16650 => 25, 16642 => 12, 16640 => 23, 16634 => 23];
        // Shining Xenotime +100% (46313)
        $moonOreYields[46313] = [16650 => 44, 16642 => 20, 16640 => 40, 16634 => 40];
        
        // ---------- MONAZITE FAMILY (produces Neodymium 16651 + Chromium 16641 + Tungsten 16637 + Evaporite Deposits 16635) ----------

        // Base Monazite (45511)
        $moonOreYields[45511] = [16651 => 22, 16641 => 10, 16637 => 20, 16635 => 20];
        // Bountiful Monazite +15% (46314)
        $moonOreYields[46314] = [16651 => 25, 16641 => 12, 16637 => 23, 16635 => 23];
        // Shining Monazite +100% (46315)
        $moonOreYields[46315] = [16651 => 44, 16641 => 20, 16637 => 40, 16635 => 40];
        
        // ---------- LOPARITE FAMILY (produces Promethium 16652 + Platinum 16644 + Scandium 16639 + Hydrocarbons 16633) ----------

        // Base Loparite (45512)
        $moonOreYields[45512] = [16652 => 22, 16644 => 10, 16639 => 20, 16633 => 20];
        // Bountiful Loparite +15% (46316)
        $moonOreYields[46316] = [16652 => 25, 16644 => 12, 16639 => 23, 16633 => 23];
        // Shining Loparite +100% (46317)
        $moonOreYields[46317] = [16652 => 44, 16644 => 20, 16639 => 40, 16633 => 40];
        
        // ---------- YTTERBITE FAMILY (produces Thulium 16653 + Cadmium 16643 + Titanium 16638 + Silicates 16636) ----------

        // Base Ytterbite (45513)
        $moonOreYields[45513] = [16653 => 22, 16643 => 10, 16638 => 20, 16636 => 20];
        // Bountiful Ytterbite +15% (46318)
        $moonOreYields[46318] = [16653 => 25, 16643 => 12, 16638 => 23, 16636 => 23];
        // Shining Ytterbite +100% (46319)
        $moonOreYields[46319] = [16653 => 44, 16643 => 20, 16638 => 40, 16636 => 40];
        
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

        // Ensure ore_composition is an array
        $oreComposition = is_string($extraction->ore_composition)
            ? json_decode($extraction->ore_composition, true)
            : $extraction->ore_composition;

        if (!is_array($oreComposition)) {
            return [];
        }

        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');
        $chunkSize = config('mining-manager.moon.estimated_chunk_size', 150000);

        $breakdown = [];
        $totalValue = 0;

        foreach ($oreComposition as $oreName => $oreData) {
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

        // Ensure ore_composition is an array
        $oreComposition = is_string($extraction->ore_composition)
            ? json_decode($extraction->ore_composition, true)
            : $extraction->ore_composition;

        if (!is_array($oreComposition)) {
            return null;
        }

        $chunkSize = config('mining-manager.moon.estimated_chunk_size', 150000);
        $totalVolume = 0;

        foreach ($oreComposition as $oreName => $oreData) {
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

        // Ensure ore_composition is an array
        $oreComposition = is_string($extraction->ore_composition)
            ? json_decode($extraction->ore_composition, true)
            : $extraction->ore_composition;

        if (!is_array($oreComposition)) {
            return [
                'total_volume' => 0,
                'estimated_hours' => 0,
                'estimated_hours_per_miner' => 0,
            ];
        }

        $chunkSize = config('mining-manager.moon.estimated_chunk_size', 150000);
        $totalVolume = 0;

        foreach ($oreComposition as $oreName => $oreData) {
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
