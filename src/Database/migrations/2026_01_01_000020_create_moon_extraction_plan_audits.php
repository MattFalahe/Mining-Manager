<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for the Moon Extraction Planner — "who did what".
 *
 * One row per operator action on a planned pull (create / move / delete) plus
 * a single aggregate row per auto-fill run. plan_id is nullable because a plan
 * can be deleted after the fact; structure_id / moon_id are denormalised so
 * the history stays readable even once the plan row is gone.
 *
 * Actor is stored as character_id + a denormalised character_name captured at
 * write time, so the history renders without joining live character tables
 * (and survives a character being removed from SeAT).
 */
class CreateMoonExtractionPlanAudits extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moon_extraction_plan_audits')) {
            return;
        }

        Schema::create('moon_extraction_plan_audits', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('structure_id')->nullable();
            $table->unsignedBigInteger('moon_id')->nullable();

            // created | moved | deleted | autofilled
            $table->string('action', 20);

            // Actor — main character of the acting user. Null for system runs.
            $table->unsignedBigInteger('character_id')->nullable();
            $table->string('character_name')->nullable();

            // Before/after times for a move (both null for create/delete/autofill).
            $table->timestamp('old_arrival')->nullable();
            $table->timestamp('new_arrival')->nullable();

            // Free-text detail (moon/structure label, "12 pulls" for auto-fill, etc.).
            $table->string('detail', 500)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['corporation_id', 'created_at'], 'idx_mepa_corp_created');
            $table->index('plan_id', 'idx_mepa_plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moon_extraction_plan_audits');
    }
}
