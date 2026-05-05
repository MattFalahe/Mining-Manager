<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add infrastructure for the extraction_at_risk + extraction_lost
 * notifications — a cross-plugin safety warning family that fires when a
 * refinery (Athanor/Tatara) running an active extraction is:
 *
 *   - Running out of fuel (structure.alert.fuel_critical)
 *   - Under shield attack (structure.alert.shield_reinforced)
 *   - Under armor attack (structure.alert.armor_reinforced)
 *   - In final timer (structure.alert.hull_reinforced)
 *   - Destroyed entirely (structure.alert.destroyed → extraction_lost)
 *
 * Architecture: Manager Core's EventBus + Structure Manager's low-fuel +
 * combat detection publish `structure.alert.*` events. Mining Manager
 * subscribes via StructureAlertHandler, filters to extractions-only, and
 * dispatches one of two notification types with dynamic embed flavors:
 *
 *   extraction_at_risk  — still recoverable (fuel/shield/armor/hull)
 *   extraction_lost     — destroyed, post-mortem
 *
 * Both are opt-in per-webhook. The settings UI greys out both toggles
 * with a banner when Manager Core or Structure Manager is missing.
 *
 * Columns added:
 *
 *  moon_extractions.alert_fuel_critical_sent       (bool, default false)
 *  moon_extractions.alert_shield_reinforced_sent   (bool, default false)
 *  moon_extractions.alert_armor_reinforced_sent    (bool, default false)
 *  moon_extractions.alert_hull_reinforced_sent     (bool, default false)
 *  moon_extractions.alert_destroyed_sent           (bool, default false)
 *
 *    Five separate dedup flags so each flavor is idempotent independently.
 *    An extraction can receive both fuel_critical AND under_attack (different
 *    threats, different alerts) but not the same flavor twice.
 *    Individual booleans chosen over a JSON meta column for atomic writes
 *    (no read-modify-write race between concurrent queue workers) and to
 *    match the existing dedup pattern (notification_sent, unstable_warning_sent).
 *
 *  webhook_configurations.notify_extraction_at_risk  (bool, default false)
 *  webhook_configurations.notify_extraction_lost     (bool, default false)
 *
 *    Two per-webhook toggles — opt-in to avoid surprising existing installs.
 *    Split into two so ops teams can route post-mortems (lost) to management /
 *    finance channels and live-threat pings (at_risk) to fleet ops channels.
 *
 * All additive + defaulted so existing rows don't need backfill.
 */
class AddExtractionAtRiskNotifications extends Migration
{
    public function up(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->boolean('alert_fuel_critical_sent')->default(false)->after('unstable_warning_sent');
            $table->boolean('alert_shield_reinforced_sent')->default(false)->after('alert_fuel_critical_sent');
            $table->boolean('alert_armor_reinforced_sent')->default(false)->after('alert_shield_reinforced_sent');
            $table->boolean('alert_hull_reinforced_sent')->default(false)->after('alert_armor_reinforced_sent');
            $table->boolean('alert_destroyed_sent')->default(false)->after('alert_hull_reinforced_sent');

            // Composite index — SM event handler queries by structure_id then
            // filters on "any flavor unsent" in PHP; covering index isn't
            // critical but helps the sent-flag lookups.
            $table->index('alert_fuel_critical_sent', 'idx_moon_ext_alert_fuel');
            $table->index('alert_destroyed_sent', 'idx_moon_ext_alert_destroyed');
        });

        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->boolean('notify_extraction_at_risk')->default(false)->after('notify_moon_chunk_unstable');
            $table->boolean('notify_extraction_lost')->default(false)->after('notify_extraction_at_risk');
        });
    }

    public function down(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->dropIndex('idx_moon_ext_alert_fuel');
            $table->dropIndex('idx_moon_ext_alert_destroyed');
            $table->dropColumn([
                'alert_fuel_critical_sent',
                'alert_shield_reinforced_sent',
                'alert_armor_reinforced_sent',
                'alert_hull_reinforced_sent',
                'alert_destroyed_sent',
            ]);
        });

        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'notify_extraction_at_risk',
                'notify_extraction_lost',
            ]);
        });
    }
}
