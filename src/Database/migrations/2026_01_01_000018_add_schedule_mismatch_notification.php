<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastructure for the `schedule_mismatch` notification.
 *
 * Fires when a planned pull and the real in-game extraction for the same
 * refinery are further apart than the planner's match tolerance (30 min) but
 * still inside the same cycle — i.e. the drill was fired on a different timer
 * than the plan called for ("wrong scheduled moon"). The planner already
 * surfaces this visually; this makes it reach Discord/Slack/EVE-mail too.
 *
 *  moon_extraction_plans.mismatch_notified_at (timestamp, nullable)
 *    One-shot latch so a standing mismatch pings once rather than every time
 *    the detector runs. Nullable timestamp (not a bool) so operators can see
 *    WHEN it fired, and so clearing it re-arms the alert.
 *
 *  webhook_configurations.notify_schedule_mismatch (bool, default false)
 *    Per-webhook opt-in, off by default so existing installs don't suddenly
 *    start emitting a new type.
 *
 * Both additive + defaulted — no backfill needed.
 */
class AddScheduleMismatchNotification extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moon_extraction_plans')
            && !Schema::hasColumn('moon_extraction_plans', 'mismatch_notified_at')) {
            Schema::table('moon_extraction_plans', function (Blueprint $table) {
                $table->timestamp('mismatch_notified_at')->nullable()->after('variance_hours');
            });
        }

        if (Schema::hasTable('webhook_configurations')
            && !Schema::hasColumn('webhook_configurations', 'notify_schedule_mismatch')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->boolean('notify_schedule_mismatch')->default(false)->after('notify_next_extraction_planned');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('moon_extraction_plans')
            && Schema::hasColumn('moon_extraction_plans', 'mismatch_notified_at')) {
            Schema::table('moon_extraction_plans', function (Blueprint $table) {
                $table->dropColumn('mismatch_notified_at');
            });
        }

        if (Schema::hasTable('webhook_configurations')
            && Schema::hasColumn('webhook_configurations', 'notify_schedule_mismatch')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->dropColumn('notify_schedule_mismatch');
            });
        }
    }
}
