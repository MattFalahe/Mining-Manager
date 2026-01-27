@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.mining_ledger') . ' - ' . ($characterInfo['name'] ?? 'Character'))
@section('page_header', trans('mining-manager::ledger.mining_ledger') . ' - ' . ($characterInfo['name'] ?? 'Character'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ (Request::is('*/ledger') && !Request::is('*/ledger/*')) || Request::is('*/ledger/summary') ? '' : '' }}">
            <a href="{{ route('mining-manager.ledger.index') }}">
                <i class="fas fa-layer-group"></i> {{ trans('mining-manager::ledger.mining_summary') }}
            </a>
        </li>
        <li class="{{ Request::is('*/ledger/my-mining') ? '' : '' }}">
            <a href="{{ route('mining-manager.ledger.my-mining') }}">
                <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
            </a>
        </li>
        @can('mining-manager.ledger.process')
        <li class="{{ Request::is('*/ledger/process') ? '' : '' }}">
            <a href="{{ route('mining-manager.ledger.process') }}">
                <i class="fas fa-cogs"></i> {{ trans('mining-manager::menu.process_ledger') }}
            </a>
        </li>
        @endcan
    </ul>
    <div class="tab-content">

<div class="mining-manager-wrapper mining-ledger-details">

    {{-- CHARACTER HEADER --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <img src="https://images.evetech.net/characters/{{ $characterId }}/portrait?size=128"
                                 style="width: 64px; height: 64px; border-radius: 8px;"
                                 alt="{{ $characterInfo['name'] ?? 'Character' }}">
                        </div>
                        <div class="col">
                            <h3 class="mb-1">{{ $characterInfo['name'] ?? 'Unknown Character' }}</h3>
                            @if(isset($characterInfo['corporation_name']))
                                <p class="mb-0">
                                    <img src="https://images.evetech.net/corporations/{{ $characterInfo['corporation_id'] }}/logo?size=32"
                                         style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;"
                                         alt="">
                                    <strong>{{ $characterInfo['corporation_name'] }}</strong>
                                </p>
                            @endif
                            <p class="text-muted mb-0">{{ trans('mining-manager::ledger.mining_ledger') }} - {{ $monthDate->format('F Y') }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('mining-manager.ledger.index', ['month' => $month]) }}" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> {{ trans('mining-manager::ledger.view_all') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- SUMMARY STATISTICS --}}
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon">
                    <i class="fas fa-coins"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_value') }}</span>
                    <span class="info-box-number">{{ number_format($totals->total_value ?? 0, 0) }}</span>
                    <small>ISK</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon">
                    <i class="fas fa-cube"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_quantity') }}</span>
                    <span class="info-box-number">{{ number_format($totals->total_quantity ?? 0, 0) }}</span>
                    <small>m³</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon">
                    <i class="fas fa-map-marked-alt"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.unique_systems') }}</span>
                    <span class="info-box-number">{{ $totals->unique_systems ?? 0 }}</span>
                    <small>{{ trans('mining-manager::ledger.solar_system') }}s</small>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="info-box bg-gradient-primary">
                <span class="info-box-icon">
                    <i class="fas fa-gem"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::ledger.unique_ores') }}</span>
                    <span class="info-box-number">{{ $totals->unique_ores ?? 0 }}</span>
                    <small>{{ trans('mining-manager::ledger.ore_types') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- MINING ENTRIES TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        {{ trans('mining-manager::ledger.ledger_entries') }}
                    </h3>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::ledger.date') }}</th>
                                <th>{{ trans('mining-manager::ledger.system') }}</th>
                                <th>{{ trans('mining-manager::ledger.ore_type') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.quantity') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.price_per_unit') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.total_value') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($entries as $entry)
                                <tr>
                                    <td>{{ $entry->date->format('Y-m-d H:i') }}</td>
                                    <td>
                                        {{ $entry->solarSystem->name ?? 'Unknown System' }}
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/types/{{ $entry->type_id }}/icon?size=32"
                                             style="width: 24px; height: 24px; vertical-align: middle; margin-right: 5px;"
                                             alt="">
                                        {{ $entry->type->typeName ?? 'Unknown Ore' }}
                                        @if($entry->is_moon_ore)
                                            <span class="badge badge-warning">{{ trans('mining-manager::ledger.moon_ore') }}</span>
                                        @elseif($entry->is_ice)
                                            <span class="badge badge-info">{{ trans('mining-manager::ledger.ice') }}</span>
                                        @elseif($entry->is_gas)
                                            <span class="badge badge-success">{{ trans('mining-manager::ledger.gas') }}</span>
                                        @else
                                            <span class="badge badge-secondary">{{ trans('mining-manager::ledger.regular_ore') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($entry->quantity, 2) }} m³</td>
                                    <td class="text-right">{{ number_format($entry->price_per_unit, 2) }} ISK</td>
                                    <td class="text-right"><strong>{{ number_format($entry->total_value, 2) }} ISK</strong></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center">
                                        <em>{{ trans('mining-manager::ledger.no_entries') }}</em>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($entries->hasPages())
                    <div class="card-footer">
                        {{ $entries->appends(['month' => $month])->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>

    </div>
</div>

@endsection
