<?php

namespace MiningManager\Models\Concerns;

use Carbon\Carbon;
use MiningManager\Services\Moon\MoonDrillingRigs;

/**
 * A moon chunk's cycle, shared by live and archived extractions.
 *
 * Mining Manager's own cycle: the chunk arrives and is fractured, by a laser
 * shot or on its own at EVE's auto-fracture time; it can then be mined for 48
 * hours and spends a further 2 hours unstable before it is gone. A Stability
 * or Proficiency rig stretches only the 48 hours, to 72 or 96, and the wait
 * before the chunk fractures on its own. The 2 hour tail stays 2 hours.
 *
 * The rig is read from the chunk itself: EVE schedules its auto-fracture from
 * the rig fitted, so the gap between arrival and natural_decay_time gives the
 * tier (MoonDrillingRigs::timerTier()). What is fitted today does not change
 * a chunk that was pulled with something else.
 *
 * Expects chunk_arrival_time, natural_decay_time and fractured_at cast to
 * dates, auto_fractured to a boolean and moon_rigs to an array.
 */
trait ChunkLifecycle
{
    /**
     * The tier of the timer rig this chunk had: 0 none, 1 Tech I, 2 Tech II.
     */
    public function timerRigTier(): int
    {
        $fromTimer = MoonDrillingRigs::timerTier($this->chunk_arrival_time, $this->natural_decay_time);

        if ($fromTimer !== null) {
            return $fromTimer;
        }

        // No usable auto-fracture time: whatever was seen fitted, if anything.
        $rigs = is_array($this->moon_rigs) ? ($this->moon_rigs['rigs'] ?? []) : [];

        return MoonDrillingRigs::summarise(is_array($rigs) ? $rigs : [])['timer_tier'];
    }

    /**
     * Minutes from arrival until the chunk fractures on its own: EVE's own
     * auto-fracture time when we have it, otherwise 3 hours stretched by the
     * chunk's rig.
     */
    public function getAutoFractureDelayMinutes(): float
    {
        if ($this->chunk_arrival_time && $this->natural_decay_time) {
            $minutes = ($this->natural_decay_time->getTimestamp() - $this->chunk_arrival_time->getTimestamp()) / 60;

            if ($minutes > 0) {
                return $minutes;
            }
        }

        return MoonDrillingRigs::autoFractureMinutes($this->timerRigTier());
    }

    /**
     * Get the actual fracture time (when mining became available).
     *
     * Timeline:
     * - Chunk arrives (chunk_arrival_time) → waiting for player to fire laser
     * - Player fires laser → fractured_at = notification timestamp (manual fracture)
     * - No one fires → it fractures on its own at EVE's auto-fracture time
     * - From fractured_at: the mining window → 2h unstable → expired
     *
     * If fractured_at is not set, falls back to chunk_arrival_time (legacy behavior).
     */
    public function getFractureTime(): ?Carbon
    {
        if ($this->fractured_at) {
            return $this->fractured_at;
        }

        // Legacy fallback: estimate based on auto_fractured flag
        if ($this->chunk_arrival_time) {
            return $this->auto_fractured
                ? $this->chunk_arrival_time->copy()->addSeconds((int) round($this->getAutoFractureDelayMinutes() * 60))
                : $this->chunk_arrival_time->copy();
        }

        return null;
    }

    /**
     * Hours the chunk can be mined after fracture: 48, or 72 / 96 with a
     * Stability or Proficiency rig.
     */
    public function getReadyDurationHours(): int
    {
        return MoonDrillingRigs::readyHours($this->timerRigTier());
    }

    /**
     * Get the time when the unstable phase starts (end of the mining window).
     */
    public function getUnstableStartTime(): ?Carbon
    {
        $fractureTime = $this->getFractureTime();

        return $fractureTime ? $fractureTime->copy()->addHours($this->getReadyDurationHours()) : null;
    }

    /**
     * Get the time when the extraction expires (end of the unstable window).
     */
    public function getExpiryTime(): ?Carbon
    {
        $unstableStart = $this->getUnstableStartTime();

        return $unstableStart ? $unstableStart->copy()->addHours(MoonDrillingRigs::UNSTABLE_HOURS) : null;
    }

    /**
     * Check if moon is in unstable state: the last 2 hours of the chunk's life.
     */
    public function isUnstable(): bool
    {
        $unstableStart = $this->getUnstableStartTime();
        $expiryTime = $this->getExpiryTime();

        if (!$unstableStart || !$expiryTime) {
            return false;
        }

        $now = Carbon::now();

        return $now >= $unstableStart && $now < $expiryTime;
    }

    /**
     * Check if extraction has expired (past the unstable window).
     */
    public function isExpired(): bool
    {
        $expiryTime = $this->getExpiryTime();

        return $expiryTime ? Carbon::now() >= $expiryTime : false;
    }

    /**
     * The moon rigs seen on the refinery while this chunk was on its way, with
     * what they add up to. Null when nothing was recorded, as on extractions
     * from before rigs were tracked.
     */
    public function moonRigSummary(): ?array
    {
        if (!is_array($this->moon_rigs)) {
            return null;
        }

        $rigs = $this->moon_rigs['rigs'] ?? [];

        return MoonDrillingRigs::summarise(is_array($rigs) ? $rigs : []) + [
            'assets_visible' => (bool) ($this->moon_rigs['assets_visible'] ?? false),
        ];
    }
}
