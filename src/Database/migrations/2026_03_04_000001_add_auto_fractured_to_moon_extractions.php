<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->boolean('auto_fractured')->default(false)->after('has_notification_data');
        });

        Schema::table('moon_extraction_history', function (Blueprint $table) {
            $table->boolean('auto_fractured')->default(false)->after('is_jackpot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->dropColumn('auto_fractured');
        });

        Schema::table('moon_extraction_history', function (Blueprint $table) {
            $table->dropColumn('auto_fractured');
        });
    }
};
