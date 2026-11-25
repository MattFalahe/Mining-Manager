<?php

namespace MiningManager\Tests\Unit;

use MiningManager\Services\Ecosystem\PluginStatus;
use PHPUnit\Framework\TestCase;

class PluginStatusTest extends TestCase
{
    private const NONE = [
        'manager-core' => false,
        'structure-manager' => false,
        'structure-manager-fuel' => false,
        'seat-broadcast' => false,
        'seat-connector' => false,
        'warlof-discord' => false,
    ];

    private const MC_FEATURES = ['mc-pricing', 'fast-poll', 'mining-events'];

    private static function labels(array $badges): array
    {
        return array_map(fn (array $b) => $b['label'] . '/' . $b['tone'] . ($b['pulse'] ? '/pulse' : ''), $badges);
    }

    public function test_without_manager_core_everything_that_needs_it_is_red(): void
    {
        $status = PluginStatus::resolve(self::NONE);

        $this->assertSame(['state' => 'red', 'banner' => 'Not installed', 'coming_soon' => false, 'installed' => false], $status['hub']);
        $this->assertSame(['Not installed/danger/pulse', 'Recommended/info'], self::labels($status['plugins']['manager-core']['badges']));

        foreach (self::MC_FEATURES as $feature) {
            $this->assertSame('red', $status['features'][$feature]['state'], $feature);
            $this->assertSame('Plugin not installed', $status['features'][$feature]['banner'], $feature);
            $this->assertSame('Manager Core', $status['features'][$feature]['needs'], $feature);
        }

        $this->assertSame('red', $status['features']['structure-alerts']['state']);
        $this->assertSame('Manager Core and Structure Manager', $status['features']['structure-alerts']['needs']);
        $this->assertSame('red', $status['features']['simulator-fuel']['state']);
        $this->assertSame('Manager Core and Structure Manager 2.0.5 or newer', $status['features']['simulator-fuel']['needs']);
    }

    public function test_with_manager_core_alone_structure_manager_features_turn_yellow(): void
    {
        $status = PluginStatus::resolve(['manager-core' => true] + self::NONE);

        $this->assertSame('on', $status['hub']['state']);
        $this->assertNull($status['hub']['banner']);
        $this->assertSame(['Installed/success'], self::labels($status['plugins']['manager-core']['badges']));

        foreach (self::MC_FEATURES as $feature) {
            $this->assertSame(['state' => 'on', 'banner' => null, 'needs' => '', 'using' => 'Manager Core'], $status['features'][$feature], $feature);
        }

        $this->assertSame('yellow', $status['features']['structure-alerts']['state']);
        $this->assertSame('Structure Manager', $status['features']['structure-alerts']['needs']);
        $this->assertSame('Manager Core', $status['features']['structure-alerts']['using']);
        $this->assertSame(['Not installed/warning'], self::labels($status['plugins']['structure-manager']['badges']));
    }

    public function test_a_structure_manager_without_the_fuel_capability_leaves_only_the_simulator_yellow(): void
    {
        $status = PluginStatus::resolve(['manager-core' => true, 'structure-manager' => true] + self::NONE);

        $this->assertSame('on', $status['features']['structure-alerts']['state']);
        $this->assertSame('Manager Core and Structure Manager', $status['features']['structure-alerts']['using']);
        $this->assertSame('yellow', $status['features']['simulator-fuel']['state']);
        $this->assertSame('Structure Manager 2.0.5 or newer', $status['features']['simulator-fuel']['needs']);
        $this->assertSame(['Installed/success'], self::labels($status['plugins']['structure-manager']['badges']));

        $status = PluginStatus::resolve(['manager-core' => true, 'structure-manager' => true, 'structure-manager-fuel' => true] + self::NONE);
        $this->assertSame('on', $status['features']['simulator-fuel']['state']);
        $this->assertSame('Manager Core and Structure Manager 2.0.5 or newer', $status['features']['simulator-fuel']['using']);
    }

    public function test_structure_manager_without_manager_core_says_what_it_needs(): void
    {
        $status = PluginStatus::resolve(['structure-manager' => true] + self::NONE);

        $this->assertSame(['Installed/success', 'Needs Manager Core/danger'], self::labels($status['plugins']['structure-manager']['badges']));
        $this->assertSame('red', $status['features']['structure-alerts']['state']);
        $this->assertSame('Manager Core', $status['features']['structure-alerts']['needs']);
    }

    public function test_role_names_need_one_discord_plugin_and_the_other_goes_grey(): void
    {
        $none = PluginStatus::resolve(self::NONE);
        $this->assertSame('yellow', $none['features']['role-names']['state']);
        $this->assertSame('SeAT Broadcast or SeAT Connector', $none['features']['role-names']['needs']);
        $this->assertSame(['Not installed/warning'], self::labels($none['plugins']['seat-broadcast']['badges']));
        $this->assertSame(['Not installed/warning'], self::labels($none['plugins']['seat-connector']['badges']));

        foreach (['seat-broadcast' => 'SeAT Broadcast', 'seat-connector' => 'SeAT Connector', 'warlof-discord' => 'Warlof Discord'] as $key => $name) {
            $status = PluginStatus::resolve([$key => true] + self::NONE);
            $this->assertSame('on', $status['features']['role-names']['state'], $key);
            $this->assertSame($name, $status['features']['role-names']['using'], $key);

            foreach (['seat-broadcast', 'seat-connector'] as $row) {
                $expected = $row === $key ? ['Installed/success'] : ['Not installed/secondary'];
                $this->assertSame($expected, self::labels($status['plugins'][$row]['badges']), "$key installed, $row row");
            }
        }
    }

    public function test_every_plugin_a_feature_needs_has_a_name(): void
    {
        foreach (PluginStatus::resolve(self::NONE)['features'] as $feature => $state) {
            $this->assertDoesNotMatchRegularExpression('/[a-z]+-[a-z]+/', $state['needs'], $feature);
        }
    }

    public function test_the_rules_use_exactly_the_keys_detect_reports(): void
    {
        $used = array_keys(PluginStatus::PLUGINS);
        foreach (PluginStatus::FEATURES as $needs) {
            foreach ($needs as $group) {
                $used = array_merge($used, $group['any']);
            }
        }

        $this->assertEqualsCanonicalizing(array_keys(self::NONE), array_values(array_unique($used)));
    }
}
