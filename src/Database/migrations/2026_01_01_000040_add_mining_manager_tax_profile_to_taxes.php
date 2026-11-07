<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * mining_taxes.tax_profile: the tax profile that billed each bill, 'global'
 * or the configured corporation's id.
 *
 * The tax overview splits bills into members' and non-members' by it. It
 * used to go by the corporation the bill's main character is in now, so a
 * member who left took their old bills over to the non-members.
 *
 * Bills made before this update stay NULL and keep that split: nothing here
 * goes back over them. Additive only.
 */
class AddMiningManagerTaxProfileToTaxes extends Migration
{
    private const INDEX = 'mm_taxes_tax_profile_idx';

    public function up(): void
    {
        if (!Schema::hasTable('mining_taxes')) {
            return;
        }

        if (!Schema::hasColumn('mining_taxes', 'tax_profile')) {
            Schema::table('mining_taxes', function (Blueprint $table) {
                $table->string('tax_profile', 20)->nullable();
            });
        }

        if (!$this->hasIndex('mining_taxes', self::INDEX)) {
            Schema::table('mining_taxes', function (Blueprint $table) {
                $table->index('tax_profile', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('mining_taxes') || !Schema::hasColumn('mining_taxes', 'tax_profile')) {
            return;
        }

        if ($this->hasIndex('mining_taxes', self::INDEX)) {
            Schema::table('mining_taxes', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->dropColumn('tax_profile');
        });
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
