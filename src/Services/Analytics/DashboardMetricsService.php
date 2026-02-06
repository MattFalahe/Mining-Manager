<?php

namespace MiningManager\Services\Analytics;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardMetricsService
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
     * Get the moon owner corporation ID from settings.
     *
     * @return int|null
     */
    protected function getMoonOwnerCorporationId(): ?int
    {
        return $this->settingsService->getSetting('general.moon_owner_corporation_id');
    }
    /**
     * Get dashboard summary metrics.
     *
     * @return array
     */
    public function getSummaryMetrics(): array
    {
        $cacheKey = 'mining-manager:dashboard:summary-metrics';
        $cacheDuration = config('mining-manager.dashboard.refresh_interval', 300) / 60;

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () {
            $today = Carbon::today();
            $thisMonth = Carbon::now()->startOfMonth();
            $lastMonth = Carbon::now()->subMonth()->startOfMonth();

            return [
                'today' => $this->getTodayMetrics($today),
                'this_month' => $this->getMonthMetrics($thisMonth, Carbon::now()),
                'last_month' => $this->getMonthMetrics($lastMonth, $lastMonth->copy()->endOfMonth()),
                'taxes' => $this->getTaxMetrics(),
                'events' => $this->getEventMetrics(),
                'extractions' => $this->getExtractionMetrics(),
            ];
        });
    }

    /**
     * Get today's metrics.
     *
     * @param Carbon $date
     * @return array
     */
    private function getTodayMetrics(Carbon $date): array
    {
        $mined = MiningLedger::whereDate('date', $date)->sum('quantity');
        $miners = MiningLedger::whereDate('date', $date)->distinct('character_id')->count();

        return [
            'mined' => $mined,
            'miners' => $miners,
            'average_per_miner' => $miners > 0 ? $mined / $miners : 0,
        ];
    }

    /**
     * Get month metrics.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getMonthMetrics(Carbon $startDate, Carbon $endDate): array
    {
        $mined = MiningLedger::whereBetween('date', [$startDate, $endDate])->sum('quantity');
        $miners = MiningLedger::whereBetween('date', [$startDate, $endDate])->distinct('character_id')->count();
        
        // Calculate value using settings service
        $generalSettings = $this->settingsService->getGeneralSettings();
        $pricingSettings = $this->settingsService->getPricingSettings();
        $regionId = $generalSettings['default_region_id'] ?? 10000002;
        $priceType = $pricingSettings['price_type'] ?? 'sell';
        $priceColumn = match ($priceType) {
            'buy' => 'buy_price',
            'average' => 'average_price',
            default => 'sell_price',
        };

        $value = MiningLedger::whereBetween('mining_ledger.date', [$startDate, $endDate])
            ->join('mining_price_cache', function ($join) use ($regionId) {
                $join->on('mining_ledger.type_id', '=', 'mining_price_cache.type_id')
                    ->where('mining_price_cache.region_id', '=', $regionId);
            })
            ->select(DB::raw("SUM(mining_ledger.quantity * mining_price_cache.{$priceColumn}) as total_value"))
            ->value('total_value') ?? 0;

        return [
            'mined' => $mined,
            'miners' => $miners,
            'value' => $value,
            'average_per_miner' => $miners > 0 ? $mined / $miners : 0,
        ];
    }

    /**
     * Get tax metrics.
     *
     * @return array
     */
    private function getTaxMetrics(): array
    {
        $thisMonth = Carbon::now()->startOfMonth();

        return [
            'unpaid' => MiningTax::where('status', 'unpaid')->sum('amount_owed'),
            'overdue' => MiningTax::where('status', 'overdue')->sum('amount_owed'),
            'paid_this_month' => MiningTax::where('status', 'paid')
                ->where('paid_at', '>=', $thisMonth)
                ->sum('amount_paid'),
            'unpaid_count' => MiningTax::where('status', 'unpaid')->count(),
            'overdue_count' => MiningTax::where('status', 'overdue')->count(),
        ];
    }

    /**
     * Get event metrics.
     *
     * @return array
     */
    private function getEventMetrics(): array
    {
        return [
            'active' => MiningEvent::where('status', 'active')->count(),
            'planned' => MiningEvent::where('status', 'planned')->count(),
            'completed_this_month' => MiningEvent::where('status', 'completed')
                ->whereMonth('end_time', Carbon::now()->month)
                ->count(),
        ];
    }

    /**
     * Get extraction metrics.
     * Now filters by moon owner corporation ID from settings.
     *
     * @return array
     */
    private function getExtractionMetrics(): array
    {
        $moonOwnerCorpId = $this->getMoonOwnerCorporationId();

        $extractingQuery = MoonExtraction::where('status', 'extracting');
        $readyQuery = MoonExtraction::where('status', 'ready');
        $upcomingQuery = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '>=', now())
            ->where('chunk_arrival_time', '<=', now()->addHours(24));

        // Filter by corporation if configured
        if ($moonOwnerCorpId) {
            $extractingQuery->where('corporation_id', $moonOwnerCorpId);
            $readyQuery->where('corporation_id', $moonOwnerCorpId);
            $upcomingQuery->where('corporation_id', $moonOwnerCorpId);
        }

        return [
            'extracting' => $extractingQuery->count(),
            'ready' => $readyQuery->count(),
            'upcoming_24h' => $upcomingQuery->count(),
        ];
    }

    /**
     * Get top miners for dashboard.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getTopMiners(Carbon $startDate, Carbon $endDate, int $limit = 10)
    {
        $cacheKey = "mining-manager:dashboard:top-miners:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}:{$limit}";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate, $limit) {
            return MiningLedger::whereBetween('date', [$startDate, $endDate])
                ->join('character_infos', 'mining_ledger.character_id', '=', 'character_infos.character_id')
                ->select(
                    'mining_ledger.character_id',
                    'character_infos.name',
                    DB::raw('SUM(mining_ledger.quantity) as total_quantity')
                )
                ->groupBy('mining_ledger.character_id', 'character_infos.name')
                ->orderByDesc('total_quantity')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Get mining chart data for dashboard.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getMiningChartData(Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = "mining-manager:dashboard:chart-data:{$startDate->format('Ymd')}:{$endDate->format('Ymd')}";
        $cacheDuration = config('mining-manager.performance.query_cache_duration', 15);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($startDate, $endDate) {
            $dailyData = MiningLedger::whereBetween('date', [$startDate, $endDate])
                ->select(
                    'date',
                    DB::raw('SUM(quantity) as total_quantity'),
                    DB::raw('COUNT(DISTINCT character_id) as unique_miners')
                )
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return [
                'labels' => $dailyData->pluck('date')->map(fn($date) => Carbon::parse($date)->format('M d'))->toArray(),
                'quantity' => $dailyData->pluck('total_quantity')->toArray(),
                'miners' => $dailyData->pluck('unique_miners')->toArray(),
            ];
        });
    }

    /**
     * Get recent mining activity.
     *
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getRecentActivity(int $limit = 20)
    {
        return MiningLedger::with(['character', 'type', 'solarSystem'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get upcoming moon extractions.
     * Now filters by moon owner corporation ID from settings.
     *
     * @param int $hours
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getUpcomingExtractions(int $hours = 48, int $limit = 5)
    {
        $moonOwnerCorpId = $this->getMoonOwnerCorporationId();

        $query = MoonExtraction::with(['structure', 'moon'])
            ->where('status', 'extracting')
            ->where('chunk_arrival_time', '>=', now())
            ->where('chunk_arrival_time', '<=', now()->addHours($hours));

        // Filter by corporation if configured
        if ($moonOwnerCorpId) {
            $query->where('corporation_id', $moonOwnerCorpId);
        }

        return $query->orderBy('chunk_arrival_time')
            ->limit($limit)
            ->get();
    }

    /**
     * Get comparison data (this month vs last month).
     *
     * @return array
     */
    public function getComparisonData(): array
    {
        $thisMonth = $this->getMonthMetrics(
            Carbon::now()->startOfMonth(),
            Carbon::now()
        );
        
        $lastMonth = $this->getMonthMetrics(
            Carbon::now()->subMonth()->startOfMonth(),
            Carbon::now()->subMonth()->endOfMonth()
        );

        return [
            'mined_change' => $this->calculatePercentageChange($thisMonth['mined'], $lastMonth['mined']),
            'value_change' => $this->calculatePercentageChange($thisMonth['value'], $lastMonth['value']),
            'miners_change' => $this->calculatePercentageChange($thisMonth['miners'], $lastMonth['miners']),
        ];
    }

    /**
     * Calculate percentage change.
     *
     * @param float $current
     * @param float $previous
     * @return float
     */
    private function calculatePercentageChange(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return (($current - $previous) / $previous) * 100;
    }

    /**
     * Clear dashboard cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::tags(['mining-manager', 'dashboard'])->flush();
    }
}
