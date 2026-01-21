<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixSettingsUniqueConstraintForMulticorp extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration fixes the settings table unique constraint to support multi-corporation settings.
     * Previously, the 'key' column had a simple unique constraint, which prevented the same setting
     * key from being used for different corporations. This migration:
     * 1. Drops the old unique constraint on 'key'
     * 2. Adds a composite unique constraint on ['key', 'corporation_id']
     *
     * This allows the same setting key (e.g., 'tax_rates.moon_ore.r64') to exist for multiple
     * corporations with different values, while preventing duplicate settings for the same corporation.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mining_manager_settings', function (Blueprint $table) {
            // Drop the old unique constraint on 'key' column
            $table->dropUnique(['key']);

            // Add composite unique constraint on key + corporation_id
            // This allows the same key to exist for different corporations
            $table->unique(['key', 'corporation_id'], 'settings_key_corp_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mining_manager_settings', function (Blueprint $table) {
            // Drop the composite unique constraint
            $table->dropUnique('settings_key_corp_unique');

            // Restore the simple unique constraint on 'key'
            $table->unique('key');
        });
    }
}
