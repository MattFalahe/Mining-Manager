@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.account_balances'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=2">
@include('mining-manager::taxes.partials.datatables-styles')
@endpush

@section('full')
@include('mining-manager::partials.toastr')
<div class="mining-manager-wrapper mining-dashboard taxes-balances-page">

@include('mining-manager::taxes.partials.tab-navigation')

<div class="account-balances">

    {{-- Summary. Members see one number that is theirs; directors see the
         corporation-wide position. --}}
    <div class="row">
        <div class="col-md-4">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ number_format($stats['total_held'], 0) }}</h3>
                    <p>
                        {{ $canSeeAll
                            ? trans('mining-manager::taxes.held_credit_total')
                            : trans('mining-manager::taxes.balance_available') }}
                        (ISK)
                    </p>
                </div>
                <div class="icon"><i class="fas fa-piggy-bank"></i></div>
            </div>
        </div>
        @if($canSeeAll)
        <div class="col-md-4">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>{{ $stats['holders'] }}</h3>
                    <p>{{ trans('mining-manager::taxes.balance_holders') }}</p>
                </div>
                <div class="icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
        @endif
        <div class="col-md-4">
            <div class="small-box bg-primary">
                <div class="inner">
                    <h3>{{ number_format($stats['total_drawn'], 0) }}</h3>
                    <p>{{ trans('mining-manager::taxes.balance_drawn_total') }} (ISK)</p>
                </div>
                <div class="icon"><i class="fas fa-arrow-right"></i></div>
            </div>
        </div>
    </div>

    {{-- How money gets here in the first place. Only worth explaining while
         upfront payments are switched on, otherwise the only route is
         overpaying an invoice. --}}
    <div class="row">
        <div class="col-12">
            <div class="callout callout-info">
                <p class="mb-0">{{ trans('mining-manager::taxes.balance_explained') }}</p>
                @if($upfrontKeyword)
                <p class="mb-0 mt-2">
                    {!! trans('mining-manager::taxes.balance_upfront_hint', ['keyword' => '<code>' . e($upfrontKeyword) . '</code>']) !!}
                </p>
                @endif
            </div>
        </div>
    </div>

    {{-- Balances --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-wallet mr-1"></i> {{ trans('mining-manager::taxes.account_balances') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    @if($credits->isEmpty())
                        <p class="text-muted p-3 mb-0">
                            {{ $canSeeAll
                                ? trans('mining-manager::taxes.held_credit_none')
                                : trans('mining-manager::taxes.balance_none_personal') }}
                        </p>
                    @else
                    <table class="table table-dark table-striped mb-0" id="balancesTable">
                        <thead>
                            <tr>
                                @if($canSeeAll)
                                <th>{{ trans('mining-manager::taxes.character') }}</th>
                                @endif
                                <th>{{ trans('mining-manager::taxes.date') }}</th>
                                <th class="text-right">{{ trans('mining-manager::taxes.balance_original') }}</th>
                                <th class="text-right">{{ trans('mining-manager::taxes.balance_remaining') }}</th>
                                <th>{{ trans('mining-manager::taxes.balance_covered') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($credits as $credit)
                            @php $spent = $drawdowns[$credit->id] ?? collect(); @endphp
                            <tr class="{{ (float) $credit->remaining <= 0 ? 'text-muted' : '' }}">
                                @if($canSeeAll)
                                <td>{{ $credit->character->name ?? "Character #{$credit->character_id}" }}</td>
                                @endif
                                <td>{{ $credit->created_at ? $credit->created_at->format('Y-m-d H:i') : '-' }}</td>
                                <td class="text-right">{{ number_format($credit->amount, 0) }}</td>
                                <td class="text-right">
                                    @if((float) $credit->remaining > 0)
                                        <strong class="text-success">{{ number_format($credit->remaining, 0) }}</strong>
                                    @else
                                        <span class="badge badge-secondary">{{ trans('mining-manager::taxes.balance_fully_used') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($spent->isEmpty())
                                        <span class="text-muted">{{ trans('mining-manager::taxes.balance_not_used_yet') }}</span>
                                    @else
                                        @foreach($spent as $use)
                                        <div>
                                            <a href="{{ route('mining-manager.taxes.details', $use->mining_tax_id) }}">
                                                {{ $use->tax && $use->tax->period_start
                                                    ? $use->tax->period_start->format('M Y')
                                                    : trans('mining-manager::taxes.view_tax') }}
                                            </a>
                                            <span class="text-muted">
                                                &mdash; {{ number_format($use->amount, 0) }} ISK
                                                on {{ $use->allocated_at ? $use->allocated_at->format('Y-m-d') : '' }}
                                            </span>
                                        </div>
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/jquery.dataTables.min.js') }}"></script>
<script>
$(document).ready(function() {
    // Only worth paginating once the list is long enough to need it.
    if ($('#balancesTable tbody tr').length > 10) {
        $('#balancesTable').DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            order: [],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries found",
                paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
            }
        });
    }
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
