<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Universe\UniverseType;
use Seat\Eveapi\Models\Universe\UniverseSystem;

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
    ];

    /**
     * Get the character that owns the mining ledger entry.
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Get the ore type.
     */
    public function type()
    {
        return $this->belongsTo(UniverseType::class, 'type_id', 'type_id');
    }

    /**
     * Get the solar system.
     */
    public function solarSystem()
    {
        return $this->belongsTo(UniverseSystem::class, 'solar_system_id', 'system_id');
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
     * Get estimated value based on current market prices.
     *
     * @return float|null
     */
    public function getEstimatedValue()
    {
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
    }
}
