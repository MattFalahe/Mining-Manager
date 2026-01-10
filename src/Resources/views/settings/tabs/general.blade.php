<form method="POST" action="{{ route('mining-manager.settings.update-general') }}">
    @csrf
    
    <h4>
        <i class="fas fa-sliders-h"></i>
        {{ trans('mining-manager::settings.general_settings') }}
    </h4>
    <hr>

    <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        <strong>{{ trans('mining-manager::settings.info') }}:</strong>
        {{ trans('mining-manager::settings.general_info') }}
    </div>

    {{-- Corporation Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-building"></i>
                {{ trans('mining-manager::settings.corporation_settings') }}
            </h5>
        </div>
        <div class="card-body">
            {{-- Corporation Selection Dropdown --}}
            <div class="form-group">
                <label for="corporation_id">
                    <i class="fas fa-building"></i>
                    Corporation <span class="text-danger">*</span>
                </label>
                <select class="form-control @error('corporation_id') is-invalid @enderror" 
                        id="corporation_id" 
                        name="corporation_id"
                        required>
                    <option value="">-- Select Corporation --</option>
                    @if(isset($corporations))
                        @foreach($corporations as $corp)
                            <option value="{{ $corp->corporation_id }}" 
                                {{ (old('corporation_id', $settings['general']['corporation_id'] ?? '') == $corp->corporation_id) ? 'selected' : '' }}>
                                [{{ $corp->ticker }}] {{ $corp->name }}
                            </option>
                        @endforeach
                    @endif
                </select>
                @error('corporation_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    Select the corporation you want to manage with this plugin. All tax calculations and mining data will be filtered to this corporation.
                </small>
            </div>

            {{-- Display Currently Selected Corporation (Read-only Info) --}}
            @if(!empty($settings['general']['corporation_name']))
            <div class="alert alert-info">
                <strong><i class="fas fa-info-circle"></i> Current Corporation:</strong><br>
                <span class="h5">[{{ $settings['general']['corporation_ticker'] ?? '' }}] {{ $settings['general']['corporation_name'] }}</span>
                <br>
                <small class="text-muted">Corporation ID: {{ $settings['general']['corporation_id'] }}</small>
            </div>
            @endif

            {{-- Hidden fields for corporation name and ticker (auto-filled by controller) --}}
            <input type="hidden" name="corporation_name" value="{{ old('corporation_name', $settings['general']['corporation_name'] ?? '') }}">
            <input type="hidden" name="corporation_ticker" value="{{ old('corporation_ticker', $settings['general']['corporation_ticker'] ?? '') }}">
        </div>
    </div>

    {{-- Time Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-clock"></i>
                {{ trans('mining-manager::settings.time_settings') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="timezone">
                    <i class="fas fa-globe"></i>
                    {{ trans('mining-manager::settings.timezone') }}
                </label>
                <select class="form-control @error('timezone') is-invalid @enderror" 
                        id="timezone" 
                        name="timezone">
                    <option value="UTC" {{ ($settings->timezone ?? 'UTC') == 'UTC' ? 'selected' : '' }}>
                        UTC (EVE Time)
                    </option>
                    <option value="America/New_York" {{ ($settings->timezone ?? '') == 'America/New_York' ? 'selected' : '' }}>
                        Eastern Time (US)
                    </option>
                    <option value="America/Chicago" {{ ($settings->timezone ?? '') == 'America/Chicago' ? 'selected' : '' }}>
                        Central Time (US)
                    </option>
                    <option value="America/Denver" {{ ($settings->timezone ?? '') == 'America/Denver' ? 'selected' : '' }}>
                        Mountain Time (US)
                    </option>
                    <option value="America/Los_Angeles" {{ ($settings->timezone ?? '') == 'America/Los_Angeles' ? 'selected' : '' }}>
                        Pacific Time (US)
                    </option>
                    <option value="Europe/London" {{ ($settings->timezone ?? '') == 'Europe/London' ? 'selected' : '' }}>
                        London (GMT)
                    </option>
                    <option value="Europe/Paris" {{ ($settings->timezone ?? '') == 'Europe/Paris' ? 'selected' : '' }}>
                        Central Europe (CET)
                    </option>
                    <option value="Europe/Moscow" {{ ($settings->timezone ?? '') == 'Europe/Moscow' ? 'selected' : '' }}>
                        Moscow (MSK)
                    </option>
                    <option value="Asia/Tokyo" {{ ($settings->timezone ?? '') == 'Asia/Tokyo' ? 'selected' : '' }}>
                        Tokyo (JST)
                    </option>
                    <option value="Australia/Sydney" {{ ($settings->timezone ?? '') == 'Australia/Sydney' ? 'selected' : '' }}>
                        Sydney (AEST)
                    </option>
                </select>
                @error('timezone')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.timezone_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="date_format">
                    <i class="fas fa-calendar"></i>
                    {{ trans('mining-manager::settings.date_format') }}
                </label>
                <select class="form-control @error('date_format') is-invalid @enderror" 
                        id="date_format" 
                        name="date_format">
                    <option value="Y-m-d" {{ ($settings->date_format ?? 'Y-m-d') == 'Y-m-d' ? 'selected' : '' }}>
                        YYYY-MM-DD ({{ now()->format('Y-m-d') }})
                    </option>
                    <option value="m/d/Y" {{ ($settings->date_format ?? '') == 'm/d/Y' ? 'selected' : '' }}>
                        MM/DD/YYYY ({{ now()->format('m/d/Y') }})
                    </option>
                    <option value="d/m/Y" {{ ($settings->date_format ?? '') == 'd/m/Y' ? 'selected' : '' }}>
                        DD/MM/YYYY ({{ now()->format('d/m/Y') }})
                    </option>
                    <option value="d.m.Y" {{ ($settings->date_format ?? '') == 'd.m.Y' ? 'selected' : '' }}>
                        DD.MM.YYYY ({{ now()->format('d.m.Y') }})
                    </option>
                </select>
                @error('date_format')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.date_format_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="time_format">
                    <i class="fas fa-clock"></i>
                    {{ trans('mining-manager::settings.time_format') }}
                </label>
                <select class="form-control @error('time_format') is-invalid @enderror" 
                        id="time_format" 
                        name="time_format">
                    <option value="H:i:s" {{ ($settings->time_format ?? 'H:i:s') == 'H:i:s' ? 'selected' : '' }}>
                        24 Hour ({{ now()->format('H:i:s') }})
                    </option>
                    <option value="g:i:s A" {{ ($settings->time_format ?? '') == 'g:i:s A' ? 'selected' : '' }}>
                        12 Hour ({{ now()->format('g:i:s A') }})
                    </option>
                </select>
                @error('time_format')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.time_format_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Display Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-eye"></i>
                {{ trans('mining-manager::settings.display_settings') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="items_per_page">
                    <i class="fas fa-list"></i>
                    {{ trans('mining-manager::settings.items_per_page') }}
                </label>
                <select class="form-control @error('items_per_page') is-invalid @enderror" 
                        id="items_per_page" 
                        name="items_per_page">
                    <option value="10" {{ ($settings->items_per_page ?? 25) == 10 ? 'selected' : '' }}>10</option>
                    <option value="25" {{ ($settings->items_per_page ?? 25) == 25 ? 'selected' : '' }}>25</option>
                    <option value="50" {{ ($settings->items_per_page ?? 25) == 50 ? 'selected' : '' }}>50</option>
                    <option value="100" {{ ($settings->items_per_page ?? 25) == 100 ? 'selected' : '' }}>100</option>
                </select>
                @error('items_per_page')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.items_per_page_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="currency_decimals">
                    <i class="fas fa-calculator"></i>
                    {{ trans('mining-manager::settings.currency_decimals') }}
                </label>
                <select class="form-control @error('currency_decimals') is-invalid @enderror" 
                        id="currency_decimals" 
                        name="currency_decimals">
                    <option value="0" {{ ($settings->currency_decimals ?? 2) == 0 ? 'selected' : '' }}>
                        0 (1,234,567)
                    </option>
                    <option value="2" {{ ($settings->currency_decimals ?? 2) == 2 ? 'selected' : '' }}>
                        2 (1,234,567.89)
                    </option>
                    <option value="4" {{ ($settings->currency_decimals ?? 2) == 4 ? 'selected' : '' }}>
                        4 (1,234,567.8901)
                    </option>
                </select>
                @error('currency_decimals')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.currency_decimals_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="show_character_portraits" 
                       name="show_character_portraits" 
                       value="1"
                       {{ old('show_character_portraits', $settings->show_character_portraits ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="show_character_portraits">
                    <i class="fas fa-user-circle"></i>
                    {{ trans('mining-manager::settings.show_character_portraits') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.show_character_portraits_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mt-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="compact_mode" 
                       name="compact_mode" 
                       value="1"
                       {{ old('compact_mode', $settings->compact_mode ?? false) ? 'checked' : '' }}>
                <label class="custom-control-label" for="compact_mode">
                    <i class="fas fa-compress"></i>
                    {{ trans('mining-manager::settings.compact_mode') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.compact_mode_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Notification Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-bell"></i>
                {{ trans('mining-manager::settings.notification_settings') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="custom-control custom-switch">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="enable_notifications" 
                       name="enable_notifications" 
                       value="1"
                       {{ old('enable_notifications', $settings->enable_notifications ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="enable_notifications">
                    <i class="fas fa-bell"></i>
                    {{ trans('mining-manager::settings.enable_notifications') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.enable_notifications_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mt-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="notify_tax_due" 
                       name="notify_tax_due" 
                       value="1"
                       {{ old('notify_tax_due', $settings->notify_tax_due ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="notify_tax_due">
                    <i class="fas fa-coins"></i>
                    {{ trans('mining-manager::settings.notify_tax_due') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.notify_tax_due_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mt-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="notify_events" 
                       name="notify_events" 
                       value="1"
                       {{ old('notify_events', $settings->notify_events ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="notify_events">
                    <i class="fas fa-calendar-star"></i>
                    {{ trans('mining-manager::settings.notify_events') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.notify_events_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch mt-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="notify_moon_extractions" 
                       name="notify_moon_extractions" 
                       value="1"
                       {{ old('notify_moon_extractions', $settings->notify_moon_extractions ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="notify_moon_extractions">
                    <i class="fas fa-moon"></i>
                    {{ trans('mining-manager::settings.notify_moon_extractions') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.notify_moon_extractions_help') }}
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
