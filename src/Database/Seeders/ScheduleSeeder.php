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
            // Process mining ledger - runs every 4 hours to sync mining data
            [
                'command' => 'mining-manager:process-ledger',
                'expression' => '0 */4 * * *',
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
            // Cache price data - runs every hour at :30 past
            [
                'command' => 'mining-manager:cache-prices',
                'expression' => '30 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Recalculate extraction values - runs every 2 hours
            // (Updates values 2-3h before chunk arrival with current market prices)
            [
                'command' => 'mining-manager:recalculate-extraction-values --hours=3',
                'expression' => '0 */2 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            // Archive old extractions - runs daily at 5 AM
            // (Archives completed extractions older than 7 days, keeps 12 months)
            [
                'command' => 'mining-manager:archive-extractions --days=7 --keep-months=12',
                'expression' => '0 5 * * *',
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
