<?php

namespace MiningManager\Database\Seeders;

use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    /**
     * Returns the schedule definitions for the Mining Manager plugin.
     *
     * @return array
     */
    public function getSchedules(): array
    {
        return [
            // Process mining ledger data - runs hourly at :05 past
            [
                'command' => 'mining-manager:process-ledger',
                'expression' => '5 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // Update active mining events - runs hourly at :15 past
            [
                'command' => 'mining-manager:update-events',
                'expression' => '15 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // Update moon extractions - runs every 30 minutes
            [
                'command' => 'mining-manager:update-extractions',
                'expression' => '*/30 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // Cache market prices - runs every 15 minutes
            [
                'command' => 'mining-manager:cache-prices',
                'expression' => '*/15 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // Verify wallet payments - runs hourly at :35 past
            [
                'command' => 'mining-manager:verify-payments',
                'expression' => '35 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // Calculate monthly taxes - runs on the 1st of each month at 02:00
            [
                'command' => 'mining-manager:calculate-taxes',
                'expression' => '0 2 1 * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // Generate tax invoices - runs on the 2nd of each month at 03:00
            [
                'command' => 'mining-manager:generate-invoices',
                'expression' => '0 3 2 * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // Send tax reminders - runs daily at 10:00 AM
            [
                'command' => 'mining-manager:send-reminders',
                'expression' => '0 10 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // Generate reports - runs daily at 04:00 AM
            [
                'command' => 'mining-manager:generate-reports',
                'expression' => '0 4 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
        ];
    }

    /**
     * Returns a list of commands to remove from the schedule.
     *
     * @return array
     */
    public function getDeprecatedSchedules(): array
    {
        return [
            // Add deprecated command names here if you rename commands in future updates
            // Example: 'old-command-name',
        ];
    }
}
