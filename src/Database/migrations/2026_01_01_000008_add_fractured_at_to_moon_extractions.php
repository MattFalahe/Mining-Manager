<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->timestamp('fractured_at')->nullable()->after('auto_fractured');
            $table->string('fractured_by', 255)->nullable()->after('fractured_at');
        });

        // Also add to history table if it exists
        if (Schema::hasTable('moon_extraction_history')) {
            Schema::table('moon_extraction_history', function (Blueprint $table) {
                if (!Schema::hasColumn('moon_extraction_history', 'fractured_at')) {
                    $table->timestamp('fractured_at')->nullable()->after('auto_fractured');
                    $table->string('fractured_by', 255)->nullable()->after('fractured_at');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->dropColumn(['fractured_at', 'fractured_by']);
        });

        if (Schema::hasTable('moon_extraction_history')) {
            Schema::table('moon_extraction_history', function (Blueprint $table) {
                if (Schema::hasColumn('moon_extraction_history', 'fractured_at')) {
                    $table->dropColumn(['fractured_at', 'fractured_by']);
                }
            });
        }
    }
};
