<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Seat\Web\Models\User;

/**
 * One thing done to an event: by whom, when, what changed and why. A row with
 * no user is the plugin's own doing, such as a held event applying. Rows stay
 * when their event is deleted, so a delete is still on record.
 */
class EventHistory extends Model
{
    public const UPDATED_AT = null;

    public const CREATED = 'created';
    public const EDITED = 'edited';
    public const SENT_FOR_APPROVAL = 'sent_for_approval';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const LAPSED = 'lapsed';
    public const APPLIED = 'applied';
    public const STARTED = 'started';
    public const COMPLETED = 'completed';
    public const RESULTS_POSTED = 'results_posted';
    public const CANCELLED = 'cancelled';
    public const RESTORED = 'restored';
    public const DELETED = 'deleted';

    protected $table = 'mining_manager_event_history';

    protected $fillable = [
        'event_id',
        'user_id',
        'action',
        'changes',
        'reason',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Put an action on an event's record. A history that cannot be written is
     * logged and the action goes ahead: what it records has already happened.
     */
    public static function record(MiningEvent $event, string $action, ?int $userId = null, array $changes = [], ?string $reason = null): void
    {
        if (!MiningEvent::hasGovernance()) {
            return;
        }

        try {
            static::create([
                'event_id' => $event->id,
                'user_id' => $userId,
                'action' => $action,
                'changes' => $changes === [] ? null : $changes,
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Mining Manager: could not record '{$action}' on event {$event->id}: {$e->getMessage()}");
        }
    }
}
