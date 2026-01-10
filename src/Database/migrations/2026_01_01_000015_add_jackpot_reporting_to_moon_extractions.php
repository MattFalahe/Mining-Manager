<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddJackpotReportingToMoonExtractions extends Migration
{
    public function up(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->unsignedBigInteger('jackpot_reported_by')->nullable()->after('jackpot_detected_at');
            $table->boolean('jackpot_verified')->nullable()->after('jackpot_reported_by');
            $table->timestamp('jackpot_verified_at')->nullable()->after('jackpot_verified');
        });
    }

    public function down(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->dropColumn(['jackpot_reported_by', 'jackpot_verified', 'jackpot_verified_at']);
        });
    }
}
