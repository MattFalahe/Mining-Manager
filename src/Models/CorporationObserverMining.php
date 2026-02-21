<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Universe\UniverseStructure;

/**
 * Corporation Observer Mining Data Model
 * 
 * This model accesses SeAT's corporation_industry_mining_observer_data table
 * which contains ALL mining activity from corporation-owned structures.
 * 
 * This includes miners who are NOT registered in SeAT, giving complete
 * visibility into moon mining activity for taxation purposes.
 */
class CorporationObserverMining extends Model
{
    /**
     * The table associated with the model.
     * 
     * Note: This is SeAT's table, not our plugin's table
     */
    protected $table = 'corporation_industry_mining_observer_data';
    
    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';
    
    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = true;
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'last_updated' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    /**
     * Get the character who mined (may be null if not in SeAT).
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }
    
    /**
     * Get the ore type that was mined.
     * Auto-detect primary key to handle different SeAT versions.
     */
    public function type()
    {
        try {
            $typeModel = app(InvType::class);
            $primaryKey = $typeModel->getKeyName();
            return $this->belongsTo(InvType::class, 'type_id', $primaryKey);
        } catch (\Exception $e) {
            Log::debug('CorporationObserverMining: Failed to detect InvType primary key, using default', [
                'error' => $e->getMessage()
            ]);
            return $this->belongsTo(InvType::class, 'type_id', 'typeID');
        }
    }
    
    /**
     * Get the observer (structure) where mining occurred.
     */
    public function observer()
    {
        return $this->belongsTo(CorporationMiningObserver::class, 'observer_id', 'observer_id');
    }
    
    /**
     * Get the structure details.
     */
    public function structure()
    {
        return $this->belongsTo(UniverseStructure::class, 'observer_id', 'structure_id');
    }
    
    /**
     * Scope to filter by date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('last_updated', [$startDate, $endDate]);
    }
    
    /**
     * Scope to filter by specific observer/structure.
     */
    public function scopeForObserver($query, $observerId)
    {
        return $query->where('observer_id', $observerId);
    }
    
    /**
     * Scope to filter by character.
     */
    public function scopeForCharacter($query, $characterId)
    {
        return $query->where('character_id', $characterId);
    }
    
    /**
     * Scope for recent mining (last N days).
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('last_updated', '>=', now()->subDays($days));
    }
    
    /**
     * Get the character name (handles non-registered miners).
     */
    public function getCharacterNameAttribute()
    {
        if ($this->character && $this->character->name) {
            return $this->character->name;
        }
        return "Character {$this->character_id}";
    }
    
    /**
     * Get the ore name.
     */
    public function getOreNameAttribute()
    {
        return $this->type ? $this->type->typeName : "Unknown Ore";
    }
    
    /**
     * Get the structure name.
     */
    public function getStructureNameAttribute()
    {
        return $this->structure ? $this->structure->name : "Unknown Structure";
    }
    
    /**
     * Check if character is registered in SeAT.
     */
    public function isCharacterRegistered()
    {
        return $this->character !== null && $this->character->name !== null;
    }
}
