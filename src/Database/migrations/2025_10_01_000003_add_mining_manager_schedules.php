<?php

use Illuminate\Database\Migrations\Migration;
use Seat\Services\Models\Schedule;

class AddMiningManagerSchedules extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // Process mining ledger - runs every 4 hours to sync mining data
        Schedule::firstOrCreate(
            ['command' => 'mining-manager:process-ledger'],
            [
                'expression'        => '0 */4 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Update moon extractions - runs every 6 hours to refresh moon data
        Schedule::firstOrCreate(
            ['command' => 'mining-manager:update-extractions'],
            [
                'expression'        => '0 */6 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Update mining events - runs every 2 hours
        Schedule::firstOrCreate(
            ['command' => 'mining-manager:update-events'],
            [
                'expression'        => '0 */2 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Calculate monthly taxes - runs daily at 2 AM to update running totals
        // This keeps the dashboard current with month-to-date tax obligations
        Schedule::firstOrCreate(
            ['command' => 'mining-manager:calculate-taxes'],
            [
                'expression'        => '0 2 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Generate tax invoices - runs on 1st of each month at 3 AM
        // Creates invoices for the previous month's mining activity
        Schedule::firstOrCreate(
            ['command' => 'mining-manager:generate-invoices'],
            [
                'expression'        => '0 3 1 * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Verify wallet payments - runs every 6 hours
        Schedule::firstOrCreate(
            ['command' => 'mining-manager:verify-payments'],
            [
                'expression'        => '0 */6 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Send tax reminders - runs on 25th of each month at 10 AM
        // Reminds miners about upcoming payment due on the 1st
        Schedule::firstOrCreate(
            ['command' => 'mining-manager:send-reminders'],
            [
                'expression'        => '0 10 25 * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Generate reports - runs daily at 4 AM
        Schedule::firstOrCreate(
            ['command' => 'mining-manager:generate-reports'],
            [
                'expression'        => '0 4 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Cache price data - runs every hour at :30 past
        Schedule::firstOrCreate(
            ['command' => 'mining-manager:cache-prices'],
            [
                'expression'        => '30 * * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schedule::where('command', 'mining-manager:process-ledger')->delete();
        Schedule::where('command', 'mining-manager:update-extractions')->delete();
        Schedule::where('command', 'mining-manager:update-events')->delete();
        Schedule::where('command', 'mining-manager:calculate-taxes')->delete();
        Schedule::where('command', 'mining-manager:generate-invoices')->delete();
        Schedule::where('command', 'mining-manager:verify-payments')->delete();
        Schedule::where('command', 'mining-manager:send-reminders')->delete();
        Schedule::where('command', 'mining-manager:generate-reports')->delete();
        Schedule::where('command', 'mining-manager:cache-prices')->delete();
    }
}
