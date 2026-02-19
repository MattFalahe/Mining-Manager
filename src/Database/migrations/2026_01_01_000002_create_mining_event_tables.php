<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates event tables: mining events and event participants.
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
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('mining_events');
    }
};
