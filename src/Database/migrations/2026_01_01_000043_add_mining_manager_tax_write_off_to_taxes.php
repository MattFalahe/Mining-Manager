<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who let a bill off, when, and why.
 *
 * A bill waived or exempted stops being owed, but the status alone could not
 * say who decided it or on what grounds, and Tax Overview could not say how
 * much was let off in a month. What was let off is not stored: it is what the
 * bill was for less what was paid on it, which a bill let off never changes.
 *
 * Bills let off before this update stay NULL: nothing here goes back over
 * them. Additive only.
 */
class AddMiningManagerTaxWriteOffToTaxes extends Migration
{
    private const INDEX = 'mm_taxes_written_off_at_idx';

    public function up(): void
    {
        if (!Schema::hasTable('mining_taxes')) {
            return;
        }

        if (!Schema::hasColumn('mining_taxes', 'written_off_at')) {
            Schema::table('mining_taxes', function (Blueprint $table) {
                $table->timestamp('written_off_at')->nullable();
            });
        }

        if (!Schema::hasColumn('mining_taxes', 'written_off_by')) {
            Schema::table('mining_taxes', function (Blueprint $table) {
                // SeAT user id, not a character: the person who decided.
                $table->unsignedBigInteger('written_off_by')->nullable();
            });
        }

        if (!Schema::hasColumn('mining_taxes', 'written_off_reason')) {
            Schema::table('mining_taxes', function (Blueprint $table) {
                $table->string('written_off_reason', 255)->nullable();
            });
        }

        if (!$this->hasIndex('mining_taxes', self::INDEX)) {
            Schema::table('mining_taxes', function (Blueprint $table) {
                $table->index('written_off_at', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('mining_taxes')) {
            return;
        }

        if ($this->hasIndex('mining_taxes', self::INDEX)) {
            Schema::table('mining_taxes', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        foreach (['written_off_reason', 'written_off_by', 'written_off_at'] as $column) {
            if (Schema::hasColumn('mining_taxes', $column)) {
                Schema::table('mining_taxes', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * Laravel has no portable "does this index exist", and an operator may be
     * part-migrated, so ask the schema itself.
     */
    protected function hasIndex(string $table, string $index): bool
    {
        try {
            return DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $index)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
