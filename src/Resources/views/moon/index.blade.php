@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.moon_mining'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper moon-index-page">

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/moon') && !Request::is('*/moon/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.index') }}">
                <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_extractions') }}
            </a>
        </li>
        <li class="{{ Request::is('*/moon/active') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.active') }}">
                <i class="fas fa-hourglass-half"></i> {{ trans('mining-manager::menu.active_extractions') }}
            </a>
        </li>
        <li class="{{ Request::is('*/moon/calendar') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.calendar') }}">
                <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.extraction_calendar') }}
            </a>
        </li>
        <li class="{{ Request::is('*/moon/compositions') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.compositions') }}">
                <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::menu.moon_compositions') }}
            </a>
        </li>
        <li class="{{ Request::is('*/moon/calculator') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.calculator') }}">
                <i class="fas fa-flask"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">


<div class="mining-manager-wrapper moon-extractions">
    
    {{-- QUICK STATS --}}
    <div class="row">
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon">
                    <i class="fas fa-circle-notch fa-spin"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.extracting') }}</span>
                    <span class="info-box-number">{{ $stats['extracting'] ?? 0 }}</span>
                    <small>{{ trans('mining-manager::moons.active_extractions') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon">
                    <i class="fas fa-gem"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.ready') }}</span>
                    <span class="info-box-number">{{ $stats['ready'] ?? 0 }}</span>
                    <small>{{ trans('mining-manager::moons.ready_to_mine') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon">
                    <i class="fas fa-clock"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.upcoming_7d') }}</span>
                    <span class="info-box-number">{{ $upcoming->count() }}</span>
                    <small>{{ trans('mining-manager::moons.next_week') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-secondary">
                <span class="info-box-icon">
                    <i class="fas fa-check-circle"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.completed') }}</span>
                    <span class="info-box-number">{{ $stats['completed'] ?? 0 }}</span>
                    <small>{{ trans('mining-manager::moons.this_month') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- UPCOMING EXTRACTIONS (NEXT 7 DAYS) --}}
    @if($upcoming->count() > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-week"></i>
                        {{ trans('mining-manager::moons.upcoming_extractions') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-warning">{{ $upcoming->count() }} {{ trans('mining-manager::moons.upcoming') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::moons.structure') }}</th>
                                    <th>{{ trans('mining-manager::moons.moon') }}</th>
                                    <th>{{ trans('mining-manager::moons.chunk_arrival') }}</th>
                                    <th>{{ trans('mining-manager::moons.time_remaining') }}</th>
                                    <th>{{ trans('mining-manager::moons.estimated_value') }}</th>
                                    <th>{{ trans('mining-manager::moons.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($upcoming as $extraction)
                                <tr>
                                    <td>
                                        {{ $extraction->structure_name ?? 'Unknown' }}
                                    </td>
                                    <td>
                                        <i class="fas fa-moon text-info"></i>
                                        {{ $extraction->moon_name ?? 'N/A' }}
                                    </td>
                                    <td>
                                        {{ $extraction->chunk_arrival_time->format('M d, Y H:i') }}
                                    </td>
                                    <td>
                                        <span class="countdown text-warning">
                                            {{ $extraction->chunk_arrival_time->diffForHumans() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($extraction->ore_composition)
                                            <span class="text-success">~{{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK</span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('mining-manager.moon.show', $extraction->id) }}" class="btn btn-sm btn-info">
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
    @endif

    {{-- FILTERS & ACTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i>
                        {{ trans('mining-manager::moons.filters') }}
                    </h3>
                    <div class="card-tools">
                        @can('mining-manager.director')
                        <form action="{{ route('mining-manager.moon.refresh-all') }}" method="POST" style="display: inline;">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::moons.refresh_all') }}
                            </button>
                        </form>
                        @endcan
                        <a href="{{ route('mining-manager.moon.calendar') }}" class="btn btn-sm btn-info">
                            <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::moons.calendar_view') }}
                        </a>
                        <a href="{{ route('mining-manager.moon.compositions') }}" class="btn btn-sm btn-success">
                            <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::moons.compositions') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.moon.index') }}">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{ trans('mining-manager::moons.status') }}</label>
                                    <select name="status" class="form-control">
                                        <option value="all" {{ $status === 'all' ? 'selected' : '' }}>{{ trans('mining-manager::moons.all') }}</option>
                                        <option value="extracting" {{ $status === 'extracting' ? 'selected' : '' }}>{{ trans('mining-manager::moons.extracting') }}</option>
                                        <option value="ready" {{ $status === 'ready' ? 'selected' : '' }}>{{ trans('mining-manager::moons.ready') }}</option>
                                        <option value="completed" {{ $status === 'completed' ? 'selected' : '' }}>{{ trans('mining-manager::moons.completed') }} ({{ trans('mining-manager::moons.past') }})</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i> {{ trans('mining-manager::moons.filter') }}
                                        </button>
                                        <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> {{ trans('mining-manager::moons.clear') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ALL EXTRACTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        {{ trans('mining-manager::moons.all_extractions') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info">{{ $extractions->total() }} {{ trans('mining-manager::moons.total') }}</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    @forelse($extractions as $extraction)
                    <div class="extraction-card card mb-2 status-{{ $extraction->status }}">
                        <div class="card-body">
                            <div class="row align-items-center">
                                {{-- Structure & Moon Info --}}
                                <div class="col-md-4">
                                    <h5 class="mb-1">
                                        <i class="fas fa-building text-primary"></i>
                                        {{ $extraction->structure_name ?? 'Unknown Structure' }}
                                    </h5>
                                    <p class="mb-0 text-muted">
                                        <i class="fas fa-moon text-info"></i>
                                        {{ $extraction->moon_name ?? 'Unknown Moon' }}
                                    </p>
                                </div>

                                {{-- Status & Times --}}
                                <div class="col-md-4">
                                    <div class="mb-2">
                                        @php $effectiveStatus = $extraction->getEffectiveStatus(); @endphp
                                        <span class="status-badge badge badge-{{
                                            $effectiveStatus === 'extracting' ? 'warning' :
                                            ($effectiveStatus === 'ready' ? 'success' :
                                            ($effectiveStatus === 'unstable' ? 'warning mm-badge-unstable' :
                                            ($effectiveStatus === 'expired' ? 'dark' : 'secondary')))
                                        }}">
                                            {{ trans('mining-manager::moons.' . $effectiveStatus) }}
                                        </span>
                                        @if($extraction->is_jackpot)
                                            <span class="badge badge-warning ml-1" style="background: linear-gradient(45deg, #ffd700, #ffed4e); color: #000;">
                                                <i class="fas fa-star"></i> JACKPOT
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mb-1">
                                        <strong>{{ trans('mining-manager::moons.chunk_arrival') }}:</strong><br>
                                        {{ $extraction->chunk_arrival_time->format('M d, Y H:i') }}
                                        @if($extraction->chunk_arrival_time->isFuture())
                                            <span class="text-warning">({{ $extraction->chunk_arrival_time->diffForHumans() }})</span>
                                        @endif
                                    </p>
                                    @if($extraction->natural_decay_time)
                                    <p class="mb-0 text-muted small">
                                        <strong>{{ trans('mining-manager::moons.auto_fracture') }}:</strong> {{ $extraction->natural_decay_time->format('M d, H:i') }}
                                        @if($extraction->isUnstable())
                                            <span class="badge badge-warning ml-1">{{ trans('mining-manager::moons.unstable') }}</span>
                                        @endif
                                    </p>
                                    @endif
                                </div>

                                {{-- Value & Actions --}}
                                <div class="col-md-4 text-right">
                                    @if($extraction->ore_composition)
                                        <h4 class="mb-2 text-success">
                                            ~{{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK
                                        </h4>
                                        <p class="mb-2 text-muted small">{{ trans('mining-manager::moons.estimated_value') }}</p>
                                    @else
                                        <p class="mb-2 text-muted">{{ trans('mining-manager::moons.no_composition_data') }}</p>
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
                        </div>
                    </div>
                    @empty
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-moon fa-3x mb-3"></i>
                        <h4>{{ trans('mining-manager::moons.no_extractions') }}</h4>
                        <p>{{ trans('mining-manager::moons.no_extractions_message') }}</p>
                    </div>
                    @endforelse
                </div>
                @if($extractions->hasPages())
                <div class="card-footer">
                    {{ $extractions->appends(request()->query())->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ARCHIVED PAST EXTRACTIONS (from history table) --}}
    @if(isset($historyExtractions) && $historyExtractions->count() > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-secondary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-archive"></i>
                        {{ trans('mining-manager::moons.past_extractions') }} (Archived)
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-secondary">{{ $historyExtractions->count() }} archived</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::moons.moon') }}</th>
                                    <th>{{ trans('mining-manager::moons.chunk_arrival') }}</th>
                                    <th>{{ trans('mining-manager::moons.status') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::moons.estimated_value') }}</th>
                                    <th class="text-right">Actual Mined</th>
                                    <th class="text-center">Completion</th>
                                    <th>Archived</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($historyExtractions as $history)
                                <tr>
                                    <td>
                                        <i class="fas fa-moon text-secondary"></i>
                                        @php
                                            $moonName = 'Unknown Moon';
                                            if ($history->moon_id) {
                                                $moon = \DB::table('moons')->where('moon_id', $history->moon_id)->first();
                                                $moonName = $moon ? $moon->name : "Moon {$history->moon_id}";
                                            }
                                        @endphp
                                        {{ $moonName }}
                                    </td>
                                    <td>
                                        {{ $history->chunk_arrival_time->format('M d, Y H:i') }}
                                    </td>
                                    <td>
                                        <span class="badge badge-{{
                                            $history->final_status === 'fractured' ? 'success' :
                                            ($history->final_status === 'expired' ? 'dark' : 'secondary')
                                        }}">
                                            {{ ucfirst($history->final_status) }}
                                        </span>
                                        @if($history->is_jackpot)
                                            <span class="badge badge-warning ml-1" style="background: linear-gradient(45deg, #ffd700, #ffed4e); color: #000;">
                                                <i class="fas fa-star"></i> JACKPOT
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if($history->final_estimated_value)
                                            <span class="text-success">{{ number_format($history->final_estimated_value, 0) }} ISK</span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if($history->actual_mined_value)
                                            <span class="text-info">{{ number_format($history->actual_mined_value, 0) }} ISK</span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($history->completion_percentage > 0)
                                            <div class="progress" style="height: 18px; min-width: 60px;">
                                                <div class="progress-bar bg-{{ $history->completion_percentage >= 80 ? 'success' : ($history->completion_percentage >= 50 ? 'warning' : 'danger') }}"
                                                     style="width: {{ min($history->completion_percentage, 100) }}%">
                                                    {{ number_format($history->completion_percentage, 0) }}%
                                                </div>
                                            </div>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        <small class="text-muted">{{ $history->archived_at->diffForHumans() }}</small>
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
// Auto-refresh countdown timers
setInterval(function() {
    $('.countdown').each(function() {
        // Simple refresh - in production you might want to update via AJAX
    });
}, 60000); // Update every minute
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
