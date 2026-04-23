{{--
    Pending Tax Period Switch Banner
    =================================
    Rendered on every tax-related page when the admin has queued a switch
    from one tax calculation period type (monthly / biweekly / weekly) to
    another. The change is always deferred to the first of the next
    calendar month to avoid mid-period collisions on
    mining_taxes (character_id, period_start).

    The banner surfaces the queued change so miners and directors can see
    what's coming and verify any open balances settle under the current
    scheme before the cutover.

    Data source: fetched here via TaxPeriodHelper::getPendingPeriodChange()
    so every page that @include's this partial gets the banner for free,
    without having to thread controller variables through.
--}}

@php
    $__periodHelper = app(\MiningManager\Services\Tax\TaxPeriodHelper::class);
    $__pendingSwitch = $__periodHelper->getPendingPeriodChange();
    $__periodLabels = [
        'monthly' => 'Monthly',
        'biweekly' => 'Bi-weekly',
        'weekly' => 'Weekly',
    ];
@endphp

@if($__pendingSwitch)
<div class="row">
    <div class="col-12">
        <div class="alert alert-warning" role="alert" style="border-left: 4px solid #ffc107;">
            <div class="d-flex align-items-center">
                <i class="fas fa-calendar-alt fa-2x mr-3 text-warning"></i>
                <div class="flex-grow-1">
                    <strong>Tax period change scheduled</strong>
                    &mdash; switching from
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
@endif
