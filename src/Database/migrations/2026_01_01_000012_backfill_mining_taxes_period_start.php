<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Backfill `mining_taxes.period_start` from `month` for legacy rows.
 *
 * The original migration 000001 declares
 *   `$table->unique(['character_id', 'period_start'], 'mining_taxes_char_period_unique')`
 * but `period_start` is `nullable()`. MySQL/Postgres treat `NULL != NULL`
 * inside unique indexes, so two rows with the same `character_id` and
 * NULL `period_start` can coexist — defeating the unique constraint for
 * exactly the rows it was meant to protect (legacy taxes from pre-period_start
 * days, before bi-weekly support landed).
 *
 * Fix: backfill NULL period_start values from the older `month` column.
 * `month` is a date-typed column that pre-period_start versions used as
 * the period anchor. Setting `period_start = month` for legacy rows
 * realigns the legacy rows with the new schema and makes the unique
 * constraint enforceable for them.
 *
 * Why we DON'T also flip the column to NOT NULL:
 *   - Application-layer code (`MiningTax::create`, `updateOrCreate`) always
 *     populates period_start for new rows in v1.0.0+. So NULLs only ever
 *     come from upgraded installs that had pre-v1.0.0 rows.
 *   - The backfill cleans those legacy rows up. New rows are clean by code.
 *   - Flipping NOT NULL would require either a default value (no good
 *     candidate — there's no sensible "missing period" sentinel) or
 *     dropping rows that fail to backfill (data loss).
 *   - Keeping the column nullable is mostly harmless once legacy data is
 *     clean and only matters if a buggy future path reintroduces NULLs.
 *     If that happens, the unique constraint would degrade for those
 *     specific rows — easier to fix in code than to fight the schema.
 *
 * Forward-only — backward compat with the released v1.0.2 migration set.
 * NEVER edit migration 000001 to add a default or NOT NULL on
 * period_start; that would break idempotency of the migration run for
 * anyone on v1.0.2 already.
 *
 * @see project memory feedback_released_plugin_migrations.md
 */
class BackfillMiningTaxesPeriodStart extends Migration
{
    public function up(): void
    {
        // Defensive guards: skip cleanly when the table or columns aren't
        // there (extreme schema-drift edge cases). Migration 000001 always
        // creates them.
        if (!Schema::hasTable('mining_taxes')) {
            return;
        }

        if (!Schema::hasColumn('mining_taxes', 'period_start')
            || !Schema::hasColumn('mining_taxes', 'month')) {
            return;
        }

        // Backfill: for every row with period_start IS NULL, copy `month`
        // into period_start. `month` is a date column so the assignment is
        // type-safe. Single UPDATE — runs in a single statement, no row
        // iteration needed.
        $affected = DB::table('mining_taxes')
            ->whereNull('period_start')
            ->whereNotNull('month')
            ->update(['period_start' => DB::raw('`month`')]);

        if ($affected > 0) {
            Log::info("[Mining Manager] Backfilled period_start for {$affected} legacy mining_taxes rows from month column");
        }

        // Sanity check — log any rows that still have NULL period_start
        // (shouldn't happen unless there are rows with NULL `month` too,
        // which would be data corruption from a much older era).
        $remainingNulls = DB::table('mining_taxes')
            ->whereNull('period_start')
            ->count();

        if ($remainingNulls > 0) {
            Log::warning("[Mining Manager] {$remainingNulls} mining_taxes rows still have NULL period_start after backfill — these have NULL `month` too. Cannot disambiguate; rows skipped. Investigate manually.");
        }
    }

    public function down(): void
    {
        // Backfill is one-way. Reversing it would require knowing which rows
        // had NULL period_start before the up() ran, which we didn't record.
        // Down() is a no-op — the data assignments are not destructive in
        // any case (period_start = month is a valid value pair for the
        // legacy rows being targeted).
    }
}
