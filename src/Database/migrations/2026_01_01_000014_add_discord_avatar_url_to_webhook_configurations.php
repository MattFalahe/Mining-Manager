<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the missing `discord_avatar_url` column to `webhook_configurations`.
 *
 * `NotificationService` (lines 778-779 + 1401-1402) reads
 * `$webhook->discord_avatar_url` to set `avatar_url` on outgoing Discord
 * webhook messages. The lang file `Resources/lang/en/settings.php:352`
 * exposes a "Custom Avatar URL" label for it. But the migration that
 * created `webhook_configurations` (000001) never declared the column,
 * the model never listed it as `$fillable`, the controller validator
 * never validated it, and the JS form never submitted it.
 *
 * Effect pre-fix: a UI label that doesn't surface anywhere, dispatch
 * code that reads a non-existent column (always null), and operators
 * with no way to override the Discord webhook's default avatar.
 *
 * This migration adds the column. The companion changes (model
 * fillable, validator rule, blade form, JS submit) all land in the
 * same commit so the feature works end-to-end after this migration
 * applies.
 *
 * Forward-only — backward compat with the released v1.0.2 migration
 * set. Migration 000001 is unchanged.
 *
 * @see project memory feedback_released_plugin_migrations.md
 */
class AddDiscordAvatarUrlToWebhookConfigurations extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('webhook_configurations')) {
            return;
        }

        if (Schema::hasColumn('webhook_configurations', 'discord_avatar_url')) {
            return;
        }

        Schema::table('webhook_configurations', function (Blueprint $table) {
            // Nullable string — sits next to discord_username (existing
            // column) so the two avatar-override fields are colocated.
            // No default; null = use the webhook's default Discord avatar
            // (same semantics as discord_username).
            $table->string('discord_avatar_url')->nullable()->after('discord_username');
        });
    }

    public function down(): void
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
}
