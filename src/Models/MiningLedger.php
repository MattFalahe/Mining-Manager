<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Sde\SolarSystem;
use Illuminate\Support\Facades\Log;

/**
 * Mining Ledger Model for SeAT v5.x - FIXED VERSION
 * 
 * Stores processed mining data from ESI.
 * Uses SeAT's Sde models (InvType, SolarSystem) for reference data.
 * 
 * FIXES:
 * - Enhanced error handling for relationships
 * - Multiple fallback methods for attribute accessors
 * - Proper logging for debugging
 * - Handles missing/null relationships gracefully
 */
class MiningLedger extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mining_ledger';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'character_id',
        'date',
        'type_id',
        'quantity',
        'solar_system_id',
        'processed_at',
        'unit_price',
        'ore_value',
        'mineral_value',
        'total_value',
        'tax_rate',
        'tax_amount',
        'ore_type',
        'corporation_id',
        'is_taxable',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'date' => 'date',
        'quantity' => 'integer',
        'processed_at' => 'datetime',
        'unit_price' => 'decimal:2',
        'ore_value' => 'decimal:2',
        'mineral_value' => 'decimal:2',
        'total_value' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'is_taxable' => 'boolean',
    ];

    /**
     * Get the character that owns the mining ledger entry.
     * FIXED: Added error handling
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Get the ore type information.
     * FIXED: Auto-detect primary key and handle errors
     */
    public function type()
    {
        try {
            // Try to get an instance to detect the primary key
            $typeModel = app(InvType::class);
            $primaryKey = $typeModel->getKeyName();
            
            return $this->belongsTo(InvType::class, 'type_id', $primaryKey);
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Failed to detect InvType primary key, using default', [
                'error' => $e->getMessage()
            ]);
            // Fallback to common primary key names
            return $this->belongsTo(InvType::class, 'type_id', 'typeID');
        }
    }

    /**
     * Get the solar system where mining occurred.
     * FIXED: Auto-detect primary key and handle errors
     */
    public function solarSystem()
    {
        try {
            // Try to get an instance to detect the primary key
            $systemModel = app(SolarSystem::class);
            $primaryKey = $systemModel->getKeyName();
            
            return $this->belongsTo(SolarSystem::class, 'solar_system_id', $primaryKey);
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Failed to detect SolarSystem primary key, using default', [
                'error' => $e->getMessage()
            ]);
            // Fallback: 'system_id' is most common in SeAT v5
            return $this->belongsTo(SolarSystem::class, 'solar_system_id', 'system_id');
        }
    }

    /**
     * Get the ore type name.
     * FIXED: Multiple fallback methods with proper error handling
     * 
     * @return string
     */
    public function getTypeNameAttribute()
    {
        // Method 1: Try loaded relationship
        try {
            if ($this->relationLoaded('type') && $this->type) {
                // Try multiple possible property names
                $name = $this->type->typeName 
                    ?? $this->type->name 
                    ?? $this->type->type_name
                    ?? null;
                
                if ($name && $name !== 'Unknown') {
                    return $name;
                }
            }
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Error accessing type relationship', [
                'ledger_id' => $this->id,
                'type_id' => $this->type_id,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 2: Try to load the relationship
        if ($this->type_id) {
            try {
                $type = InvType::find($this->type_id);
                if ($type) {
                    $name = $type->typeName 
                        ?? $type->name 
                        ?? $type->type_name 
                        ?? null;
                    
                    if ($name && $name !== 'Unknown') {
                        return $name;
                    }
                }
            } catch (\Exception $e) {
                Log::debug('MiningLedger: Failed to load type directly', [
                    'ledger_id' => $this->id,
                    'type_id' => $this->type_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Method 3: Try direct database query as last resort
        try {
            $typeName = \DB::table('invTypes')
                ->where('typeID', $this->type_id)
                ->value('typeName');
            
            if ($typeName) {
                return $typeName;
            }
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Failed to query invTypes table', [
                'ledger_id' => $this->id,
                'type_id' => $this->type_id,
                'error' => $e->getMessage()
            ]);
        }
        
        return 'Unknown';
    }

    /**
     * Get the solar system name.
     * FIXED: Multiple fallback methods with proper error handling
     * 
     * @return string
     */
    public function getSystemNameAttribute()
    {
        // Method 1: Try loaded relationship
        try {
            if ($this->relationLoaded('solarSystem') && $this->solarSystem) {
                // Try multiple possible property names
                $name = $this->solarSystem->solarSystemName 
                    ?? $this->solarSystem->name 
                    ?? $this->solarSystem->system_name 
                    ?? $this->solarSystem->itemName
                    ?? null;
                
                if ($name && $name !== 'Unknown') {
                    return $name;
                }
            }
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Error accessing solarSystem relationship', [
                'ledger_id' => $this->id,
                'solar_system_id' => $this->solar_system_id,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 2: Try to load the relationship
        if ($this->solar_system_id) {
            try {
                $system = SolarSystem::find($this->solar_system_id);
                if ($system) {
                    $name = $system->solarSystemName 
                        ?? $system->name 
                        ?? $system->system_name 
                        ?? $system->itemName
                        ?? null;
                    
                    if ($name && $name !== 'Unknown') {
                        return $name;
                    }
                }
            } catch (\Exception $e) {
                Log::debug('MiningLedger: Failed to load solarSystem directly', [
                    'ledger_id' => $this->id,
                    'solar_system_id' => $this->solar_system_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Method 3: Try direct database query - try multiple table names
        try {
            // Try mapSolarSystems table first (common in SeAT v5)
            $systemName = \DB::table('mapSolarSystems')
                ->where('solarSystemID', $this->solar_system_id)
                ->value('solarSystemName');
            
            if ($systemName) {
                return $systemName;
            }
            
            // Try alternative table name
            $systemName = \DB::table('map_solar_systems')
                ->where('system_id', $this->solar_system_id)
                ->value('name');
            
            if ($systemName) {
                return $systemName;
            }
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Failed to query solar systems table', [
                'ledger_id' => $this->id,
                'solar_system_id' => $this->solar_system_id,
                'error' => $e->getMessage()
            ]);
        }
        
        return 'Unknown';
    }

    /**
     * Get the security status of the solar system.
     * FIXED: Enhanced error handling
     * 
     * @return float|null
     */
    public function getSecurityStatusAttribute()
    {
        try {
            if ($this->relationLoaded('solarSystem') && $this->solarSystem) {
                $security = $this->solarSystem->security 
                    ?? $this->solarSystem->security_status 
                    ?? null;
                return $security !== null ? round($security, 1) : null;
            }
            
            // Try to load it if not loaded
            if ($this->solar_system_id) {
                $system = SolarSystem::find($this->solar_system_id);
                if ($system) {
                    $security = $system->security ?? $system->security_status ?? null;
                    return $security !== null ? round($security, 1) : null;
                }
            }
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Failed to get security status', [
                'ledger_id' => $this->id,
                'solar_system_id' => $this->solar_system_id,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Scope a query to only include processed entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProcessed($query)
    {
        return $query->whereNotNull('processed_at');
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
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by character.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $characterId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCharacter($query, $characterId)
    {
        return $query->where('character_id', $characterId);
    }

    /**
     * Scope a query to only include taxable entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTaxable($query)
    {
        return $query->where('is_taxable', true);
    }

    /**
     * Scope a query to filter by corporation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $corporationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCorporation($query, $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    /**
     * Get estimated value based on current market prices.
     * FIXED: Added error handling
     *
     * @return float|null
     */
    public function getEstimatedValue()
    {
        try {
            $priceCache = MiningPriceCache::where('type_id', $this->type_id)
                ->where('region_id', config('mining-manager.pricing.default_region_id', 10000002))
                ->latest('cached_at')
                ->first();

            if (!$priceCache) {
                return null;
            }

            $priceType = config('mining-manager.pricing.price_type', 'sell');
            $price = match ($priceType) {
                'sell' => $priceCache->sell_price,
                'buy' => $priceCache->buy_price,
                'average' => $priceCache->average_price,
                default => $priceCache->sell_price,
            };

            return $this->quantity * ($price ?? 0);
        } catch (\Exception $e) {
            Log::error('MiningLedger: Failed to get estimated value', [
                'ledger_id' => $this->id,
                'type_id' => $this->type_id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Calculate and update the ore value.
     * FIXED: Added error handling
     * 
     * @return void
     */
    public function calculateValue()
    {
        try {
            $priceCache = MiningPriceCache::where('type_id', $this->type_id)
                ->where('region_id', config('mining-manager.pricing.default_region_id', 10000002))
                ->latest('cached_at')
                ->first();

            if ($priceCache) {
                $priceType = config('mining-manager.pricing.price_type', 'sell');
                $price = match ($priceType) {
                    'sell' => $priceCache->sell_price,
                    'buy' => $priceCache->buy_price,
                    'average' => $priceCache->average_price,
                    default => $priceCache->sell_price,
                };

                $this->unit_price = $price ?? 0;
                $this->ore_value = $this->quantity * $this->unit_price;
                $this->total_value = $this->ore_value;
            }
        } catch (\Exception $e) {
            Log::error('MiningLedger: Failed to calculate value', [
                'ledger_id' => $this->id,
                'type_id' => $this->type_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate and apply tax.
     * 
     * @param float $taxRate
     * @return void
     */
    public function applyTax(float $taxRate)
    {
        if (!$this->is_taxable) {
            return;
        }

        $this->tax_rate = $taxRate;
        $this->tax_amount = $this->total_value * ($taxRate / 100);
    }
}
