<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A moon somebody wants to keep an eye on.
 *
 * One row per moon, shared by everyone who can search moons. Taking a moon
 * off the list removes the row: unlike a claim, there is nothing here worth
 * keeping once the interest has gone.
 */
class MoonWatch extends Model
{
    protected $table = 'mining_manager_moon_watchlist';

    protected $fillable = [
        'moon_id',
        'note',
        'character_id',
        'character_name',
    ];

    protected $casts = [
        'moon_id' => 'integer',
        'character_id' => 'integer',
    ];
}
