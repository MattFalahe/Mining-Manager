<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Universe\UniverseType;

class MiningPriceCache extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mining_price_cache';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'type_id',
        'region_id',
        'sell_price',
        'buy_price',
        'average_price',
        'volume',
        'cached_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'sell_price' => 'decimal:2',
        'buy_price' => 'decimal:2',
        'average_price' => 'decimal:2',
        'volume' => 'integer',
        'cached_at' => 'datetime',
    ];

    /**
     * Get the item type.
     */
    public function type()
    {
        return $this->belongsTo(UniverseType::class, 'type_id', 'type_id');
    }

    /**
     * Scope a query for fresh cache entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $minutes
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFresh($query, int $minutes = null)
    {
        $minutes = $minutes ?? config('mining-manager.pricing.cache_duration', 60);
        
        return $query->where('cached_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Check if cache entry is fresh.
     *
     * @param int|null $minutes
     * @return bool
     */
    public function isFresh(?int $minutes = null)
    {
        $minutes = $minutes ?? config('mining-manager.pricing.cache_duration', 60);
        
        return $this->cached_at->greaterThanOrEqualTo(now()->subMinutes($minutes));
    }

    /**
     * Get price based on configured price type.
     *
     * @return float|null
     */
    public function getConfiguredPrice()
    {
        $priceType = config('mining-manager.pricing.price_type', 'sell');

        return match ($priceType) {
            'sell' => $this->sell_price,
            'buy' => $this->buy_price,
            'average' => $this->average_price,
            default => $this->sell_price,
        };
    }
}
