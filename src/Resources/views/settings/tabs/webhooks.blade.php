<div id="webhooks-settings-content">

    <h4>
        <i class="fas fa-satellite-dish"></i>
        {{ trans('mining-manager::settings.webhook_notifications') }}
    </h4>
    <hr>

    <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        <strong>{{ trans('mining-manager::settings.info') }}:</strong>
        {{ trans('mining-manager::settings.webhooks_info') }}
    </div>

    {{-- Statistics Card --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-chart-line"></i>
                {{ trans('mining-manager::settings.webhook_statistics') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-3">
                    <div class="stat-box">
                        <h3 class="text-success" id="stat-webhooks-configured">{{ $webhooks->count() }}</h3>
                        <p class="text-muted">{{ trans('mining-manager::settings.webhooks_configured') }}</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h3 class="text-primary" id="stat-webhooks-enabled">{{ $webhooks->where('is_enabled', true)->count() }}</h3>
                        <p class="text-muted">{{ trans('mining-manager::settings.webhooks_enabled') }}</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h3 class="text-success" id="stat-total-sent">{{ $webhooks->sum('success_count') }}</h3>
                        <p class="text-muted">{{ trans('mining-manager::settings.total_sent') }}</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h3 class="text-danger" id="stat-total-failed">{{ $webhooks->sum('failure_count') }}</h3>
                        <p class="text-muted">{{ trans('mining-manager::settings.total_failed') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Add Webhook Button --}}
    <div class="mb-3">
        <button type="button" class="btn btn-success" onclick="openWebhookModal()">
            <i class="fas fa-plus"></i> {{ trans('mining-manager::settings.add_webhook') }}
        </button>
    </div>

    {{-- Webhooks List --}}
    <div class="card bg-dark">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-list"></i>
                {{ trans('mining-manager::settings.configured_webhooks') }}
            </h5>
        </div>
        <div class="card-body p-0">
            @if($webhooks->isEmpty())
                <div class="p-4 text-center text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>{{ trans('mining-manager::settings.no_webhooks_configured') }}</p>
                    <button type="button" class="btn btn-primary" onclick="openWebhookModal()">
                        <i class="fas fa-plus"></i> {{ trans('mining-manager::settings.add_first_webhook') }}
                    </button>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0" id="webhooks-table">
                        <thead>
                            <tr>
                                <th width="5%">
                                    <i class="fas fa-toggle-on"></i>
                                </th>
                                <th width="20%">{{ trans('mining-manager::settings.name') }}</th>
                                <th width="15%">{{ trans('mining-manager::settings.type') }}</th>
                                <th width="25%">{{ trans('mining-manager::settings.events') }}</th>
                                <th width="15%">{{ trans('mining-manager::settings.health') }}</th>
                                <th width="20%" class="text-right">{{ trans('mining-manager::settings.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($webhooks as $webhook)
                                <tr data-webhook-id="{{ $webhook->id }}">
                                    <td>
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox"
                                                   class="custom-control-input webhook-toggle"
                                                   id="webhook-toggle-{{ $webhook->id }}"
                                                   data-webhook-id="{{ $webhook->id }}"
                                                   {{ $webhook->is_enabled ? 'checked' : '' }}>
                                            <label class="custom-control-label" for="webhook-toggle-{{ $webhook->id }}"></label>
                                        </div>
                                    </td>
                                    <td>
                                        <strong>{{ $webhook->name }}</strong>
                                    </td>
                                    <td>
                                        @if($webhook->type === 'discord')
                                            <span class="badge badge-primary"><i class="fab fa-discord"></i> Discord</span>
                                        @elseif($webhook->type === 'slack')
                                            <span class="badge badge-info"><i class="fab fa-slack"></i> Slack</span>
                                        @else
                                            <span class="badge badge-secondary"><i class="fas fa-globe"></i> Custom</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="webhook-events-pills">
                                            @if($webhook->notify_theft_detected)
                                                <span class="badge badge-warning" title="{{ trans('mining-manager::settings.theft_detected') }}">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_critical_theft)
                                                <span class="badge badge-danger" title="{{ trans('mining-manager::settings.critical_theft') }}">
                                                    <i class="fas fa-fire"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_active_theft)
                                                <span class="badge badge-danger" title="{{ trans('mining-manager::settings.active_theft') }}">
                                                    <i class="fas fa-bolt"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_incident_resolved)
                                                <span class="badge badge-success" title="{{ trans('mining-manager::settings.incident_resolved') }}">
                                                    <i class="fas fa-check"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_moon_arrival)
                                                <span class="badge badge-info" title="Moon Arrival">
                                                    <i class="fas fa-moon"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_jackpot_detected)
                                                <span class="badge" style="background: linear-gradient(45deg, #ffd700, #ffed4e); color: #000;" title="Jackpot Detected">
                                                    <i class="fas fa-star"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_event_created)
                                                <span class="badge badge-primary" title="Event Created">
                                                    <i class="fas fa-calendar-plus"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_event_started)
                                                <span class="badge badge-success" title="Event Started">
                                                    <i class="fas fa-play"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_event_completed)
                                                <span class="badge badge-secondary" title="Event Completed">
                                                    <i class="fas fa-flag-checkered"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_tax_generated)
                                                <span class="badge badge-info" title="Mining Taxes Summary">
                                                    <i class="fas fa-calculator"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_tax_announcement)
                                                <span class="badge badge-primary" title="Tax Announcement">
                                                    <i class="fas fa-bullhorn"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_tax_reminder)
                                                <span class="badge badge-warning" title="Tax Reminder">
                                                    <i class="fas fa-clock"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_tax_invoice)
                                                <span class="badge badge-info" title="Tax Invoice">
                                                    <i class="fas fa-file-invoice"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_tax_overdue)
                                                <span class="badge badge-danger" title="Tax Overdue">
                                                    <i class="fas fa-exclamation-circle"></i>
                                                </span>
                                            @endif
                                            @if($webhook->notify_report_generated)
                                                <span class="badge badge-info" title="{{ trans('mining-manager::settings.report_generated') }}">
                                                    <i class="fas fa-chart-bar"></i>
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        @php
                                            $total = $webhook->success_count + $webhook->failure_count;
                                            $percentage = $total > 0 ? round(($webhook->success_count / $total) * 100, 1) : 100;
                                        @endphp

                                        @if($total === 0)
                                            <span class="badge badge-secondary">
                                                <i class="fas fa-clock"></i> {{ trans('mining-manager::settings.not_tested') }}
                                            </span>
                                        @elseif($percentage >= 90)
                                            <span class="badge badge-success">
                                                <i class="fas fa-check-circle"></i> {{ $percentage }}%
                                            </span>
                                        @elseif($percentage >= 70)
                                            <span class="badge badge-warning">
                                                <i class="fas fa-exclamation-circle"></i> {{ $percentage }}%
                                            </span>
                                        @else
                                            <span class="badge badge-danger">
                                                <i class="fas fa-times-circle"></i> {{ $percentage }}%
                                            </span>
                                        @endif

                                        @if($webhook->last_error)
                                            <i class="fas fa-info-circle text-warning"
                                               data-toggle="tooltip"
                                               title="{{ $webhook->last_error }}"></i>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <div class="btn-group">
                                            <button type="button"
                                                    class="btn btn-sm btn-primary"
                                                    onclick="testWebhook({{ $webhook->id }})"
                                                    title="{{ trans('mining-manager::settings.test_webhook') }}">
                                                <i class="fas fa-vial"></i>
                                            </button>
                                            <button type="button"
                                                    class="btn btn-sm btn-info"
                                                    onclick="editWebhook({{ $webhook->id }})"
                                                    title="{{ trans('mining-manager::settings.edit_webhook') }}">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button"
                                                    class="btn btn-sm btn-danger"
                                                    onclick="deleteWebhook({{ $webhook->id }})"
                                                    title="{{ trans('mining-manager::settings.delete_webhook') }}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

