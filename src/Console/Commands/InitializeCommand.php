<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InitializeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:initialize
                            {--skip-checks : Skip settings verification prompts}
                            {--skip-backfill : Skip historical data backfill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize Mining Manager — guided setup wizard for first-time installation';

    /**
     * Track overall progress
     */
    private int $currentStep = 0;
    private int $totalSteps = 0;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════════════════╗');
        $this->line('║                                                              ║');
        $this->line('║              ⛏️  Mining Manager — Initial Setup               ║');
        $this->line('║                                                              ║');
        $this->line('║   This wizard will guide you through setting up Mining        ║');
        $this->line('║   Manager and populating your data for the first time.        ║');
        $this->line('║                                                              ║');
        $this->line('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // ─────────────────────────────────────────────────────
        // PHASE 1: Settings Verification
        // ─────────────────────────────────────────────────────
        if (!$this->option('skip-checks')) {
            if (!$this->verifySettings()) {
                return Command::FAILURE;
            }
        } else {
            $this->warn('Skipping settings verification (--skip-checks).');
        }

        // ─────────────────────────────────────────────────────
        // PHASE 2: Current Month Setup
        // ─────────────────────────────────────────────────────
        $this->newLine();
        $this->displayPhaseHeader('PHASE 2: Current Month Data', 'Building your current month data — this is the essential setup.');

        $this->runCurrentMonthSetup();

        // ─────────────────────────────────────────────────────
        // PHASE 3: Historical Backfill (Optional)
        // ─────────────────────────────────────────────────────
        if (!$this->option('skip-backfill')) {
            $this->newLine();
            $this->displayPhaseHeader('PHASE 3: Historical Data (Optional)', 'Backfill older mining data for reports and analytics.');

            $this->runHistoricalBackfill();
        } else {
            $this->warn('Skipping historical backfill (--skip-backfill).');
        }

        // ─────────────────────────────────────────────────────
        // DONE
        // ─────────────────────────────────────────────────────
        $this->newLine(2);
        $this->line('╔══════════════════════════════════════════════════════════════╗');
        $this->line('║                                                              ║');
        $this->line('║              ✅  Mining Manager — Setup Complete!             ║');
        $this->line('║                                                              ║');
        $this->line('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->info('Next steps:');
        $this->line('  1. Open SeAT and navigate to Mining Manager from the sidebar');
        $this->line('  2. Check Settings > General to verify your Moon Owner Corporation');
        $this->line('  3. Check Settings > Tax Rates to configure per-corporation tax rates');
        $this->line('  4. Set up webhooks in Settings > Webhooks for Discord/Slack notifications');
        $this->line('  5. The scheduled tasks will keep everything updated automatically');
        $this->newLine();
        $this->line('  For help, visit the built-in Help & Documentation page in Settings.');
        $this->newLine();

        return Command::SUCCESS;
    }

    /**
     * Phase 1: Verify essential settings are configured
     */
    private function verifySettings(): bool
    {
        $this->displayPhaseHeader('PHASE 1: Settings Verification', 'Let\'s make sure your essential settings are configured before we populate data.');

        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);

        $issues = [];

        // Check 1: Moon Owner Corporation
        $this->newLine();
        $this->info('  [1/4] Checking Moon Owner Corporation...');
        $moonOwnerCorpId = $settingsService->getSetting('general.moon_owner_corporation_id');
        if ($moonOwnerCorpId) {
            $corpName = DB::table('corporation_infos')->where('corporation_id', $moonOwnerCorpId)->value('name') ?? 'Unknown';
            $this->line("         ✅ Set to: {$corpName} ({$moonOwnerCorpId})");
        } else {
            $this->error('         ❌ Not configured!');
            $issues[] = 'Moon Owner Corporation is not set. Go to Settings > General and select your corporation.';
        }

        // Check 2: Price Provider
        $this->info('  [2/4] Checking Price Provider...');
        $priceProvider = $settingsService->getSetting('price_provider', 'eve_market');
        $this->line("         ✅ Using: {$priceProvider}");

        // Check price cache
        $cachedPrices = DB::table('mining_manager_price_cache')->count();
        if ($cachedPrices > 0) {
            $this->line("         ✅ Price cache has {$cachedPrices} entries");
        } else {
            $this->warn('         ⚠️  Price cache is empty — will be populated during setup');
        }

        // Check 3: Tax Rates
        $this->info('  [3/4] Checking Tax Rates...');
        $configuredCorps = DB::table('mining_manager_settings')
            ->whereNotNull('corporation_id')
            ->distinct()
            ->pluck('corporation_id');

        if ($configuredCorps->isNotEmpty()) {
            foreach ($configuredCorps as $corpId) {
                $corpName = DB::table('corporation_infos')->where('corporation_id', $corpId)->value('name') ?? "Corp #{$corpId}";
                $settingsService->setActiveCorporation((int) $corpId);
                $r64 = $settingsService->getSetting('tax_rates.moon_ore.r64', 0);
                $this->line("         ✅ {$corpName}: R64={$r64}%");
            }
            $settingsService->setActiveCorporation(null);
        } else {
            // Check global defaults
            $r64 = $settingsService->getSetting('tax_rates.moon_ore.r64', 0);
            if ($r64 > 0) {
                $this->line("         ✅ Global defaults configured (R64={$r64}%)");
            } else {
                $this->warn('         ⚠️  No tax rates configured yet. You can set them in Settings > Tax Rates.');
                $issues[] = 'Tax rates are at 0% — set your rates in Settings > Tax Rates before calculating taxes.';
            }
        }

        // Check 4: ESI Data
        $this->info('  [4/4] Checking available ESI data...');
        $observerCount = DB::table('corporation_industry_mining_observers')->count();
        $characterCount = DB::table('character_minings')->count();
        $this->line("         Mining observers: {$observerCount} entries");
        $this->line("         Character mining: {$characterCount} entries");

        if ($observerCount === 0 && $characterCount === 0) {
            $this->warn('         ⚠️  No mining data found in SeAT. Make sure ESI jobs have run first.');
            $issues[] = 'No ESI mining data found. Ensure SeAT has pulled corporation observer and character mining data.';
        }

        // Show issues summary
        if (!empty($issues)) {
            $this->newLine();
            $this->warn('  ⚠️  Issues found:');
            foreach ($issues as $i => $issue) {
                $this->warn('     ' . ($i + 1) . '. ' . $issue);
            }
            $this->newLine();

            // Critical: Moon Owner Corp must be set
            if (!$moonOwnerCorpId) {
                $this->error('  Moon Owner Corporation must be configured before running setup.');
                $this->error('  Go to SeAT > Mining Manager > Settings > General and set it, then run this command again.');
                return false;
            }

            if (!$this->confirm('Continue with setup despite the warnings above?', true)) {
                $this->info('Setup cancelled. Fix the issues above and run this command again.');
                return false;
            }
        } else {
            $this->newLine();
            $this->info('  ✅ All settings look good!');
        }

        return true;
    }

    /**
     * Phase 2: Set up current month data
     */
    private function runCurrentMonthSetup(): void
    {
        $currentMonth = Carbon::now()->format('Y-m');
        $this->info("  Setting up data for current month: {$currentMonth}");
        $this->newLine();

        $steps = [
            ['Cache market prices', 'mining-manager:cache-prices', ['--force' => true]],
            ['Process corporation observer mining data', 'mining-manager:process-ledger', ['--days' => 35]],
            ['Import character mining data', 'mining-manager:import-character-mining', ['--days' => 35]],
            ['Backfill ore type flags', 'mining-manager:backfill-ore-types', []],
            ['Update mining entry prices', 'mining-manager:update-ledger-prices', ['--days' => 35]],
            ['Generate daily summaries', 'mining-manager:update-daily-summaries', ['--month' => $currentMonth]],
            ['Update moon extractions', 'mining-manager:update-extractions', []],
            ['Detect jackpot extractions', 'mining-manager:detect-jackpots', ['--days' => 35]],
            ['Calculate dashboard statistics', 'mining-manager:calculate-monthly-stats', ['--current-month' => true]],
        ];

        // Note: Tax records (MiningTax) and tax codes are NOT generated here.
        // The admin should review data first, then use Calculate Taxes in the UI.

        $this->totalSteps = count($steps);

        foreach ($steps as $index => $step) {
            $this->runStep($index + 1, $step[0], $step[1], $step[2]);
        }

        $this->newLine();
        $this->info('  ✅ Current month setup complete!');
    }

    /**
     * Phase 3: Optional historical data backfill
     */
    private function runHistoricalBackfill(): void
    {
        // Count how much data is available
        $oldestObserver = DB::table('corporation_industry_mining_observers')
            ->orderBy('last_updated', 'asc')
            ->value('last_updated');
        $oldestCharacter = DB::table('character_minings')
            ->orderBy('date', 'asc')
            ->value('date');

        $oldest = null;
        if ($oldestObserver) {
            $oldest = Carbon::parse($oldestObserver);
        }
        if ($oldestCharacter) {
            $charDate = Carbon::parse($oldestCharacter);
            if (!$oldest || $charDate->lt($oldest)) {
                $oldest = $charDate;
            }
        }

        if (!$oldest) {
            $this->warn('  No historical ESI data found to backfill.');
            return;
        }

        $monthsAvailable = (int) Carbon::now()->startOfMonth()->diffInMonths($oldest->startOfMonth());
        $this->info("  ESI data goes back approximately {$monthsAvailable} months (oldest: {$oldest->format('Y-m-d')}).");
        $this->newLine();

        $this->warn('  ╔═══════════════════════════════════════════════════════════╗');
        $this->warn('  ║  Historical backfill is for DISPLAY PURPOSES ONLY.       ║');
        $this->warn('  ║  Past tax calculations may not reflect the rates that     ║');
        $this->warn('  ║  were active at the time. Only current month taxes are    ║');
        $this->warn('  ║  accurate. Processing time depends on data volume and     ║');
        $this->warn('  ║  server performance — large datasets may take 10-30 min.  ║');
        $this->warn('  ╚═══════════════════════════════════════════════════════════╝');
        $this->newLine();

        if (!$this->confirm('Would you like to backfill historical data?', false)) {
            $this->info('  Skipping historical backfill.');
            return;
        }

        $choices = [
            '3 months',
            '6 months',
            '12 months',
            'All available data (' . $monthsAvailable . ' months)',
        ];

        $choice = $this->choice('How far back would you like to backfill?', $choices, 0);

        $months = match ($choice) {
            '3 months' => 3,
            '6 months' => 6,
            '12 months' => 12,
            default => $monthsAvailable,
        };

        // Cap at available data
        $months = min($months, max($monthsAvailable, 1));
        $days = $months * 31;

        $this->newLine();
        $this->info("  Backfilling {$months} months of historical data...");
        $this->warn('  This may take a while depending on data volume. Please be patient.');
        $this->newLine();

        $steps = [
            ['Process historical observer data', 'mining-manager:process-ledger', ['--days' => $days, '--recalculate' => true]],
            ['Import historical character mining', 'mining-manager:import-character-mining', ['--days' => $days]],
            ['Update historical prices', 'mining-manager:update-ledger-prices', ['--all-unpriced' => true]],
        ];

        // Generate summaries for each historical month
        $startMonth = Carbon::now()->subMonths($months)->startOfMonth();
        $currentMonth = Carbon::now()->startOfMonth();

        $monthCursor = $startMonth->copy();
        while ($monthCursor->lt($currentMonth)) {
            $monthStr = $monthCursor->format('Y-m');
            $steps[] = ["Generate summaries for {$monthStr}", 'mining-manager:update-daily-summaries', ['--month' => $monthStr]];
            $monthCursor->addMonth();
        }

        // Stats and finalization
        $steps[] = ['Calculate historical statistics', 'mining-manager:calculate-monthly-stats', ['--all-history' => true]];
        $steps[] = ['Detect historical jackpots', 'mining-manager:detect-jackpots', ['--all' => true]];
        $steps[] = ['Backfill extraction notification data', 'mining-manager:backfill-extraction-notifications', ['--days' => $days]];

        $this->totalSteps = count($steps);

        foreach ($steps as $index => $step) {
            $this->runStep($index + 1, $step[0], $step[1], $step[2]);
        }

        $this->newLine();
        $this->info("  ✅ Historical backfill complete! ({$months} months)");
    }

    /**
     * Run a single command step with progress display
     */
    private function runStep(int $step, string $description, string $command, array $options = []): void
    {
        $this->line("  [{$step}/{$this->totalSteps}] {$description}...");

        $startTime = microtime(true);

        try {
            $exitCode = Artisan::call($command, $options);
            $elapsed = round(microtime(true) - $startTime, 1);

            if ($exitCode === 0) {
                $this->line("         ✅ Done ({$elapsed}s)");
            } else {
                $this->warn("         ⚠️  Completed with warnings ({$elapsed}s)");
            }
        } catch (\Exception $e) {
            $elapsed = round(microtime(true) - $startTime, 1);
            $this->error("         ❌ Failed ({$elapsed}s): {$e->getMessage()}");
        }
    }

    /**
     * Display a phase header
     */
    private function displayPhaseHeader(string $title, string $description): void
    {
        $this->newLine();
        $this->line('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("  {$title}");
        $this->line("  {$description}");
        $this->line('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}
