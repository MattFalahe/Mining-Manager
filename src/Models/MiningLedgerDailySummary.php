<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Mining Ledger Daily Summary
 *
 * Stores pre-calculated daily totals for each character
 * Used for drill-down view from monthly summaries
 *
 * @property int $id
 * @property int $character_id
 * @property Carbon $date
 * @property int|null $corporation_id
 * @property float $total_quantity
 * @property float $total_value
 * @property float $total_tax
 * @property float $event_discount_total
 * @property float $moon_ore_value
 * @property float $regular_ore_value
 * @property float $ice_value
 * @property float $gas_value
 * @property float $abyssal_ore_value
 * @property float $triglavian_ore_value
 * @property array|null $ore_types
 * @property bool $is_finalized
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MiningLedgerDailySummary extends Model
{
    protected $table = 'mining_ledger_daily_summaries';

    protected $fillable = [
        'character_id',
        'date',
        'corporation_id',
        'total_quantity',
        'total_value',
        'total_tax',
        'event_discount_total',
        'moon_ore_value',
        'regular_ore_value',
        'ice_value',
        'gas_value',
        'abyssal_ore_value',
        'triglavian_ore_value',
        'ore_types',
        'is_finalized',
    ];

    protected $casts = [
        'date' => 'date',
        'total_quantity' => 'decimal:2',
        'total_value' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'event_discount_total' => 'decimal:2',
        'moon_ore_value' => 'decimal:2',
        'regular_ore_value' => 'decimal:2',
        'ice_value' => 'decimal:2',
        'gas_value' => 'decimal:2',
        'abyssal_ore_value' => 'decimal:2',
        'triglavian_ore_value' => 'decimal:2',
        'ore_types' => 'array',
        'is_finalized' => 'boolean',
    ];

    /**
     * Scope to get only finalized summaries
     */
    public function scopeFinalized($query)
    {
        return $query->where('is_finalized', true);
    }

    /**
     * Scope to filter by date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    /**
     * Scope to filter by character
     */
    public function scopeForCharacter($query, $characterId)
    {
        return $query->where('character_id', $characterId);
    }

    /**
     * Scope to filter by corporation
     */
    public function scopeForCorporation($query, $corporationId)
    {
        if ($corporationId) {
            return $query->where('corporation_id', $corporationId);
        }
        return $query;
    }

    /**
     * Scope to filter by month
     */
    public function scopeForMonth($query, $month)
    {
        $start = Carbon::parse($month)->startOfMonth();
        $end = Carbon::parse($month)->endOfMonth();
        return $query->whereBetween('date', [$start, $end]);
    }

    /**
     * Get character relation
     */
    public function character()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Character\CharacterInfo::class, 'character_id', 'character_id');
    }
}
