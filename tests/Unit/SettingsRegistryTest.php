<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Configuration\SettingsRegistry;
use PHPUnit\Framework\TestCase;

class SettingsRegistryTest extends TestCase
{
    public function test_what_a_tax_profile_decides_is_kept_per_profile(): void
    {
        foreach (['tax_rates.moon_ore.r4', 'tax_rates.moon_ore.r64', 'tax_rates.ore', 'tax_selector.all_moon_ore', 'tax_rates.tax_calculation_period', 'tax_rates.tax_code_prefix'] as $key) {
            $this->assertSame(SettingsRegistry::PROFILE, SettingsRegistry::scope($key), $key);
        }
    }

    public function test_setup_applies_to_the_whole_install(): void
    {
        $this->assertSame(SettingsRegistry::INSTALL, SettingsRegistry::scope('general.moon_owner_corporation_id'));
        $this->assertSame(SettingsRegistry::INSTALL, SettingsRegistry::scope('general.configured_corporations'));
    }

    public function test_a_key_the_plugin_does_not_keep_has_no_scope(): void
    {
        $this->assertNull(SettingsRegistry::scope('general.nothing_of_the_sort'));
    }

    public function test_the_credentials_an_export_locks_are_install_settings(): void
    {
        $this->assertNotEmpty(SettingsRegistry::CREDENTIALS);
        foreach (SettingsRegistry::CREDENTIALS as $key) {
            $this->assertSame(SettingsRegistry::INSTALL, SettingsRegistry::scope($key), $key);
            $this->assertTrue(SettingsRegistry::isCredential($key), $key);
        }

        $this->assertFalse(SettingsRegistry::isCredential('general.moon_owner_corporation_id'));
    }

    public function test_every_key_has_exactly_one_scope(): void
    {
        $scoped = [];
        foreach ([SettingsRegistry::INSTALL, SettingsRegistry::PROFILE, SettingsRegistry::STATE, SettingsRegistry::LEGACY] as $scope) {
            $scoped = array_merge($scoped, SettingsRegistry::keys($scope));
        }

        $this->assertEqualsCanonicalizing(SettingsRegistry::keys(), $scoped);
        $this->assertSame(count($scoped), count(array_unique($scoped)));
    }
}
