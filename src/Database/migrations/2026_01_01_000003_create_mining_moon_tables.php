<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates moon tables: active extractions and extraction history archive.
     */
    public function up(): void
    {
        // Moon Extractions - active and recent moon extraction operations
        Schema::create('moon_extractions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('structure_id');
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedInteger('moon_id');
            $table->timestamp('extraction_start_time');
            $table->timestamp('chunk_arrival_time');
            $table->timestamp('natural_decay_time')->nullable();
            $table->string('status', 20)->default('extracting');
            $table->unsignedBigInteger('estimated_value')->default(0);
            $table->json('ore_composition')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->boolean('is_jackpot')->default(false);
            $table->timestamp('jackpot_detected_at')->nullable();

            // Value tracking at different timestamps
            $table->unsignedBigInteger('estimated_value_at_start')->nullable()
                ->comment('Value when extraction started');
            $table->unsignedBigInteger('estimated_value_pre_arrival')->nullable()
                ->comment('Value recalculated 2-3h before arrival');
            $table->timestamp('value_last_updated')->nullable()
                ->comment('When value was last recalculated');
            $table->boolean('has_notification_data')->default(false)
                ->comment('Whether actual volumes from notification were found');

            $table->timestamps();

            $table->index('structure_id');
            $table->index('corporation_id');
            $table->index('moon_id');
        });

        // Moon Extraction History - archived extraction records with mining results
        Schema::create('moon_extraction_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('moon_extraction_id')->nullable();
            $table->unsignedBigInteger('structure_id');
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedInteger('moon_id');
            $table->timestamp('extraction_start_time');
            $table->timestamp('chunk_arrival_time');
            $table->timestamp('natural_decay_time')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('final_status', 20)->default('completed');

            // Value snapshots
            $table->unsignedBigInteger('estimated_value_at_start')->nullable();
            $table->unsignedBigInteger('estimated_value_at_arrival')->nullable();
            $table->unsignedBigInteger('final_estimated_value')->nullable();
            $table->json('ore_composition')->nullable();

            // Mining results
            $table->unsignedBigInteger('actual_mined_value')->nullable();
            $table->unsignedInteger('total_miners')->default(0);
            $table->decimal('completion_percentage', 5, 2)->default(0);
            $table->boolean('is_jackpot')->default(false);
            $table->timestamp('jackpot_detected_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('moon_extraction_id');
            $table->index('structure_id');
            $table->index('corporation_id');
            $table->index('moon_id');
            $table->index(['corporation_id', 'archived_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moon_extraction_history');
        Schema::dropIfExists('moon_extractions');
    }
};
