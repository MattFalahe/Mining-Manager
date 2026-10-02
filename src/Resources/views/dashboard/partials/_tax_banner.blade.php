{{-- Mining tax still owed by whoever is looking, at the top of both dashboards.
     Red once a bill is late, by the same rule as everywhere else, and yellow while
     there is still time. No dismiss button on purpose: it goes when the bill is
     paid. Built in DashboardController::taxBanner(). --}}
@if(!empty($taxBanner))
@php
    $bannerBill = $taxBanner['first'];
    $bannerDue = $bannerBill->effectiveDueDate()->format('M j');
    $bannerPeriod = $bannerBill->formatted_period;
    $bannerLeft = number_format($taxBanner['remaining'], 0);
@endphp
<div class="alert-mm alert-mm-{{ $taxBanner['late'] ? 'danger' : 'warning' }} d-flex align-items-center flex-wrap" role="alert">
    <div class="flex-grow-1 mr-3">
        <strong>
            <i class="fas {{ $taxBanner['late'] ? 'fa-exclamation-triangle' : 'fa-coins' }}"></i>
            {{ trans('mining-manager::taxes.banner_title', ['countdown' => $bannerBill->dueCountdown()]) }}
        </strong>
        <div>
            @if($taxBanner['count'] === 1)
                {{ trans('mining-manager::taxes.banner_one_bill', ['amount' => $bannerLeft, 'period' => $bannerPeriod, 'due' => $bannerDue]) }}
            @else
                {{ trans('mining-manager::taxes.banner_many_bills', ['amount' => $bannerLeft, 'count' => $taxBanner['count'], 'period' => $bannerPeriod, 'due' => $bannerDue]) }}
            @endif
        </div>
        @if($taxBanner['paid'] > 0)
            <small class="d-block">
                {{ trans('mining-manager::taxes.banner_part_paid', ['paid' => number_format($taxBanner['paid'], 0), 'owed' => number_format($taxBanner['billed'], 0)]) }}
            </small>
        @endif
    </div>
    <a href="{{ route('mining-manager.taxes.my-taxes') }}" class="btn btn-sm btn-mm-primary mt-2 mt-md-0">
        {{ trans('mining-manager::taxes.banner_pay_now') }}
    </a>
</div>
@endif
