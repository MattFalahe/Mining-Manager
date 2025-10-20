<form method="POST" action="{{ route('mining-manager.settings.update') }}">
    @csrf
    @method('PUT')
    
    <h4>
        <i class="fas fa-percent"></i>
        {{ trans('mining-manager::settings.tax_rate_settings') }}
    </h4>
    <hr>

    <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        <strong>{{ trans('mining-manager::settings.info') }}:</strong>
        {{ trans('mining-manager::settings.tax_rate_info') }}
    </div>

    {{-- Default Tax Rates --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-percentage"></i>
                {{ trans('mining-manager::settings.default_tax_rates') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="default_ore_tax">
                            <i class="fas fa-gem"></i>
                            {{ trans('mining-manager::settings.ore_tax_rate') }}
                        </label>
                        <div class="input-group">
                            <input type="number" 
                                   class="form-control @error('default_ore_tax') is-invalid @enderror" 
                                   id="default_ore_tax" 
                                   name="default_ore_tax" 
                                   value="{{ old('default_ore_tax', $settings->default_ore_tax ?? 5) }}"
                                   min="0" 
                                   max="100" 
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('default_ore_tax')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <small class="form-text text-muted">
                            {{ trans('mining-manager::settings.ore_tax_rate_help') }}
                        </small>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label for="default_ice_tax">
                            <i class="fas fa-snowflake"></i>
                            {{ trans('mining-manager::settings.ice_tax_rate') }}
                        </label>
                        <div class="input-group">
                            <input type="number" 
                                   class="form-control @error('default_ice_tax') is-invalid @enderror" 
                                   id="default_ice_tax" 
                                   name="default_ice_tax" 
                                   value="{{ old('default_ice_tax', $settings->default_ice_tax ?? 5) }}"
                                   min="0" 
                                   max="100" 
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('default_ice_tax')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <small class="form-text text-muted">
                            {{ trans('mining-manager::settings.ice_tax_rate_help') }}
                        </small>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label for="default_gas_tax">
                            <i class="fas fa-cloud"></i>
                            {{ trans('mining-manager::settings.gas_tax_rate') }}
                        </label>
                        <div class="input-group">
                            <input type="number" 
                                   class="form-control @error('default_gas_tax') is-invalid @enderror" 
                                   id="default_gas_tax" 
                                   name="default_gas_tax" 
                                   value="{{ old('default_gas_tax', $settings->default_gas_tax ?? 5) }}"
                                   min="0" 
                                   max="100" 
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('default_gas_tax')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <small class="form-text text-muted">
                            {{ trans('mining-manager::settings.gas_tax_rate_help') }}
                        </small>
                    </div>
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="default_moon_tax">
                            <i class="fas fa-moon"></i>
                            {{ trans('mining-manager::settings.moon_tax_rate') }}
                        </label>
                        <div class="input-group">
                            <input type="number" 
                                   class="form-control @error('default_moon_tax') is-invalid @enderror" 
                                   id="default_moon_tax" 
                                   name="default_moon_tax" 
                                   value="{{ old('default_moon_tax', $settings->default_moon_tax ?? 10) }}"
                                   min="0" 
                                   max="100" 
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('default_moon_tax')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <small class="form-text text-muted">
                            {{ trans('mining-manager::settings.moon_tax_rate_help') }}
                        </small>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-group">
                        <label for="default_mercoxit_tax">
                            <i class="fas fa-radiation"></i>
                            {{ trans('mining-manager::settings.mercoxit_tax_rate') }}
                        </label>
                        <div class="input-group">
                            <input type="number" 
                                   class="form-control @error('default_mercoxit_tax') is-invalid @enderror" 
                                   id="default_mercoxit_tax" 
                                   name="default_mercoxit_tax" 
                                   value="{{ old('default_mercoxit_tax', $settings->default_mercoxit_tax ?? 5) }}"
                                   min="0" 
                                   max="100" 
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                            @error('default_mercoxit_tax')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <small class="form-text text-muted">
                            {{ trans('mining-manager::settings.mercoxit_tax_rate_help') }}
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Tax Payment Method --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-money-check-alt"></i>
                {{ trans('mining-manager::settings.tax_payment_method') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label>
                    <i class="fas fa-hand-holding-usd"></i>
                    {{ trans('mining-manager::settings.payment_method') }}
                </label>
                <div class="row">
                    <div class="col-md-6">
                        <div class="custom-control custom-radio">
                            <input type="radio" 
                                   id="payment_contract" 
                                   name="tax_payment_method" 
                                   value="contract" 
                                   class="custom-control-input"
                                   {{ old('tax_payment_method', $settings->tax_payment_method ?? 'contract') == 'contract' ? 'checked' : '' }}>
                            <label class="custom-control-label" for="payment_contract">
                                <strong>{{ trans('mining-manager::settings.contract_method') }}</strong>
                                <br>
                                <small class="text-muted">
                                    {{ trans('mining-manager::settings.contract_method_desc') }}
                                </small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="custom-control custom-radio">
                            <input type="radio" 
                                   id="payment_wallet" 
                                   name="tax_payment_method" 
                                   value="wallet" 
                                   class="custom-control-input"
                                   {{ old('tax_payment_method', $settings->tax_payment_method ?? '') == 'wallet' ? 'checked' : '' }}>
                            <label class="custom-control-label" for="payment_wallet">
                                <strong>{{ trans('mining-manager::settings.wallet_method') }}</strong>
                                <br>
                                <small class="text-muted">
                                    {{ trans('mining-manager::settings.wallet_method_desc') }}
                                </small>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group mt-3">
                <label for="tax_wallet_division">
                    <i class="fas fa-wallet"></i>
                    {{ trans('mining-manager::settings.tax_wallet_division') }}
                </label>
                <select class="form-control @error('tax_wallet_division') is-invalid @enderror" 
                        id="tax_wallet_division" 
                        name="tax_wallet_division">
                    <option value="1000" {{ ($settings->tax_wallet_division ?? '1000') == '1000' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.master_wallet') }} (1000)
                    </option>
                    <option value="1001" {{ ($settings->tax_wallet_division ?? '') == '1001' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.division_1') }} (1001)
                    </option>
                    <option value="1002" {{ ($settings->tax_wallet_division ?? '') == '1002' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.division_2') }} (1002)
                    </option>
                    <option value="1003" {{ ($settings->tax_wallet_division ?? '') == '1003' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.division_3') }} (1003)
                    </option>
                    <option value="1004" {{ ($settings->tax_wallet_division ?? '') == '1004' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.division_4') }} (1004)
                    </option>
                    <option value="1005" {{ ($settings->tax_wallet_division ?? '') == '1005' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.division_5') }} (1005)
                    </option>
                    <option value="1006" {{ ($settings->tax_wallet_division ?? '') == '1006' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.division_6') }} (1006)
                    </option>
                    <option value="1007" {{ ($settings->tax_wallet_division ?? '') == '1007' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.division_7') }} (1007)
                    </option>
                </select>
                @error('tax_wallet_division')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.tax_wallet_division_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Tax Code Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-barcode"></i>
                {{ trans('mining-manager::settings.tax_code_settings') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="tax_code_prefix">
                    <i class="fas fa-tag"></i>
                    {{ trans('mining-manager::settings.tax_code_prefix') }}
                </label>
                <input type="text" 
                       class="form-control @error('tax_code_prefix') is-invalid @enderror" 
                       id="tax_code_prefix" 
                       name="tax_code_prefix" 
                       value="{{ old('tax_code_prefix', $settings->tax_code_prefix ?? 'TAX-') }}"
                       maxlength="10">
                @error('tax_code_prefix')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.tax_code_prefix_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="tax_code_length">
                    <i class="fas fa-ruler"></i>
                    {{ trans('mining-manager::settings.tax_code_length') }}
                </label>
                <select class="form-control @error('tax_code_length') is-invalid @enderror" 
                        id="tax_code_length" 
                        name="tax_code_length">
                    <option value="6" {{ ($settings->tax_code_length ?? 8) == 6 ? 'selected' : '' }}>6</option>
                    <option value="8" {{ ($settings->tax_code_length ?? 8) == 8 ? 'selected' : '' }}>8</option>
                    <option value="10" {{ ($settings->tax_code_length ?? 8) == 10 ? 'selected' : '' }}>10</option>
                    <option value="12" {{ ($settings->tax_code_length ?? 8) == 12 ? 'selected' : '' }}>12</option>
                </select>
                @error('tax_code_length')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.tax_code_length_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="auto_generate_tax_codes" 
                       name="auto_generate_tax_codes" 
                       value="1"
                       {{ old('auto_generate_tax_codes', $settings->auto_generate_tax_codes ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="auto_generate_tax_codes">
                    <i class="fas fa-magic"></i>
                    {{ trans('mining-manager::settings.auto_generate_tax_codes') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.auto_generate_tax_codes_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Tax Period Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-calendar-alt"></i>
                {{ trans('mining-manager::settings.tax_period_settings') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="tax_calculation_period">
                    <i class="fas fa-calendar-check"></i>
                    {{ trans('mining-manager::settings.tax_calculation_period') }}
                </label>
                <select class="form-control @error('tax_calculation_period') is-invalid @enderror" 
                        id="tax_calculation_period" 
                        name="tax_calculation_period">
                    <option value="monthly" {{ ($settings->tax_calculation_period ?? 'monthly') == 'monthly' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.monthly') }}
                    </option>
                    <option value="weekly" {{ ($settings->tax_calculation_period ?? '') == 'weekly' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.weekly') }}
                    </option>
                    <option value="biweekly" {{ ($settings->tax_calculation_period ?? '') == 'biweekly' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.biweekly') }}
                    </option>
                </select>
                @error('tax_calculation_period')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.tax_calculation_period_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="tax_payment_deadline_days">
                    <i class="fas fa-hourglass-end"></i>
                    {{ trans('mining-manager::settings.tax_payment_deadline') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('tax_payment_deadline_days') is-invalid @enderror" 
                           id="tax_payment_deadline_days" 
                           name="tax_payment_deadline_days" 
                           value="{{ old('tax_payment_deadline_days', $settings->tax_payment_deadline_days ?? 7) }}"
                           min="1" 
                           max="30">
                    <div class="input-group-append">
                        <span class="input-group-text">{{ trans('mining-manager::settings.days') }}</span>
                    </div>
                    @error('tax_payment_deadline_days')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.tax_payment_deadline_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="send_tax_reminders" 
                       name="send_tax_reminders" 
                       value="1"
                       {{ old('send_tax_reminders', $settings->send_tax_reminders ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="send_tax_reminders">
                    <i class="fas fa-bell"></i>
                    {{ trans('mining-manager::settings.send_tax_reminders') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.send_tax_reminders_help') }}
                </small>
            </div>

            <div class="form-group mt-3">
                <label for="tax_reminder_days">
                    <i class="fas fa-calendar-day"></i>
                    {{ trans('mining-manager::settings.tax_reminder_days') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('tax_reminder_days') is-invalid @enderror" 
                           id="tax_reminder_days" 
                           name="tax_reminder_days" 
                           value="{{ old('tax_reminder_days', $settings->tax_reminder_days ?? 3) }}"
                           min="1" 
                           max="30">
                    <div class="input-group-append">
                        <span class="input-group-text">{{ trans('mining-manager::settings.days') }}</span>
                    </div>
                    @error('tax_reminder_days')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.tax_reminder_days_help') }}
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
