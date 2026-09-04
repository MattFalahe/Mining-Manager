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
                            The corporation that owns your moons/structures and runs the mining tax program. All tax invoices, theft detection, moon tracking, ledger data, and webhook notifications are scoped to this corporation — regardless of ore source (moon, belt, ice, gas).
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

            {{-- Auto-match toggle. When ON (default), the wallet listener
                 applies matched payments to taxes automatically as ESI
                 wallet updates arrive. When OFF, matches are detected
                 and listed on the wallet-verification page but the
                 operator must manually confirm before the tax row
                 updates. Useful for installs that want a human-review
                 step before any money moves on the books. --}}
            <div class="row">
                <div class="col-md-12">
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox"
                                   class="custom-control-input"
                                   id="payment_auto_match_payments"
                                   name="payment_auto_match_payments"
                                   value="1"
                                   {{ old('payment_auto_match_payments', $settings->auto_match_payments ?? true) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="payment_auto_match_payments">
                                <i class="fas fa-bolt"></i>
                                Auto-match wallet payments
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            When ON (default), the scheduled verification run applies
                            matched payments to invoices as soon as it finds them. When
                            OFF, matches are detected and listed on the Wallet
                            Verification page but require manual confirmation before any
                            invoice updates. Recommended for most installs to leave ON.
                        </small>
                    </div>
                </div>
            </div>

            {{-- Accept payments from any of a player's characters
                 (alt-aware match). When ON (default), MM credits a tax
                 payment if the tax code matches AND the paying character
                 shares a SeAT user_id with the taxed character — so a
                 player can settle their main's tax bill from any alt's
                 wallet. When OFF, strict per-character matching (the
                 pre-v2.0.2 behaviour). --}}
            <div class="row">
                <div class="col-md-12">
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox"
                                   class="custom-control-input"
                                   id="payment_accept_alt_characters"
                                   name="payment_accept_alt_characters"
                                   value="1"
                                   {{ old('payment_accept_alt_characters', $settings->accept_alt_characters ?? true) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="payment_accept_alt_characters">
                                <i class="fas fa-users"></i>
                                Accept payments from any of a player's characters
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            When ON (default), MM accepts a tax payment if the tax code
                            in the transaction description matches AND the paying character
                            shares a SeAT user with the taxed character (i.e. is an alt of
                            the same player). When OFF, the paying character must be
                            <em>exactly</em> the taxed character — strict pre-v2.0.2
                            behaviour. Alt payments are logged in
                            <code>laravel.log</code> with both the paying and taxed
                            character IDs for audit, so directors can reconcile after
                            the fact.
                        </small>
                    </div>
                </div>
            </div>

            {{-- What to do when a payment is bigger than the invoice it settles. --}}
            <div class="row">
                <div class="col-md-12">
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox"
                                   class="custom-control-input"
                                   id="payment_cascade_remainder"
                                   name="payment_cascade_remainder"
                                   value="1"
                                   {{ old('payment_cascade_remainder', $settings->cascade_remainder ?? true) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="payment_cascade_remainder">
                                <i class="fas fa-angle-double-right"></i>
                                Roll leftover payment onto the next unpaid invoice
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            When ON (default), a payment larger than the invoice it settles
                            keeps going: the remainder pays down that player's next-oldest
                            unpaid invoice, and so on until the money runs out. Covers the
                            common case of someone clearing three months in one transfer.
                            When OFF, a payment only ever touches the one invoice it was
                            matched to.
                        </small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox"
                                   class="custom-control-input"
                                   id="payment_hold_surplus_as_credit"
                                   name="payment_hold_surplus_as_credit"
                                   value="1"
                                   {{ old('payment_hold_surplus_as_credit', $settings->hold_surplus_as_credit ?? true) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="payment_hold_surplus_as_credit">
                                <i class="fas fa-piggy-bank"></i>
                                Hold surplus as credit against the next invoice
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            When ON (default), money still left over once every open
                            invoice is settled is parked against the paying character and
                            comes off their next invoice automatically. Held credit is
                            listed on the Wallet Verification page. When OFF, the surplus
                            is logged and discarded.
                        </small>
                    </div>
                </div>
            </div>

            {{-- Standing keyword for paying ahead of an invoice. Greyed out with
                 a banner when the feature is off, so the box does not look
                 broken or invite someone to type into a field that does
                 nothing. --}}
            @php $upfrontOn = (bool) ($settings->enable_upfront_payments ?? false); @endphp
            <div class="row">
                <div class="col-12">
                    @unless($upfrontOn)
                    <div class="alert alert-danger py-2">
                        <i class="fas fa-times-circle mr-1"></i>
                        <strong>Upfront payments are turned off.</strong>
                        Nothing below has any effect until you enable the feature under
                        Settings &rarr; Features &rarr; Upfront Payments.
                    </div>
                    @endunless
                </div>
                <div class="col-md-6" @unless($upfrontOn) style="opacity: 0.5;" @endunless>
                    <div class="form-group">
                        <label for="payment_upfront_keyword">
                            <i class="fas fa-hand-holding-usd"></i>
                            Upfront payment keyword
                            <span class="badge badge-info ml-1">{{ trans('mining-manager::settings.applies_to_all_corporations') }}</span>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="payment_upfront_keyword"
                               name="payment_upfront_keyword"
                               maxlength="32"
                               placeholder="MM-UPFRONT"
                               @unless($upfrontOn) disabled @endunless
                               value="{{ old('payment_upfront_keyword', $settings->upfront_keyword ?? 'MM-UPFRONT') }}">
                        <small class="form-text text-muted">
                            A standing keyword members can put in the transfer reason to pay
                            ahead, without waiting to be invoiced. Unlike a tax code it never
                            expires and is the same for everyone, so it can live in the corp
                            MOTD. The payment settles whatever they already owe, oldest first,
                            and the rest becomes account balance against future invoices.
                            Matching ignores case. <strong>Leave empty to turn the feature
                            off.</strong> It cannot overlap the tax code prefix, since both are
                            read from the same field.
                        </small>
                        <div class="alert alert-info py-2 mt-2 mb-0">
                            <i class="fas fa-globe mr-1"></i>
                            <strong>One keyword for every corporation.</strong>
                            This is stored globally, not per corporation, and is not
                            affected by the corporation context selected above. There is
                            one tax program reading one wallet, so a per-corporation
                            keyword would be saved somewhere the matcher never looks.
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label for="payment_overdue_paid_threshold_pct">
                            <i class="fas fa-percentage"></i>
                            Treat as overdue below (% paid)
                        </label>
                        <input type="number"
                               class="form-control"
                               id="payment_overdue_paid_threshold_pct"
                               name="payment_overdue_paid_threshold_pct"
                               min="0" max="100" step="1"
                               value="{{ old('payment_overdue_paid_threshold_pct', $settings->overdue_paid_threshold_pct ?? 95) }}">
                        <small class="form-text text-muted">
                            A part-paid invoice past its due date gets the overdue wording
                            unless at least this much of it is covered. Without it, a token
                            payment buys permanent immunity: 1m against a 1b invoice stays
                            "partial" forever, however late it gets. The default of 95%
                            forgives rounding and price drift, which is the only honest
                            reason to be slightly short. Set to <strong>0</strong> to
                            restore the old behaviour where any payment at all softened
                            the tone.
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
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="guest_triglavian_ore_tax"><i class="fas fa-radiation"></i> Triglavian Ore</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="guest_triglavian_ore_tax" name="guest_triglavian_ore_tax"
                                   value="{{ old('guest_triglavian_ore_tax', $settings->guest_triglavian_ore_tax ?? 0) }}"
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
