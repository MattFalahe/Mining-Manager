<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moons somebody else already holds.
 *
 * Find Moons searches every moon SeAT has a scan for, and a good chunk of the
 * best ones already have another corporation's refinery on them. Nothing in
 * the game tells us that: ESI only reports our own structures, so the first
 * time anyone finds out is when they warp in and see a drill sitting there.
 * That knowledge then lives in someone's head or a channel nobody reads, and
 * the next person searching walks into the same moon.
 *
 * So the people doing the searching record it here, and the search can hide
 * those moons. Clearing a claim closes the row rather than deleting it, so a
 * moon that changes hands keeps the history of who thought what and when.
 */
class CreateMoonClaims extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mining_manager_moon_claims')) {
            return;
        }

        Schema::create('mining_manager_moon_claims', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('moon_id');

            // Who holds it and anything worth remembering. Both optional: a
            // moon can be known to be taken without knowing by whom.
            $table->string('claimed_by', 100)->nullable();
            $table->string('note', 255)->nullable();

            // Whoever reported it, as the main character of the acting user.
            $table->unsignedBigInteger('character_id')->nullable();
            $table->string('character_name')->nullable();

            // Set when the moon is found free again. Null is a live claim.
            $table->timestamp('cleared_at')->nullable();
            $table->unsignedBigInteger('cleared_by')->nullable();
            $table->string('cleared_by_name')->nullable();

            $table->timestamps();

            $table->index(['moon_id', 'cleared_at'], 'idx_mmmc_moon_cleared');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mining_manager_moon_claims');
    }
}
