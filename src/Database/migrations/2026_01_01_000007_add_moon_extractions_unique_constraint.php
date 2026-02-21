<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds unique constraint on moon_extractions to prevent duplicate extraction
     * records for the same structure and extraction start time.
     */
    public function up(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->unique(
                ['structure_id', 'extraction_start_time'],
                'moon_extractions_structure_start_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->dropUnique('moon_extractions_structure_start_unique');
        });
    }
};
