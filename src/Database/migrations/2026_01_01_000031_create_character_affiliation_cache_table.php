<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CreateCharacterAffiliationCacheTable
 *
 * Local cache for character-to-corporation resolution.
 *
 * Filled by the ResolveGuestAffiliationsCommand (scheduled) and by
 * CharacterInfoService on first lookup when the local entry is missing
 * or expired.  The dashboard reads here instead of making live ESI
 * calls for every unknown miner.
 */
class CreateCharacterAffiliationCacheTable extends Migration
{
    public function up(): void
    {
        Schema::create('mining_manager_character_affiliations', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->unique();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->string('corporation_name', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('source', 32)->default('esi'); // esi | zkillboard | evewho | manual

            $table->index(['expires_at', 'corporation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mining_manager_character_affiliations');
    }
}
