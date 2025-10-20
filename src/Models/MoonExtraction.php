<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Universe\UniverseMoon;

class MoonExtraction extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'moon_extractions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'structure_id',
        'corporation_id',
        'moon_id',
        'extraction_start_time',
        'chunk_arrival_time',
        'natural_decay_time',
        'status',
        'estimated_value',
        'ore_composition',
        'notification_sent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'extraction_start_time' => 'datetime',
        'chunk_arrival_time' => 'datetime',
        'natural_decay_time' => 'datetime',
        'estimated_value' => 'integer',
        'ore_composition' => 'array',
        'notification_sent' => 'boolean',
    ];

    /**
     * Get the corporation.
     */
    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    /**
     * Get the moon.
     */
    public function moon()
    {
        return $this->belongsTo(UniverseMoon::class, 'moon_id', 'moon_id');
    }

    /**
     * Get the structure (refinery).
     */
    public function structure()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStructure::class, 'structure_id', 'structure_id');
    }

    /**
     * Scope a query to only include active extractions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExtracting($query)
    {
        return $query->where('status', 'extracting');
    }

    /**
     * Scope a query to only include ready extractions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    /**
     * Scope a query for upcoming extractions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $hours
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUpcoming($query, $hours = 48)
    {
        return $query->where('status', 'extracting')
            ->where('chunk_arrival_time', '>=', now())
            ->where('chunk_arrival_time', '<=', now()->addHours($hours));
    }

    /**
     * Check if extraction is ready to mine.
     *
     * @return bool
     */
    public function isReady()
    {
        return $this->status === 'ready' || 
               (now()->greaterThanOrEqualTo($this->chunk_arrival_time) && 
                now()->lessThan($this->natural_decay_time));
    }

    /**
     * Check if extraction has expired.
     *
     * @return bool
     */
    public function isExpired()
    {
        return now()->greaterThan($this->natural_decay_time);
    }

    /**
     * Get hours until chunk arrival.
     *
     * @return float|null
     */
    public function getHoursUntilArrival()
    {
        if (now()->greaterThan($this->chunk_arrival_time)) {
            return null;
        }

        return now()->diffInHours($this->chunk_arrival_time, false);
    }

    /**
     * Get hours until decay.
     *
     * @return float|null
     */
    public function getHoursUntilDecay()
    {
        if (now()->greaterThan($this->natural_decay_time)) {
            return null;
        }

        return now()->diffInHours($this->natural_decay_time, false);
    }
}
