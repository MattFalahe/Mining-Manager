<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes to mining_ledger table
 *
 * This migration adds critical indexes to improve query performance:
 * - processed_at: Used in every dashboard query (whereNotNull)
 * - Compound indexes for common filter combinations
 * - Ore type filters (is_moon_ore, is_ice, is_gas)
 *
 * Expected Performance Impact: 10-50x faster queries
 */
class AddPerformanceIndexesToMiningLedger extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            // Critical: processed_at is filtered in every dashboard query
            // Without this index, database does full table scan
            $table->index('processed_at', 'idx_mining_ledger_processed_at');

            // Compound indexes for common query patterns
            // These dramatically speed up filtered queries

            // Member dashboard queries: filter by character + date + processed
            $table->index(['character_id', 'date', 'processed_at'], 'idx_mining_ledger_char_date_proc');

            // Corporation dashboard queries: filter by date range + processed
            $table->index(['date', 'processed_at'], 'idx_mining_ledger_date_proc');

            // Ore type filtering (used in ledger filters and analytics)
            $table->index('is_moon_ore', 'idx_mining_ledger_is_moon_ore');
            $table->index('is_ice', 'idx_mining_ledger_is_ice');
            $table->index('is_gas', 'idx_mining_ledger_is_gas');

            // Total value sorting (used in ledger sorting)
            $table->index('total_value', 'idx_mining_ledger_total_value');

            // Quantity sorting (used in ledger sorting and top miners)
            $table->index('quantity', 'idx_mining_ledger_quantity');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropIndex('idx_mining_ledger_processed_at');
            $table->dropIndex('idx_mining_ledger_char_date_proc');
            $table->dropIndex('idx_mining_ledger_date_proc');
            $table->dropIndex('idx_mining_ledger_is_moon_ore');
            $table->dropIndex('idx_mining_ledger_is_ice');
            $table->dropIndex('idx_mining_ledger_is_gas');
            $table->dropIndex('idx_mining_ledger_total_value');
            $table->dropIndex('idx_mining_ledger_quantity');
        });
    }
}
