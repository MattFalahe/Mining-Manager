<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastructure for the Moon Scan Missing notification.
 *
 *  webhook_configurations.notify_moon_scan_missing
 *    Per-webhook opt-in, off by default, so an existing install does not start
 *    posting something new until somebody binds it.
 *
 *  mining_manager_moon_scan_alerts
 *    What has already been said about a moon that has no scan in SeAT, one row
 *    per moon and per thing that made the scan matter:
 *      refinery    one of our refineries with a moon drill sits on it
 *      extraction  an extraction was started there
 *      plan        a pull was planned there
 *    ref_id is the refinery, extraction or planned pull. A moon is only
 *    mentioned again for something new, and its rows go once it is scanned.
 *
 * All additive. Nothing existing is read or changed.
 */
class AddMiningManagerMoonScanAlerts extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webhook_configurations')
            && !Schema::hasColumn('webhook_configurations', 'notify_moon_scan_missing')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->boolean('notify_moon_scan_missing')->default(false);
            });
        }

        if (!Schema::hasTable('mining_manager_moon_scan_alerts')) {
            Schema::create('mining_manager_moon_scan_alerts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('moon_id');
                $table->string('kind', 16);
                $table->unsignedBigInteger('ref_id');
                $table->timestamps();

                $table->unique(['moon_id', 'kind', 'ref_id'], 'mm_moon_scan_alerts_unique');
            });
        } elseif (!$this->hasIndex('mining_manager_moon_scan_alerts', 'mm_moon_scan_alerts_unique')) {
            Schema::table('mining_manager_moon_scan_alerts', function (Blueprint $table) {
                $table->unique(['moon_id', 'kind', 'ref_id'], 'mm_moon_scan_alerts_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mining_manager_moon_scan_alerts');

        if (Schema::hasTable('webhook_configurations')
            && Schema::hasColumn('webhook_configurations', 'notify_moon_scan_missing')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->dropColumn('notify_moon_scan_missing');
            });
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
