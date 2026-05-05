<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `abyssal_ore_value` + `triglavian_ore_value` columns to the daily and
 * monthly mining ledger summary tables.
 *
 * Background: when the summary tables were originally created (migration
 * 000001), only four ore-class buckets had dedicated columns —
 * `moon_ore_value`, `regular_ore_value`, `ice_value`, `gas_value`. The
 * `regular_ore_value` was effectively "everything not moon/ice/gas",
 * which silently included abyssal ores (Talassonite/Bezdnacine/Rakovene
 * + their Abyssal/Hadal variants) and Triglavian ores. Result:
 *
 *   - Personal/member dashboard "Mining by Group" doughnut chart never
 *     surfaced Abyssal or Triglavian as separate slices — they were
 *     hidden inside Regular Ore.
 *   - Director dashboards same issue.
 *   - Analytics breakdown by category was inaccurate for any corp doing
 *     Triglavian/Abyssal mining.
 *
 * Taxation was never affected — that path uses the per-row
 * `is_abyssal` / `is_triglavian` boolean flags directly. This is a
 * dashboard/analytics-only schema gap.
 *
 * After this migration runs, existing summary rows still have abyssal +
 * triglavian value baked into `regular_ore_value` and 0 in the new
 * columns. To recompute, run:
 *
 *   docker exec -it seat-docker-front-1 \
 *     php artisan mining-manager:process-ledger --recalculate
 *
 * The recalculate flag re-runs the SUM-by-flag aggregation in
 * LedgerSummaryService which now correctly populates all six buckets
 * AND excludes abyssal + triglavian from regular_ore_value.
 */
class AddAbyssalTriglavianToSummaries extends Migration
{
    public function up(): void
    {
        Schema::table('mining_ledger_daily_summaries', function (Blueprint $table) {
            $table->decimal('abyssal_ore_value', 20, 2)->default(0)->after('gas_value');
            $table->decimal('triglavian_ore_value', 20, 2)->default(0)->after('abyssal_ore_value');
        });

        Schema::table('mining_ledger_monthly_summaries', function (Blueprint $table) {
            $table->decimal('abyssal_ore_value', 20, 2)->default(0)->after('gas_value');
            $table->decimal('triglavian_ore_value', 20, 2)->default(0)->after('abyssal_ore_value');
        });
    }

    public function down(): void
    {
        Schema::table('mining_ledger_daily_summaries', function (Blueprint $table) {
            $table->dropColumn(['abyssal_ore_value', 'triglavian_ore_value']);
        });

        Schema::table('mining_ledger_monthly_summaries', function (Blueprint $table) {
            $table->dropColumn(['abyssal_ore_value', 'triglavian_ore_value']);
        });
    }
}
