<?php

namespace MiningManager\Console\Commands\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * A command that can run long needs two numbers that agree: how long it is
 * allowed to work for, and how long its lock is held.
 *
 * Picked separately they drift. The lock then expires underneath a command
 * that is still working, the next caller finds nothing holding it and starts a
 * second copy on top of the first. For anything talking to a rate limited
 * provider that is the worst possible moment to double the traffic, because a
 * run only gets long when the provider is already struggling.
 *
 * So the lease is derived from the budget instead of chosen, and the command
 * stops starting new work once the budget is gone.
 */
trait RunsWithinABudget
{
    /**
     * How far the lease outlives the budget. Covers whatever is still in
     * flight when the budget runs out, so the lock is never the first of the
     * two to go.
     */
    protected int $lockHeadroomSeconds = 120;

    private ?int $budgetEndsAt = null;

    /**
     * Take the lock and start the clock. Null means somebody else holds it.
     *
     * @return \Illuminate\Contracts\Cache\Lock|null
     */
    protected function lockForBudget(string $name, int $budgetSeconds)
    {
        $lock = Cache::lock($name, $budgetSeconds + $this->lockHeadroomSeconds);

        if (! $lock->get()) {
            return null;
        }

        $this->budgetEndsAt = time() + $budgetSeconds;

        return $lock;
    }

    /**
     * True once there is no time left to start anything new. Work already
     * under way is allowed to finish; that is what the headroom is for.
     */
    protected function budgetSpent(): bool
    {
        return $this->budgetEndsAt !== null && time() >= $this->budgetEndsAt;
    }

    /**
     * The moment the budget runs out, as a unix timestamp, for handing to a
     * service that does the slow part on the command's behalf.
     */
    protected function budgetEndsAt(): ?int
    {
        return $this->budgetEndsAt;
    }
}
