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
        Schema::table('mining_manager_moon_extractions', function (Blueprint $table) {
            $table->boolean('is_jackpot')->default(false)->after('chunk_arrival_time');
            $table->index('is_jackpot'); // Add index for filtering jackpot extractions
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mining_manager_moon_extractions', function (Blueprint $table) {
            $table->dropIndex(['is_jackpot']);
            $table->dropColumn('is_jackpot');
        });
    }
};
