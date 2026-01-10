<form method="POST" action="{{ route('mining-manager.settings.update-notifications') }}">
    @csrf

    <h4>
        <i class="fas fa-bell"></i>
        Notification Settings
        <span class="badge badge-success ml-2">Global</span>
    </h4>
    <hr>

    <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        Configure how notifications are delivered through Discord webhooks and Slack.
        Each notification type has its own settings for role pinging, user pinging, and display options.
        These are master switches — if disabled here, the notification won't be sent through any channel.
    </div>

    @php
        $enabledTypes = $notificationSettings['enabled_types'] ?? [];
        $typeSettings = $notificationSettings['type_settings'] ?? [];
        $seatConnectorAvailable = $notificationSettings['seat_connector_available'] ?? false;

        // Notification type definitions grouped by category
        $categories = [
            'Tax Notifications' => [
                'icon' => 'fas fa-coins text-warning',
                'types' => [
                    'tax_generated' => [
                        'label' => 'Mining Taxes Summary',
                        'icon' => 'fas fa-calculator text-info',
                        'desc' => 'When taxes are calculated — broadcasts summary to directors with collection instructions',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                    'tax_announcement' => [
                        'label' => 'New Invoices Announcement',
                        'icon' => 'fas fa-bullhorn text-primary',
                        'desc' => 'General announcement for members when new invoices are generated — shows due date and payment links',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                    'tax_reminder' => [
                        'label' => 'Tax Payment Reminder',
                        'icon' => 'fas fa-clock text-warning',
                        'desc' => 'Reminder sent to individual miners before the payment deadline',
                        'scope' => 'individual',
                        'has_role_ping' => true,
                        'has_user_ping' => true,
                        'has_show_amount' => true,
                    ],
                    'tax_invoice' => [
                        'label' => 'Tax Invoice Created',
                        'icon' => 'fas fa-file-invoice text-info',
                        'desc' => 'Sent to the individual miner when a tax invoice is generated',
                        'scope' => 'individual',
                        'has_role_ping' => true,
                        'has_user_ping' => true,
                        'has_show_amount' => true,
                    ],
                    'tax_overdue' => [
                        'label' => 'Tax Payment Overdue',
                        'icon' => 'fas fa-exclamation-circle text-danger',
                        'desc' => 'Sent to the individual miner when a tax payment is past its due date',
                        'scope' => 'individual',
                        'has_role_ping' => true,
                        'has_user_ping' => true,
                        'has_show_amount' => true,
                    ],
                ],
            ],
            'Event Notifications' => [
                'icon' => 'fas fa-calendar text-primary',
                'types' => [
                    'event_created' => [
                        'label' => 'Mining Event Created',
                        'icon' => 'fas fa-calendar-plus text-primary',
                        'desc' => 'New mining event has been scheduled',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                    'event_started' => [
                        'label' => 'Mining Event Started',
                        'icon' => 'fas fa-play text-success',
                        'desc' => 'A mining event has begun',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                    'event_completed' => [
                        'label' => 'Mining Event Completed',
                        'icon' => 'fas fa-flag-checkered text-secondary',
                        'desc' => 'A mining event has finished with results',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                ],
            ],
            'Moon Notifications' => [
                'icon' => 'fas fa-moon text-warning',
                'types' => [
                    'moon_ready' => [
                        'label' => 'Moon Chunk Ready',
                        'icon' => 'fas fa-moon text-warning',
                        'desc' => 'Moon chunk has arrived and is ready for fracture',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                    'jackpot_detected' => [
                        'label' => 'Jackpot Detected',
                        'icon' => 'fas fa-star text-warning',
                        'desc' => 'A jackpot moon extraction has been detected or reported',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                ],
            ],
            'Theft Detection' => [
                'icon' => 'fas fa-user-secret text-danger',
                'types' => [
                    'theft_detected' => [
                        'label' => 'Theft Detected',
                        'icon' => 'fas fa-exclamation-triangle text-warning',
                        'desc' => 'Suspicious mining activity detected on a moon',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                    'critical_theft' => [
                        'label' => 'Critical Theft',
                        'icon' => 'fas fa-skull-crossbones text-danger',
                        'desc' => 'High-value theft or repeated theft incidents',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                    'active_theft' => [
                        'label' => 'Active Theft in Progress',
                        'icon' => 'fas fa-bolt text-danger',
                        'desc' => 'Theft is currently happening — immediate attention required',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                    'incident_resolved' => [
                        'label' => 'Incident Resolved',
                        'icon' => 'fas fa-check-circle text-success',
                        'desc' => 'A theft incident has been resolved or cleared',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                ],
            ],
            'Reports' => [
                'icon' => 'fas fa-chart-bar text-info',
                'types' => [
                    'report_generated' => [
                        'label' => 'Report Generated',
                        'icon' => 'fas fa-chart-line text-info',
                        'desc' => 'A scheduled mining report has been generated',
                        'scope' => 'general',
                        'has_role_ping' => true,
                        'has_user_ping' => false,
                        'has_show_amount' => false,
                    ],
                ],
            ],
        ];
    @endphp

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- NOTIFICATION TYPES WITH PER-TYPE SETTINGS --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="card bg-dark mb-3">
        <div class="card-header" style="background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);">
            <h5 class="card-title mb-0">
                <i class="fas fa-sliders-h"></i>
                Notification Types & Settings
            </h5>
        </div>
        <div class="card-body">
            <small class="form-text text-muted mb-3 d-block">
                Master switches control whether a notification type is sent through any channel.
                Expand each type to configure role pinging, user pinging, and display options.
            </small>

            @foreach($categories as $catName => $catData)
                <h6 class="mt-3 mb-2"><i class="{{ $catData['icon'] }}"></i> {{ $catName }}</h6>

                @foreach($catData['types'] as $typeKey => $typeInfo)
                    @php
                        $isEnabled = $enabledTypes[$typeKey] ?? true;
                        $ts = $typeSettings[$typeKey] ?? [];
                        $pingRole = $ts['ping_role'] ?? false;
                        $roleId = $ts['role_id'] ?? '';
                        $pingUser = $ts['ping_user'] ?? false;
                        $showAmount = $ts['show_amount'] ?? true;
                    @endphp

                    <div class="card mb-2 ml-3" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                        <div class="card-body py-2 px-3">
                            {{-- Master toggle row --}}
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input notify-master-toggle"
                                           id="notify_global_{{ $typeKey }}" name="notify_global_{{ $typeKey }}" value="1"
                                           data-type="{{ $typeKey }}"
                                           {{ $isEnabled ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="notify_global_{{ $typeKey }}">
                                        <i class="{{ $typeInfo['icon'] }}"></i> <strong>{{ $typeInfo['label'] }}</strong>
                                        @if(($typeInfo['scope'] ?? '') === 'individual')
                                            <span class="badge badge-warning badge-sm ml-1" title="Sent individually per character">Individual</span>
                                        @elseif(($typeInfo['scope'] ?? '') === 'general')
                                            <span class="badge badge-info badge-sm ml-1" title="Broadcast to all configured channels">General</span>
                                        @endif
                                    </label>
                                </div>

                                @if($typeInfo['has_role_ping'] || $typeInfo['has_user_ping'] || $typeInfo['has_show_amount'])
                                    <button type="button" class="btn btn-xs btn-outline-secondary notify-expand-btn"
                                            data-target="#typeSettings_{{ $typeKey }}"
                                            onclick="toggleTypeSettings('{{ $typeKey }}')">
                                        <i class="fas fa-cog" id="typeSettingsIcon_{{ $typeKey }}"></i>
                                    </button>
                                @endif
                            </div>
                            <small class="form-text text-muted ml-4">{{ $typeInfo['desc'] }}</small>

                            {{-- Expandable settings --}}
                            @if($typeInfo['has_role_ping'] || $typeInfo['has_user_ping'] || $typeInfo['has_show_amount'])
                                <div id="typeSettings_{{ $typeKey }}" class="mt-2 pl-4 pr-2 pb-2"
                                     style="display: {{ ($pingRole || $pingUser || !$showAmount) ? 'block' : 'none' }};
                                            border-left: 3px solid rgba(88,166,255,0.3); margin-left: 10px;
                                            {{ !$isEnabled ? 'opacity: 0.5; pointer-events: none;' : '' }}">

                                    {{-- Role Ping --}}
                                    @if($typeInfo['has_role_ping'])
                                        <div class="d-flex align-items-center mt-1 mb-1" style="gap: 10px;">
                                            <div class="custom-control custom-switch" style="min-width: 200px;">
                                                <input type="checkbox" class="custom-control-input role-ping-toggle"
                                                       id="type_{{ $typeKey }}_ping_role" name="type_{{ $typeKey }}_ping_role" value="1"
                                                       data-type="{{ $typeKey }}"
                                                       {{ $pingRole ? 'checked' : '' }}>
                                                <label class="custom-control-label" for="type_{{ $typeKey }}_ping_role">
                                                    <i class="fas fa-at text-info"></i> Ping Role
                                                </label>
                                            </div>
                                            <input type="text" class="form-control form-control-sm role-id-input"
                                                   id="type_{{ $typeKey }}_role_id" name="type_{{ $typeKey }}_role_id"
                                                   value="{{ $roleId }}"
                                                   placeholder="Discord Role ID"
                                                   style="max-width: 220px; {{ !$pingRole ? 'opacity: 0.4;' : '' }}">
                                        </div>
                                    @endif

                                    {{-- User Ping (tax types only) --}}
                                    @if($typeInfo['has_user_ping'])
                                        <div class="custom-control custom-switch mt-1 mb-1">
                                            <input type="checkbox" class="custom-control-input"
                                                   id="type_{{ $typeKey }}_ping_user" name="type_{{ $typeKey }}_ping_user" value="1"
                                                   {{ $pingUser ? 'checked' : '' }}>
                                            <label class="custom-control-label" for="type_{{ $typeKey }}_ping_user">
                                                <i class="fas fa-user text-success"></i> Ping User
                                                @if(!$seatConnectorAvailable)
                                                    <span class="badge badge-secondary ml-1" style="font-size: 0.65rem;">Requires seat-connector</span>
                                                @endif
                                            </label>
                                            <small class="form-text text-muted">Mention the miner by their Discord account (requires seat-connector)</small>
                                        </div>
                                    @endif

                                    {{-- Show Amount (tax types only) --}}
                                    @if($typeInfo['has_show_amount'])
                                        <div class="custom-control custom-switch mt-1 mb-1">
                                            <input type="checkbox" class="custom-control-input"
                                                   id="type_{{ $typeKey }}_show_amount" name="type_{{ $typeKey }}_show_amount" value="1"
                                                   {{ $showAmount ? 'checked' : '' }}>
                                            <label class="custom-control-label" for="type_{{ $typeKey }}_show_amount">
                                                <i class="fas fa-coins text-warning"></i> Show ISK Amount
                                            </label>
                                            <small class="form-text text-muted">Include the tax amount in the notification message and ping</small>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- SECTION 1: EVE Mail --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="card mb-3" style="background: #1e2a38; border: 1px solid rgba(255,255,255,0.05); opacity: 0.65;">
        <div class="card-header" style="background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);">
            <h5 class="card-title mb-0" style="color: #8899aa;">
                <i class="fas fa-envelope"></i>
                EVE Mail
                <span class="badge ml-2" style="font-size: 0.7rem; background: #4a5568; color: #a0aec0;">NOT AVAILABLE</span>
            </h5>
        </div>
        <div class="card-body">
            <div class="mb-3" style="background: rgba(102, 126, 234, 0.1); border-left: 4px solid #667eea; border-radius: 5px; padding: 15px; color: #c0c8d4;">
                <i class="fas fa-info-circle" style="color: #667eea;"></i>
                <strong style="color: #e2e8f0;">Currently Not Supported</strong> — EVE Mail notifications require the
                <code style="color: #fbbf24; background: rgba(0,0,0,0.3); padding: 1px 4px; border-radius: 3px;">esi-mail.send_mail.v1</code> ESI scope, which is not currently available in SeAT's SSO scope
                configuration.
                <br><br>
                <small style="color: #9ca3af;">
                    This feature will be enabled in a future update once scope support is confirmed with SeAT developers.
                </small>
            </div>

            <div class="custom-control custom-switch mb-3" style="opacity: 0.4;">
                <input type="checkbox" class="custom-control-input"
                       id="evemail_enabled" name="evemail_enabled" value="0" disabled>
                <label class="custom-control-label" for="evemail_enabled">
                    <strong style="color: #8899aa;">Enable EVE Mail Notifications</strong>
                </label>
                <small class="form-text" style="color: #6b7280;">This option is currently disabled pending ESI scope support in SeAT.</small>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- SECTION 2: Discord --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="card bg-dark mb-3">
        <div class="card-header" style="background: linear-gradient(135deg, #5865F2 0%, #7289DA 100%);">
            <h5 class="card-title mb-0">
                <i class="fab fa-discord"></i>
                Discord (via Webhooks)
            </h5>
        </div>
        <div class="card-body">
            <div class="info-banner mb-3">
                <i class="fas fa-info-circle"></i>
                Webhook management (add, edit, delete) is on the
                <a href="#" onclick="$('.nav-link[data-tab=webhooks]').click(); return false;">
                    <i class="fas fa-satellite-dish"></i> Webhooks tab</a>.
                Role pinging is now configured per notification type in the section above.
            </div>

            {{-- Discord Webhook Summary Table --}}
            @php
                $discordWebhooks = ($webhooks ?? collect())->where('type', 'discord');
            @endphp

            @if($discordWebhooks->count() > 0)
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-dark table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Webhook</th>
                                <th>Status</th>
                                <th class="text-center">Tax Notifications</th>
                                <th class="text-center">Events</th>
                                <th class="text-center">Moon</th>
                                <th class="text-center">Theft</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($discordWebhooks as $wh)
                                <tr>
                                    <td>
                                        <i class="fab fa-discord text-primary"></i>
                                        {{ $wh->name }}
                                    </td>
                                    <td>
                                        @if($wh->is_enabled)
                                            <span class="badge badge-success">Active</span>
                                        @else
                                            <span class="badge badge-secondary">Disabled</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($wh->notify_tax_generated) <span class="badge badge-info" title="Taxes Summary"><i class="fas fa-calculator"></i></span> @endif
                                        @if($wh->notify_tax_reminder) <span class="badge badge-warning" title="Tax Reminder"><i class="fas fa-clock"></i></span> @endif
                                        @if($wh->notify_tax_invoice) <span class="badge badge-info" title="Tax Invoice"><i class="fas fa-file-invoice"></i></span> @endif
                                        @if($wh->notify_tax_overdue) <span class="badge badge-danger" title="Tax Overdue"><i class="fas fa-exclamation-circle"></i></span> @endif
                                        @if(!$wh->notify_tax_generated && !$wh->notify_tax_reminder && !$wh->notify_tax_invoice && !$wh->notify_tax_overdue)
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($wh->notify_event_created) <span class="badge badge-primary" title="Created"><i class="fas fa-calendar-plus"></i></span> @endif
                                        @if($wh->notify_event_started) <span class="badge badge-success" title="Started"><i class="fas fa-play"></i></span> @endif
                                        @if($wh->notify_event_completed) <span class="badge badge-secondary" title="Completed"><i class="fas fa-flag-checkered"></i></span> @endif
                                        @if(!$wh->notify_event_created && !$wh->notify_event_started && !$wh->notify_event_completed)
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($wh->notify_moon_arrival) <span class="badge badge-warning" title="Moon Arrival"><i class="fas fa-moon"></i></span> @endif
                                        @if($wh->notify_jackpot_detected) <span class="badge badge-info" title="Jackpot"><i class="fas fa-star"></i></span> @endif
                                        @if(!$wh->notify_moon_arrival && !$wh->notify_jackpot_detected)
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($wh->notify_theft_detected) <span class="badge badge-danger" title="Theft"><i class="fas fa-user-secret"></i></span> @endif
                                        @if(!$wh->notify_theft_detected)
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="alert alert-secondary text-center mb-3">
                    <i class="fab fa-discord"></i> No Discord webhooks configured.
                    <a href="#" onclick="$('.nav-link[data-tab=webhooks]').click(); return false;">Add one on the Webhooks tab.</a>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- SECTION 3: Slack --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="card bg-dark mb-3">
        <div class="card-header" style="background: linear-gradient(135deg, #4A154B 0%, #611f69 100%);">
            <h5 class="card-title mb-0">
                <i class="fab fa-slack"></i>
                Slack
            </h5>
        </div>
        <div class="card-body">
            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" class="custom-control-input"
                       id="slack_enabled" name="slack_enabled" value="1"
                       {{ old('slack_enabled', $notificationSettings['slack_enabled'] ?? false) ? 'checked' : '' }}>
                <label class="custom-control-label" for="slack_enabled">
                    <strong>Enable Slack Notifications</strong>
                </label>
                <small class="form-text text-muted">Send notifications to a Slack channel via incoming webhook.</small>
            </div>

            <div id="slack-options" style="{{ old('slack_enabled', $notificationSettings['slack_enabled'] ?? false) ? '' : 'opacity: 0.5; pointer-events: none;' }}">
                <div class="form-group">
                    <label for="slack_webhook_url">
                        <i class="fas fa-link"></i> Slack Webhook URL
                    </label>
                    <input type="url" class="form-control" id="slack_webhook_url" name="slack_webhook_url"
                           value="{{ old('slack_webhook_url', $notificationSettings['slack_webhook_url'] ?? '') }}"
                           placeholder="https://hooks.slack.com/services/...">
                    <small class="form-text text-muted">
                        Create an incoming webhook in your Slack workspace settings.
                    </small>
                </div>

                @php
                    $slackTypeList = [
                        'tax_generated' => ['label' => 'Mining Taxes Summary', 'icon' => 'fas fa-calculator text-info'],
                        'tax_reminder' => ['label' => 'Tax Reminder', 'icon' => 'fas fa-clock text-warning'],
                        'tax_invoice' => ['label' => 'Tax Invoice Created', 'icon' => 'fas fa-file-invoice text-info'],
                        'tax_overdue' => ['label' => 'Tax Overdue', 'icon' => 'fas fa-exclamation-circle text-danger'],
                        'event_created' => ['label' => 'Event Created', 'icon' => 'fas fa-calendar-plus text-primary'],
                        'event_started' => ['label' => 'Event Started', 'icon' => 'fas fa-play text-success'],
                        'event_completed' => ['label' => 'Event Completed', 'icon' => 'fas fa-flag-checkered text-secondary'],
                        'moon_ready' => ['label' => 'Moon Chunk Ready', 'icon' => 'fas fa-moon text-warning'],
                        'jackpot_detected' => ['label' => 'Jackpot Detected', 'icon' => 'fas fa-star text-warning'],
                        'theft_detected' => ['label' => 'Theft Detected', 'icon' => 'fas fa-exclamation-triangle text-warning'],
                        'critical_theft' => ['label' => 'Critical Theft', 'icon' => 'fas fa-skull-crossbones text-danger'],
                        'active_theft' => ['label' => 'Active Theft', 'icon' => 'fas fa-bolt text-danger'],
                        'incident_resolved' => ['label' => 'Incident Resolved', 'icon' => 'fas fa-check-circle text-success'],
                        'report_generated' => ['label' => 'Report Generated', 'icon' => 'fas fa-chart-line text-info'],
                    ];
                    $slackTypes = old('slack_types', $notificationSettings['slack_types'] ?? []);
                @endphp

                <label class="mb-2"><i class="fas fa-filter"></i> Notification Types</label>
                <div class="row">
                    @foreach($slackTypeList as $typeKey => $typeInfo)
                        <div class="col-md-6">
                            <div class="custom-control custom-checkbox mb-2">
                                <input type="checkbox" class="custom-control-input"
                                       id="slack_type_{{ $typeKey }}" name="slack_type_{{ $typeKey }}" value="1"
                                       {{ ($slackTypes[$typeKey] ?? true) ? 'checked' : '' }}>
                                <label class="custom-control-label" for="slack_type_{{ $typeKey }}">
                                    <i class="{{ $typeInfo['icon'] }}"></i> {{ $typeInfo['label'] }}
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Save Button --}}
    <div class="action-buttons">
        <button type="submit" class="btn btn-success btn-block">
            <i class="fas fa-save"></i>
            Save Notification Settings
        </button>
    </div>
</form>

@push('javascript')
<script>
$(document).ready(function() {
    // Toggle Slack options visibility
    $('#slack_enabled').on('change', function() {
        $('#slack-options').css({
            'opacity': this.checked ? '1' : '0.5',
            'pointer-events': this.checked ? 'auto' : 'none'
        });
    });

    // Master toggle enables/disables the per-type settings panel
    $('.notify-master-toggle').on('change', function() {
        const type = $(this).data('type');
        const panel = document.getElementById('typeSettings_' + type);
        if (panel) {
            panel.style.opacity = this.checked ? '1' : '0.5';
            panel.style.pointerEvents = this.checked ? 'auto' : 'none';
        }
    });

    // Role ping toggle enables/disables the role ID input
    $('.role-ping-toggle').on('change', function() {
        const type = $(this).data('type');
        const input = document.getElementById('type_' + type + '_role_id');
        if (input) {
            input.style.opacity = this.checked ? '1' : '0.4';
        }
    });
});

function toggleTypeSettings(type) {
    const panel = document.getElementById('typeSettings_' + type);
    const icon = document.getElementById('typeSettingsIcon_' + type);
    if (panel) {
        const isHidden = panel.style.display === 'none';
        panel.style.display = isHidden ? 'block' : 'none';
        if (icon) {
            icon.className = isHidden ? 'fas fa-cog fa-spin' : 'fas fa-cog';
            // Stop spin after a moment
            if (isHidden) {
                setTimeout(() => { icon.className = 'fas fa-cog'; }, 500);
            }
        }
    }
}
</script>
@endpush
