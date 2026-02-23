<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NotificationLog Model
 *
 * Tracks sent notifications for history and debugging.
 * Maps to the `mining_notification_log` table created in
 * 2026_01_01_000004_create_mining_support_tables migration.
 *
 * Note: This table intentionally uses only `created_at` (no `updated_at`)
 * since log entries are write-once and never modified.
 */
class NotificationLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mining_notification_log';

    /**
     * Disable updated_at — log entries are immutable.
     */
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'type',
        'recipients',
        'channels',
        'results',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'recipients' => 'array',
        'channels' => 'array',
        'results' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Scope to filter by notification type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get recent notifications.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $hours
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Check if any notification of the given type was sent recently.
     *
     * @param string $type
     * @param int $hours
     * @return bool
     */
    public static function wasSentRecently(string $type, int $hours = 24): bool
    {
        return self::ofType($type)->recent($hours)->exists();
    }
}
