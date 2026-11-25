<?php

namespace MiningManager\Services\Ecosystem;

use MiningManager\Services\DiscordRoleResolver;

/**
 * Which of the plugins Mining Manager works with are on this server, and what
 * that leaves the features that need them. Help shows it, so an operator sees
 * what is missing before wondering why a feature is.
 *
 * Each plugin is found the way the feature that needs it finds it, so Help
 * never says a feature works when it does not.
 */
final class PluginStatus
{
    public const CRUCIAL = 'crucial';
    public const OPTIONAL = 'optional';

    /** The plugin that links the others. */
    public const HUB = 'manager-core';

    /**
     * The plugins Help lists. Crucial: full functionality needs it. Optional:
     * a feature needs it. ready is false while Mining Manager does not connect
     * to it yet. via names the plugin it is reached through, which then has to
     * be there too.
     */
    public const PLUGINS = [
        'manager-core' => ['name' => 'Manager Core', 'role' => self::CRUCIAL, 'ready' => true, 'via' => null],
        'structure-manager' => ['name' => 'Structure Manager', 'role' => self::OPTIONAL, 'ready' => true, 'via' => 'manager-core'],
        'seat-broadcast' => ['name' => 'SeAT Broadcast', 'role' => self::OPTIONAL, 'ready' => true, 'via' => null],
        'seat-connector' => ['name' => 'SeAT Connector', 'role' => self::OPTIONAL, 'ready' => true, 'via' => null],
    ];

    /**
     * Features that need another plugin. A group is met by any one plugin in
     * it. The simulator asks Structure Manager for fuel through a capability
     * only newer releases register, so it goes by that rather than by
     * Structure Manager being installed. Warlof's older Discord connector also
     * feeds the role picker, so it counts without being listed.
     */
    public const FEATURES = [
        'mc-pricing' => [['role' => self::CRUCIAL, 'any' => ['manager-core']]],
        'fast-poll' => [['role' => self::CRUCIAL, 'any' => ['manager-core']]],
        'mining-events' => [['role' => self::CRUCIAL, 'any' => ['manager-core']]],
        'structure-alerts' => [
            ['role' => self::CRUCIAL, 'any' => ['manager-core']],
            ['role' => self::OPTIONAL, 'any' => ['structure-manager']],
        ],
        'simulator-fuel' => [
            ['role' => self::CRUCIAL, 'any' => ['manager-core']],
            ['role' => self::OPTIONAL, 'any' => ['structure-manager-fuel']],
        ],
        'role-names' => [['role' => self::OPTIONAL, 'any' => ['seat-broadcast', 'seat-connector', 'warlof-discord']]],
    ];

    private const UNLISTED_NAMES = [
        'structure-manager-fuel' => 'Structure Manager 2.0.5 or newer',
        'warlof-discord' => 'Warlof Discord',
    ];

    public static function forHelp(): array
    {
        return self::resolve(self::detect());
    }

    /** @return array<string, bool> by plugin */
    public static function detect(): array
    {
        $managerCore = class_exists(\ManagerCore\ManagerCore::class);

        // The role picker finds its sources by their tables, so these do too.
        $providers = DiscordRoleResolver::detectAvailableProviders();

        return [
            'manager-core' => $managerCore,
            // The guard the threat alerts are registered behind.
            'structure-manager' => class_exists('StructureManager\\Helpers\\FuelCalculator'),
            'structure-manager-fuel' => $managerCore && self::bridgeHas('structure-manager', 'fuel.estimate'),
            'seat-broadcast' => in_array(DiscordRoleResolver::PROVIDER_DISCORD_ROLES_TABLE, $providers, true),
            'seat-connector' => in_array(DiscordRoleResolver::PROVIDER_SEAT_CONNECTOR, $providers, true),
            'warlof-discord' => in_array(DiscordRoleResolver::PROVIDER_WARLOF_DISCORD, $providers, true),
        ];
    }

