@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.my_taxes'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/tax-management.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper taxes-my-taxes-page">

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/tax') && !Request::is('*/tax/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.index') }}">
                <i class="fas fa-chart-pie"></i> {{ trans('mining-manager::menu.tax_overview') }}
            </a>
        </li>
        @can('mining-manager.tax.calculate')
        <li class="{{ Request::is('*/tax/calculate') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.calculate') }}">
                <i class="fas fa-calculator"></i> {{ trans('mining-manager::menu.calculate_taxes') }}
            </a>
        </li>
        @endcan
        <li class="{{ Request::is('*/tax/my-taxes') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.my-taxes') }}">
                <i class="fas fa-receipt"></i> {{ trans('mining-manager::menu.my_taxes') }}
            </a>
        </li>
        <li class="{{ Request::is('*/tax/codes') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.codes') }}">
                <i class="fas fa-barcode"></i> {{ trans('mining-manager::menu.tax_codes') }}
            </a>
        </li>
        @can('mining-manager.tax.generate_invoices')
        <li class="{{ Request::is('*/tax/contracts') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.contracts') }}">
                <i class="fas fa-file-contract"></i> {{ trans('mining-manager::menu.tax_contracts') }}
            </a>
        </li>
        @endcan
        @can('mining-manager.tax.verify_payments')
        <li class="{{ Request::is('*/tax/wallet') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.wallet') }}">
                <i class="fas fa-wallet"></i> {{ trans('mining-manager::menu.wallet_verification') }}
            </a>
        </li>
        @endcan
    </ul>
    <div class="tab-content">


