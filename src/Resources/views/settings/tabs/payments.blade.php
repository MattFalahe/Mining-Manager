@php
    $payments = $settings['payments'];
    $upfrontOn = (bool) old('enable_upfront_payments', $settings['features']['enable_upfront_payments'] ?? false);
@endphp

<form method="POST" action="{{ route('mining-manager.settings.update-payments') }}">
    @csrf

    <h4>
        <i class="fas fa-wallet"></i>
        Payments
    </h4>
    <hr>

    <p>
        How tax payments are found in the Moon Owner's wallet and matched to bills. One wallet is read, so one set of rules matches every payment, whichever tax profile the bill came from.
    </p>

    {{-- Matching --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-search-dollar"></i>
                Matching
            </h5>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="tax_wallet_division">Wallet division</label>
                    <select class="form-control" id="tax_wallet_division" name="tax_wallet_division">
                        @foreach($walletDivisions as $division => $name)
                            <option value="{{ $division }}" {{ (int) old('tax_wallet_division', $payments['wallet_division'] ?? 1) === (int) $division ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">The Moon Owner's wallet division members pay into.</small>
                </div>
                <div class="form-group col-md-6">
                    <label for="payment_match_tolerance">Match tolerance</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="payment_match_tolerance" name="payment_match_tolerance" min="0" max="100000000" step="1000"
                               value="{{ old('payment_match_tolerance', $payments['match_tolerance'] ?? 100) }}">
                        <div class="input-group-append"><span class="input-group-text">ISK</span></div>
                    </div>
                    <small class="form-text text-muted">A payment this much short of a bill still settles it.</small>
                </div>
            </div>

            <div class="custom-control custom-switch mb-2">
                <input type="checkbox" class="custom-control-input" id="payment_auto_match_payments" name="payment_auto_match_payments" value="1"
                       {{ old('payment_auto_match_payments', $payments['auto_match_payments'] ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="payment_auto_match_payments">Apply matched payments as soon as they are found</label>
                <small class="form-text text-muted">
                    Turned off, matches are listed on the Wallet Verification page and wait for a director to confirm them before any bill changes.
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="payment_accept_alt_characters" name="payment_accept_alt_characters" value="1"
                       {{ old('payment_accept_alt_characters', $payments['accept_alt_characters'] ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="payment_accept_alt_characters">Treat a player's characters as one account</label>
                <small class="form-text text-muted">
                    A payment from any character on the same SeAT account settles the bill, upfront money clears any of the player's bills, and a refund can go to any of their characters.
                    Turned off, the paying character must be exactly the billed one. A match made across characters is recorded with both, so a dispute can be traced afterwards.
                </small>
            </div>
        </div>
    </div>

    {{-- Overpayment --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-coins"></i>
                Paying more than a bill
            </h5>
        </div>
        <div class="card-body">
            <div class="custom-control custom-switch mb-2">
                <input type="checkbox" class="custom-control-input" id="payment_cascade_remainder" name="payment_cascade_remainder" value="1"
                       {{ old('payment_cascade_remainder', $payments['cascade_remainder'] ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="payment_cascade_remainder">Roll what is left onto the next unpaid bill</label>
                <small class="form-text text-muted">
                    The rest of a payment pays the player's next-oldest unpaid bill, and so on, so clearing three months in one transfer works. Turned off, a payment only touches the bill it matched.
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="payment_hold_surplus_as_credit" name="payment_hold_surplus_as_credit" value="1"
                       {{ old('payment_hold_surplus_as_credit', $payments['hold_surplus_as_credit'] ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="payment_hold_surplus_as_credit">Keep anything left over as credit against the next bill</label>
                <small class="form-text text-muted">
                    Money left once every open bill is paid comes off the player's next bill. Turned off, it is logged and not kept. Credit already held is never touched either way.
                    This works whether or not upfront payments are on.
                </small>
            </div>
        </div>
    </div>

    {{-- Keywords --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-key"></i>
                Upfront payments and refunds
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Both keywords are read from the transfer reason, like payment codes, so neither may overlap any tax profile's code prefix or the other keyword.
            </p>

            <div class="custom-control custom-switch mb-2">
                <input type="checkbox" class="custom-control-input" id="enable_upfront_payments" name="enable_upfront_payments" value="1" {{ $upfrontOn ? 'checked' : '' }}>
                <label class="custom-control-label" for="enable_upfront_payments">Let members pay ahead of being billed</label>
            </div>

            <div class="form-group {{ $upfrontOn ? '' : 'mm-dim' }}" id="upfront-keyword-group" @unless($upfrontOn) style="opacity: 0.5;" @endunless>
                <label for="payment_upfront_keyword">Upfront payment keyword</label>
                <input type="text" class="form-control" id="payment_upfront_keyword" name="payment_upfront_keyword" maxlength="32" placeholder="MM-UPFRONT"
                       value="{{ old('payment_upfront_keyword', $payments['upfront_keyword'] ?? 'MM-UPFRONT') }}">
                <small class="form-text text-muted">
                    The same for everyone and it never expires, so it can live in the corporation MOTD. A payment with it settles whatever the player already owes, oldest first, and the rest becomes credit. Matching ignores case.
                </small>
            </div>

            <div class="form-group mb-0">
                <label for="payment_refund_keyword">Refund keyword</label>
                <input type="text" class="form-control" id="payment_refund_keyword" name="payment_refund_keyword" maxlength="32" placeholder="MM-REFUND"
                       value="{{ old('payment_refund_keyword', $payments['refund_keyword'] ?? 'MM-REFUND') }}">
                <small class="form-text text-muted">
                    Put it in the reason when you send a player's credit back from the corporation wallet. It is how the refund is recognised when the wallet is read.
                </small>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-mm-primary">
        <i class="fas fa-save"></i> Save payments
    </button>
</form>

@push('javascript')
<script>
$(function () {
    $('#enable_upfront_payments').on('change', function () {
        $('#upfront-keyword-group').css('opacity', this.checked ? 1 : 0.5);
    });
});
</script>
@endpush
