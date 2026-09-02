<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Stamp the ore-classification cutover.
 *
 * This release teaches TypeIdRegistry about type IDs it never knew: IV-Grade
 * variants of all fifteen classic ores, the Exordium 0-Grade tier, the X-Grade
 * families, Prismaticite, nine gas colours and the base Triglavian ores.
 *
 * Every one of those was previously unrecognised and fell through to the plain
 * ore rate. Recognising them properly is correct going forward, but applying it
 * to existing rows would change what people owe for mining they have already
 * done. On an install where gas is taxed, the nine gas types move from an
 * untaxed bucket to a taxed one, and members would see historical bills grow.
 *
 * That is not acceptable on a live install, so the new classification starts
 * here. Rows dated before this timestamp keep the tax_rate they were given when
 * they were imported, no matter what recalculates them later.
 *
 * Nothing existing is modified. The migration only writes one settings row.
 * Deliberately not corporation-scoped: the cutover is a property of the code
 * version, and every corporation on this install upgrades at the same moment.
 */
class AddClassificationEpoch extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mining_manager_settings')) {
            return;
        }

        // Never move a cutover that is already set: moving it forward would
        // expose rows the guard had been covering. insertOrIgnore is not enough
        // on its own here, because the unique index on (key, corporation_id)
        // cannot constrain rows whose corporation_id is NULL - MySQL treats
        // NULLs in a unique index as distinct from one another.
        $already = DB::table('mining_manager_settings')
            ->where('key', 'classification.epoch')
            ->whereNull('corporation_id')
            ->exists();

        if ($already) {
            return;
        }

        $now = Carbon::now();

        DB::table('mining_manager_settings')->insertOrIgnore([
            'key' => 'classification.epoch',
            'value' => $now->toDateTimeString(),
            'type' => 'string',
            'corporation_id' => null,
            'description' => 'Ore classification cutover. Mining dated before this keeps the tax rate it was originally given.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('mining_manager_settings')) {
            return;
        }

        DB::table('mining_manager_settings')
            ->where('key', 'classification.epoch')
            ->whereNull('corporation_id')
            ->delete();
    }
}
