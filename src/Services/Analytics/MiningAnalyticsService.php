<?php

namespace MiningManager\Services\Analytics;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningPriceCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

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

            return MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->join('universe_types', 'mining_ledger.type_id', '=', 'universe_types.type_id')
                ->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                    $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                        ->where('mining_price_cache.region_id', '=', $regionId);
                })
                ->select(
                    'mining_ledger.type_id',
                    'universe_types.typeName as ore_name',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw("SUM(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as total_value")
                )
                ->groupBy('mining_ledger.type_id', 'universe_types.typeName')
                ->orderByDesc('total_quantity')
                ->get();
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
            return MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
                ->join('universe_systems', 'mining_ledger.solar_system_id', '=', 'universe_systems.system_id')
                ->select(
                    'mining_ledger.solar_system_id',
                    'universe_systems.name as system_name',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                    DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners')
                )
                ->groupBy('mining_ledger.solar_system_id', 'universe_systems.name')
                ->orderByDesc('total_quantity')
                ->get();
        });
    }

    /**
     * Get daily mining trends.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getDailyTrends(Carbon $startDate, Carbon $endDate)
    {
        $cacheKey = "mining-analytics:daily-trends:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}";
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
        });
    }

    /**
     * Get mining trend data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getMiningTrendData(Carbon $startDate, Carbon $endDate): array
    {
        $dailyTrends = $this->getDailyTrends($startDate, $endDate);

        return [
            'labels' => $dailyTrends->pluck('date')->map(fn($date) => Carbon::parse($date)->format('M d'))->toArray(),
            'datasets' => [
                [
                    'label' => 'Total Quantity',
                    'data' => $dailyTrends->pluck('total_quantity')->toArray(),
                    'borderColor' => 'rgb(75, 192, 192)',
                    'tension' => 0.1,
                ],
                [
                    'label' => 'Unique Miners',
                    'data' => $dailyTrends->pluck('unique_miners')->toArray(),
                    'borderColor' => 'rgb(153, 102, 255)',
                    'tension' => 0.1,
                ],
            ],
        ];
    }

    /**
     * Get ore distribution data for pie charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getOreDistributionData(Carbon $startDate, Carbon $endDate): array
    {
        $oreBreakdown = $this->getOreBreakdown($startDate, $endDate);
        
        // Take top 10 ore types, group rest as "Other"
        $topOres = $oreBreakdown->take(10);
        $otherQuantity = $oreBreakdown->skip(10)->sum('total_quantity');

        $labels = $topOres->pluck('ore_name')->toArray();
        $data = $topOres->pluck('total_quantity')->toArray();

        if ($otherQuantity > 0) {
            $labels[] = 'Other';
            $data[] = $otherQuantity;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $this->generateColors(count($data)),
                ],
            ],
        ];
    }

    /**
     * Get miner activity data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getMinerActivityData(Carbon $startDate, Carbon $endDate): array
    {
        $topMiners = $this->getTopMiners($startDate, $endDate, 15);

        return [
            'labels' => $topMiners->pluck('name')->toArray(),
            'datasets' => [
                [
                    'label' => 'Ore Mined',
                    'data' => $topMiners->pluck('total_quantity')->toArray(),
                    'backgroundColor' => 'rgba(54, 162, 235, 0.5)',
                    'borderColor' => 'rgb(54, 162, 235)',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    /**
     * Get system activity data for charts.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getSystemActivityData(Carbon $startDate, Carbon $endDate): array
    {
        $systemBreakdown = $this->getSystemBreakdown($startDate, $endDate)->take(10);

        return [
            'labels' => $systemBreakdown->pluck('system_name')->toArray(),
            'datasets' => [
                [
                    'label' => 'Total Quantity',
                    'data' => $systemBreakdown->pluck('total_quantity')->toArray(),
                    'backgroundColor' => 'rgba(255, 99, 132, 0.5)',
                    'borderColor' => 'rgb(255, 99, 132)',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    /**
     * Get detailed miner statistics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Support\Collection
     */
    public function getMinerStatistics(Carbon $startDate, Carbon $endDate)
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
            ->join('universe_types', 'mining_ledger.type_id', '=', 'universe_types.type_id')
            ->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                    ->where('mining_price_cache.region_id', '=', $regionId);
            })
            ->select(
                'mining_ledger.type_id',
                'universe_types.typeName as ore_name',
                DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                DB::raw("SUM(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as total_value"),
                DB::raw("AVG(mining_price_cache.{$priceColumn}) as average_price"),
                DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners')
            )
            ->groupBy('mining_ledger.type_id', 'universe_types.typeName')
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
            ->join('universe_systems', 'mining_ledger.solar_system_id', '=', 'universe_systems.system_id')
            ->select(
                'mining_ledger.solar_system_id',
                'universe_systems.name as system_name',
                'universe_systems.security as security_status',
                DB::raw('SUM(mining_ledger.quantity) as total_quantity'),
                DB::raw('COUNT(DISTINCT mining_ledger.character_id) as unique_miners'),
                DB::raw('COUNT(DISTINCT mining_ledger.type_id) as unique_ore_types'),
                DB::raw('COUNT(DISTINCT mining_ledger.date) as days_active')
            )
            ->groupBy('mining_ledger.solar_system_id', 'universe_systems.name', 'universe_systems.security')
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
            ->join('universe_types', 'mining_ledger.type_id', '=', 'universe_types.type_id')
            ->join('universe_systems', 'mining_ledger.solar_system_id', '=', 'universe_systems.system_id')
            ->leftJoin('mining_price_cache', function ($join) use ($regionId) {
                $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                    ->where('mining_price_cache.region_id', '=', $regionId);
            })
            ->select(
                'character_infos.name as character',
                'universe_types.typeName as ore_type',
                'mining_ledger.quantity',
                DB::raw("(mining_ledger.quantity * COALESCE(mining_price_cache.{$priceColumn}, 0)) as value"),
                'universe_systems.name as system',
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
     * Generate color palette for charts.
     *
     * @param int $count
     * @return array
     */
    private function generateColors(int $count): array
    {
        $baseColors = [
            'rgba(255, 99, 132, 0.6)',
            'rgba(54, 162, 235, 0.6)',
            'rgba(255, 206, 86, 0.6)',
            'rgba(75, 192, 192, 0.6)',
            'rgba(153, 102, 255, 0.6)',
            'rgba(255, 159, 64, 0.6)',
            'rgba(199, 199, 199, 0.6)',
            'rgba(83, 102, 255, 0.6)',
            'rgba(255, 99, 255, 0.6)',
            'rgba(99, 255, 132, 0.6)',
        ];

        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $colors[] = $baseColors[$i % count($baseColors)];
        }

        return $colors;
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
