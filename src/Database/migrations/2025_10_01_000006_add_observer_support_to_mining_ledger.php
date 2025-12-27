<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add observer support to mining_ledger table
        Schema::table('mining_ledger', function (Blueprint $table) {
            // Add observer_id to track which structure the mining occurred at
            $table->bigInteger('observer_id')->unsigned()->nullable()->after('solar_system_id');
            
            // Add index for performance
            $table->index('observer_id');
            
            // Update unique constraint to include observer_id
            // This ensures we don't duplicate entries from the same observer
            $table->unique(['character_id', 'date', 'type_id', 'observer_id'], 'unique_mining_entry');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropUnique('unique_mining_entry');
            $table->dropColumn('observer_id');
        });
    }
};
