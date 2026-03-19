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
        'estimated_value_at_start',
        'estimated_value_pre_arrival',
        'value_last_updated',
        'has_notification_data',
        'auto_fractured',
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
        'estimated_value_at_start' => 'float',
        'estimated_value_pre_arrival' => 'float',
        'value_last_updated' => 'datetime',
        'has_notification_data' => 'boolean',
        'auto_fractured' => 'boolean',
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
     * Get how many hours the ready state lasts.
     * 48h normally, 51h if auto-fractured (no player fired the laser).
     */
    public function getReadyDurationHours(): int
    {
        return $this->auto_fractured ? 51 : 48;
    }

    /**
     * Check if moon is in unstable state.
     * Unstable starts after the ready window ends and lasts 2 hours.
     */
    public function isUnstable(): bool
    {
        if (!$this->chunk_arrival_time) {
            return false;
        }

        $now = Carbon::now();
        $readyHours = $this->getReadyDurationHours();
        $unstableStart = $this->chunk_arrival_time->copy()->addHours($readyHours);
        $unstableEnd = $unstableStart->copy()->addHours(2);

        return $now >= $unstableStart && $now < $unstableEnd;
    }

    /**
     * Check if auto-fracture warning should be shown (during unstable window).
     */
    public function shouldShowAutoFractureWarning(): bool
    {
        if (!$this->chunk_arrival_time) {
            return false;
        }

        $readyHours = $this->getReadyDurationHours();
        $unstableStart = $this->chunk_arrival_time->copy()->addHours($readyHours);
        $unstableEnd = $unstableStart->copy()->addHours(2);
        $now = Carbon::now();

        return $now >= $unstableStart && $now < $unstableEnd;
    }

    /**
     * Check if decay warning should be shown (within 3 hours of decay).
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
        if (!$this->chunk_arrival_time) {
            return null;
        }

        $readyHours = $this->getReadyDurationHours();
        $unstableEnd = $this->chunk_arrival_time->copy()->addHours($readyHours + 2);

        if ($unstableEnd->isPast()) {
            return null;
        }

        $diff = Carbon::now()->diff($unstableEnd);
        return sprintf('%dd %dh', $diff->days, $diff->h);
    }

    /**
     * Get time until decay in human readable format.
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
     * Ready moons are shown for the duration of the ready window after arrival.
     */
    public function isWithinTodayWindow(): bool
    {
        if (!$this->chunk_arrival_time) {
            return false;
        }

        $now = Carbon::now();
        $windowEnd = $this->chunk_arrival_time->copy()->addHours($this->getReadyDurationHours());

        return $now >= $this->chunk_arrival_time && $now < $windowEnd;
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
     * Check if extraction is ready to mine.
     */
    public function isReady()
    {
        if (!$this->chunk_arrival_time) {
            return false;
        }

        $now = now();
        $readyEnd = $this->chunk_arrival_time->copy()->addHours($this->getReadyDurationHours());

        return $now->greaterThanOrEqualTo($this->chunk_arrival_time) && $now->lessThan($readyEnd);
    }

    /**
     * Check if extraction has expired.
     */
    public function isExpired()
    {
        if (!$this->chunk_arrival_time) {
            return false;
        }

        $readyHours = $this->getReadyDurationHours();
        $expiredStart = $this->chunk_arrival_time->copy()->addHours($readyHours + 2);

        return now()->greaterThanOrEqualTo($expiredStart);
    }

    /**
     * Get hours until chunk arrival.
     */
    public function getHoursUntilArrival()
    {
        if (now()->greaterThan($this->chunk_arrival_time)) {
            return null;
        }

        return now()->diffInHours($this->chunk_arrival_time, false);
    }

    /**
     * Get hours until expiry (end of unstable window).
     */
    public function getHoursUntilDecay()
    {
        if (!$this->chunk_arrival_time) {
            return null;
        }

        $readyHours = $this->getReadyDurationHours();
        $unstableEnd = $this->chunk_arrival_time->copy()->addHours($readyHours + 2);

        if (now()->greaterThan($unstableEnd)) {
            return null;
        }

        return now()->diffInHours($unstableEnd, false);
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
            $this->is_jackpot = true;
            $this->jackpot_detected_at = now();
            $this->save();
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
