<?php

namespace MattFalahe\Seat\MiningManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class MiningAnalytics
{
    protected $cachePrefix = 'mining_analytics_';
    protected $cacheTTL = 300; // 5 minutes
    
    public function getTrends($corporationId, $days = 30)
    {
        $cacheKey = $this->cachePrefix . "trends_{$corporationId}_{$days}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($corporationId, $days) {
            $startDate = Carbon::now()->subDays($days);
            
            return DB::table('mining_ledger')
                ->select(
                    DB::raw('DATE(mined_at) as date'),
                    DB::raw('SUM(quantity) as total_quantity'),
                    DB::raw('SUM(volume) as total_volume'),
                    DB::raw('SUM(refined_value) as total_value'),
                    DB::raw('COUNT(DISTINCT character_id) as unique_miners'),
                    'location_type'
                )
                ->where('corporation_id', $corporationId)
                ->where('mined_at', '>=', $startDate)
                ->groupBy('date', 'location_type')
                ->orderBy('date')
                ->get()
                ->groupBy('date')
                ->map(function ($dayData, $date) {
                    return [
                        'date' => $date,
                        'total_quantity' => $dayData->sum('total_quantity'),
                        'total_volume' => $dayData->sum('total_volume'),
                        'total_value' => $dayData->sum('total_value'),
                        'unique_miners' => $dayData->max('unique_miners'),
                        'breakdown' => $dayData->mapWithKeys(function ($item) {
                            return [$item->location_type => [
                                'quantity' => $item->total_quantity,
                                'volume' => $item->total_volume,
                                'value' => $item->total_value,
                            ]];
                        }),
                    ];
                });
        });
    }
    
    public function getTopMiners($corporationId, $limit = 10, $days = 30)
    {
        $cacheKey = $this->cachePrefix . "top_miners_{$corporationId}_{$limit}_{$days}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($corporationId, $limit, $days) {
            $startDate = Carbon::now()->subDays($days);
            
            return DB::table('mining_ledger as ml')
                ->select(
                    'ml.character_id',
                    'ci.name as character_name',
                    'u.name as main_name',
                    DB::raw('SUM(ml.quantity) as total_quantity'),
                    DB::raw('SUM(ml.volume) as total_volume'),
                    DB::raw('SUM(ml.refined_value) as total_value'),
                    DB::raw('COUNT(DISTINCT DATE(ml.mined_at)) as active_days'),
                    DB::raw('COUNT(DISTINCT ml.type_id) as ore_types')
                )
                ->join('character_infos as ci', 'ml.character_id', '=', 'ci.character_id')
                ->leftJoin('refresh_tokens as rt', 'ml.character_id', '=', 'rt.character_id')
                ->leftJoin('users as u', 'rt.user_id', '=', 'u.id')
                ->where('ml.corporation_id', $corporationId)
                ->where('ml.mined_at', '>=', $startDate)
                ->groupBy('ml.character_id', 'ci.name', 'u.name')
                ->orderByDesc('total_value')
                ->limit($limit)
                ->get();
        });
    }
    
    public function getOreDistribution($corporationId, $days = 30)
    {
        $cacheKey = $this->cachePrefix . "ore_dist_{$corporationId}_{$days}";
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($corporationId, $days) {
            $startDate = Carbon::now()->subDays($days);
            
            $data = DB::table('mining_ledger as ml')
                ->select(
                    'ig.groupName as ore_group',
                    DB::raw('SUM(ml.quantity) as quantity'),
                    DB::raw('SUM(ml.volume) as volume'),
                    DB::raw('SUM(ml.refined_value) as value'),
                    DB::raw('COUNT(DISTINCT ml.character_id) as miners')
                )
                ->join('invTypes as it', 'ml.type_id', '=', 'it.typeID')
                ->join('invGroups as ig', 'it.groupID', '=', 'ig.groupID')
                ->where('ml.corporation_id', $corporationId)
                ->where('ml.mined_at', '>=', $startDate)
                ->groupBy('ig.groupID', 'ig.groupName')
                ->orderByDesc('value')
                ->get();
            
            $total = $data->sum('value');
            
            return $data->map(function ($item) use ($total) {
                $item->percentage = $total > 0 ? round(($item->value / $total) * 100, 2) : 0;
                return $item;
            });
        });
    }
    
    public function getMiningTimeline($corporationId, $days = 30, $type = 'all')
    {
        $startDate = Carbon::now()->subDays($days);
        $interval = $this->determineInterval($days);
        
        $query = DB::table('mining_ledger')
            ->select(
                DB::raw("DATE_FORMAT(mined_at, '{$interval}') as period"),
                DB::raw('SUM(quantity) as quantity'),
                DB::raw('SUM(volume) as volume'),
                DB::raw('SUM(refined_value) as value')
            )
            ->where('corporation_id', $corporationId)
            ->where('mined_at', '>=', $startDate);
        
        if ($type !== 'all') {
            $query->where('location_type', $type);
        }
        
        return $query->groupBy('period')
            ->orderBy('period')
            ->get();
    }
    
    private function determineInterval($days)
    {
        if ($days <= 7) {
            return '%Y-%m-%d %H:00'; // Hourly
        } elseif ($days <= 31) {
            return '%Y-%m-%d'; // Daily
        } elseif ($days <= 90) {
            return '%Y Week %u'; // Weekly
        } else {
            return '%Y-%m'; // Monthly
        }
    }
}
