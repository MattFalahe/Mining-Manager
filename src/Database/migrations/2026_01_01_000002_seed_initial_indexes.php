<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SeedInitialIndexes extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds composite indexes that improve query performance beyond
     * the primary UNIQUE constraints and single-column indexes
     * defined in the table creation migration.
     */
    public function up(): void
    {
        // Composite index for observer-based date joins (moon mining queries)
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->index(['observer_id', 'date'], 'idx_mining_ledger_observer_date');
        });

        // Composite index for character+date lookups (daily summary generation)
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->index(['character_id', 'date'], 'idx_mining_ledger_char_date');
        });

        // Composite index for corporation moon ore queries (dashboard "Top Miners - Moon Ore")
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->index(
                ['corporation_id', 'is_moon_ore', 'character_id'],
                'idx_mining_ledger_corp_moon_char'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropIndex('idx_mining_ledger_corp_moon_char');
        });

        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropIndex('idx_mining_ledger_char_date');
        });

        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropIndex('idx_mining_ledger_observer_date');
        });
    }
}
