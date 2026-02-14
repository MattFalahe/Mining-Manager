<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTypeAndLocationToMiningEvents extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds event type and location scope fields to mining_events table.
     * - type: mining_op, moon_extraction, ice_mining, gas_huffing, special
     * - location_scope: any (global), system, constellation, region
     */
    public function up(): void
    {
        Schema::table('mining_events', function (Blueprint $table) {
            // Event type for categorization
            $table->string('type', 50)->default('mining_op')->after('description');

            // Location scope determines if solar_system_id refers to system, constellation, or region
            $table->string('location_scope', 20)->default('any')->after('solar_system_id');
            // 'any' = global event (solar_system_id is null)
            // 'system' = specific solar system
            // 'constellation' = constellation (itemID from mapDenormalize)
            // 'region' = region (itemID from mapDenormalize)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mining_events', function (Blueprint $table) {
            $table->dropColumn(['type', 'location_scope']);
        });
    }
}
