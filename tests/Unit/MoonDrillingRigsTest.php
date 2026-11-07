<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Moon\MoonDrillingRigs;
use PHPUnit\Framework\TestCase;

class MoonDrillingRigsTest extends TestCase
{
    public function test_a_chunks_rig_is_read_from_the_time_eve_set_it_to_fracture(): void
    {
        $this->assertSame(0, MoonDrillingRigs::timerTier('2026-11-01 12:00:00', '2026-11-01 15:00:00'));
        $this->assertSame(1, MoonDrillingRigs::timerTier('2026-11-01 12:00:00', '2026-11-01 15:36:00'));
        $this->assertSame(2, MoonDrillingRigs::timerTier('2026-11-01 12:00:00', '2026-11-01 15:43:12'));
    }

    public function test_a_gap_no_fit_produces_says_nothing_about_the_rig(): void
    {
        $this->assertNull(MoonDrillingRigs::timerTier('2026-11-01 12:00:00', '2026-11-01 13:00:00'));
        $this->assertNull(MoonDrillingRigs::timerTier('2026-11-01 12:00:00', '2026-11-01 17:00:00'));
        $this->assertNull(MoonDrillingRigs::timerTier(null, '2026-11-01 15:00:00'));
        $this->assertNull(MoonDrillingRigs::timerTier('2026-11-01 12:00:00', null));
    }

    public function test_the_mining_window_and_fracture_time_for_each_tier(): void
    {
        $this->assertSame([48, 72, 96], array_map([MoonDrillingRigs::class, 'readyHours'], [0, 1, 2]));
        $this->assertEqualsWithDelta(180.0, MoonDrillingRigs::autoFractureMinutes(0), 0.001);
        $this->assertEqualsWithDelta(216.0, MoonDrillingRigs::autoFractureMinutes(1), 0.001);
        $this->assertEqualsWithDelta(223.2, MoonDrillingRigs::autoFractureMinutes(2), 0.001);
    }

    /** Look-backs that have to catch every live chunk reach this far, so it has to cover the longest one any fit makes. */
    public function test_the_longest_chunk_covers_the_best_rig_from_arrival_to_the_end_of_its_belt(): void
    {
        $hours = MoonDrillingRigs::autoFractureMinutes(2) / 60 + MoonDrillingRigs::readyHours(2) + MoonDrillingRigs::UNSTABLE_HOURS;

        $this->assertSame((int) ceil($hours), MoonDrillingRigs::LONGEST_CHUNK_HOURS);
    }

    public function test_a_tatara_rig_carries_the_timers_and_the_yield(): void
    {
        $fit = MoonDrillingRigs::summarise([MoonDrillingRigs::typeFor(MoonDrillingRigs::PROFICIENCY, 2)]);

        $this->assertSame(2, $fit['timer_tier']);
        $this->assertSame(2, $fit['yield_tier']);
        $this->assertSame(2.4, $fit['yield']);
        $this->assertSame(100.0, $fit['belt']);
    }

    public function test_an_athanor_has_a_rig_for_each(): void
    {
        $fit = MoonDrillingRigs::summarise([
            MoonDrillingRigs::typeFor(MoonDrillingRigs::STABILITY, 1),
            MoonDrillingRigs::typeFor(MoonDrillingRigs::EFFICIENCY, 2),
        ]);

        $this->assertSame(1, $fit['timer_tier']);
        $this->assertSame(2, $fit['yield_tier']);
        $this->assertSame(50.0, $fit['belt']);
        $this->assertSame(2.4, $fit['yield']);
        $this->assertCount(2, $fit['rigs']);
    }

    public function test_only_moon_rigs_count(): void
    {
        $fit = MoonDrillingRigs::summarise([37180, 46325, 46325]);

        $this->assertSame(1, $fit['timer_tier']);
        $this->assertSame(0, $fit['yield_tier']);
        $this->assertCount(1, $fit['rigs']);

        $this->assertSame(0, MoonDrillingRigs::summarise([])['timer_tier']);
    }

    public function test_every_rig_is_found_by_its_kind_and_tier(): void
    {
        foreach (MoonDrillingRigs::RIGS as $typeId => $rig) {
            $this->assertSame($typeId, MoonDrillingRigs::typeFor($rig['kind'], $rig['tier']));
        }

        $this->assertNull(MoonDrillingRigs::typeFor(MoonDrillingRigs::STABILITY, 3));
    }
}
