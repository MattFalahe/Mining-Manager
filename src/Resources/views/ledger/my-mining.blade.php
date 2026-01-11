@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.my_mining'))
@section('page_header', trans_choice('mining-manager::ledger.mining_ledger', 2))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/ledger') && !Request::is('*/ledger/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.ledger.index') }}">
                <i class="fas fa-list"></i> {{ trans('mining-manager::menu.view_ledger') }}
            </a>
        </li>
        <li class="{{ Request::is('*/ledger/my-mining') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.ledger.my-mining') }}">
                <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
            </a>
        </li>
        @can('mining-manager.ledger.process')
        <li class="{{ Request::is('*/ledger/process') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.ledger.process') }}">
                <i class="fas fa-cogs"></i> {{ trans('mining-manager::menu.process_ledger') }}
            </a>
        </li>
        @endcan
    </ul>
    <div class="tab-content">

<div class="mining-manager-wrapper my-mining">
    
    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::ledger.your_mining_summary') }}
                    </h3>
                    <div class="card-tools">
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-primary" onclick="changePeriod('week')">{{ trans('mining-manager::ledger.week') }}</button>
                            <button type="button" class="btn btn-sm btn-primary active" onclick="changePeriod('month')">{{ trans('mining-manager::ledger.month') }}</button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="changePeriod('year')">{{ trans('mining-manager::ledger.year') }}</button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="changePeriod('all')">{{ trans('mining-manager::ledger.all_time') }}</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Total Value Mined --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-primary">
                                <div class="inner">
                                    <h3>{{ number_format($stats['total_value'] ?? 0, 0) }}</h3>
                                    <p>{{ trans('mining-manager::ledger.total_value_mined') }} (ISK)</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-coins"></i>
                                </div>
                            </div>
                        </div>

                        {{-- Total Quantity --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-success">
                                <div class="inner">
                                    <h3>{{ number_format($stats['total_quantity'] ?? 0, 0) }}</h3>
                                    <p>{{ trans('mining-manager::ledger.units_mined') }}</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-cubes"></i>
                                </div>
                            </div>
                        </div>

                        {{-- Mining Sessions --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-info">
                                <div class="inner">
                                    <h3>{{ $stats['total_sessions'] ?? 0 }}</h3>
                                    <p>{{ trans('mining-manager::ledger.mining_sessions') }}</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-history"></i>
                                </div>
                            </div>
                        </div>

                        {{-- Active Days --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-warning">
                                <div class="inner">
                                    <h3>{{ $stats['active_days'] ?? 0 }}</h3>
                                    <p>{{ trans('mining-manager::ledger.active_days') }}</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Additional Stats Row --}}
                    <div class="row">
                        <div class="col-md-3">
                            <div class="info-box bg-dark">
                                <span class="info-box-icon bg-gradient-primary"><i class="fas fa-gem"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.favorite_ore') }}</span>
                                    <span class="info-box-number">{{ $stats['favorite_ore']['name'] ?? 'N/A' }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-dark">
                                <span class="info-box-icon bg-gradient-success"><i class="fas fa-trophy"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.best_day') }}</span>
                                    <span class="info-box-number">{{ number_format($stats['best_day_value'] ?? 0, 0) }} ISK</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-dark">
                                <span class="info-box-icon bg-gradient-info"><i class="fas fa-chart-line"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.daily_average') }}</span>
                                    <span class="info-box-number">{{ number_format($stats['daily_average'] ?? 0, 0) }} ISK</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-dark">
                                <span class="info-box-icon bg-gradient-warning"><i class="fas fa-star"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.corp_rank') }}</span>
                                    <span class="info-box-number">#{{ $stats['corp_rank'] ?? '-' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- CHARTS --}}
    <div class="row">
        {{-- Mining Trend Chart --}}
        <div class="col-md-8">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::ledger.mining_trend') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="miningTrendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        {{-- Ore Distribution Chart --}}
        <div class="col-md-4">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::ledger.ore_distribution') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="oreDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- CHARACTER BREAKDOWN --}}
    @if(count($characterStats ?? []) > 1)
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::ledger.character_breakdown') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::ledger.character') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.total_value') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.sessions') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.percentage') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($characterStats as $charStat)
                                <tr>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $charStat['character_id'] }}/portrait?size=32" 
                                             class="img-circle" 
                                             style="width: 32px; height: 32px;">
                                        {{ $charStat['name'] }}
                                    </td>
                                    <td class="text-right">{{ number_format($charStat['total_value'], 0) }} ISK</td>
                                    <td class="text-right">{{ number_format($charStat['quantity'], 0) }}</td>
                                    <td class="text-right">{{ $charStat['sessions'] }}</td>
                                    <td class="text-right">{{ number_format($charStat['percentage'], 1) }}%</td>
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

    {{-- RECENT ACTIVITY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-clock"></i>
                        {{ trans('mining-manager::ledger.recent_activity') }}
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('mining-manager.ledger.index', ['character_id' => auth()->user()->id]) }}" class="btn btn-sm btn-info">
                            <i class="fas fa-list"></i> {{ trans('mining-manager::ledger.view_all') }}
                        </a>
                        <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                            <i class="fas fa-print"></i> {{ trans('mining-manager::ledger.print') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-success" onclick="exportPersonalData()">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::ledger.export') }}
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::ledger.date') }}</th>
                                    <th>{{ trans('mining-manager::ledger.character') }}</th>
                                    <th>{{ trans('mining-manager::ledger.ore_type') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.value') }}</th>
                                    <th>{{ trans('mining-manager::ledger.system') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentActivity ?? [] as $activity)
                                <tr>
                                    <td>
                                        <small>{{ $activity->date->format('Y-m-d H:i') }}</small>
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $activity->character_id }}/portrait?size=32" 
                                             class="img-circle" 
                                             style="width: 32px; height: 32px;">
                                        {{ $activity->character->name ?? 'Unknown' }}
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/types/{{ $activity->type_id }}/icon?size=32" 
                                             style="width: 32px; height: 32px;">
                                        {{ $activity->type_name ?? 'Unknown' }}
                                        @if($activity->is_moon_ore)
                                            <span class="badge badge-secondary">
                                                <i class="fas fa-moon"></i>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($activity->quantity, 0) }}</td>
                                    <td class="text-right">
                                        <strong>{{ number_format($activity->total_value, 0) }}</strong>
                                        <small class="text-muted">ISK</small>
                                    </td>
                                    <td><small>{{ $activity->solar_system_name ?? 'Unknown' }}</small></td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 mt-3"></i>
                                        <p>{{ trans('mining-manager::ledger.no_recent_activity') }}</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- MILESTONES & ACHIEVEMENTS --}}
    <div class="row">
        <div class="col-md-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-trophy"></i>
                        {{ trans('mining-manager::ledger.milestones') }}
                    </h3>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        @foreach($milestones ?? [] as $milestone)
                        <li class="list-group-item bg-dark d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-{{ $milestone['icon'] }} text-{{ $milestone['color'] }}"></i>
                                {{ $milestone['title'] }}
                            </span>
                            @if($milestone['achieved'])
                                <span class="badge badge-success">
                                    <i class="fas fa-check"></i> {{ trans('mining-manager::ledger.achieved') }}
                                </span>
                            @else
                                <span class="badge badge-secondary">
                                    {{ $milestone['progress'] }}%
                                </span>
                            @endif
                        </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::ledger.monthly_comparison') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 250px;">
                        <canvas id="monthlyComparisonChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// Change period function
