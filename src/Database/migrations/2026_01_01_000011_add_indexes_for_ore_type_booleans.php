<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add indexes for ore type boolean columns
 *
 * These indexes speed up ore type filtering in the ledger.
 * Must run after 2026_01_01_000010 which creates the columns.
 *
 * Migration: 2026_01_01_000011
 */
class AddIndexesForOreTypeBooleans extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            // Indexes for ore type boolean filtering
            $table->index('is_moon_ore', 'idx_mining_ledger_is_moon_ore');
            $table->index('is_ice', 'idx_mining_ledger_is_ice');
            $table->index('is_gas', 'idx_mining_ledger_is_gas');
            $table->index('is_abyssal', 'idx_mining_ledger_is_abyssal');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropIndex('idx_mining_ledger_is_moon_ore');
            $table->dropIndex('idx_mining_ledger_is_ice');
            $table->dropIndex('idx_mining_ledger_is_gas');
            $table->dropIndex('idx_mining_ledger_is_abyssal');
        });
    }
}
