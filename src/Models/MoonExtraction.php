<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use MiningManager\Services\Moon\MoonOreHelper;
use Carbon\Carbon;

class MoonExtraction extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'moon_extractions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'structure_id',
        'corporation_id',
        'moon_id',
        'extraction_start_time',
        'chunk_arrival_time',
        'natural_decay_time',
        'status',
        'estimated_value',
        'ore_composition',
        'notification_sent',
        'is_jackpot',
        'jackpot_detected_at',
        'jackpot_reported_by',
        'jackpot_verified',
        'jackpot_verified_at',
        'estimated_value_at_start',
        'estimated_value_pre_arrival',
        'value_last_updated',
        'has_notification_data',
        'auto_fractured',
        'fractured_at',
        'fractured_by',
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
        'estimated_value' => 'float',
        'ore_composition' => 'array',
        'notification_sent' => 'boolean',
        'is_jackpot' => 'boolean',
        'jackpot_detected_at' => 'datetime',
        'jackpot_reported_by' => 'integer',
        'jackpot_verified' => 'boolean',
        'jackpot_verified_at' => 'datetime',
        'estimated_value_at_start' => 'float',
        'estimated_value_pre_arrival' => 'float',
        'value_last_updated' => 'datetime',
        'has_notification_data' => 'boolean',
        'auto_fractured' => 'boolean',
        'fractured_at' => 'datetime',
    ];

    /**
     * Accessors removed from $appends to prevent N+1 queries.
     * Use getStructureNameAttribute() and getMoonNameAttribute() explicitly,
     * or call loadDisplayNames() to batch-load names.
     */

    /**
     * Get the corporation.
     */
    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    /**
     * Batch-load display names for a collection of MoonExtraction models.
     * Prevents N+1 by querying all names in bulk, then assigning to each model.
     *
     * @param \Illuminate\Support\Collection $extractions
     * @return \Illuminate\Support\Collection The same collection with display names set
     */
    public static function loadDisplayNames($extractions)
    {
        if ($extractions->isEmpty()) {
            return $extractions;
        }

        $structureIds = $extractions->pluck('structure_id')->unique()->filter()->values()->toArray();
        $moonIds = $extractions->pluck('moon_id')->unique()->filter()->values()->toArray();

        // Batch-load all names
        $universeStructures = !empty($structureIds)
            ? \DB::table('universe_structures')->whereIn('structure_id', $structureIds)->get()->keyBy('structure_id')
            : collect();

        $corpStructures = !empty($structureIds)
            ? \DB::table('corporation_structures')->whereIn('structure_id', $structureIds)->get()->keyBy('structure_id')
            : collect();

        $moons = !empty($moonIds)
            ? \DB::table('moons')->whereIn('moon_id', $moonIds)->get()->keyBy('moon_id')
            : collect();

        // Get type names for corp structures
        $typeIds = $corpStructures->pluck('type_id')->unique()->filter()->values()->toArray();
        $typeNames = !empty($typeIds)
            ? \DB::table('invTypes')->whereIn('typeID', $typeIds)->pluck('typeName', 'typeID')->toArray()
            : [];

        // Assign pre-loaded names to each extraction
        foreach ($extractions as $extraction) {
            // Moon name
            $moon = $moons->get($extraction->moon_id);
            $moonName = $moon ? $moon->name : ($extraction->moon_id ? "Moon {$extraction->moon_id}" : 'Unknown Moon');
            $extraction->setAttribute('moon_name', $moonName);

            // Structure name
            $structureName = "Structure {$extraction->structure_id}";
            $us = $universeStructures->get($extraction->structure_id);
            if ($us && !empty($us->name)) {
                $structureName = $us->name;
            } else {
                $cs = $corpStructures->get($extraction->structure_id);
                if ($cs) {
                    if (isset($cs->name) && !empty($cs->name)) {
                        $structureName = $cs->name;
                    } elseif (isset($cs->type_id) && isset($typeNames[$cs->type_id])) {
                        $structureName = $moonName !== 'Unknown Moon'
                            ? "{$typeNames[$cs->type_id]} - {$moonName}"
                            : "{$typeNames[$cs->type_id]} #{$extraction->structure_id}";
                    }
                } elseif ($moonName !== 'Unknown Moon') {
                    $structureName = "Refinery at {$moonName}";
                }
            }
            $extraction->setAttribute('structure_name', $structureName);
        }

        return $extractions;
    }

    /**
     * Get the moon name from SDE data.
     */
    public function getMoonNameAttribute()
    {
        if (!$this->moon_id) {
            return 'Unknown Moon';
        }

        $moon = \DB::table('moons')
            ->where('moon_id', $this->moon_id)
            ->first();

        return $moon ? $moon->name : "Moon {$this->moon_id}";
    }

    /**
     * Get the structure name.
     */
    public function getStructureNameAttribute()
    {
        if (!$this->structure_id) {
            return 'Unknown Structure';
        }

        // Try universe_structures first (has actual names from structure browser)
        $universeStructure = \DB::table('universe_structures')
            ->where('structure_id', $this->structure_id)
            ->first();

        if ($universeStructure && !empty($universeStructure->name)) {
            return $universeStructure->name;
        }

        // Try corporation_structures for type info
        $corpStructure = \DB::table('corporation_structures')
            ->where('structure_id', $this->structure_id)
            ->first();

        // Get moon name for context
        $moonName = null;
        if ($this->moon_id) {
            $moon = \DB::table('moons')
                ->where('moon_id', $this->moon_id)
                ->first();
            $moonName = $moon ? $moon->name : null;
        }

        if ($corpStructure) {
            // Check if name column exists and has value (some SeAT versions may have it)
            if (isset($corpStructure->name) && !empty($corpStructure->name)) {
                return $corpStructure->name;
            }

            // Build name from type + moon location
            if (isset($corpStructure->type_id)) {
                $typeName = \DB::table('invTypes')
                    ->where('typeID', $corpStructure->type_id)
                    ->value('typeName');

                if ($typeName && $moonName) {
                    // Format: "Athanor - 3AE-CP III - Moon 3"
                    return "{$typeName} - {$moonName}";
                } elseif ($typeName) {
                    return "{$typeName} #{$this->structure_id}";
                }
            }
        }

        // Fallback with moon name if available
        if ($moonName) {
            return "Refinery at {$moonName}";
        }

        return "Structure {$this->structure_id}";
    }

    /**
     * Get the structure (refinery).
     */
    public function structure()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStructure::class, 'structure_id', 'structure_id');
    }

    /**
     * Get the moon for this extraction.
     */
    public function moon()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Sde\Moon::class, 'moon_id', 'moon_id');
    }

    /**
     * Get mining ledger entries for this extraction's structure and time period.
     */
    public function miningLedger()
    {
        return $this->hasMany(MiningLedger::class, 'observer_id', 'structure_id');
    }

    /**
     * Get the actual fracture time (when mining became available).
     *
     * Timeline:
     * - Chunk arrives (chunk_arrival_time) → waiting for player to fire laser
     * - Player fires laser → fractured_at = notification timestamp (manual fracture)
     * - No one fires → auto-fracture 3h after arrival → fractured_at = chunk_arrival + 3h
     * - From fractured_at: 48h ready → 2h unstable → expired
     *
     * If fractured_at is not set, falls back to chunk_arrival_time (legacy behavior).
     */
    public function getFractureTime(): ?Carbon
    {
        if ($this->fractured_at) {
            return $this->fractured_at;
        }

        // Legacy fallback: estimate based on auto_fractured flag
        if ($this->chunk_arrival_time) {
            return $this->auto_fractured
                ? $this->chunk_arrival_time->copy()->addHours(3)
                : $this->chunk_arrival_time->copy();
        }

        return null;
    }

    /**
     * Get the ready window duration in hours.
     * Always 48 hours from fracture time.
     */
    public function getReadyDurationHours(): int
    {
        return 48;
    }

    /**
     * Get the time when the unstable phase starts (end of ready window).
     */
    public function getUnstableStartTime(): ?Carbon
    {
        $fractureTime = $this->getFractureTime();
        return $fractureTime ? $fractureTime->copy()->addHours(48) : null;
    }

    /**
     * Get the time when the extraction expires (end of unstable window).
     */
    public function getExpiryTime(): ?Carbon
    {
        $fractureTime = $this->getFractureTime();
        return $fractureTime ? $fractureTime->copy()->addHours(50) : null;
    }

    /**
     * Check if moon is in unstable state.
     * Unstable starts 48h after fracture and lasts 2 hours.
     */
    public function isUnstable(): bool
    {
        $unstableStart = $this->getUnstableStartTime();
        $expiryTime = $this->getExpiryTime();

        if (!$unstableStart || !$expiryTime) {
            return false;
        }

        $now = Carbon::now();
        return $now >= $unstableStart && $now < $expiryTime;
    }

    /**
     * Check if extraction has expired (past the unstable window).
     */
    public function isExpired(): bool
    {
        $expiryTime = $this->getExpiryTime();
        return $expiryTime ? Carbon::now() >= $expiryTime : false;
    }

    /**
     * Check if auto-fracture warning should be shown (during unstable window).
     */
    public function shouldShowAutoFractureWarning(): bool
    {
        return $this->isUnstable();
    }

    /**
     * @deprecated Use shouldShowAutoFractureWarning() instead
     */
    public function shouldShowDecayWarning()
    {
        return $this->shouldShowAutoFractureWarning();
    }

    /**
     * Get time until expiry (end of unstable window) in human readable format.
     */
    public function getTimeUntilAutoFracture(): ?string
    {
        $expiryTime = $this->getExpiryTime();

        if (!$expiryTime || $expiryTime->isPast()) {
            return null;
        }

        $diff = Carbon::now()->diff($expiryTime);
        return sprintf('%dd %dh', $diff->days, $diff->h);
    }

    /**
     * Get time remaining in the ready phase (mining time left).
     */
    public function getTimeUntilUnstable(): ?string
    {
        $unstableStart = $this->getUnstableStartTime();

        if (!$unstableStart || $unstableStart->isPast()) {
            return null;
        }

        $diff = Carbon::now()->diff($unstableStart);
        return sprintf('%dd %dh %dm', $diff->days, $diff->h, $diff->i);
    }

    /**
     * @deprecated Use getTimeUntilAutoFracture() instead
     */
    public function getTimeUntilDecay()
    {
        return $this->getTimeUntilAutoFracture();
    }

    /**
     * Get hours since chunk arrived.
     */
    public function getHoursSinceArrival(): ?int
    {
        if (!$this->chunk_arrival_time || $this->chunk_arrival_time->isFuture()) {
            return null;
        }

        return (int) $this->chunk_arrival_time->diffInHours(Carbon::now());
    }

    /**
     * Check if moon is still within the "Today" display window.
     * Ready moons are shown for the duration of the ready window after fracture.
     */
    public function isWithinTodayWindow(): bool
    {
        $fractureTime = $this->getFractureTime();
        if (!$fractureTime) {
            return false;
        }

        $now = Carbon::now();
        $unstableStart = $this->getUnstableStartTime();

        return $now >= $fractureTime && $unstableStart && $now < $unstableStart;
    }

    /**
     * Get the effective status including unstable state.
     * Returns: 'extracting', 'ready', 'unstable', 'expired'
     */
    public function getEffectiveStatus(): string
    {
        // If expired (past auto-fracture time)
        if ($this->isExpired()) {
            return 'expired';
        }

        // If chunk hasn't arrived yet
        if ($this->chunk_arrival_time && $this->chunk_arrival_time->isFuture()) {
            return 'extracting';
        }

        // If in unstable window (48-51h after arrival)
        if ($this->isUnstable()) {
            return 'unstable';
        }

        // Otherwise ready (arrived and within 48h)
        return 'ready';
    }

    /**
     * Scope: extractions that have expired based on fractured_at or legacy estimate.
     * Uses SQL-level checks so it can be used in bulk updates.
     *
     * Expiry = fractured_at + 50h (if fractured_at is set)
     * Legacy: chunk_arrival + 50h (manual) or chunk_arrival + 53h (auto-fractured)
     */
    public function scopeExpiredByTime($query)
    {
        $now = Carbon::now();

        return $query->where('status', '!=', 'expired')
            ->where('status', '!=', 'fractured')
            ->where(function ($q) use ($now) {
                // Has actual fractured_at: expiry = fractured_at + 50h
                $q->where(function ($q2) use ($now) {
                    $q2->whereNotNull('fractured_at')
                       ->where('fractured_at', '<', $now->copy()->subHours(50));
                })
                // Legacy fallback: no fractured_at, use old estimate
                ->orWhere(function ($q2) use ($now) {
                    $q2->whereNull('fractured_at')
                       ->where(function ($q3) use ($now) {
                           $q3->where(function ($q4) use ($now) {
                               $q4->where('auto_fractured', false)
                                  ->where('chunk_arrival_time', '<', $now->copy()->subHours(50));
                           })->orWhere(function ($q4) use ($now) {
                               $q4->where('auto_fractured', true)
                                  ->where('chunk_arrival_time', '<', $now->copy()->subHours(53));
                           });
                       });
                });
            });
    }

    /**
     * Scope: only include active extractions.
     */
    public function scopeExtracting($query)
    {
        return $query->where('status', 'extracting');
    }

    /**
     * Scope: only include ready extractions.
     */
    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    /**
     * Scope: upcoming extractions.
     */
    public function scopeUpcoming($query, $hours = 48)
    {
        return $query->where('status', 'extracting')
            ->where('chunk_arrival_time', '>=', now())
            ->where('chunk_arrival_time', '<=', now()->addHours($hours));
    }

    /**
     * Scope: only include jackpot extractions.
     */
    public function scopeJackpot($query)
    {
        return $query->where('is_jackpot', true);
    }

    /**
     * Check if extraction is ready to mine (between fracture and unstable).
     */
    public function isReady()
    {
        $fractureTime = $this->getFractureTime();
        if (!$fractureTime) {
            return false;
        }

        $now = now();
        $unstableStart = $this->getUnstableStartTime();

        return $now->greaterThanOrEqualTo($fractureTime) && $unstableStart && $now->lessThan($unstableStart);
    }

    /**
     * Get hours until chunk arrival.
     */
    public function getHoursUntilArrival()
    {
        if (!$this->chunk_arrival_time || now()->greaterThan($this->chunk_arrival_time)) {
            return null;
        }

        return now()->diffInHours($this->chunk_arrival_time, false);
    }

    /**
     * Get hours until expiry (end of unstable window).
     */
    public function getHoursUntilDecay()
    {
        $expiryTime = $this->getExpiryTime();

        if (!$expiryTime || now()->greaterThan($expiryTime)) {
            return null;
        }

        return now()->diffInHours($expiryTime, false);
    }

    // ============================================
    // JACKPOT DETECTION METHODS
    // ============================================

    /**
     * Detect and mark if this extraction is a jackpot
     */
    public function detectJackpot(): bool
    {
        if (empty($this->ore_composition)) {
            return false;
        }

        $isJackpot = MoonOreHelper::detectJackpotInComposition($this->ore_composition);

        if ($isJackpot && !$this->is_jackpot) {
            // Atomic update to prevent race condition with concurrent workers
            $updated = static::where('id', $this->id)
                ->where('is_jackpot', false)
                ->update([
                    'is_jackpot' => true,
                    'jackpot_detected_at' => now(),
                ]);

            if ($updated) {
                $this->refresh();
            }
        }

        return $isJackpot;
    }

    /**
     * Get jackpot statistics for this extraction
     */
    public function getJackpotStatistics(): array
    {
        if (empty($this->ore_composition)) {
            return [
                'is_jackpot' => false,
                'total_ore_types' => 0,
                'jackpot_ore_types' => 0,
                'jackpot_percentage' => 0,
            ];
        }

        return MoonOreHelper::getJackpotStatistics($this->ore_composition);
    }

    /**
     * Get all jackpot ores in this extraction
     */
    public function getJackpotOres(): array
    {
        if (empty($this->ore_composition)) {
            return [];
        }

        return MoonOreHelper::getJackpotOresFromComposition($this->ore_composition);
    }

    /**
     * Get jackpot display badge HTML
     */
    public function getJackpotBadgeAttribute(): ?string
    {
        if (!$this->is_jackpot) {
            return null;
        }

        $stats = $this->getJackpotStatistics();
        $percentage = round($stats['jackpot_percentage']);

        return sprintf(
            '<span class="badge badge-warning" title="Jackpot Extraction! %d%% of ores are +100%% variants" style="background: linear-gradient(45deg, #ffd700, #ffed4e); color: #000; font-weight: bold;">
                <i class="fas fa-star"></i> JACKPOT (%d%%)
            </span>',
            $percentage,
            $percentage
        );
    }

    /**
     * Get jackpot value multiplier for this extraction
     */
    public function getJackpotValueMultiplier(): float
    {
        if (!$this->is_jackpot || empty($this->ore_composition)) {
            return 1.0;
        }

        return MoonOreHelper::calculateJackpotMultiplier($this->ore_composition);
    }

    /**
     * Calculate estimated value with jackpot bonus
     */
    public function calculateValueWithJackpotBonus(float $baseValue): float
    {
        $multiplier = $this->getJackpotValueMultiplier();
        return $baseValue * $multiplier;
    }
}
