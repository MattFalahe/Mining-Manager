<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An account that signed up for an event.
 *
 * Signing up covers every character on the account. On an event that only
 * counts people who signed up, their mining counts from the day they signed
 * up for moon mining, which refineries report by the day, and from the moment
 * they signed up for everything else.
 */
class EventSignup extends Model
{
    protected $table = 'mining_manager_event_signups';

    protected $fillable = [
        'event_id',
        'user_id',
        'character_id',
        'signed_up_at',
    ];

    protected $casts = [
        'event_id' => 'integer',
        'user_id' => 'integer',
        'character_id' => 'integer',
        'signed_up_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(MiningEvent::class, 'event_id');
    }
}
