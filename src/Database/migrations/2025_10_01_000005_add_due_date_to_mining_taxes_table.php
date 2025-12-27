<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            // Add due_date column after paid_at
            $table->date('due_date')->nullable()->after('paid_at');
        });

        // Populate due_date for existing records
        // Set due date to the 15th of the month following the tax month
        DB::statement("
            UPDATE mining_taxes 
            SET due_date = DATE_ADD(month, INTERVAL 15 DAY)
            WHERE due_date IS NULL
        ");

        // Alternative: Set due date to end of the month following the tax month
        // DB::statement("
        //     UPDATE mining_taxes 
        //     SET due_date = LAST_DAY(DATE_ADD(month, INTERVAL 1 MONTH))
        //     WHERE due_date IS NULL
        // ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });
    }
};
