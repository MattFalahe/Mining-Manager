<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the `discord_avatar_url` column added by migration 000014.
 *
 * Migration 000014 wired up `discord_avatar_url` end-to-end as a
 * per-webhook avatar override. After review, that field was decided to
 * be redundant: Discord webhooks have their own avatar setting in the
 * Discord channel UI (Edit Channel → Integrations → edit webhook →
 * upload avatar) which is the canonical place to configure it. The MM
 * column duplicated that surface with no added value — operators
 * configured the same image in two places and got the same result.
 *
 * The remove-the-feature decision was made AFTER 000014 had already
 * shipped on dev-5.0 and run on at least one install. Rather than edit
 * the released 000014 migration (which would break idempotency for
 * anyone who already applied it), this 000015 cleans up the column
 * properly:
 *
 *   - Existing installs: 000015 drops the column on next migration run.
 *   - Fresh installs: 000014 creates then 000015 drops in sequence.
 *     Net effect across the pair = column never persists.
 *
 * Companion changes that landed in the same commit (no migration needed
 * for these, just code/UI/lang removals):
 *   - NotificationService: dispatch reads removed (now sets username
 *     only, lets Discord's own webhook avatar render)
 *   - WebhookConfiguration: $fillable + property docblock entry removed
 *   - SettingsController::webhookValidationRules: rule removed
 *   - webhooks.js: load + submit fields removed
 *   - webhooks.blade.php: form input removed
 *   - lang/en/settings.php: discord_avatar_url + discord_avatar_help
 *     translation keys removed
 *
 * Forward-only per `feedback_released_plugin_migrations.md`. 000014
 * stays in the migration set as historical record.
 *
 * @see project memory feedback_released_plugin_migrations.md
 */
class DropDiscordAvatarUrlFromWebhookConfigurations extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('webhook_configurations')) {
            return;
        }

        if (!Schema::hasColumn('webhook_configurations', 'discord_avatar_url')) {
            return;
        }

        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->dropColumn('discord_avatar_url');
        });
    }

    public function down(): void
    {
        // Reversing this would re-create the column for a feature we
        // intentionally removed. Down() is a no-op — operators rolling
        // back this migration get nothing back. Roll back 000014 too if
        // you actually want the column for some reason.
    }
}
