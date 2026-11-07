<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Moon\StructureNotificationText;
use PHPUnit\Framework\TestCase;

class StructureNotificationTextTest extends TestCase
{
    private const STRUCTURE = 1032717532381;

    public function test_a_plain_structure_id_is_found(): void
    {
        $this->assertTrue(StructureNotificationText::mentions("moonID: 40031169\nstructureID: 1032717532381\n", self::STRUCTURE));
    }

    /** StructureDestroyed and StructureUnanchoring write the id this way, so a match on the plain form misses them all. */
    public function test_an_id_written_with_a_yaml_anchor_is_found(): void
    {
        $text = "structureID: &id001 1032717532381\nstructureShowInfoData:\n- showinfo\n- 35835\n- *id001\n";

        $this->assertTrue(StructureNotificationText::mentions($text, self::STRUCTURE));
    }

    public function test_a_longer_id_starting_with_the_same_digits_is_another_structure(): void
    {
        $this->assertFalse(StructureNotificationText::mentions('structureID: 10327175323819', self::STRUCTURE));
        $this->assertFalse(StructureNotificationText::mentions('structureID: 1032717532382', self::STRUCTURE));
    }

    public function test_the_same_digits_under_another_name_are_not_this_structure(): void
    {
        $this->assertFalse(StructureNotificationText::mentions('moonID: 1032717532381', self::STRUCTURE));
    }
}
