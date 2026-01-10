<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMiningEventAndMoonTables extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates event tables (mining events, participants) and
     * moon tables (active extractions, extraction history archive).
     */
    public function up(): void
    {
        // Mining Events - scheduled mining operations with tax modifiers
        Schema::create('mining_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 50)->default('mining_op');
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->unsignedInteger('solar_system_id')->nullable();
            $table->string('location_scope', 20)->default('any');
            $table->string('status', 20)->default('planned');
            $table->integer('participant_count')->default(0);
            $table->integer('total_mined')->default(0);
            $table->integer('tax_modifier')->default(0);
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            $table->index('corporation_id');
            $table->index('status');
            $table->index('start_time');
            $table->index('created_by');
        });

        // Event Participants - tracks character participation in mining events
        Schema::create('event_participants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('character_id');
            $table->integer('quantity_mined')->default(0);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            $table->index('event_id');
            $table->index('character_id');

            // Prevent duplicate event participation records
            $table->unique(['event_id', 'character_id'], 'event_participants_event_char_unique');

            $table->foreign('event_id')
                ->references('id')
                ->on('mining_events')
                ->onDelete('cascade');
        });

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
            $table->boolean('auto_fractured')->default(false);

            $table->timestamps();

            $table->index('structure_id');
            $table->index('corporation_id');
            $table->index('moon_id');
            $table->index('status');
            $table->index('chunk_arrival_time');

            // Prevent duplicate extraction records for same structure + start time
            $table->unique(
                ['structure_id', 'extraction_start_time'],
                'moon_extractions_structure_start_unique'
            );
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
            $table->boolean('auto_fractured')->default(false);
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
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('mining_events');
    }
}
