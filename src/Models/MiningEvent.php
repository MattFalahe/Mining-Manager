<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        'participation',
        'members_only',
        'ore_categories',
        'results_sent_at',
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
        'members_only' => 'boolean',
        'ore_categories' => 'array',
        'results_sent_at' => 'datetime',
    ];

    /**
     * Who gets an event's rate: everybody whose mining meets its rules, or
     * only the accounts that signed up.
     */
    public const PARTICIPATION_EVERYONE = 'everyone';
    public const PARTICIPATION_SIGNED_UP = 'signed_up';

    /** The moon ore rarities, cheapest first. */
    public const MOON_CATEGORIES = ['moon_r4', 'moon_r8', 'moon_r16', 'moon_r32', 'moon_r64'];

    private static bool $hasRules = false;
    private static int $hasRulesCheckedAt = 0;

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
     * The refineries a moon event is narrowed to.
     */
    public function moons()
    {
        return $this->hasMany(EventMoon::class, 'event_id');
    }

    /**
     * The accounts that signed up.
     */
    public function signups()
    {
        return $this->hasMany(EventSignup::class, 'event_id');
    }

    /**
     * Whether the database has what events need for refineries, sign-ups and
     * narrowed ore. A plugin update can start on the new code before its
     * migrations have run, so a "no" is asked again after a while. A "yes"
     * stays. The sign-ups table is the last thing the migration makes.
     */
    public static function hasRules(): bool
    {
        if (!self::$hasRules && time() - self::$hasRulesCheckedAt >= 30) {
            self::$hasRulesCheckedAt = time();

            try {
                self::$hasRules = Schema::hasTable('mining_manager_event_signups');
            } catch (\Throwable $e) {
                self::$hasRules = false;
            }
        }

        return self::$hasRules;
    }

    /**
     * The ore categories the event counts: its type's, narrowed to the ones
     * picked for it when some were.
     *
     * @return array<int, string>
     */
    public function oreCategories(): array
    {
        $categories = self::EVENT_TYPE_ORE_CATEGORIES[$this->type] ?? [];
        $picked = is_array($this->ore_categories) ? $this->ore_categories : [];

        return $picked === [] ? $categories : array_values(array_intersect($categories, $picked));
    }

    /**
     * Whether only the accounts that signed up get the event's rate.
     */
    public function needsSignUp(): bool
    {
        return $this->participation === self::PARTICIPATION_SIGNED_UP;
    }

    /**
     * Whether the event counts by whole days. Refineries report moon mining
     * by the day, so a moon event counts every day it touches.
     */
    public function countsWholeDays(): bool
    {
        return $this->type === 'moon_extraction';
    }

    /**
     * Sign-ups stay open until the event ends.
     */
    public function takesSignUps(): bool
    {
        return in_array($this->status, ['planned', 'active'], true)
            && ($this->end_time === null || $this->end_time->isFuture());
    }

    /**
     * The refineries the event is narrowed to; empty when it counts every
     * refinery in its location.
     *
     * @return array<int, int>
     */
    public function refineryIds(): array
    {
        if (!$this->exists || !self::hasRules()) {
            return [];
        }

        return $this->moons()->pluck('structure_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Whether a user's account has signed up.
     *
     * @param \Seat\Web\Models\User|null $user
     */
    public function isSignedUp($user): bool
    {
        if (!$user || !$this->exists || !self::hasRules()) {
            return false;
        }

        return $this->signups()->where('user_id', $user->id)->exists();
    }

    /**
     * The account each character belongs to, as user ids keyed by character.
     * Expired tokens still tie a character to its account.
     *
     * @param array<int, int> $characterIds
     * @return array<int, int>
     */
    public static function accountsOf(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        return DB::table('refresh_tokens')
            ->whereIn('character_id', array_values(array_unique($characterIds)))
            ->pluck('user_id', 'character_id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
     * Check if this event's tax modifier applies to the given ore category:
     * one its type covers ("special" covers everything, the others their own
     * ore), and one picked for it when its ore was narrowed.
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

        return in_array($oreCategory, $this->oreCategories(), true);
    }

    /**
     * List of attribute names that, if modified, invalidate the
     * event_mining_records materialisation for this event. The refineries a
     * moon event is narrowed to are rows of their own, so whoever changes
     * them gathers the event again.
     */
    public const SCOPE_AFFECTING_FIELDS = [
        'type',
        'corporation_id',
        'solar_system_id',
        'location_scope',
        'start_time',
        'end_time',
        'participation',
        'members_only',
        'ore_categories',
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
     * A status changed on the edit form, an event cancelled or brought
     * back, keeps the records but decides whether the rate applies at all,
     * so their days are worked out again. A deleted event's records go and
     * its days are worked out without it. A billed day keeps what it was
     * billed either way.
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
            try {
                if ($event->wasChanged(self::SCOPE_AFFECTING_FIELDS)) {
                    app(EventMiningAggregator::class)->aggregate($event, true);
                } elseif ($event->wasChanged('status')) {
                    app(EventMiningAggregator::class)->rebuildDays($event);
                }
            } catch (\Throwable $e) {
                Log::error(
                    "Mining Manager: Re-aggregation failed for event {$event->id} after an edit: {$e->getMessage()}"
                );
            }
        });

        static::deleted(function (self $event) {
            try {
                app(EventMiningAggregator::class)->forget($event);
            } catch (\Throwable $e) {
                Log::error(
                    "Mining Manager: Could not take deleted event {$event->id}'s discount off its days: {$e->getMessage()}"
                );
            }
        });
    }
}
