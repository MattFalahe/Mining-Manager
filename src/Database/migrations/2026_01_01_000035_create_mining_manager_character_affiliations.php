<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Names and corporations for characters SeAT does not know.
 *
 * SeAT only keeps characters it has tokens or affiliations for, so a visiting
 * miner has no name or corporation anywhere in its tables. The
 * resolve-characters command looks them up in the background and keeps the
 * answer here, and pages only ever read it.
 */
class CreateMiningManagerCharacterAffiliations extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mining_manager_character_affiliations')) {
            return;
        }

        Schema::create('mining_manager_character_affiliations', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->primary();
            $table->string('character_name', 64)->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->string('corporation_name', 128)->nullable();
            $table->unsignedBigInteger('alliance_id')->nullable();
            // esi, evewho or zkillboard; pending while a page waits for a first
            // answer; invalid for an id ESI rejects.
            $table->string('source', 16);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Laravel's own names for these run past MySQL's 64 characters.
            $table->index(['source', 'expires_at'], 'mm_char_affil_source_expires');
            $table->index('corporation_id', 'mm_char_affil_corporation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mining_manager_character_affiliations');
    }
}
