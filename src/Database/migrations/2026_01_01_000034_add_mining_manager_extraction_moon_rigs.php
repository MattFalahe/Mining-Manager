<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The moon drilling rigs each extraction was pulled with.
 *
 *  moon_extractions.moon_rigs
 *  moon_extraction_history.moon_rigs
 *    The rig type ids seen in the refinery's rig slots while the chunk was
 *    on its way, whether SeAT could see that refinery's assets at all, and
 *    when it was last looked at. Rigs can be swapped at any time, so the
 *    extraction keeps its own copy instead of reading whatever is fitted
 *    today; the history table carries it on when an extraction is archived.
 *
 * Nullable and left empty on existing rows: what was fitted back then is not
 * known, and nothing is guessed for them.
 */
class AddMiningManagerExtractionMoonRigs extends Migration
{
    private const TABLES = ['moon_extractions', 'moon_extraction_history'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'moon_rigs')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $column = $table->json('moon_rigs')->nullable();

                if (Schema::hasColumn($tableName, 'fractured_by')) {
                    $column->after('fractured_by');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'moon_rigs')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('moon_rigs');
                });
            }
        }
    }
}
