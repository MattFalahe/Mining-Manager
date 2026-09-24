<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One pull in a rotation: a refinery, a weekday inside one of the pattern's
 * weeks, and the EVE time to fire it.
 *
 * The weekday is ISO (1 = Monday), matching Carbon's isoWeekday, so a slot
 * lands on the same day of the week in every cycle regardless of where the
 * rotation was started from.
 */
class MoonRotationSlot extends Model
{
    protected $table = 'mining_manager_moon_rotation_slots';

    protected $fillable = [
        'rotation_id',
        'week_number',
        'day_of_week',
        'time_of_day',
        'structure_id',
        'moon_id',
    ];

    protected $casts = [
        'rotation_id' => 'integer',
        'week_number' => 'integer',
        'day_of_week' => 'integer',
        'structure_id' => 'integer',
        'moon_id' => 'integer',
    ];

    public function rotation()
    {
        return $this->belongsTo(MoonRotation::class, 'rotation_id');
    }
}
