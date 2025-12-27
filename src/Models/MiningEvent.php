<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\MapDenormalize;
use Seat\Web\Models\User;

class MiningEvent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mining_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'start_time',
        'end_time',
        'solar_system_id',
        'status',
        'participant_count',
        'total_mined',
        'bonus_percentage',
        'created_by',
        'last_updated',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'participant_count' => 'integer',
        'total_mined' => 'integer',
        'bonus_percentage' => 'decimal:2',
        'last_updated' => 'datetime',
    ];

    /**
     * Get the participants for this event.
     */
    public function participants()
    {
        return $this->hasMany(EventParticipant::class, 'event_id');
    }

    /**
     * Get the solar system.
     */
    public function solarSystem()
    {
        return $this->belongsTo(MapDenormalize::class, 'solar_system_id', 'itemID');
    }

    /**
     * Get the user who created the event.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope a query to only include active events.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include planned events.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePlanned($query)
    {
        return $query->where('status', 'planned');
    }

    /**
     * Scope a query to only include completed events.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to filter by date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $startDate
     * @param string $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('start_time', [$startDate, $endDate]);
    }

    /**
     * Check if event is currently active.
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->status === 'active' &&
               now()->greaterThanOrEqualTo($this->start_time) &&
               (!$this->end_time || now()->lessThanOrEqualTo($this->end_time));
    }

    /**
     * Check if event is in the future.
     *
     * @return bool
     */
    public function isFuture()
    {
        return $this->status === 'planned' && now()->lessThan($this->start_time);
    }

    /**
     * Get event duration in hours.
     *
     * @return float|null
     */
    public function getDuration()
    {
        if (!$this->end_time) {
            return null;
        }

        return $this->start_time->diffInHours($this->end_time);
    }

    /**
     * Get average ore per participant.
     *
     * @return float
     */
    public function getAveragePerParticipant()
    {
        if ($this->participant_count <= 0) {
            return 0;
        }

        return $this->total_mined / $this->participant_count;
    }

    /**
     * Check if a user is participating in this event.
     *
     * @param \Seat\Web\Models\User|int $user
     * @return bool
     */
    public function isParticipating($user)
    {
        $userId = is_object($user) ? $user->id : $user;
        
        return $this->participants()
            ->where('character_id', $userId)
            ->exists();
    }
}
