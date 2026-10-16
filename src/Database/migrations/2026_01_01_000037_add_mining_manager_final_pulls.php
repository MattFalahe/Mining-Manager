<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A planned pull can be marked as a refinery's final one, for a relocation or
 * an unanchor. Nothing is planned after it, and the planner stops reminding
 * anyone to plan or restart that refinery until it is resumed.
 *
 *  moon_extraction_plans.is_final
 *  moon_extraction_plans.final_marked_by   character who marked it
 *  moon_extraction_plans.final_marked_at
 *
 * All additive. Every existing pull stays an ordinary one.
 */
class AddMiningManagerFinalPulls extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('moon_extraction_plans')) {
            return;
        }

        if (!Schema::hasColumn('moon_extraction_plans', 'is_final')) {
            Schema::table('moon_extraction_plans', function (Blueprint $table) {
                $table->boolean('is_final')->default(false);
            });
        }

        if (!Schema::hasColumn('moon_extraction_plans', 'final_marked_by')) {
            Schema::table('moon_extraction_plans', function (Blueprint $table) {
                $table->unsignedBigInteger('final_marked_by')->nullable();
            });
        }

        if (!Schema::hasColumn('moon_extraction_plans', 'final_marked_at')) {
            Schema::table('moon_extraction_plans', function (Blueprint $table) {
                $table->timestamp('final_marked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('moon_extraction_plans')) {
            return;
        }

        foreach (['final_marked_at', 'final_marked_by', 'is_final'] as $column) {
            if (Schema::hasColumn('moon_extraction_plans', $column)) {
                Schema::table('moon_extraction_plans', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
}
