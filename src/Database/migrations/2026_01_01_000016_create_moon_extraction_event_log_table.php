<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-extraction dedup latch for the moon-extraction lifecycle events
 * published by `mining:scan-extraction-events`.
 *
 * The scanner cron diffs every active extraction's effective lifecycle
 * stage (ready / unstable / expired) against the latches in this table
 * and only publishes when the corresponding column is NULL. After a
 * successful publish the column is stamped with NOW(), so re-runs of the
 * scanner never re-publish the same event for the same extraction.
 *
 * One row per extraction (extraction_id UNIQUE). Additive table, safe
 * for fresh and upgrade installs (Schema::hasTable guard).
 *
 * Why a separate table (not bool columns on `moon_extractions`):
 *   - keeps the integrations layer fully optional and isolated
 *   - keeps the published-stage timestamps available for diagnostics
 *     ("when did MM tell Pings this moon was ready?")
 *   - leaves room for adding future stages without re-migrating the
 *     central extractions table
 */
class CreateMoonExtractionEventLogTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moon_extraction_event_log')) {
            return;
        }

        Schema::create('moon_extraction_event_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('extraction_id');
            $table->timestamp('ready_published_at')->nullable();
            $table->timestamp('unstable_published_at')->nullable();
            $table->timestamp('expired_published_at')->nullable();
            $table->timestamps();

            $table->unique('extraction_id', 'mm_eel_extraction_unique');
            $table->index('ready_published_at', 'mm_eel_ready_idx');
            $table->index('expired_published_at', 'mm_eel_expired_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moon_extraction_event_log');
    }
}
