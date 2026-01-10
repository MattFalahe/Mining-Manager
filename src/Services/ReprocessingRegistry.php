<?php

namespace MiningManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Reprocessing Registry
 * 
 * Handles ore-to-mineral mapping by querying SeAT's invTypeMaterials table.
 * This provides dynamic reprocessing data without hardcoded mappings.
 * 
 * All mineral compositions come directly from the EVE SDE via SeAT's database.
 */
class ReprocessingRegistry
{
    /**
     * Cache duration for reprocessing data (in seconds)
     * Default: 24 hours (reprocessing data rarely changes)
     */
    const CACHE_DURATION = 86400;

    /**
     * Get mineral composition for an ore type
     * 
     * Returns array of [mineralTypeId => quantity per 100 units]
     * 
     * @param int $oreTypeId The ore type ID
     * @return array|null Array of minerals or null if no data
     */
    public static function getMinerals(int $oreTypeId): ?array
    {
        $cacheKey = "reprocessing_minerals_{$oreTypeId}";
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function() use ($oreTypeId) {
            try {
                $materials = DB::table('invTypeMaterials')
                    ->where('typeID', $oreTypeId)
                    ->get();
                
                if ($materials->isEmpty()) {
                    return null;
                }
                
                $minerals = [];
                foreach ($materials as $material) {
                    $minerals[$material->materialTypeID] = $material->quantity;
                }
                
                return $minerals;
            } catch (\Exception $e) {
                Log::error("ReprocessingRegistry: Failed to get minerals for type_id {$oreTypeId}", [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    /**
     * Get detailed mineral composition with names
     * 
     * @param int $oreTypeId The ore type ID
     * @return array|null Array with mineral details
     */
    public static function getMineralsWithDetails(int $oreTypeId): ?array
    {
        $cacheKey = "reprocessing_minerals_detailed_{$oreTypeId}";
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function() use ($oreTypeId) {
            try {
                $materials = DB::table('invTypeMaterials as tm')
                    ->join('invTypes as t', 'tm.materialTypeID', '=', 't.typeID')
                    ->where('tm.typeID', $oreTypeId)
                    ->select('tm.materialTypeID as type_id', 't.typeName as name', 'tm.quantity')
                    ->get();
                
                if ($materials->isEmpty()) {
                    return null;
                }
                
                $minerals = [];
                foreach ($materials as $material) {
                    $minerals[] = [
                        'type_id' => $material->type_id,
                        'name' => $material->name,
                        'quantity' => $material->quantity,
                    ];
                }
                
                return $minerals;
            } catch (\Exception $e) {
                Log::error("ReprocessingRegistry: Failed to get detailed minerals for type_id {$oreTypeId}", [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    /**
     * Calculate refined mineral value for ore
     * 
     * @param int $oreTypeId The ore type ID
     * @param int $quantity Quantity of ore
     * @param float $refiningEfficiency Refining efficiency (0.0 to 1.0)
     * @param array $mineralPrices Array of [mineralTypeId => price]
     * @return float Total value in ISK
     */
    public static function calculateRefinedValue(
        int $oreTypeId, 
        int $quantity, 
        float $refiningEfficiency,
        array $mineralPrices
    ): float {
        $minerals = self::getMinerals($oreTypeId);
        
        if ($minerals === null) {
            return 0;
        }
        
        $totalValue = 0;
        
        foreach ($minerals as $mineralTypeId => $baseYield) {
            // Apply refining efficiency
            $actualYield = $baseYield * $refiningEfficiency;
            
            // Get price for this mineral
            $price = $mineralPrices[$mineralTypeId] ?? 0;
            
            // Add to total value
            $totalValue += $actualYield * $price;
        }
        
        // Scale from per-portionSize to actual quantity
        $portionSize = DB::table('invTypes')->where('typeID', $oreTypeId)->value('portionSize') ?? 100;
        return ($quantity / $portionSize) * $totalValue;
    }

    /**
     * Check if an ore type has reprocessing data
     * 
     * @param int $oreTypeId The ore type ID
     * @return bool True if reprocessing data exists
     */
    public static function hasReprocessingData(int $oreTypeId): bool
    {
        return self::getMinerals($oreTypeId) !== null;
    }

    /**
     * Get primary mineral from ore (the highest quantity mineral)
     * 
     * @param int $oreTypeId The ore type ID
     * @return array|null ['type_id' => int, 'quantity' => int] or null
     */
    public static function getPrimaryMineral(int $oreTypeId): ?array
    {
        $minerals = self::getMinerals($oreTypeId);
        
        if ($minerals === null || empty($minerals)) {
            return null;
        }
        
        // Find mineral with highest quantity
        $maxQuantity = 0;
        $primaryMineralId = null;
        
        foreach ($minerals as $mineralId => $quantity) {
            if ($quantity > $maxQuantity) {
                $maxQuantity = $quantity;
                $primaryMineralId = $mineralId;
            }
        }
        
        return [
            'type_id' => $primaryMineralId,
            'quantity' => $maxQuantity,
        ];
    }

    /**
     * Clear cached reprocessing data
     * 
     * @param int|null $oreTypeId Specific ore type or null for all
     * @return void
     */
    public static function clearCache(?int $oreTypeId = null): void
    {
        if ($oreTypeId !== null) {
            Cache::forget("reprocessing_minerals_{$oreTypeId}");
            Cache::forget("reprocessing_minerals_detailed_{$oreTypeId}");
        } else {
            // Clear all reprocessing cache
            // Note: This is a simple approach; for production you might want to track all cached keys
            Log::info('ReprocessingRegistry: Cache clear requested (use specific type_id for targeted clear)');
        }
    }

    /**
     * Batch get minerals for multiple ore types (more efficient)
     * 
     * @param array $oreTypeIds Array of ore type IDs
     * @return array [oreTypeId => [mineralId => quantity]]
     */
    public static function getBatchMinerals(array $oreTypeIds): array
    {
        if (empty($oreTypeIds)) {
            return [];
        }
        
        $cacheKey = "reprocessing_batch_" . md5(implode(',', $oreTypeIds));
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function() use ($oreTypeIds) {
            try {
                $materials = DB::table('invTypeMaterials')
                    ->whereIn('typeID', $oreTypeIds)
                    ->get();
                
                $result = [];
                
                foreach ($materials as $material) {
                    if (!isset($result[$material->typeID])) {
                        $result[$material->typeID] = [];
                    }
                    $result[$material->typeID][$material->materialTypeID] = $material->quantity;
                }
                
                // Ensure all requested ore types are in result (even if empty)
                foreach ($oreTypeIds as $oreTypeId) {
                    if (!isset($result[$oreTypeId])) {
                        $result[$oreTypeId] = [];
                    }
                }
                
                return $result;
            } catch (\Exception $e) {
                Log::error("ReprocessingRegistry: Failed batch minerals lookup", [
                    'error' => $e->getMessage(),
                    'ore_count' => count($oreTypeIds)
                ]);
                return [];
            }
        });
    }

    /**
     * Get all ores that produce a specific mineral
     * 
     * @param int $mineralTypeId The mineral type ID
     * @return array Array of ore type IDs
     */
    public static function getOresProducingMineral(int $mineralTypeId): array
    {
        $cacheKey = "reprocessing_ores_for_mineral_{$mineralTypeId}";
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function() use ($mineralTypeId) {
            try {
                $ores = DB::table('invTypeMaterials')
                    ->where('materialTypeID', $mineralTypeId)
                    ->pluck('typeID')
                    ->toArray();
                
                return $ores;
            } catch (\Exception $e) {
                Log::error("ReprocessingRegistry: Failed to get ores for mineral {$mineralTypeId}", [
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        });
    }

    /**
     * Get ore type name from database
     * 
     * @param int $oreTypeId The ore type ID
     * @return string Ore name or "Unknown Ore"
     */
    public static function getOreName(int $oreTypeId): string
    {
        $cacheKey = "ore_name_{$oreTypeId}";
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function() use ($oreTypeId) {
            try {
                $name = DB::table('invTypes')
                    ->where('typeID', $oreTypeId)
                    ->value('typeName');
                
                return $name ?? "Unknown Ore ({$oreTypeId})";
            } catch (\Exception $e) {
                Log::error("ReprocessingRegistry: Failed to get ore name for {$oreTypeId}", [
                    'error' => $e->getMessage()
                ]);
                return "Unknown Ore ({$oreTypeId})";
            }
        });
    }
}
