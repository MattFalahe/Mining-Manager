<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes to mining_taxes table
 *
 * Adds compound indexes for common tax queries
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
            $table->index(['character_id', 'status', 'month'], 'idx_mining_taxes_char_status_month');

            // Index for paid_at queries (payment history)
            $table->index('paid_at', 'idx_mining_taxes_paid_at');

            // Index for calculated_at queries
            $table->index('calculated_at', 'idx_mining_taxes_calculated_at');
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
            $table->dropIndex('idx_mining_taxes_char_status_month');
            $table->dropIndex('idx_mining_taxes_paid_at');
            $table->dropIndex('idx_mining_taxes_calculated_at');
        });
    }
}
