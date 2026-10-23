{{--
    Pending Tax Period Switch Banner
    =================================
    Rendered on every tax-related page when a switch from one billing period
    to the other is queued. The change waits for a safe day early in a
    calendar month to avoid mid-period collisions on mining_taxes
    (character_id, period_start).

    Each tax profile has a period of its own, so a player sees their own
    account's queued switch, and an admin sees every profile's, named.

    Data source: TaxPeriodHelper::pendingPeriodChangesForViewer(), fetched
    here so every page that @include's this partial gets the banner for
    free, without threading controller variables through.
--}}

@php
    $__periodHelper = app(\MiningManager\Services\Tax\TaxPeriodHelper::class);
    $__pendingSwitches = $__periodHelper->pendingPeriodChangesForViewer();
    $__periodLabels = [
        'monthly' => 'Monthly',
        'biweekly' => 'Twice a month',
        'weekly' => 'Weekly',
    ];
@endphp

@foreach($__pendingSwitches as $__pendingSwitch)
<div class="row">
    <div class="col-12">
        <div class="alert alert-warning" role="alert" style="border-left: 4px solid #ffc107;">
            <div class="d-flex align-items-center">
                <i class="fas fa-calendar-alt fa-2x mr-3 text-warning"></i>
                <div class="flex-grow-1">
                    <strong>Tax period change scheduled{{ $__pendingSwitch['profile'] ? ' for ' . $__pendingSwitch['profile'] : '' }}:</strong>
                    switching from
                    <span class="badge badge-secondary">{{ $__periodLabels[$__pendingSwitch['current']] ?? $__pendingSwitch['current'] }}</span>
                    to
                    <span class="badge badge-primary">{{ $__periodLabels[$__pendingSwitch['pending']] ?? $__pendingSwitch['pending'] }}</span>
                    on
                    <strong>{{ $__pendingSwitch['effective_from']->format('F j, Y') }}</strong>
                    (in {{ \Carbon\Carbon::today()->diffInDays($__pendingSwitch['effective_from']) }} days).
                    <br>
                    <small class="text-muted">
                        Existing tax records keep their original period type.
                        New calculations from the effective date onward will use the new period.
                        Settle any open balances in the current scheme to avoid mixed-period rows in your history.
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
@endforeach
