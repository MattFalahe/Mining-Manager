<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Audit-trail row for a Moon Extraction Planner action.
 *
 * @property int $id
 * @property int $corporation_id
 * @property int|null $plan_id
 * @property int|null $structure_id
 * @property int|null $moon_id
 * @property string $action           created|moved|deleted|autofilled
 * @property int|null $character_id
 * @property string|null $character_name
 * @property \Carbon\Carbon|null $old_arrival
 * @property \Carbon\Carbon|null $new_arrival
 * @property string|null $detail
 */
class MoonExtractionPlanAudit extends Model
{
    protected $table = 'moon_extraction_plan_audits';

    // created_at only — no updated_at column.
    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'created';
    public const ACTION_MOVED = 'moved';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_AUTOFILLED = 'autofilled';

    protected $fillable = [
        'corporation_id',
        'plan_id',
        'structure_id',
        'moon_id',
        'action',
        'character_id',
        'character_name',
        'old_arrival',
        'new_arrival',
        'detail',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'plan_id' => 'integer',
        'structure_id' => 'integer',
        'moon_id' => 'integer',
        'character_id' => 'integer',
        'old_arrival' => 'datetime',
        'new_arrival' => 'datetime',
    ];

    public function scopeForCorporation($query, int $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    /**
     * Record an audit entry. Defensive — never lets a logging failure break
     * the action being audited.
     */
    public static function record(array $attributes): void
    {
        try {
            $attributes['created_at'] = Carbon::now();
            static::create($attributes);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[MM] plan audit write failed: ' . $e->getMessage());
        }
    }
}
