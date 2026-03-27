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
        Each channel can be independently enabled and configured with specific notification types.
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- SECTION 1: EVE Mail --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="card bg-dark mb-3" style="border: 1px solid rgba(255,255,255,0.1);">
        <div class="card-header" style="background: linear-gradient(135deg, #1a5276 0%, #2471a3 100%); filter: grayscale(50%);">
            <h5 class="card-title mb-0">
                <i class="fas fa-envelope"></i>
                EVE Mail
                <span class="badge badge-secondary ml-2" style="font-size: 0.7rem;">NOT AVAILABLE</span>
            </h5>
        </div>
        <div class="card-body">
            {{-- Not Supported Banner --}}
            <div class="alert alert-warning mb-3" style="border-left: 4px solid #ffc107; color: #333;">
                <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                <strong style="color: #1a1a1a;">Currently Not Supported</strong> — EVE Mail notifications require the
                <code style="color: #856404; background: rgba(0,0,0,0.1); padding: 1px 4px; border-radius: 3px;">esi-mail.send_mail.v1</code> ESI scope, which is not currently available in SeAT's SSO scope
                configuration. The scope exists in EVE's ESI API but SeAT does not offer it as an option during
                character authentication.
                <br><br>
                <small style="color: #555;">
                    This feature will be enabled in a future update once scope support is confirmed with SeAT developers.
                    In the meantime, please use Discord or Slack webhooks for notifications.
                </small>
            </div>

            {{-- Enable Toggle - forced off and disabled --}}
            <div class="custom-control custom-switch mb-3" style="opacity: 0.5;">
                <input type="checkbox" class="custom-control-input"
                       id="evemail_enabled" name="evemail_enabled" value="0" disabled>
                <label class="custom-control-label" for="evemail_enabled">
                    <strong>Enable EVE Mail Notifications</strong>
                </label>
                <small class="form-text text-muted">This option is currently disabled pending ESI scope support in SeAT.</small>
            </div>

            @php
                $evemailTypeList = [
                    'tax_reminder' => ['label' => 'Tax Reminder', 'icon' => 'fas fa-clock text-warning'],
                    'tax_invoice' => ['label' => 'Tax Invoice Created', 'icon' => 'fas fa-file-invoice text-info'],
                    'tax_overdue' => ['label' => 'Tax Overdue', 'icon' => 'fas fa-exclamation-circle text-danger'],
                    'event_created' => ['label' => 'Event Created', 'icon' => 'fas fa-calendar-plus text-primary'],
                    'event_started' => ['label' => 'Event Started', 'icon' => 'fas fa-play text-success'],
                    'event_completed' => ['label' => 'Event Completed', 'icon' => 'fas fa-flag-checkered text-secondary'],
                    'moon_ready' => ['label' => 'Moon Extraction Ready', 'icon' => 'fas fa-moon text-warning'],
                ];
            @endphp
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
                This section shows notification type status and Discord pinging options.
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
                                        @if($wh->notify_tax_reminder) <span class="badge badge-warning" title="Tax Reminder"><i class="fas fa-clock"></i></span> @endif
                                        @if($wh->notify_tax_invoice) <span class="badge badge-info" title="Tax Invoice"><i class="fas fa-file-invoice"></i></span> @endif
                                        @if($wh->notify_tax_overdue) <span class="badge badge-danger" title="Tax Overdue"><i class="fas fa-exclamation-circle"></i></span> @endif
                                        @if(!$wh->notify_tax_reminder && !$wh->notify_tax_invoice && !$wh->notify_tax_overdue)
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

            {{-- Discord User Pinging --}}
            <hr>
            <h6 class="mb-3">
                <i class="fas fa-at"></i> User Pinging
            </h6>

            @if($seatConnectorAvailable)
                <div class="custom-control custom-switch mb-3">
                    <input type="checkbox" class="custom-control-input"
                           id="discord_pinging_enabled" name="discord_pinging_enabled" value="1"
                           {{ old('discord_pinging_enabled', $notificationSettings['discord_pinging_enabled'] ?? false) ? 'checked' : '' }}>
                    <label class="custom-control-label" for="discord_pinging_enabled">
                        <strong>Enable Discord User Pinging</strong>
                    </label>
                    <small class="form-text text-muted">
                        Mention users by their Discord ID in tax notification messages.
                        Uses SeAT Connector to map EVE characters to Discord accounts.
                    </small>
                </div>

                <div id="discord-ping-options" style="{{ old('discord_pinging_enabled', $notificationSettings['discord_pinging_enabled'] ?? false) ? '' : 'opacity: 0.5; pointer-events: none;' }}">
                    <label class="mb-2">Ping Content</label>
                    <div class="custom-control custom-radio mb-2">
                        <input type="radio" class="custom-control-input"
                               id="ping_show_amount" name="discord_ping_show_amount" value="1"
                               {{ old('discord_ping_show_amount', $notificationSettings['discord_ping_show_amount'] ?? true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="ping_show_amount">
                            <i class="fas fa-coins text-warning"></i> Show tax amount in ping message
                        </label>
                        <small class="form-text text-muted">e.g., "@User - Tax payment due: 1,234,567 ISK"</small>
                    </div>
                    <div class="custom-control custom-radio mb-3">
                        <input type="radio" class="custom-control-input"
                               id="ping_general_notice" name="discord_ping_show_amount" value="0"
                               {{ !old('discord_ping_show_amount', $notificationSettings['discord_ping_show_amount'] ?? true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="ping_general_notice">
                            <i class="fas fa-link text-info"></i> Send general notice with link to tax page
                        </label>
                        <small class="form-text text-muted">e.g., "@User - You have a tax payment due. View details: [link]"</small>
                    </div>

                    <div class="form-group">
                        <label for="discord_ping_tax_page_url">
                            <i class="fas fa-external-link-alt"></i> Tax Page URL (optional)
                        </label>
                        <input type="text" class="form-control" id="discord_ping_tax_page_url"
                               name="discord_ping_tax_page_url"
                               value="{{ old('discord_ping_tax_page_url', $notificationSettings['discord_ping_tax_page_url'] ?? '') }}"
                               placeholder="https://your-seat.example.com/mining-manager/ledger/my-minings">
                        <small class="form-text text-muted">URL included in general notice pings. Leave empty to omit link.</small>
                    </div>
                </div>
            @else
                <div class="alert alert-danger mb-0">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>SeAT Connector Required</strong> —
                    <a href="https://github.com/warlof/seat-connector" target="_blank" class="alert-link">warlof/seat-connector</a>
                    is not installed. Discord user pinging requires this plugin to map EVE characters to Discord user IDs.
                    <br>
                    <small class="text-muted mt-1 d-block">
                        Without this plugin, Discord webhook notifications will still work — but users won't be mentioned by name.
                    </small>
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
            {{-- Enable Toggle --}}
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
                {{-- Webhook URL --}}
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

                {{-- Per-type Checkboxes --}}
                <label class="mb-2"><i class="fas fa-filter"></i> Notification Types</label>
                <div class="row">
                    @php
                        $slackTypes = old('slack_types', $notificationSettings['slack_types'] ?? []);
                    @endphp
                    @foreach($evemailTypeList as $typeKey => $typeInfo)
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
    // EVE Mail toggle disabled - feature not currently supported

    // Toggle Slack options visibility
    $('#slack_enabled').on('change', function() {
        $('#slack-options').css({
            'opacity': this.checked ? '1' : '0.5',
            'pointer-events': this.checked ? 'auto' : 'none'
        });
    });

    // Toggle Discord pinging options visibility
    $('#discord_pinging_enabled').on('change', function() {
        $('#discord-ping-options').css({
            'opacity': this.checked ? '1' : '0.5',
            'pointer-events': this.checked ? 'auto' : 'none'
        });
    });
});
</script>
@endpush
