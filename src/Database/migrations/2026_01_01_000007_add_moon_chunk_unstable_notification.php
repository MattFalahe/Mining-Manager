<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add infrastructure for the moon_chunk_unstable notification — a capital-
 * ship safety warning fired ~2 hours before a chunk enters the plugin's
 * UNSTABLE state (= fractured_at + 48h, the last 2 hours of the 50-hour
 * post-fracture lifecycle). Unstable chunks are known hostile-activity
 * hotspots; this gives Rorqual / Orca pilots time to dock up or safe up.
 *
 * The plugin's moon lifecycle is richer than raw ESI:
 *
 *     chunk_arrival_time
 *       ↓
 *     fractured_at  (manual laser fire OR auto-fracture at chunk_arrival + 3h)
 *       ↓
 *     48h READY window (stable mining)
 *       ↓
 *     2h UNSTABLE window (MoonExtraction::isUnstable() returns true here)
 *       ↓
 *     EXPIRED (MoonExtraction::isExpired())
 *
 * The warning fires at fractured_at + 46h (2h before unstable begins).
 * It uses MoonExtraction::getUnstableStartTime() as the authoritative
 * source, NOT ESI's natural_decay_time (which is actually the auto-
 * fracture mark, a completely different point in the lifecycle).
 *
 * Two columns added:
 *
 *  - moon_extractions.unstable_warning_sent (boolean, default false)
 *    Dedup flag. Once the 2h warning fires for an extraction, flip this to
 *    true so the per-minute cron doesn't re-fire every tick. Parallels the
 *    existing notification_sent flag (which gates moon_ready / arrival).
 *
 *  - webhook_configurations.notify_moon_chunk_unstable (boolean, default
 *    false — opt-in to avoid surprising existing installs). Per-webhook
 *    subscription toggle, matches every other notify_* flag on this table.
 *
 * Both columns are additive and defaulted so existing rows don't need
 * backfill.
 */
class AddMoonChunkUnstableNotification extends Migration
{
    public function up(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->boolean('unstable_warning_sent')->default(false)->after('notification_sent');
            $table->index('unstable_warning_sent', 'idx_moon_ext_unstable_sent');
        });

        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->boolean('notify_moon_chunk_unstable')->default(false)->after('notify_jackpot_detected');
        });
    }

    public function down(): void
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->dropIndex('idx_moon_ext_unstable_sent');
            $table->dropColumn('unstable_warning_sent');
        });

        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->dropColumn('notify_moon_chunk_unstable');
        });
    }
}
