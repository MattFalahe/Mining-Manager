<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MonthlyStatistic;
use MiningManager\Http\Controllers\DashboardController;
use Seat\Web\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Calculate and store monthly statistics for closed months
 *
 * This command pre-calculates dashboard statistics and stores them in
 * the monthly_statistics table. For closed months this eliminates
 * recalculating historical data on every page load. For the current
 * month (--current-month), it reads from pre-computed daily summaries
 * for fast aggregation aligned with the 30-minute ESI data cycle.
 */
class CalculateMonthlyStatisticsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:calculate-monthly-stats
                            {--month= : Month to calculate (YYYY-MM format, defaults to last month)}
                            {--user_id= : Calculate for specific user}
                            {--recalculate : Recalculate existing statistics}
                            {--current-month : Calculate current month from daily summaries (fast, for frequent cron)}
                            {--all-history : Calculate all historical closed months}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-calculate and store monthly dashboard statistics for closed months';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check feature flag
        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $features = $settingsService->getFeatureFlags();
        if (!($features['enable_analytics'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $this->info('🚀 Starting monthly statistics calculation...');

        if ($this->option('all-history')) {
            return $this->calculateAllHistory();
        }

        // Current month mode: fast path using pre-computed daily summaries
        if ($this->option('current-month')) {
            $month = Carbon::now();
        } elseif ($this->option('month')) {
            $month = Carbon::createFromFormat('Y-m', $this->option('month'));
        } else {
            $month = Carbon::now()->subMonth();
        }

        $userId = $this->option('user_id');

        $this->info("📅 Calculating statistics for: {$month->format('F Y')}");

        $userQuery = $userId ? User::where('id', $userId) : User::query();
        $totalUsers = $userQuery->count();
        $this->info("👥 Processing {$totalUsers} user(s)...\n");

        $bar = $this->output->createProgressBar($totalUsers);
        $bar->start();

        $calculated = 0;
        $skipped = 0;
        $errors = 0;

        $userQuery->chunk(100, function ($users) use ($month, &$calculated, &$skipped, &$errors, $bar) {
            foreach ($users as $user) {
                try {
                    $result = $this->calculateForUser($user, $month);
                    if ($result === 'calculated') {
                        $calculated++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    Log::error("Failed to calculate monthly stats for user {$user->id}: " . $e->getMessage());
                    $this->error("\n❌ Error for user {$user->id}: " . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();

        $this->newLine(2);
        $this->info("✅ Calculation complete!");
        $this->info("   📊 Calculated: {$calculated}");
        $this->info("   ⏭️  Skipped: {$skipped}");
        if ($errors > 0) {
            $this->error("   ❌ Errors: {$errors}");
        }

        // Clear dashboard cache
        DashboardController::clearDashboardCache();
        $this->info("\n🔄 Dashboard cache cleared!");

        return Command::SUCCESS;
    }

    /**
     * Calculate statistics for a specific user and month
     */
    protected function calculateForUser(User $user, Carbon $month)
    {
        $year = $month->year;
        $monthNum = $month->month;
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $isClosed = $monthEnd->lt(Carbon::now()->startOfMonth());

        // Get user's character IDs
        $characterIds = $user->characters->pluck('character_id')->toArray();

        if (empty($characterIds)) {
            return 'skipped';
        }

        $isCurrentMonth = $this->option('current-month');

        // Check if already calculated (unless recalculate or current-month flag is set)
        // Current month always recalculates since data changes every 30 min
        if (!$this->option('recalculate') && !$isCurrentMonth) {
            $exists = MonthlyStatistic::where('user_id', $user->id)
                ->where('character_id', null)
                ->where('corporation_id', null)
                ->where('year', $year)
                ->where('month', $monthNum)
                ->exists();

            if ($exists) {
                return 'skipped';
            }
        }

        // Calculate statistics — use fast daily summary path for current month
        $stats = $isCurrentMonth
            ? $this->calculateStatsFromDailySummaries($characterIds, $monthStart, $monthEnd)
            : $this->calculateStats($characterIds, $monthStart, $monthEnd);

        // Only store if there's actual mining activity
        if ($stats['total_value'] == 0 && $stats['total_quantity'] == 0) {
            return 'skipped';
        }

        // Store in database
        MonthlyStatistic::updateOrCreate(
            [
                'user_id' => $user->id,
                'character_id' => null,
                'corporation_id' => null,
                'year' => $year,
                'month' => $monthNum,
            ],
            [
                'month_start' => $monthStart,
                'month_end' => $monthEnd,
                'is_closed' => $isClosed,
                'total_quantity' => $stats['total_quantity'],
                'total_value' => $stats['total_value'],
                'ore_value' => $stats['ore_value'],
                'mineral_value' => $stats['mineral_value'],
                'tax_owed' => $stats['tax_owed'],
                'tax_paid' => $stats['tax_paid'],
                'tax_pending' => $stats['tax_pending'],
                'tax_overdue' => $stats['tax_overdue'],
                'moon_ore_value' => $stats['moon_ore_value'],
                'ice_value' => $stats['ice_value'],
                'gas_value' => $stats['gas_value'],
                'regular_ore_value' => $stats['regular_ore_value'],
                'mining_days' => $stats['mining_days'],
                'total_days' => $monthStart->daysInMonth,
                'daily_chart_data' => $stats['daily_chart_data'],
                'ore_type_chart_data' => $stats['ore_type_chart_data'],
                'value_breakdown_chart_data' => $stats['value_breakdown_chart_data'],
                'top_miners' => $stats['top_miners'],
                'top_systems' => $stats['top_systems'],
                'calculated_at' => now(),
            ]
        );

        return 'calculated';
    }

    /**
     * Calculate statistics from mining ledger data
     */
    protected function calculateStats(array $characterIds, Carbon $monthStart, Carbon $monthEnd)
    {
        // Base query
        $ledgerQuery = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->whereNotNull('processed_at');

        // Total statistics
        $totals = $ledgerQuery->selectRaw('
            SUM(quantity) as total_quantity,
            SUM(total_value) as total_value,
            SUM(ore_value) as ore_value,
            SUM(mineral_value) as mineral_value,
            COUNT(DISTINCT DATE(date)) as mining_days
        ')->first();

        // Ore type breakdown
        $oreTypes = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->whereNotNull('processed_at')
            ->selectRaw('
                SUM(CASE WHEN is_moon_ore = 1 THEN total_value ELSE 0 END) as moon_ore_value,
                SUM(CASE WHEN is_ice = 1 THEN total_value ELSE 0 END) as ice_value,
                SUM(CASE WHEN is_gas = 1 THEN total_value ELSE 0 END) as gas_value,
                SUM(CASE WHEN is_moon_ore = 0 AND is_ice = 0 AND is_gas = 0 THEN total_value ELSE 0 END) as regular_ore_value
            ')
            ->first();

        // Tax statistics
        $taxes = MiningTax::whereIn('character_id', $characterIds)
            ->where('month', $monthStart)
            ->selectRaw('
                SUM(amount_owed) as tax_owed,
                SUM(CASE WHEN status = "paid" THEN amount_owed ELSE 0 END) as tax_paid,
                SUM(CASE WHEN status = "pending" THEN amount_owed ELSE 0 END) as tax_pending,
                SUM(CASE WHEN status = "overdue" THEN amount_owed ELSE 0 END) as tax_overdue
            ')
            ->first();

        // Daily chart data
        $dailyData = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->whereNotNull('processed_at')
            ->selectRaw('DATE(date) as day, SUM(total_value) as value')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->pluck('value', 'day')
            ->toArray();

        // Ore type chart data
        $oreTypeData = [
            'moon_ore' => $oreTypes->moon_ore_value ?? 0,
            'ice' => $oreTypes->ice_value ?? 0,
            'gas' => $oreTypes->gas_value ?? 0,
            'regular' => $oreTypes->regular_ore_value ?? 0,
        ];

        // Top systems
        $topSystems = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->whereNotNull('processed_at')
            ->selectRaw('solar_system_id, SUM(total_value) as value')
            ->groupBy('solar_system_id')
            ->orderByDesc('value')
            ->limit(5)
            ->get()
            ->toArray();

        return [
            'total_quantity' => $totals->total_quantity ?? 0,
            'total_value' => $totals->total_value ?? 0,
            'ore_value' => $totals->ore_value ?? 0,
            'mineral_value' => $totals->mineral_value ?? 0,
            'tax_owed' => $taxes->tax_owed ?? 0,
            'tax_paid' => $taxes->tax_paid ?? 0,
            'tax_pending' => $taxes->tax_pending ?? 0,
            'tax_overdue' => $taxes->tax_overdue ?? 0,
            'moon_ore_value' => $oreTypes->moon_ore_value ?? 0,
            'ice_value' => $oreTypes->ice_value ?? 0,
            'gas_value' => $oreTypes->gas_value ?? 0,
            'regular_ore_value' => $oreTypes->regular_ore_value ?? 0,
            'mining_days' => $totals->mining_days ?? 0,
            'daily_chart_data' => $dailyData,
            'ore_type_chart_data' => $oreTypeData,
            'value_breakdown_chart_data' => [
                'ore' => $totals->ore_value ?? 0,
                'mineral' => $totals->mineral_value ?? 0,
            ],
            'top_miners' => [],
            'top_systems' => $topSystems,
        ];
    }

    /**
     * Fast path: Calculate statistics from pre-computed daily summaries.
     *
     * Used for current month updates every 30 minutes. Instead of querying
     * the raw mining_ledger table (thousands of rows), this reads from
     * mining_ledger_daily_summaries (one row per character per day).
     */
    protected function calculateStatsFromDailySummaries(array $characterIds, Carbon $monthStart, Carbon $monthEnd)
    {
        // Totals from daily summaries — single aggregate query
        $totals = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->selectRaw('
                SUM(total_quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(moon_ore_value) as moon_ore_value,
                SUM(regular_ore_value) as regular_ore_value,
                SUM(ice_value) as ice_value,
                SUM(gas_value) as gas_value,
                SUM(total_tax) as total_tax,
                COUNT(DISTINCT date) as mining_days
            ')
            ->first();

        // Tax statistics (still from mining_taxes — not in daily summaries)
        $taxes = MiningTax::whereIn('character_id', $characterIds)
            ->where('month', $monthStart)
            ->selectRaw('
                SUM(amount_owed) as tax_owed,
                SUM(CASE WHEN status = "paid" THEN amount_owed ELSE 0 END) as tax_paid,
                SUM(CASE WHEN status = "pending" THEN amount_owed ELSE 0 END) as tax_pending,
                SUM(CASE WHEN status = "overdue" THEN amount_owed ELSE 0 END) as tax_overdue
            ')
            ->first();

        // Daily chart data from daily summaries — one row per day already
        $dailyData = MiningLedgerDailySummary::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->selectRaw('date as day, SUM(total_value) as value')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->pluck('value', 'day')
            ->toArray();

        $totalValue = $totals->total_value ?? 0;
        $moonOreValue = $totals->moon_ore_value ?? 0;
        $iceValue = $totals->ice_value ?? 0;
        $gasValue = $totals->gas_value ?? 0;
        $regularOreValue = $totals->regular_ore_value ?? 0;

        // Ore type chart data
        $oreTypeData = [
            'moon_ore' => $moonOreValue,
            'ice' => $iceValue,
            'gas' => $gasValue,
            'regular' => $regularOreValue,
        ];

        // Top systems from daily summaries — not directly available, use raw table with GROUP BY
        $topSystems = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->whereNotNull('processed_at')
            ->selectRaw('solar_system_id, SUM(total_value) as value')
            ->groupBy('solar_system_id')
            ->orderByDesc('value')
            ->limit(5)
            ->get()
            ->toArray();

        return [
            'total_quantity' => $totals->total_quantity ?? 0,
            'total_value' => $totalValue,
            'ore_value' => $totalValue, // ore_value approximated as total_value for current month
            'mineral_value' => 0, // mineral reprocessing value not tracked in daily summaries
            'tax_owed' => $taxes->tax_owed ?? 0,
            'tax_paid' => $taxes->tax_paid ?? 0,
            'tax_pending' => $taxes->tax_pending ?? 0,
            'tax_overdue' => $taxes->tax_overdue ?? 0,
            'moon_ore_value' => $moonOreValue,
            'ice_value' => $iceValue,
            'gas_value' => $gasValue,
            'regular_ore_value' => $regularOreValue,
            'mining_days' => $totals->mining_days ?? 0,
            'daily_chart_data' => $dailyData,
            'ore_type_chart_data' => $oreTypeData,
            'value_breakdown_chart_data' => [
                'ore' => $totalValue,
                'mineral' => 0,
            ],
            'top_miners' => [],
            'top_systems' => $topSystems,
        ];
    }

    /**
     * Calculate all historical closed months
     */
    protected function calculateAllHistory()
    {
        $this->info('📚 Calculating ALL historical closed months...');

        // Find earliest mining ledger entry
        $earliest = MiningLedger::whereNotNull('processed_at')
            ->orderBy('date')
            ->first();

        if (!$earliest) {
            $this->warn('No mining ledger data found!');
            return Command::SUCCESS;
        }

        $startMonth = Carbon::parse($earliest->date)->startOfMonth();
        $currentMonth = Carbon::now()->startOfMonth();
        $month = $startMonth->copy();

        $months = [];
        while ($month->lt($currentMonth)) {
            $months[] = $month->copy();
            $month->addMonth();
        }

        $this->info("Found " . count($months) . " historical months to process");
        $this->info("From: {$startMonth->format('F Y')} to: {$currentMonth->subMonth()->format('F Y')}\n");

        foreach ($months as $month) {
            $this->info("Processing {$month->format('F Y')}...");
            $this->call('mining-manager:calculate-monthly-stats', [
                '--month' => $month->format('Y-m'),
                '--recalculate' => $this->option('recalculate'),
            ]);
            $this->newLine();
        }

        return Command::SUCCESS;
    }
}
