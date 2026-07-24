<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moon Extraction Planner — the corp-internal coordination layer.
 *
 * An Athanor/Tatara is anchored at one fixed moon and SeAT can only READ
 * the extractions a director fires in-game; nothing here reaches into the
 * game. The planner is an intent/coordination tool: a director (or anyone
 * with the new `mining-manager.moon_manager` ability) lays each refinery's
 * projected next pull onto a calendar so a small crew can STAGGER arrivals
 * instead of having eight chunks land the same evening (chunks not mined
 * promptly are wasted — the real risk for smaller corps).
 *
 * Three new pieces of schema, all additive + defaulted (no backfill):
 *
 *  1. moon_extraction_plans
 *     One row per planned pull. `planned_arrival_time` is the source of
 *     truth — auto-fill SEEDS it from history cadence, the moon manager can
 *     re-anchor it (drag Monday → Tuesday), and the NEXT projection chains
 *     off the shifted anchor so the move sticks going forward. Once a real
 *     ESI extraction shows up matching the slot, the plan is reconciled
 *     (status=confirmed, linked_extraction_id + variance_hours recorded).
 *
 *  2. moon_extractions.extraction_started_sent / next_planned_sent
 *     Idempotency latches for the two new notifications, matching the
 *     existing notification_sent / unstable_warning_sent pattern. Individual
 *     booleans (not a JSON meta column) for atomic CAS writes between
 *     concurrent queue workers.
 *
 *  3. webhook_configurations.notify_extraction_started /
 *     notify_next_extraction_planned
 *     Two per-webhook opt-in toggles, off by default so existing installs
 *     don't suddenly start emitting new notification types.
 */
class CreateMoonExtractionPlans extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('moon_extraction_plans')) {
            Schema::create('moon_extraction_plans', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('corporation_id');
                // The refinery (Athanor/Tatara) this pull is planned for.
                $table->unsignedBigInteger('structure_id');
                // The moon the refinery is anchored at — denormalised for
                // display + cadence grouping. Nullable because a freshly
                // anchored structure may not have a resolved moon_id yet.
                $table->unsignedBigInteger('moon_id')->nullable();

                // Source of truth for WHEN the pull is planned to arrive.
                // Seeded by auto-fill from history cadence; the moon manager
                // can move it freely. Subsequent projections chain off this.
                $table->timestamp('planned_arrival_time');

                // The cadence (in days) used when this slot was projected —
                // informational, shown in the planner so the operator can
                // see "every ~7 days" vs a one-off manual placement.
                $table->unsignedSmallInteger('cadence_days')->nullable();

                // manual = placed/moved by hand, auto = projected from history.
                $table->string('source', 16)->default('manual');

                // planned   — intended, no matching ESI extraction yet
                // confirmed — reconciled to a real extraction (see linked_extraction_id)
                // superseded — replaced by a newer plan for the same structure
                // done      — the linked extraction has completed/archived
                // cancelled — operator removed the intent
                $table->string('status', 16)->default('planned');

                // Set when reconciled to an actual MoonExtraction row.
                $table->unsignedBigInteger('linked_extraction_id')->nullable();
                // planned_arrival_time − actual chunk_arrival_time, in hours
                // (signed). Lets the planner show projection accuracy.
                $table->integer('variance_hours')->nullable();

                // character_id of the moon manager who created/last touched it.
                $table->unsignedBigInteger('created_by')->nullable();

                $table->text('notes')->nullable();

                $table->timestamps();

                // Planner reads are "all plans for this corp this month,
                // ordered by planned_arrival_time" and "next planned slot for
                // this structure" — index to serve both.
                $table->index(['corporation_id', 'planned_arrival_time'], 'idx_mep_corp_arrival');
                $table->index(['structure_id', 'planned_arrival_time'], 'idx_mep_struct_arrival');
                $table->index('status', 'idx_mep_status');
                $table->index('linked_extraction_id', 'idx_mep_linked');
            });
        }

        if (Schema::hasTable('moon_extractions')) {
            Schema::table('moon_extractions', function (Blueprint $table) {
                if (!Schema::hasColumn('moon_extractions', 'extraction_started_sent')) {
                    $table->boolean('extraction_started_sent')->default(false)->after('notification_sent');
                }
                if (!Schema::hasColumn('moon_extractions', 'next_planned_sent')) {
                    $table->boolean('next_planned_sent')->default(false)->after('extraction_started_sent');
                }
            });
        }

        if (Schema::hasTable('webhook_configurations')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                if (!Schema::hasColumn('webhook_configurations', 'notify_extraction_started')) {
                    $table->boolean('notify_extraction_started')->default(false)->after('notify_moon_chunk_unstable');
                }
                if (!Schema::hasColumn('webhook_configurations', 'notify_next_extraction_planned')) {
                    $table->boolean('notify_next_extraction_planned')->default(false)->after('notify_extraction_started');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('moon_extractions')) {
            Schema::table('moon_extractions', function (Blueprint $table) {
                if (Schema::hasColumn('moon_extractions', 'extraction_started_sent')) {
                    $table->dropColumn('extraction_started_sent');
                }
                if (Schema::hasColumn('moon_extractions', 'next_planned_sent')) {
                    $table->dropColumn('next_planned_sent');
                }
            });
        }

        if (Schema::hasTable('webhook_configurations')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                if (Schema::hasColumn('webhook_configurations', 'notify_extraction_started')) {
                    $table->dropColumn('notify_extraction_started');
                }
                if (Schema::hasColumn('webhook_configurations', 'notify_next_extraction_planned')) {
                    $table->dropColumn('notify_next_extraction_planned');
                }
            });
        }

        Schema::dropIfExists('moon_extraction_plans');
    }
}
