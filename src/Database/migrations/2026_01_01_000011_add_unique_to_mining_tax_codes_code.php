<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add a UNIQUE constraint on `mining_tax_codes.code`.
 *
 * The original `2026_01_01_000001_create_mining_manager_tables.php` migration
 * (lines 388-410) declared only `$table->index('code')` — non-unique. Tax
 * code uniqueness was enforced solely at the application layer via the
 * Laravel validation rule `'code' => 'nullable|string|unique:mining_tax_codes,code'`
 * in `TaxController::store/update` etc. That works under sequential load but
 * is racy under concurrent generation paths:
 *
 *     - admin clicks "Generate Codes" in the UI
 *     - the `mining-manager:generate-tax-codes` cron fires
 *     - a manual API POST hits the controller
 *
 * Two of those firing at the same moment can both pass their independent
 * `unique:` validation reads, both INSERT the same code, and the wallet
 * matcher then has an ambiguous code-to-tax mapping (could credit the wrong
 * tax). The `extractTaxCode` + `where('code', $taxCode)` lookup in
 * WalletTransferService::processTransaction takes whichever row Eloquent
 * returns first — non-deterministic.
 *
 * Fix: enforce uniqueness at the DB level. MySQL/Postgres reject the
 * second INSERT regardless of how the validation paths interleaved.
 *
 * Pre-check: existing installs MAY already have duplicate codes from prior
 * runs of the racy paths. We detect duplicates before trying to add the
 * constraint and abort with a clear message + the offending codes if so.
 * The operator can then `UPDATE` to disambiguate (e.g. cancel the older row
 * via `status='cancelled'`) and re-run migrations.
 *
 * Forward-only — backward compat with the released v1.0.2 migration set.
 * NEVER edit migration 000001 to add the constraint; that would break
 * idempotency of the migration run for anyone on v1.0.2 already.
 *
 * @see project memory feedback_released_plugin_migrations.md
 */
class AddUniqueToMiningTaxCodesCode extends Migration
{
    public function up(): void
    {
        // Defensive duplicate-check.
        //
        // Skip when the table doesn't exist (fresh install order) or the
        // column isn't there (extreme schema-drift edge case). Migration
        // 000001 always creates this — the guard is just paranoid safety.
        if (!Schema::hasTable('mining_tax_codes') || !Schema::hasColumn('mining_tax_codes', 'code')) {
            return;
        }

        $duplicates = DB::table('mining_tax_codes')
            ->select('code', DB::raw('COUNT(*) as count'))
            ->whereNotNull('code')
            ->groupBy('code')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $sample = $duplicates->take(5)->pluck('code')->implode(', ');
            $totalDupes = $duplicates->count();

            throw new \RuntimeException(
                "Cannot add UNIQUE constraint on mining_tax_codes.code: " .
                "found {$totalDupes} duplicated code(s) in the table. " .
                "Sample: [{$sample}]. " .
                "Resolve by setting status='cancelled' on the older duplicate(s), " .
                "then re-run migrations. SQL hint: " .
                "DELETE FROM mining_tax_codes WHERE id IN (SELECT * FROM (SELECT MIN(id) FROM mining_tax_codes WHERE code IN (...) GROUP BY code) tmp);"
            );
        }

        Schema::table('mining_tax_codes', function (Blueprint $table) {
            $table->unique('code', 'mining_tax_codes_code_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mining_tax_codes')) {
            return;
        }

        Schema::table('mining_tax_codes', function (Blueprint $table) {
            $table->dropUnique('mining_tax_codes_code_unique');
        });
    }
}