function changePeriod(period) {
    window.location.href = '{{ route("mining-manager.ledger.my-mining") }}?period=' + period;
}

// Export personal data
function exportPersonalData() {
    window.location.href = '{{ route("mining-manager.ledger.export-personal") }}';
}

// Mining Trend Chart
const trendCtx = document.getElementById('miningTrendChart').getContext('2d');
const trendChart = new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: @json($trendData['labels'] ?? []),
        datasets: [{
            label: '{{ trans("mining-manager::ledger.daily_value") }}',
            data: @json($trendData['values'] ?? []),
            borderColor: 'rgba(0, 210, 255, 1)',
            backgroundColor: 'rgba(0, 210, 255, 0.1)',
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
                display: true,
                labels: { color: '#fff' }
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

// Ore Distribution Chart
const distributionCtx = document.getElementById('oreDistributionChart').getContext('2d');
const distributionChart = new Chart(distributionCtx, {
    type: 'doughnut',
    data: {
        labels: @json($oreDistribution['labels'] ?? []),
        datasets: [{
            data: @json($oreDistribution['values'] ?? []),
            backgroundColor: [
                'rgba(0, 210, 255, 0.8)',
                'rgba(255, 0, 132, 0.8)',
                'rgba(161, 198, 60, 0.8)',
                'rgba(255, 159, 64, 0.8)',
                'rgba(255, 205, 86, 0.8)',
                'rgba(75, 192, 192, 0.8)',
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { 
                position: 'right',
                labels: { color: '#fff' }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const value = context.parsed;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((value / total) * 100).toFixed(1);
                        return context.label + ': ' + value.toLocaleString() + ' ISK (' + percentage + '%)';
                    }
                }
            }
        }
    }
});

// Monthly Comparison Chart
const comparisonCtx = document.getElementById('monthlyComparisonChart').getContext('2d');
const comparisonChart = new Chart(comparisonCtx, {
    type: 'bar',
    data: {
        labels: @json($monthlyComparison['labels'] ?? []),
        datasets: [{
            label: '{{ trans("mining-manager::ledger.monthly_value") }}',
            data: @json($monthlyComparison['values'] ?? []),
            backgroundColor: 'rgba(0, 210, 255, 0.6)',
            borderColor: 'rgba(0, 210, 255, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { 
                display: false
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

@endsection
