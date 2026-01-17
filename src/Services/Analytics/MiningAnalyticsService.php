<?php

namespace MiningManager\Services\Analytics;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningPriceCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Mining Analytics Service for SeAT v5.x
 * 
 * This service provides analytics and statistics for mining operations.
 * Uses SeAT v5.x universe tables (solar_systems, invTypes).
 */
class MiningAnalyticsService
{
    /**
     * Get total volume mined in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return int
     */
    public function getTotalVolume(Carbon $startDate, Carbon $endDate): int
    {
        $cacheKey = "mining-analytics:total-volume:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate) {
            return MiningLedger::whereBetween('date', [$startDate, $endDate])
                ->sum('quantity');
        });
    }

    /**
     * Get total ISK value of ore mined in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return float
     */
    public function getTotalValue(Carbon $startDate, Carbon $endDate): float
    {
        $cacheKey = "mining-analytics:total-value:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate) {
            $regionId = config('mining-manager.pricing.default_region_id', 10000002);
            $priceType = config('mining-manager.pricing.price_type', 'sell');
            
            $priceColumn = match ($priceType) {
                'buy' => 'buy_price',
                'average' => 'average_price',
                default => 'sell_price',
            };

            return MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->join('mining_price_cache', function ($join) use ($regionId) {
                    $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                        ->where('mining_price_cache.region_id', '=', $regionId);
                })
                ->select(DB::raw("SUM(mining_ledger.quantity * mining_price_cache.{$priceColumn}) as total_value"))
                ->value('total_value') ?? 0;
        });
    }

    /**
     * Get count of unique miners in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return int
     */
    public function getUniqueMinerCount(Carbon $startDate, Carbon $endDate): int
    {
        $cacheKey = "mining-analytics:unique-miners:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate) {
            return MiningLedger::whereBetween('date', [$startDate, $endDate])
                ->distinct('character_id')
                ->count('character_id');
        });
    }

    /**
     * Get top miners by quantity in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getTopMiners(Carbon $startDate, Carbon $endDate, int $limit = 10)
    {
        $cacheKey = "mining-analytics:top-miners:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}:{$limit}";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $limit) {
            $regionId = config('mining-manager.pricing.default_region_id', 10000002);
            $priceType = config('mining-manager.pricing.price_type', 'sell');
            
            $priceColumn = match ($priceType) {
                'buy' => 'buy_price',
                'average' => 'average_price',
                default => 'sell_price',
            };

            return MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->join('character_infos', 'mining_ledger.character_id', '=', 'character_infos.character_id')
                ->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                    $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                        ->where('mining_price_cache.region_id', '=', $regionId);
                })
                ->select(
                    'mining_ledger.character_id',
                    'character_infos.name',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw("SUM(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as total_value")
                )
                ->groupBy('mining_ledger.character_id', 'character_infos.name')
                ->orderByDesc('total_quantity')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Get ore type breakdown in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getOreBreakdown(Carbon $startDate, Carbon $endDate)
    {
        $cacheKey = "mining-analytics:ore-breakdown:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate) {
            $regionId = config('mining-manager.pricing.default_region_id', 10000002);
            $priceType = config('mining-manager.pricing.price_type', 'sell');
            
            $priceColumn = match ($priceType) {
                'buy' => 'buy_price',
                'average' => 'average_price',
                default => 'sell_price',
            };

            // Use InvType model with eager loading (this one works correctly)
            $results = MiningLedger::with('type')
                ->whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                    $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                        ->where('mining_price_cache.region_id', '=', $regionId);
                })
                ->select(
                    'mining_ledger.type_id',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw("SUM(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as total_value")
                )
                ->groupBy('mining_ledger.type_id')
                ->orderByDesc('total_quantity')
                ->get();
            
            // Add ore names from the type relationship
            return $results->map(function($item) {
                $item->ore_name = $item->type_name ?? 'Unknown';
                return $item;
            });
        });
    }

    /**
     * Get solar system breakdown in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getSystemBreakdown(Carbon $startDate, Carbon $endDate)
    {
        $cacheKey = "mining-analytics:system-breakdown:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate) {
            // Use eager loading with Sde\SolarSystem relationship (now works correctly with system_id)
            $results = MiningLedger::with('solarSystem')
                ->whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->select(
                    'mining_ledger.solar_system_id',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners')
                )
                ->groupBy('mining_ledger.solar_system_id')
                ->orderByDesc('total_quantity')
                ->get();
            
            // Add system names from the relationship
            return $results->map(function($item) {
                $item->system_name = $item->system_name ?? 'Unknown';
                return $item;
            });
        });
    }

    /**
     * Get daily mining trends in date range.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getDailyTrends(Carbon $startDate, Carbon $endDate)
    {
        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');
        
        $priceColumn = match ($priceType) {
            'buy' => 'buy_price',
            'average' => 'average_price',
            default => 'sell_price',
        };

        return MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
            ->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                    ->where('mining_price_cache.region_id', '=', $regionId);
            })
            ->select(
                'mining_ledger.date',
                DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                DB::raw("SUM(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as total_value"),
                DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners')
            )
            ->groupBy('mining_ledger.date')
            ->orderBy('mining_ledger.date')
            ->get();
    }

    /**
     * Get character mining statistics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getCharacterStatistics(Carbon $startDate, Carbon $endDate)
    {
        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');
        
        $priceColumn = match ($priceType) {
            'buy' => 'buy_price',
            'average' => 'average_price',
            default => 'sell_price',
        };

        return MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
            ->join('character_infos', 'mining_ledger.character_id', '=', 'character_infos.character_id')
            ->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                    ->where('mining_price_cache.region_id', '=', $regionId);
            })
            ->select(
                'mining_ledger.character_id',
                'character_infos.name',
                DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                DB::raw("SUM(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as total_value"),
                DB::raw('COUNT(DISTINCT mining_ledger.date) as days_active'),
                DB::raw('COUNT(DISTINCT mining_ledger.type_id) as unique_ore_types'),
                DB::raw('COUNT(DISTINCT mining_ledger.solar_system_id) as unique_systems')
            )
            ->groupBy('mining_ledger.character_id', 'character_infos.name')
            ->orderByDesc('total_quantity')
            ->get();
    }

    /**
     * Get detailed ore statistics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getOreStatistics(Carbon $startDate, Carbon $endDate)
    {
        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');
        
        $priceColumn = match ($priceType) {
            'buy' => 'buy_price',
            'average' => 'average_price',
            default => 'sell_price',
        };

        return MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                    ->where('mining_price_cache.region_id', '=', $regionId);
            })
            ->select(
                'mining_ledger.type_id',
                'invTypes.typeName as ore_name',
                DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                DB::raw("SUM(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as total_value"),
                DB::raw("AVG(mining_price_cache.{$priceColumn}) as average_price"),
                DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners')
            )
            ->groupBy('mining_ledger.type_id', 'invTypes.typeName')
            ->orderByDesc('total_quantity')
            ->get();
    }

    /**
     * Get detailed system statistics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getSystemStatistics(Carbon $startDate, Carbon $endDate)
    {
        return MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
            ->join('solar_systems', 'mining_ledger.solar_system_id', '=', 'solar_systems.system_id')
            ->select(
                'mining_ledger.solar_system_id',
                'solar_systems.name as system_name',
                'solar_systems.security as security_status',
                DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners'),
                DB::raw('COUNT(DISTINCT mining_ledger.type_id) as unique_ore_types'),
                DB::raw('COUNT(DISTINCT mining_ledger.date) as days_active')
            )
            ->groupBy('mining_ledger.solar_system_id', 'solar_systems.name', 'solar_systems.security')
            ->orderByDesc('total_quantity')
            ->get();
    }

    /**
     * Get export data for analytics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getExportData(Carbon $startDate, Carbon $endDate): array
    {
        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');
        
        $priceColumn = match ($priceType) {
            'buy' => 'buy_price',
            'average' => 'average_price',
            default => 'sell_price',
        };

        return MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
            ->join('character_infos', 'mining_ledger.character_id', '=', 'character_infos.character_id')
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->join('solar_systems', 'mining_ledger.solar_system_id', '=', 'solar_systems.system_id')
            ->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                    ->where('mining_price_cache.region_id', '=', $regionId);
            })
            ->select(
                'character_infos.name as character',
                'invTypes.typeName as ore_type',
                'mining_ledger.quantity',
                DB::raw("(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as value"),
                'solar_systems.name as system',
                'mining_ledger.date'
            )
            ->orderBy('mining_ledger.date', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    $item->character,
                    $item->ore_type,
                    $item->quantity,
                    number_format($item->value, 2),
                    $item->system,
                    $item->date->format('Y-m-d'),
                ];
            })
            ->toArray();
    }

    /**
     * Get mining trend data for charts.
     * This is an alias for getDailyTrends() to match controller expectations.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getMiningTrendData(Carbon $startDate, Carbon $endDate)
    {
        // Return the same data as getDailyTrends()
        return $this->getDailyTrends($startDate, $endDate);
    }

    /**
     * Get miner statistics for tables view.
     * This is an alias for getCharacterStatistics() to match controller expectations.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getMinerStatistics(Carbon $startDate, Carbon $endDate)
    {
        // Return the same data as getCharacterStatistics()
        return $this->getCharacterStatistics($startDate, $endDate);
    }

    /**
     * Get ore distribution data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getOreDistributionData(Carbon $startDate, Carbon $endDate)
    {
        $oreBreakdown = $this->getOreBreakdown($startDate, $endDate);
        
        return [
            'labels' => $oreBreakdown->pluck('ore_name')->toArray(),
            'data' => $oreBreakdown->pluck('total_quantity')->toArray(),
            'values' => $oreBreakdown->pluck('total_value')->toArray(),
        ];
    }

    /**
     * Get miner activity data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getMinerActivityData(Carbon $startDate, Carbon $endDate)
    {
        $topMiners = $this->getTopMiners($startDate, $endDate, 10);
        
        return [
            'labels' => $topMiners->pluck('name')->toArray(),
            'data' => $topMiners->pluck('total_quantity')->toArray(),
            'values' => $topMiners->pluck('total_value')->toArray(),
        ];
    }

    /**
     * Get system activity data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getSystemActivityData(Carbon $startDate, Carbon $endDate)
    {
        $systemBreakdown = $this->getSystemBreakdown($startDate, $endDate);
        
        return [
            'labels' => $systemBreakdown->pluck('system_name')->toArray(),
            'data' => $systemBreakdown->pluck('total_quantity')->toArray(),
        ];
    }

    /**
     * Clear analytics cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::tags(['mining-analytics'])->flush();
    }
}
