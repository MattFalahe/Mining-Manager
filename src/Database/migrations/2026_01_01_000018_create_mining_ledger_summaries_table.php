<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMiningLedgerSummariesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Monthly summaries - finalized at end of each month
        Schema::create('mining_ledger_monthly_summaries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('character_id')->unsigned();
            $table->date('month'); // First day of month (2025-01-01)
            $table->bigInteger('corporation_id')->unsigned()->nullable();

            // Aggregated totals
            $table->decimal('total_quantity', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->decimal('total_tax', 20, 2)->default(0);

            // Breakdown by ore category
            $table->decimal('moon_ore_value', 20, 2)->default(0);
            $table->decimal('regular_ore_value', 20, 2)->default(0);
            $table->decimal('ice_value', 20, 2)->default(0);
            $table->decimal('gas_value', 20, 2)->default(0);

            // Detailed breakdown (JSON for flexibility)
            $table->json('ore_breakdown')->nullable();

            // Status tracking
            $table->boolean('is_finalized')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->unique(['character_id', 'month']);
            $table->index('month');
            $table->index('is_finalized');
            $table->index('corporation_id');
            $table->index(['month', 'corporation_id']);
        });

        // Daily summaries - for drill-down view
        Schema::create('mining_ledger_daily_summaries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('character_id')->unsigned();
            $table->date('date');
            $table->bigInteger('corporation_id')->unsigned()->nullable();

            // Aggregated totals for the day
            $table->decimal('total_quantity', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->decimal('total_tax', 20, 2)->default(0);

            // Breakdown by ore category
            $table->decimal('moon_ore_value', 20, 2)->default(0);
            $table->decimal('regular_ore_value', 20, 2)->default(0);
            $table->decimal('ice_value', 20, 2)->default(0);
            $table->decimal('gas_value', 20, 2)->default(0);

            // Ore types mined (for display)
            $table->json('ore_types')->nullable(); // ['Bistot', 'Crokite', ...]

            // Status
            $table->boolean('is_finalized')->default(false);
            $table->timestamps();

            // Indexes
            $table->unique(['character_id', 'date']);
            $table->index('date');
            $table->index('is_finalized');
            $table->index('corporation_id');
            $table->index(['date', 'corporation_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mining_ledger_daily_summaries');
        Schema::dropIfExists('mining_ledger_monthly_summaries');
    }
}
