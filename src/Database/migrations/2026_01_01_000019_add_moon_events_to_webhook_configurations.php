<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMoonEventsToWebhookConfigurations extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->boolean('notify_moon_arrival')->default(false)->after('notify_incident_resolved');
            $table->boolean('notify_jackpot_detected')->default(false)->after('notify_moon_arrival');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->dropColumn(['notify_moon_arrival', 'notify_jackpot_detected']);
        });
    }
}
