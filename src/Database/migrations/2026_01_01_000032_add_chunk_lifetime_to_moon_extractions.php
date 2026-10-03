<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store each extraction's real belt lifetime and auto-fracture delay.
 *
 * A refinery's Moon Drilling Stability / Proficiency rig extends the belt's
 * lifetime after fracture (48 h base, +50% / +100% rig -> 72 h / 96 h) and,
 * via the Chunk Stability Bonus, the wait before the chunk fractures on its
 * own (3 h base, +20% / +24% -> 3.6 h / 3.72 h).
 *
 * Those numbers were hard-coded as "48 h ready + 2 h unstable = 50 h" all over
 * the plugin, so every structure got the default regardless of its fit. This
 * adds the resolved values to the row (see StructureMoonRigs) so the lifecycle
 * helpers, the SQL expiry scope, notifications and analytics all read the same
 * rig-aware figures.
 *
 * The 2 h "unstable" tail is the plugin's own last-call warning; it is carved
 * out of the end of the lifetime, so expiry == fracture + chunk_lifetime_hours
 * and stays in step with the game's "up to four days".
 *
 * Defaults keep existing rows on the old base behaviour. History keeps the
 * values too, so archived chunks retain the window they actually had.
 */
class AddChunkLifetimeToMoonExtractions extends Migration
{
    public function up(): void
    {
        foreach (['moon_extractions', 'moon_extraction_history'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                if (!Schema::hasColumn($blueprint->getTable(), 'chunk_lifetime_hours')) {
                    $blueprint->unsignedSmallInteger('chunk_lifetime_hours')
                        ->default(48)
                        ->after('fractured_by')
                        ->comment('Belt lifetime after fracture, in hours, from the fitted moon drilling rig');
                }

                if (!Schema::hasColumn($blueprint->getTable(), 'auto_fracture_delay_minutes')) {
                    $blueprint->unsignedSmallInteger('auto_fracture_delay_minutes')
                        ->default(180)
                        ->after('chunk_lifetime_hours')
                        ->comment('Auto-fracture delay after chunk arrival, in minutes, from the fitted rig');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['moon_extractions', 'moon_extraction_history'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                foreach (['auto_fracture_delay_minutes', 'chunk_lifetime_hours'] as $column) {
                    if (Schema::hasColumn($blueprint->getTable(), $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
}
