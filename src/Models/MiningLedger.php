<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    use SoftDeletes;

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
        'observer_id',
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
        'is_moon_ore',
        'is_ice',
        'is_gas',
        'is_abyssal',
        'ore_category',
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
        'is_moon_ore' => 'boolean',
        'is_ice' => 'boolean',
        'is_gas' => 'boolean',
        'is_abyssal' => 'boolean',
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
     * Cached primary key name for InvType model.
     * Detected once per request to avoid repeated reflection.
     *
     * @var string|null
     */
    protected static ?string $invTypePrimaryKey = null;

    /**
     * Cached primary key name for SolarSystem model.
     * Detected once per request to avoid repeated reflection.
     *
     * @var string|null
     */
    protected static ?string $solarSystemPrimaryKey = null;

    /**
     * Detect and cache the InvType primary key name.
     *
     * @return string
     */
    protected static function getInvTypePrimaryKey(): string
    {
        if (static::$invTypePrimaryKey === null) {
            try {
                static::$invTypePrimaryKey = app(InvType::class)->getKeyName();
            } catch (\Exception $e) {
                static::$invTypePrimaryKey = 'typeID';
            }
        }

        return static::$invTypePrimaryKey;
    }

    /**
     * Detect and cache the SolarSystem primary key name.
     *
     * @return string
     */
    public static function getSolarSystemPrimaryKey(): string
    {
        if (static::$solarSystemPrimaryKey === null) {
            try {
                static::$solarSystemPrimaryKey = app(SolarSystem::class)->getKeyName();
            } catch (\Exception $e) {
                static::$solarSystemPrimaryKey = 'system_id';
            }
        }

        return static::$solarSystemPrimaryKey;
    }

    /**
     * Get the ore type information.
     * Uses cached primary key detection (resolved once per request).
     */
    public function type()
    {
        return $this->belongsTo(InvType::class, 'type_id', static::getInvTypePrimaryKey());
    }

    /**
     * Get the solar system where mining occurred.
     * Uses cached primary key detection (resolved once per request).
     */
    public function solarSystem()
    {
        return $this->belongsTo(SolarSystem::class, 'solar_system_id', static::getSolarSystemPrimaryKey());
    }

    /**
     * Get the ore type name.
     *
     * PERFORMANCE: Only reads from the eager-loaded relationship.
     * Always use ->with('type') when querying MiningLedger collections.
     * Falls back to a single DB query only if the relationship is not loaded.
     *
     * @return string
     */
    public function getTypeNameAttribute()
    {
        try {
            // Use eager-loaded relationship (no extra query when with('type') is used)
            $type = $this->relationLoaded('type') ? $this->getRelation('type') : null;

            if ($type) {
                $name = $type->typeName ?? $type->name ?? $type->type_name ?? null;
                if ($name && $name !== 'Unknown') {
                    return $name;
                }
            }

            // Fallback: relationship not eager-loaded — single query via relationship
            if ($this->type_id && !$this->relationLoaded('type')) {
                $type = $this->type;
                if ($type) {
                    $name = $type->typeName ?? $type->name ?? $type->type_name ?? null;
                    if ($name && $name !== 'Unknown') {
                        return $name;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Error resolving type name', [
                'type_id' => $this->type_id,
                'error' => $e->getMessage(),
            ]);
        }

        return 'Unknown';
    }

    /**
     * Get the solar system name.
     *
     * PERFORMANCE: Only reads from the eager-loaded relationship.
     * Always use ->with('solarSystem') when querying MiningLedger collections.
     * Falls back to a single DB query only if the relationship is not loaded.
     *
     * @return string
     */
    public function getSystemNameAttribute()
    {
        try {
            // Use eager-loaded relationship (no extra query when with('solarSystem') is used)
            $system = $this->relationLoaded('solarSystem') ? $this->getRelation('solarSystem') : null;

            if ($system) {
                $name = $system->solarSystemName ?? $system->name ?? $system->system_name ?? $system->itemName ?? null;
                if ($name && $name !== 'Unknown') {
                    return $name;
                }
            }

            // Fallback: relationship not eager-loaded — single query via relationship
            if ($this->solar_system_id && !$this->relationLoaded('solarSystem')) {
                $system = $this->solarSystem;
                if ($system) {
                    $name = $system->solarSystemName ?? $system->name ?? $system->system_name ?? $system->itemName ?? null;
                    if ($name && $name !== 'Unknown') {
                        return $name;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Error resolving system name', [
                'solar_system_id' => $this->solar_system_id,
                'error' => $e->getMessage(),
            ]);
        }

        return 'Unknown';
    }

    /**
     * Get the security status of the solar system.
     *
     * PERFORMANCE: Only reads from the eager-loaded relationship.
     *
     * @return float|null
     */
    public function getSecurityStatusAttribute()
    {
        try {
            $system = $this->relationLoaded('solarSystem') ? $this->getRelation('solarSystem') : null;

            if (!$system && $this->solar_system_id && !$this->relationLoaded('solarSystem')) {
                $system = $this->solarSystem;
            }

            if ($system) {
                $security = $system->security ?? $system->security_status ?? null;
                return $security !== null ? round($security, 1) : null;
            }
        } catch (\Exception $e) {
            Log::debug('MiningLedger: Failed to get security status', [
                'solar_system_id' => $this->solar_system_id,
                'error' => $e->getMessage(),
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
            $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
            $generalSettings = $settingsService->getGeneralSettings();
            $pricingSettings = $settingsService->getPricingSettings();

            $priceCache = MiningPriceCache::where('type_id', $this->type_id)
                ->where('region_id', $generalSettings['default_region_id'] ?? 10000002)
                ->latest('cached_at')
                ->first();

            if (!$priceCache) {
                return null;
            }

            $priceType = $pricingSettings['price_type'] ?? 'sell';
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
     * Calculate and update the ore value using OreValuationService.
     * Properly calculates both ore_value and mineral_value based on configured valuation method.
     *
     * @return void
     */
    public function calculateValue()
    {
        try {
            // Use OreValuationService to calculate values
            $valuationService = app(\MiningManager\Services\Pricing\OreValuationService::class);

            $values = $valuationService->calculateOreValue($this->type_id, $this->quantity);

            // Update all value fields
            $this->unit_price = $values['unit_price'];
            $this->ore_value = $values['ore_value'];
            $this->mineral_value = $values['mineral_value'];
            $this->total_value = $values['total_value'];

            Log::debug('MiningLedger: Calculated values', [
                'ledger_id' => $this->id,
                'type_id' => $this->type_id,
                'quantity' => $this->quantity,
                'ore_value' => $this->ore_value,
                'mineral_value' => $this->mineral_value,
                'total_value' => $this->total_value,
                'valuation_method' => $values['valuation_method'],
            ]);
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
