<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Models\MiningTax;
use MiningManager\Services\Character\CharacterInfoService;
use MiningManager\Services\Ledger\LedgerSummaryService;
use MiningManager\Services\Analytics\ChartFilter;
use MiningManager\Services\Tax\ClassificationEpoch;
use MiningManager\Services\Tax\TaxPeriodHelper;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Services\ReprocessingRegistry;
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Http\Controllers\Traits\EnrichesCharacterData;
use Carbon\Carbon;
use MiningManager\Http\Controllers\Concerns\GuardsDataExport;

class LedgerController extends Controller
{
    use GuardsDataExport;

    use EnrichesCharacterData;

    protected $characterInfoService;
    protected $summaryService;
    protected OreValuationService $oreValuationService;
    protected SettingsManagerService $settingsService;

    public function __construct(
        CharacterInfoService $characterInfoService,
        LedgerSummaryService $summaryService,
        OreValuationService $oreValuationService,
        SettingsManagerService $settingsService
    ) {
        $this->characterInfoService = $characterInfoService;
        $this->summaryService = $summaryService;
        $this->oreValuationService = $oreValuationService;
        $this->settingsService = $settingsService;
    }
    /**
     * Display personal mining activity for the logged-in user
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function myMining(Request $request)
    {
        // Get user's characters
        $userCharacters = auth()->user()->characters->pluck('character_id');

        if ($userCharacters->isEmpty()) {
            $emptyChartData = $this->getEmptyChartData();
            return view('mining-manager::ledger.my-mining', [
                'ledgerEntries' => collect(),
                'stats' => $this->getEmptyStats(),
                'trendData' => ['labels' => [], 'values' => [], 'by_type' => []],
                'oreDistribution' => ['labels' => [], 'values' => []],
                'monthlyComparison' => ['labels' => [], 'values' => []],
                'recentActivity' => collect(),
                'characterStats' => [],
                'characters' => collect(),
                'dateFrom' => now()->subMonth()->format('Y-m-d'),
                'dateTo' => now()->format('Y-m-d'),
                'characterId' => null,
                'features' => $this->settingsService->getFeatureFlags(),
                'period' => 'period',
                'rangeLabel' => null,
                'billedOn' => null,
                'message' => trans('mining-manager::ledger.no_characters'),
            ]);
        }

        // The tax period we are in, the one before it, or a rolling window.
        // The tax periods line up with the bills, so the page opens on this one.
        $period = $request->get('period', 'period');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));
        $characterId = $request->get('character_id');

        $periodHelper = app(TaxPeriodHelper::class);
        $periodType = $periodHelper->periodTypeForViewer();
        [$periodStart, $periodEnd] = $periodHelper->getPeriodBounds(now(), $periodType);

        if (!$dateFrom) {
            switch ($period) {
                case 'last_period':
                    // The period holding the day before this one began.
                    [$lastStart, $lastEnd] = $periodHelper->getPeriodBounds($periodStart->copy()->subDay(), $periodType);
                    $dateFrom = $lastStart->format('Y-m-d');
                    $dateTo = $lastEnd->format('Y-m-d');
                    break;
                case 'week':
                    $dateFrom = now()->subDays(6)->format('Y-m-d');
                    break;
                case 'month':
                    $dateFrom = now()->subDays(29)->format('Y-m-d');
                    break;
                case 'year':
                    $dateFrom = now()->subYear()->format('Y-m-d');
                    break;
                case 'all':
                    $dateFrom = '2003-01-01'; // EVE Online launch date
                    break;
                case 'period':
                default:
                    $period = 'period';
                    $dateFrom = $periodStart->format('Y-m-d');
                    $dateTo = $periodEnd->format('Y-m-d');
                    break;
            }
        }

        $taxPeriodShown = in_array($period, ['period', 'last_period'], true) && !$request->filled('date_from');
        $rangeLabel = match (true) {
            $taxPeriodShown => $periodHelper->formatPeriod(Carbon::parse($dateFrom), Carbon::parse($dateTo), $periodType),
            $period === 'all' && !$request->filled('date_from') => trans('mining-manager::ledger.all_time'),
            default => Carbon::parse($dateFrom)->format('M j, Y') . ' - ' . Carbon::parse($dateTo)->format('M j, Y'),
        };

        // A tax period's bills go out on the nightly run two days after it
        // ends. Only said when the run is switched on to send them.
        $features = $this->settingsService->getFeatureFlags();
        $billedOn = $taxPeriodShown && ($features['auto_calculate_taxes'] ?? true)
            ? Carbon::parse($dateTo)->addDays(2)->setTime(2, 15)
            : null;

        // Build query for user's characters with eager loading
        $query = MiningLedger::with(['solarSystem', 'type'])
            ->whereIn('character_id', $userCharacters)
            ->whereBetween('date', [$dateFrom, $dateTo]);

        // Filter by specific character if selected
        if ($characterId && $userCharacters->contains($characterId)) {
            $query->where('character_id', $characterId);
        }

        $query->orderBy('date', 'desc');

        $ledgerEntries = $query->paginate(50);
        
        // Enrich with character information
        $ledgerEntries = $this->enrichPaginatorWithCharacterInfo(
            $ledgerEntries,
            $this->characterInfoService
        );

        // Calculate personal statistics
        $stats = $this->calculatePersonalStats($userCharacters, $dateFrom, $dateTo, $characterId);

        // Get chart data — map to variable names the view expects
        $rawChartData = $this->getPersonalChartData($userCharacters, $dateFrom, $dateTo, $characterId);
        $trendData = [
            'labels' => $rawChartData['daily']['labels'],
            'values' => $rawChartData['daily']['data'],
            'by_type' => $rawChartData['daily']['by_type'],
        ];
        $oreDistribution = [
            'labels' => $rawChartData['topOres']['labels'],
            'values' => $rawChartData['topOres']['data'],
        ];
        $monthlyComparison = $this->getMonthlyComparisonData($userCharacters, $characterId);

        // Get recent activity for the table
        $recentActivity = MiningLedger::with(['solarSystem', 'type'])
            ->whereIn('character_id', $userCharacters)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->when($characterId && $userCharacters->contains($characterId), fn($q) => $q->where('character_id', $characterId))
            ->orderBy('date', 'desc')
            ->limit(20)
            ->get();

        // Per-character breakdown
        $characterStats = $this->getCharacterBreakdown($userCharacters, $dateFrom, $dateTo);

        // Get user's characters for filter - enrich with info
        $charactersInfo = $this->characterInfoService->getBatchCharacterInfo($userCharacters->toArray());
        $characters = collect($charactersInfo)->sortBy('name');

        return view('mining-manager::ledger.my-mining', compact(
            'features',
            'period',
            'rangeLabel',
            'billedOn',
            'ledgerEntries',
            'stats',
            'trendData',
            'oreDistribution',
            'monthlyComparison',
            'recentActivity',
            'characterStats',
            'characters',
            'dateFrom',
            'dateTo',
            'characterId'
        ));
    }


    /**
     * Calculate ledger statistics for a given date range
     *
     * @param string $startDate
     * @param string $endDate
     * @param int|null $characterId
     * @return array
     */
    /**
     * Check if the current user can view a specific character's data.
     * Directors can view all; members can only view their own linked characters.
     */
    protected function canViewCharacter(int $characterId): bool
    {
        $user = auth()->user();

        // Directors and admins can view all characters
        if ($user->can('mining-manager.director') || $user->can('mining-manager.admin')) {
            return true;
        }

        // Members can only view their own linked characters
        $userCharacterIds = $user->characters->pluck('character_id')->toArray();
        return in_array($characterId, $userCharacterIds);
    }

