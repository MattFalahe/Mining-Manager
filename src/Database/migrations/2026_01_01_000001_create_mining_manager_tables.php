<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMiningManagerTables extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates all Mining Manager tables with their final schema for dev-5.0.
     * Tables are ordered for FK-safe creation.
     */
    public function up(): void
    {
        // 1. Mining Manager Settings - key-value configuration store (multi-corp aware)
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

        // 2. Webhook Configurations - notification webhooks for Discord/Slack/custom
        Schema::create('webhook_configurations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->enum('type', ['discord', 'slack', 'custom'])->default('discord');
            $table->text('webhook_url');
            $table->boolean('is_enabled')->default(true);

            // Theft notifications
            $table->boolean('notify_theft_detected')->default(true);
            $table->boolean('notify_critical_theft')->default(true);
            $table->boolean('notify_active_theft')->default(true);
            $table->boolean('notify_incident_resolved')->default(false);

            // Moon notifications
            $table->boolean('notify_moon_arrival')->default(false);
            $table->boolean('notify_jackpot_detected')->default(false);

            // Event notifications
            $table->boolean('notify_event_created')->default(false);
            $table->boolean('notify_event_started')->default(true);
            $table->boolean('notify_event_completed')->default(false);

            // Tax notifications
            $table->boolean('notify_tax_reminder')->default(false);
            $table->boolean('notify_tax_invoice')->default(false);
            $table->boolean('notify_tax_overdue')->default(false);
            $table->boolean('notify_tax_generated')->default(false);
            $table->boolean('notify_tax_announcement')->default(false);

            // Report notifications
            $table->boolean('notify_report_generated')->default(false);

            // Platform-specific settings
            $table->string('discord_role_id')->nullable();
            $table->string('discord_username')->nullable();
            $table->string('slack_channel')->nullable();
            $table->string('slack_username')->nullable();
            $table->text('custom_payload_template')->nullable();
            $table->json('custom_headers')->nullable();

            // Delivery stats
            $table->integer('success_count')->default(0);
            $table->integer('failure_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();

            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->timestamps();

            $table->index('is_enabled');
            $table->index('type');
            $table->index('corporation_id');
        });

        // 3. Mining Ledger - tracks individual mining activity records
        Schema::create('mining_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->date('date');
            $table->unsignedInteger('type_id');
            $table->unsignedBigInteger('quantity')->default(0);
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
            $table->string('ore_category', 20)->nullable()->comment('ore, moon_r4-r64, ice, gas, abyssal, triglavian');
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_moon_ore')->default(false);
            $table->boolean('is_ice')->default(false);
            $table->boolean('is_gas')->default(false);
            $table->boolean('is_abyssal')->default(false);
            $table->boolean('is_triglavian')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique constraint to prevent duplicate entries.
            //
            // Known limitation: observer_id is nullable, and MySQL treats
            // NULL as distinct from NULL in unique constraints (so two rows
            // with observer_id=NULL don't conflict with each other). In
            // practice this matters for rows imported from ESI personal
            // mining ledger (which has no observer) vs rows from ESI
            // observer data (which does). Different data sources → rare
            // overlap. If duplicate-observer-NULL rows ever surface at
            // scale, migrate to a generated-column unique that COALESCEs
            // observer_id to 0 (requires MySQL 8+).
            $table->unique(
                ['character_id', 'date', 'type_id', 'solar_system_id', 'observer_id'],
                'unique_mining_entry'
            );

            // Primary lookup indexes (observer_id standalone omitted - covered by composite)
            $table->index('character_id');
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
            $table->index('is_triglavian', 'idx_mining_ledger_is_triglavian');
        });

        // 4. Mining Ledger Daily Summaries - pre-computed daily aggregates per character
        Schema::create('mining_ledger_daily_summaries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->date('date');
            $table->unsignedBigInteger('corporation_id')->nullable();

            $table->decimal('total_quantity', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->decimal('total_tax', 20, 2)->default(0);
            $table->decimal('moon_ore_value', 20, 2)->default(0);
            $table->decimal('regular_ore_value', 20, 2)->default(0);
            $table->decimal('ice_value', 20, 2)->default(0);
            $table->decimal('gas_value', 20, 2)->default(0);
            $table->json('ore_types')->nullable();
            $table->boolean('is_finalized')->default(false);
            $table->timestamps();

            $table->unique(['character_id', 'date']);
            $table->index('date');
            $table->index('is_finalized');
            $table->index('corporation_id');
            $table->index(['date', 'corporation_id']);
        });

        // 5. Mining Ledger Monthly Summaries - pre-computed monthly aggregates per character
        Schema::create('mining_ledger_monthly_summaries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->date('month');
            $table->unsignedBigInteger('corporation_id')->nullable();

            $table->decimal('total_quantity', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->decimal('total_tax', 20, 2)->default(0);
            $table->decimal('moon_ore_value', 20, 2)->default(0);
            $table->decimal('regular_ore_value', 20, 2)->default(0);
            $table->decimal('ice_value', 20, 2)->default(0);
            $table->decimal('gas_value', 20, 2)->default(0);
            $table->json('ore_breakdown')->nullable();
            $table->boolean('is_finalized')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'month']);
            $table->index('month');
            $table->index('is_finalized');
            $table->index('corporation_id');
            $table->index(['month', 'corporation_id']);
        });

        // 6. Mining Events - scheduled mining operations with tax modifiers
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
            $table->unsignedBigInteger('total_mined')->default(0);
            $table->integer('tax_modifier')->default(0);
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            $table->index('corporation_id');
            $table->index('status');
            $table->index('start_time');
            $table->index('created_by');
        });

        // 7. Event Participants - tracks character participation in mining events
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

        // 8. Moon Extractions - active and recent moon extraction operations
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
            $table->unsignedBigInteger('jackpot_reported_by')->nullable();
            $table->boolean('jackpot_verified')->nullable();
            $table->timestamp('jackpot_verified_at')->nullable();

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
            $table->timestamp('fractured_at')->nullable();
            $table->string('fractured_by', 255)->nullable();

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

        // 9. Moon Extraction History - archived extraction records with mining results
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
            $table->timestamp('fractured_at')->nullable();
            $table->string('fractured_by', 255)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('moon_extraction_id');
            $table->index('structure_id');
            $table->index('corporation_id');
            $table->index('moon_id');
            $table->index(['corporation_id', 'archived_at']);

            $table->foreign('moon_extraction_id')
                ->references('id')
                ->on('moon_extractions')
                ->onDelete('set null');
        });

        // 10. Mining Taxes - tax records per character per period
        Schema::create('mining_taxes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->date('month');
            $table->string('period_type', 20)->default('monthly');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
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
            $table->string('triggered_by', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique constraint: one tax per character per period start
            $table->unique(['character_id', 'period_start'], 'mining_taxes_char_period_unique');

            // Performance indexes
            $table->index(
                ['character_id', 'status', 'period_start'],
                'idx_mining_taxes_char_status_period'
            );
            $table->index('paid_at', 'idx_mining_taxes_paid_at');
            $table->index('calculated_at', 'idx_mining_taxes_calculated_at');
            $table->index(['period_type', 'period_start', 'period_end'], 'idx_mining_taxes_period');
        });

        // 11. Mining Tax Codes - payment verification codes linked to tax records
        Schema::create('mining_tax_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('mining_tax_id');
            $table->unsignedBigInteger('character_id');
            $table->string('code', 32);
            $table->string('prefix', 20)->nullable();
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

        // 12. Tax Invoices - invoice records for unpaid taxes
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

        // 13. Mining Reports - generated report records
        Schema::create('mining_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('report_type', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('format', 10)->default('pdf');
            $table->longText('data')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->timestamps();
        });

        // 14. Report Schedules - automated report generation schedules
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('report_type', 50);
            $table->string('format', 10)->default('json');
            $table->string('frequency', 20)->default('daily');
            $table->time('run_time')->default('00:00');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run')->nullable();
            $table->timestamp('next_run')->nullable();
            $table->unsignedInteger('reports_generated')->default(0);
            $table->boolean('send_to_discord')->default(false);
            $table->unsignedBigInteger('webhook_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index('next_run');
            $table->index(['is_active', 'next_run']);

            $table->foreign('webhook_id')
                ->references('id')
                ->on('webhook_configurations')
                ->onDelete('set null');
        });

        // Add FK for mining_reports.schedule_id (after report_schedules exists)
        Schema::table('mining_reports', function (Blueprint $table) {
            $table->index('schedule_id', 'idx_mining_reports_schedule_id');
            $table->foreign('schedule_id')
                ->references('id')
                ->on('report_schedules')
                ->onDelete('set null');
        });

        // 15. Notification Log - tracks sent notifications for history/debugging
        Schema::create('mining_notification_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 50);
            $table->json('recipients')->nullable();
            $table->json('channels')->nullable();
            $table->json('results')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('type');
            $table->index('created_at');
        });

        // 16. Monthly Statistics - aggregated monthly mining and tax statistics
        Schema::create('monthly_statistics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('character_id')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('month_start');
            $table->date('month_end');
            $table->boolean('is_closed')->default(false);

            // Mining totals
            $table->decimal('total_quantity', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->decimal('ore_value', 20, 2)->default(0);
            $table->decimal('mineral_value', 20, 2)->default(0);

            // Tax totals
            $table->decimal('tax_owed', 20, 2)->default(0);
            $table->decimal('tax_paid', 20, 2)->default(0);
            $table->decimal('tax_pending', 20, 2)->default(0);
            $table->decimal('tax_overdue', 20, 2)->default(0);

            // Ore breakdown by type
            $table->decimal('moon_ore_value', 20, 2)->default(0);
            $table->decimal('ice_value', 20, 2)->default(0);
            $table->decimal('gas_value', 20, 2)->default(0);
            $table->decimal('regular_ore_value', 20, 2)->default(0);

            // Activity stats
            $table->unsignedSmallInteger('mining_days')->default(0);
            $table->unsignedSmallInteger('total_days')->default(0);

            // Chart & ranking data (JSON)
            $table->json('daily_chart_data')->nullable();
            $table->json('ore_type_chart_data')->nullable();
            $table->json('value_breakdown_chart_data')->nullable();
            $table->json('top_miners')->nullable();
            $table->json('top_systems')->nullable();

            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'character_id', 'year', 'month']);
            $table->index('user_id');
            $table->index('character_id');
            $table->index('corporation_id');
        });

        // 17. Mining Price Cache - cached market prices for ore types
        Schema::create('mining_price_cache', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('type_id');
            $table->unsignedInteger('region_id')->nullable();
            $table->decimal('sell_price', 20, 2)->default(0);
            $table->decimal('buy_price', 20, 2)->default(0);
            $table->decimal('average_price', 20, 2)->default(0);
            $table->unsignedBigInteger('volume')->default(0);
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();

            // Standalone type_id index omitted - covered by unique(type_id, region_id)
            $table->unique(['type_id', 'region_id']);
        });

        // 18. Theft Incidents - detected mining tax theft tracking
        Schema::create('theft_incidents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->string('character_name')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->unsignedBigInteger('mining_tax_id')->nullable();
            $table->timestamp('incident_date');
            $table->date('mining_date_from')->nullable();
            $table->date('mining_date_to')->nullable();

            // Financial data
            $table->decimal('ore_value', 20, 2)->default(0);
            $table->decimal('tax_owed', 20, 2)->default(0);
            $table->unsignedInteger('quantity_mined')->default(0);

            // Status tracking
            $table->string('status', 20)->default('detected');
            $table->string('severity', 20)->default('low');
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('notified_at')->nullable();

            // Active theft monitoring
            $table->boolean('is_active_theft')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('activity_count')->default(0);
            $table->boolean('on_theft_list')->default(false);

            $table->timestamps();

            $table->index('character_id');
            $table->index('corporation_id');
            $table->index('mining_tax_id');
            $table->index('status');
            $table->index('severity');
            $table->index('is_active_theft');
            $table->index('on_theft_list');
            $table->index(['status', 'is_active_theft']);
            $table->index('resolved_by', 'idx_theft_incidents_resolved_by');

            $table->foreign('mining_tax_id')
                ->references('id')
                ->on('mining_taxes')
                ->onDelete('set null');
        });

        // 19. Dismissed Transactions - wallet transactions dismissed by users
        Schema::create('mining_manager_dismissed_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transaction_id')->unique();
            $table->unsignedBigInteger('dismissed_by')->nullable()->comment('Character ID of who dismissed');
            $table->timestamp('dismissed_at')->useCurrent();
        });

        // 20. Processed Transactions - wallet transactions matched to mining taxes
        Schema::create('mining_manager_processed_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transaction_id')->unique();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedBigInteger('tax_id')->nullable()->index();
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops all tables in reverse FK-safe order.
     */
    public function down(): void
    {
        Schema::dropIfExists('mining_manager_processed_transactions');
        Schema::dropIfExists('mining_manager_dismissed_transactions');
        Schema::dropIfExists('theft_incidents');
        Schema::dropIfExists('mining_price_cache');
        Schema::dropIfExists('monthly_statistics');
        Schema::dropIfExists('mining_notification_log');

        // Drop FK on mining_reports.schedule_id before dropping report_schedules
        if (Schema::hasColumn('mining_reports', 'schedule_id')) {
            Schema::table('mining_reports', function (Blueprint $table) {
                $table->dropForeign(['schedule_id']);
                $table->dropIndex('idx_mining_reports_schedule_id');
            });
        }

        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('mining_reports');
        Schema::dropIfExists('tax_invoices');
        Schema::dropIfExists('mining_tax_codes');
        Schema::dropIfExists('mining_taxes');
        Schema::dropIfExists('moon_extraction_history');
        Schema::dropIfExists('moon_extractions');
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('mining_events');
        Schema::dropIfExists('mining_ledger_monthly_summaries');
        Schema::dropIfExists('mining_ledger_daily_summaries');
        Schema::dropIfExists('mining_ledger');
        Schema::dropIfExists('webhook_configurations');
        Schema::dropIfExists('mining_manager_settings');
    }
}
