<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MoonExtractionHistory Model
 *
 * Stores historical moon extraction data for long-term analysis.
 * Keeps last 12 months of extraction history with value tracking.
 *
 * @property int $id
 * @property int $moon_extraction_id
 * @property int $structure_id
 * @property int $corporation_id
 * @property int|null $moon_id
 * @property \Carbon\Carbon $extraction_start_time
 * @property \Carbon\Carbon $chunk_arrival_time
 * @property \Carbon\Carbon $natural_decay_time
 * @property \Carbon\Carbon $archived_at
 * @property string $final_status
 * @property int|null $estimated_value_at_start
 * @property int|null $estimated_value_at_arrival
 * @property int|null $final_estimated_value
 * @property array|null $ore_composition
 * @property int|null $actual_mined_value
 * @property int $total_miners
 * @property float $completion_percentage
 * @property bool $is_jackpot
 * @property \Carbon\Carbon|null $jackpot_detected_at
 * @property string|null $notes
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class MoonExtractionHistory extends Model
{

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'moon_extraction_history';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'moon_extraction_id',
        'structure_id',
        'corporation_id',
        'moon_id',
        'extraction_start_time',
        'chunk_arrival_time',
        'natural_decay_time',
        'archived_at',
        'final_status',
        'estimated_value_at_start',
        'estimated_value_at_arrival',
        'final_estimated_value',
        'ore_composition',
        'actual_mined_value',
        'total_miners',
        'completion_percentage',
        'is_jackpot',
        'jackpot_detected_at',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'extraction_start_time' => 'datetime',
        'chunk_arrival_time' => 'datetime',
        'natural_decay_time' => 'datetime',
        'archived_at' => 'datetime',
        'jackpot_detected_at' => 'datetime',
        'ore_composition' => 'array',
        'is_jackpot' => 'boolean',
        'total_miners' => 'integer',
        'completion_percentage' => 'decimal:2',
        'estimated_value_at_start' => 'float',
        'estimated_value_at_arrival' => 'float',
        'final_estimated_value' => 'float',
        'actual_mined_value' => 'float',
    ];

    /**
     * Get the moon for this extraction.
     */
    public function moon()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Universe\UniverseMoon::class, 'moon_id', 'moon_id');
    }

    /**
     * Get the structure for this extraction.
     */
    public function structure()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStructure::class, 'structure_id', 'structure_id');
    }

    /**
     * Scope to filter by corporation.
     */
    public function scopeForCorporation($query, int $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    /**
     * Scope to filter by moon.
     */
    public function scopeForMoon($query, int $moonId)
    {
        return $query->where('moon_id', $moonId);
    }

    /**
     * Scope to get recent history (last N months).
     */
    public function scopeRecentMonths($query, int $months = 12)
    {
        return $query->where('archived_at', '>=', now()->subMonths($months));
    }

    /**
     * Scope to get jackpots only.
     */
    public function scopeJackpots($query)
    {
        return $query->where('is_jackpot', true);
    }

    /**
     * Get value change percentage between start and arrival.
     *
     * @return float|null
     */
    public function getValueChangePercentage(): ?float
    {
        if (!$this->estimated_value_at_start || !$this->estimated_value_at_arrival) {
            return null;
        }

        $change = (($this->estimated_value_at_arrival - $this->estimated_value_at_start) / $this->estimated_value_at_start) * 100;
        return round($change, 2);
    }

    /**
     * Get efficiency percentage (actual vs estimated).
     *
     * @return float|null
     */
    public function getMiningEfficiency(): ?float
    {
        if (!$this->final_estimated_value || !$this->actual_mined_value) {
            return null;
        }

        $efficiency = ($this->actual_mined_value / $this->final_estimated_value) * 100;
        return round($efficiency, 2);
    }
}
