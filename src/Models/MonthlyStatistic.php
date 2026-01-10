<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Web\Models\User;

/**
 * MonthlyStatistic Model
 *
 * Stores pre-calculated dashboard statistics for closed months.
 * Eliminates need to recalculate historical data on every page load.
 */
class MonthlyStatistic extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'monthly_statistics';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'character_id',
        'corporation_id',
        'year',
        'month',
        'month_start',
        'month_end',
        'is_closed',
        'total_quantity',
        'total_value',
        'ore_value',
        'mineral_value',
        'tax_owed',
        'tax_paid',
        'tax_pending',
        'tax_overdue',
        'moon_ore_value',
        'ice_value',
        'gas_value',
        'regular_ore_value',
        'mining_days',
        'total_days',
        'daily_chart_data',
        'ore_type_chart_data',
        'value_breakdown_chart_data',
        'top_miners',
        'top_systems',
        'calculated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'user_id' => 'integer',
        'character_id' => 'integer',
        'corporation_id' => 'integer',
        'year' => 'integer',
        'month' => 'integer',
        'month_start' => 'date',
        'month_end' => 'date',
        'is_closed' => 'boolean',
        'total_quantity' => 'decimal:2',
        'total_value' => 'decimal:2',
        'ore_value' => 'decimal:2',
        'mineral_value' => 'decimal:2',
        'tax_owed' => 'decimal:2',
        'tax_paid' => 'decimal:2',
        'tax_pending' => 'decimal:2',
        'tax_overdue' => 'decimal:2',
        'moon_ore_value' => 'decimal:2',
        'ice_value' => 'decimal:2',
        'gas_value' => 'decimal:2',
        'regular_ore_value' => 'decimal:2',
        'mining_days' => 'integer',
        'total_days' => 'integer',
        'daily_chart_data' => 'array',
        'ore_type_chart_data' => 'array',
        'value_breakdown_chart_data' => 'array',
        'top_miners' => 'array',
        'top_systems' => 'array',
        'calculated_at' => 'datetime',
    ];

    /**
     * Get the user that owns this statistic.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the character that owns this statistic.
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Scope to get statistics for a specific month/year.
     *
     * Accepts either a single date (Carbon/string) or separate year+month.
     * Single date usage: MonthlyStatistic::forMonth('2026-01') or forMonth(Carbon::now())
     * Legacy usage: MonthlyStatistic::forMonth(2026, 1)
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param mixed $yearOrDate  Year (int) or date string/Carbon
     * @param int|null $month    Month number (only if first param is year)
     */
    public function scopeForMonth($query, $yearOrDate, $month = null)
    {
        if ($month !== null) {
            // Legacy: separate year and month params
            return $query->where('year', $yearOrDate)->where('month', $month);
        }

        // Single date param: parse year and month from it
        $date = \Carbon\Carbon::parse($yearOrDate);
        return $query->where('year', $date->year)->where('month', $date->month);
    }

    /**
     * Scope to get only closed months.
     */
    public function scopeClosed($query)
    {
        return $query->where('is_closed', true);
    }

    /**
     * Scope to get statistics for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get statistics for a specific character.
     */
    public function scopeForCharacter($query, $characterId)
    {
        return $query->where('character_id', $characterId);
    }

    /**
     * Scope to get statistics for a specific corporation.
     */
    public function scopeForCorporation($query, $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    /**
     * Check if a statistic exists for the given parameters.
     *
     * Note: Unique constraint is on [user_id, character_id, year, month].
     * corporation_id is stored but not part of the unique key.
     */
    public static function existsFor($userId, $characterId, $corporationId, $year, $month)
    {
        return self::where('user_id', $userId)
            ->where('character_id', $characterId)
            ->where('year', $year)
            ->where('month', $month)
            ->exists();
    }

    /**
     * Get or create a statistic record.
     *
     * Uses only the unique key columns [user_id, character_id, year, month]
     * for the firstOrCreate lookup. corporation_id is set in the defaults.
     */
    public static function getOrCreateFor($userId, $characterId, $corporationId, $year, $month)
    {
        return self::firstOrCreate(
            [
                'user_id' => $userId,
                'character_id' => $characterId,
                'year' => $year,
                'month' => $month,
            ],
            [
                'corporation_id' => $corporationId,
                'month_start' => date('Y-m-01', strtotime("$year-$month-01")),
                'month_end' => date('Y-m-t', strtotime("$year-$month-01")),
                'is_closed' => false,
            ]
        );
    }
}
