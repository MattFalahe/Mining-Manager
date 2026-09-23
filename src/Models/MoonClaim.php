<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A report that another corporation already holds a moon.
 *
 * A row with no cleared_at is a live claim. Clearing closes the row instead of
 * removing it, so a moon that changes hands keeps its history.
 */
class MoonClaim extends Model
{
    protected $table = 'mining_manager_moon_claims';

    protected $fillable = [
        'moon_id',
        'claimed_by',
        'note',
        'character_id',
        'character_name',
        'cleared_at',
        'cleared_by',
        'cleared_by_name',
    ];

    protected $casts = [
        'moon_id' => 'integer',
        'character_id' => 'integer',
        'cleared_at' => 'datetime',
        'cleared_by' => 'integer',
    ];

    public function scopeLive($query)
    {
        return $query->whereNull('cleared_at');
    }
}