<div class="my-taxes">
    
    {{-- CURRENT TAX STATUS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i>
                        {{ trans('mining-manager::taxes.current_status') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Current Balance --}}
                        <div class="col-lg-4 col-md-6">
                            <div class="info-box {{ $currentTax && $currentTax->status === 'paid' ? 'bg-gradient-success' : 'bg-gradient-warning' }}">
                                <span class="info-box-icon">
                                    <i class="fas {{ $currentTax && $currentTax->status === 'paid' ? 'fa-check-circle' : 'fa-exclamation-circle' }}"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.current_balance') }}</span>
                                    <span class="info-box-number">{{ number_format($currentTax->amount_owed ?? 0, 0) }}</span>
                                    <small>ISK {{ trans('mining-manager::taxes.for') }} {{ now()->format('F Y') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Amount Paid --}}
                        <div class="col-lg-4 col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-coins"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.paid_so_far') }}</span>
                                    <span class="info-box-number">{{ number_format($currentTax->amount_paid ?? 0, 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>

                        {{-- Due Date --}}
                        <div class="col-lg-4 col-md-6">
                            <div class="info-box {{ $currentTax && $currentTax->due_date && \Carbon\Carbon::parse($currentTax->due_date)->isPast() && $currentTax->status !== 'paid' ? 'bg-gradient-danger' : 'bg-gradient-secondary' }}">
                                <span class="info-box-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.due_date') }}</span>
                                    <span class="info-box-number">
                                        {{ $currentTax && $currentTax->due_date ? \Carbon\Carbon::parse($currentTax->due_date)->format('M d') : 'N/A' }}
                                    </span>
                                    <small>
                                        @if($currentTax && $currentTax->due_date)
                                            {{ \Carbon\Carbon::parse($currentTax->due_date)->diffForHumans() }}
                                        @else
                                            -
                                        @endif
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($currentTax && $currentTax->status !== 'paid')
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <h5><i class="icon fas fa-info-circle"></i> {{ trans('mining-manager::taxes.payment_instructions') }}</h5>
                                <p>{{ trans('mining-manager::taxes.payment_info_text') }}</p>
                                
                                <strong>{{ trans('mining-manager::taxes.payment_options') }}:</strong>
                                <ul>
                                    <li>{{ trans('mining-manager::taxes.option_contract') }}</li>
                                    <li>{{ trans('mining-manager::taxes.option_wallet_transfer') }}: 
                                        <code class="text-dark">{{ $currentTax->tax_code ?? trans('mining-manager::taxes.code_pending') }}</code>
                                    </li>
                                </ul>

                                <div class="mt-2">
                                    @if($currentTax->tax_code)
                                    <button type="button" class="btn btn-sm btn-primary" onclick="copyTaxCode('{{ $currentTax->tax_code }}')">
                                        <i class="fas fa-copy"></i> {{ trans('mining-manager::taxes.copy_tax_code') }}
                                    </button>
                                    @endif
                                    <a href="{{ route('mining-manager.taxes.details', $currentTax->id) }}" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i> {{ trans('mining-manager::taxes.view_details') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    @elseif($currentTax && $currentTax->status === 'paid')
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="alert alert-success">
                                <h5><i class="icon fas fa-check-circle"></i> {{ trans('mining-manager::taxes.all_paid_up') }}</h5>
                                <p>{{ trans('mining-manager::taxes.thank_you_payment') }}</p>
                                <small>{{ trans('mining-manager::taxes.paid_on') }}: {{ $currentTax->paid_at ? \Carbon\Carbon::parse($currentTax->paid_at)->format('F d, Y') : 'N/A' }}</small>
                            </div>
                        </div>
                    </div>
                    @else
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> {{ trans('mining-manager::taxes.no_tax_this_month') }}
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- TAX HISTORY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        {{ trans('mining-manager::taxes.tax_history') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                            <i class="fas fa-print"></i> {{ trans('mining-manager::taxes.print') }}
                        </button>
                        <a href="{{ route('mining-manager.taxes.export-personal') }}" class="btn btn-sm btn-info">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::taxes.export') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Summary Stats --}}
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="small-box bg-primary">
                                <div class="inner">
                                    <h3>{{ number_format($totalTaxPaid, 0) }}</h3>
                                    <p>{{ trans('mining-manager::taxes.total_paid') }} (ISK)</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-coins"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small-box bg-success">
                                <div class="inner">
                                    <h3>{{ $onTimePayments }}</h3>
                                    <p>{{ trans('mining-manager::taxes.on_time_payments') }}</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-check"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small-box bg-warning">
                                <div class="inner">
                                    <h3>{{ $latePayments }}</h3>
                                    <p>{{ trans('mining-manager::taxes.late_payments') }}</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small-box bg-info">
                                <div class="inner">
                                    <h3>{{ $taxHistory->count() }}</h3>
                                    <p>{{ trans('mining-manager::taxes.total_months') }}</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-calendar"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- History Table --}}
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::taxes.month') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.amount_owed') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.amount_paid') }}</th>
                                    <th>{{ trans('mining-manager::taxes.status') }}</th>
                                    <th>{{ trans('mining-manager::taxes.payment_date') }}</th>
                                    <th>{{ trans('mining-manager::taxes.tax_code') }}</th>
                                    <th class="text-center">{{ trans('mining-manager::taxes.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($taxHistory as $tax)
                                <tr>
                                    <td>
                                        <strong>{{ \Carbon\Carbon::parse($tax->month)->format('F Y') }}</strong>
                                    </td>
                                    <td class="text-right">
                                        {{ number_format($tax->amount_owed, 0) }} ISK
                                    </td>
                                    <td class="text-right">
                                        {{ number_format($tax->amount_paid, 0) }} ISK
                                        @if($tax->amount_paid > 0 && $tax->amount_paid < $tax->amount_owed)
                                            <br><small class="text-warning">({{ number_format(($tax->amount_paid / $tax->amount_owed) * 100, 1) }}%)</small>
                                        @endif
                                    </td>
                                    <td>
                                        @switch($tax->status)
                                            @case('paid')
                                                <span class="badge badge-success">
                                                    <i class="fas fa-check"></i> {{ trans('mining-manager::taxes.paid') }}
                                                </span>
                                                @break
                                            @case('unpaid')
                                                <span class="badge badge-warning">
                                                    <i class="fas fa-clock"></i> {{ trans('mining-manager::taxes.unpaid') }}
                                                </span>
                                                @break
                                            @case('overdue')
                                                <span class="badge badge-danger">
                                                    <i class="fas fa-exclamation-triangle"></i> {{ trans('mining-manager::taxes.overdue') }}
                                                </span>
                                                @break
                                            @case('partial')
                                                <span class="badge badge-info">
                                                    <i class="fas fa-adjust"></i> {{ trans('mining-manager::taxes.partial') }}
                                                </span>
                                                @break
                                        @endswitch
                                    </td>
                                    <td>
                                        @if($tax->paid_at)
                                            {{ \Carbon\Carbon::parse($tax->paid_at)->format('Y-m-d') }}
                                            @if($tax->due_date && \Carbon\Carbon::parse($tax->paid_at)->gt(\Carbon\Carbon::parse($tax->due_date)))
                                                <br><small class="text-warning">({{ trans('mining-manager::taxes.late') }})</small>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($tax->tax_code)
                                            <code>{{ $tax->tax_code }}</code>
                                            <button type="button" 
                                                    class="btn btn-xs btn-link" 
                                                    onclick="copyTaxCode('{{ $tax->tax_code }}')"
                                                    data-toggle="tooltip"
                                                    title="{{ trans('mining-manager::taxes.copy') }}">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <a href="{{ route('mining-manager.taxes.details', $tax->id) }}" 
                                           class="btn btn-sm btn-info"
                                           data-toggle="tooltip"
                                           title="{{ trans('mining-manager::taxes.view_details') }}">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        @if($tax->status === 'paid')
                                        <button type="button" 
                                                class="btn btn-sm btn-primary download-receipt"
                                                data-tax-id="{{ $tax->id }}"
                                                data-toggle="tooltip"
                                                title="{{ trans('mining-manager::taxes.download_receipt') }}">
                                            <i class="fas fa-file-invoice"></i>
                                        </button>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 mt-3"></i>
                                        <p>{{ trans('mining-manager::taxes.no_history') }}</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                            @if($taxHistory->count() > 0)
                            <tfoot class="bg-secondary">
                                <tr>
                                    <td><strong>{{ trans('mining-manager::taxes.totals') }}</strong></td>
                                    <td class="text-right"><strong>{{ number_format($taxHistory->sum('amount_owed'), 0) }} ISK</strong></td>
                                    <td class="text-right"><strong>{{ number_format($taxHistory->sum('amount_paid'), 0) }} ISK</strong></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- TAX TREND CHART --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::taxes.tax_trend') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="taxTrendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// Initialize tooltips
$('[data-toggle="tooltip"]').tooltip();

// Copy tax code function
function copyTaxCode(code) {
    navigator.clipboard.writeText(code).then(function() {
        toastr.success('{{ trans("mining-manager::taxes.code_copied") }}: ' + code);
    }, function(err) {
        toastr.error('{{ trans("mining-manager::taxes.code_copy_failed") }}');
    });
}

// Download receipt
$('.download-receipt').on('click', function() {
    const taxId = $(this).data('tax-id');
    window.location.href = '{{ route("mining-manager.taxes.download-receipt", ":id") }}'.replace(':id', taxId);
});

// Tax Trend Chart
const taxTrendCtx = document.getElementById('taxTrendChart').getContext('2d');
const taxTrendData = {
    labels: @json($taxHistory->pluck('month')->map(fn($m) => \Carbon\Carbon::parse($m)->format('M Y'))),
    datasets: [
        {
            label: '{{ trans("mining-manager::taxes.amount_owed") }}',
            data: @json($taxHistory->pluck('amount_owed')),
            borderColor: 'rgba(255, 159, 64, 1)',
            backgroundColor: 'rgba(255, 159, 64, 0.2)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        },
        {
            label: '{{ trans("mining-manager::taxes.amount_paid") }}',
            data: @json($taxHistory->pluck('amount_paid')),
            borderColor: 'rgba(0, 210, 255, 1)',
            backgroundColor: 'rgba(0, 210, 255, 0.2)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }
    ]
};

const taxTrendChart = new Chart(taxTrendCtx, {
    type: 'line',
    data: taxTrendData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    color: '#fff'
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + ' ISK';
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: { color: '#fff' },
                grid: { color: '#444' }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    color: '#fff',
                    callback: function(value) {
                        return value.toLocaleString();
                    }
                },
                grid: { color: '#444' }
            }
        }
    }
});

// Print styles
window.onbeforeprint = function() {
    $('.card-tools').hide();
    $('.btn').hide();
};

window.onafterprint = function() {
    $('.card-tools').show();
    $('.btn').show();
};
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
