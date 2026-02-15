<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEventNotificationsToWebhookConfigurations extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds event notification flags to webhook_configurations table.
     */
    public function up(): void
    {
        Schema::table('webhook_configurations', function (Blueprint $table) {
            // Event notification flags
            $table->boolean('notify_event_created')->default(false)->after('notify_jackpot_detected');
            $table->boolean('notify_event_started')->default(true)->after('notify_event_created');
            $table->boolean('notify_event_completed')->default(false)->after('notify_event_started');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'notify_event_created',
                'notify_event_started',
                'notify_event_completed',
            ]);
        });
    }
}
