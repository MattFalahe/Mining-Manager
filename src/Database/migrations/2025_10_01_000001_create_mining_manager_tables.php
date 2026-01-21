<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMiningManagerTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Mining Ledger Table
        Schema::create('mining_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('character_id')->unsigned();
            $table->date('date');
            $table->integer('type_id')->unsigned();
            $table->bigInteger('quantity')->unsigned();
            $table->integer('solar_system_id')->unsigned();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('character_id');
            $table->index('date');
            $table->index('type_id');
            $table->index('solar_system_id');
            $table->index(['character_id', 'date']);
        });

        // Mining Taxes Table
        Schema::create('mining_taxes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('character_id')->unsigned();
            $table->date('month');
            $table->decimal('amount_owed', 20, 2)->default(0);
            $table->decimal('amount_paid', 20, 2)->default(0);
            $table->enum('status', ['unpaid', 'partial', 'paid', 'overdue', 'waived'])->default('unpaid');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_reminder_sent')->nullable();
            $table->integer('reminder_count')->default(0);
            $table->bigInteger('transaction_id')->unsigned()->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('character_id');
            $table->index('month');
            $table->index('status');
            $table->index(['character_id', 'month']);
            $table->unique(['character_id', 'month']);
        });

        // Mining Events Table
        Schema::create('mining_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->integer('solar_system_id')->unsigned()->nullable();
            $table->enum('status', ['planned', 'active', 'completed', 'cancelled'])->default('planned');
            $table->integer('participant_count')->default(0);
            $table->bigInteger('total_mined')->default(0);
            $table->decimal('bonus_percentage', 5, 2)->default(0);
            $table->bigInteger('created_by')->unsigned();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('start_time');
            $table->index('solar_system_id');
            $table->index('created_by');
        });

        // Event Participants Table
        Schema::create('event_participants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('event_id')->unsigned();
            $table->bigInteger('character_id')->unsigned();
            $table->bigInteger('quantity_mined')->default(0);
            $table->decimal('bonus_earned', 20, 2)->default(0);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('mining_events')->onDelete('cascade');
            $table->index('event_id');
            $table->index('character_id');
            $table->unique(['event_id', 'character_id']);
        });

        // Moon Extractions Table
        Schema::create('moon_extractions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('structure_id')->unsigned();
            $table->bigInteger('corporation_id')->unsigned();
            $table->integer('moon_id')->unsigned()->nullable();
            $table->timestamp('extraction_start_time');
            $table->timestamp('chunk_arrival_time');
            $table->timestamp('natural_decay_time');
            $table->enum('status', ['extracting', 'ready', 'expired', 'fractured'])->default('extracting');
            $table->bigInteger('estimated_value')->unsigned()->nullable();
            $table->json('ore_composition')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->timestamps();

            $table->index('structure_id');
            $table->index('corporation_id');
            $table->index('status');
            $table->index('chunk_arrival_time');
            $table->index(['structure_id', 'extraction_start_time']);
        });

        // Tax Invoices Table
        Schema::create('tax_invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('mining_tax_id')->unsigned();
            $table->bigInteger('character_id')->unsigned();
            $table->decimal('amount', 20, 2);
            $table->enum('status', ['pending', 'sent', 'accepted', 'rejected', 'expired'])->default('pending');
            $table->bigInteger('contract_id')->unsigned()->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('mining_tax_id')->references('id')->on('mining_taxes')->onDelete('cascade');
            $table->index('mining_tax_id');
            $table->index('character_id');
            $table->index('status');
            $table->index('contract_id');
        });

        // Mining Reports Table
        Schema::create('mining_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('report_type');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('format')->default('json');
            $table->longText('data')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('generated_by')->nullable();
            $table->timestamps();

            $table->index('report_type');
            $table->index('start_date');
            $table->index('generated_at');
        });

        // Settings Table
        Schema::create('mining_manager_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->bigInteger('corporation_id')->unsigned()->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('key');
            $table->index('corporation_id');
        });

        // Price Cache Table
        Schema::create('mining_price_cache', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('type_id')->unsigned();
            $table->integer('region_id')->unsigned();
            $table->decimal('sell_price', 20, 2)->nullable();
            $table->decimal('buy_price', 20, 2)->nullable();
            $table->decimal('average_price', 20, 2)->nullable();
            $table->bigInteger('volume')->unsigned()->nullable();
            $table->timestamp('cached_at');
            $table->timestamps();

            $table->index('type_id');
            $table->index('region_id');
            $table->index(['type_id', 'region_id']);
            $table->index('cached_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mining_price_cache');
        Schema::dropIfExists('mining_manager_settings');
        Schema::dropIfExists('mining_reports');
        Schema::dropIfExists('tax_invoices');
        Schema::dropIfExists('moon_extractions');
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('mining_events');
        Schema::dropIfExists('mining_taxes');
        Schema::dropIfExists('mining_ledger');
    }
}
