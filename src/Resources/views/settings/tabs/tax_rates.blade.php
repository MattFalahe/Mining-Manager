<form method="POST" action="{{ route('mining-manager.settings.update-tax-rates') }}">
    @csrf

    {{-- Hidden field to maintain corporation context --}}
    <input type="hidden" name="selected_corporation_id" value="{{ $selectedCorporationId ?? '' }}">

    <h4>
        <i class="fas fa-percent"></i>
        {{ trans('mining-manager::settings.tax_rate_settings') }}
        <span class="badge badge-info ml-2">Per-Corporation</span>
    </h4>
    <hr>

    {{-- Corporation Context Banner --}}
    @if(isset($selectedCorporationId) && $selectedCorporationId)
        <div class="alert alert-info">
            <i class="fas fa-building"></i>
            <strong>Corporation-Specific Settings</strong> - These tax rates will only apply to this corporation.
            Other corporations will use their own rates or fall back to global defaults.
        </div>
    @else
        <div class="alert alert-success">
            <i class="fas fa-globe"></i>
            <strong>Global Default Settings</strong> - These tax rates will apply to all corporations that don't have custom settings.
            To set corporation-specific rates, switch to a corporation in the General tab first.
        </div>
    @endif

    <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        <strong>{{ trans('mining-manager::settings.info') }}:</strong>
        {{ trans('mining-manager::settings.tax_rate_info') }}
    </div>

    {{-- Moon Ore Tax Rates by Rarity --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-moon"></i>
                Moon Ore Tax Rates by Rarity
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                <i class="fas fa-info-circle"></i>
                Set different tax rates for moon ores based on their rarity classification.
                Higher rarity ores are typically more valuable and can be taxed at higher rates.
            </p>

            <div class="row">
                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="moon_ore_r64">
                            <i class="fas fa-star" style="color: #FFD700;"></i>
                            R64 (Exceptional)
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('moon_ore_r64') is-invalid @enderror"
                                   id="moon_ore_r64"
                                   name="moon_ore_r64"
                                   value="{{ old('moon_ore_r64', $settings->moon_ore_r64 ?? 15) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Xenotime, Monazite, etc.</small>
                    </div>
                </div>

                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="moon_ore_r32">
                            <i class="fas fa-star" style="color: #C0C0C0;"></i>
                            R32 (Rare)
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('moon_ore_r32') is-invalid @enderror"
                                   id="moon_ore_r32"
                                   name="moon_ore_r32"
                                   value="{{ old('moon_ore_r32', $settings->moon_ore_r32 ?? 12) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Carnotite, Cinnabar, etc.</small>
                    </div>
                </div>

                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="moon_ore_r16">
                            <i class="fas fa-star" style="color: #CD7F32;"></i>
                            R16 (Uncommon)
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('moon_ore_r16') is-invalid @enderror"
                                   id="moon_ore_r16"
                                   name="moon_ore_r16"
                                   value="{{ old('moon_ore_r16', $settings->moon_ore_r16 ?? 10) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Chromite, Otavite, etc.</small>
                    </div>
                </div>

                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="moon_ore_r8">
                            <i class="fas fa-circle" style="color: #90EE90;"></i>
                            R8 (Common)
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('moon_ore_r8') is-invalid @enderror"
                                   id="moon_ore_r8"
                                   name="moon_ore_r8"
                                   value="{{ old('moon_ore_r8', $settings->moon_ore_r8 ?? 8) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Cobaltite, Euxenite, etc.</small>
                    </div>
                </div>

                <div class="col-md-4 col-lg-2">
                    <div class="form-group">
                        <label for="moon_ore_r4">
                            <i class="fas fa-circle" style="color: #A9A9A9;"></i>
                            R4 (Ubiquitous)
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('moon_ore_r4') is-invalid @enderror"
                                   id="moon_ore_r4"
                                   name="moon_ore_r4"
                                   value="{{ old('moon_ore_r4', $settings->moon_ore_r4 ?? 5) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Bitumens, Coesite, etc.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Regular Ore Type Tax Rates --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-percentage"></i>
                Ore Type Tax Rates
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="ore_tax">
                            <i class="fas fa-gem"></i>
                            Regular Ore
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('ore_tax') is-invalid @enderror"
                                   id="ore_tax"
                                   name="ore_tax"
                                   value="{{ old('ore_tax', $settings->ore_tax ?? 10) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Veldspar, Scordite, Bistot, Arkonor, etc.</small>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label for="ice_tax">
                            <i class="fas fa-snowflake"></i>
                            Ice
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('ice_tax') is-invalid @enderror"
                                   id="ice_tax"
                                   name="ice_tax"
                                   value="{{ old('ice_tax', $settings->ice_tax ?? 10) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Clear Icicle, Blue Ice, etc.</small>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label for="gas_tax">
                            <i class="fas fa-cloud"></i>
                            Gas
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('gas_tax') is-invalid @enderror"
                                   id="gas_tax"
                                   name="gas_tax"
                                   value="{{ old('gas_tax', $settings->gas_tax ?? 10) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Fullerites, Booster Gases (always raw value)</small>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label for="abyssal_ore_tax">
                            <i class="fas fa-skull"></i>
                            Abyssal Ore
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('abyssal_ore_tax') is-invalid @enderror"
                                   id="abyssal_ore_tax"
                                   name="abyssal_ore_tax"
                                   value="{{ old('abyssal_ore_tax', $settings->abyssal_ore_tax ?? 15) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Rare ores from Abyssal Deadspace</small>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label for="triglavian_ore_tax">
                            <i class="fas fa-radiation"></i>
                            Triglavian Ore
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   class="form-control @error('triglavian_ore_tax') is-invalid @enderror"
                                   id="triglavian_ore_tax"
                                   name="triglavian_ore_tax"
                                   value="{{ old('triglavian_ore_tax', $settings->triglavian_ore_tax ?? 10) }}"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Pochven/Triglavian space ores</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Guest Miner Tax Rates - moved to General Settings (tied to Moon Owner Corporation) --}}

    {{-- Tax Exemption Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-user-shield"></i>
                Tax Exemption Settings
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong>Small Miner Protection:</strong>
                Enable this to exempt miners whose total tax amount falls below a specified threshold.
                This encourages new players to try mining without being charged for very small amounts.
            </div>

            <div class="custom-control custom-switch mb-3">
                <input type="checkbox"
                       class="custom-control-input"
                       id="exemption_enabled"
                       name="exemption_enabled"
                       value="1"
                       {{ old('exemption_enabled', $settings->exemption_enabled ?? false) ? 'checked' : '' }}>
                <label class="custom-control-label" for="exemption_enabled">
                    <strong><i class="fas fa-toggle-on"></i> Enable Tax Exemption Threshold</strong>
                </label>
            </div>

            <div class="form-group">
                <label for="exemption_threshold">
                    <i class="fas fa-coins"></i>
                    Exemption Threshold Amount
                </label>
                <div class="input-group">
                    <input type="number"
                           class="form-control @error('exemption_threshold') is-invalid @enderror"
                           id="exemption_threshold"
                           name="exemption_threshold"
                           value="{{ old('exemption_threshold', $settings->exemption_threshold ?? 1000000) }}"
                           min="0"
                           step="100000">
                    <div class="input-group-append">
                        <span class="input-group-text">ISK</span>
                    </div>
                </div>
                <small class="form-text text-muted">
                    Miners whose <strong>total tax amount</strong> is below this threshold will not be charged.
                    Default: 1,000,000 ISK (1M ISK)
                </small>
            </div>

            <hr class="border-secondary my-3">
            <h6 class="text-info"><i class="fas fa-coins"></i> Minimum Tax Amount</h6>
            <small class="text-muted d-block mb-3">
                Control what happens when a miner's calculated tax is very small but above the exemption threshold.
            </small>

            <div class="form-group">
                <label for="minimum_tax_amount">
                    <i class="fas fa-money-bill-wave"></i>
                    Minimum Tax Amount
                </label>
                <div class="input-group">
                    <input type="number"
                           class="form-control @error('minimum_tax_amount') is-invalid @enderror"
                           id="minimum_tax_amount"
                           name="minimum_tax_amount"
                           value="{{ old('minimum_tax_amount', $settings->minimum_tax_amount ?? 1000000) }}"
                           min="0"
                           step="100000">
                    <div class="input-group-append">
                        <span class="input-group-text">ISK</span>
                    </div>
                </div>
                <small class="form-text text-muted">
                    Set to 0 to disable minimum tax. Default: 1,000,000 ISK (1M ISK)
                </small>
                @error('minimum_tax_amount')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="minimum_tax_behavior">
                    <i class="fas fa-sliders-h"></i>
                    Behavior When Tax is Below Minimum
                </label>
                <select class="form-control @error('minimum_tax_behavior') is-invalid @enderror"
                        id="minimum_tax_behavior"
                        name="minimum_tax_behavior">
                    <option value="exempt" {{ old('minimum_tax_behavior', $settings->minimum_tax_behavior ?? 'exempt') == 'exempt' ? 'selected' : '' }}>
                        No tax — Ignore entirely (do not generate invoice)
                    </option>
                    <option value="enforce" {{ old('minimum_tax_behavior', $settings->minimum_tax_behavior ?? 'exempt') == 'enforce' ? 'selected' : '' }}>
                        Enforce minimum — Raise to minimum amount
                    </option>
                </select>
                <small class="form-text text-muted">
                    <strong>No tax:</strong> Tax below minimum is treated as zero — no invoice is generated.<br>
                    <strong>Enforce minimum:</strong> Tax below minimum is raised to the minimum amount.
                </small>
                @error('minimum_tax_behavior')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <hr class="border-secondary my-3">

            <div class="form-group">
                <label for="grace_period_days">
                    <i class="fas fa-calendar-times"></i>
                    Grace Period Before Overdue
                </label>
                <div class="input-group">
                    <input type="number"
                           class="form-control @error('grace_period_days') is-invalid @enderror"
                           id="grace_period_days"
                           name="grace_period_days"
                           value="{{ old('grace_period_days', $settings->grace_period_days ?? 7) }}"
                           min="1"
                           max="30">
                    <div class="input-group-append">
                        <span class="input-group-text">Days</span>
                    </div>
                </div>
                <small class="form-text text-muted">
                    Number of days after tax deadline before marking tax as overdue. Default: 7 days
                </small>
            </div>
        </div>
    </div>

    {{-- Tax Selector (What to Tax) --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-filter"></i>
                Tax Selector - What to Tax
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                <i class="fas fa-info-circle"></i>
                Select which ore types should be subject to taxation. Disabled ore types will not be taxed.
            </p>

            <div class="row">
                <div class="col-md-6">
                    <h6><i class="fas fa-moon"></i> Moon Ore Options</h6>
                    @php
                        // Determine default moon ore taxing mode
                        $defaultMoonTaxing = 'all'; // default
                        if (isset($settings->only_corp_moon_ore) && $settings->only_corp_moon_ore) {
                            $defaultMoonTaxing = 'corp';
                        } elseif (isset($settings->no_moon_ore) && $settings->no_moon_ore) {
                            $defaultMoonTaxing = 'none';
                        } elseif (isset($settings->all_moon_ore) && !$settings->all_moon_ore) {
                            $defaultMoonTaxing = 'none';
                        }
                        $selectedMoonTaxing = old('moon_ore_taxing', $defaultMoonTaxing);
                    @endphp
                    <div class="custom-control custom-radio mb-2">
                        <input type="radio"
                               id="moon_tax_all"
                               name="moon_ore_taxing"
                               value="all"
                               class="custom-control-input"
                               {{ $selectedMoonTaxing == 'all' ? 'checked' : '' }}>
                        <label class="custom-control-label" for="moon_tax_all">
                            <strong>Tax All Moon Ore</strong><br>
                            <small class="text-muted">Tax moon ore from personal mining ledger AND corporation mining observers</small>
                        </label>
                    </div>
                    <div class="custom-control custom-radio mb-2">
                        <input type="radio"
                               id="moon_tax_corp"
                               name="moon_ore_taxing"
                               value="corp"
                               class="custom-control-input"
                               {{ $selectedMoonTaxing == 'corp' ? 'checked' : '' }}>
                        <label class="custom-control-label" for="moon_tax_corp">
                            <strong>Tax Only Corporation Moon Ore</strong><br>
                            <small class="text-muted">Only tax moon ore mined at YOUR corporation's moon mining structures</small>
                        </label>
                    </div>
                    <div class="custom-control custom-radio mb-3">
                        <input type="radio"
                               id="moon_tax_none"
                               name="moon_ore_taxing"
                               value="none"
                               class="custom-control-input"
                               {{ $selectedMoonTaxing == 'none' ? 'checked' : '' }}>
                        <label class="custom-control-label" for="moon_tax_none">
                            <strong>Don't Tax Moon Ore</strong><br>
                            <small class="text-muted">Moon ore mining is not taxed</small>
                        </label>
                    </div>
                </div>

                <div class="col-md-6">
                    <h6><i class="fas fa-check-square"></i> Other Ore Types</h6>
                    <div class="custom-control custom-switch mb-2">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="tax_regular_ore"
                               name="tax_regular_ore"
                               value="1"
                               {{ old('tax_regular_ore', $settings->tax_regular_ore ?? true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="tax_regular_ore">
                            <i class="fas fa-gem"></i> Tax Regular Ore
                        </label>
                    </div>
                    <div class="custom-control custom-switch mb-2">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="tax_ice"
                               name="tax_ice"
                               value="1"
                               {{ old('tax_ice', $settings->tax_ice ?? true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="tax_ice">
                            <i class="fas fa-snowflake"></i> Tax Ice
                        </label>
                    </div>
                    <div class="custom-control custom-switch mb-2">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="tax_gas"
                               name="tax_gas"
                               value="1"
                               {{ old('tax_gas', $settings->tax_gas ?? false) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="tax_gas">
                            <i class="fas fa-cloud"></i> Tax Gas (always valued at raw gas price)
                        </label>
                    </div>
                    <div class="custom-control custom-switch mb-2">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="tax_abyssal_ore"
                               name="tax_abyssal_ore"
                               value="1"
                               {{ old('tax_abyssal_ore', $settings->tax_abyssal_ore ?? true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="tax_abyssal_ore">
                            <i class="fas fa-skull"></i> Tax Abyssal Ore
                        </label>
                    </div>
                    <div class="custom-control custom-switch mb-2">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="tax_triglavian_ore"
                               name="tax_triglavian_ore"
                               value="1"
                               {{ old('tax_triglavian_ore', $settings->tax_triglavian_ore ?? true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="tax_triglavian_ore">
                            <i class="fas fa-radiation"></i> Tax Triglavian Ore
                        </label>
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
                <input type="hidden" name="tax_payment_method" value="wallet">
                <div class="alert alert-info mb-0">
                    <i class="fas fa-wallet"></i>
                    <strong>{{ trans('mining-manager::settings.wallet_method') }}</strong> &mdash;
                    {{ trans('mining-manager::settings.wallet_method_desc') }}
                </div>
            </div>

            <div class="form-group mt-3">
                <label for="tax_wallet_division">
                    <i class="fas fa-wallet"></i>
                    {{ trans('mining-manager::settings.tax_wallet_division') }}
                </label>
                @php
                    $currentDivision = $settings->tax_wallet_division ?? 1;
                    // Backwards compatibility: convert 1000-1007 to 1-7
                    if ($currentDivision >= 1000) {
                        $currentDivision = $currentDivision - 999;
                    }
                @endphp
                <select class="form-control @error('tax_wallet_division') is-invalid @enderror"
                        id="tax_wallet_division"
                        name="tax_wallet_division">
                    @foreach($walletDivisions as $divId => $divName)
                        <option value="{{ $divId }}" {{ $currentDivision == $divId ? 'selected' : '' }}>
                            {{ $divName }}
                        </option>
                    @endforeach
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
