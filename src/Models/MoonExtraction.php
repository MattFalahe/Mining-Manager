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
        'estimated_value' => 'integer',
        'ore_composition' => 'array',
        'notification_sent' => 'boolean',
        'is_jackpot' => 'boolean',
        'jackpot_detected_at' => 'datetime',
    ];

    /**
     * Get the corporation.
     */
    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
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

        $universeStructure = \DB::table('universe_structures')
            ->where('structure_id', $this->structure_id)
            ->first();

        if ($universeStructure && !empty($universeStructure->name)) {
            return $universeStructure->name;
        }

        $corpStructure = \DB::table('corporation_structures')
            ->where('structure_id', $this->structure_id)
            ->first();

        if ($corpStructure) {
            return "Structure {$this->structure_id}";
        }

        return "Unknown Structure";
    }

    /**
     * Get the structure (refinery).
     */
    public function structure()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStructure::class, 'structure_id', 'structure_id');
    }

    /**
     * Check if decay warning should be shown (within 3 hours of decay).
     */
    public function shouldShowDecayWarning()
    {
        if (!$this->natural_decay_time) {
            return false;
        }

        $now = Carbon::now();
        $threeHoursBeforeDecay = $this->natural_decay_time->copy()->subHours(3);

        return $now >= $threeHoursBeforeDecay && $now < $this->natural_decay_time;
    }

    /**
     * Get time until decay in human readable format.
     */
    public function getTimeUntilDecay()
    {
        if (!$this->natural_decay_time || $this->natural_decay_time->isPast()) {
            return null;
        }

        $diff = Carbon::now()->diff($this->natural_decay_time);
        return sprintf('%dd %dh', $diff->days, $diff->h);
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
        return $this->status === 'ready' || 
               (now()->greaterThanOrEqualTo($this->chunk_arrival_time) && 
                now()->lessThan($this->natural_decay_time));
    }

    /**
     * Check if extraction has expired.
     */
    public function isExpired()
    {
        return now()->greaterThan($this->natural_decay_time);
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
     * Get hours until decay.
     */
    public function getHoursUntilDecay()
    {
        if (now()->greaterThan($this->natural_decay_time)) {
            return null;
        }

        return now()->diffInHours($this->natural_decay_time, false);
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
