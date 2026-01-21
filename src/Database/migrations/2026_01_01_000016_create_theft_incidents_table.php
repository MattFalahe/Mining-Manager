<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTheftIncidentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('theft_incidents', function (Blueprint $table) {
            $table->id();

            // Character information
            $table->bigInteger('character_id')->index();
            $table->string('character_name')->nullable(); // Cache for unregistered users
            $table->bigInteger('corporation_id')->nullable()->index();

            // Related tax record
            $table->bigInteger('mining_tax_id')->nullable()->index();
            $table->foreign('mining_tax_id')
                  ->references('id')
                  ->on('mining_taxes')
                  ->onDelete('set null');

            // Incident details
            $table->dateTime('incident_date')->index(); // When detected
            $table->date('mining_date_from')->index(); // Period start
            $table->date('mining_date_to')->index(); // Period end

            // Financial details
            $table->decimal('ore_value', 15, 2)->default(0); // Total value mined
            $table->decimal('tax_owed', 15, 2)->default(0); // Unpaid tax amount
            $table->bigInteger('quantity_mined')->default(0); // Total ore quantity

            // Status tracking
            $table->enum('status', ['detected', 'investigating', 'resolved', 'false_alarm', 'removed_paid'])
                  ->default('detected')
                  ->index();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])
                  ->default('low')
                  ->index();

            // Theft list management
            $table->boolean('on_theft_list')->default(true)->index();

            // Additional information
            $table->text('notes')->nullable();

            // Resolution tracking
            $table->dateTime('resolved_at')->nullable();
            $table->integer('resolved_by')->nullable(); // user_id

            // Notification tracking
            $table->dateTime('notified_at')->nullable();

            // Active theft tracking
            $table->boolean('is_active_theft')->default(false)->index();
            $table->dateTime('last_activity_at')->nullable();
            $table->integer('activity_count')->default(1);

            $table->timestamps();

            // Composite indexes for common queries
            $table->index(['character_id', 'status']);
            $table->index(['status', 'severity']);
            $table->index(['mining_date_from', 'mining_date_to']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('theft_incidents');
    }
}
