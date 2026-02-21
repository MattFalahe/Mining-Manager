<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds unique constraints to prevent duplicate records in mining_taxes
     * and event_participants tables.
     */
    public function up(): void
    {
        // Prevent duplicate monthly tax records per character
        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->unique(['character_id', 'month'], 'mining_taxes_char_month_unique');
        });

        // Prevent duplicate event participation records
        Schema::table('event_participants', function (Blueprint $table) {
            $table->unique(['event_id', 'character_id'], 'event_participants_event_char_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->dropUnique('mining_taxes_char_month_unique');
        });

        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropUnique('event_participants_event_char_unique');
        });
    }
};
