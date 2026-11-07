<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Moon\MoonOreHelper;
use PHPUnit\Framework\TestCase;

class MoonOreHelperTest extends TestCase
{
    private const ZEOLITES = 45490;
    private const EUXENITE = 45495;
    private const MONAZITE = 45511;
    private const SHINING_MONAZITE = 46315;

    public function test_a_moon_is_as_rare_as_the_richest_ore_in_it(): void
    {
        $this->assertSame('R64', MoonOreHelper::highestRarity([
            ['type_id' => self::ZEOLITES],
            ['type_id' => self::MONAZITE],
            ['type_id' => self::EUXENITE],
        ]));
        $this->assertSame('R8', MoonOreHelper::highestRarity([['type_id' => self::EUXENITE], ['type_id' => self::ZEOLITES]]));
    }

    public function test_a_composition_with_no_moon_ore_has_no_rarity(): void
    {
        $this->assertNull(MoonOreHelper::highestRarity(null));
        $this->assertNull(MoonOreHelper::highestRarity([]));
        $this->assertNull(MoonOreHelper::highestRarity([['type_id' => 1230], ['quantity' => 5]]));
    }

    public function test_the_badge_for_a_rarity_takes_either_case(): void
    {
        $this->assertSame('badge-r64', MoonOreHelper::rarityBadgeClass('R64'));
        $this->assertSame('badge-r4', MoonOreHelper::rarityBadgeClass(' r4 '));
        $this->assertSame('badge-secondary', MoonOreHelper::rarityBadgeClass(null));
        $this->assertSame('badge-secondary', MoonOreHelper::rarityBadgeClass('R128'));
    }

    public function test_the_quality_of_an_ore(): void
    {
        $this->assertSame('excellent', MoonOreHelper::getQuality(self::SHINING_MONAZITE));
        $this->assertSame('base', MoonOreHelper::getQuality(self::MONAZITE));
    }

    public function test_a_jackpot_is_found_in_either_shape_a_composition_comes_in(): void
    {
        $this->assertTrue(MoonOreHelper::detectJackpotInComposition([['type_id' => self::SHINING_MONAZITE]]));
        $this->assertTrue(MoonOreHelper::detectJackpotInComposition([(object) ['type_id' => self::SHINING_MONAZITE]]));
        $this->assertFalse(MoonOreHelper::detectJackpotInComposition([['type_id' => self::MONAZITE]]));
        $this->assertFalse(MoonOreHelper::detectJackpotInComposition([]));
    }

    public function test_jackpot_figures_for_a_mixed_chunk(): void
    {
        $composition = [
            ['type_id' => self::MONAZITE, 'quantity' => 300],
            ['type_id' => self::SHINING_MONAZITE, 'quantity' => 100],
        ];

        $stats = MoonOreHelper::getJackpotStatistics($composition);

        $this->assertTrue($stats['is_jackpot']);
        $this->assertSame(2, $stats['total_ore_types']);
        $this->assertSame(1, $stats['jackpot_ore_types']);
        $this->assertSame(400, $stats['total_quantity']);
        $this->assertSame(25.0, $stats['jackpot_quantity_percentage']);

        // A quarter of the chunk yields double.
        $this->assertEqualsWithDelta(1.25, MoonOreHelper::calculateJackpotMultiplier($composition), 0.0001);
        $this->assertCount(1, MoonOreHelper::getJackpotOresFromComposition($composition));
    }

    public function test_a_chunk_with_no_jackpot_ore_multiplies_by_one(): void
    {
        $this->assertSame(1.0, MoonOreHelper::calculateJackpotMultiplier([['type_id' => self::MONAZITE, 'quantity' => 10]]));
        $this->assertSame(1.0, MoonOreHelper::calculateJackpotMultiplier([]));
        $this->assertFalse(MoonOreHelper::getJackpotStatistics([])['is_jackpot']);
    }
}
