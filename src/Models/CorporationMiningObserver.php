<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Universe\UniverseStructure;

/**
 * Corporation Mining Observer Model
 * 
 * Represents corporation-owned structures (refineries, athanors) that
 * track mining activity via observers.
 */
class CorporationMiningObserver extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'corporation_industry_mining_observers';
    
    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'observer_id';
    
    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'last_updated' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    /**
     * Get the structure details.
     */
    public function structure()
    {
        return $this->belongsTo(UniverseStructure::class, 'observer_id', 'structure_id');
    }
    
    /**
     * Get all mining activity for this observer.
     */
    public function miningActivity()
    {
        return $this->hasMany(CorporationObserverMining::class, 'observer_id', 'observer_id');
    }
    
    /**
     * Get recent mining activity.
     */
    public function recentMining($days = 7)
    {
        return $this->miningActivity()
            ->where('last_updated', '>=', now()->subDays($days));
    }
}
