<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\OreClassifier;
use MiningManager\Services\TypeIdRegistry;
use PHPUnit\Framework\TestCase;

class OreClassifierTest extends TestCase
{
    public function test_moon_ore_is_filed_under_its_rarity_and_taxed_as_moon_ore(): void
    {
        $expected = [
            45490 => 'moon_r4',  // Zeolites
            46280 => 'moon_r4',  // Brimful Zeolites
            45495 => 'moon_r8',  // Euxenite
            45511 => 'moon_r64', // Monazite
            46314 => 'moon_r64', // Bountiful Monazite
        ];

        foreach ($expected as $typeId => $category) {
            $this->assertSame($category, OreClassifier::category($typeId), (string) $typeId);
            $this->assertSame('moon', OreClassifier::taxCategory($typeId), (string) $typeId);
        }
    }

    /** A moon ore with no rarity has no rate to be charged at, so none of them may lack one. */
    public function test_every_moon_ore_a_refinery_can_report_has_a_rarity(): void
    {
        foreach (TypeIdRegistry::getAllMoonOres() as $typeId) {
            $this->assertNotSame('moon', OreClassifier::category($typeId), (string) $typeId);
        }
    }

    public function test_the_other_kinds_of_ore_each_have_their_own_rate(): void
    {
        $this->assertSame(['ice', 'ice'], [OreClassifier::category(16262), OreClassifier::taxCategory(16262)]);
        $this->assertSame(['gas', 'gas'], [OreClassifier::category(30370), OreClassifier::taxCategory(30370)]);
        $this->assertSame(['abyssal', 'abyssal_ore'], [OreClassifier::category(52316), OreClassifier::taxCategory(52316)]);
        $this->assertSame(['triglavian', 'triglavian_ore'], [OreClassifier::category(28617), OreClassifier::taxCategory(28617)]);
        $this->assertSame(['ore', 'ore'], [OreClassifier::category(1230), OreClassifier::taxCategory(1230)]);
    }

    /** They sound abyssal. Filing them there would quietly stop charging an install that taxes ore but not abyssal ore. */
    public function test_the_newer_asteroid_families_are_regular_ore(): void
    {
        foreach (array_merge(TypeIdRegistry::getAllDeepSpaceSurveyOres(), TypeIdRegistry::getAllOreProspectingArrayOres()) as $typeId) {
            $this->assertSame('ore', OreClassifier::category($typeId), (string) $typeId);
            $this->assertFalse(OreClassifier::isAbyssal($typeId), (string) $typeId);
        }
    }

    /** The imports only read recent days, so mining skipped for being unknown could never be brought back. */
    public function test_a_type_nobody_has_heard_of_is_kept_as_regular_ore(): void
    {
        $this->assertFalse(TypeIdRegistry::isRegistered(999999999));
        $this->assertFalse(OreClassifier::isIgnored(999999999));
        $this->assertSame('ore', OreClassifier::category(999999999));
    }

    public function test_event_ore_mutanite_and_zuthrine_are_left_out(): void
    {
        foreach ([77118, 77418, 77419, 77420, 77421, 77524, 28626] as $typeId) {
            $this->assertTrue(OreClassifier::isIgnored($typeId), (string) $typeId);
        }

        foreach (TypeIdRegistry::EVENT_ORES as $typeId) {
            $this->assertTrue(OreClassifier::isIgnored($typeId), (string) $typeId);
        }
    }

    public function test_nothing_with_a_rate_of_its_own_is_left_out(): void
    {
        $taxed = array_merge(
            TypeIdRegistry::getAllMoonOres(),
            TypeIdRegistry::getAllIce(),
            TypeIdRegistry::getAllGas(),
            TypeIdRegistry::getAllRegularOres(),
            TypeIdRegistry::getAllTriglavianOres(),
            OreClassifier::abyssalTypeIds()
        );

        $this->assertSame([], array_values(array_intersect($taxed, OreClassifier::ignoredTypeIds())));
    }
}
