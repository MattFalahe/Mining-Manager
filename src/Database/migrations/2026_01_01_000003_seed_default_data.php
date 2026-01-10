<?php

use Illuminate\Database\Migrations\Migration;

class SeedDefaultData extends Migration
{
    /**
     * Run the migrations.
     *
     * Default data is seeded via ScheduleSeeder (composer dump-autoload)
     * and SettingsManagerService defaults (config fallbacks).
     * No migration-level seeding needed.
     */
    public function up(): void
    {
        //
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
}
