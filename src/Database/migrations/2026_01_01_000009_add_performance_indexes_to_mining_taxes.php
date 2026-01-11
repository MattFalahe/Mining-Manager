<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add performance indexes to mining_taxes table
 *
 * Adds compound indexes for common tax queries
 *
 * Migration: 2026_01_01_000009
 */
class AddPerformanceIndexesToMiningTaxes extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            // Compound index for dashboard tax calculations
            if (!$this->indexExists('mining_taxes', 'idx_mining_taxes_char_status_month')) {
                $table->index(['character_id', 'status', 'month'], 'idx_mining_taxes_char_status_month');
            }

            // Index for paid_at queries (payment history)
            if (!$this->indexExists('mining_taxes', 'idx_mining_taxes_paid_at')) {
                $table->index('paid_at', 'idx_mining_taxes_paid_at');
            }

            // Index for calculated_at queries
            if (!$this->indexExists('mining_taxes', 'idx_mining_taxes_calculated_at')) {
                $table->index('calculated_at', 'idx_mining_taxes_calculated_at');
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
        Schema::table('mining_taxes', function (Blueprint $table) {
            if ($this->indexExists('mining_taxes', 'idx_mining_taxes_char_status_month')) {
                $table->dropIndex('idx_mining_taxes_char_status_month');
            }
            if ($this->indexExists('mining_taxes', 'idx_mining_taxes_paid_at')) {
                $table->dropIndex('idx_mining_taxes_paid_at');
            }
            if ($this->indexExists('mining_taxes', 'idx_mining_taxes_calculated_at')) {
                $table->dropIndex('idx_mining_taxes_calculated_at');
            }
        });
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists($table, $indexName)
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();

        $result = DB::select(
            "SELECT COUNT(*) as count
             FROM information_schema.statistics
             WHERE table_schema = ?
             AND table_name = ?
             AND index_name = ?",
            [$databaseName, $table, $indexName]
        );

        return $result[0]->count > 0;
    }
}
