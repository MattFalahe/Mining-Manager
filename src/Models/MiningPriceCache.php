<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Sde\InvType;

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
     * Auto-detect primary key to handle different SeAT versions.
     */
    public function type()
    {
        try {
            $typeModel = app(InvType::class);
            $primaryKey = $typeModel->getKeyName();
            return $this->belongsTo(InvType::class, 'type_id', $primaryKey);
        } catch (\Exception $e) {
            Log::debug('MiningPriceCache: Failed to detect InvType primary key, using default', [
                'error' => $e->getMessage()
            ]);
            return $this->belongsTo(InvType::class, 'type_id', 'typeID');
        }
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
        if ($minutes === null) {
            $pricingSettings = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getPricingSettings();
            $minutes = (int) ($pricingSettings['cache_duration'] ?? 240);
        }

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
        if ($minutes === null) {
            $pricingSettings = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getPricingSettings();
            $minutes = (int) ($pricingSettings['cache_duration'] ?? 240);
        }

        return $this->cached_at->greaterThanOrEqualTo(now()->subMinutes($minutes));
    }

    /**
     * Get price based on configured price type.
     *
     * @return float|null
     */
    public function getConfiguredPrice()
    {
        $pricingSettings = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getPricingSettings();
        $priceType = $pricingSettings['price_type'] ?? 'sell';

        return match ($priceType) {
            'sell' => $this->sell_price,
            'buy' => $this->buy_price,
            'average' => $this->average_price,
            default => $this->sell_price,
        };
    }
}
