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
            // Process mining ledger - runs every 30 minutes at :15 and :45 to keep data fresh
            [
                'command' => 'mining-manager:process-ledger',
                'expression' => '15,45 * * * *',
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
            // Calculate monthly taxes - runs daily at 2 AM to update running totals
            // (Keeps dashboard current with month-to-date tax obligations)
            [
                'command' => 'mining-manager:calculate-taxes',
                'expression' => '0 2 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Generate tax invoices - runs on 1st of each month at 3 AM
            [
                'command' => 'mining-manager:generate-invoices',
                'expression' => '0 3 1 * *',
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
            // Send tax reminders - runs on 25th of each month at 10 AM
            // (Reminds miners about upcoming payment due on 1st)
            [
                'command' => 'mining-manager:send-reminders',
                'expression' => '0 10 25 * *',
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
                'command' => 'mining-manager:detect-theft',
                'expression' => '0 1 1,15 * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Monitor active thefts - runs every 6 hours
            // (Fast check: only monitors characters already on theft list)
            [
                'command' => 'mining-manager:monitor-active-thefts',
                'expression' => '0 */6 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Finalize month summaries - runs on 2nd of each month at 3 AM
            // (Creates pre-calculated summaries for previous month to improve ledger performance)
            [
                'command' => 'mining-manager:finalize-month',
                'expression' => '0 3 2 * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Calculate monthly statistics - runs on 2nd of each month at 4 AM
            // (Pre-calculates dashboard statistics for closed months, runs after finalization)
            [
                'command' => 'mining-manager:calculate-monthly-stats',
                'expression' => '0 4 2 * *',
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
            // Scan corporation contracts for tax payments - runs every 30 minutes
            // (Matches manually-created contracts with tax codes in title to mark taxes as paid)
            [
                'command' => 'mining-manager:scan-corporation-contracts',
                'expression' => '5,35 * * * *',
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
            // Add any old/deprecated command names here if you rename commands
            // Example: 'mining-manager:old-command-name',
        ];
    }
}
