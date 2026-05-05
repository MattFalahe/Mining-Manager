<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Events\EventMiningAggregator;
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
        'total_mined_value',
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
        'total_mined_value' => 'integer',
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
     * Mapping from event type to the ore categories its tax modifier applies to.
     *
     * mining_op       → regular asteroid ore (belt mining)
     * moon_extraction → all moon ore rarities (R4 through R64)
     * ice_mining      → ice
     * gas_huffing     → gas
     * special         → EVERYTHING (all taxable categories including abyssal + triglavian)
     *
     * The 'special' event type is intended for special occasions (holidays,
     * competitions, incentives) where the organiser wants the modifier to
     * apply regardless of what ore miners are extracting. Everything else
     * is scoped so a "Moon Extraction" event doesn't accidentally discount
     * belt mining or vice versa.
     */
    public const EVENT_TYPE_ORE_CATEGORIES = [
        'mining_op' => ['ore'],
        'moon_extraction' => ['moon_r4', 'moon_r8', 'moon_r16', 'moon_r32', 'moon_r64'],
        'ice_mining' => ['ice'],
        'gas_huffing' => ['gas'],
        'special' => ['ore', 'moon_r4', 'moon_r8', 'moon_r16', 'moon_r32', 'moon_r64', 'ice', 'gas', 'abyssal', 'triglavian'],
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
     * Resolves the user's character IDs and checks if any of them
     * are in the participants list. In SeAT, User.id != character_id.
     *
     * @param \Seat\Web\Models\User|int $user
     * @return bool
     */
    public function isParticipating($user)
    {
        if (is_object($user)) {
            // Get all character IDs belonging to this user
            $characterIds = $user->characters->pluck('character_id')->toArray();

            if (empty($characterIds)) {
                return false;
            }

            return $this->participants()
                ->whereIn('character_id', $characterIds)
                ->exists();
        }

        // If an integer was passed, treat it as a character_id directly
        return $this->participants()
            ->where('character_id', $user)
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

    /**
     * Resolve the solar system IDs that fall within this event's location scope.
     *
     * EVE universe hierarchy: Region → Constellation → System.
     * Mining ledger records contain solar_system_id (always a system).
     * Events can scope to system, constellation, or region — so we need to
     * expand constellation/region IDs into a list of system IDs for matching.
     *
     * Results are cached for 24 hours (static SDE data, never changes).
     *
     * @return array|null Array of solar system IDs, or null for 'any' (no filter needed)
     */
    public function getMatchingSystemIds(): ?array
    {
        // Global events match everything — no filter
        if ($this->location_scope === 'any' || !$this->solar_system_id) {
            return null;
        }

        // System scope — direct match (single ID)
        if ($this->location_scope === 'system') {
            return [$this->solar_system_id];
        }

        // Constellation/region scope — resolve hierarchy from mapDenormalize
        $cacheKey = "mining-manager:event-systems:{$this->location_scope}:{$this->solar_system_id}";

        return Cache::remember($cacheKey, 86400, function () {
            if ($this->location_scope === 'constellation') {
                return MapDenormalize::where('constellationID', $this->solar_system_id)
                    ->where('groupID', 5) // Solar systems only
                    ->pluck('itemID')
                    ->toArray();
            }

            if ($this->location_scope === 'region') {
                return MapDenormalize::where('regionID', $this->solar_system_id)
                    ->where('groupID', 5)
                    ->pluck('itemID')
                    ->toArray();
            }

            return [$this->solar_system_id];
        });
    }

    /**
     * Apply this event's location filter to an Eloquent query.
     *
     * Usage: $event->applyLocationFilter($query, 'solar_system_id');
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $column The column name containing solar_system_id (default: 'solar_system_id')
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function applyLocationFilter($query, string $column = 'solar_system_id')
    {
        $systemIds = $this->getMatchingSystemIds();

        if ($systemIds === null) {
            return $query; // Global — no filter
        }

        if (count($systemIds) === 1) {
            return $query->where($column, $systemIds[0]);
        }

        return $query->whereIn($column, $systemIds);
    }

    /**
     * Check if a given solar system ID falls within this event's location scope.
     *
     * @param int|null $solarSystemId
     * @return bool
     */
    public function matchesSystem(?int $solarSystemId): bool
    {
        if (!$solarSystemId) {
            return false;
        }

        $systemIds = $this->getMatchingSystemIds();

        // Global event matches everything
        if ($systemIds === null) {
            return true;
        }

        return in_array($solarSystemId, $systemIds);
    }

    /**
     * Check if this event's tax modifier applies to the given ore category.
     *
     * Returns true when the event's type includes the ore category in
     * EVENT_TYPE_ORE_CATEGORIES. "special" events apply to everything;
     * other types apply only to their matching category (ice/gas/moon/ore).
     *
     * @param string|null $oreCategory Value from mining_ledger.ore_category
     *                                 e.g. 'ore', 'ice', 'gas', 'moon_r64',
     *                                 'abyssal', 'triglavian', or null
     * @return bool
     */
    public function appliesToOreCategory(?string $oreCategory): bool
    {
        if (!$oreCategory) {
            return false;
        }

        $allowedCategories = self::EVENT_TYPE_ORE_CATEGORIES[$this->type] ?? [];

        return in_array($oreCategory, $allowedCategories, true);
    }

    /**
     * List of attribute names that, if modified, invalidate the
     * event_mining_records materialisation for this event.
     */
    protected const SCOPE_AFFECTING_FIELDS = [
        'type',
        'corporation_id',
        'solar_system_id',
        'location_scope',
        'start_time',
        'end_time',
    ];

    /**
     * Hook: when any scope-affecting field changes, rebuild the event's
     * materialised records from source tables.
     *
     * Rationale
     * =========
     * event_mining_records is populated with all four filters baked in
     * (corp, location, time, ore category). If the user edits the event
     * in the UI — e.g. narrows the location scope or flips from
     * moon_extraction to special — the prior materialisation is now
     * wrong and downstream participant / tax attribution would be stale.
     *
     * A full refresh (delete-then-rebuild) is the safest path because
     * it's hard to surgically undo filter changes incrementally.
     *
     * We ignore non-scope saves (stats updates from EventTrackingService
     * rolling up event_participants into total_mined, participant_count,
     * etc.) to avoid an infinite aggregate ⇄ save loop.
     *
     * Failures are logged but do NOT propagate — a controller save
     * shouldn't fail because a background aggregation had a hiccup. The
     * cron will re-try on its next tick.
     */
    protected static function booted(): void
    {
        static::saved(function (self $event) {
            if (!$event->wasChanged(self::SCOPE_AFFECTING_FIELDS)) {
                return;
            }

            try {
                app(EventMiningAggregator::class)->aggregate($event, true);
            } catch (\Throwable $e) {
                Log::error(
                    "Mining Manager: Re-aggregation failed for event {$event->id} after scope edit: {$e->getMessage()}"
                );
            }
        });
    }
}
