<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMiningManagerTables extends Migration
{
    public function up()
    {
        // Mining Ledger
        Schema::create('mining_ledger', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('character_id')->index();
            $table->bigInteger('corporation_id')->index();
            $table->integer('type_id');
            $table->decimal('quantity', 20, 2);
            $table->decimal('volume', 20, 2);
            $table->decimal('raw_value', 20, 2)->default(0);
            $table->decimal('refined_value', 20, 2)->default(0);
            $table->bigInteger('solar_system_id')->nullable();
            $table->bigInteger('structure_id')->nullable();
            $table->enum('location_type', ['belt', 'moon', 'ice_belt', 'gas_site', 'unknown'])->default('unknown');
            $table->timestamp('mined_at');
            $table->timestamps();
            
            $table->index(['character_id', 'mined_at']);
            $table->index(['corporation_id', 'mined_at']);
            $table->index(['type_id', 'mined_at']);
        });

        // Tax Records
        Schema::create('mining_taxes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('character_id')->index();
            $table->bigInteger('main_character_id')->index();
            $table->bigInteger('corporation_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('mined_value', 20, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->json('modifiers')->nullable();
            $table->decimal('tax_amount', 20, 2);
            $table->decimal('event_modifier', 20, 2)->default(0);
            $table->decimal('final_tax', 20, 2);
            $table->enum('status', ['pending', 'calculated', 'invoiced', 'paid', 'overdue', 'waived'])->default('pending');
            $table->json('breakdown')->nullable();
            $table->timestamps();
            
            $table->unique(['character_id', 'period_start', 'period_end']);
        });

        // Mining Events
        Schema::create('mining_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['bonus', 'competition', 'campaign', 'custom'])->default('custom');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->decimal('tax_modifier', 5, 2)->default(0);
            $table->json('ore_modifiers')->nullable();
            $table->json('rules')->nullable();
            $table->boolean('auto_track')->default(false);
            $table->enum('status', ['draft', 'scheduled', 'active', 'completed', 'cancelled'])->default('draft');
            $table->json('results')->nullable();
            $table->bigInteger('created_by');
            $table->timestamps();
        });

        // Event Participants
        Schema::create('mining_event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('mining_events')->onDelete('cascade');
            $table->bigInteger('character_id');
            $table->decimal('mined_quantity', 20, 2)->default(0);
            $table->decimal('mined_value', 20, 2)->default(0);
            $table->integer('rank')->nullable();
            $table->json('statistics')->nullable();
            $table->timestamps();
            
            $table->unique(['event_id', 'character_id']);
        });

        // Moon Tracking
        Schema::create('moon_extractions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('structure_id')->index();
            $table->bigInteger('moon_id');
            $table->string('moon_name');
            $table->timestamp('chunk_arrival_at');
            $table->timestamp('extraction_started_at');
            $table->timestamp('natural_decay_at');
            $table->json('ore_composition')->nullable();
            $table->decimal('estimated_value', 20, 2)->nullable();
            $table->decimal('actual_mined', 20, 2)->nullable();
            $table->enum('status', ['scheduled', 'ready', 'depleted'])->default('scheduled');
            $table->timestamps();
        });

        // Tax Invoices
        Schema::create('tax_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->bigInteger('character_id');
            $table->bigInteger('issuer_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 20, 2);
            $table->string('contract_title');
            $table->text('description')->nullable();
            $table->bigInteger('contract_id')->nullable();
            $table->enum('status', ['draft', 'issued', 'accepted', 'completed', 'rejected', 'expired'])->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Settings
        Schema::create('mining_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->string('type')->default('string');
            $table->timestamps();
        });

        // Reports
        Schema::create('mining_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->json('parameters');
            $table->string('file_path')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->bigInteger('generated_by');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mining_reports');
        Schema::dropIfExists('mining_settings');
        Schema::dropIfExists('tax_invoices');
        Schema::dropIfExists('moon_extractions');
        Schema::dropIfExists('mining_event_participants');
        Schema::dropIfExists('mining_events');
        Schema::dropIfExists('mining_taxes');
        Schema::dropIfExists('mining_ledger');
    }
}
