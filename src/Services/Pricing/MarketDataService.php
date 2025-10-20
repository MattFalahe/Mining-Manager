<?php

namespace MiningManager\Services\Pricing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\Setting;
use Carbon\Carbon;
use Exception;

/**
 * Service for caching and managing market data
 * 
 * Handles:
 * - Price caching to reduce API calls
 * - Historical price tracking
 * - Price trend analysis
 * - Bulk price operations
 */
class MarketDataService
{
    /**
     * Cache duration in minutes
     */
    const CACHE_DURATION = 60; // 1 hour default
    const CACHE_PREFIX = 'mining_manager_prices_';
    const HISTORICAL_CACHE_PREFIX = 'mining_manager_historical_';

    /**
     * Price provider service
     */
    protected PriceProviderService $priceProvider;

    /**
     * Constructor
     */
    public function __construct(PriceProviderService $priceProvider)
    {
        $this->priceProvider = $priceProvider;
    }

    /**
     * Get cached prices for type IDs
     *
     * @param array $typeIds
     * @param bool $forceRefresh Force refresh from provider
     * @return array
     */
    public function getCachedPrices(array $typeIds, bool $forceRefresh = false): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $cacheKey = $this->getPriceCacheKey($typeIds);
        $cacheDuration = $this->getCacheDuration();

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, $cacheDuration, function () use ($typeIds) {
            Log::info('Cache miss - fetching fresh prices', ['type_count' => count($typeIds)]);
            return $this->priceProvider->getPrices($typeIds);
        });
    }

    /**
     * Get a single cached price
     *
     * @param int $typeId
     * @param bool $forceRefresh
     * @return float|null
     */
    public function getCachedPrice(int $typeId, bool $forceRefresh = false): ?float
    {
        $prices = $this->getCachedPrices([$typeId], $forceRefresh);
        return $prices[$typeId] ?? null;
    }

    /**
     * Store historical price data
     *
     * @param array $prices Associative array [type_id => price]
     * @param Carbon|null $date
     * @return void
     */
    public function storeHistoricalPrices(array $prices, ?Carbon $date = null): void
    {
        $date = $date ?? Carbon::now();
        
        try {
            foreach ($prices as $typeId => $price) {
                DB::table('mining_historical_prices')->updateOrInsert(
                    [
                        'type_id' => $typeId,
                        'date' => $date->format('Y-m-d')
                    ],
                    [
                        'price' => $price,
                        'updated_at' => Carbon::now()
                    ]
                );
            }

            Log::info('Stored historical prices', [
                'count' => count($prices),
                'date' => $date->format('Y-m-d')
            ]);
        } catch (Exception $e) {
            Log::error('Failed to store historical prices', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get historical prices for a type
     *
     * @param int $typeId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getHistoricalPrices(int $typeId, Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = $this->getHistoricalCacheKey($typeId, $startDate, $endDate);

        return Cache::remember($cacheKey, 1440, function () use ($typeId, $startDate, $endDate) {
            return DB::table('mining_historical_prices')
                ->where('type_id', $typeId)
                ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->orderBy('date')
                ->get()
                ->map(function ($record) {
                    return [
                        'date' => $record->date,
                        'price' => (float) $record->price
                    ];
                })
                ->toArray();
        });
    }

    /**
     * Get price trend for a type
     *
     * @param int $typeId
     * @param int $days Number of days to analyze
     * @return array
     */
    public function getPriceTrend(int $typeId, int $days = 30): array
    {
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays($days);

        $historicalPrices = $this->getHistoricalPrices($typeId, $startDate, $endDate);

        if (empty($historicalPrices)) {
            return [
                'trend' => 'stable',
                'change_percentage' => 0,
                'current_price' => $this->getCachedPrice($typeId),
                'average_price' => null,
                'data_points' => 0
            ];
        }

        $prices = array_column($historicalPrices, 'price');
        $firstPrice = reset($prices);
        $lastPrice = end($prices);
        $averagePrice = array_sum($prices) / count($prices);

        $changePercentage = (($lastPrice - $firstPrice) / $firstPrice) * 100;

        $trend = 'stable';
        if ($changePercentage > 5) {
            $trend = 'increasing';
        } elseif ($changePercentage < -5) {
            $trend = 'decreasing';
        }

        return [
            'trend' => $trend,
            'change_percentage' => round($changePercentage, 2),
            'current_price' => $lastPrice,
            'average_price' => round($averagePrice, 2),
            'min_price' => min($prices),
            'max_price' => max($prices),
            'data_points' => count($historicalPrices),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d')
        ];
    }

    /**
     * Refresh prices for all configured ores
     *
     * @return array
     */
    public function refreshAllOrePrices(): array
    {
        $oreTypeIds = $this->getAllOreTypeIds();
        
        Log::info('Refreshing all ore prices', ['ore_count' => count($oreTypeIds)]);

        $prices = $this->getCachedPrices($oreTypeIds, true);
        
        // Store historical prices
        $this->storeHistoricalPrices($prices);

        return $prices;
    }

    /**
     * Get bulk prices with automatic batching
     *
     * @param array $typeIds
     * @param int $batchSize
     * @return array
     */
    public function getBulkPrices(array $typeIds, int $batchSize = 100): array
    {
        $batches = array_chunk($typeIds, $batchSize);
        $allPrices = [];

        foreach ($batches as $batch) {
            $batchPrices = $this->getCachedPrices($batch);
            $allPrices = array_merge($allPrices, $batchPrices);
            
            // Small delay between batches to avoid rate limiting
            if (count($batches) > 1) {
                usleep(100000); // 100ms
            }
        }

        return $allPrices;
    }

    /**
     * Calculate total value for items
     *
     * @param array $items Array of ['type_id' => quantity]
     * @param bool $useRefinedValue Calculate value of refined materials
     * @return float
     */
    public function calculateTotalValue(array $items, bool $useRefinedValue = false): float
    {
        $typeIds = array_keys($items);
        $prices = $this->getCachedPrices($typeIds);

        $totalValue = 0;

        foreach ($items as $typeId => $quantity) {
            if (!isset($prices[$typeId])) {
                Log::warning('Price not found for type', ['type_id' => $typeId]);
                continue;
            }

            if ($useRefinedValue) {
                // TODO: Implement refined material calculation
                // This would require getting refined materials for the ore
                // and calculating their total value
                $totalValue += $prices[$typeId] * $quantity;
            } else {
                $totalValue += $prices[$typeId] * $quantity;
            }
        }

        return $totalValue;
    }

    /**
     * Get price statistics for multiple types
     *
     * @param array $typeIds
     * @param int $days
     * @return array
     */
    public function getPriceStatistics(array $typeIds, int $days = 30): array
    {
        $statistics = [];

        foreach ($typeIds as $typeId) {
            $statistics[$typeId] = $this->getPriceTrend($typeId, $days);
        }

        return $statistics;
    }

    /**
     * Clear all price caches
     *
     * @return void
     */
    public function clearAllCaches(): void
    {
        $pattern = self::CACHE_PREFIX . '*';
        Cache::flush(); // Or use Cache::tags if using Redis/Memcached
        
        Log::info('Cleared all price caches');
    }

    /**
     * Clear cache for specific types
     *
     * @param array $typeIds
     * @return void
     */
    public function clearCacheForTypes(array $typeIds): void
    {
        $cacheKey = $this->getPriceCacheKey($typeIds);
        Cache::forget($cacheKey);
        
        Log::info('Cleared price cache for types', ['type_ids' => $typeIds]);
    }

    /**
     * Get cache status
     *
     * @return array
     */
    public function getCacheStatus(): array
    {
        $oreTypeIds = $this->getAllOreTypeIds();
        $cacheKey = $this->getPriceCacheKey($oreTypeIds);
        
        $isCached = Cache::has($cacheKey);
        $lastUpdate = null;

        if ($isCached) {
            // Try to get cache timestamp
            $lastUpdate = DB::table('mining_cache_metadata')
                ->where('cache_key', $cacheKey)
                ->value('updated_at');
        }

        return [
            'cached' => $isCached,
            'last_update' => $lastUpdate,
            'cache_duration' => $this->getCacheDuration(),
            'provider' => Setting::get('price_provider', 'esi'),
            'ore_count' => count($oreTypeIds)
        ];
    }

    /**
     * Warm up cache for common operations
     *
     * @return void
     */
    public function warmUpCache(): void
    {
        Log::info('Warming up price cache');

        $oreTypeIds = $this->getAllOreTypeIds();
        $this->getCachedPrices($oreTypeIds, true);
        
        Log::info('Price cache warmed up', ['ore_count' => count($oreTypeIds)]);
    }

    /**
     * Get all ore type IDs from configuration
     *
     * @return array
     */
    protected function getAllOreTypeIds(): array
    {
        // This could be stored in config or database
        // For now, we'll get it from settings or use a default list
        $customOres = Setting::get('tracked_ore_types', []);
        
        if (!empty($customOres)) {
            return $customOres;
        }

        // Default ore list (common ores and their compressed variants)
        return [
            // Veldspar
            1230, 17470, 17471,
            // Scordite
            1228, 17463, 17464,
            // Pyroxeres
            1224, 17459, 17460,
            // Plagioclase
            18, 17455, 17456,
            // Omber
            1227, 17867, 17868,
            // Kernite
            20, 17452, 17453,
            // Jaspet
            1226, 17448, 17449,
            // Hemorphite
            1231, 17444, 17445,
            // Hedbergite
            21, 17440, 17441,
            // Gneiss
            1229, 17865, 17866,
            // Dark Ochre
            1232, 17436, 17437,
            // Spodumain
            19, 17466, 17467,
            // Crokite
            1225, 17432, 17433,
            // Bistot
            1223, 17428, 17429,
            // Arkonor
            22, 17425, 17426,
            // Mercoxit
            11396,
            // Ice products (common)
            16262, 16263, 16264, 16265, 16266, 16267, 16268, 16269, 16270,
            // Moon ores (R4, R8, R16, R32, R64)
            45490, 45491, 45492, 45493, 45494, 45495, 45496, 45497, 45498, 45499,
            45500, 45501, 45502, 45503, 45504, 45506, 45510, 45511, 45512, 45513
        ];
    }

    /**
     * Generate cache key for prices
     *
     * @param array $typeIds
     * @return string
     */
    protected function getPriceCacheKey(array $typeIds): string
    {
        sort($typeIds);
        $provider = Setting::get('price_provider', 'esi');
        return self::CACHE_PREFIX . $provider . '_' . md5(implode(',', $typeIds));
    }

    /**
     * Generate cache key for historical prices
     *
     * @param int $typeId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return string
     */
    protected function getHistoricalCacheKey(int $typeId, Carbon $startDate, Carbon $endDate): string
    {
        return self::HISTORICAL_CACHE_PREFIX . $typeId . '_' . 
               $startDate->format('Ymd') . '_' . 
               $endDate->format('Ymd');
    }

    /**
     * Get cache duration in minutes
     *
     * @return int
     */
    protected function getCacheDuration(): int
    {
        return Setting::get('price_cache_duration', self::CACHE_DURATION);
    }

    /**
     * Export price data to CSV
     *
     * @param array $typeIds
     * @param string $filepath
     * @return bool
     */
    public function exportPricesToCsv(array $typeIds, string $filepath): bool
    {
        try {
            $prices = $this->getCachedPrices($typeIds);
            
            $file = fopen($filepath, 'w');
            
            // Write header
            fputcsv($file, ['Type ID', 'Price', 'Last Updated']);
            
            // Write data
            foreach ($prices as $typeId => $price) {
                fputcsv($file, [
                    $typeId,
                    $price,
                    Carbon::now()->toDateTimeString()
                ]);
            }
            
            fclose($file);
            
            Log::info('Exported prices to CSV', ['filepath' => $filepath]);
            
            return true;
        } catch (Exception $e) {
            Log::error('Failed to export prices to CSV', [
                'filepath' => $filepath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Import prices from CSV
     *
     * @param string $filepath
     * @return array
     */
    public function importPricesFromCsv(string $filepath): array
    {
        $prices = [];
        
        try {
            $file = fopen($filepath, 'r');
            
            // Skip header
            fgetcsv($file);
            
            while (($data = fgetcsv($file)) !== false) {
                $typeId = (int) $data[0];
                $price = (float) $data[1];
                $prices[$typeId] = $price;
            }
            
            fclose($file);
            
            Log::info('Imported prices from CSV', [
                'filepath' => $filepath,
                'count' => count($prices)
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to import prices from CSV', [
                'filepath' => $filepath,
                'error' => $e->getMessage()
            ]);
        }
        
        return $prices;
    }
}
