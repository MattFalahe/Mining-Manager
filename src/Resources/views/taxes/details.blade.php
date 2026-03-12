@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.tax_details'))
@section('page_header', trans('mining-manager::taxes.tax_details') . ' - ' . ($tax->character_info['name'] ?? $tax->character->name ?? 'Unknown'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper taxes-details-page">

@include('mining-manager::taxes.partials.tab-navigation')

<div class="tax-details">

    {{-- Breadcrumb --}}
    <div class="row">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-dark">
                    <li class="breadcrumb-item"><a href="{{ route('mining-manager.taxes.index') }}">{{ trans('mining-manager::taxes.tax_overview') }}</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('mining-manager.taxes.my-taxes') }}">{{ trans('mining-manager::taxes.my_taxes') }}</a></li>
                    <li class="breadcrumb-item active">{{ trans('mining-manager::taxes.details') }}</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- Tax Method Indicator --}}
    @if(($taxCalculationMethod ?? 'individually') === 'accumulated')
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info alert-sm">
                <i class="fas fa-users"></i> This tax is calculated using <strong>account-based taxation</strong> (all characters combined).
            </div>
        </div>
    </div>
    @endif

    {{-- Character & Tax Info --}}
    <div class="row">
        <div class="col-md-4">
            <div class="card card-dark card-outline">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.character_info') }}</h3>
                </div>
                <div class="card-body box-profile">
                    <div class="text-center">
                        <img class="profile-user-img img-fluid img-circle"
                             src="https://images.evetech.net/characters/{{ $tax->character_id }}/portrait?size=128">
                    </div>
                    <h3 class="profile-username text-center">{{ $tax->character_info['name'] ?? $tax->character->name ?? 'Unknown' }}</h3>
                    <p class="text-muted text-center">{{ $tax->character_info['corporation_name'] ?? $tax->affiliation->corporation_name ?? 'Unknown' }}</p>

                    <ul class="list-group list-group-unbordered mb-3">
                        <li class="list-group-item bg-dark">
                            <b>{{ trans('mining-manager::taxes.month') }}</b>
                            <span class="float-right">{{ \Carbon\Carbon::parse($tax->month)->format('F Y') }}</span>
                        </li>
                        <li class="list-group-item bg-dark">
                            <b>{{ trans('mining-manager::taxes.status') }}</b>
                            <span class="float-right">
                                @switch($tax->status)
                                    @case('paid')
                                        <span class="badge badge-success">{{ trans('mining-manager::taxes.paid') }}</span>
                                        @break
                                    @case('unpaid')
                                        <span class="badge badge-warning">{{ trans('mining-manager::taxes.unpaid') }}</span>
                                        @break
                                    @case('overdue')
                                        <span class="badge badge-danger">{{ trans('mining-manager::taxes.overdue') }}</span>
                                        @break
                                    @case('partial')
                                        <span class="badge badge-info">{{ trans('mining-manager::taxes.partial') }}</span>
                                        @break
                                @endswitch
                            </span>
                        </li>
                        <li class="list-group-item bg-dark">
                            <b>{{ trans('mining-manager::taxes.tax_code') }}</b>
                            <span class="float-right">
                                @php
                                    $activeTaxCode = $tax->taxCodes->where('status', 'active')->first()
                                                   ?? $tax->taxCodes->where('status', 'used')->first();
                                @endphp
                                @if($activeTaxCode)
                                    <code>{{ $activeTaxCode->code }}</code>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </span>
                        </li>
                    </ul>

                    <button class="btn btn-info btn-block" onclick="window.print()">
                        <i class="fas fa-print"></i> {{ trans('mining-manager::taxes.print') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.tax_summary') }}</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-box bg-gradient-primary">
                                <span class="info-box-icon"><i class="fas fa-calculator"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.total_mined_value') }}</span>
                                    <span class="info-box-number">{{ number_format($miningTotal ?? 0, 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-box bg-gradient-danger">
                                <span class="info-box-icon"><i class="fas fa-receipt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.amount_owed') }}</span>
                                    <span class="info-box-number">{{ number_format($tax->amount_owed, 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($tax->status === 'paid')
                    <div class="callout callout-success">
                        <h5><i class="fas fa-check-circle"></i> {{ trans('mining-manager::taxes.payment_confirmed') }}</h5>
                        <p>{{ trans('mining-manager::taxes.paid_on') }}: {{ $tax->paid_at ? \Carbon\Carbon::parse($tax->paid_at)->format('F d, Y') : 'N/A' }}</p>
                    </div>
                    @endif

                    {{-- Notes (shows alt breakdown for accumulated taxes) --}}
                    @if($tax->notes)
                    <div class="callout callout-info">
                        <h5><i class="fas fa-info-circle"></i> Notes</h5>
                        <pre class="mb-0" style="color: #ccc; background: transparent; border: none; padding: 0;">{{ $tax->notes }}</pre>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Mining Breakdown --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">{{ trans('mining-manager::taxes.mining_breakdown') }}</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    @if(($taxCalculationMethod ?? 'individually') === 'accumulated')
                                    <th>Character</th>
                                    @endif
                                    <th>{{ trans('mining-manager::taxes.ore_type') }}</th>
                                    <th>Category</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.total_value') }}</th>
                                    <th class="text-right">Tax Rate</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.tax_amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($miningBreakdown ?? [] as $ore)
                                <tr>
                                    @if(($taxCalculationMethod ?? 'individually') === 'accumulated')
                                    <td>{{ $ore['character_name'] ?? '' }}</td>
                                    @endif
                                    <td>
                                        {{ $ore['type_name'] ?? 'Unknown' }}
                                        @if(!empty($ore['rarity']))
                                            <span class="badge badge-{{ $ore['rarity'] === 'r64' ? 'warning' : ($ore['rarity'] === 'r32' ? 'info' : 'secondary') }}">
                                                {{ strtoupper($ore['rarity']) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td><small class="text-muted">{{ ucfirst($ore['category'] ?? 'ore') }}</small></td>
                                    <td class="text-right">{{ number_format($ore['quantity'] ?? 0, 0) }}</td>
                                    <td class="text-right">{{ number_format($ore['total_value'] ?? 0, 0) }} ISK</td>
                                    <td class="text-right">
                                        {{ number_format($ore['tax_rate'] ?? 0, 1) }}%
                                        @if(($ore['event_modifier'] ?? 0) != 0)
                                            <br><small class="text-{{ $ore['event_modifier'] < 0 ? 'success' : 'danger' }}">
                                                ({{ $ore['event_modifier'] > 0 ? '+' : '' }}{{ $ore['event_modifier'] }}% event)
                                            </small>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($ore['tax_amount'] ?? 0, 0) }} ISK</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="{{ ($taxCalculationMethod ?? 'individually') === 'accumulated' ? 7 : 6 }}" class="text-center text-muted">{{ trans('mining-manager::taxes.no_breakdown') }}</td>
                                </tr>
                                @endforelse
                            </tbody>
                            @if(!empty($miningBreakdown))
                            <tfoot class="bg-secondary">
                                <tr>
                                    @if(($taxCalculationMethod ?? 'individually') === 'accumulated')
                                    <td></td>
                                    @endif
                                    <td colspan="2"><strong>Total</strong></td>
                                    <td></td>
                                    <td class="text-right"><strong>{{ number_format(collect($miningBreakdown)->sum('total_value'), 0) }} ISK</strong></td>
                                    <td></td>
                                    <td class="text-right"><strong>{{ number_format(collect($miningBreakdown)->sum('tax_amount'), 0) }} ISK</strong></td>
                                </tr>
                            </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
