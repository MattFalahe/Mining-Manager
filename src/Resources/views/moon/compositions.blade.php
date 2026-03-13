@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.moon_compositions'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper moon-compositions-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon') && !Request::is('*/moon/*') ? 'active' : '' }}" href="{{ route('mining-manager.moon.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/active') ? 'active' : '' }}" href="{{ route('mining-manager.moon.active') }}">
                    <i class="fas fa-hourglass-half"></i> {{ trans('mining-manager::menu.active_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/calendar') ? 'active' : '' }}" href="{{ route('mining-manager.moon.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.extraction_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/compositions') ? 'active' : '' }}" href="{{ route('mining-manager.moon.compositions') }}">
                    <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::menu.moon_compositions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/calculator') ? 'active' : '' }}" href="{{ route('mining-manager.moon.calculator') }}">
                    <i class="fas fa-flask"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">


<div class="moon-compositions">
    
    {{-- CONTROLS --}}
    <div class="row mb-3">
        <div class="col-12">
            <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-secondary">
                <i class="fas fa-list"></i> {{ trans('mining-manager::moons.list_view') }}
            </a>
            <a href="{{ route('mining-manager.moon.calendar') }}" class="btn btn-info">
                <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::moons.calendar_view') }}
            </a>
        </div>
    </div>

    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon">
                    <i class="fas fa-moon"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.total_moons') }}</span>
                    <span class="info-box-number">{{ count($moonData) }}</span>
                    <small>{{ trans('mining-manager::moons.tracked_moons') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon">
                    <i class="fas fa-gem"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.highest_value') }}</span>
                    <span class="info-box-number">
                        @if(count($moonData) > 0)
                            {{ number_format(collect($moonData)->max('average_value'), 0) }}
                        @else
                            0
                        @endif
                    </span>
                    <small>ISK {{ trans('mining-manager::moons.per_extraction') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon">
                    <i class="fas fa-chart-line"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.average_value') }}</span>
                    <span class="info-box-number">
                        @if(count($moonData) > 0)
                            {{ number_format(collect($moonData)->avg('average_value'), 0) }}
                        @else
                            0
                        @endif
                    </span>
                    <small>ISK {{ trans('mining-manager::moons.per_extraction') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-primary">
                <span class="info-box-icon">
                    <i class="fas fa-history"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.total_extractions') }}</span>
                    <span class="info-box-number">
                        {{ collect($moonData)->sum(function($data) { return count($data['extractions']); }) }}
                    </span>
                    <small>{{ trans('mining-manager::moons.in_database') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- TOP MOONS RANKING --}}
    @if(count($moonData) > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-trophy"></i>
                        {{ trans('mining-manager::moons.top_value_moons') }}
                    </h3>
                </div>
                <div class="card-body">
                    @php
                        $sortedMoons = collect($moonData)->sortByDesc('average_value')->take(10);
                        $rank = 1;
                    @endphp
                    
                    <div class="row">
                        @foreach($sortedMoons as $moonId => $data)
                        <div class="col-md-12 mb-3">
                            <div class="card moon-card bg-dark">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        {{-- Rank --}}
                                        <div class="col-auto">
                                            <div class="rank-badge rank-{{ min($rank, 3) }}">
                                                #{{ $rank }}
                                            </div>
                                        </div>

                                        {{-- Moon Info --}}
                                        <div class="col-md-4">
                                            <h5 class="mb-1">
                                                <i class="fas fa-moon text-info"></i>
                                                {{ $data['moon_name'] ?? 'Unknown Moon' }}
                                            </h5>
                                            <p class="mb-0 text-muted small">
                                                {{ count($data['extractions']) }} {{ trans('mining-manager::moons.extractions') }}
                                            </p>
                                        </div>

                                        {{-- Average Value --}}
                                        <div class="col-md-3 text-center">
                                            <span class="moon-value-badge badge badge-success">
                                                {{ number_format($data['average_value'], 0) }} ISK
                                            </span>
                                            <p class="mb-0 text-muted small mt-1">{{ trans('mining-manager::moons.avg_per_extraction') }}</p>
                                        </div>

                                        {{-- Top Ores --}}
                                        <div class="col-md-4">
                                            <p class="mb-1 small"><strong>{{ trans('mining-manager::moons.top_ores') }}:</strong></p>
                                            @php
                                                $latestExtraction = collect($data['extractions'])->sortByDesc('extraction_start_time')->first();
                                                $composition = is_string($latestExtraction->ore_composition) 
                                                    ? json_decode($latestExtraction->ore_composition, true) 
                                                    : $latestExtraction->ore_composition;
                                                $topOres = collect($composition)->sortByDesc('percentage')->take(3);
                                            @endphp
                                            @foreach($topOres as $oreName => $oreData)
                                            <div class="d-flex align-items-center mb-1">
                                                <span class="small mr-2" style="width: 80px;">{{ $oreName }}</span>
                                                <div class="progress flex-grow-1" style="height: 15px;">
                                                    <div class="progress-bar bg-info" style="width: {{ max($oreData['percentage'], 1) }}%">
                                                        @if($oreData['percentage'] >= 20){{ number_format($oreData['percentage'], 1) }}%@endif
                                                    </div>
                                                </div>
                                                @if($oreData['percentage'] < 20 && $oreData['percentage'] > 0)
                                                    <span class="ml-2 small">{{ number_format($oreData['percentage'], 1) }}%</span>
                                                @endif
                                            </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @php $rank++; @endphp
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- DETAILED MOON COMPOSITIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list-alt"></i>
                        {{ trans('mining-manager::moons.all_moon_compositions') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="accordion" id="moonAccordion">
                        @foreach(collect($moonData)->sortByDesc('average_value') as $moonId => $data)
                        <div class="card bg-secondary mb-2">
                            <div class="card-header p-2" id="heading{{ $moonId }}">
                                <h5 class="mb-0">
                                    <button class="btn btn-link text-light w-100 text-left d-flex justify-content-between align-items-center collapsed" 
                                            type="button" 
                                            data-toggle="collapse" 
                                            data-target="#collapse{{ $moonId }}" 
                                            aria-expanded="false" 
                                            aria-controls="collapse{{ $moonId }}">
                                        <span>
                                            <i class="fas fa-moon text-info"></i>
                                            {{ $data['moon_name'] ?? 'Unknown Moon' }}
                                        </span>
                                        <span class="badge badge-success">
                                            {{ number_format($data['average_value'], 0) }} ISK
                                        </span>
                                    </button>
                                </h5>
                            </div>

                            <div id="collapse{{ $moonId }}" 
                                 class="collapse" 
                                 aria-labelledby="heading{{ $moonId }}" 
                                 data-parent="#moonAccordion">
                                <div class="card-body">
                                    @php
                                        $latestExtraction = collect($data['extractions'])->sortByDesc('extraction_start_time')->first();
                                        $composition = is_string($latestExtraction->ore_composition) 
                                            ? json_decode($latestExtraction->ore_composition, true) 
                                            : $latestExtraction->ore_composition;
                                    @endphp
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <p><strong>{{ trans('mining-manager::moons.total_extractions') }}:</strong> {{ count($data['extractions']) }}</p>
                                            <p><strong>{{ trans('mining-manager::moons.average_value') }}:</strong> 
                                                <span class="text-success">{{ number_format($data['average_value'], 0) }} ISK</span>
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>{{ trans('mining-manager::moons.last_extraction') }}:</strong> 
                                                {{ $latestExtraction->extraction_start_time->format('M d, Y') }}
                                            </p>
                                        </div>
                                    </div>

                                    <h6>{{ trans('mining-manager::moons.ore_composition') }}</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-dark">
                                            <thead>
                                                <tr>
                                                    <th>{{ trans('mining-manager::moons.ore_type') }}</th>
                                                    <th style="width: 50%;">{{ trans('mining-manager::moons.percentage') }}</th>
                                                    <th class="text-right">{{ trans('mining-manager::moons.avg_value') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach(collect($composition)->sortByDesc('percentage') as $oreName => $oreData)
                                                <tr>
                                                    <td>{{ $oreName }}</td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="progress flex-grow-1" style="height: 20px;">
                                                                <div class="progress-bar bg-info" style="width: {{ max($oreData['percentage'], 1) }}%">
                                                                    @if($oreData['percentage'] >= 20){{ number_format($oreData['percentage'], 2) }}%@endif
                                                                </div>
                                                            </div>
                                                            @if($oreData['percentage'] < 20 && $oreData['percentage'] > 0)
                                                                <span class="ml-2 small">{{ number_format($oreData['percentage'], 2) }}%</span>
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td class="text-right text-success">
                                                        {{ number_format($oreData['value'] ?? 0, 0) }} ISK
                                                    </td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-3">
                                        <h6>{{ trans('mining-manager::moons.recent_extractions') }}</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-dark table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>{{ trans('mining-manager::moons.date') }}</th>
                                                        <th>{{ trans('mining-manager::moons.structure') }}</th>
                                                        <th class="text-right">{{ trans('mining-manager::moons.value') }}</th>
                                                        <th>{{ trans('mining-manager::moons.actions') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach(collect($data['extractions'])->sortByDesc('extraction_start_time')->take(5) as $extraction)
                                                    <tr>
                                                        <td>{{ $extraction->extraction_start_time->format('M d, Y') }}</td>
                                                        <td>{{ $extraction->structure_name ?? 'Unknown' }}</td>
                                                        <td class="text-right text-success">
                                                            {{ number_format($extraction->calculated_value ?? 0, 0) }} ISK
                                                        </td>
                                                        <td>
                                                            <a href="{{ route('mining-manager.moon.show', $extraction->id) }}" class="btn btn-xs btn-info">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @else
    <div class="row">
        <div class="col-12">
            <div class="card card-warning">
                <div class="card-body text-center py-5">
                    <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                    <h4>{{ trans('mining-manager::moons.no_composition_data') }}</h4>
                    <p>{{ trans('mining-manager::moons.no_extractions_recorded') }}</p>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

    </div>
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
