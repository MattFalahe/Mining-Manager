<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWebhookConfigurationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('webhook_configurations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name'); // User-friendly name for the webhook
            $table->enum('type', ['discord', 'slack', 'custom'])->default('discord');
            $table->text('webhook_url'); // The webhook URL
            $table->boolean('is_enabled')->default(true);

            // Event type flags - which events to send to this webhook
            $table->boolean('notify_theft_detected')->default(true);
            $table->boolean('notify_critical_theft')->default(true);
            $table->boolean('notify_active_theft')->default(true);
            $table->boolean('notify_incident_resolved')->default(false);

            // Discord-specific settings
            $table->string('discord_role_id')->nullable(); // Discord role ID to ping (optional)
            $table->string('discord_username')->nullable(); // Custom webhook username

            // Slack-specific settings
            $table->string('slack_channel')->nullable(); // Channel name (e.g., #mining-alerts)
            $table->string('slack_username')->nullable(); // Custom username for Slack

            // Custom webhook settings
            $table->text('custom_payload_template')->nullable(); // JSON template for custom webhooks
            $table->json('custom_headers')->nullable(); // Custom HTTP headers

            // Statistics and health
            $table->integer('success_count')->default(0);
            $table->integer('failure_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();

            // Multi-corporation support
            $table->bigInteger('corporation_id')->unsigned()->nullable();

            $table->timestamps();

            // Indexes
            $table->index('is_enabled');
            $table->index('type');
            $table->index('corporation_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('webhook_configurations');
    }
}