    private static function bridgeHas(string $plugin, string $capability): bool
    {
        try {
            return app(\ManagerCore\Services\PluginBridge::class)->hasCapability($plugin, $capability);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * What Help shows for what is installed.
     *
     * @param array<string, bool> $installed by plugin
     */
    public static function resolve(array $installed, array $plugins = self::PLUGINS, array $features = self::FEATURES): array
    {
        $rows = [];
        foreach ($plugins as $key => $plugin) {
            $rows[$key] = $plugin + [
                'installed' => $installed[$key] ?? false,
                'badges' => self::badges($key, $plugins, $features, $installed),
            ];
        }

        $states = [];
        foreach ($features as $key => $needs) {
            $states[$key] = self::feature($needs, $plugins, $installed);
        }

        return [
            'hub' => isset($plugins[self::HUB]) ? self::hub($plugins[self::HUB], $installed[self::HUB] ?? false) : null,
            'plugins' => $rows,
            'features' => $states,
        ];
    }

    /** The Manager Core box stays red until this plugin connects and Manager Core is there. */
    private static function hub(array $plugin, bool $installed): array
    {
        if (!$plugin['ready']) {
            return ['state' => 'red', 'banner' => 'Coming soon', 'coming_soon' => true, 'installed' => $installed];
        }

        return $installed
            ? ['state' => 'on', 'banner' => null, 'coming_soon' => false, 'installed' => true]
            : ['state' => 'red', 'banner' => 'Not installed', 'coming_soon' => false, 'installed' => false];
    }

    /**
     * Red while a crucial plugin the feature needs is missing or not connected
     * yet, yellow while only an optional one is missing.
     */
    private static function feature(array $needs, array $plugins, array $installed): array
    {
        $missing = [self::CRUCIAL => [], self::OPTIONAL => []];
        $using = [];
        $comingSoon = false;

        foreach ($needs as $group) {
            $ready = array_filter($group['any'], fn (string $key) => $plugins[$key]['ready'] ?? true);
            if ($ready === []) {
                $comingSoon = true;
                continue;
            }

            $found = array_values(array_filter($ready, fn (string $key) => $installed[$key] ?? false));
            if ($found !== []) {
                $using[] = self::name($found[0], $plugins);
                continue;
            }

            $listed = array_filter($ready, fn (string $key) => isset($plugins[$key]));
            $missing[$group['role']][] = implode(' or ', array_map(fn (string $key) => self::name($key, $plugins), $listed ?: $ready));
        }

        $needed = implode(' and ', array_merge($missing[self::CRUCIAL], $missing[self::OPTIONAL]));
        $using = implode(' and ', $using);

        if ($comingSoon) {
            return ['state' => 'red', 'banner' => 'Coming soon', 'needs' => $needed, 'using' => $using];
        }
        if ($missing[self::CRUCIAL] !== []) {
            return ['state' => 'red', 'banner' => 'Plugin not installed', 'needs' => $needed, 'using' => $using];
        }
        if ($missing[self::OPTIONAL] !== []) {
            return ['state' => 'yellow', 'banner' => 'Plugin not installed', 'needs' => $needed, 'using' => $using];
        }

        return ['state' => 'on', 'banner' => null, 'needs' => '', 'using' => $using];
    }

    /**
     * The badges on a plugin's row. A missing crucial plugin pulses and is
     * recommended. A missing optional one is yellow, or grey when another
     * plugin already does its job, since nothing is lost without it. One
     * installed but cut off from the plugin it is reached through says which.
     */
    private static function badges(string $key, array $plugins, array $features, array $installed): array
    {
        $plugin = $plugins[$key];
        $has = $installed[$key] ?? false;

        if (!$plugin['ready']) {
            return [
                self::badge('Coming soon', 'danger', true),
                $has ? self::badge('Installed', 'success') : self::badge('Not installed', 'secondary'),
            ];
        }

        if (!$has) {
            if ($plugin['role'] === self::CRUCIAL) {
                return [self::badge('Not installed', 'danger', true), self::badge('Recommended', 'info')];
            }

            return [self::badge('Not installed', self::covered($key, $plugins, $features, $installed) ? 'secondary' : 'warning')];
        }

        $via = $plugin['via'];
        if ($via !== null && !($installed[$via] ?? false)) {
            return [self::badge('Installed', 'success'), self::badge('Needs ' . self::name($via, $plugins), 'danger')];
        }

        return [self::badge('Installed', 'success')];
    }

    /** Whether every feature this plugin could serve is already served by another one. */
    private static function covered(string $key, array $plugins, array $features, array $installed): bool
    {
        $serves = false;
        foreach ($features as $needs) {
            foreach ($needs as $group) {
                if (!in_array($key, $group['any'], true)) {
                    continue;
                }
                $serves = true;
                $others = array_filter(
                    $group['any'],
                    fn (string $other) => $other !== $key && ($plugins[$other]['ready'] ?? true) && ($installed[$other] ?? false)
                );
                if ($others === []) {
                    return false;
                }
            }
        }

        return $serves;
    }

    private static function badge(string $label, string $tone, bool $pulse = false): array
    {
        return ['label' => $label, 'tone' => $tone, 'pulse' => $pulse];
    }

    private static function name(string $key, array $plugins): string
    {
        return $plugins[$key]['name'] ?? self::UNLISTED_NAMES[$key] ?? $key;
    }
}
