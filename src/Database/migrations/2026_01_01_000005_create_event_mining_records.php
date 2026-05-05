<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the event_mining_records table.
 *
 * This table is the single source of truth for "which mining activity
 * counts toward which event." It replaces the ad-hoc filtering that was
 * previously done inside EventTrackingService at query time.
 *
 * Rationale
 * =========
 * Previously, EventTrackingService::updateEventTracking() walked the
 * mining_ledger table with only a date+location filter, and the result
 * was folded directly into event_participants. That missed three axes:
 *   1. Corporation scope (event.corporation_id was ignored)
 *   2. Sub-day precision (mining_ledger.date is DATE-only; participants
 *      mined any time that day got counted)
 *   3. Ore category (a "mining_op" event would credit ice/gas mining on
 *      the same day — but see note below about tax behaviour)
 *
 * For non-moon events we can do better than day-level because SeAT's
 * character_minings table has a `time` TIME column (SeAT-fetch time,
 * not the actual mining time, but a reasonable proxy for sub-day scope).
 *
 * By writing to a dedicated table, we make those filters happen exactly
 * once at populate time, and every consumer (participant rollup, tax
 * attribution, reports) reads a simple pre-scoped list.
 *
 * Column notes
 * ============
 * - mining_time defaults to '00:00:00' for moon/observer rows (no real
 *   time is available from corporation_industry_mining_observer_data);
 *   holds the actual SeAT-fetched time for character_mining rows.
 * - source distinguishes the origin so debugging / audit tooling can
 *   tell a day-level observer row apart from a time-precise personal one.
 * - observer_id is only set when source = 'observer'; kept in the unique
 *   key so we can dedup across multiple observers at the same structure.
 * - value_isk is frozen at record-creation time using OreValuationService,
 *   so changing ore prices later doesn't retroactively rewrite event
 *   history.
 *
 * Populated by EventMiningAggregator. Consumed by EventTrackingService
 * (rollup to event_participants) and LedgerSummaryService (per-row tax
 * attribution, Phase 2).
 */
class CreateEventMiningRecords extends Migration
{
    public function up(): void
    {
        Schema::create('event_mining_records', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('character_id');

            $table->date('mining_date');
            $table->time('mining_time')->default('00:00:00')
                ->comment("SeAT-fetched time from character_minings for non-moon events; '00:00:00' placeholder for moon/observer rows which have no time column");

            $table->unsignedInteger('type_id');
            $table->string('ore_category', 20)
                ->comment('ore, moon_r4-r64, ice, gas, abyssal, triglavian');
            $table->unsignedInteger('solar_system_id');

            $table->unsignedBigInteger('quantity')->default(0);
            $table->decimal('unit_price', 20, 2)->default(0);
            $table->decimal('value_isk', 20, 2)->default(0)
                ->comment('quantity × unit_price, frozen at record-creation time');

            $table->enum('source', ['observer', 'character_mining'])
                ->comment('Populated from mining_ledger observer rows (moon) or character_minings (belt/ice/gas)');
            $table->unsignedBigInteger('observer_id')->nullable()
                ->comment('Corp observer ID when source=observer; NULL for character_mining');

            $table->timestamp('recorded_at')->useCurrent()
                ->comment('When this row was materialised by EventMiningAggregator');

            $table->timestamps();

            // Uniqueness: one row per (event × character × date × time × ore × system × observer).
            // observer_id participates so the same character-day-system-type combination can
            // coexist from both an observer and a personal source — though the aggregator
            // will split by category to avoid double-counting on special events.
            $table->unique(
                ['event_id', 'character_id', 'mining_date', 'mining_time', 'type_id', 'solar_system_id', 'observer_id'],
                'event_mining_records_unique'
            );

            // Lookup indexes
            $table->index('event_id', 'event_mining_records_event_idx');
            $table->index(['event_id', 'character_id'], 'event_mining_records_event_char_idx');
            $table->index(['event_id', 'ore_category'], 'event_mining_records_event_category_idx');

            // Tax attribution join (Phase 2): LedgerSummaryService will look up
            // "does this ledger row have an event record?" by (character, date, type, system).
            $table->index(
                ['character_id', 'mining_date', 'type_id', 'solar_system_id'],
                'event_mining_records_ledger_join_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_mining_records');
    }
}
