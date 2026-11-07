<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Services\Ledger\LedgerSummaryService;
use Carbon\Carbon;

class FinalizeMonthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:finalize-month {month? : The month to finalize in YYYY-MM format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finalize mining ledger summaries for a specific month';

    /**
     * Ledger summary service
     *
     * @var LedgerSummaryService
     */
    protected $summaryService;

    /**
     * Create a new command instance.
     *
     * @param LedgerSummaryService $summaryService
     */
    public function __construct(LedgerSummaryService $summaryService)
    {
        parent::__construct();
        $this->summaryService = $summaryService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lock = Cache::lock('mining-manager:finalize-month', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return self::SUCCESS;
        }

        try {
        // Get month from argument or default to previous month
        $month = $this->argument('month');

        if (!$month) {
            $month = now()->subMonth()->format('Y-m');
            $this->info("No month specified, defaulting to previous month: {$month}");
        }

        // Validate month format
        try {
            $monthDate = Carbon::parse($month);
        } catch (\Exception $e) {
            $this->error("Invalid month format. Use YYYY-MM format (e.g., 2025-01)");
            return 1;
        }

        // Don't finalize current or future months
        if ($monthDate->isSameMonth(now()) || $monthDate->isFuture()) {
            $this->error("Cannot finalize current or future months");
            return 1;
        }

        $this->info("Finalizing summaries for {$month}...");

        // Each character is finalized in a transaction of its own, so the
        // importer is never kept waiting on the whole month.
        $stats = $this->summaryService->finalizeMonth($month);

        // A rebuilt day of a past month is finalized already. This marks the
        // rest, such as a day whose mining has since gone from the ledger.
        $finalizedCount = MiningLedgerDailySummary::forMonth(Carbon::parse($month)->startOfMonth())
            ->where('is_finalized', false)
            ->update(['is_finalized' => true]);

        if ($finalizedCount > 0) {
            $this->info("Marked {$finalizedCount} daily summaries as finalized.");
        }

        // Display results
        $this->info("Finalization complete!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Characters Processed', $stats['characters_processed']],
                ['Monthly Summaries', $stats['monthly_summaries']],
                ['Daily Summaries', $stats['daily_summaries']],
                ['Errors', count($stats['errors'])],
            ]
        );

        if (!empty($stats['errors'])) {
            $this->error("Errors occurred during finalization:");
            foreach ($stats['errors'] as $error) {
                $this->error("  - {$error}");
            }
            return 1;
        }

        return 0;
        } finally {
            $lock->release();
        }
    }
}
