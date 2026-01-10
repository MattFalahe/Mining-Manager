<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCompositeIndexCorpMoonOre extends Migration
{
    /**
     * Add composite index for corporation moon ore queries.
     * Optimizes the dashboard "Top Miners - Moon Ore" query which filters
     * on corporation_id + is_moon_ore simultaneously.
     */
    public function up(): void
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->index(
                ['corporation_id', 'is_moon_ore', 'character_id'],
                'idx_mining_ledger_corp_moon_char'
            );
        });
    }

    public function down(): void
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropIndex('idx_mining_ledger_corp_moon_char');
        });
    }
}
