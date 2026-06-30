<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Carbon\Carbon;

/**
 * MoonExtractionPlan Model
 *
 * A single planned moon pull on the corp-internal planner. This is an
 * INTENT layer — it does not and cannot drive the in-game structure; SeAT
 * only reads extractions via ESI. The planner exists so a corp can stagger
 * arrivals across many refineries (see the migration docblock for the why).
 *
 * `planned_arrival_time` is the source of truth for when the pull is meant
 * to land. Auto-fill seeds it from history cadence; the moon manager can
 * move it; once a real extraction matches, the row is reconciled to
 * status=confirmed with linked_extraction_id + variance_hours set.
 *
 * @property int $id
 * @property int $corporation_id
 * @property int $structure_id
 * @property int|null $moon_id
 * @property \Carbon\Carbon $planned_arrival_time
 * @property int|null $cadence_days
 * @property string $source              manual|auto
 * @property string $status              planned|confirmed|superseded|done|cancelled
 * @property int|null $linked_extraction_id
 * @property int|null $variance_hours
 * @property int|null $created_by
 * @property string|null $notes
 */
class MoonExtractionPlan extends Model
{
    protected $table = 'moon_extraction_plans';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTO = 'auto';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'corporation_id',
        'structure_id',
        'moon_id',
        'planned_arrival_time',
        'cadence_days',
        'source',
        'status',
        'linked_extraction_id',
        'variance_hours',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'planned_arrival_time' => 'datetime',
        'cadence_days' => 'integer',
        'variance_hours' => 'integer',
        'corporation_id' => 'integer',
        'structure_id' => 'integer',
        'moon_id' => 'integer',
        'linked_extraction_id' => 'integer',
        'created_by' => 'integer',
    ];

    /**
     * Statuses that represent a still-active intent (not yet completed or
     * thrown away). Used by the gap checker + projection so superseded /
     * done / cancelled rows don't pollute conflict detection.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_CONFIRMED,
    ];

    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    public function moon()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Sde\Moon::class, 'moon_id', 'moon_id');
    }

    public function structure()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStructure::class, 'structure_id', 'structure_id');
    }

    /**
     * The actual extraction this plan was reconciled to, if any.
     */
    public function linkedExtraction()
    {
        return $this->belongsTo(MoonExtraction::class, 'linked_extraction_id', 'id');
    }

    public function scopeForCorporation($query, int $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    /**
     * Active intent only — drops superseded/done/cancelled.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeForMonth($query, Carbon $month)
    {
        return $query->whereBetween('planned_arrival_time', [
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth(),
        ]);
    }

    /**
     * Reuse the MoonExtraction display-name batch loader so planner rows
     * render moon + structure names without N+1 queries. The loader keys
     * off structure_id + moon_id, both of which this model also carries.
     *
     * @param \Illuminate\Support\Collection $plans
     * @return \Illuminate\Support\Collection
     */
    public static function loadDisplayNames($plans)
    {
        return MoonExtraction::loadDisplayNames($plans);
    }

    public function getMoonNameAttribute()
    {
        if (isset($this->attributes['moon_name'])) {
            return $this->attributes['moon_name'];
        }

        if (!$this->moon_id) {
            return 'Unknown Moon';
        }

        $moon = \DB::table('moons')->where('moon_id', $this->moon_id)->first();
        return $moon ? $moon->name : "Moon {$this->moon_id}";
    }
}
