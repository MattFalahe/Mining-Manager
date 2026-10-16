<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A repeating pattern of moon pulls: which refinery on which weekday at what
 * time, over a number of weeks, before it starts again.
 *
 * The rotation holds the intent. Applying it writes ordinary planned pulls
 * that carry its id, so the calendar, the coverage badges and reconciliation
 * against real extractions all keep working on plan rows as they always have.
 */
class MoonRotation extends Model
{
    protected $table = 'mining_manager_moon_rotations';

    protected $fillable = [
        'corporation_id',
        'name',
        'weeks',
        'created_by',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'weeks' => 'integer',
        'created_by' => 'integer',
    ];

    public function slots()
    {
        return $this->hasMany(MoonRotationSlot::class, 'rotation_id')
            ->orderBy('week_number')
            ->orderBy('day_of_week')
            ->orderBy('time_of_day');
    }

    public function plans()
    {
        return $this->hasMany(MoonExtractionPlan::class, 'rotation_id');
    }

    public function scopeForCorporation($query, int $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }
}
