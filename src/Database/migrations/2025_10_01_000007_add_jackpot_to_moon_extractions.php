<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddJackpotToMoonExtractions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            // Add jackpot detection columns if they don't exist
            if (!Schema::hasColumn('moon_extractions', 'is_jackpot')) {
                $table->boolean('is_jackpot')->default(false)->after('ore_composition');
            }
            
            if (!Schema::hasColumn('moon_extractions', 'jackpot_detected_at')) {
                $table->timestamp('jackpot_detected_at')->nullable()->after('is_jackpot');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->dropColumn(['is_jackpot', 'jackpot_detected_at']);
        });
    }
}
