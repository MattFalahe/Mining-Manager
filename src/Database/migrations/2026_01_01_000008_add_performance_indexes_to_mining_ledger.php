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
 * - Indexes for existing columns only
 *
 * Expected Performance Impact: 10-50x faster queries
 *
 * Migration: 2026_01_01_000008
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

            // Total value sorting (used in ledger sorting) - only if column exists
            if (Schema::hasColumn('mining_ledger', 'total_value')) {
                $table->index('total_value', 'idx_mining_ledger_total_value');
            }

            // Ore type filtering - only if ore_type column exists
            if (Schema::hasColumn('mining_ledger', 'ore_type')) {
                $table->index('ore_type', 'idx_mining_ledger_ore_type');
            }
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

            // Drop only if they were created
            if (Schema::hasColumn('mining_ledger', 'total_value')) {
                $table->dropIndex('idx_mining_ledger_total_value');
            }
            if (Schema::hasColumn('mining_ledger', 'ore_type')) {
                $table->dropIndex('idx_mining_ledger_ore_type');
            }
        });
    }
}
