<?php

namespace MiningManager\Database\Seeders;

use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
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
            // Update moon extractions - runs every 6 hours to refresh moon data
            [
                'command' => 'mining-manager:update-extractions',
                'expression' => '0 */6 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Update mining events - runs every 2 hours
            [
                'command' => 'mining-manager:update-events',
                'expression' => '0 */2 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Calculate mining taxes - runs daily at 2:15 AM
            // Smart: checks shouldCalculateToday() and only acts on period boundaries
            // (monthly=1st, biweekly=1st&15th, weekly=Mondays). Skips other days.
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
            // Verify wallet payments - runs every 6 hours
            [
                'command' => 'mining-manager:verify-payments',
                'expression' => '0 */6 * * *',
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
            // Generate reports - runs daily at 4 AM
            [
                'command' => 'mining-manager:generate-reports',
                'expression' => '0 4 * * *',
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
            // Archive old extractions - runs daily at 5 AM
            // (Archives completed extractions older than 7 days, keeps 12 months)
            [
                'command' => 'mining-manager:archive-extractions',
                'expression' => '0 5 * * *',
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
            // Monitor active thefts - runs every 6 hours
            // (Fast check: only monitors characters already on theft list)
            [
                'command' => 'mining-manager:monitor-active-thefts --notify',
                'expression' => '0 */6 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Finalize month summaries - runs daily at 2:00 AM (before tax calculation)
            // Locks previous month's daily summaries so taxes are calculated from final data.
            // Safe daily: only acts on previous month, re-finalizing already-finalized data is a no-op.
            [
                'command' => 'mining-manager:finalize-month',
                'expression' => '0 2 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Calculate monthly statistics - runs daily at 3:00 AM (after taxes + invoices)
            // Pre-calculates dashboard stats for closed months. Safe daily: skips already-calculated months.
            [
                'command' => 'mining-manager:calculate-monthly-stats',
                'expression' => '0 3 * * *',
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
            // Detect jackpots - runs daily at 6 AM
            // (Analyzes recent moon extractions to identify high-value jackpot ores)
            [
                'command' => 'mining-manager:detect-jackpots',
                'expression' => '0 6 * * *',
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
