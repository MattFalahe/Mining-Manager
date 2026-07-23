{{--
    Notification Routing Map — read-only delivery snapshot.

    Rendered as its own Settings tab (#routing-map-section). For every
    notification type it shows each webhook the type fires through and the
    Discord role that will actually be mentioned, resolved through the same
    two-tier precedence WebhookDispatchTrait::getDiscordRoleMention() applies
    at delivery time.

    MM's model differs from SM:
      - No bindings table — each webhook carries one boolean `notify_<type>`
        column per notification type
      - Per-type role lives in global `notifications.type_settings[type][role_id]`
        (set in Settings -> Notifications)
      - Webhook-level fallback role lives on `webhook.discord_role_id` (set
        in Settings -> Webhooks)
      - Two-tier precedence: L1 per-type role, L2 webhook legacy role
      - tax_invoice is hard-blocked from role pings (batch protection in trait)

    Required variables (inherited from the settings view scope):
      $webhooks                 — collection of WebhookConfiguration
      $notificationSettings     — output of SettingsManagerService::getNotificationSettings
      $roleProviderAvailable    — bool (consumed by _role_pill partial)

    Locally computed inside this partial:
      $mmRouteCategories        — definitions of every category + type
      $mmRoleLookup             — DiscordRoleResolver::roleLookupMap result
      $mmManagerCoreAvailable   — flags cross-plugin types that won't fire
      $mmStructureManagerAvail  — flags cross-plugin types that won't fire

    Blade gotcha: do NOT place an
    inline @php(...) directive immediately before this @php block, and never
    write the literal tokens @php or @endphp inside a comment — Blade's
    storePhpBlocks regex is non-greedy and will corrupt the compiled view.
--}}
<style>
    .mm-routing-map .routing-map-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin: 0.8rem 0 1.1rem;
    }
    .mm-routing-map .routing-stat {
        background: #1e222b;
        border: 1px solid #3a4049;
        border-radius: 6px;
        padding: 0.45rem 0.8rem;
        font-size: 0.8rem;
        color: #c2c7d0;
    }
    .mm-routing-map .routing-stat strong {
        color: #fff;
        font-size: 1.05rem;
        margin-right: 4px;
    }
    .mm-routing-map .routing-stat.warn {
        background: #3a2e16;
        border-color: #6b5424;
        color: #d4c69a;
    }
    .mm-routing-map .routing-stat.warn strong { color: #ffd96a; }
    .mm-routing-map .routing-ns-label {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #8b95a5;
        font-weight: 600;
        margin: 1.1rem 0 0.4rem;
    }
    .mm-routing-map .routing-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.83rem;
        margin-bottom: 0.4rem;
    }
    .mm-routing-map .routing-table th {
        text-align: left;
        color: #8b95a5;
        font-weight: 500;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.35rem 0.6rem;
        border-bottom: 1px solid #3a4049;
    }
    .mm-routing-map .routing-table td {
        padding: 0.5rem 0.6rem;
        border-bottom: 1px solid #2a2f3a;
        vertical-align: top;
    }
    .mm-routing-map .routing-table tr:last-child td { border-bottom: none; }
    .mm-routing-map .routing-cat-cell {
        background: #20242e;
        border-right: 1px solid #313845;
        min-width: 200px;
    }
    .mm-routing-map .routing-cat-name { color: #fff; font-weight: 600; }
    .mm-routing-map .routing-cat-key {
        font-size: 0.69rem;
        color: #666c76;
        font-family: 'Courier New', monospace;
        margin-top: 2px;
    }
    .mm-routing-map .routing-row-disabled { opacity: 0.55; }
    .mm-routing-map .routing-dest { color: #e2e8f0; }
    .mm-routing-map .routing-arrow { color: #667eea; margin-right: 5px; }
    .mm-routing-map .routing-via {
        display: inline-block;
        font-size: 0.66rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        font-weight: 600;
        padding: 1px 6px;
        border-radius: 8px;
        margin-left: 6px;
        white-space: nowrap;
    }
    .mm-routing-map .routing-via.via-type    { background: #4338ca; color: #e0e7ff; }
    .mm-routing-map .routing-via.via-webhook { background: #52616b; color: #fff; }
    .mm-routing-map .routing-via.via-blocked { background: #6b2222; color: #fde2e2; }
    .mm-routing-map .routing-none { color: #666c76; font-style: italic; }
    .mm-routing-map .routing-unrouted { color: #d4c69a; }
    .mm-routing-map .routing-status { font-size: 0.72rem; color: #8b95a5; margin-top: 2px; }
    .mm-routing-map .routing-status .off { color: #e0683c; }
    .mm-routing-map .routing-empty {
        color: #8b95a5;
        font-style: italic;
        padding: 0.6rem 0;
    }

    /* Resolved-role pill styling (shared with the SM pattern, prefixed mm-). */
    .mm-role-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #1c2230;
        border: 1px solid #3a4049;
        border-radius: 10px;
        padding: 2px 8px 2px 6px;
        font-size: 0.78rem;
        color: #e2e8f0;
    }
    .mm-role-pill .role-color-dot {
        display: inline-block;
        width: 9px;
        height: 9px;
        border-radius: 50%;
        border: 1px solid rgba(255, 255, 255, 0.18);
    }
    .mm-role-pill.is-user    { background: #1a2436; border-color: #2b5a8a; color: #cdd9eb; }
    .mm-role-pill.is-unknown { background: #2e1e1e; border-color: #5a3a3a; color: #f1c0c0; }
    .mm-role-none {
        font-size: 0.78rem;
        font-style: italic;
        color: #666c76;
    }
</style>

@php
    // Manager Core + Structure Manager availability — these flag cross-plugin
    // notification types that won't fire when companion plugins are missing.
    // The two extraction_* types ride on MC's EventBus subscriber and SM's
    // payload contract, so without either plugin they remain dormant even
    // when toggled on and bound to a webhook.
    $mmManagerCoreAvailable      = class_exists(\ManagerCore\Services\EventBus::class);
    $mmStructureManagerAvailable = class_exists(\StructureManager\Helpers\FuelCalculator::class);

    // Snowflake lookup → role name + colour for the resolved pill display.
    $mmRoleLookup = \MiningManager\Services\DiscordRoleResolver::roleLookupMap();

    // Category + type definitions — kept in lockstep with the same shape used
    // in notifications.blade.php so the operator sees the same labels here.
    // (No corresponding settings columns means no entry in this map.)
    $mmRouteCategories = [
        'tax' => [
            'label' => 'Tax notifications',
            'icon'  => 'fas fa-coins',
            'types' => [
                'tax_generated'    => ['label' => 'Mining Taxes Summary',     'col' => 'notify_tax_generated'],
                'tax_announcement' => ['label' => 'New Invoices Announcement','col' => 'notify_tax_announcement'],
                'tax_reminder'     => ['label' => 'Tax Payment Reminder',     'col' => 'notify_tax_reminder'],
                'tax_invoice'      => ['label' => 'Tax Invoice Created',      'col' => 'notify_tax_invoice', 'role_blocked' => true],
                'tax_overdue'      => ['label' => 'Tax Payment Overdue',      'col' => 'notify_tax_overdue'],
            ],
        ],
        'event' => [
            'label' => 'Event notifications',
            'icon'  => 'fas fa-calendar',
            'types' => [
                'event_created'   => ['label' => 'Mining Event Created',   'col' => 'notify_event_created'],
                'event_started'   => ['label' => 'Mining Event Started',   'col' => 'notify_event_started'],
                'event_completed' => ['label' => 'Mining Event Completed', 'col' => 'notify_event_completed'],
            ],
        ],
        'moon' => [
            'label' => 'Moon notifications',
            'icon'  => 'fas fa-moon',
            'types' => [
                // moon_ready settings key maps to notify_moon_arrival column
                // (legacy naming preserved per the dispatcher's match() in
                // WebhookDispatchTrait::getDiscordRoleMention).
                'moon_ready'          => ['label' => 'Moon Chunk Ready',                'col' => 'notify_moon_arrival'],
                'jackpot_detected'    => ['label' => 'Jackpot Detected',                'col' => 'notify_jackpot_detected'],
                'moon_chunk_unstable' => ['label' => 'Moon Chunk Unstable',             'col' => 'notify_moon_chunk_unstable'],
                'extraction_started'  => ['label' => 'Extraction Started',              'col' => 'notify_extraction_started'],
                'next_extraction_planned' => ['label' => 'Next Extraction Planned',     'col' => 'notify_next_extraction_planned'],
                'schedule_mismatch'   => ['label' => 'Moon Scheduled Off-Plan',        'col' => 'notify_schedule_mismatch'],
                'extraction_at_risk'  => ['label' => 'Extraction at Risk (MC+SM)',      'col' => 'notify_extraction_at_risk',  'requires_mc' => true, 'requires_sm' => true],
                'extraction_lost'     => ['label' => 'Extraction Lost (MC+SM)',         'col' => 'notify_extraction_lost',     'requires_mc' => true, 'requires_sm' => true],
                'metenox_cargo_full'  => ['label' => 'Metenox Cargo Bay Full',          'col' => 'notify_metenox_cargo_full'],
            ],
        ],
        'theft' => [
            'label' => 'Theft detection',
            'icon'  => 'fas fa-user-secret',
            'types' => [
                'theft_detected'    => ['label' => 'Theft Detected',           'col' => 'notify_theft_detected'],
                'critical_theft'    => ['label' => 'Critical Theft',           'col' => 'notify_critical_theft'],
                'active_theft'      => ['label' => 'Active Theft in Progress', 'col' => 'notify_active_theft'],
                'incident_resolved' => ['label' => 'Incident Resolved',        'col' => 'notify_incident_resolved'],
            ],
        ],
        'report' => [
            'label' => 'Reports',
            'icon'  => 'fas fa-chart-bar',
            'types' => [
                'report_generated' => ['label' => 'Report Generated', 'col' => 'notify_report_generated'],
            ],
        ],
    ];

    // Quick handles into the data the settings panel already passes through.
    $mmEnabledTypes = $notificationSettings['enabled_types'] ?? [];
    $mmTypeSettings = $notificationSettings['type_settings'] ?? [];

    // Summary tallies. "Delivering" = enabled globally, has at least one
    // webhook with the notify_* column ON and that webhook is itself enabled,
    // and is NOT blocked by a missing companion plugin. "Silent" = enabled
    // globally but no live destination.
    $mmRouteStatTotal      = 0;
    $mmRouteStatEnabled    = 0;
    $mmRouteStatDelivering = 0;
    $mmRouteStatSilent     = 0;
    $mmRoutingData         = [];

    foreach ($mmRouteCategories as $catKey => $catDef) {
        $catRows = [];
        foreach ($catDef['types'] as $typeKey => $typeDef) {
            $mmRouteStatTotal++;

            $globalEnabled = (bool) ($mmEnabledTypes[$typeKey] ?? true);
            if ($globalEnabled) {
                $mmRouteStatEnabled++;
            }

            $requiresMc = !empty($typeDef['requires_mc']);
            $requiresSm = !empty($typeDef['requires_sm']);
            $mcBlocked  = $requiresMc && !$mmManagerCoreAvailable;
            $smBlocked  = $requiresSm && !$mmStructureManagerAvailable;
            $crossBlocked = $mcBlocked || $smBlocked;

            // Resolve the per-type role under the dispatcher's rules:
            // - tax_invoice (and any 'role_blocked') is hard-blocked from
            //   pings regardless of stored values
            // - ping_role OFF (per-type) → no role ping at all
            // - ping_role ON + per-type role_id set → L1 per-type role
            // - ping_role ON + no per-type role + webhook.discord_role_id → L2 webhook role
            $ts        = $mmTypeSettings[$typeKey] ?? [];
            $pingRole  = (bool) ($ts['ping_role'] ?? false);
            $typeRoleId = $ts['role_id'] ?? null;
            $hardBlocked = !empty($typeDef['role_blocked']);

            // Webhooks that subscribe to this type via their notify_<col> flag.
            $subscribers = $webhooks->filter(function ($wh) use ($typeDef) {
                $col = $typeDef['col'] ?? null;
                if (!$col) {
                    return false;
                }
                return (bool) ($wh->{$col} ?? false);
            });

            // Per-destination resolution.
            $dests = [];
            foreach ($subscribers as $wh) {
                $effRole = null;
                $via     = 'none';

                if ($hardBlocked) {
                    $via = 'blocked';
                } elseif ($pingRole) {
                    if (!empty($typeRoleId)) {
                        $effRole = "<@&{$typeRoleId}>";
                        $via     = 'type';
                    } elseif (!empty($wh->discord_role_id)) {
                        $effRole = "<@&{$wh->discord_role_id}>";
                        $via     = 'webhook';
                    }
                } else {
                    $via = 'none';
                }

                $dests[] = [
                    'webhook' => $wh,
                    'via'     => $via,
                    'role'    => \MiningManager\Services\DiscordRoleResolver::describeRoleMention($effRole, $mmRoleLookup),
                    // Live = the type is reachable for this destination:
                    // global enabled + webhook enabled + not cross-plugin blocked.
                    // Per-channel-type filters (Discord vs Slack vs EVE Mail)
                    // are applied lower in the dispatcher and don't affect
                    // routing-map reachability.
                    'live'    => $globalEnabled && (bool) ($wh->is_enabled ?? false) && !$crossBlocked,
                ];
            }

            $liveCount = collect($dests)->where('live', true)->count();
            if ($globalEnabled) {
                if ($liveCount > 0) {
                    $mmRouteStatDelivering++;
                } else {
                    $mmRouteStatSilent++;
                }
            }

            $catRows[] = [
                'typeKey'      => $typeKey,
                'typeDef'      => $typeDef,
                'globalEnabled'=> $globalEnabled,
                'dests'        => $dests,
                'mcBlocked'    => $mcBlocked,
                'smBlocked'    => $smBlocked,
                'crossBlocked' => $crossBlocked,
                'hardBlocked'  => $hardBlocked,
                'pingRole'     => $pingRole,
            ];
        }
        if (!empty($catRows)) {
            $mmRoutingData[$catKey] = ['def' => $catDef, 'rows' => $catRows];
        }
    }
@endphp

<div class="mm-routing-map">
    <div class="settings-block">
        <h4><i class="fas fa-project-diagram"></i> Notification Routing Map</h4>
        <p class="text-muted" style="font-size: 0.88em; margin-bottom: 0.5rem;">
            Read-only snapshot of the dispatcher's delivery plan: for every notification type
            it shows the webhook destinations and the Discord role each one will mention,
            resolved with the same precedence used at send time (L1 per-type role &middot; L2
            webhook legacy role). Use this to verify routing without clicking through every
            category.
        </p>

        <div class="routing-map-summary">
            <div class="routing-stat"><strong>{{ $mmRouteStatTotal }}</strong> notification types</div>
            <div class="routing-stat"><strong>{{ $mmRouteStatEnabled }}</strong> globally enabled</div>
            <div class="routing-stat"><strong>{{ $mmRouteStatDelivering }}</strong> delivering</div>
            @if($mmRouteStatSilent > 0)
                <div class="routing-stat warn">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>{{ $mmRouteStatSilent }}</strong> enabled but firing nowhere
                </div>
            @endif
            @if(!$mmManagerCoreAvailable)
                <div class="routing-stat warn" title="Cross-plugin types (extraction_at_risk, extraction_lost) need Manager Core to fire.">
                    <i class="fas fa-puzzle-piece"></i>
                    Manager Core missing &mdash; cross-plugin types dormant
                </div>
            @elseif(!$mmStructureManagerAvailable)
                <div class="routing-stat warn" title="Cross-plugin types need Structure Manager's FuelCalculator helper to fire.">
                    <i class="fas fa-puzzle-piece"></i>
                    Structure Manager missing &mdash; cross-plugin types dormant
                </div>
            @endif
        </div>

        @forelse($mmRoutingData as $catKey => $catData)
            <div class="routing-ns-label">
                <i class="{{ $catData['def']['icon'] }}"></i>
                {{ $catData['def']['label'] }}
            </div>
            <table class="routing-table">
                <thead>
                    <tr>
                        <th style="width:26%;">Notification type</th>
                        <th style="width:30%;">Delivers to</th>
                        <th style="width:18%;">Corporation</th>
                        <th style="width:26%;">Will mention</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($catData['rows'] as $row)
                        @php($typeKey = $row['typeKey'])
                        @php($typeDef = $row['typeDef'])
                        @php($dests = $row['dests'])
                        @php($globalEnabled = $row['globalEnabled'])
                        @php($crossBlocked = $row['crossBlocked'])
                        @if(count($dests) === 0)
                            <tr class="{{ $globalEnabled ? '' : 'routing-row-disabled' }}">
                                <td class="routing-cat-cell">
                                    <div class="routing-cat-name">
                                        <i class="fas fa-circle" style="font-size:0.5rem; vertical-align:middle; color:{{ $globalEnabled ? '#28a745' : '#52616b' }};"></i>
                                        {{ $typeDef['label'] }}
                                    </div>
                                    <div class="routing-cat-key">{{ $typeKey }}</div>
                                </td>
                                <td colspan="3">
                                    @if(!$globalEnabled)
                                        <span class="routing-none">Type disabled globally (no delivery).</span>
                                    @elseif($crossBlocked)
                                        <span class="routing-unrouted">
                                            <i class="fas fa-puzzle-piece"></i>
                                            Requires {{ $row['mcBlocked'] && $row['smBlocked'] ? 'Manager Core + Structure Manager' : ($row['mcBlocked'] ? 'Manager Core' : 'Structure Manager') }} to fire (not installed). Not bound to any webhook either.
                                        </span>
                                    @else
                                        <span class="routing-unrouted">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            Enabled but no webhook subscribed (this type fires nowhere).
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @else
                            @foreach($dests as $di => $d)
                                @php($wh = $d['webhook'])
                                <tr class="{{ $d['live'] ? '' : 'routing-row-disabled' }}">
                                    @if($di === 0)
                                        <td class="routing-cat-cell" rowspan="{{ count($dests) }}">
                                            <div class="routing-cat-name">
                                                <i class="fas fa-circle" style="font-size:0.5rem; vertical-align:middle; color:{{ $globalEnabled ? '#28a745' : '#52616b' }};"></i>
                                                {{ $typeDef['label'] }}
                                            </div>
                                            <div class="routing-cat-key">{{ $typeKey }}</div>
                                            @if(!$globalEnabled)
                                                <div class="routing-status"><span class="off">Type disabled globally</span></div>
                                            @elseif($crossBlocked)
                                                <div class="routing-status"><span class="off">Dormant: needs {{ $row['mcBlocked'] && $row['smBlocked'] ? 'MC + SM' : ($row['mcBlocked'] ? 'Manager Core' : 'Structure Manager') }}</span></div>
                                            @endif
                                            @if($row['hardBlocked'])
                                                <div class="routing-status"><span class="off">Role pings hard-blocked (batch protection)</span></div>
                                            @endif
                                        </td>
                                    @endif
                                    <td class="routing-dest">
                                        <i class="fas fa-arrow-right routing-arrow"></i>{{ $wh->name ?: 'Webhook #' . $wh->id }}
                                        <span class="badge badge-secondary" style="font-size: 0.65em; vertical-align: middle;">{{ strtoupper($wh->type ?? 'discord') }}</span>
                                        @if(!$wh->is_enabled)
                                            <div class="routing-status"><span class="off">webhook disabled</span></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($wh->corporation_id)
                                            Corp #{{ $wh->corporation_id }}
                                        @else
                                            All corps
                                        @endif
                                    </td>
                                    <td>
                                        @if($row['hardBlocked'])
                                            <span class="mm-role-none">No mention (hard-blocked)</span>
                                            <span class="routing-via via-blocked" title="tax_invoice batch protection: role pings disabled in code">L0 blocked</span>
                                        @elseif(!$row['pingRole'])
                                            <span class="mm-role-none">No mention (ping_role OFF)</span>
                                        @else
                                            @include('mining-manager::settings.partials._role_pill', ['desc' => $d['role']])
                                            @if($d['via'] === 'type')
                                                <span class="routing-via via-type" title="L1 per-type role: set in Settings -> Notifications for {{ $typeKey }}">L1 per-type</span>
                                            @elseif($d['via'] === 'webhook')
                                                <span class="routing-via via-webhook" title="L2 webhook legacy role: set in Settings -> Webhooks on this webhook">L2 webhook</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    @endforeach
                </tbody>
            </table>
        @empty
            <div class="routing-empty">No notification categories found.</div>
        @endforelse
    </div>
</div>
