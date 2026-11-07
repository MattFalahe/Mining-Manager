<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\OreClassifier;
use MiningManager\Services\TypeIdRegistry;
use PHPUnit\Framework\TestCase;

class TypeIdRegistryTest extends TestCase
{
    public function test_each_rarity_has_its_four_ores_in_three_grades(): void
    {
        $map = TypeIdRegistry::getMoonOreRarityMap();

        $this->assertSame(['R4', 'R8', 'R16', 'R32', 'R64'], array_keys($map));
        foreach ($map as $rarity => $typeIds) {
            $this->assertCount(12, array_unique($typeIds), $rarity);
        }
    }

    public function test_an_ore_has_one_rarity_and_it_is_the_one_the_map_says(): void
    {
        $map = TypeIdRegistry::getMoonOreRarityMap();

        $this->assertCount(60, array_unique(array_merge(...array_values($map))));
        foreach ($map as $rarity => $typeIds) {
            $this->assertSame($typeIds, TypeIdRegistry::getMoonOresByRarity($rarity));
            foreach ($typeIds as $typeId) {
                $this->assertSame(strtolower($rarity), TypeIdRegistry::getMoonOreRarity($typeId));
            }
        }

        $this->assertNull(TypeIdRegistry::getMoonOreRarity(1230));
        $this->assertSame([], TypeIdRegistry::getMoonOresByRarity('R128'));
    }

    public function test_the_rarity_map_and_the_moon_ore_list_agree(): void
    {
        $this->assertEqualsCanonicalizing(
            array_merge(...array_values(TypeIdRegistry::getMoonOreRarityMap())),
            TypeIdRegistry::getAllMoonOres()
        );
    }

    /** A jackpot is found by its ore, so an id in this list that is not moon ore would never be billed as moon ore. */
    public function test_jackpot_ore_is_moon_ore(): void
    {
        $this->assertSame([], array_values(array_diff(
            TypeIdRegistry::getAllJackpotOres(),
            TypeIdRegistry::getAllMoonOres(),
            TypeIdRegistry::getAllCompressedMoonOres()
        )));
    }

    /** One id in two families would be charged at whichever rate is asked about first. */
    public function test_no_ore_is_in_two_families(): void
    {
        $families = [
            'moon' => TypeIdRegistry::getAllMoonOres(),
            'compressed moon' => TypeIdRegistry::getAllCompressedMoonOres(),
            'ice' => TypeIdRegistry::getAllIce(),
            'gas' => TypeIdRegistry::getAllGas(),
            'abyssal' => OreClassifier::abyssalTypeIds(),
            'triglavian' => TypeIdRegistry::getAllTriglavianOres(),
            'regular' => TypeIdRegistry::getAllRegularOres(),
            'left out' => OreClassifier::ignoredTypeIds(),
        ];

        foreach ($families as $name => $typeIds) {
            foreach ($families as $other => $otherIds) {
                if ($name < $other) {
                    $this->assertSame([], array_values(array_intersect($typeIds, $otherIds)), "$name and $other");
                }
            }
        }
    }

    public function test_every_family_is_registered(): void
    {
        $families = array_merge(
            TypeIdRegistry::getAllMoonOres(),
            TypeIdRegistry::getAllIce(),
            TypeIdRegistry::getAllGas(),
            TypeIdRegistry::getAllRegularOres(),
            TypeIdRegistry::getAllTriglavianOres(),
            OreClassifier::abyssalTypeIds(),
            OreClassifier::ignoredTypeIds()
        );

        foreach ($families as $typeId) {
            $this->assertTrue(TypeIdRegistry::isRegistered($typeId), (string) $typeId);
        }
    }
}
