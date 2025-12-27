<form method="POST" action="{{ route('mining-manager.settings.update-features') }}">
    @csrf
    
    <h4>
        <i class="fas fa-toggle-on"></i>
        {{ trans('mining-manager::settings.feature_settings') }}
    </h4>
    <hr>

    <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        <strong>{{ trans('mining-manager::settings.info') }}:</strong>
        {{ trans('mining-manager::settings.features_info') }}
    </div>

    {{-- Core Features --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-cubes"></i>
                {{ trans('mining-manager::settings.core_features') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="enable_tax_tracking" 
                       name="enable_tax_tracking" 
                       value="1"
                       {{ old('enable_tax_tracking', $settings->enable_tax_tracking ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="enable_tax_tracking">
                    <i class="fas fa-coins"></i>
                    <strong>{{ trans('mining-manager::settings.enable_tax_tracking') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.enable_tax_tracking_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="enable_ledger_tracking" 
                       name="enable_ledger_tracking" 
                       value="1"
                       {{ old('enable_ledger_tracking', $settings->enable_ledger_tracking ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="enable_ledger_tracking">
                    <i class="fas fa-book"></i>
                    <strong>{{ trans('mining-manager::settings.enable_ledger_tracking') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.enable_ledger_tracking_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="enable_analytics" 
                       name="enable_analytics" 
                       value="1"
                       {{ old('enable_analytics', $settings->enable_analytics ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="enable_analytics">
                    <i class="fas fa-chart-bar"></i>
                    <strong>{{ trans('mining-manager::settings.enable_analytics') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.enable_analytics_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="enable_reports" 
                       name="enable_reports" 
                       value="1"
                       {{ old('enable_reports', $settings->enable_reports ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="enable_reports">
                    <i class="fas fa-file-alt"></i>
                    <strong>{{ trans('mining-manager::settings.enable_reports') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.enable_reports_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Mining Events --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-calendar-star"></i>
                {{ trans('mining-manager::settings.mining_events') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="enable_events" 
                       name="enable_events" 
                       value="1"
                       {{ old('enable_events', $settings->enable_events ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="enable_events">
                    <i class="fas fa-calendar-check"></i>
                    <strong>{{ trans('mining-manager::settings.enable_events') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.enable_events_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="allow_event_creation" 
                       name="allow_event_creation" 
                       value="1"
                       {{ old('allow_event_creation', $settings->allow_event_creation ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="allow_event_creation">
                    <i class="fas fa-plus-circle"></i>
                    <strong>{{ trans('mining-manager::settings.allow_event_creation') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.allow_event_creation_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="auto_track_event_participation" 
                       name="auto_track_event_participation" 
                       value="1"
                       {{ old('auto_track_event_participation', $settings->auto_track_event_participation ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="auto_track_event_participation">
                    <i class="fas fa-users"></i>
                    <strong>{{ trans('mining-manager::settings.auto_track_event_participation') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.auto_track_event_participation_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="event_bonus_multiplier">
                    <i class="fas fa-gift"></i>
                    {{ trans('mining-manager::settings.event_bonus_multiplier') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('event_bonus_multiplier') is-invalid @enderror" 
                           id="event_bonus_multiplier" 
                           name="event_bonus_multiplier" 
                           value="{{ old('event_bonus_multiplier', $settings->event_bonus_multiplier ?? 1.5) }}"
                           min="1" 
                           max="5" 
                           step="0.1">
                    <div class="input-group-append">
                        <span class="input-group-text">x</span>
                    </div>
                    @error('event_bonus_multiplier')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.event_bonus_multiplier_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Moon Mining --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-moon"></i>
                {{ trans('mining-manager::settings.moon_mining') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="enable_moon_tracking" 
                       name="enable_moon_tracking" 
                       value="1"
                       {{ old('enable_moon_tracking', $settings->enable_moon_tracking ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="enable_moon_tracking">
                    <i class="fas fa-satellite-dish"></i>
                    <strong>{{ trans('mining-manager::settings.enable_moon_tracking') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.enable_moon_tracking_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="track_moon_compositions" 
                       name="track_moon_compositions" 
                       value="1"
                       {{ old('track_moon_compositions', $settings->track_moon_compositions ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="track_moon_compositions">
                    <i class="fas fa-atom"></i>
                    <strong>{{ trans('mining-manager::settings.track_moon_compositions') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.track_moon_compositions_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="calculate_moon_value" 
                       name="calculate_moon_value" 
                       value="1"
                       {{ old('calculate_moon_value', $settings->calculate_moon_value ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="calculate_moon_value">
                    <i class="fas fa-calculator"></i>
                    <strong>{{ trans('mining-manager::settings.calculate_moon_value') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.calculate_moon_value_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="notify_extraction_ready" 
                       name="notify_extraction_ready" 
                       value="1"
                       {{ old('notify_extraction_ready', $settings->notify_extraction_ready ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="notify_extraction_ready">
                    <i class="fas fa-bell"></i>
                    <strong>{{ trans('mining-manager::settings.notify_extraction_ready') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.notify_extraction_ready_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="extraction_notification_hours">
                    <i class="fas fa-clock"></i>
                    {{ trans('mining-manager::settings.extraction_notification_hours') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('extraction_notification_hours') is-invalid @enderror" 
                           id="extraction_notification_hours" 
                           name="extraction_notification_hours" 
                           value="{{ old('extraction_notification_hours', $settings->extraction_notification_hours ?? 24) }}"
                           min="1" 
                           max="168">
                    <div class="input-group-append">
                        <span class="input-group-text">{{ trans('mining-manager::settings.hours') }}</span>
                    </div>
                    @error('extraction_notification_hours')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.extraction_notification_hours_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Permissions & Access --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-user-shield"></i>
                {{ trans('mining-manager::settings.permissions_access') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="allow_public_stats" 
                       name="allow_public_stats" 
                       value="1"
                       {{ old('allow_public_stats', $settings->allow_public_stats ?? false) ? 'checked' : '' }}>
                <label class="custom-control-label" for="allow_public_stats">
                    <i class="fas fa-chart-pie"></i>
                    <strong>{{ trans('mining-manager::settings.allow_public_stats') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.allow_public_stats_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="allow_member_leaderboard" 
                       name="allow_member_leaderboard" 
                       value="1"
                       {{ old('allow_member_leaderboard', $settings->allow_member_leaderboard ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="allow_member_leaderboard">
                    <i class="fas fa-trophy"></i>
                    <strong>{{ trans('mining-manager::settings.allow_member_leaderboard') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.allow_member_leaderboard_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="show_character_names" 
                       name="show_character_names" 
                       value="1"
                       {{ old('show_character_names', $settings->show_character_names ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="show_character_names">
                    <i class="fas fa-id-card"></i>
                    <strong>{{ trans('mining-manager::settings.show_character_names') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.show_character_names_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="allow_export_data" 
                       name="allow_export_data" 
                       value="1"
                       {{ old('allow_export_data', $settings->allow_export_data ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="allow_export_data">
                    <i class="fas fa-download"></i>
                    <strong>{{ trans('mining-manager::settings.allow_export_data') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.allow_export_data_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Automation & Processing --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-robot"></i>
                {{ trans('mining-manager::settings.automation_processing') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="auto_process_ledger" 
                       name="auto_process_ledger" 
                       value="1"
                       {{ old('auto_process_ledger', $settings->auto_process_ledger ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="auto_process_ledger">
                    <i class="fas fa-sync"></i>
                    <strong>{{ trans('mining-manager::settings.auto_process_ledger') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.auto_process_ledger_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="ledger_processing_interval">
                    <i class="fas fa-hourglass-half"></i>
                    {{ trans('mining-manager::settings.ledger_processing_interval') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('ledger_processing_interval') is-invalid @enderror" 
                           id="ledger_processing_interval" 
                           name="ledger_processing_interval" 
                           value="{{ old('ledger_processing_interval', $settings->ledger_processing_interval ?? 60) }}"
                           min="15" 
                           max="1440">
                    <div class="input-group-append">
                        <span class="input-group-text">{{ trans('mining-manager::settings.minutes') }}</span>
                    </div>
                    @error('ledger_processing_interval')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.ledger_processing_interval_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="auto_calculate_taxes" 
                       name="auto_calculate_taxes" 
                       value="1"
                       {{ old('auto_calculate_taxes', $settings->auto_calculate_taxes ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="auto_calculate_taxes">
                    <i class="fas fa-calculator"></i>
                    <strong>{{ trans('mining-manager::settings.auto_calculate_taxes') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.auto_calculate_taxes_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="auto_generate_invoices" 
                       name="auto_generate_invoices" 
                       value="1"
                       {{ old('auto_generate_invoices', $settings->auto_generate_invoices ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="auto_generate_invoices">
                    <i class="fas fa-file-invoice"></i>
                    <strong>{{ trans('mining-manager::settings.auto_generate_invoices') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.auto_generate_invoices_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="verify_wallet_transactions" 
                       name="verify_wallet_transactions" 
                       value="1"
                       {{ old('verify_wallet_transactions', $settings->verify_wallet_transactions ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="verify_wallet_transactions">
                    <i class="fas fa-check-double"></i>
                    <strong>{{ trans('mining-manager::settings.verify_wallet_transactions') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.verify_wallet_transactions_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Data Retention --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-database"></i>
                {{ trans('mining-manager::settings.data_retention') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="warning-banner">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>{{ trans('mining-manager::settings.warning') }}:</strong>
                {{ trans('mining-manager::settings.data_retention_warning') }}
            </div>

            <div class="form-group">
                <label for="ledger_retention_days">
                    <i class="fas fa-book"></i>
                    {{ trans('mining-manager::settings.ledger_retention_days') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('ledger_retention_days') is-invalid @enderror" 
                           id="ledger_retention_days" 
                           name="ledger_retention_days" 
                           value="{{ old('ledger_retention_days', $settings->ledger_retention_days ?? 365) }}"
                           min="30" 
                           max="3650">
                    <div class="input-group-append">
                        <span class="input-group-text">{{ trans('mining-manager::settings.days') }}</span>
                    </div>
                    @error('ledger_retention_days')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.ledger_retention_days_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="tax_record_retention_days">
                    <i class="fas fa-coins"></i>
                    {{ trans('mining-manager::settings.tax_record_retention_days') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('tax_record_retention_days') is-invalid @enderror" 
                           id="tax_record_retention_days" 
                           name="tax_record_retention_days" 
                           value="{{ old('tax_record_retention_days', $settings->tax_record_retention_days ?? 730) }}"
                           min="90" 
                           max="3650">
                    <div class="input-group-append">
                        <span class="input-group-text">{{ trans('mining-manager::settings.days') }}</span>
                    </div>
                    @error('tax_record_retention_days')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.tax_record_retention_days_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="auto_cleanup_old_data" 
                       name="auto_cleanup_old_data" 
                       value="1"
                       {{ old('auto_cleanup_old_data', $settings->auto_cleanup_old_data ?? false) ? 'checked' : '' }}>
                <label class="custom-control-label" for="auto_cleanup_old_data">
                    <i class="fas fa-trash-alt"></i>
                    <strong>{{ trans('mining-manager::settings.auto_cleanup_old_data') }}</strong>
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.auto_cleanup_old_data_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="action-buttons">
        <div class="row">
            <div class="col-md-6">
                <button type="submit" class="btn btn-success btn-block">
                    <i class="fas fa-save"></i>
                    {{ trans('mining-manager::settings.save_changes') }}
                </button>
            </div>
            <div class="col-md-6">
                <a href="{{ route('mining-manager.settings.index') }}" 
                   class="btn btn-secondary btn-block">
                    <i class="fas fa-undo"></i>
                    {{ trans('mining-manager::settings.reset_form') }}
                </a>
            </div>
        </div>
    </div>

</form>
