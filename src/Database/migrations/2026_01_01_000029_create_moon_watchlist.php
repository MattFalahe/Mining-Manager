<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moons worth keeping an eye on.
 *
 * Searching turns up moons that are worth having but cannot be had today:
 * somebody else is on them, or there is no refinery to spare. That knowledge
 * is worth nothing a month later when the drill comes down and nobody
 * remembers which moon it was.
 *
 * A moon on this list is one to check back on. It is shared, because the
 * people hunting moons hunt them together, and it clears itself once we
 * anchor on the moon, which is the point at which watching it is done.
 */
class CreateMoonWatchlist extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mining_manager_moon_watchlist')) {
            return;
        }

        Schema::create('mining_manager_moon_watchlist', function (Blueprint $table) {
            $table->bigIncrements('id');

            // One row per moon: the list belongs to everyone who can search.
            $table->unsignedBigInteger('moon_id')->unique('uniq_mmmw_moon');

            // Why it is worth watching, if whoever added it says.
            $table->string('note', 255)->nullable();

            // Who added it, as the main character of the acting user.
            $table->unsignedBigInteger('character_id')->nullable();
            $table->string('character_name')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mining_manager_moon_watchlist');
    }
}
