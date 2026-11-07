<?php

namespace MiningManager\Services\Events;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MiningEvent;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Tax\InvoiceCoverage;

/**
 * Who may do what to an event, and how far back or how soon an event may
 * start.
 *
 * Directors and admins may set up any event, past ones included, and never
 * need approval. A moon manager may set up an event that starts far enough
 * ahead, and look after it until it starts; whether it waits for a director
 * is the Events settings' call. Every event stops changing once its figures
 * are final, except that an admin may still cancel it.
 */
class EventAccess
{
    private ?array $settings = null;

    public function __construct(private SettingsManagerService $settingsService)
    {
    }

    /**
     * Directors, and admins, who hold every ability.
     *
     * @param \Seat\Web\Models\User|null $user
     */
    public function isDirector($user): bool
    {
        return $user !== null && $user->can('mining-manager.director');
    }

    /**
     * @param \Seat\Web\Models\User|null $user
     */
    public function isAdmin($user): bool
    {
        return $user !== null && $user->can('mining-manager.admin');
    }

    /**
     * @param \Seat\Web\Models\User|null $user
     */
    public function isMoonManager($user): bool
    {
        return $user !== null && $user->can('mining-manager.moon_manager');
    }

    /**
     * Whether the user may set up events at all.
     *
     * @param \Seat\Web\Models\User|null $user
     */
    public function canCreate($user): bool
    {
        if ($this->isDirector($user)) {
            return true;
        }

        return MiningEvent::hasGovernance() && $this->isMoonManager($user) && $this->settings()['managers_can_create'];
    }

    /**
     * @param \Seat\Web\Models\User|null $user
     */
    public function owns($user, MiningEvent $event): bool
    {
        return $user !== null && $event->created_by !== null && (int) $event->created_by === (int) $user->id;
    }

    /**
     * Members see what has been announced. An event held, waiting for
     * approval or declined is for the directors and whoever set it up.
     *
     * @param \Seat\Web\Models\User|null $user
     */
    public function canSee($user, MiningEvent $event): bool
    {
        return $event->isAnnounced() || $this->isDirector($user) || $this->owns($user, $event);
    }

    /**
     * A moon manager looks after their own event until it starts.
     *
     * @param \Seat\Web\Models\User|null $user
     */
    private function ownsBeforeStart($user, MiningEvent $event): bool
    {
        return $this->isMoonManager($user)
            && $this->owns($user, $event)
            && !$event->hasStarted()
            && in_array($event->status, [MiningEvent::STATUS_PLANNED, MiningEvent::STATUS_AWAITING_APPROVAL], true);
    }

    /**
     * @param \Seat\Web\Models\User|null $user
     */
    public function canEdit($user, MiningEvent $event): bool
    {
        if ($event->isLocked() || in_array($event->status, [MiningEvent::STATUS_CANCELLED, MiningEvent::STATUS_DECLINED], true)) {
            return false;
        }

        return $this->isDirector($user) || $this->ownsBeforeStart($user, $event);
    }

    /**
     * An admin may cancel even a locked event, with a reason: a mistake found
     * after the figures went out should not have to wait for the bills.
     *
     * @param \Seat\Web\Models\User|null $user
     */
    public function canCancel($user, MiningEvent $event): bool
    {
        if (in_array($event->status, [MiningEvent::STATUS_CANCELLED, MiningEvent::STATUS_DECLINED], true)) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        if ($event->isLocked()) {
            return false;
        }

        return $this->isDirector($user) || $this->ownsBeforeStart($user, $event);
    }

    /**
     * @param \Seat\Web\Models\User|null $user
     */
    public function canRestore($user, MiningEvent $event): bool
    {
        if ($event->status !== MiningEvent::STATUS_CANCELLED) {
            return false;
        }

        return $this->isAdmin($user) || ($this->isDirector($user) && !$event->isLocked());
    }

