<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates support tables: reports, statistics, summaries, theft incidents, webhooks.
     */
    public function up(): void
    {
        // Mining Reports - generated report records
        Schema::create('mining_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('report_type', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('format', 10)->default('pdf');
            $table->longText('data')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedInteger('generated_by')->nullable();
            $table->timestamps();
        });

        // Monthly Statistics - aggregated monthly mining and tax statistics
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

        // Mining Ledger Monthly Summaries - pre-computed monthly aggregates per character
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

        // Mining Ledger Daily Summaries - pre-computed daily aggregates per character
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

        // Theft Incidents - detected mining tax theft tracking
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
            $table->unsignedInteger('resolved_by')->nullable();
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
        });

        // Webhook Configurations - notification webhooks for Discord/Slack/custom
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

        // Notification Log - tracks sent notifications for history/debugging
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mining_notification_log');
        Schema::dropIfExists('webhook_configurations');
        Schema::dropIfExists('theft_incidents');
        Schema::dropIfExists('mining_ledger_daily_summaries');
        Schema::dropIfExists('mining_ledger_monthly_summaries');
        Schema::dropIfExists('monthly_statistics');
        Schema::dropIfExists('mining_reports');
    }
};
