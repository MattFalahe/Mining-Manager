<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Webhook Configuration Model
 *
 * Stores webhook configurations for theft detection notifications
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string $webhook_url
 * @property bool $is_enabled
 * @property bool $notify_theft_detected
 * @property bool $notify_critical_theft
 * @property bool $notify_active_theft
 * @property bool $notify_incident_resolved
 * @property bool $notify_moon_arrival
 * @property bool $notify_jackpot_detected
 * @property bool $notify_event_created
 * @property bool $notify_event_started
 * @property bool $notify_event_completed
 * @property bool $notify_tax_reminder
 * @property bool $notify_tax_invoice
 * @property bool $notify_tax_overdue
 * @property string|null $discord_role_id
 * @property string|null $discord_username
 * @property string|null $slack_channel
 * @property string|null $slack_username
 * @property string|null $custom_payload_template
 * @property array|null $custom_headers
 * @property int $success_count
 * @property int $failure_count
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property string|null $last_error
 * @property int|null $corporation_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WebhookConfiguration extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'webhook_configurations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'type',
        'webhook_url',
        'is_enabled',
        'notify_theft_detected',
        'notify_critical_theft',
        'notify_active_theft',
        'notify_incident_resolved',
        'notify_moon_arrival',
        'notify_jackpot_detected',
        'notify_event_created',
        'notify_event_started',
        'notify_event_completed',
        'notify_tax_reminder',
        'notify_tax_invoice',
        'notify_tax_overdue',
        'discord_role_id',
        'discord_username',
        'slack_channel',
        'slack_username',
        'custom_payload_template',
        'custom_headers',
        'corporation_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_enabled' => 'boolean',
        'notify_theft_detected' => 'boolean',
        'notify_critical_theft' => 'boolean',
        'notify_active_theft' => 'boolean',
        'notify_incident_resolved' => 'boolean',
        'notify_moon_arrival' => 'boolean',
        'notify_jackpot_detected' => 'boolean',
        'notify_event_created' => 'boolean',
        'notify_event_started' => 'boolean',
        'notify_event_completed' => 'boolean',
        'notify_tax_reminder' => 'boolean',
        'notify_tax_invoice' => 'boolean',
        'notify_tax_overdue' => 'boolean',
        'custom_headers' => 'array',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'success_count' => 'integer',
        'failure_count' => 'integer',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'webhook_url', // Hide sensitive webhook URLs from general queries
    ];

    /**
     * Scope to get only enabled webhooks
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to get webhooks for a specific corporation
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $corporationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCorporation($query, $corporationId)
    {
        if ($corporationId) {
            return $query->where('corporation_id', $corporationId);
        }
        return $query->whereNull('corporation_id');
    }

    /**
     * Scope to get webhooks by type
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get webhooks that should be notified for a specific event
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $eventType (theft_detected, critical_theft, active_theft, incident_resolved)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForEvent($query, $eventType)
    {
        $columnMap = [
            'theft_detected' => 'notify_theft_detected',
            'critical_theft' => 'notify_critical_theft',
            'active_theft' => 'notify_active_theft',
            'incident_resolved' => 'notify_incident_resolved',
            'moon_arrival' => 'notify_moon_arrival',
            'jackpot_detected' => 'notify_jackpot_detected',
            'event_created' => 'notify_event_created',
            'event_started' => 'notify_event_started',
            'event_completed' => 'notify_event_completed',
            'tax_reminder' => 'notify_tax_reminder',
            'tax_invoice' => 'notify_tax_invoice',
            'tax_overdue' => 'notify_tax_overdue',
        ];

        $column = $columnMap[$eventType] ?? null;

        if ($column) {
            return $query->where($column, true);
        }

        return $query;
    }

    /**
     * Record a successful webhook delivery
     *
     * @return void
     */
    public function recordSuccess()
    {
        $this->increment('success_count');
        $this->update([
            'last_success_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Record a failed webhook delivery
     *
     * @param string $error
     * @return void
     */
    public function recordFailure($error)
    {
        $this->increment('failure_count');
        $this->update([
            'last_failure_at' => now(),
            'last_error' => $error,
        ]);
    }

    /**
     * Get health status percentage
     *
     * @return float
     */
    public function getHealthPercentageAttribute()
    {
        $total = $this->success_count + $this->failure_count;

        if ($total === 0) {
            return 100.0;
        }

        return round(($this->success_count / $total) * 100, 1);
    }

    /**
     * Check if webhook is healthy
     *
     * @return bool
     */
    public function isHealthy()
    {
        // Consider healthy if success rate is above 80% and last attempt succeeded
        return $this->health_percentage >= 80.0 &&
               ($this->last_success_at === null ||
                $this->last_failure_at === null ||
                $this->last_success_at->isAfter($this->last_failure_at));
    }

    /**
     * Get Discord mention string for role
     *
     * @return string|null
     */
    public function getDiscordRoleMention()
    {
        if ($this->type === 'discord' && $this->discord_role_id) {
            return "<@&{$this->discord_role_id}>";
        }
        return null;
    }

    /**
     * Check if this webhook should be notified for a specific event type
     *
     * @param string $eventType
     * @return bool
     */
    public function shouldNotifyForEvent($eventType)
    {
        if (!$this->is_enabled) {
            return false;
        }

        $eventMap = [
            'theft_detected' => $this->notify_theft_detected,
            'critical_theft' => $this->notify_critical_theft,
            'active_theft' => $this->notify_active_theft,
            'incident_resolved' => $this->notify_incident_resolved,
            'moon_arrival' => $this->notify_moon_arrival,
            'jackpot_detected' => $this->notify_jackpot_detected,
            'event_created' => $this->notify_event_created,
            'event_started' => $this->notify_event_started,
            'event_completed' => $this->notify_event_completed,
            'tax_reminder' => $this->notify_tax_reminder,
            'tax_invoice' => $this->notify_tax_invoice,
            'tax_overdue' => $this->notify_tax_overdue,
        ];

        return $eventMap[$eventType] ?? false;
    }
}
