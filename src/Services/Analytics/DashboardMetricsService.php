<?php

namespace MiningManager\Services\Analytics;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningLedgerDailySummary;
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
        $stats = MiningLedgerDailySummary::whereDate('date', $date)
            ->selectRaw('SUM(total_quantity) as mined, COUNT(DISTINCT character_id) as miners')
            ->first();

        $mined = $stats->mined ?? 0;
        $miners = $stats->miners ?? 0;

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
        $stats = MiningLedgerDailySummary::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('SUM(total_quantity) as mined, SUM(total_value) as value, COUNT(DISTINCT character_id) as miners')
            ->first();

        $mined = $stats->mined ?? 0;
        $miners = $stats->miners ?? 0;
        $value = $stats->value ?? 0;

        return [
            'mined' => $mined,
            'miners' => $miners,
            'value' => $value,
            'average_per_miner' => $miners > 0 ? $mined / $miners : 0,
        ];
    }

    /**
     * Get tax metrics.
     * Uses a single aggregate query instead of 5 separate queries.
     *
     * @return array
     */
    public function getTaxMetrics(): array
    {
        $thisMonth = Carbon::now()->startOfMonth();

        $stats = MiningTax::selectRaw("
            COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount_owed ELSE 0 END), 0) as total_unpaid,
            COALESCE(SUM(CASE WHEN status = 'overdue' THEN amount_owed ELSE 0 END), 0) as total_overdue,
            COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= ? THEN amount_paid ELSE 0 END), 0) as paid_this_month,
            COUNT(CASE WHEN status = 'unpaid' THEN 1 END) as unpaid_count,
            COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count
        ", [$thisMonth])->first();

        return [
            'unpaid' => (float) $stats->total_unpaid,
            'overdue' => (float) $stats->total_overdue,
            'paid_this_month' => (float) $stats->paid_this_month,
            'unpaid_count' => (int) $stats->unpaid_count,
            'overdue_count' => (int) $stats->overdue_count,
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
            return MiningLedgerDailySummary::whereBetween('date', [$startDate, $endDate])
                ->join('character_infos', 'mining_ledger_daily_summaries.character_id', '=', 'character_infos.character_id')
                ->select(
                    'mining_ledger_daily_summaries.character_id',
                    'character_infos.name',
                    DB::raw('SUM(total_value) as total_value'),
                    DB::raw('SUM(total_quantity) as total_quantity')
                )
                ->groupBy('mining_ledger_daily_summaries.character_id', 'character_infos.name')
                ->orderByDesc('total_value')
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
            $dailyData = MiningLedgerDailySummary::whereBetween('date', [$startDate, $endDate])
                ->selectRaw('date, SUM(total_value) as value, SUM(total_quantity) as total_quantity, COUNT(DISTINCT character_id) as unique_miners')
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
        try {
            Cache::tags(['mining-manager', 'dashboard'])->flush();
        } catch (\BadMethodCallException $e) {
            // Fallback for cache drivers that don't support tags
            Cache::flush();
        }
    }
}
