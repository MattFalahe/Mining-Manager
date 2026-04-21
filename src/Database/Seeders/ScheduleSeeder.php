<?php

namespace MiningManager\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    /**
     * Override parent run() to update existing schedule expressions.
     * AbstractScheduleSeeder only inserts new commands and skips existing ones,
     * so changed cron expressions never get applied.
     */
    public function run(): void
    {
        foreach ($this->getSchedules() as $job) {
            DB::table('schedules')->updateOrInsert(
                ['command' => $job['command']],
                $job
            );
        }

        // Remove deprecated commands
        $deprecated = $this->getDeprecatedSchedules();
        if (! empty($deprecated)) {
            DB::table('schedules')->whereIn('command', $deprecated)->delete();
        }
    }

    /**
     * Returns a list of schedules to be added to the schedule table.
     *
     * @return array
     */
    public function getSchedules(): array
    {
        return [
            // Process corporation observer mining - runs every 30 minutes at :15 and :45
            [
                'command' => 'mining-manager:process-ledger',
                'expression' => '15,45 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Import character mining from SeAT ESI cache - runs every 30 minutes at :20 and :50
            // Safety net: Queue::after hook handles real-time import, this catches any missed entries
            [
                'command' => 'mining-manager:import-character-mining --days=2',
                'expression' => '20,50 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Update moon extractions - runs every 2 hours to catch late fracture notifications
            [
                'command' => 'mining-manager:update-extractions',
                'expression' => '0 */2 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Fire moon_arrival notifications based on stored chunk_arrival_time.
            // Runs every minute — notifications arrive within ~60s of actual
            // chunk arrival, independent of ESI refresh timing. Idempotent via
            // the notification_sent flag on moon_extractions.
            [
                'command' => 'mining-manager:check-extraction-arrivals',
                'expression' => '* * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Update mining events — runs EVERY MINUTE.
            // Status transitions (planned→active→completed) need to fire close
            // to the configured start/end times so Discord notifications arrive
            // promptly. Participant tracking is cheap for small event counts
            // (simple ledger query filtered by time + location). allow_overlap=false
            // prevents concurrent runs if a tick takes longer than 60s.
            [
                'command' => 'mining-manager:update-events',
                'expression' => '* * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Calculate mining taxes - runs daily at 2:15 AM
            // Smart: checks shouldCalculateToday() and only acts on period boundaries
            // Shifted +1 day to allow observer data to settle (ESI lags 12-24h):
            // (monthly=2nd, biweekly=2nd&16th, weekly=Tuesdays). Skips other days.
            // Pipeline: ledger-prices (1:00) → daily-summaries (1:30) → finalize (2:00) → taxes (2:15)
            [
                'command' => 'mining-manager:calculate-taxes',
                'expression' => '15 2 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Generate tax invoices - runs daily at 2:30 AM (after tax calculation)
            // Smart: only creates invoices for completed periods that don't have one yet
            [
                'command' => 'mining-manager:generate-invoices',
                'expression' => '30 2 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Verify wallet payments - runs every 6 hours (staggered +5min after update-extractions)
            [
                'command' => 'mining-manager:verify-payments --auto-match',
                'expression' => '5 */6 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Send tax reminders - runs daily at 10 AM
            // (Command checks send_tax_reminders and tax_reminder_days settings to determine if reminders are needed)
            [
                'command' => 'mining-manager:send-reminders',
                'expression' => '0 10 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Generate previous month's report — runs day 9 at 04:05 AM.
            // Day 9 = ~7 days after `finalize-month` (day 2) and `calculate-monthly-stats` (day 2),
            // giving miners time to pay invoices so the collection % in the report is meaningful.
            // For ad-hoc/user-defined report cadences, use `report_schedules` (handled by cron below).
            [
                'command' => 'mining-manager:generate-reports',
                'expression' => '5 4 9 * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Process scheduled reports - runs every hour to check for due schedules
            [
                'command' => 'mining-manager:generate-reports --scheduled',
                'expression' => '0 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Cache price data - runs every 4 hours at :30 past
            // (Reduces API load on providers like Janice while keeping prices reasonably fresh)
            [
                'command' => 'mining-manager:cache-prices',
                'expression' => '30 */4 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Recalculate extraction values - runs twice daily at 6 AM and 6 PM
            // (Updates values 4 hours before chunk arrival with current market prices)
            [
                'command' => 'mining-manager:recalculate-extraction-values',
                'expression' => '0 6,18 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Archive old extractions - runs daily at 5:05 AM (staggered)
            // (Archives completed extractions older than 7 days, keeps 12 months)
            [
                'command' => 'mining-manager:archive-extractions',
                'expression' => '5 5 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Detect moon theft - runs twice monthly (1st and 15th at 1 AM)
            // (Full scan: manages theft list, adds/removes characters based on tax status)
            [
                'command' => 'mining-manager:detect-theft --notify',
                'expression' => '0 1 1,15 * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Monitor active thefts - runs every 6 hours (staggered +10min after update-extractions)
            // (Fast check: only monitors characters already on theft list)
            [
                'command' => 'mining-manager:monitor-active-thefts --notify',
                'expression' => '10 */6 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Finalize month summaries - runs on 2nd of each month at 2:00 AM (before tax calculation)
            // Locks previous month's daily summaries so the tax run uses finalized data.
            // Runs on 2nd (not 1st) to allow observer data to settle before finalizing.
            [
                'command' => 'mining-manager:finalize-month',
                'expression' => '0 2 2 * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Calculate monthly statistics - runs on 2nd of each month at 3:00 AM (after taxes + invoices)
            // Pre-calculates dashboard stats for the now-closed previous month.
            [
                'command' => 'mining-manager:calculate-monthly-stats',
                'expression' => '0 3 2 * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Update ledger prices - runs daily at 1 AM (after cache-prices at 0:30)
            // Locks in daily session prices for today's mining entries
            [
                'command' => 'mining-manager:update-ledger-prices',
                'expression' => '0 1 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Update daily summaries (safety net) - runs daily at 1:30 AM (before finalize + taxes)
            // Catches non-observer mining (belt mining, etc.) and any late ESI data.
            // Must run before finalize-month and calculate-taxes so they work from complete data.
            [
                'command' => 'mining-manager:update-daily-summaries',
                'expression' => '30 1 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Update current month dashboard statistics - runs every 30 min
            // Reads from pre-computed daily summaries (fast) and updates monthly_statistics
            // Pipeline: :15/:45 process-ledger (+ auto daily summaries) → :20/:50 monthly stats
            [
                'command' => 'mining-manager:calculate-monthly-stats --current-month',
                'expression' => '20,50 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Detect jackpots - runs daily at 6:05 AM (staggered after recalculate-extraction-values)
            // (Analyzes recent moon extractions to identify high-value jackpot ores)
            [
                'command' => 'mining-manager:detect-jackpots',
                'expression' => '5 6 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
        ];
    }

    /**
     * Returns a list of commands to remove from the schedule.
     * This is useful for cleanup when commands are renamed or deprecated.
     *
     * @return array
     */
    public function getDeprecatedSchedules(): array
    {
        return [
            'mining-manager:scan-corporation-contracts', // Removed: contract-based taxing replaced by wallet transfers
            'mining-manager:update-daily-summaries --today-only', // Removed: process-ledger now auto-generates daily summaries
        ];
    }
}
