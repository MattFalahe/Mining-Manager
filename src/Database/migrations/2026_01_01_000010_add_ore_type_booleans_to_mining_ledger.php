<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add ore type boolean columns to mining_ledger table
 *
 * The LedgerController filters by ore types using these boolean columns.
 * This migration adds them and populates based on the ore_type string column.
 *
 * Migration: 2026_01_01_000010
 */
class AddOreTypeBooleansToMiningLedger extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            // Add boolean columns for ore type filtering
            $table->boolean('is_moon_ore')->default(false)->after('ore_type');
            $table->boolean('is_ice')->default(false)->after('is_moon_ore');
            $table->boolean('is_gas')->default(false)->after('is_ice');
            $table->boolean('is_abyssal')->default(false)->after('is_gas');
        });

        // Populate the boolean columns based on existing ore_type data
        if (Schema::hasColumn('mining_ledger', 'ore_type')) {
            DB::statement("UPDATE mining_ledger SET is_moon_ore = 1 WHERE ore_type = 'moon_ore'");
            DB::statement("UPDATE mining_ledger SET is_ice = 1 WHERE ore_type = 'ice'");
            DB::statement("UPDATE mining_ledger SET is_gas = 1 WHERE ore_type = 'gas'");
            DB::statement("UPDATE mining_ledger SET is_abyssal = 1 WHERE ore_type = 'abyssal'");
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropColumn(['is_moon_ore', 'is_ice', 'is_gas', 'is_abyssal']);
        });
    }
}
