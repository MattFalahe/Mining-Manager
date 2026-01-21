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
                                        @if($webhook->discord_role_id)
                                            <br><small class="text-muted"><i class="fas fa-at"></i> Role ping enabled</small>
                                        @endif
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
                    </div>

                    {{-- Discord Specific Settings --}}
                    <div id="discord-settings" class="webhook-type-settings">
                        <hr>
                        <h6><i class="fab fa-discord"></i> {{ trans('mining-manager::settings.discord_settings') }}</h6>

                        <div class="form-group">
                            <label for="discord-role-id">
                                {{ trans('mining-manager::settings.discord_role_id') }}
                                <span class="text-muted">({{ trans('mining-manager::settings.optional') }})</span>
                            </label>
                            <input type="text"
                                   class="form-control"
                                   id="discord-role-id"
                                   name="discord_role_id"
                                   placeholder="123456789012345678">
                            <small class="form-text text-muted">{{ trans('mining-manager::settings.discord_role_id_help') }}</small>
                        </div>

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

                        <div class="form-group">
                            <label for="discord-avatar-url">
                                {{ trans('mining-manager::settings.discord_avatar_url') }}
                                <span class="text-muted">({{ trans('mining-manager::settings.optional') }})</span>
                            </label>
                            <input type="url"
                                   class="form-control"
                                   id="discord-avatar-url"
                                   name="discord_avatar_url"
                                   placeholder="https://...">
                            <small class="form-text text-muted">{{ trans('mining-manager::settings.discord_avatar_help') }}</small>
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
