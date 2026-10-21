@php
    $rates = $settings['tax_rates'];
    $exemptions = $settings['exemptions'];
    $payments = $settings['payments'];
@endphp

<form method="POST" action="{{ route('mining-manager.settings.update-billing') }}">
    @csrf

    <h4>
        <i class="fas fa-file-invoice-dollar"></i>
        Billing
    </h4>
    <hr>

    <p>
        How bills are worked out once a period ends, and how they are followed up. These apply to every bill, whichever tax profile it comes from;
        how often bills go out and the prefix on their codes are set per profile, under Tax profiles.
    </p>

    {{-- Small amounts --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-user-shield"></i>
                Small amounts
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Both are checked against an account's whole bill for the period, main and alts together, so spreading mining over alts does not slip under them.
            </p>

            <div class="custom-control custom-switch mb-2">
                <input type="checkbox" class="custom-control-input" id="exemption_enabled" name="exemption_enabled" value="1"
                       {{ old('exemption_enabled', $exemptions['enabled'] ?? false) ? 'checked' : '' }}>
                <label class="custom-control-label" for="exemption_enabled">
                    Do not bill an account whose tax is below the exemption threshold
                </label>
            </div>

            <div class="form-group">
                <label for="exemption_threshold">Exemption threshold</label>
                <div class="input-group">
                    <input type="number" class="form-control" id="exemption_threshold" name="exemption_threshold" min="0" step="100000"
                           value="{{ old('exemption_threshold', $exemptions['threshold'] ?? 1000000) }}">
                    <div class="input-group-append"><span class="input-group-text">ISK</span></div>
                </div>
                <small class="form-text text-muted">Checked first. An account below it gets no bill at all.</small>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="minimum_tax_amount">Minimum tax</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="minimum_tax_amount" name="minimum_tax_amount" min="0" step="100000"
                               value="{{ old('minimum_tax_amount', $payments['minimum_tax_amount'] ?? 1000000) }}">
                        <div class="input-group-append"><span class="input-group-text">ISK</span></div>
                    </div>
                    <small class="form-text text-muted">0 turns it off.</small>
                </div>
                <div class="form-group col-md-6">
                    <label for="minimum_tax_behavior">Below the minimum</label>
                    <select class="form-control" id="minimum_tax_behavior" name="minimum_tax_behavior">
                        <option value="exempt" {{ old('minimum_tax_behavior', $payments['minimum_tax_behavior'] ?? 'exempt') === 'exempt' ? 'selected' : '' }}>No bill</option>
                        <option value="enforce" {{ old('minimum_tax_behavior', $payments['minimum_tax_behavior'] ?? 'exempt') === 'enforce' ? 'selected' : '' }}>Bill the minimum</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Due dates and reminders --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-hourglass-end"></i>
                Due dates and reminders
            </h5>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="tax_payment_deadline_days">Days to pay</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="tax_payment_deadline_days" name="tax_payment_deadline_days" min="1" max="90"
                               value="{{ old('tax_payment_deadline_days', $rates['tax_payment_deadline_days'] ?? 7) }}">
                        <div class="input-group-append"><span class="input-group-text">days</span></div>
                    </div>
                    <small class="form-text text-muted">A bill falls due this many days after its period ends.</small>
                </div>
                <div class="form-group col-md-6">
                    <label for="payment_grace_period_hours">Wallet delay allowance</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="payment_grace_period_hours" name="payment_grace_period_hours" min="1" max="168"
                               value="{{ old('payment_grace_period_hours', $payments['grace_period_hours'] ?? 24) }}">
                        <div class="input-group-append"><span class="input-group-text">hours</span></div>
                    </div>
                    <small class="form-text text-muted">
                        A bill turns overdue this long after its due date. The EVE API can take hours to report a wallet transfer, and this keeps someone who paid on time from being shown as late.
                    </small>
                </div>
            </div>

            <div class="form-group">
                <label for="payment_overdue_paid_threshold_pct">Part-paid bills count as overdue below</label>
                <div class="input-group" style="max-width: 220px;">
                    <input type="number" class="form-control" id="payment_overdue_paid_threshold_pct" name="payment_overdue_paid_threshold_pct" min="0" max="100" step="1"
                           value="{{ old('payment_overdue_paid_threshold_pct', $payments['overdue_paid_threshold_pct'] ?? 95) }}">
                    <div class="input-group-append"><span class="input-group-text">% paid</span></div>
                </div>
                <small class="form-text text-muted">
                    A part-paid bill past its due date is treated as overdue unless at least this much of it is paid, so a token payment cannot hold it off for ever. 95% forgives rounding and price drift. 0 lets any payment at all count.
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="send_tax_reminders" name="send_tax_reminders" value="1"
                       {{ old('send_tax_reminders', $rates['send_tax_reminders'] ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="send_tax_reminders">Send reminders before a bill falls due</label>
            </div>
            <div class="form-group mt-2">
                <label for="tax_reminder_days">Remind this many days before</label>
                <div class="input-group" style="max-width: 220px;">
                    <input type="number" class="form-control" id="tax_reminder_days" name="tax_reminder_days" min="1" max="30"
                           value="{{ old('tax_reminder_days', $rates['tax_reminder_days'] ?? 3) }}">
                    <div class="input-group-append"><span class="input-group-text">days</span></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Payment codes --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-barcode"></i>
                Payment codes
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Every bill gets a code members put in the transfer reason when they pay. The prefix in front of it is set per tax profile.
            </p>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="tax_code_length">Code length</label>
                    <select class="form-control" id="tax_code_length" name="tax_code_length">
                        @foreach([6, 8, 10, 12] as $length)
                            <option value="{{ $length }}" {{ (int) old('tax_code_length', $rates['tax_code_length'] ?? 8) === $length ? 'selected' : '' }}>{{ $length }} characters</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">Codes already made keep their length and still match.</small>
                </div>
                <div class="form-group col-md-4">
                    <label for="grace_period_days">Grace days</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="grace_period_days" name="grace_period_days" min="0" max="365"
                               value="{{ old('grace_period_days', $exemptions['grace_period_days'] ?? 7) }}">
                        <div class="input-group-append"><span class="input-group-text">days</span></div>
                    </div>
                    <small class="form-text text-muted">
                        Part of how long a code stays valid. Bills from before due dates were recorded also fall due this many days after their period ends.
                    </small>
                </div>
                <div class="form-group col-md-4">
                    <label for="tax_code_expiration_buffer">Further validity</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="tax_code_expiration_buffer" name="tax_code_expiration_buffer" min="0" max="365"
                               value="{{ old('tax_code_expiration_buffer', $rates['tax_code_expiration_buffer'] ?? 30) }}">
                        <div class="input-group-append"><span class="input-group-text">days</span></div>
                    </div>
                    <small class="form-text text-muted">A code stays valid for the grace days plus these, from the day it is made.</small>
                </div>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="auto_generate_tax_codes" name="auto_generate_tax_codes" value="1"
                       {{ old('auto_generate_tax_codes', $rates['auto_generate_tax_codes'] ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="auto_generate_tax_codes">Show the Tax Codes tab on the tax pages</label>
                <small class="form-text text-muted">Codes are made for every bill either way.</small>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-mm-primary">
        <i class="fas fa-save"></i> Save billing
    </button>
</form>
