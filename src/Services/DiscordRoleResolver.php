<?php

namespace MiningManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Detects Discord role providers on a SeAT install and surfaces role lists
 * for Mining Manager's per-notification-type role-id pickers.
 *
 * Ported verbatim from Structure Manager's DiscordRoleResolver (only the
 * namespace + log prefix differ). The pattern + behaviour is documented
 * in memory/feedback_plugin_role_picker_pattern.md as the standard for
 * every plugin in the ecosystem. When MC eventually consolidates this,
 * both SM and MM will import from \ManagerCore\Services\DiscordRoleResolver
 * via the PluginBridge `discord.listRoles` capability and these per-plugin
 * copies will be removed.
 *
 * Detection is TABLE-based (not class_exists) because SeAT plugins ship
 * differently (composer, tarball, manual), and a package may be installed
 * without all its classes being autoloadable under our namespace resolution.
 * If the table is present, its roles contribute to the picker.
 *
 * Known providers and their ownership (confirmed on real installs):
 *   - discord_roles table            → mattfalahe/seat-discord-pings
 *     Display name: SeAT Broadcast. Curated registry with mention_format,
 *     color, is_active. Richest UX; treat as the "curated" source.
 *
 *   - seat_connector_sets            → warlof/seat-connector (framework)
 *     + warlof/seat-discord-connector (Discord driver)
 *     Rows with connector_type='discord' are Discord roles synced from
 *     the guild. No color, but covers the full live guild.
 *
 *   - warlof_discord_connector_roles → older warlof-only install (legacy)
 *
 * UNION BEHAVIOR: When multiple providers are installed (common on larger
 * SeAT installs), we query all of them and merge. Roles that appear in
 * multiple sources are DEDUPED by Discord snowflake, keeping the entry
 * from the richest source (curated > synced > legacy) but recording
 * every source the role appeared in for UI display.
 *
 * Returns [] from listRoles() when no provider is detected, so the UI falls
 * back to a plain text input.
 */
class DiscordRoleResolver
{
    public const PROVIDER_DISCORD_ROLES_TABLE = 'discord-roles-table';
    public const PROVIDER_SEAT_CONNECTOR      = 'seat-connector';
    public const PROVIDER_WARLOF_DISCORD      = 'warlof-discord';

    /**
     * Source priority when deduping: richer first.
     * Lower index wins when the same Discord snowflake appears in multiple sources.
     */
    private const SOURCE_PRIORITY = [
        self::PROVIDER_DISCORD_ROLES_TABLE, // curated, has color, has mention_format
        self::PROVIDER_SEAT_CONNECTOR,      // synced from live guild
        self::PROVIDER_WARLOF_DISCORD,      // legacy
    ];

    /**
     * Return every provider whose table is present. Order matches SOURCE_PRIORITY.
     *
     * @return array<int, string>
     */
    public static function detectAvailableProviders(): array
    {
        $providers = [];

        if (Schema::hasTable('discord_roles') && Schema::hasColumn('discord_roles', 'role_id')) {
            $providers[] = self::PROVIDER_DISCORD_ROLES_TABLE;
        }

        if (Schema::hasTable('seat_connector_sets')) {
            $providers[] = self::PROVIDER_SEAT_CONNECTOR;
        }

        foreach (['warlof_discord_connector_roles', 'discord_connector_roles'] as $t) {
            if (Schema::hasTable($t)) {
                $providers[] = self::PROVIDER_WARLOF_DISCORD;
                break;
            }
        }

        return $providers;
    }

    /**
     * Backward-compat: return the top-priority provider, or null if none.
     */
    public static function detectProvider(): ?string
    {
        $all = self::detectAvailableProviders();
        return $all[0] ?? null;
    }

    public static function isAvailable(): bool
    {
        return !empty(self::detectAvailableProviders());
    }