    /**
     * Calculate personal statistics for given character IDs
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function calculatePersonalStats($characterIds, $startDate, $endDate, $specificCharacterId = null)
    {
        // Closure to build a fresh base query on raw table (for queries needing per-row data)
        $baseQuery = fn() => MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId));

        // Single aggregation from pre-computed daily summaries
        $agg = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->selectRaw('
                SUM(total_quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(total_tax) as total_tax,
                SUM(event_discount_total) as event_discount_total,
                COUNT(DISTINCT date) as active_days,
                MAX(total_value) as best_day_value
            ')
            ->first();

        $totalQuantity = (float) ($agg->total_quantity ?? 0);
        $totalValue = (float) ($agg->total_value ?? 0);
        $activeDays = (int) ($agg->active_days ?? 0);

        // Total volume in m³ from raw ledger × invTypes.volume
        $totalVolume = (float) $baseQuery()
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->selectRaw('COALESCE(SUM(mining_ledger.quantity * invTypes.volume), 0) as total_volume')
            ->value('total_volume');

        // Best single day by value (from daily summaries)
        $bestDayValue = (float) ($agg->best_day_value ?? 0);

        // The bills that cover any of the days shown, each counted whole, as
        // it was billed. A bill's month is only the month it falls in, which a
        // rolling window can start after while still taking in the bill's
        // days. Bills from before periods were stored cover their month.
        $bills = MiningTax::whereIn('character_id', $characterIds)
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->where('status', '!=', 'waived')
            ->whereRaw('COALESCE(period_start, month) <= ?', [$endDate])
            ->whereRaw('COALESCE(period_end, LAST_DAY(month)) >= ?', [$startDate])
            ->get();

        $taxBilled = (float) $bills->sum('amount_owed');
        $paidTaxAmount = (float) $bills->sum('amount_paid');
        $leftToPay = (float) $bills->sum(fn ($bill) => $bill->getRemainingBalance());

        // Estimated tax: daily summaries first, then the raw ledger. When both
        // say nothing is due, nothing is: mining can be untaxed (exempt, a 0%
        // rate), and a guess at the ore rate would show tax nobody is billed.
        $estimatedTax = (float) ($agg->total_tax ?? 0);

        if ($estimatedTax <= 0) {
            // Fallback: calculate from raw mining_ledger tax_amount
            $estimatedTax = (float) MiningLedger::whereIn('character_id', $characterIds)
                ->whereBetween('date', [$startDate, $endDate])
                ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
                ->sum('tax_amount');
        }

        // All-time event tax savings — every discount the user has accrued
        // from event participation, across all events the daily summaries
        // have seen. Cheap one-column sum from mining_ledger_daily_summaries.
        $eventSavingsAllTime = $this->summaryService->getTotalEventSavings(
            is_array($characterIds) ? $characterIds : $characterIds->toArray()
        );

        return [
            'total_quantity' => $totalQuantity,
            'total_value' => $totalValue,
            'total_volume' => $totalVolume,
            'active_days' => $activeDays,
            'best_day_value' => $bestDayValue,
            'daily_average' => $activeDays > 0 ? round($totalValue / $activeDays, 0) : 0,
            'estimated_tax_this_month' => $estimatedTax,
            'total_tax_billed' => $taxBilled,
            'total_tax_paid' => $paidTaxAmount,
            'tax_outstanding' => $leftToPay,
            'bills_covering' => $bills->count(),
            'next_payment' => $this->nextPayment($characterIds),
            'event_discount_total' => (float) ($agg->event_discount_total ?? 0),
            'event_savings_all_time' => $eventSavingsAllTime,
            'mining_days' => $activeDays,
            'favorite_ore' => $this->getFavoriteOre($characterIds, $startDate, $endDate, $specificCharacterId),
            'corp_rank' => $this->calculateCorpRank($characterIds, $startDate, $endDate),
        ];
    }

    /**
     * What the account has to pay next, whatever period the page shows: the
     * overdue bills when there are any, otherwise the next one coming due.
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @return array{overdue_count: int, overdue_amount: float, next: MiningTax|null}
     */
    protected function nextPayment($characterIds): array
    {
        $open = MiningTax::whereIn('character_id', $characterIds)
            ->outstanding()
            ->get()
            ->filter(fn ($bill) => $bill->getRemainingBalance() > 0);

        $overdue = $open->filter(fn ($bill) => $bill->isOverdue());

        return [
            'overdue_count' => $overdue->count(),
            'overdue_amount' => (float) $overdue->sum(fn ($bill) => $bill->getRemainingBalance()),
            'next' => $open->reject(fn ($bill) => $bill->isOverdue())
                ->sortBy(fn ($bill) => $bill->effectiveDueDate()->getTimestamp())
                ->first(),
        ];
    }

    /**
     * Get chart data for personal mining visualization
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function getPersonalChartData($characterIds, $startDate, $endDate, $specificCharacterId = null)
    {
        // Use a closure to build a fresh query each time (avoids consumed builder bug)
        $baseQuery = fn() => MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId));

        // Daily mining value from pre-computed daily summaries, with each
        // day split by kind of ore for the chart's By type view. The kinds
        // add up to the day's value.
        $dailyData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->selectRaw('
                date,
                SUM(total_value) as value,
                SUM(moon_ore_value) as moon,
                SUM(regular_ore_value) as ore,
                SUM(ice_value) as ice,
                SUM(gas_value) as gas,
                SUM(abyssal_ore_value) as abyssal,
                SUM(triglavian_ore_value) as triglavian
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top ores by value (uses a fresh query builder)
        $topOres = ($baseQuery())
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->select('invTypes.typeName as name', DB::raw('SUM(total_value) as value'))
            ->groupBy('invTypes.typeName')
            ->orderByDesc('value')
            ->limit(10)
            ->get();

        // Everything past the top ten as one slice, so each slice is a share of
        // all the mining in the period rather than of the top ten.
        $topOreLabels = $topOres->pluck('name')->toArray();
        $topOreData = $topOres->pluck('value')->map(fn ($value) => (float) $value)->toArray();
        $otherOres = (float) ($baseQuery())->sum('total_value') - array_sum($topOreData);
        if ($otherOres >= 1) {
            $topOreLabels[] = trans('mining-manager::ledger.other_ores');
            $topOreData[] = round($otherOres, 2);
        }

        return [
            'daily' => [
                'labels' => $dailyData->pluck('date')->map(fn($d) => Carbon::parse($d)->format('M d'))->toArray(),
                'data' => $dailyData->pluck('value')->toArray(),
                'by_type' => collect(['moon', 'ore', 'ice', 'gas', 'abyssal', 'triglavian'])
                    ->mapWithKeys(fn ($type) => [$type => $dailyData->pluck($type)->map(fn ($value) => (float) $value)->all()])
                    ->all(),
            ],
            'topOres' => [
                'labels' => $topOreLabels,
                'data' => $topOreData,
            ],
        ];
    }

    /**
     * Get the favorite ore (most mined by value) for given parameters
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function getFavoriteOre($characterIds, $startDate, $endDate, $specificCharacterId = null)
    {
        $query = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate]);

        if ($specificCharacterId) {
            $query->where('character_id', $specificCharacterId);
        }

        $favorite = $query
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->select('invTypes.typeName as name', DB::raw('SUM(total_value) as total'))
            ->groupBy('invTypes.typeName', 'mining_ledger.type_id')
            ->orderByDesc('total')
            ->first();

        return $favorite ? [
            'name' => $favorite->name,
            'value' => $favorite->total,
        ] : [
            'name' => trans('mining-manager::ledger.no_data'),
            'value' => 0,
        ];
    }

    /**
     * Get empty statistics array
     *
     * @return array
     */
    protected function getEmptyStats()
    {
        return [
            'total_quantity' => 0,
            'total_value' => 0,

            'active_days' => 0,
            'best_day_value' => 0,
            'daily_average' => 0,
            'estimated_tax_this_month' => 0,
            'total_tax_billed' => 0,
            'total_tax_paid' => 0,
            'tax_outstanding' => 0,
            'bills_covering' => 0,
            'next_payment' => ['overdue_count' => 0, 'overdue_amount' => 0.0, 'next' => null],
            'event_discount_total' => 0,
            'event_savings_all_time' => 0,
            'mining_days' => 0,
            'favorite_ore' => [
                'name' => trans('mining-manager::ledger.no_data'),
                'value' => 0,
            ],
            'corp_rank' => null,
        ];
    }

