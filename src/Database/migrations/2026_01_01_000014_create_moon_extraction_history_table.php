<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMoonExtractionHistoryTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('moon_extraction_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moon_extraction_id')->index();
            $table->unsignedBigInteger('structure_id')->index();
            $table->unsignedBigInteger('corporation_id')->index();
            $table->unsignedInteger('moon_id')->nullable()->index();

            // Extraction timing
            $table->timestamp('extraction_start_time');
            $table->timestamp('chunk_arrival_time')->index();
            $table->timestamp('natural_decay_time');
            $table->timestamp('archived_at')->index(); // When it was moved to history

            // Status at time of archival
            $table->enum('final_status', ['extracting', 'ready', 'expired', 'fractured'])->default('expired');

            // Value tracking - store values at different times
            $table->unsignedBigInteger('estimated_value_at_start')->nullable()->comment('Value when extraction started');
            $table->unsignedBigInteger('estimated_value_at_arrival')->nullable()->comment('Value recalculated 2-3h before arrival');
            $table->unsignedBigInteger('final_estimated_value')->nullable()->comment('Final value at completion');

            // Ore composition (JSON) - actual volumes from notification
            $table->longText('ore_composition')->nullable();

            // Actual mining results (populated after mining)
            $table->unsignedBigInteger('actual_mined_value')->nullable()->comment('Real value based on what was actually mined');
            $table->integer('total_miners')->default(0)->comment('Number of unique miners who participated');
            $table->decimal('completion_percentage', 5, 2)->default(0)->comment('Percentage of chunk that was mined');

            // Jackpot detection
            $table->boolean('is_jackpot')->default(false);
            $table->timestamp('jackpot_detected_at')->nullable();

            // Metadata
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign key (soft, moon_extraction may be deleted)
            $table->index(['corporation_id', 'archived_at']); // For corp reports
            $table->index(['moon_id', 'archived_at']); // For moon profitability analysis
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('moon_extraction_history');
    }
}
