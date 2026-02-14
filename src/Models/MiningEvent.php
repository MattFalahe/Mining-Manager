<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\MapDenormalize;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Web\Models\User;

class MiningEvent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mining_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'type',
        'start_time',
        'end_time',
        'solar_system_id',
        'location_scope',
        'status',
        'participant_count',
        'total_mined',
        'tax_modifier',
        'corporation_id',
        'created_by',
        'last_updated',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'participant_count' => 'integer',
        'total_mined' => 'integer',
        'tax_modifier' => 'integer',
        'corporation_id' => 'integer',
        'last_updated' => 'datetime',
    ];

    /**
     * Event type constants.
     */
    public const EVENT_TYPES = [
        'mining_op' => 'Mining Operation',
        'moon_extraction' => 'Moon Extraction',
        'ice_mining' => 'Ice Mining',
        'gas_huffing' => 'Gas Huffing',
        'special' => 'Special Event',
    ];

    /**
     * Location scope constants.
     */
    public const LOCATION_SCOPES = [
        'any' => 'Any Location (Global)',
        'system' => 'Specific System',
        'constellation' => 'Constellation',
        'region' => 'Region',
    ];

    /**
     * Tax modifier preset labels for UI display.
     */
    public const TAX_MODIFIER_LABELS = [
        -100 => 'Tax Free',
        -75 => 'Reduced Tax (-75%)',
        -50 => 'Half Tax (-50%)',
        -25 => 'Light Discount (-25%)',
        0 => 'Normal Tax',
        25 => 'Slight Increase (+25%)',
        50 => 'Heavy Tax (+50%)',
        75 => 'Punitive Tax (+75%)',
        100 => 'Double Tax (+100%)',
    ];

    /**
     * Get the participants for this event.
     */
    public function participants()
    {
        return $this->hasMany(EventParticipant::class, 'event_id');
    }

    /**
     * Get the solar system.
     */
    public function solarSystem()
    {
        return $this->belongsTo(MapDenormalize::class, 'solar_system_id', 'itemID');
    }

    /**
     * Get the corporation this event belongs to.
     */
    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    /**
     * Get the user who created the event.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope a query to only include active events.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include planned events.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePlanned($query)
    {
        return $query->where('status', 'planned');
    }

    /**
     * Scope a query to only include completed events.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to filter by corporation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $corporationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCorporation($query, ?int $corporationId)
    {
        if ($corporationId) {
            return $query->where('corporation_id', $corporationId);
        }
        return $query;
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
        return $query->whereBetween('start_time', [$startDate, $endDate]);
    }

    /**
     * Check if event is currently active.
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->status === 'active' &&
               now()->greaterThanOrEqualTo($this->start_time) &&
               (!$this->end_time || now()->lessThanOrEqualTo($this->end_time));
    }

    /**
     * Check if event is in the future.
     *
     * @return bool
     */
    public function isFuture()
    {
        return $this->status === 'planned' && now()->lessThan($this->start_time);
    }

    /**
     * Get event duration in hours.
     *
     * @return float|null
     */
    public function getDuration()
    {
        if (!$this->end_time) {
            return null;
        }

        return $this->start_time->diffInHours($this->end_time);
    }

    /**
     * Get average ore per participant.
     *
     * @return float
     */
    public function getAveragePerParticipant()
    {
        if ($this->participant_count <= 0) {
            return 0;
        }

        return $this->total_mined / $this->participant_count;
    }

    /**
     * Check if a user is participating in this event.
     *
     * @param \Seat\Web\Models\User|int $user
     * @return bool
     */
    public function isParticipating($user)
    {
        $userId = is_object($user) ? $user->id : $user;

        return $this->participants()
            ->where('character_id', $userId)
            ->exists();
    }

    /**
     * Get the human-readable label for the tax modifier.
     *
     * @return string
     */
    public function getTaxModifierLabel(): string
    {
        // Check for exact preset match
        if (isset(self::TAX_MODIFIER_LABELS[$this->tax_modifier])) {
            return self::TAX_MODIFIER_LABELS[$this->tax_modifier];
        }

        // Format custom value
        $sign = $this->tax_modifier >= 0 ? '+' : '';
        return "{$sign}{$this->tax_modifier}%";
    }

    /**
     * Get formatted tax modifier with sign for display.
     *
     * @return string
     */
    public function getFormattedTaxModifier(): string
    {
        if ($this->tax_modifier == 0) {
            return '0%';
        }

        $sign = $this->tax_modifier > 0 ? '+' : '';
        return "{$sign}{$this->tax_modifier}%";
    }

    /**
     * Check if this event reduces taxes (negative modifier).
     *
     * @return bool
     */
    public function reducesTax(): bool
    {
        return $this->tax_modifier < 0;
    }

    /**
     * Check if this event increases taxes (positive modifier).
     *
     * @return bool
     */
    public function increasesTax(): bool
    {
        return $this->tax_modifier > 0;
    }

    /**
     * Check if this is a tax-free event.
     *
     * @return bool
     */
    public function isTaxFree(): bool
    {
        return $this->tax_modifier <= -100;
    }

    /**
     * Get the human-readable label for the event type.
     *
     * @return string
     */
    public function getTypeLabel(): string
    {
        return self::EVENT_TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type ?? 'mining_op'));
    }

    /**
     * Get the human-readable label for the location scope.
     *
     * @return string
     */
    public function getLocationScopeLabel(): string
    {
        return self::LOCATION_SCOPES[$this->location_scope] ?? 'Any Location';
    }

    /**
     * Get the location name (system/constellation/region name).
     *
     * @return string|null
     */
    public function getLocationName(): ?string
    {
        if ($this->location_scope === 'any' || !$this->solar_system_id) {
            return null;
        }

        $location = MapDenormalize::where('itemID', $this->solar_system_id)->first();
        return $location ? $location->itemName : null;
    }

    /**
     * Get top participants for leaderboard.
     *
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function topParticipants(int $limit = 10)
    {
        return $this->participants()
            ->with('character')
            ->orderByDesc('quantity_mined')
            ->limit($limit)
            ->get();
    }
}
