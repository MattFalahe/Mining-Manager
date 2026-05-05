<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add ISK value tracking columns to mining events.
 *
 * Context: the event show page has always displayed "Total Mined: X ISK"
 * and "Mined Value: X ISK" per participant, but the underlying data only
 * stored `quantity_mined` (integer m³ or unit count). No ISK value was
 * ever computed or stored, so the UI showed 0 regardless of activity.
 *
 * This migration adds:
 *   - event_participants.value_mined — ISK value of ore mined during event
 *   - mining_events.total_mined_value — sum of value_mined across participants
 *
 * Values are populated by EventTrackingService::updateEventTracking() from
 * mining_ledger.total_value entries matching the participant's activity
 * during the event window.
 */
class AddValueTrackingToEvents extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('value_mined')
                ->default(0)
                ->after('quantity_mined')
                ->comment('ISK value of ore mined during event — computed from mining_ledger.total_value');
        });

        Schema::table('mining_events', function (Blueprint $table) {
            $table->unsignedBigInteger('total_mined_value')
                ->default(0)
                ->after('total_mined')
                ->comment('Sum of value_mined across all participants — ISK value');
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropColumn('value_mined');
        });

        Schema::table('mining_events', function (Blueprint $table) {
            $table->dropColumn('total_mined_value');
        });
    }
}
