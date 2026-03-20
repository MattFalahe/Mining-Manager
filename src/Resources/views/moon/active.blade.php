@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.active_extractions'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
<meta http-equiv="refresh" content="60">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard moon-active-page">

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


<div class="active-extractions-view">
    
    {{-- AUTO-REFRESH NOTICE --}}
    <div class="row">
        <div class="col-12">
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i>
                {{ trans('mining-manager::moons.auto_refresh_active') }}
                <span class="badge badge-warning ml-2">
                    <i class="fas fa-sync-alt pulse"></i> {{ trans('mining-manager::moons.refreshing_60s') }}
                </span>
            </div>
        </div>
    </div>

    {{-- QUICK STATS --}}
    <div class="row">
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon">
                    <i class="fas fa-circle-notch fa-spin"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.currently_extracting') }}</span>
                    <span class="info-box-number">{{ $activeExtractions->count() }}</span>
                    <small>{{ trans('mining-manager::moons.active_now') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon">
                    <i class="fas fa-gem"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.total_value') }}</span>
                    <span class="info-box-number">
                        {{ number_format($totalValue ?? 0, 0) }}
                    </span>
                    <small>ISK</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon">
                    <i class="fas fa-clock"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.arriving_soon') }}</span>
                    <span class="info-box-number">{{ $arrivingSoon ?? 0 }}</span>
                    <small>{{ trans('mining-manager::moons.within_24h') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-primary">
                <span class="info-box-icon">
                    <i class="fas fa-chart-line"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.average_value') }}</span>
                    <span class="info-box-number">
                        @if($activeExtractions->count() > 0)
                            {{ number_format(($totalValue ?? 0) / $activeExtractions->count(), 0) }}
                        @else
                            0
                        @endif
                    </span>
                    <small>ISK</small>
                </div>
            </div>
        </div>
    </div>

    {{-- ARRIVING IN NEXT 24 HOURS --}}
    @if(isset($imminentArrivals) && $imminentArrivals->count() > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-danger card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle pulse"></i>
                        {{ trans('mining-manager::moons.urgent_arrivals') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-danger">{{ $imminentArrivals->count() }} {{ trans('mining-manager::moons.arriving_soon') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach($imminentArrivals as $extraction)
                        <div class="col-md-6 mb-3">
                            <div class="card bg-danger">
                                <div class="card-body">
                                    <h5 class="mb-1">
                                        <i class="fas fa-building"></i>
                                        {{ $extraction->structure_name ?? 'Unknown' }}
                                    </h5>
                                    <p class="mb-2">
                                        <i class="fas fa-moon"></i>
                                        {{ $extraction->moon_name ?? 'Unknown Moon' }}
                                    </p>
                                    <div class="countdown-timer">
                                        <i class="fas fa-hourglass-half"></i>
                                        {{ $extraction->chunk_arrival_time->diffForHumans() }}
                                    </div>
                                    <p class="mb-0 mt-2">
                                        <strong>{{ trans('mining-manager::moons.estimated_value') }}:</strong>
                                        <span class="badge badge-warning">
                                            {{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ACTIVE EXTRACTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-broadcast-tower pulse"></i>
                        {{ trans('mining-manager::moons.all_active_extractions') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-warning live-badge">
                            <i class="fas fa-circle"></i> {{ trans('mining-manager::moons.live') }}
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    @forelse($activeExtractions as $extraction)
                    <div class="active-extraction-card card mb-2">
                        <div class="card-body">
                            <div class="row align-items-center">
                                {{-- Structure & Moon Info --}}
                                <div class="col-lg-3 col-md-4">
                                    <h5 class="mb-1">
                                        <i class="fas fa-building text-warning"></i>
                                        {{ $extraction->structure_name ?? 'Unknown' }}
                                    </h5>
                                    <p class="mb-1">
                                        <i class="fas fa-moon text-info"></i>
                                        {{ $extraction->moon_name ?? 'Unknown Moon' }}
                                    </p>
                                    <p class="mb-0 text-muted small">
                                        <i class="fas fa-map-marker-alt"></i>
                                        @php
                                            $activeMoonStr = $extraction->moon_name ?? '';
                                            $activeSystemName = preg_match('/^(.+?)\s+[IVXLCDM]+\s+-/', $activeMoonStr, $am) ? $am[1] : 'Unknown System';
                                        @endphp
                                        {{ $activeSystemName }}
                                    </p>
                                </div>

                                {{-- Countdown Timer --}}
                                <div class="col-lg-3 col-md-4 text-center">
                                    @php
                                        $hoursUntilArrival = \Carbon\Carbon::now()->diffInHours($extraction->chunk_arrival_time, false);
                                        $totalHours = $extraction->extraction_start_time->diffInHours($extraction->chunk_arrival_time);
                                        $progressPercent = $totalHours > 0 ? (($totalHours - $hoursUntilArrival) / $totalHours) * 100 : 0;
                                    @endphp
                                    
                                    <div class="mb-2">
                                        <div class="mm-progress-wrap">
                                            <div class="progress" style="height: 25px;">
                                                <div class="progress-bar bg-warning progress-bar-striped progress-bar-animated"
                                                     role="progressbar"
                                                     style="width: {{ max($progressPercent, 1) }}%"></div>
                                            </div>
                                            <span class="mm-pct-label">{{ number_format($progressPercent, 1) }}%</span>
                                        </div>
                                    </div>
                                    
                                    <p class="mb-1"><strong>{{ trans('mining-manager::moons.chunk_arrival') }}:</strong></p>
                                    <div class="countdown-timer">
                                        @if($hoursUntilArrival > 0)
                                            {{ floor($hoursUntilArrival / 24) }}d {{ $hoursUntilArrival % 24 }}h
                                        @else
                                            <span class="text-success">{{ trans('mining-manager::moons.ready') }}</span>
                                        @endif
                                    </div>
                                    <p class="mb-0 text-muted small">
                                        {{ $extraction->chunk_arrival_time->format('M d, H:i') }}
                                    </p>
                                </div>

                                {{-- Ore Composition Preview --}}
                                <div class="col-lg-3 col-md-4">
                                    @if($extraction->ore_composition)
                                        @php
                                            $composition = is_string($extraction->ore_composition) 
                                                ? json_decode($extraction->ore_composition, true) 
                                                : $extraction->ore_composition;
                                            $topOres = collect($composition)->sortByDesc('percentage')->take(3);
                                        @endphp
                                        <p class="mb-1 small"><strong>{{ trans('mining-manager::moons.top_ores') }}:</strong></p>
                                        @foreach($topOres as $oreName => $oreData)
                                        <div class="mb-1">
                                            <div class="d-flex justify-content-between">
                                                <small>{{ $oreName }}</small>
                                                <small>{{ number_format($oreData['percentage'], 1) }}%</small>
                                            </div>
                                            <div class="progress" style="height: 5px;">
                                                <div class="progress-bar bg-info" style="width: {{ $oreData['percentage'] }}%"></div>
                                            </div>
                                        </div>
                                        @endforeach
                                    @else
                                        <p class="text-muted small">{{ trans('mining-manager::moons.no_composition_data') }}</p>
                                    @endif
                                </div>

                                {{-- Value & Actions --}}
                                <div class="col-lg-3 col-md-12 text-right">
                                    @if($extraction->ore_composition)
                                        <h3 class="mb-1 text-success">
                                            {{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }}
                                        </h3>
                                        <p class="mb-2 text-muted small">ISK {{ trans('mining-manager::moons.estimated') }}</p>
                                    @else
                                        <p class="text-muted">{{ trans('mining-manager::moons.value_unknown') }}</p>
                                    @endif
                                    
                                    <div class="btn-group">
                                        <a href="{{ route('mining-manager.moon.show', $extraction->id) }}" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i> {{ trans('mining-manager::moons.details') }}
                                        </a>
                                        @can('mining-manager.director')
                                        <form action="{{ route('mining-manager.moon.update', $extraction->id) }}" method="POST" style="display: inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::moons.update') }}
                                            </button>
                                        </form>
                                        @endcan
                                    </div>
                                </div>
                            </div>

                            {{-- Unstable Moon Warning (48-51h after arrival) --}}
                            @if($extraction->isUnstable())
                                <div class="row mt-2">
                                    <div class="col-12">
                                        <div class="alert mm-alert-unstable mb-0 py-2">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <strong>{{ trans('mining-manager::moons.unstable_warning') }}:</strong>
                                            {{ trans('mining-manager::moons.unstable_message') }}
                                            <span class="badge badge-dark ml-2">
                                                {{ trans('mining-manager::moons.auto_fractures_in') }}: {{ $extraction->getTimeUntilAutoFracture() }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            {{-- Auto Fracture Warning (within 3h of auto-fracture) --}}
                            @elseif($extraction->shouldShowAutoFractureWarning())
                                <div class="row mt-2">
                                    <div class="col-12">
                                        <div class="alert mm-alert-auto-fracture mb-0 py-2">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <strong>{{ trans('mining-manager::moons.auto_fracture_warning') }}:</strong>
                                            {{ trans('mining-manager::moons.auto_fractures_in') }} {{ $extraction->getTimeUntilAutoFracture() }}
                                            ({{ $extraction->natural_decay_time->format('M d, H:i') }})
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                    @empty
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-moon fa-3x mb-3"></i>
                        <h4>{{ trans('mining-manager::moons.no_active_extractions') }}</h4>
                        <p>{{ trans('mining-manager::moons.no_extractions_currently_active') }}</p>
                        <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-info">
                            <i class="fas fa-list"></i> {{ trans('mining-manager::moons.view_all_extractions') }}
                        </a>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- TIMELINE VIEW --}}
    @if($activeExtractions->count() > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-stream"></i>
                        {{ trans('mining-manager::moons.arrival_timeline') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::moons.structure') }}</th>
                                    <th>{{ trans('mining-manager::moons.moon') }}</th>
                                    <th>{{ trans('mining-manager::moons.arrival_time') }}</th>
                                    <th>{{ trans('mining-manager::moons.time_remaining') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::moons.value') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($activeExtractions->sortBy('chunk_arrival_time') as $extraction)
                                <tr>
                                    <td>{{ $extraction->structure_name ?? 'Unknown' }}</td>
                                    <td>
                                        <i class="fas fa-moon text-info"></i>
                                        {{ $extraction->moon_name ?? 'Unknown' }}
                                    </td>
                                    <td>{{ $extraction->chunk_arrival_time->format('M d, Y H:i') }}</td>
                                    <td>
                                        @php
                                            $hoursRemaining = \Carbon\Carbon::now()->diffInHours($extraction->chunk_arrival_time, false);
                                        @endphp
                                        @if($hoursRemaining > 0)
                                            <span class="badge badge-{{ $hoursRemaining < 24 ? 'danger' : 'warning' }}">
                                                {{ floor($hoursRemaining / 24) }}d {{ $hoursRemaining % 24 }}h
                                            </span>
                                        @else
                                            <span class="badge badge-success">{{ trans('mining-manager::moons.ready') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right text-success">
                                        {{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK
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
    @endif

</div>

@push('javascript')
<script>
// Auto-update countdown timers without full page refresh
setInterval(function() {
    // Could implement AJAX updates here for smoother UX
    // For now, the meta refresh tag handles it
}, 30000);

// Add visual feedback for extractions arriving very soon (< 6 hours)
$(document).ready(function() {
    $('.countdown-timer').each(function() {
        const text = $(this).text().trim();
        // Only highlight if 0d and less than 6 hours remaining
        const match = text.match(/(\d+)d\s+(\d+)h/);
        if (match) {
            const days = parseInt(match[1]);
            const hours = parseInt(match[2]);
            if (days === 0 && hours < 6) {
                $(this).addClass('text-warning');
            }
        }
    });
});
</script>
@endpush

    </div>
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
