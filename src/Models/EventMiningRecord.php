<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Sde\MapDenormalize;

/**
 * Single row of mining activity that qualifies for a specific event.
 *
 * A row is written here by EventMiningAggregator when mining activity
 * passes ALL of the event's filters: corporation, location (system /
 * constellation / region), ore category, and time window. The table
 * is the canonical answer to "does this mining count for this event?"
 *
 * See Database/migrations/2026_01_01_000005_create_event_mining_records.php
 * for column semantics.
 */
class EventMiningRecord extends Model
{
    /**
     * @var string
     */
    protected $table = 'event_mining_records';

    /**
     * @var array
     */
    protected $fillable = [
        'event_id',
        'character_id',
        'mining_date',
        'mining_time',
        'type_id',
        'ore_category',
        'solar_system_id',
        'quantity',
        'unit_price',
        'value_isk',
        'source',
        'observer_id',
        'recorded_at',
    ];

    /**
     * @var array
     */
    protected $casts = [
        'event_id' => 'integer',
        'character_id' => 'integer',
        'mining_date' => 'date',
        // mining_time intentionally NOT cast — it's a TIME column,
        // Laravel's 'datetime' cast mangles it. String is fine for reads.
        'type_id' => 'integer',
        'solar_system_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'value_isk' => 'decimal:2',
        'observer_id' => 'integer',
        'recorded_at' => 'datetime',
    ];

    /**
     * Source enum values — lifted as constants so callers don't repeat magic strings.
     */
    public const SOURCE_OBSERVER = 'observer';
    public const SOURCE_CHARACTER_MINING = 'character_mining';

    /**
     * The event this record belongs to.
     */
    public function event()
    {
        return $this->belongsTo(MiningEvent::class, 'event_id');
    }

    /**
     * The character that did the mining.
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * The ore type (SDE invTypes).
     */
    public function type()
    {
        return $this->belongsTo(InvType::class, 'type_id', 'typeID');
    }

    /**
     * The solar system where mining occurred.
     */
    public function solarSystem()
    {
        return $this->belongsTo(MapDenormalize::class, 'solar_system_id', 'itemID');
    }

    /**
     * Scope: rows for a given event.
     */
    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Scope: rows for a given event and character.
     */
    public function scopeForEventCharacter($query, int $eventId, int $characterId)
    {
        return $query->where('event_id', $eventId)
                     ->where('character_id', $characterId);
    }

    /**
     * Scope: rows whose source is the corporation observer feed
     * (i.e. moon extraction data, always day-level).
     */
    public function scopeFromObserver($query)
    {
        return $query->where('source', self::SOURCE_OBSERVER);
    }

    /**
     * Scope: rows whose source is the per-character ESI mining endpoint
     * (belt / ice / gas, with SeAT-fetch time).
     */
    public function scopeFromCharacterMining($query)
    {
        return $query->where('source', self::SOURCE_CHARACTER_MINING);
    }
}
