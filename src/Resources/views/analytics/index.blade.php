@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::analytics.analytics_overview'))
@section('page_header', trans('mining-manager::menu.analytics'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
<style>
    .stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 10px;
        padding: 25px;
        color: white;
        transition: transform 0.3s;
    }
    .stat-card:hover {
        transform: translateY(-5px);
    }
    .stat-card h3 {
        font-size: 2.5rem;
        margin: 0;
        font-weight: bold;
    }
    .chart-container {
        height: 350px;
        position: relative;
    }
    .leaderboard-card {
        transition: all 0.3s;
        border-left: 4px solid transparent;
    }
    .leaderboard-card:hover {
        border-left-color: #ffc107;
        background: rgba(255, 193, 7, 0.1);
    }
    .rank-badge {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: bold;
    }
    .rank-1 { background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%); }
    .rank-2 { background: linear-gradient(135deg, #C0C0C0 0%, #808080 100%); }
    .rank-3 { background: linear-gradient(135deg, #CD7F32 0%, #8B4513 100%); }
    .rank-other { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard analytics-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics') && !Request::is('*/analytics/*') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.index') }}">
                    <i class="fas fa-chart-area"></i> {{ trans('mining-manager::menu.analytics_overview') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/charts') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.charts') }}">
                    <i class="fas fa-chart-line"></i> {{ trans('mining-manager::menu.performance_charts') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/moons') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.moons') }}">
                    <i class="fas fa-moon"></i> {{ trans('mining-manager::analytics.moon_analytics') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/tables') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.tables') }}">
                    <i class="fas fa-table"></i> {{ trans('mining-manager::menu.data_tables') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/compare') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.compare') }}">
                    <i class="fas fa-balance-scale"></i> {{ trans('mining-manager::menu.comparative_analysis') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">


<div class="mining-manager-wrapper analytics-overview">
    
    {{-- DATE RANGE FILTER --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.analytics.index') }}" class="form-inline">
                        <div class="form-group mr-3">
                            <label class="mr-2">{{ trans('mining-manager::analytics.start_date') }}</label>
                            <input type="date" name="start_date" class="form-control" value="{{ $startDate->format('Y-m-d') }}">
                        </div>
                        <div class="form-group mr-3">
                            <label class="mr-2">{{ trans('mining-manager::analytics.end_date') }}</label>
                            <input type="date" name="end_date" class="form-control" value="{{ $endDate->format('Y-m-d') }}">
                        </div>
                        <button type="submit" class="btn btn-primary mr-2">
                            <i class="fas fa-filter"></i> {{ trans('mining-manager::analytics.filter') }}
                        </button>
                        <div class="btn-group mr-2">
                            <button type="button" class="btn btn-secondary quick-filter" data-days="7">7d</button>
                            <button type="button" class="btn btn-secondary quick-filter" data-days="30">30d</button>
                            <button type="button" class="btn btn-secondary quick-filter" data-days="90">90d</button>
                        </div>
                        <a href="{{ route('mining-manager.analytics.charts') }}" class="btn btn-info mr-2">
                            <i class="fas fa-chart-line"></i> {{ trans('mining-manager::analytics.charts') }}
                        </a>
                        <a href="{{ route('mining-manager.analytics.tables') }}" class="btn btn-success">
                            <i class="fas fa-table"></i> {{ trans('mining-manager::analytics.tables') }}
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- KEY STATISTICS --}}
    <div class="row">
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="mb-1 opacity-75">{{ trans('mining-manager::analytics.total_quantity') }}</p>
                        <h3>{{ number_format($analytics['total_volume'] ?? 0, 0) }}</h3>
                        <small>{{ trans('mining-manager::analytics.units') }}</small>
                    </div>
                    <i class="fas fa-cube fa-3x opacity-50"></i>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="mb-1 opacity-75">{{ trans('mining-manager::analytics.total_value') }}</p>
                        <h3>{{ number_format(($analytics['total_value'] ?? 0) / 1000000000, 2) }}</h3>
                        <small>{{ trans('mining-manager::analytics.billion_isk') }}</small>
                    </div>
                    <i class="fas fa-coins fa-3x opacity-50"></i>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="mb-1 opacity-75">{{ trans('mining-manager::analytics.unique_miners') }}</p>
                        <h3>{{ $analytics['unique_miners'] ?? 0 }}</h3>
                        <small>{{ trans('mining-manager::analytics.active_characters') }}</small>
                    </div>
                    <i class="fas fa-users fa-3x opacity-50"></i>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="mb-1 opacity-75">{{ trans('mining-manager::analytics.avg_per_miner') }}</p>
                        <h3>{{ number_format(($analytics['unique_miners'] ?? 0) > 0 ? (($analytics['total_value'] ?? 0) / ($analytics['unique_miners'] ?? 1)) / 1000000 : 0, 0) }}</h3>
                        <small>{{ trans('mining-manager::analytics.million_isk') }}</small>
                    </div>
                    <i class="fas fa-chart-line fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    {{-- DAILY TRENDS CHART --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-area"></i>
                        {{ trans('mining-manager::analytics.daily_mining_trends') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="dailyTrendsChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_daily_trends') }}</small>
                </div>
            </div>
        </div>
    </div>

    @php $features = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags(); @endphp
    <div class="row">
        {{-- TOP MINERS LEADERBOARD --}}
        @if($features['allow_member_leaderboard'] ?? true)
        <div class="col-lg-6">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-trophy"></i>
                        {{ trans('mining-manager::analytics.top_miners') }}
                    </h3>
                    <div class="card-tools">
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('mining-manager.analytics.index', array_merge(request()->except('group_by'), ['group_by' => 'account'])) }}"
                               class="btn btn-sm {{ ($groupBy ?? 'account') === 'account' ? 'btn-info' : 'btn-outline-info' }}">
                                {{ trans('mining-manager::analytics.by_account') }}
                            </a>
                            <a href="{{ route('mining-manager.analytics.index', array_merge(request()->except('group_by'), ['group_by' => 'character'])) }}"
                               class="btn btn-sm {{ ($groupBy ?? 'account') === 'character' ? 'btn-info' : 'btn-outline-info' }}">
                                {{ trans('mining-manager::analytics.by_character') }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">{{ trans('mining-manager::analytics.rank') }}</th>
                                    <th>{{ trans('mining-manager::analytics.character') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.value') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($analytics['top_miners'] ?? [] as $index => $miner)
                                <tr class="leaderboard-card">
                                    <td class="text-center">
                                        <div class="rank-badge rank-{{ min($index + 1, 3) > 3 ? 'other' : $index + 1 }}">
                                            #{{ $index + 1 }}
                                        </div>
                                    </td>
                                    <td>
                                        @php
                                            $portraitId = ($groupBy ?? 'account') === 'account' ? ($miner->main_character_id ?? $miner->character_id ?? 0) : ($miner->character_id ?? 0);
                                        @endphp
                                        <img src="https://images.evetech.net/characters/{{ $portraitId }}/portrait?size=32"
                                             class="img-circle"
                                             style="width: 32px;">
                                        {{ ($features['show_character_names'] ?? true) ? $miner->name : 'Miner #' . ($miner->character_id ?? $index + 1) }}
                                        @if(($groupBy ?? 'account') === 'account' && isset($miner->character_count) && $miner->character_count > 1)
                                            <span class="badge badge-secondary ml-1">{{ $miner->character_count }} {{ trans('mining-manager::analytics.characters') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($miner->total_quantity ?? 0, 0) }}</td>
                                    <td class="text-right text-success">{{ number_format(($miner->total_value ?? 0) / 1000000, 0) }}M ISK</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">
                                        {{ trans('mining-manager::analytics.no_data') }}
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>

    @php
        $oreCategories = [
            '' => 'all_ores',
            'regular_ore' => 'regular_ore',
            'moon_ore' => 'moon_ore',
            'ice' => 'ice',
            'gas' => 'gas',
            'abyssal_ore' => 'abyssal_ore',
        ];
    @endphp

    {{-- ORE BREAKDOWN CHARTS --}}
    <div class="row">
        {{-- ORE BREAKDOWN (ISK) --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::analytics.ore_breakdown') }} (ISK)
                    </h3>
                    <div class="card-tools">
                        <div class="btn-group btn-group-sm">
                            @foreach($oreCategories as $catValue => $catLabel)
                                <a href="{{ route('mining-manager.analytics.index', array_merge(request()->except('ore_category'), $catValue ? ['ore_category' => $catValue] : [])) }}"
                                   class="btn btn-sm {{ ($oreCategory ?? '') === $catValue ? 'btn-info' : 'btn-outline-info' }}">
                                    {{ trans('mining-manager::analytics.' . $catLabel) }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="oreBreakdownChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_ore_breakdown_isk') }}</small>
                </div>
            </div>
        </div>

        {{-- ORE BREAKDOWN (QUANTITY) --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-cubes"></i>
                        {{ trans('mining-manager::analytics.ore_breakdown') }} ({{ trans('mining-manager::analytics.total_quantity') }})
                    </h3>
                    <div class="card-tools">
                        <div class="btn-group btn-group-sm">
                            @foreach($oreCategories as $catValue => $catLabel)
                                <a href="{{ route('mining-manager.analytics.index', array_merge(request()->except('ore_category'), $catValue ? ['ore_category' => $catValue] : [])) }}"
                                   class="btn btn-sm {{ ($oreCategory ?? '') === $catValue ? 'btn-info' : 'btn-outline-info' }}">
                                    {{ trans('mining-manager::analytics.' . $catLabel) }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="oreBreakdownQtyChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_ore_breakdown_qty') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- SYSTEM BREAKDOWN --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-map-marker-alt"></i>
                        {{ trans('mining-manager::analytics.system_breakdown') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::analytics.system') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.total_quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.value') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::analytics.miners') }}</th>
                                    <th style="width: 40%;">{{ trans('mining-manager::analytics.activity') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($analytics['system_breakdown'] ?? [] as $system)
                                <tr>
                                    <td><i class="fas fa-globe"></i> {{ $system->system_name }}</td>
                                    <td class="text-right">{{ number_format($system->total_quantity ?? 0, 0) }}</td>
                                    <td class="text-right text-success">{{ number_format(($system->total_value ?? 0) / 1000000, 0) }}M ISK</td>
                                    <td class="text-right">{{ $system->unique_miners ?? 0 }}</td>
                                    <td>
                                        @php
                                            $maxValue = collect($analytics['system_breakdown'] ?? [])->max('total_value');
                                            $percentage = $maxValue > 0 ? (($system->total_value ?? 0) / $maxValue) * 100 : 0;
                                        @endphp
                                        <div class="mm-progress-wrap">
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-info" style="width: {{ max($percentage, 1) }}%"></div>
                                            </div>
                                            <span class="mm-pct-label">{{ number_format($percentage, 1) }}%</span>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">
                                        {{ trans('mining-manager::analytics.no_data') }}
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

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// Daily Trends Chart
const dailyTrendsCtx = document.getElementById('dailyTrendsChart');
const dailyTrendsData = @json($analytics['daily_trends'] ?? []);

new Chart(dailyTrendsCtx, {
    type: 'line',
    data: {
        labels: dailyTrendsData.map(d => d.date ? d.date.substring(0, 10) : ''),
        datasets: [{
            label: '{{ trans("mining-manager::analytics.total_quantity") }}',
            data: dailyTrendsData.map(d => d.total_quantity),
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            yAxisID: 'y'
        }, {
            label: '{{ trans("mining-manager::analytics.value") }}',
            data: dailyTrendsData.map(d => d.total_value / 1000000),
            borderColor: '#f5576c',
            backgroundColor: 'rgba(245, 87, 108, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false,
        },
        plugins: {
            legend: {
                labels: { color: '#fff' }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) label += ': ';
                        if (context.parsed.y !== null) {
                            if (context.datasetIndex === 0) {
                                label += context.parsed.y.toLocaleString() + ' units';
                            } else {
                                label += context.parsed.y.toLocaleString() + 'M ISK';
                            }
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                ticks: { color: '#fff' },
                grid: { color: 'rgba(255, 255, 255, 0.1)' }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                ticks: { color: '#fff' },
                grid: { drawOnChartArea: false }
            },
            x: {
                ticks: { color: '#fff' },
                grid: { color: 'rgba(255, 255, 255, 0.1)' }
            }
        }
    }
});

// Ore Breakdown Charts
const oreBreakdownData = @json($analytics['ore_breakdown'] ?? []);
const oreChartColors = [
    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
    '#FF9F40', '#C9CBCF', '#4BC0C0', '#FF6384', '#36A2EB',
    '#FFCE56', '#9966FF', '#FF9F40', '#C9CBCF', '#4BC0C0'
];

// By ISK
new Chart(document.getElementById('oreBreakdownChart'), {
    type: 'doughnut',
    data: {
        labels: oreBreakdownData.map(d => d.ore_name),
        datasets: [{
            data: oreBreakdownData.map(d => d.total_value),
            backgroundColor: oreChartColors.slice(0, oreBreakdownData.length),
            borderColor: '#343a40',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { color: '#fff' } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ': ' + (context.parsed / 1000000).toFixed(1) + 'M ISK';
                    }
                }
            }
        }
    }
});

// By Quantity
new Chart(document.getElementById('oreBreakdownQtyChart'), {
    type: 'doughnut',
    data: {
        labels: oreBreakdownData.map(d => d.ore_name),
        datasets: [{
            data: oreBreakdownData.map(d => d.total_quantity),
            backgroundColor: oreChartColors.slice(0, oreBreakdownData.length),
            borderColor: '#343a40',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { color: '#fff' } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ': ' + context.parsed.toLocaleString() + ' units';
                    }
                }
            }
        }
    }
});

// Quick filter buttons
$('.quick-filter').on('click', function() {
    const days = $(this).data('days');
    const endDate = new Date();
    const startDate = new Date();
    startDate.setDate(startDate.getDate() - days);
    
    $('input[name="start_date"]').val(startDate.toISOString().split('T')[0]);
    $('input[name="end_date"]').val(endDate.toISOString().split('T')[0]);
    $(this).closest('form').submit();
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