    /**
     * @param \Seat\Web\Models\User|null $user
     */
    public function canApprove($user, MiningEvent $event): bool
    {
        return $event->status === MiningEvent::STATUS_AWAITING_APPROVAL && $this->isDirector($user);
    }

    /**
     * Deleting takes the event and its record of who mined away. Directors
     * may delete one that has never counted; anything else is an admin's,
     * and nobody deletes a locked event: cancel it instead, so it stays on
     * record.
     *
     * @param \Seat\Web\Models\User|null $user
     */
    public function canDelete($user, MiningEvent $event): bool
    {
        if ($event->isLocked()) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        $neverCounted = in_array($event->status, [MiningEvent::STATUS_HELD, MiningEvent::STATUS_AWAITING_APPROVAL, MiningEvent::STATUS_DECLINED], true)
            || ($event->status === MiningEvent::STATUS_PLANNED && !$event->hasStarted());

        if ($this->isDirector($user)) {
            return $neverCounted;
        }

        return $neverCounted && $this->ownsBeforeStart($user, $event);
    }

    /**
     * The first day an event can count, the day after the last day on an
     * issued bill, or null while nothing has been billed.
     */
    public function earliestFirstDay(): ?Carbon
    {
        $last = InvoiceCoverage::lastCoveredDay();

        return $last !== null ? Carbon::parse($last)->addDay()->startOfDay() : null;
    }

    /**
     * The earliest a moon manager's event may start.
     */
    public function earliestManagerStart(): Carbon
    {
        return Carbon::now()->addHours($this->settings()['manager_lead_hours']);
    }

    /**
     * Whether a moon manager's event at this rate waits for a director.
     */
    public function needsApproval(int $taxModifier): bool
    {
        $rule = $this->settings()['approval_rule'];

        return match ($rule) {
            'never' => false,
            'always' => true,
            default => $taxModifier <= -(int) $rule,
        };
    }

    /**
     * When an event for days that have passed, set up now, applies: once the
     * hold is over, and at least half an hour before the next bill run, which
     * could bill its days. Null when that run is too close.
     */
    public function holdUntil(): ?Carbon
    {
        $deadline = $this->nextBillRun()->subMinutes(30);

        if (Carbon::now()->gte($deadline)) {
            return null;
        }

        $until = Carbon::now()->addMinutes($this->settings()['past_hold_minutes']);

        return $until->lt($deadline) ? $until : $deadline;
    }

    /**
     * The next run of the tax calculation that can bill a period: the 2nd
     * and the 16th, at the time its schedule says, 02:15 unless an operator
     * moved it. A monthly install only bills on the 2nd, so for it the 16th
     * is merely early, never late.
     */
    public function nextBillRun(): Carbon
    {
        [$hour, $minute] = $this->billRunTime();
        $now = Carbon::now();
        $day = $now->copy()->startOfDay()->setTime($hour, $minute);

        for ($i = 0; $i < 45; $i++, $day->addDay()) {
            if (in_array($day->day, [2, 16], true) && $day->gt($now)) {
                return $day;
            }
        }

        return $day;
    }

    /**
     * Hour and minute of the tax calculation's schedule.
     *
     * @return array{0: int, 1: int}
     */
    private function billRunTime(): array
    {
        try {
            $expression = (string) DB::table('schedules')->where('command', 'mining-manager:calculate-taxes')->value('expression');
            $parts = preg_split('/\s+/', trim($expression));

            if (isset($parts[0], $parts[1]) && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
                return [min(23, (int) $parts[1]), min(59, (int) $parts[0])];
            }
        } catch (\Throwable $e) {
            // Falls back to the time the plugin schedules it at.
        }

        return [2, 15];
    }

    /**
     * @return array{past_hold_minutes: int, managers_can_create: bool, manager_lead_hours: int, approval_rule: string}
     */
    public function settings(): array
    {
        return $this->settings ??= $this->settingsService->getEventSettings();
    }
}
