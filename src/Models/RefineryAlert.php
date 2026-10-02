<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What the Moon Planner remembers about one refinery between runs.
 *
 * One row per corporation, structure and kind. See the 000033 migration for
 * what each column means for each kind.
 *
 * @property int $corporation_id
 * @property int $structure_id
 * @property string $kind
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $last_at
 * @property int $count
 * @property \Carbon\Carbon|null $acted_at
 */
class RefineryAlert extends Model
{
    /** Missing from the corporation's structures. */
    public const KIND_MISSING = 'refinery_missing';

    /** Chunk arrived and nothing has been started since. */
    public const KIND_NOT_RESCHEDULED = 'not_rescheduled';

    protected $table = 'mining_manager_refinery_alerts';

    protected $fillable = [
        'corporation_id',
        'structure_id',
        'kind',
        'started_at',
        'last_at',
        'count',
        'acted_at',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'structure_id' => 'integer',
        'started_at' => 'datetime',
        'last_at' => 'datetime',
        'count' => 'integer',
        'acted_at' => 'datetime',
    ];
}
