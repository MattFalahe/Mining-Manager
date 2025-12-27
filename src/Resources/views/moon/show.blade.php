@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.extraction_details'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .ore-bar {
        height: 30px;
        border-radius: 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
    }
    .timer-box {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        color: white;
    }
    .timer-box h2 {
        font-size: 2.5rem;
        margin: 0;
        font-family: 'Courier New', monospace;
    }
    .composition-chart {
        height: 300px;
    }
</style>
@endpush

@section('full')


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
                <i class="fas fa-coins"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">


<div class="extraction-details">
    
    {{-- BACK BUTTON --}}
    <div class="row mb-3">
        <div class="col-12">
            <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> {{ trans('mining-manager::moons.back_to_list') }}
            </a>
            @can('mining-manager.moon.update')
            <form action="{{ route('mining-manager.moon.update', $extraction->id) }}" method="POST" style="display: inline;">
                @csrf
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::moons.refresh_data') }}
                </button>
            </form>
            @endcan
        </div>
    </div>

    {{-- MAIN INFORMATION --}}
    <div class="row">
        <div class="col-md-8">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i>
                        {{ trans('mining-manager::moons.extraction_information') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-{{ 
                            $extraction->status === 'extracting' ? 'warning' : 
                            ($extraction->status === 'ready' ? 'success' : 'secondary') 
                        }}">
                            {{ trans('mining-manager::moons.' . $extraction->status) }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>{{ trans('mining-manager::moons.structure') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                <i class="fas fa-building text-primary"></i>
                                {{ $extraction->structure_name ?? 'Unknown' }}
                            </p>

                            <p><strong>{{ trans('mining-manager::moons.moon') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                <i class="fas fa-moon text-info"></i>
                                {{ $extraction->moon_name ?? 'Unknown' }}
                            </p>

                            <p><strong>{{ trans('mining-manager::moons.corporation') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                {{ $extraction->corporation->name ?? 'Unknown' }}
                            </p>

                            <p><strong>{{ trans('mining-manager::moons.status') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                <span class="badge badge-lg badge-{{ 
                                    $extraction->status === 'extracting' ? 'warning' : 
                                    ($extraction->status === 'ready' ? 'success' : 'secondary') 
                                }}">
                                    {{ trans('mining-manager::moons.' . $extraction->status) }}
                                </span>
                            </p>
                        </div>

                        <div class="col-md-6">
                            <p><strong>{{ trans('mining-manager::moons.extraction_started') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                {{ $extraction->extraction_start_time->format('M d, Y H:i') }}<br>
                                <small class="text-muted">{{ $extraction->extraction_start_time->diffForHumans() }}</small>
                            </p>

                            <p><strong>{{ trans('mining-manager::moons.chunk_arrival') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                {{ $extraction->chunk_arrival_time->format('M d, Y H:i') }}<br>
                                <small class="text-muted">{{ $extraction->chunk_arrival_time->diffForHumans() }}</small>
                            </p>

                            <p><strong>{{ trans('mining-manager::moons.natural_decay') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                {{ $extraction->natural_decay_time->format('M d, Y H:i') }}<br>
                                <small class="text-muted">{{ $extraction->natural_decay_time->diffForHumans() }}</small>
                            </p>

                            @if($estimatedValue)
                            <p><strong>{{ trans('mining-manager::moons.estimated_value') }}:</strong></p>
                            <p class="ml-3 mb-3">
                                <span class="h4 text-success">{{ number_format($estimatedValue, 0) }} ISK</span>
                            </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- COUNTDOWN TIMERS --}}
        <div class="col-md-4">
            @if($timeUntilArrival !== null && $timeUntilArrival > 0)
            <div class="card card-warning card-outline mb-3">
                <div class="card-body p-0">
                    <div class="timer-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <p class="mb-2">{{ trans('mining-manager::moons.chunk_arrives_in') }}</p>
                        <h2>{{ floor($timeUntilArrival / 24) }}d {{ $timeUntilArrival % 24 }}h</h2>
                        <small>{{ $extraction->chunk_arrival_time->format('M d, H:i') }}</small>
                    </div>
                </div>
            </div>
            @endif

            @if($timeUntilDecay !== null && $timeUntilDecay > 0)
            <div class="card card-danger card-outline">
                <div class="card-body p-0">
                    <div class="timer-box" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                        <p class="mb-2">{{ trans('mining-manager::moons.decays_in') }}</p>
                        <h2>{{ floor($timeUntilDecay / 24) }}d {{ $timeUntilDecay % 24 }}h</h2>
                        <small>{{ $extraction->natural_decay_time->format('M d, H:i') }}</small>
                    </div>
                </div>
            </div>
            @endif

            @if($extraction->status === 'ready')
            <div class="card card-success">
                <div class="card-body text-center">
                    <i class="fas fa-gem fa-3x mb-2 text-success"></i>
                    <h4>{{ trans('mining-manager::moons.ready_to_mine') }}!</h4>
                    <p class="text-muted">{{ trans('mining-manager::moons.chunk_available') }}</p>
                </div>
            </div>
            @endif
        </div>
    </div>

    {{-- ORE COMPOSITION --}}
    @if($extraction->ore_composition)
    <div class="row">
        <div class="col-12">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::moons.ore_composition') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::moons.ore_type') }}</th>
                                    <th style="width: 50%;">{{ trans('mining-manager::moons.percentage') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::moons.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::moons.value') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $composition = is_string($extraction->ore_composition) 
                                        ? json_decode($extraction->ore_composition, true) 
                                        : $extraction->ore_composition;
                                    $colors = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22', '#34495e'];
                                    $colorIndex = 0;
                                @endphp
                                @foreach($composition as $oreType => $data)
                                <tr>
                                    <td>
                                        <i class="fas fa-cube" style="color: {{ $colors[$colorIndex % count($colors)] }}"></i>
                                        {{ $oreType }}
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 25px;">
                                            <div class="ore-bar progress-bar" 
                                                 style="width: {{ $data['percentage'] }}%; background-color: {{ $colors[$colorIndex % count($colors)] }};">
                                                {{ number_format($data['percentage'], 2) }}%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        {{ number_format($data['quantity'] ?? 0, 0) }}
                                    </td>
                                    <td class="text-right text-success">
                                        {{ number_format($data['value'] ?? 0, 0) }} ISK
                                    </td>
                                </tr>
                                @php $colorIndex++; @endphp
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="font-weight-bold">
                                    <td colspan="3" class="text-right">{{ trans('mining-manager::moons.total_value') }}:</td>
                                    <td class="text-right text-success h5">
                                        {{ number_format($estimatedValue ?? 0, 0) }} ISK
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- Composition Visualization --}}
                    <div class="mt-4">
                        <h5>{{ trans('mining-manager::moons.composition_breakdown') }}</h5>
                        <div class="composition-chart">
                            <canvas id="compositionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @else
    <div class="row">
        <div class="col-12">
            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ trans('mining-manager::moons.no_composition_data') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h5><i class="fas fa-info-circle"></i> How to Get Moon Composition Data:</h5>
                        <ol>
                            <li><strong>Scan the Moon:</strong> Use a Survey Scanner probe in-game to scan this moon</li>
                            <li><strong>Wait for Sync:</strong> The composition data will be sent to ESI automatically</li>
                            <li><strong>Refresh SeAT:</strong> Wait for SeAT to sync (usually happens automatically within a few minutes)</li>
                            <li><strong>Update This Page:</strong> Click the "Refresh Data" button above to fetch the new composition</li>
                        </ol>
                    </div>
                    <p class="text-muted">
                        <i class="fas fa-moon"></i> <strong>Moon:</strong> {{ $extraction->moon_name ?? 'Unknown' }}<br>
                        <i class="fas fa-hashtag"></i> <strong>Moon ID:</strong> {{ $extraction->moon_id ?? 'N/A' }}
                    </p>
                    <p class="text-muted">
                        Once this moon is scanned, the ore composition, percentages, and estimated value will appear here.
                    </p>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- EXTRACTION HISTORY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        {{ trans('mining-manager::moons.extraction_history') }}
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('mining-manager.moon.extractions', $extraction->structure_id) }}" class="btn btn-sm btn-info">
                            <i class="fas fa-list"></i> {{ trans('mining-manager::moons.view_all') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-muted">{{ trans('mining-manager::moons.view_structure_history') }}</p>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
@if($extraction->ore_composition)
// Composition Chart
const ctx = document.getElementById('compositionChart');
const composition = @json($composition ?? []);

const labels = Object.keys(composition);
const data = labels.map(ore => composition[ore].percentage);
const backgroundColors = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22', '#34495e'];

new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: labels,
        datasets: [{
            data: data,
            backgroundColor: backgroundColors,
            borderColor: '#343a40',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    color: '#fff',
                    font: {
                        size: 12
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed || 0;
                        return label + ': ' + value.toFixed(2) + '%';
                    }
                }
            }
        }
    }
});
@endif
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

@endsection
