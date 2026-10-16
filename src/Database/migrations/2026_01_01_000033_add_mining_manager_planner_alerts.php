<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastructure for four Moon Planner notifications.
 *
 *  webhook_configurations.notify_refinery_gone
 *  webhook_configurations.notify_extraction_cancelled
 *  webhook_configurations.notify_moon_not_rescheduled
 *  webhook_configurations.notify_schedule_needs_filling
 *    Per-webhook opt-in, off by default, so an existing install does not start
 *    posting something new until somebody binds it.
 *
 *  mining_manager_refinery_alerts
 *    What the planner remembers about one refinery between runs, one row per
 *    corporation, structure and kind of alert:
 *      refinery_missing  first time it was missing from the corporation's
 *                        structures, the last sighting that counted, how many,
 *                        and when its planned pulls were taken off. Pulls only
 *                        go after several sightings hours apart, so a refinery
 *                        that drops out of SeAT for an afternoon loses nothing.
 *      not_rescheduled   the arrival it is about, when it was last reminded and
 *                        how many times. Kept per refinery rather than on the
 *                        extraction, because arrived extractions are archived
 *                        a week after they decay and a reminder must keep going
 *                        until somebody starts the next one.
 *
 * All additive. Nothing existing is read or changed.
 */
class AddMiningManagerPlannerAlerts extends Migration
{
    private const COLUMNS = [
        'notify_refinery_gone',
        'notify_extraction_cancelled',
        'notify_moon_not_rescheduled',
        'notify_schedule_needs_filling',
    ];

    public function up(): void
    {
        if (Schema::hasTable('webhook_configurations')) {
            foreach (self::COLUMNS as $column) {
                if (!Schema::hasColumn('webhook_configurations', $column)) {
                    Schema::table('webhook_configurations', function (Blueprint $table) use ($column) {
                        $table->boolean($column)->default(false);
                    });
                }
            }
        }

        if (!Schema::hasTable('mining_manager_refinery_alerts')) {
            Schema::create('mining_manager_refinery_alerts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('corporation_id');
                $table->unsignedBigInteger('structure_id');
                $table->string('kind', 32);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('last_at')->nullable();
                $table->unsignedSmallInteger('count')->default(0);
                $table->timestamp('acted_at')->nullable();
                $table->timestamps();

                $table->unique(['corporation_id', 'structure_id', 'kind'], 'mm_refinery_alerts_unique');
            });
        } elseif (!$this->hasIndex('mining_manager_refinery_alerts', 'mm_refinery_alerts_unique')) {
            Schema::table('mining_manager_refinery_alerts', function (Blueprint $table) {
                $table->unique(['corporation_id', 'structure_id', 'kind'], 'mm_refinery_alerts_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mining_manager_refinery_alerts');

        if (Schema::hasTable('webhook_configurations')) {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('webhook_configurations', $column)) {
                    Schema::table('webhook_configurations', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }

    /**
     * Laravel has no portable "does this index exist", and an operator may be
     * part-migrated, so ask the schema itself.
     */
    protected function hasIndex(string $table, string $index): bool
    {
        try {
            return DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $index)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
