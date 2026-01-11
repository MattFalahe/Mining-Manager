<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create monthly_statistics table for pre-calculated dashboard data
 *
 * This table stores pre-calculated statistics for closed months to avoid
 * recalculating historical data on every page load. Closed months are
 * calculated once and stored permanently.
 *
 * Migration: 2026_01_01_000012
 */
class CreateMonthlyStatisticsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('monthly_statistics', function (Blueprint $table) {
            $table->id();

            // Identifiers
            $table->integer('user_id')->unsigned()->nullable();
            $table->integer('character_id')->unsigned()->nullable();
            $table->integer('corporation_id')->unsigned()->nullable();

            // Time period
            $table->integer('year')->unsigned();
            $table->integer('month')->unsigned(); // 1-12
            $table->date('month_start');
            $table->date('month_end');
            $table->boolean('is_closed')->default(false);

            // Mining Statistics
            $table->decimal('total_quantity', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->decimal('ore_value', 20, 2)->default(0);
            $table->decimal('mineral_value', 20, 2)->default(0);

            // Tax Statistics
            $table->decimal('tax_owed', 20, 2)->default(0);
            $table->decimal('tax_paid', 20, 2)->default(0);
            $table->decimal('tax_pending', 20, 2)->default(0);
            $table->decimal('tax_overdue', 20, 2)->default(0);

            // Ore Type Breakdown
            $table->decimal('moon_ore_value', 20, 2)->default(0);
            $table->decimal('ice_value', 20, 2)->default(0);
            $table->decimal('gas_value', 20, 2)->default(0);
            $table->decimal('regular_ore_value', 20, 2)->default(0);

            // Mining Days
            $table->integer('mining_days')->default(0);
            $table->integer('total_days')->default(0);

            // Chart Data (stored as JSON)
            $table->json('daily_chart_data')->nullable();
            $table->json('ore_type_chart_data')->nullable();
            $table->json('value_breakdown_chart_data')->nullable();

            // Top Performers (stored as JSON)
            $table->json('top_miners')->nullable();
            $table->json('top_systems')->nullable();

            // Metadata
            $table->timestamp('calculated_at');
            $table->timestamps();

            // Indexes for fast lookups
            $table->index(['user_id', 'year', 'month'], 'idx_monthly_stats_user_period');
            $table->index(['character_id', 'year', 'month'], 'idx_monthly_stats_char_period');
            $table->index(['corporation_id', 'year', 'month'], 'idx_monthly_stats_corp_period');
            $table->index(['is_closed'], 'idx_monthly_stats_closed');
            $table->index(['month_start', 'month_end'], 'idx_monthly_stats_dates');

            // Unique constraint - one record per user/character/corp per month
            $table->unique(['user_id', 'character_id', 'corporation_id', 'year', 'month'], 'unique_monthly_stat');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('monthly_statistics');
    }
}
