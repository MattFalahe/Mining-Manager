@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.structure_extractions'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard moon-extractions-page">

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


<div class="structure-extractions">
    
    {{-- BACK BUTTON --}}
    <div class="row mb-3">
        <div class="col-12">
            <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> {{ trans('mining-manager::moons.back_to_list') }}
            </a>
        </div>
    </div>

    {{-- STRUCTURE INFORMATION --}}
    @if($extractions->isNotEmpty())
    <div class="row">
        <div class="col-12">
            <div class="structure-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h2 class="mb-2">
                            <i class="fas fa-building"></i>
                            {{ $extractions->first()->structure_name }}
                        </h2>
                        <p class="mb-1">
                            <i class="fas fa-map-marker-alt"></i>
                            @php
                                $moonNameStr = $extractions->first()->moon_name ?? '';
                                $systemName = preg_match('/^(.+?)\s+[IVXLCDM]+\s+-/', $moonNameStr, $m) ? $m[1] : 'Unknown System';
                            @endphp
                            {{ $systemName }}
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-shield-alt"></i>
                            @php
                                $typeName = 'Unknown Type';
                                if ($structure && $structure->type_id) {
                                    $invType = \Seat\Eveapi\Models\Sde\InvType::find($structure->type_id);
                                    $typeName = $invType->typeName ?? "Type #{$structure->type_id}";
                                }
                            @endphp
                            {{ $typeName }}
                        </p>
                    </div>
                    <div class="col-md-4 text-right">
                        <div class="h3 mb-2">
                            {{ $extractions->total() }}
                        </div>
                        <p class="mb-0">{{ trans('mining-manager::moons.total_extractions') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- EXTRACTION STATISTICS --}}
    <div class="row mb-3">
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon">
                    <i class="fas fa-check-circle"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.completed') }}</span>
                    <span class="info-box-number">
                        {{ $extractions->where('status', 'completed')->count() }}
                    </span>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon">
                    <i class="fas fa-circle-notch fa-spin"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.extracting') }}</span>
                    <span class="info-box-number">
                        {{ $extractions->where('status', 'extracting')->count() }}
                    </span>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon">
                    <i class="fas fa-gem"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.ready') }}</span>
                    <span class="info-box-number">
                        {{ $extractions->where('status', 'ready')->count() }}
                    </span>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-primary">
                <span class="info-box-icon">
                    <i class="fas fa-coins"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.avg_value') }}</span>
                    <span class="info-box-number">
                        @php
                            $extractionsWithValue = $extractions->filter(function($e) {
                                $value = $e->calculated_value ?? $e->estimated_value ?? 0;
                                return $value > 0;
                            });
                            $avgValue = $extractionsWithValue->count() > 0
                                ? $extractionsWithValue->avg('calculated_value')
                                : 0;
                        @endphp
                        {{ number_format($avgValue, 0) }}
                    </span>
                    <small>ISK</small>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- EXTRACTIONS TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        {{ trans('mining-manager::moons.extraction_history') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info">{{ $extractions->total() }} {{ trans('mining-manager::moons.extractions') }}</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::moons.extraction_id') }}</th>
                                    <th>{{ trans('mining-manager::moons.moon') }}</th>
                                    <th>{{ trans('mining-manager::moons.status') }}</th>
                                    <th>{{ trans('mining-manager::moons.started') }}</th>
                                    <th>{{ trans('mining-manager::moons.chunk_arrival') }}</th>
                                    <th>{{ trans('mining-manager::moons.auto_fracture') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::moons.estimated_value') }}</th>
                                    <th>{{ trans('mining-manager::moons.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($extractions as $extraction)
                                <tr class="extraction-row">
                                    <td>
                                        <code>#{{ $extraction->id }}</code>
                                    </td>
                                    <td>
                                        <i class="fas fa-moon text-info"></i>
                                        {{ $extraction->moon_name ?? 'Unknown Moon' }}
                                    </td>
                                    <td>
                                        @php $effectiveStatus = $extraction->getEffectiveStatus(); @endphp
                                        <span class="badge badge-{{
                                            $effectiveStatus === 'extracting' ? 'warning' :
                                            ($effectiveStatus === 'ready' ? 'success' :
                                            ($effectiveStatus === 'unstable' ? 'warning mm-badge-unstable' : 'secondary'))
                                        }}">
                                            {{ trans('mining-manager::moons.' . $effectiveStatus) }}
                                        </span>
                                    </td>
                                    <td>
                                        {{ $extraction->extraction_start_time->format('M d, Y') }}<br>
                                        <small class="text-muted">{{ $extraction->extraction_start_time->format('H:i') }}</small>
                                    </td>
                                    <td>
                                        {{ $extraction->chunk_arrival_time->format('M d, Y') }}<br>
                                        <small class="text-muted">{{ $extraction->chunk_arrival_time->format('H:i') }}</small>
                                        @if($extraction->chunk_arrival_time->isFuture())
                                            <br><span class="badge badge-warning">{{ $extraction->chunk_arrival_time->diffForHumans() }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $extraction->natural_decay_time->format('M d, Y') }}<br>
                                        <small class="text-muted">{{ $extraction->natural_decay_time->format('H:i') }}</small>
                                    </td>
                                    <td class="text-right">
                                        @if($extraction->ore_composition)
                                            <span class="text-success font-weight-bold">
                                                {{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK
                                            </span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="{{ route('mining-manager.moon.show', $extraction->id) }}" 
                                               class="btn btn-xs btn-info"
                                               title="{{ trans('mining-manager::moons.view_details') }}">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            @can('mining-manager.director')
                                            <form action="{{ route('mining-manager.moon.update', $extraction->id) }}" 
                                                  method="POST" 
                                                  style="display: inline;">
                                                @csrf
                                                <button type="submit" 
                                                        class="btn btn-xs btn-primary"
                                                        title="{{ trans('mining-manager::moons.refresh') }}">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                            </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="fas fa-moon fa-3x mb-3"></i>
                                        <h4>{{ trans('mining-manager::moons.no_extractions') }}</h4>
                                        <p>{{ trans('mining-manager::moons.no_extractions_for_structure') }}</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($extractions->hasPages())
                <div class="card-footer">
                    {{ $extractions->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- VALUE OVER TIME CHART --}}
    @if($extractions->count() > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::moons.value_over_time') }}
                    </h3>
                </div>
                <div class="card-body">
                    <canvas id="valueChart" style="height: 300px;"></canvas>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::moons.note_value_over_time') }}</small>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

@push('javascript')
@if($extractions->count() > 0)
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// Value Over Time Chart
const ctx = document.getElementById('valueChart');
const extractions = @json($extractions->sortBy('extraction_start_time')->values()->map(function($e) {
    return [
        'date' => $e->extraction_start_time->format('M d, Y'),
        'value' => $e->calculated_value ?? $e->estimated_value ?? 0
    ];
}));

const labels = extractions.map(e => e.date);
const data = extractions.map(e => e.value);

new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: '{{ trans("mining-manager::moons.extraction_value") }}',
            data: data,
            borderColor: '#28a745',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: '#fff'
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.parsed.y.toLocaleString() + ' ISK';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    color: '#fff',
                    callback: function(value) {
                        return value.toLocaleString() + ' ISK';
                    }
                },
                grid: {
                    color: 'rgba(255, 255, 255, 0.1)'
                }
            },
            x: {
                ticks: {
                    color: '#fff',
                    maxRotation: 45,
                    minRotation: 45
                },
                grid: {
                    color: 'rgba(255, 255, 255, 0.1)'
                }
            }
        }
    }
});
</script>
@endif
@endpush

    </div>
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