    /**
     * Return Discord roles from ALL installed providers, merged and deduped.
     *
     * Shape per role:
     *   [
     *     'id'             => '1227722401236652123',
     *     'name'           => 'Corp Member',
     *     'mention_format' => '<@&1227722401236652123>',
     *     'color'          => '#2ecc71' | null,
     *     'source'         => 'discord-roles-table',        // primary (richest) source
     *     'sources'        => ['discord-roles-table', 'seat-connector'], // every source
     *   ]
     */
    public static function listRoles(): array
    {
        $providers = self::detectAvailableProviders();
        if (empty($providers)) {
            return [];
        }

        $merged = [];

        foreach ($providers as $provider) {
            try {
                $rows = match ($provider) {
                    self::PROVIDER_DISCORD_ROLES_TABLE => self::rolesFromDiscordRolesTable(),
                    self::PROVIDER_SEAT_CONNECTOR      => self::rolesFromSeatConnector(),
                    self::PROVIDER_WARLOF_DISCORD      => self::rolesFromWarlof(),
                    default                            => [],
                };
            } catch (\Throwable $e) {
                Log::warning('[Mining Manager] DiscordRoleResolver: failed listing roles from ' . $provider . ': ' . $e->getMessage());
                continue;
            }

            foreach ($rows as $row) {
                $id = $row['id'];
                if (!$id) {
                    continue;
                }

                if (!isset($merged[$id])) {
                    $row['source']  = $provider;
                    $row['sources'] = [$provider];
                    $merged[$id] = $row;
                } else {
                    $merged[$id]['sources'][] = $provider;
                    if (empty($merged[$id]['color']) && !empty($row['color'])) {
                        $merged[$id]['color'] = $row['color'];
                    }
                }
            }
        }

        $result = array_values($merged);
        usort($result, fn($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        return $result;
    }

    /**
     * Aggregate label for UI banner ("SeAT Broadcast + SeAT Connector").
     */
    public static function providerLabel(): string
    {
        $providers = self::detectAvailableProviders();
        if (empty($providers)) {
            return 'Manual input only';
        }

        $labels = array_map(fn($p) => self::providerShortLabel($p), $providers);
        return implode(' + ', $labels);
    }

    /**
     * Short human label for a single provider (used in source badges).
     *
     * Display names follow the project's canonical naming convention from
     * feedback_plugin_naming_conventions: the seat-discord-pings package is
     * displayed as "SeAT Broadcast" in user-facing UI. The internal table
     * identifier (PROVIDER_DISCORD_ROLES_TABLE) and underlying table name
     * (discord_roles) stay as-is so we don't churn through unrelated layers.
     */
    public static function providerShortLabel(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_DISCORD_ROLES_TABLE => 'SeAT Broadcast (curated)',
            self::PROVIDER_SEAT_CONNECTOR      => 'SeAT Connector (warlof)',
            self::PROVIDER_WARLOF_DISCORD      => 'Warlof Discord (legacy)',
            default                            => $provider,
        };
    }

    // ---- Provider-specific queries ----

    /**
     * mattfalahe/seat-discord-pings — discord_roles table.
     */
    private static function rolesFromDiscordRolesTable(): array
    {
        $rows = DB::table('discord_roles')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role_id', 'mention_format', 'color']);

        return $rows->map(function ($r) {
            return [
                'id'             => (string) $r->role_id,
                'name'           => (string) $r->name,
                'mention_format' => (string) ($r->mention_format ?: '<@&' . $r->role_id . '>'),
                'color'          => $r->color ?: null,
            ];
        })->all();
    }

    /**
     * warlof/seat-connector — Discord rows from seat_connector_sets.
     */
    private static function rolesFromSeatConnector(): array
    {
        $rows = DB::table('seat_connector_sets')
            ->where('connector_type', 'discord')
            ->orderBy('name')
            ->get(['id', 'connector_id', 'name']);

        return $rows->map(function ($r) {
            return [
                'id'             => (string) $r->connector_id,
                'name'           => (string) $r->name,
                'mention_format' => '<@&' . $r->connector_id . '>',
                'color'          => null,
            ];
        })->all();
    }

    /**
     * Legacy warlof/seat-discord-connector tables.
     * Best-effort query — adjusts to whatever schema exists.
     */
    private static function rolesFromWarlof(): array
    {
        foreach (['warlof_discord_connector_roles', 'discord_connector_roles'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $idCol = in_array('discord_id', $columns) ? 'discord_id' : 'id';
            $nameCol = in_array('name', $columns) ? 'name' : $idCol;

            $rows = DB::table($table)
                ->orderBy($nameCol)
                ->get([$idCol . ' as role_id', $nameCol . ' as name']);

            return $rows->map(function ($r) {
                return [
                    'id'             => (string) $r->role_id,
                    'name'           => (string) $r->name,
                    'mention_format' => '<@&' . $r->role_id . '>',
                    'color'          => null,
                ];
            })->all();
        }

        return [];
    }
}
