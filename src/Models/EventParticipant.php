<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;

class EventParticipant extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'event_participants';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'event_id',
        'character_id',
        'quantity_mined',
        'joined_at',
        'last_updated',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'quantity_mined' => 'integer',
        'joined_at' => 'datetime',
        'last_updated' => 'datetime',
    ];

    /**
     * Get the event.
     */
    public function event()
    {
        return $this->belongsTo(MiningEvent::class, 'event_id');
    }

    /**
     * Get the character.
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Get formatted quantity mined for display.
     *
     * @return string
     */
    public function getFormattedQuantity(): string
    {
        return number_format($this->quantity_mined);
    }
}
