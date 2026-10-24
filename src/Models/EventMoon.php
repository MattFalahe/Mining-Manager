<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A refinery a moon event counts.
 *
 * A moon event with none of these counts every refinery in its location. One
 * with some counts only theirs: a refinery has a single belt at a time, so its
 * mining on the event's days is exactly that chunk's.
 */
class EventMoon extends Model
{
    protected $table = 'mining_manager_event_moons';

    protected $fillable = [
        'event_id',
        'structure_id',
        'moon_id',
    ];

    protected $casts = [
        'event_id' => 'integer',
        'structure_id' => 'integer',
        'moon_id' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(MiningEvent::class, 'event_id');
    }
}
