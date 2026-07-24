<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.0.1 — add the Metenox MoonMaterialBay full-cargo notification.
 *
 * Two schema changes:
 *
 *   1. New boolean column on `webhook_configurations`:
 *      `notify_metenox_cargo_full` — toggle per-webhook subscription to
 *      the new notification type, same shape as every other notify_*
 *      column the model already carries.
 *
 *   2. New table `metenox_cargo_alert_state` — per-structure dedup latch.
 *      The cron compares the current fill % against the stored
 *      `last_fill_pct` to decide whether to fire (cross-up transition
 *      across the configured threshold). Resets implicitly when fill
 *      drops below threshold (cargo pulled) — the next cross-up fires
 *      a fresh notification for the new cycle.
 *
 * Forward-only per the released-plugin-migration rule: adds columns/table
 * only, never alters existing released migrations.
 *
 * Both objects are guarded with Schema::hasTable / Schema::hasColumn so
 * re-running the migration is a no-op (idempotent for ops who run the
 * SeAT stack-up multiple times).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Add the per-webhook subscription toggle.
        if (Schema::hasTable('webhook_configurations')
            && !Schema::hasColumn('webhook_configurations', 'notify_metenox_cargo_full')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->boolean('notify_metenox_cargo_full')
                    ->default(false)
                    ->after('notify_extraction_lost');
            });
        }

        // 2) Create the per-structure dedup latch table.
        if (!Schema::hasTable('metenox_cargo_alert_state')) {
            Schema::create('metenox_cargo_alert_state', function (Blueprint $table) {
                $table->unsignedBigInteger('structure_id')->primary();
                $table->unsignedBigInteger('corporation_id')->index();
                // Most recent fill % observed by the scanner. Used to detect
                // cross-up transitions (current >= threshold AND previous <
                // threshold = fire; otherwise stay silent).
                $table->float('last_fill_pct')->default(0);
                // Timestamp the alert was last fired. Operators read it on
                // the diagnostic page to confirm the notification went out.
                // Null = never alerted for this structure.
                $table->timestamp('last_alerted_at')->nullable();
                // Cached fill % at the moment of the last fired alert. Lets
                // the alert body include "alerted at XX%" for context.
                $table->float('fill_pct_at_alert')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // No down() — released migrations are forward-only.
    }
};
