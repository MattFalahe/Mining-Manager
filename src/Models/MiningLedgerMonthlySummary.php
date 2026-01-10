<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Mining Ledger Monthly Summary
 *
 * Stores pre-calculated monthly totals for each character
 * Used to improve performance for historical data
 *
 * @property int $id
 * @property int $character_id
 * @property Carbon $month
 * @property int|null $corporation_id
 * @property float $total_quantity
 * @property float $total_value
 * @property float $total_tax
 * @property float $moon_ore_value
 * @property float $regular_ore_value
 * @property float $ice_value
 * @property float $gas_value
 * @property array|null $ore_breakdown
 * @property bool $is_finalized
 * @property Carbon|null $finalized_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MiningLedgerMonthlySummary extends Model
{
    protected $table = 'mining_ledger_monthly_summaries';

    protected $fillable = [
        'character_id',
        'month',
        'corporation_id',
        'total_quantity',
        'total_value',
        'total_tax',
        'moon_ore_value',
        'regular_ore_value',
        'ice_value',
        'gas_value',
        'ore_breakdown',
        'is_finalized',
        'finalized_at',
    ];

    protected $casts = [
        'month' => 'date',
        'total_quantity' => 'decimal:2',
        'total_value' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'moon_ore_value' => 'decimal:2',
        'regular_ore_value' => 'decimal:2',
        'ice_value' => 'decimal:2',
        'gas_value' => 'decimal:2',
        'ore_breakdown' => 'array',
        'is_finalized' => 'boolean',
        'finalized_at' => 'datetime',
    ];

    /**
     * Scope to get only finalized summaries
     */
    public function scopeFinalized($query)
    {
        return $query->where('is_finalized', true);
    }

    /**
     * Scope to filter by month
     */
    public function scopeForMonth($query, $month)
    {
        return $query->where('month', $month);
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
     * Get character relation
     */
    public function character()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Character\CharacterInfo::class, 'character_id', 'character_id');
    }
}
