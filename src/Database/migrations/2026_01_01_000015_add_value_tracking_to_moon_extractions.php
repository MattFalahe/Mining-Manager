<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddValueTrackingToMoonExtractions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            // Track value at different stages
            $table->unsignedBigInteger('estimated_value_at_start')->nullable()->after('estimated_value')->comment('Value when extraction started');
            $table->unsignedBigInteger('estimated_value_pre_arrival')->nullable()->after('estimated_value_at_start')->comment('Value recalculated 2-3h before arrival');
            $table->timestamp('value_last_updated')->nullable()->after('estimated_value_pre_arrival')->comment('When value was last recalculated');

            // Track if notification was successfully processed
            $table->boolean('has_notification_data')->default(false)->after('notification_sent')->comment('Whether actual volumes from notification were found');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('moon_extractions', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_value_at_start',
                'estimated_value_pre_arrival',
                'value_last_updated',
                'has_notification_data',
            ]);
        });
    }
}
