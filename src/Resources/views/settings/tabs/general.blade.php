<form method="POST" action="{{ route('mining-manager.settings.update-general') }}">
    @csrf

    {{-- Hidden field to track selected corporation context --}}
    <input type="hidden" name="selected_corporation_id" id="selected_corporation_id" value="{{ $selectedCorporationId ?? '' }}">

    <h4>
        <i class="fas fa-sliders-h"></i>
        {{ trans('mining-manager::settings.general_settings') }}
        <span class="badge badge-success ml-2">Global</span>
    </h4>
    <hr>

    <div class="alert alert-success">
        <i class="fas fa-globe"></i>
        <strong>Global Settings</strong> - These settings apply to ALL corporations and cannot be overridden per-corporation.
    </div>

    {{-- Primary Corporation Setup --}}
    <div class="card bg-dark mb-3 border-primary">
        <div class="card-header bg-primary">
            <h5 class="card-title mb-0">
                <i class="fas fa-building"></i>
                Primary Corporation Setup
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle"></i>
                <strong>Important:</strong> Set up your primary corporation that owns moons and structures.
                This determines which wallet is checked for payments and which moons are tracked.
            </div>

            <div class="row">
                <div class="col-md-6">
                    {{-- Moon Owner Corporation --}}
                    <div class="form-group">
                        <label for="moon_owner_corporation_id">
                            <i class="fas fa-moon"></i>
                            Moon/Structure Owner Corporation <span class="text-danger">*</span>
                        </label>
                        <select class="form-control @error('moon_owner_corporation_id') is-invalid @enderror"
                                id="moon_owner_corporation_id"
                                name="moon_owner_corporation_id"
                                required>
                            <option value="">-- Select Corporation --</option>
                            @if(isset($corporations))
                                @foreach($corporations as $corp)
                                    <option value="{{ $corp->corporation_id }}"
                                        {{ (old('moon_owner_corporation_id', $settings->moon_owner_corporation_id ?? '') == $corp->corporation_id) ? 'selected' : '' }}>
                                        [{{ $corp->ticker }}] {{ $corp->name }}
                                    </option>
                                @endforeach
                            @endif
                        </select>
                        @error('moon_owner_corporation_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <small class="form-text text-muted">
                            The corporation that owns your moons and structures. Wallet payments are verified against this corp.
                        </small>
                    </div>
                </div>

                <div class="col-md-6">
                    {{-- Currently Configured --}}
                    @if(!empty($settings->moon_owner_corporation_id))
                    <div class="form-group">
                        <label><i class="fas fa-check-circle text-success"></i> Currently Active</label>
                        <div class="alert alert-success mb-0">
                            @php
                                $moonOwnerCorp = $corporations->firstWhere('corporation_id', $settings->moon_owner_corporation_id);
                            @endphp
                            @if($moonOwnerCorp)
                                <span class="h5">[{{ $moonOwnerCorp->ticker }}] {{ $moonOwnerCorp->name }}</span>
                                <br>
                                <small class="text-muted">ID: {{ $moonOwnerCorp->corporation_id }}</small>
                            @else
                                <span class="text-warning">ID: {{ $settings->moon_owner_corporation_id }} (Not found)</span>
                            @endif
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Corporation Context Switcher for Tax Rates --}}
            <hr>
            <div class="form-group mb-0">
                <label for="corporation_id">
                    <i class="fas fa-exchange-alt"></i>
                    Switch Corporation Context (for Tax Rates tab)
                </label>
                <div class="input-group">
                    <select class="form-control @error('corporation_id') is-invalid @enderror"
                            id="corporation_id"
                            name="corporation_id">
                        <option value="">-- Global Settings (Default) --</option>
                        @if(isset($corporations))
                            @foreach($corporations as $corp)
                                <option value="{{ $corp->corporation_id }}"
                                    {{ (old('corporation_id', $selectedCorporationId ?? '') == $corp->corporation_id) ? 'selected' : '' }}>
                                    [{{ $corp->ticker }}] {{ $corp->name }}
                                </option>
                            @endforeach
                        @endif
                    </select>
                    <div class="input-group-append">
                        <button type="button" class="btn btn-info" id="switchCorporationBtn">
                            <i class="fas fa-sync-alt"></i> Switch
                        </button>
                    </div>
                </div>
                <small class="form-text text-muted">
                    Select a corporation to configure its specific tax rates in the Tax Rates tab. Leave empty to edit global defaults.
                </small>
            </div>

            {{-- Hidden fields for corporation name and ticker (auto-filled by controller) --}}
            <input type="hidden" name="corporation_name" value="{{ old('corporation_name', $settings->corporation_name ?? '') }}">
            <input type="hidden" name="corporation_ticker" value="{{ old('corporation_ticker', $settings->corporation_ticker ?? '') }}">
        </div>
    </div>

    {{-- Time & Date Information --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-clock"></i>
                Time & Date Settings
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-0">
                <h6><i class="fas fa-info-circle"></i> UTC (EVE Time) is Always Used</h6>
                <p class="mb-2">
                    All tax calculations use <strong>UTC (EVE Time)</strong> exclusively to ensure consistency with:
                </p>
                <ul class="mb-0">
                    <li>Moon rental bills from your alliance</li>
                    <li>Corporation mining ledger timestamps from the EVE API</li>
                    <li>Tax month boundaries aligned with EVE's calendar</li>
                </ul>
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
            <div class="row">
                <div class="col-md-6">
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
                </div>

                <div class="col-md-6">
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
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
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
                </div>

                <div class="col-md-6">
                    <div class="custom-control custom-switch">
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
        </div>
    </div>

    {{-- Payment Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-money-check-alt"></i>
                {{ trans('mining-manager::settings.payment_settings') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="payment_match_tolerance">
                            <i class="fas fa-balance-scale"></i>
                            {{ trans('mining-manager::settings.payment_match_tolerance') }}
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('payment_match_tolerance') is-invalid @enderror"
                                   id="payment_match_tolerance"
                                   name="payment_match_tolerance"
                                   value="{{ old('payment_match_tolerance', $settings->payment_match_tolerance ?? 100) }}"
                                   min="0"
                                   max="100000000"
                                   step="1000">
                            <div class="input-group-append">
                                <span class="input-group-text">ISK</span>
                            </div>
                        </div>
                        @error('payment_match_tolerance')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <small class="form-text text-muted">
                            {{ trans('mining-manager::settings.payment_match_tolerance_help') }}
                        </small>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label for="payment_grace_period_hours">
                            <i class="fas fa-hourglass-half"></i>
                            {{ trans('mining-manager::settings.payment_grace_period') }}
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('payment_grace_period_hours') is-invalid @enderror"
                                   id="payment_grace_period_hours"
                                   name="payment_grace_period_hours"
                                   value="{{ old('payment_grace_period_hours', $settings->payment_grace_period_hours ?? 24) }}"
                                   min="1"
                                   max="168">
                            <div class="input-group-append">
                                <span class="input-group-text">{{ trans('mining-manager::settings.hours') }}</span>
                            </div>
                        </div>
                        @error('payment_grace_period_hours')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <small class="form-text text-muted">
                            {{ trans('mining-manager::settings.payment_grace_period_help') }}
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Notification Settings (Moved) --}}
    <div class="card bg-dark mb-3">
        <div class="card-body text-center text-muted py-4">
            <i class="fas fa-bell fa-2x mb-2 d-block"></i>
            <p class="mb-0">Notification settings have moved to the dedicated
            <a href="#" onclick="$('.nav-link[data-tab=notifications]').click(); return false;">
                <i class="fas fa-bell"></i> Notifications tab</a>.</p>
        </div>
    </div>

    {{-- Guest Miner Tax Rates (Global — tied to Moon Owner Corporation) --}}
    <div class="card bg-dark mb-3 border-info">
        <div class="card-header bg-info">
            <h5 class="card-title mb-0">
                <i class="fas fa-user-friends"></i>
                Guest Miner Tax Rates
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong>What are Guest Miners?</strong>
                Guest miners are characters who mine on your <strong>Moon Owner Corporation's</strong> structures but are not members of any configured corporation.
                These rates apply globally and are tied to the Moon Owner Corporation set above.
                Setting a rate to <strong>0%</strong> means guests pay <strong>no tax</strong> on that ore type.
            </div>

            {{-- Guest Moon Ore Rates --}}
            <h6 class="mb-3"><i class="fas fa-moon"></i> Guest Moon Ore Rates</h6>
            <div class="row mb-3">
                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="guest_moon_ore_r64">
                            <i class="fas fa-star" style="color: #FFD700;"></i> R64
                        </label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_moon_ore_r64" name="guest_moon_ore_r64"
                                   value="{{ old('guest_moon_ore_r64', $settings->guest_moon_ore_r64 ?? 0) }}"
                                   min="0" max="100" step="0.1">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="guest_moon_ore_r32">
                            <i class="fas fa-star" style="color: #C0C0C0;"></i> R32
                        </label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_moon_ore_r32" name="guest_moon_ore_r32"
                                   value="{{ old('guest_moon_ore_r32', $settings->guest_moon_ore_r32 ?? 0) }}"
                                   min="0" max="100" step="0.1">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="guest_moon_ore_r16">
                            <i class="fas fa-star" style="color: #CD7F32;"></i> R16
                        </label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_moon_ore_r16" name="guest_moon_ore_r16"
                                   value="{{ old('guest_moon_ore_r16', $settings->guest_moon_ore_r16 ?? 0) }}"
                                   min="0" max="100" step="0.1">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="guest_moon_ore_r8">
                            <i class="fas fa-certificate" style="color: #90EE90;"></i> R8
                        </label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_moon_ore_r8" name="guest_moon_ore_r8"
                                   value="{{ old('guest_moon_ore_r8', $settings->guest_moon_ore_r8 ?? 0) }}"
                                   min="0" max="100" step="0.1">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="guest_moon_ore_r4">
                            <i class="fas fa-circle" style="color: #808080;"></i> R4
                        </label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_moon_ore_r4" name="guest_moon_ore_r4"
                                   value="{{ old('guest_moon_ore_r4', $settings->guest_moon_ore_r4 ?? 0) }}"
                                   min="0" max="100" step="0.1">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Guest Regular Ore Rates --}}
            <hr>
            <h6 class="mb-3"><i class="fas fa-percentage"></i> Guest Regular Ore Rates</h6>
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="guest_ore_tax"><i class="fas fa-gem"></i> Regular Ore</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_ore_tax" name="guest_ore_tax"
                                   value="{{ old('guest_ore_tax', $settings->guest_ore_tax ?? 0) }}"
                                   min="0" max="100" step="0.1">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="guest_ice_tax"><i class="fas fa-snowflake"></i> Ice</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_ice_tax" name="guest_ice_tax"
                                   value="{{ old('guest_ice_tax', $settings->guest_ice_tax ?? 0) }}"
                                   min="0" max="100" step="0.1">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="guest_gas_tax"><i class="fas fa-cloud"></i> Gas</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_gas_tax" name="guest_gas_tax"
                                   value="{{ old('guest_gas_tax', $settings->guest_gas_tax ?? 0) }}"
                                   min="0" max="100" step="0.1">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="guest_abyssal_ore_tax"><i class="fas fa-skull"></i> Abyssal Ore</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_abyssal_ore_tax" name="guest_abyssal_ore_tax"
                                   value="{{ old('guest_abyssal_ore_tax', $settings->guest_abyssal_ore_tax ?? 0) }}"
                                   min="0" max="100" step="0.1">
                            <div class="input-group-append"><span class="input-group-text">%</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-warning mt-3 mb-0">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>0% = No Tax.</strong> Setting any guest rate to 0% means guests pay nothing for that ore type.
                Guest miners only appear via moon mining observer data — their character ledger mining (regular ore, ice, gas mined elsewhere) is never taxed.
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