    /**
     * Get estimated tax from daily summaries for a month.
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $month YYYY-MM format
     * @param int|null $specificCharacterId
     * @return float
     */
    protected function getEstimatedTaxFromDailySummaries($characterIds, string $month, ?int $specificCharacterId = null): float
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        return (float) MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->forMonth($monthDate)
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->sum('total_tax');
    }

    /**
     * Calculate the player's rank among their corporation's miners for the
     * period, account against account: another player's characters are added
     * up together the way this player's are, and a miner with no SeAT account
     * stands alone. The corporation is the main character's, its miners the
     * ones the dashboard counts.
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @return int|null
     */
    protected function calculateCorpRank($characterIds, string $startDate, string $endDate): ?int
    {
        $mine = array_map('intval', is_array($characterIds) ? $characterIds : $characterIds->toArray());

        $myTotal = (float) MiningLedgerDailySummary::whereIn('character_id', $mine)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('total_value');

        if ($myTotal <= 0) {
            return null;
        }

        $mainId = auth()->user()->main_character_id ?: ($mine[0] ?? null);
        $corporationId = $mainId
            ? DB::table('character_affiliations')->where('character_id', $mainId)->value('corporation_id')
            : null;

        if (!$corporationId) {
            return null;
        }

        $others = array_values(array_diff(
            app(\MiningManager\Services\Character\CorporationMembers::class)->characterIds((int) $corporationId),
            $mine
        ));

        if (!$others) {
            return 1;
        }

        $totals = MiningLedgerDailySummary::whereIn('character_id', $others)
            ->whereBetween('date', [$startDate, $endDate])
            ->select('character_id', DB::raw('SUM(total_value) as char_total'))
            ->groupBy('character_id')
            ->pluck('char_total', 'character_id');

        $accountOf = DB::table('refresh_tokens')
            ->whereIn('character_id', $others)
            ->pluck('user_id', 'character_id');

        $accounts = [];
        foreach ($totals as $characterId => $total) {
            $account = isset($accountOf[$characterId]) ? 'user:' . $accountOf[$characterId] : 'character:' . $characterId;
            $accounts[$account] = ($accounts[$account] ?? 0) + (float) $total;
        }

        return 1 + count(array_filter($accounts, fn ($total) => $total > $myTotal));
    }

    /**
     * Get monthly comparison data (last 6 months of totals).
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function getMonthlyComparisonData($characterIds, ?int $specificCharacterId = null): array
    {
        $labels = [];
        $values = [];

        // Single batch query from daily summaries for all 6 months. Months are
        // stepped from the 1st: from the 29th to the 31st, subMonths() runs
        // over into the next month, showing one month twice and skipping
        // another.
        $sixMonthsAgo = now()->startOfMonth()->subMonths(5);
        $monthlyData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->where('date', '>=', $sixMonthsAgo)
            ->when($specificCharacterId, fn($q) => $q->where('character_id', $specificCharacterId))
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as month_key, SUM(total_value) as total")
            ->groupBy('month_key')
            ->get()
            ->keyBy('month_key');

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->startOfMonth()->subMonths($i);
            $monthKey = $month->format('Y-m');
            $value = $monthlyData->get($monthKey)->total ?? 0;

            $labels[] = $month->format('M Y');
            $values[] = (float) $value;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Get per-character breakdown for the stats table.
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    protected function getCharacterBreakdown($characterIds, string $startDate, string $endDate): array
    {
        $entries = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->selectRaw('
                character_id,
                SUM(total_value) as total_value,
                SUM(total_quantity) as quantity,
                COUNT(DISTINCT date) as sessions
            ')
            ->groupBy('character_id')
            ->get();

        // Get volume (m³) from raw ledger joined with invTypes
        $volumes = MiningLedger::whereIn('mining_ledger.character_id', $characterIds)
            ->whereBetween('mining_ledger.date', [$startDate, $endDate])
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->selectRaw('mining_ledger.character_id, SUM(mining_ledger.quantity * invTypes.volume) as total_volume_m3')
            ->groupBy('mining_ledger.character_id')
            ->pluck('total_volume_m3', 'character_id');

        $grandTotal = $entries->sum('total_value');

        $names = app(\MiningManager\Services\Character\CharacterNames::class)->many($entries->pluck('character_id'));

        return $entries->map(function ($entry) use ($grandTotal, $volumes, $names) {
            return [
                'character_id' => $entry->character_id,
                'name' => $names[(int) $entry->character_id]['name'] ?? "Character {$entry->character_id}",
                'total_value' => (float) $entry->total_value,
                'quantity' => (float) $entry->quantity,
                'total_volume_m3' => (float) ($volumes->get($entry->character_id) ?? 0),
                'sessions' => (int) $entry->sessions,
                'percentage' => $grandTotal > 0 ? ($entry->total_value / $grandTotal) * 100 : 0,
            ];
        })->sortByDesc('total_value')->values()->toArray();
    }

    /**
     * Get empty chart data array
     *
     * @return array
     */
    protected function getEmptyChartData()
    {
        return [
            'daily' => [
                'labels' => [],
                'data' => [],
            ],
            'topOres' => [
                'labels' => [],
                'data' => [],
            ],
        ];
    }

    /**
     * Get details for a specific mining ledger entry
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function details($id)
    {
        $entry = MiningLedger::with(['character.user', 'solarSystem', 'type'])
            ->findOrFail($id);

        // Authorization: route is gated by mining-manager.member, but the
        // permission only checks the user is *some* member — not that this
        // particular ledger row belongs to a character they're allowed to
        // see. Without this check, a member can iterate $id values and view
        // any character's per-row mining (system, ore, value, tax). Other
        // character-scoped endpoints in this controller (showCharacterDetails,
        // getCharacterDailySummary, getDetailedEntries, getCharacterSystemDetails)
        // all call canViewCharacter() — this one was the lone outlier.
        // canViewCharacter() returns true for directors/admins (they can
        // view everyone) and for members iff the character is one of their
        // own SeAT-linked characters.
        if (!$this->canViewCharacter($entry->character_id)) {
            abort(403, 'You do not have permission to view this character.');
        }

        // Get related tax record if exists
        $taxRecord = MiningTax::where('character_id', $entry->character_id)
            ->whereYear('month', Carbon::parse($entry->date)->year)
            ->whereMonth('month', Carbon::parse($entry->date)->month)
            ->first();

        return view('mining-manager::ledger.partials.details', compact('entry', 'taxRecord'));
    }

    /**
     * Export personal mining ledger data (only current user's characters)
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportPersonal(Request $request)
    {
        if (!$this->dataExportIsAllowed()) {
            return $this->refuseDataExport($request);
        }

        // Get current user's character IDs
        $characterIds = auth()->user()->characters->pluck('character_id');

        // Query only for user's characters
        $query = MiningLedger::with(['type', 'solarSystem'])
            ->whereIn('character_id', $characterIds);

        // Apply date filters if provided
        if ($request->has('start_date') && $request->input('start_date')) {
            $query->where('date', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date') && $request->input('end_date')) {
            $query->where('date', '<=', $request->input('end_date'));
        }

        $filename = 'my_mining_' . now()->format('Y-m-d_His') . '.csv';

        // Stream in chunks — same rationale as exportLedger above.
        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($handle, [
                'Date',
                'Character',
                'Ore Type',
                'Solar System',
                'Quantity',
                'Price',
                'Total Value',
                'Tax Rate',
                'Tax Amount',
            ]);

            $names = app(\MiningManager\Services\Character\CharacterNames::class);
            $query->chunkByIdDesc(500, function ($entries) use ($handle, $names) {
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        Carbon::parse($entry->date)->format('Y-m-d H:i:s'),
                        $names->nameOrId($entry->character_id),
                        $entry->type->name ?? 'Unknown',
                        $entry->solarSystem->name ?? 'Unknown',
                        number_format($entry->quantity, 2),
                        number_format($entry->price, 2),
                        number_format($entry->total_value, 2),
                        number_format($entry->tax_rate, 2) . '%',
                        number_format($entry->tax_amount, 2),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }


    /**
     * Display hierarchical mining summary with character monthly totals
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function summaryIndex(Request $request)
    {
        // Get month parameter or default to current month
        $month = $request->get('month', now()->format('Y-m'));
        $groupByMain = $request->get('group_by_main', true); // Default to grouped view
        $sortBy = $request->get('sort_by', 'total_value'); // Default sort column
        $sortDir = $request->get('sort_dir', 'desc'); // Default sort direction
        $scope = $request->get('scope') === LedgerSummaryService::SCOPE_MOONS
            ? LedgerSummaryService::SCOPE_MOONS
            : LedgerSummaryService::SCOPE_MEMBERS;

        // Validate month format
        try {
            $monthDate = Carbon::parse($month)->startOfMonth();
        } catch (\Exception $e) {
            $monthDate = now()->startOfMonth();
            $month = $monthDate->format('Y-m');
        }

        $isDirector = auth()->user()->can('mining-manager.director');

        // The corporation filter is a director's: a member only ever sees their
        // own rows. The moons view opens on the Moon Owner Corporation, and an
        // empty choice there means every refinery the install observes.
        $corporationId = $isDirector && $request->filled('corporation_id')
            ? (int) $request->input('corporation_id')
            : null;
        if ($scope === LedgerSummaryService::SCOPE_MOONS && !($isDirector && $request->has('corporation_id'))) {
            $corporationId = $this->settingsService->getTaxProgramCorporationId();
        }

        // All mining can be narrowed the way the Performance Charts are. The
        // moons view is moon ore off our refineries already.
        $ore = (string) $request->get('ore', 'all');
        if ($scope === LedgerSummaryService::SCOPE_MOONS || !array_key_exists($ore, LedgerSummaryService::ORE_FILTERS)) {
            $ore = 'all';
        }
        [$moonSource, $oreCategory] = LedgerSummaryService::ORE_FILTERS[$ore];
        $filter = new ChartFilter(
            $moonSource,
            $oreCategory,
            [],
            (int) $this->settingsService->getSettingForCorporation('general.moon_owner_corporation_id', null) ?: null
        );

        // A biweekly install bills each half of the month on its own, so the
        // page shows one half or the whole month. It opens on the half we are
        // in, and on the whole month for any month gone by.
        $periodHelper = app(TaxPeriodHelper::class);
        $biweekly = $periodHelper->periodTypeForMoonOwner() === 'biweekly';
        $halves = $biweekly ? $periodHelper->getPeriodsInMonth($monthDate, 'biweekly') : [];
        $half = $this->shownHalf($request, $monthDate, $biweekly);
        $period = $half === 'month' ? null : $halves[(int) $half - 1];

        // Get enhanced monthly summaries (includes ore types and systems)
        $summaries = $this->summaryService->getEnhancedMonthlySummaries($month, $corporationId, $scope, $period, $filter);

        // Group by main character if requested
        if ($groupByMain) {
            $summaries = $this->summaryService->groupByMainCharacter($summaries, $corporationId, $scope);
        }

        // Filter to user's own characters for non-directors (members see only their own)
        if (!$isDirector) {
            $userCharacterIds = auth()->user()->characters->pluck('character_id')->toArray();
            $summaries = $summaries->filter(function ($summary) use ($userCharacterIds) {
                // Check main character
                if (in_array($summary->character_id, $userCharacterIds)) {
                    return true;
                }
                // Check alt characters (grouped view)
                if (isset($summary->alt_characters) && $summary->alt_characters->isNotEmpty()) {
                    return $summary->alt_characters->pluck('character_id')->intersect($userCharacterIds)->isNotEmpty();
                }
                return false;
            })->values();
        }

        // Enrich with character information (names, corporations for unregistered characters)
        $summaries = $this->enrichWithCharacterInfo($summaries, $this->characterInfoService);

        // Apply sorting
        $summaries = $this->sortSummaries($summaries, $sortBy, $sortDir);

        // Calculate totals
        $totals = [
            'total_value' => $summaries->sum('total_value'),
            'total_tax' => $summaries->sum('total_tax'),
            'event_discount_total' => $summaries->sum('event_discount_total'),
            'total_quantity' => $summaries->sum('total_quantity'),
            'total_volume_m3' => $summaries->sum('total_volume_m3'),
            'moon_ore_value' => $summaries->sum('moon_ore_value'),
            'regular_ore_value' => $summaries->sum('regular_ore_value'),
            'ice_value' => $summaries->sum('ice_value'),
            'gas_value' => $summaries->sum('gas_value'),
        ];
        foreach (LedgerSummaryService::MOON_CLASSES as $class) {
            $totals["moon_{$class}_value"] = $summaries->sum("moon_{$class}_value");
        }

        // Each half of a biweekly month is its own bill, so the whole month
        // shows each half's tax, and the total is the two added together. A
        // finalized month's own tax figure was taken when the month closed,
        // and a day rebuilt after that, such as unbilled mining at a rate
        // changed since, moves the halves but not that figure.
        $halfTaxes = [];
        if ($half === 'month' && $halves && $scope !== LedgerSummaryService::SCOPE_MOONS) {
            $shownIds = $this->summaryCharacterIds($summaries);
            foreach ($halves as [$start, $end]) {
                $figures = $this->summaryService->periodTax($shownIds, $start, $end, $filter);
                $halfTaxes[] = [
                    'label' => $periodHelper->formatPeriod($start, $end, 'biweekly'),
                    'tax' => array_sum(array_column($figures, 'tax')),
                ];
            }
            $totals['total_tax'] = array_sum(array_column($halfTaxes, 'tax'));
        }

        // Slices that read the ledger's ore flags say when the flags were
        // corrected, as the Performance Charts do, when the days shown reach
        // back before it.
        $periodStart = $period ? $period[0] : $monthDate;
        $classificationCutover = $filter->dependsOnClassification() ? ClassificationEpoch::get() : null;
        if ($classificationCutover && $periodStart->copy()->startOfDay()->gte($classificationCutover->copy()->startOfDay())) {
            $classificationCutover = null;
        }

        return view('mining-manager::ledger.summary', [
            'summaries' => $summaries,
            'totals' => $totals,
            'month' => $month,
            'monthDate' => $monthDate,
            'corporations' => $isDirector ? $this->summaryCorporations($monthDate, $scope, $corporationId) : collect(),
            'selectedCorporationId' => $corporationId,
            'isCurrentMonth' => $monthDate->isSameMonth(now()),
            'fromFinalized' => $this->summaryService->usesFinalizedSummaries($month, $scope, $period, $filter),
            'scope' => $scope,
            'moonClasses' => LedgerSummaryService::MOON_CLASSES,
            'groupByMain' => $groupByMain,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'isDirector' => $isDirector,
            'ore' => $ore,
            'filter' => $filter,
            'classificationCutover' => $classificationCutover,
            'biweekly' => $biweekly,
            'half' => $half,
            'periodNav' => $biweekly ? $this->summaryPeriodNav($request, $monthDate, $half, $halves, $periodHelper) : null,
            'halfTaxes' => $halfTaxes,
        ]);
    }

    /**
     * Every character a summary page shows, alts included.
     *
     * @param \Illuminate\Support\Collection $summaries
     * @return array<int, int>
     */
    protected function summaryCharacterIds($summaries): array
    {
        $ids = [];
        foreach ($summaries as $summary) {
            $ids[] = (int) $summary->character_id;
            foreach ($summary->alt_characters ?? [] as $alt) {
                $ids[] = (int) $alt->character_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The half of the month a biweekly page shows: the one asked for, or the
     * half we are in for this month and the whole month for one gone by. A
     * monthly install always shows the whole month.
     */
    protected function shownHalf(Request $request, Carbon $monthDate, bool $biweekly): string
    {
        $half = (string) $request->get('half', '');
        if (!$biweekly || !in_array($half, ['1', '2', 'month'], true)) {
            $half = $biweekly && $monthDate->isSameMonth(now()) ? (now()->day <= 14 ? '1' : '2') : 'month';
        }

        return $half;
    }

    /**
     * The summary's period switch, keeping the filters it was opened with.
     *
     * @param array $halves The month's two [start, end] periods
     */
    protected function summaryPeriodNav(Request $request, Carbon $monthDate, string $half, array $halves, TaxPeriodHelper $periodHelper): array
    {
        $keep = array_filter(
            $request->only(['scope', 'corporation_id', 'group_by_main', 'ore', 'sort_by', 'sort_dir']),
            fn ($value) => $value !== null
        );
        $link = fn (Carbon $month, string $h) => route('mining-manager.ledger.summary', $keep + [
            'month' => $month->format('Y-m'),
            'half' => $h,
        ]);

        return $this->periodNav($link, $monthDate, $half, $halves, $periodHelper);
    }

    /**
     * Links for a period switch: a step back or forward, by half while a half
     * is shown and by month while the whole month is, and the two halves and
     * whole month of the month shown. Nothing links to a period that has not
     * started.
     *
     * @param \Closure $link (Carbon $month, string $half) => url
     * @param array $halves The month's two [start, end] periods, none on a monthly install
     * @return array{label: string, prev: string|null, next: string|null, choices: array<int, array{label: string, url: string|null, active: bool}>}
     */
    protected function periodNav(\Closure $link, Carbon $monthDate, string $half, array $halves, TaxPeriodHelper $periodHelper): array
    {
        $started = fn (Carbon $start) => $start->copy()->startOfDay()->lte(now());

        $previousMonth = $monthDate->copy()->subMonthNoOverflow()->startOfMonth();
        $nextMonth = $monthDate->copy()->addMonthNoOverflow()->startOfMonth();

        if ($half === 'month') {
            $label = $monthDate->format('F Y');
            $prev = $link($previousMonth, 'month');
            $next = $started($nextMonth) ? $link($nextMonth, 'month') : null;
        } else {
            [$start, $end] = $halves[(int) $half - 1];
            $label = $periodHelper->formatPeriod($start, $end, 'biweekly');

            if ($half === '1') {
                $prev = $link($previousMonth, '2');
                $next = $started($halves[1][0]) ? $link($monthDate, '2') : null;
            } else {
                $prev = $link($monthDate, '1');
                $next = $started($nextMonth) ? $link($nextMonth, '1') : null;
            }
        }

        $choices = [];
        foreach ($halves as $index => [$start, $end]) {
            $choices[] = [
                'label' => $start->format('jS') . '-' . $end->format('jS'),
                'url' => $started($start) ? $link($monthDate, (string) ($index + 1)) : null,
                'active' => $half === (string) ($index + 1),
            ];
        }
        $choices[] = [
            'label' => trans('mining-manager::ledger.whole_month'),
            'url' => $link($monthDate, 'month'),
            'active' => $half === 'month',
        ];

        return compact('label', 'prev', 'next', 'choices');
    }

    /**
     * The corporations the summary's filter offers.
     *
     * All mining offers the corporations this install looks after. The
     * moons view offers the owners of refineries whose observers recorded
     * mining that month, and always the Moon Owner Corporation it opens on.
     * Names come from wherever the plugin knows them, so a corporation SeAT
     * never loaded still reads right.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function summaryCorporations(Carbon $monthDate, string $scope, ?int $selected)
    {
        if ($scope === LedgerSummaryService::SCOPE_MOONS) {
            $ids = MiningLedger::whereYear('date', $monthDate->year)
                ->whereMonth('date', $monthDate->month)
                ->whereNotNull('observer_id')
                ->whereNotNull('corporation_id')
                ->distinct()
                ->pluck('corporation_id')
                ->all();
            $ids[] = $this->settingsService->getTaxProgramCorporationId();
        } else {
            $ids = $this->settingsService->getHomeCorporationIds();
        }

        $ids[] = $selected;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return collect();
        }

        $names = app(\MiningManager\Services\Character\CharacterNames::class)->corporationNames($ids);
        $tickers = DB::table('corporation_infos')->whereIn('corporation_id', $ids)->pluck('ticker', 'corporation_id');

        return collect($ids)
            ->map(fn ($id) => (object) [
                'corporation_id' => $id,
                'name' => $names[$id] ?? "Corporation {$id}",
                'ticker' => $tickers[$id] ?? null,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Sort summaries by specified column and direction
     *
     * @param \Illuminate\Support\Collection $summaries
     * @param string $sortBy
     * @param string $sortDir
     * @return \Illuminate\Support\Collection
     */
    protected function sortSummaries($summaries, $sortBy, $sortDir)
    {
        $validColumns = ['character_name', 'total_quantity', 'total_value', 'corporation_name'];

        if (!in_array($sortBy, $validColumns)) {
            $sortBy = 'total_value';
        }

        if (!in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        switch ($sortBy) {
            case 'character_name':
                $summaries = $sortDir === 'asc'
                    ? $summaries->sortBy(function($s) {
                        return $s->character_info['name'] ?? $s->character_name;
                    })
                    : $summaries->sortByDesc(function($s) {
                        return $s->character_info['name'] ?? $s->character_name;
                    });
                break;

            case 'corporation_name':
                $summaries = $sortDir === 'asc'
                    ? $summaries->sortBy(function($s) {
                        return $s->character_info['corporation_name'] ?? 'Unknown';
                    })
                    : $summaries->sortByDesc(function($s) {
                        return $s->character_info['corporation_name'] ?? 'Unknown';
                    });
                break;

            case 'total_quantity':
            case 'total_value':
            default:
                $summaries = $sortDir === 'asc'
                    ? $summaries->sortBy($sortBy)
                    : $summaries->sortByDesc($sortBy);
                break;
        }

        return $summaries->values(); // Re-index the collection
    }

    /**
     * Show detailed mining ledger for a specific character
     *
     * @param Request $request
     * @param int $characterId
     * @return \Illuminate\View\View
     */
    public function showCharacterDetails(Request $request, $characterId)
    {
        // Authorization: members can only view their own characters, directors can view all
        if (!$this->canViewCharacter($characterId)) {
            abort(403, 'You do not have permission to view this character.');
        }

        $month = $request->get('month', now()->format('Y-m'));
        $sortBy = $request->get('sort_by', 'date'); // Default sort column
        $sortDir = $request->get('sort_dir', 'desc'); // Default sort direction
        $taxedOnly = $request->boolean('taxed');

        try {
            $monthDate = Carbon::parse($month)->startOfMonth();
        } catch (\Exception $e) {
            $monthDate = now()->startOfMonth();
        }
        $month = $monthDate->format('Y-m');

        // Get character information
        $characterInfo = $this->characterInfoService->getCharacterInfo($characterId);

        // A main brings its alts along unless the link asks for the one
        // character, as the summary's every-character view does.
        $includeAlts = $request->has('include_alts')
            ? $request->boolean('include_alts')
            : (bool) ($characterInfo['is_registered'] ?? false);

        // Determine which character IDs to include
        $characterIds = [$characterId];
        $altCharacters = collect();

        if ($includeAlts) {
            // Get user ID for this character
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->value('user_id');

            if ($userId) {
                // Check if this character is the main for this user
                $mainCharacterId = DB::table('users')
                    ->where('id', $userId)
                    ->value('main_character_id');

                // If this IS the main character, get all alts
                if ($mainCharacterId == $characterId) {
                    $altCharacterIds = DB::table('refresh_tokens')
                        ->where('user_id', $userId)
                        ->where('character_id', '!=', $characterId)
                        ->pluck('character_id')
                        ->toArray();

                    if (!empty($altCharacterIds)) {
                        $characterIds = array_merge($characterIds, $altCharacterIds);

                        // Get alt character info for display
                        $altCharacters = collect($this->characterInfoService->getBatchCharacterInfo($altCharacterIds))
                            ->values();
                    }
                }
            }
        }

        // Validate sort parameters
        $validColumns = ['date', 'quantity', 'total_value', 'tax', 'character_id'];
        if (!in_array($sortBy, $validColumns)) {
            $sortBy = 'date';
        }
        if (!in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        // A biweekly install bills each half of the month on its own, so the
        // page shows a half or the whole month, as the summary does.
        $periodHelper = app(TaxPeriodHelper::class);
        $biweekly = $periodHelper->periodTypeForCharacter((int) $characterId, $monthDate) === 'biweekly';
        $halves = $biweekly ? $periodHelper->getPeriodsInMonth($monthDate, 'biweekly') : [];
        $half = $this->shownHalf($request, $monthDate, $biweekly);
        [$from, $to] = $half === 'month'
            ? [$monthDate->copy()->startOfMonth(), $monthDate->copy()->endOfMonth()]
            : $halves[(int) $half - 1];

        // Every row of the period with its tax, so the taxed-only view, the
        // tax column's sort and the totals all see the whole period rather
        // than one page of it.
        $rows = MiningLedger::with(['solarSystem', 'type'])
            ->whereIn('character_id', $characterIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();
        $taxes = $this->summaryService->rowTaxes($rows);
        $shown = $taxedOnly ? $rows->filter(fn ($row) => $taxes[$row->id]['taxed']) : $rows;

        $sortValue = match ($sortBy) {
            'quantity' => fn ($row) => (int) $row->quantity,
            'total_value' => fn ($row) => (float) $row->total_value,
            'tax' => fn ($row) => $taxes[$row->id]['tax'],
            'character_id' => fn ($row) => mb_strtolower($row->character_name),
            default => fn ($row) => $row->date->toDateString(),
        };
        $sorted = $shown->sort(function ($a, $b) use ($sortValue, $sortDir) {
            $order = $sortValue($a) <=> $sortValue($b);

            return $order === 0 ? $b->id <=> $a->id : ($sortDir === 'asc' ? $order : -$order);
        })->values();

        $perPage = 50;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $entries = new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $totals = (object) [
            'total_quantity' => $shown->sum('quantity'),
            'total_volume_m3' => $shown->sum(fn ($row) => $row->quantity * ($row->type->volume ?? 0)),
            'total_value' => $shown->sum(fn ($row) => (float) $row->total_value),
            'unique_systems' => $shown->pluck('solar_system_id')->unique()->count(),
            'unique_ores' => $shown->pluck('type_id')->unique()->count(),
            // The daily summaries' own figure, which a billed day keeps at
            // what was billed.
            'total_tax' => (float) MiningLedgerDailySummary::whereIn('character_id', $characterIds)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->sum('total_tax'),
        ];

        // What was taxed, by kind of ore and rate, and what was not and why,
        // over the whole period whichever rows the table shows.
        $taxedGroups = [];
        $untaxedGroups = [];
        foreach ($rows as $row) {
            $figure = $taxes[$row->id];

            if ($figure['taxed']) {
                $key = $figure['class'] . '|' . $figure['rate'];
                $taxedGroups[$key] ??= ['class' => $figure['class'], 'rate' => $figure['rate'], 'value' => 0.0, 'tax' => 0.0, 'discount' => 0.0, 'ores' => []];
                $taxedGroups[$key]['value'] += (float) $row->total_value;
                $taxedGroups[$key]['tax'] += $figure['tax'];
                $taxedGroups[$key]['discount'] += $figure['discount'];
                $taxedGroups[$key]['ores'][$row->type_id] = $row->type_name;
            } else {
                $kind = str_starts_with($figure['class'], 'moon') ? 'moon' : $figure['class'];
                $key = $figure['reason'] . '|' . $kind;
                $untaxedGroups[$key] ??= ['reason' => $figure['reason'], 'kind' => $kind, 'value' => 0.0];
                $untaxedGroups[$key]['value'] += (float) $row->total_value;
            }
        }
        usort($taxedGroups, fn ($a, $b) => $b['tax'] <=> $a['tax']);
        usort($untaxedGroups, fn ($a, $b) => $b['value'] <=> $a['value']);
        $taxedTotal = array_sum(array_column($taxedGroups, 'tax'));

        // Links keep the alts choice and the sort; the period links keep the
        // taxed-only view as well.
        $keep = array_filter([
            'include_alts' => $request->has('include_alts') ? ($includeAlts ? '1' : '0') : null,
            'sort_by' => $request->has('sort_by') ? $sortBy : null,
            'sort_dir' => $request->has('sort_dir') ? $sortDir : null,
        ], fn ($value) => $value !== null);
        $link = fn (Carbon $linkMonth, string $linkHalf, bool $taxed) => route('mining-manager.ledger.character-details', [
            'characterId' => $characterId,
            'month' => $linkMonth->format('Y-m'),
        ] + ($biweekly ? ['half' => $linkHalf] : []) + ($taxed ? ['taxed' => '1'] : []) + $keep);
        $periodLink = fn (Carbon $linkMonth, string $linkHalf) => $link($linkMonth, $linkHalf, $taxedOnly);

        $thisMonth = now()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonthNoOverflow();
        $quickPeriods = [];
        if ($biweekly) {
            $thisHalf = now()->day <= 14 ? '1' : '2';
            $quickPeriods[] = [trans('mining-manager::ledger.this_period'), $thisMonth, $thisHalf];
            $quickPeriods[] = $thisHalf === '2'
                ? [trans('mining-manager::ledger.last_period'), $thisMonth, '1']
                : [trans('mining-manager::ledger.last_period'), $lastMonth, '2'];
        }
        $quickPeriods[] = [trans('mining-manager::ledger.month_this'), $thisMonth, 'month'];
        $quickPeriods[] = [trans('mining-manager::ledger.month_last'), $lastMonth, 'month'];
        $quickPeriods = array_map(fn ($quick) => [
            'label' => $quick[0],
            'url' => $periodLink($quick[1], $quick[2]),
            'active' => $quick[1]->isSameMonth($monthDate) && $quick[2] === $half,
        ], $quickPeriods);

        return view('mining-manager::ledger.character-details', [
            'characterId' => $characterId,
            'characterInfo' => $characterInfo,
            'entries' => $entries,
            'taxes' => $taxes,
            'totals' => $totals,
            'month' => $month,
            'monthDate' => $monthDate,
            'includeAlts' => count($characterIds) > 1,
            'altCharacters' => $altCharacters,
            'showingMultiple' => count($characterIds) > 1,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'taxedOnly' => $taxedOnly,
            'taxedLinks' => [
                'all' => $link($monthDate, $half, false),
                'taxed' => $link($monthDate, $half, true),
            ],
            'taxedGroups' => $taxedGroups,
            'untaxedGroups' => $untaxedGroups,
            'taxedTotal' => $taxedTotal,
            'biweekly' => $biweekly,
            'half' => $half,
            'periodNav' => $this->periodNav($periodLink, $monthDate, $half, $halves, $periodHelper),
            'quickPeriods' => $quickPeriods,
            'summaryUrl' => route('mining-manager.ledger.index', ['month' => $month] + ($biweekly ? ['half' => $half] : [])),
        ]);
    }

    /**
     * Get daily breakdown for a specific character and month (AJAX)
     *
     * @param Request $request
     * @param int $characterId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCharacterDailySummary(Request $request, $characterId)
    {
        // Authorization: members can only view their own characters, directors can view all
        if (!$this->canViewCharacter($characterId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $month = $request->get('month', now()->format('Y-m'));

        try {
            // Validate month format
            $monthDate = Carbon::parse($month)->startOfMonth();

            // Get daily summaries using the service
            $dailySummaries = $this->summaryService->getDailySummaries($characterId, $month);

            // Format the data for the response with system breakdown
            $formattedData = $dailySummaries->map(function ($summary) use ($characterId, $monthDate) {
                $date = $summary->date instanceof Carbon ? $summary->date : Carbon::parse($summary->date);

                // Get system breakdown for this date
                $systems = MiningLedger::with('solarSystem')
                    ->where('character_id', $characterId)
                    ->whereDate('date', $date)
                    ->select('solar_system_id', DB::raw('SUM(total_value) as system_value'), DB::raw('SUM(quantity) as system_quantity'))
                    ->groupBy('solar_system_id')
                    ->orderByDesc('system_value')
                    ->get()
                    ->map(function($sys) {
                        return [
                            'system_id' => $sys->solar_system_id,
                            'system_name' => $sys->solarSystem->name ?? 'Unknown System',
                            'value' => number_format($sys->system_value, 2),
                            'quantity' => number_format($sys->system_quantity, 2),
                        ];
                    });

                return [
                    'date' => $date->format('Y-m-d'),
                    'total_quantity' => number_format($summary->total_quantity, 2),
                    'total_value' => number_format($summary->total_value, 2),
                    'total_tax' => number_format($summary->total_tax, 2),
                    'moon_ore_value' => number_format($summary->moon_ore_value, 2),
                    'regular_ore_value' => number_format($summary->regular_ore_value, 2),
                    'ice_value' => number_format($summary->ice_value, 2),
                    'gas_value' => number_format($summary->gas_value, 2),
                    'is_finalized' => $summary->is_finalized ?? false,
                    'ore_breakdown' => $summary->ore_types ?? [],
                    'systems' => $systems,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load daily summaries: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed mining entries for a specific character and date (AJAX)
     *
     * @param Request $request
     * @param int $characterId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDetailedEntries(Request $request, $characterId)
    {
        // Authorization: members can only view their own characters, directors can view all
        if (!$this->canViewCharacter($characterId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $date = $request->get('date');

        try {
            // Validate date format
            $dateCarbon = Carbon::parse($date);

            // Get detailed entries for the day
            $entries = MiningLedger::with(['solarSystem', 'type'])
                ->where('character_id', $characterId)
                ->whereDate('date', $dateCarbon)
                ->orderBy('date', 'desc')
                ->get();

            // Format the data for the response
            $formattedData = $entries->map(function ($entry) {
                return [
                    'date' => $entry->date->format('Y-m-d H:i:s'),
                    'quantity' => number_format($entry->quantity, 2),
                    'total_value' => number_format($entry->total_value, 2),
                    'tax_amount' => number_format($entry->tax_amount, 2),
                    'ore_type' => $entry->ore_type,
                    'type_name' => $entry->type->typeName ?? 'Unknown',
                    'system_name' => $entry->solarSystem->name ?? 'Unknown',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load detailed entries: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get character mining details in a specific system (AJAX)
     *
     * @param Request $request
     * @param int $characterId
     * @param int $systemId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCharacterSystemDetails(Request $request, $characterId, $systemId)
    {
        // Authorization: members can only view their own characters, directors can view all
        if (!$this->canViewCharacter($characterId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $month = $request->get('month', now()->format('Y-m'));

        try {
            // Get system details using the service
            $entries = $this->summaryService->getCharacterSystemDetails($characterId, $month, $systemId);

            // Format the data for the response
            $formattedData = $entries->map(function ($entry) {
                return [
                    'date' => $entry->date->format('Y-m-d H:i:s'),
                    'quantity' => number_format($entry->quantity, 2),
                    'total_value' => number_format($entry->total_value, 2),
                    'tax_amount' => number_format($entry->tax_amount, 2),
                    'ore_type' => $entry->ore_type,
                    'type_name' => $entry->type->typeName ?? 'Unknown',
                    'type_id' => $entry->type_id,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
                'system_name' => $entries->first()->solarSystem->name ?? 'Unknown System',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load system details: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the reprocessing calculator page.
     *
     * @return \Illuminate\View\View
     */
    public function reprocessingCalculator()
    {
        // Get cached mineral prices (all refined materials)
        $allMaterialIds = TypeIdRegistry::getAllRefinedMaterials();
        $mineralPrices = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->get()
            ->keyBy('type_id')
            ->map(function ($cache) {
                return [
                    'type_id' => $cache->type_id,
                    'price' => (float) $cache->getConfiguredPrice(),
                ];
            })
            ->toArray();

        // Get price cache age info
        $oldestCache = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->orderBy('cached_at', 'asc')
            ->first();
        $newestCache = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->orderBy('cached_at', 'desc')
            ->first();

        $cacheInfo = [
            'oldest_cached_at' => $oldestCache ? $oldestCache->cached_at->toIso8601String() : null,
            'newest_cached_at' => $newestCache ? $newestCache->cached_at->toIso8601String() : null,
            'oldest_age_minutes' => $oldestCache ? (int) $oldestCache->cached_at->diffInMinutes(now()) : null,
            'is_stale' => $oldestCache ? !$oldestCache->isFresh() : true,
        ];

        return view('mining-manager::ledger.reprocessing', compact('mineralPrices', 'cacheInfo'));
    }

    /**
     * Calculate reprocessing output for given ores.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function calculateReprocessing(Request $request)
    {
        $request->validate([
            'ores' => 'required|array|min:1',
            'ores.*.type_name' => 'required|string',
            'ores.*.quantity' => 'required|integer|min:1',
            'yield_percent' => 'required|numeric|min:50|max:100',
        ]);

        $ores = $request->input('ores');
        $yieldPercent = $request->input('yield_percent');
        $yieldFraction = $yieldPercent / 100;

        // Get all refined material prices
        $allMaterialIds = TypeIdRegistry::getAllRefinedMaterials();
        $mineralPrices = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->get()
            ->mapWithKeys(function ($cache) {
                return [$cache->type_id => (float) $cache->getConfiguredPrice()];
            })
            ->toArray();

        // Resolve ore names to type IDs and portionSize via invTypes table
        $oreNames = collect($ores)->pluck('type_name')->unique()->toArray();
        $oreTypeData = DB::table('invTypes')
            ->whereIn('typeName', $oreNames)
            ->select('typeID', 'typeName', 'portionSize')
            ->get()
            ->keyBy('typeName');
        $oreNameToId = $oreTypeData->pluck('typeID', 'typeName')->toArray();

        $mineralTotals = [];   // mineralTypeId => total quantity
        $oreResults = [];
        $unresolved = [];      // names invTypes did not recognise
        $totalOreValue = 0;

        foreach ($ores as $ore) {
            $typeName = $ore['type_name'];
            $quantity = (int) $ore['quantity'];

            $typeId = $oreNameToId[$typeName] ?? null;
            if ($typeId === null) {
                // Dropping this quietly is how a stale SDE looked like an ore
                // being worthless: the row vanished from the result and nothing
                // said why. Hand the name back so the page can say it did not
                // recognise it.
                if (! in_array($typeName, $unresolved, true)) {
                    $unresolved[] = $typeName;
                }

                continue;
            }

            // Determine if compressed
            $isCompressed = TypeIdRegistry::isCompressedOre($typeId);

            // Determine category
            $category = 'regular';
            if (TypeIdRegistry::isMoonOre($typeId)) {
                $category = 'moon';
            } elseif (TypeIdRegistry::isIce($typeId)) {
                $category = 'ice';
            } elseif (TypeIdRegistry::isGas($typeId)) {
                $category = 'gas';
            }

            if ($isCompressed) {
                $category = 'compressed ' . $category;
            }

            // Get ore price from cache for ore value
            $orePrice = MiningPriceCache::where('type_id', $typeId)->first();
            $orePricePerUnit = $orePrice ? (float) $orePrice->getConfiguredPrice() : 0;
            $oreValue = $orePricePerUnit * $quantity;
            $totalOreValue += $oreValue;

            // Get reprocessing minerals
            $minerals = ReprocessingRegistry::getMineralsWithDetails($typeId);
            $oreMineralOutput = [];
            $hasReprocessingData = !empty($minerals);

            if ($minerals) {
                // Use portionSize from SDE invTypes table (batch size for reprocessing)
                $portionSize = isset($oreTypeData[$typeName]) ? (int) $oreTypeData[$typeName]->portionSize : 100;
                $batchCount = floor($quantity / $portionSize);
                foreach ($minerals as $mineral) {
                    $mineralQty = floor($mineral['quantity'] * $yieldFraction * $batchCount);
                    if ($mineralQty > 0) {
                        $mineralTypeId = $mineral['type_id'];
                        $mineralTotals[$mineralTypeId] = ($mineralTotals[$mineralTypeId] ?? 0) + $mineralQty;
                        $oreMineralOutput[] = [
                            'name' => $mineral['name'],
                            'quantity' => $mineralQty,
                        ];
                    }
                }
            }

            $oreResults[] = [
                'type_name' => $typeName,
                'type_id' => $typeId,
                'quantity' => $quantity,
                'category' => $category,
                'ore_value' => $oreValue,
                'minerals' => $oreMineralOutput,
                'has_reprocessing_data' => $hasReprocessingData,
            ];
        }

        // Build minerals output with prices
        $mineralsOutput = [];
        $totalMineralValue = 0;
        $totalItems = 0;

        // Get mineral names from DB for any we have
        $mineralNames = DB::table('invTypes')
            ->whereIn('typeID', array_keys($mineralTotals))
            ->pluck('typeName', 'typeID')
            ->toArray();

        foreach ($mineralTotals as $mineralTypeId => $qty) {
            $price = $mineralPrices[$mineralTypeId] ?? 0;
            $value = $price * $qty;
            $totalMineralValue += $value;
            $totalItems += $qty;

            $mineralsOutput[] = [
                'type_id' => $mineralTypeId,
                'name' => $mineralNames[$mineralTypeId] ?? "Unknown ({$mineralTypeId})",
                'quantity' => $qty,
                'price_per_unit' => $price,
                'total_value' => $value,
            ];
        }

        // Sort minerals by value descending
        usort($mineralsOutput, function ($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });

        // Get cache age for the response
        $cacheAges = MiningPriceCache::whereIn('type_id', $allMaterialIds)
            ->select('type_id', 'cached_at')
            ->get()
            ->mapWithKeys(fn($c) => [$c->type_id => $c->cached_at->diffInMinutes(now())]);

        $oldestAge = $cacheAges->max();
        $pricingSettings = $this->settingsService->getPricingSettings();

        return response()->json([
            'success' => true,
            'summary' => [
                'total_ore_value' => $totalOreValue,
                'total_mineral_value' => $totalMineralValue,
                'total_items' => $totalItems,
                'yield_used' => $yieldPercent,
                'price_cache_age_minutes' => $oldestAge ?? null,
                'price_cache_stale' => ($oldestAge ?? 999) > ($pricingSettings['cache_duration'] ?? 240),
            ],
            'minerals' => $mineralsOutput,
            'ores' => $oreResults,
            'unresolved' => $unresolved,
        ]);
    }
}