</div>

{{-- Webhook Modal --}}
<div class="modal fade" id="webhookModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-satellite-dish"></i>
                    <span id="webhook-modal-title">{{ trans('mining-manager::settings.add_webhook') }}</span>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="webhook-form">
                    <input type="hidden" id="webhook-id" name="webhook_id">

                    {{-- Basic Information --}}
                    <div class="form-group">
                        <label for="webhook-name">
                            {{ trans('mining-manager::settings.webhook_name') }}
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="webhook-name"
                               name="name"
                               placeholder="e.g., Main Discord Alerts"
                               required>
                        <small class="form-text text-muted">{{ trans('mining-manager::settings.webhook_name_help') }}</small>
                    </div>

                    <div class="form-group">
                        <label for="webhook-type">
                            {{ trans('mining-manager::settings.webhook_type') }}
                            <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" id="webhook-type" name="type" required>
                            <option value="discord">Discord</option>
                            <option value="slack">Slack</option>
                            <option value="custom">Custom Webhook</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="webhook-url">
                            {{ trans('mining-manager::settings.webhook_url') }}
                            <span class="text-danger">*</span>
                        </label>
                        <input type="url"
                               class="form-control"
                               id="webhook-url"
                               name="webhook_url"
                               placeholder="https://..."
                               required>
                        <small class="form-text text-muted" id="webhook-url-help">
                            {{ trans('mining-manager::settings.discord_webhook_help') }}
                        </small>
                    </div>

                    {{-- Event Selection --}}
                    <div class="form-group">
                        <label>{{ trans('mining-manager::settings.notify_on_events') }}</label>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-theft-detected" name="notify_theft_detected" value="1" checked>
                            <label class="custom-control-label" for="notify-theft-detected">
                                <i class="fas fa-exclamation-triangle text-warning"></i>
                                {{ trans('mining-manager::settings.theft_detected') }}
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-critical-theft" name="notify_critical_theft" value="1" checked>
                            <label class="custom-control-label" for="notify-critical-theft">
                                <i class="fas fa-fire text-danger"></i>
                                {{ trans('mining-manager::settings.critical_theft') }}
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-active-theft" name="notify_active_theft" value="1" checked>
                            <label class="custom-control-label" for="notify-active-theft">
                                <i class="fas fa-bolt text-danger"></i>
                                {{ trans('mining-manager::settings.active_theft') }}
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-incident-resolved" name="notify_incident_resolved" value="1">
                            <label class="custom-control-label" for="notify-incident-resolved">
                                <i class="fas fa-check text-success"></i>
                                {{ trans('mining-manager::settings.incident_resolved') }}
                            </label>
                        </div>

                        <hr class="my-2">
                        <small class="text-muted d-block mb-2"><strong>Moon Events</strong></small>

                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-moon-arrival" name="notify_moon_arrival" value="1">
                            <label class="custom-control-label" for="notify-moon-arrival">
                                <i class="fas fa-moon text-info"></i>
                                Moon Chunk Ready
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-jackpot-detected" name="notify_jackpot_detected" value="1">
                            <label class="custom-control-label" for="notify-jackpot-detected">
                                <i class="fas fa-star" style="color: #ffd700;"></i>
                                Jackpot Detected
                            </label>
                        </div>

                        <hr class="my-2">
                        <small class="text-muted d-block mb-2"><strong>Mining Events</strong></small>

                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-event-created" name="notify_event_created" value="1">
                            <label class="custom-control-label" for="notify-event-created">
                                <i class="fas fa-calendar-plus text-primary"></i>
                                Event Created
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-event-started" name="notify_event_started" value="1" checked>
                            <label class="custom-control-label" for="notify-event-started">
                                <i class="fas fa-play text-success"></i>
                                Event Started
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-event-completed" name="notify_event_completed" value="1">
                            <label class="custom-control-label" for="notify-event-completed">
                                <i class="fas fa-flag-checkered text-secondary"></i>
                                Event Completed
                            </label>
                        </div>

                        <hr class="my-2">
                        <small class="text-muted d-block mb-2"><strong>Tax Notifications</strong></small>

                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-tax-generated" name="notify_tax_generated" value="1">
                            <label class="custom-control-label" for="notify-tax-generated">
                                <i class="fas fa-calculator text-info"></i>
                                Mining Taxes Summary <span class="badge badge-secondary badge-sm">Directors</span>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-tax-announcement" name="notify_tax_announcement" value="1">
                            <label class="custom-control-label" for="notify-tax-announcement">
                                <i class="fas fa-bullhorn text-primary"></i>
                                New Invoices Announcement <span class="badge badge-secondary badge-sm">General</span>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-tax-reminder" name="notify_tax_reminder" value="1">
                            <label class="custom-control-label" for="notify-tax-reminder">
                                <i class="fas fa-clock text-warning"></i>
                                Tax Reminder
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-tax-invoice" name="notify_tax_invoice" value="1">
                            <label class="custom-control-label" for="notify-tax-invoice">
                                <i class="fas fa-file-invoice text-info"></i>
                                Tax Invoice Created
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-tax-overdue" name="notify_tax_overdue" value="1">
                            <label class="custom-control-label" for="notify-tax-overdue">
                                <i class="fas fa-exclamation-circle text-danger"></i>
                                Tax Overdue
                            </label>
                        </div>

                        <hr class="my-2">
                        <small class="text-muted d-block mb-2"><strong>{{ trans('mining-manager::settings.reports_category') }}</strong></small>

                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="notify-report-generated" name="notify_report_generated" value="1">
                            <label class="custom-control-label" for="notify-report-generated">
                                <i class="fas fa-chart-bar text-info"></i>
                                {{ trans('mining-manager::settings.report_generated') }}
                            </label>
                        </div>
                    </div>

                    {{-- Discord Specific Settings --}}
                    <div id="discord-settings" class="webhook-type-settings">
                        <hr>
                        <h6><i class="fab fa-discord"></i> {{ trans('mining-manager::settings.discord_settings') }}</h6>

                        <div class="form-group">
                            <label for="discord-username">
                                {{ trans('mining-manager::settings.discord_username') }}
                                <span class="text-muted">({{ trans('mining-manager::settings.optional') }})</span>
                            </label>
                            <input type="text"
                                   class="form-control"
                                   id="discord-username"
                                   name="discord_username"
                                   placeholder="Mining Manager Bot">
                            <small class="form-text text-muted">{{ trans('mining-manager::settings.discord_username_help') }}</small>
                        </div>
                    </div>

                    {{-- Slack Specific Settings --}}
                    <div id="slack-settings" class="webhook-type-settings" style="display: none;">
                        <hr>
                        <h6><i class="fab fa-slack"></i> {{ trans('mining-manager::settings.slack_settings') }}</h6>

                        <div class="form-group">
                            <label for="slack-channel">
                                {{ trans('mining-manager::settings.slack_channel') }}
                                <span class="text-muted">({{ trans('mining-manager::settings.optional') }})</span>
                            </label>
                            <input type="text"
                                   class="form-control"
                                   id="slack-channel"
                                   name="slack_channel"
                                   placeholder="#mining-alerts">
                            <small class="form-text text-muted">{{ trans('mining-manager::settings.slack_channel_help') }}</small>
                        </div>

                        <div class="form-group">
                            <label for="slack-username">
                                {{ trans('mining-manager::settings.slack_username') }}
                                <span class="text-muted">({{ trans('mining-manager::settings.optional') }})</span>
                            </label>
                            <input type="text"
                                   class="form-control"
                                   id="slack-username"
                                   name="slack_username"
                                   placeholder="Mining Manager">
                            <small class="form-text text-muted">{{ trans('mining-manager::settings.slack_username_help') }}</small>
                        </div>
                    </div>

                    {{-- Custom Webhook Settings --}}
                    <div id="custom-settings" class="webhook-type-settings" style="display: none;">
                        <hr>
                        <h6><i class="fas fa-globe"></i> {{ trans('mining-manager::settings.custom_webhook_settings') }}</h6>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            {{ trans('mining-manager::settings.custom_webhook_info') }}
                        </div>

                        <div class="form-group">
                            <label for="custom-payload-template">
                                {{ trans('mining-manager::settings.custom_payload_template') }}
                                <span class="text-muted">({{ trans('mining-manager::settings.optional') }})</span>
                            </label>
                            <textarea class="form-control"
                                      id="custom-payload-template"
                                      name="custom_payload_template"
                                      rows="6"
                                      placeholder='{"event": "@{{event_type}}", "character": "@{{character_name}}", "value": @{{ore_value}}}'></textarea>
                            <small class="form-text text-muted">{{ trans('mining-manager::settings.custom_payload_help') }}</small>
                        </div>
                    </div>

                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    {{ trans('mining-manager::settings.cancel') }}
                </button>
                <button type="button" class="btn btn-primary" onclick="saveWebhook()">
                    <i class="fas fa-save"></i> {{ trans('mining-manager::settings.save_webhook') }}
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.stat-box {
    padding: 15px;
}

.stat-box h3 {
    margin-bottom: 5px;
    font-size: 2rem;
}

.webhook-events-pills {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.webhook-events-pills .badge {
    font-size: 0.9rem;
}

.webhook-type-settings {
    background: rgba(255, 255, 255, 0.05);
    padding: 15px;
    border-radius: 5px;
    margin-top: 15px;
}
</style>
