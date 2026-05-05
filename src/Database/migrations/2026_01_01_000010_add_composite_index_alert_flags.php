<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add indexes on the three alert dedup flags that migration 000008 didn't
 * cover (shield_reinforced, armor_reinforced, hull_reinforced).
 *
 * Migration 000008 only indexed two of the five alert flags
 * (alert_fuel_critical_sent, alert_destroyed_sent) because at the time
 * Structure Manager only published the fuel_critical event. Now that
 * the EventBus subscription is wildcarded to `structure.alert.*`, when
 * SM eventually adds the shield/armor/hull combat events, the
 * StructureAlertHandler's per-event lookups would do table scans on
 * `moon_extractions` to filter by these unindexed columns.
 *
 * The lookups are technically atomic single-row UPDATEs (the
 * compare-and-swap dedup pattern), but without an index the database
 * still has to scan to find the row by id AND check the flag — and
 * with concurrent queue workers and a large extraction history, that
 * scan-cost adds up. Adding the indexes proactively before SM ships
 * the publishers means there's no surprise performance regression
 * when the new event flavors start firing.
 *
 * Single-column indexes are appropriate here. The handler's query is:
 *
 *     UPDATE moon_extractions SET <flag> = 1
 *       WHERE id = ? AND <flag> = 0
 *
 * The id lookup uses the primary key index. The flag check is the
 * compare-half of the compare-and-swap. A single-column index on the
 * flag lets the database short-circuit the row scan when the flag is
 * already 1 (the common case once an extraction has fired all its
 * alerts).
 *
 * Additive + defaulted, no behavior change. Reversible.
 */
class AddCompositeIndexAlertFlags extends Migration
{
    public function up(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->index('alert_shield_reinforced_sent', 'idx_moon_ext_alert_shield');
            $table->index('alert_armor_reinforced_sent', 'idx_moon_ext_alert_armor');
            $table->index('alert_hull_reinforced_sent', 'idx_moon_ext_alert_hull');
        });
    }

    public function down(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->dropIndex('idx_moon_ext_alert_shield');
            $table->dropIndex('idx_moon_ext_alert_armor');
            $table->dropIndex('idx_moon_ext_alert_hull');
        });
    }
}
