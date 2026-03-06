<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMiningCoreTables extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates core financial tables: ledger, taxes, tax codes, invoices, price cache, settings.
     */
    public function up(): void
    {
        // Mining Ledger - tracks individual mining activity records
        Schema::create('mining_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->date('date');
            $table->unsignedInteger('type_id');
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('solar_system_id')->nullable();
            $table->unsignedBigInteger('observer_id')->nullable();
            $table->timestamp('processed_at')->nullable();

            // Value columns
            $table->decimal('unit_price', 20, 2)->default(0);
            $table->decimal('ore_value', 20, 2)->default(0);
            $table->decimal('mineral_value', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);

            // Tax columns
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 20, 2)->default(0);

            // Ore classification
            $table->string('ore_type')->nullable();
            $table->string('ore_category', 20)->nullable()->comment('ore, moon_r4-r64, ice, gas, abyssal');
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_moon_ore')->default(false);
            $table->boolean('is_ice')->default(false);
            $table->boolean('is_gas')->default(false);
            $table->boolean('is_abyssal')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique constraint to prevent duplicate entries
            $table->unique(
                ['character_id', 'date', 'type_id', 'solar_system_id', 'observer_id'],
                'unique_mining_entry'
            );

            // Primary lookup indexes
            $table->index('character_id');
            $table->index('observer_id');
            $table->index('corporation_id');

            // Performance indexes for common queries
            $table->index('processed_at', 'idx_mining_ledger_processed_at');
            $table->index(
                ['character_id', 'date', 'processed_at'],
                'idx_mining_ledger_char_date_proc'
            );
            $table->index(
                ['date', 'processed_at'],
                'idx_mining_ledger_date_proc'
            );
            $table->index('total_value', 'idx_mining_ledger_total_value');
            $table->index('ore_type', 'idx_mining_ledger_ore_type');
            $table->index('is_taxable');
            $table->index('ore_category', 'idx_mining_ledger_ore_category');
            $table->index('is_moon_ore', 'idx_mining_ledger_is_moon_ore');
            $table->index('is_ice', 'idx_mining_ledger_is_ice');
            $table->index('is_gas', 'idx_mining_ledger_is_gas');
            $table->index('is_abyssal', 'idx_mining_ledger_is_abyssal');
        });

        // Mining Taxes - monthly tax records per character
        Schema::create('mining_taxes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->date('month');
            $table->decimal('amount_owed', 20, 2)->default(0);
            $table->decimal('amount_paid', 20, 2)->default(0);
            $table->string('status', 20)->default('unpaid');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('last_reminder_sent')->nullable();
            $table->integer('reminder_count')->default(0);
            $table->string('transaction_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique constraint to prevent duplicate monthly tax records
            $table->unique(['character_id', 'month'], 'mining_taxes_char_month_unique');

            // Performance indexes
            $table->index(
                ['character_id', 'status', 'month'],
                'idx_mining_taxes_char_status_month'
            );
            $table->index('paid_at', 'idx_mining_taxes_paid_at');
            $table->index('calculated_at', 'idx_mining_taxes_calculated_at');
        });

        // Mining Tax Codes - payment verification codes linked to tax records
        Schema::create('mining_tax_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('mining_tax_id');
            $table->unsignedBigInteger('character_id');
            $table->string('code', 32);
            $table->string('status', 20)->default('active');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('transaction_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('mining_tax_id');
            $table->index('character_id');
            $table->index('code');

            $table->foreign('mining_tax_id')
                ->references('id')
                ->on('mining_taxes')
                ->onDelete('cascade');
        });

        // Tax Invoices - invoice records for unpaid taxes
        Schema::create('tax_invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('mining_tax_id');
            $table->unsignedBigInteger('character_id');
            $table->decimal('amount', 20, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('mining_tax_id');
            $table->index('character_id');

            $table->foreign('mining_tax_id')
                ->references('id')
                ->on('mining_taxes')
                ->onDelete('cascade');
        });

        // Mining Price Cache - cached market prices for ore types
        Schema::create('mining_price_cache', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('type_id');
            $table->unsignedInteger('region_id')->nullable();
            $table->decimal('sell_price', 20, 2)->default(0);
            $table->decimal('buy_price', 20, 2)->default(0);
            $table->decimal('average_price', 20, 2)->default(0);
            $table->integer('volume')->default(0);
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();

            $table->unique(['type_id', 'region_id']);
            $table->index('type_id');
        });

        // Mining Manager Settings - key-value configuration store (multi-corp aware)
        Schema::create('mining_manager_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['key', 'corporation_id'], 'settings_key_corp_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mining_manager_settings');
        Schema::dropIfExists('mining_price_cache');
        Schema::dropIfExists('tax_invoices');
        Schema::dropIfExists('mining_tax_codes');
        Schema::dropIfExists('mining_taxes');
        Schema::dropIfExists('mining_ledger');
    }
}
