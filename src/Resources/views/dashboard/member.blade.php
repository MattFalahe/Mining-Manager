@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::dashboard.member_dashboard'))
@section('page_header', trans('mining-manager::dashboard.member_dashboard'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
/* ULTRA-AGGRESSIVE CSS OVERRIDES - INLINE TO BEAT EVERYTHING */
.mining-dashboard .tab-content {
    background-color: #0f1115 !important;
}

.mining-dashboard .card-body,
.mining-dashboard .card-dark .card-body,
.mining-dashboard .card.card-dark .card-body,
.mining-dashboard div.card-body {
    background-color: #161922 !important;
    color: #e8e8e8 !important;
}

.mining-dashboard .card.card-dark,
.mining-dashboard .card-dark {
    background-color: #161922 !important;
    border-color: #2c3138 !important;
}

.mining-dashboard .card-dark .card-header,
.mining-dashboard .card.card-dark .card-header {
    background-color: #1a1d24 !important;
    border-bottom: 1px solid #2c3138 !important;
}

.mining-dashboard .table {
    color: #e8e8e8 !important;
}

.mining-dashboard .table thead th {
    background-color: #1a1d24 !important;
    color: #ffffff !important;
}

.mining-dashboard canvas {
    background-color: rgba(26, 29, 36, 0.5) !important;
}
</style>
@endpush

@section('full')
<div class="mining-dashboard member-dashboard">
    
    {{-- CURRENT MONTH STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::dashboard.current_month_stats') }} - {{ now()->format('F Y') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-success">
                            <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::dashboard.live') }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Total Mined Quantity --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-warning">
                                <span class="info-box-icon">
                                    <i class="fas fa-gem"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::dashboard.total_mined_quantity') }}</span>
                                    <span class="info-box-number">{{ number_format($currentMonthStats['total_quantity'], 0) }}</span>
                                    <small>{{ trans('mining-manager::dashboard.units') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Mined Volume --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-cube"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::dashboard.total_mined_volume') }}</span>
                                    <span class="info-box-number">{{ number_format($currentMonthStats['total_volume'], 2) }}</span>
                                    <small>m³</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Mined ISK --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon">
                                    <i class="fas fa-coins"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::dashboard.total_mined_isk') }}</span>
                                    <span class="info-box-number">{{ number_format($currentMonthStats['total_isk'], 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Tax ISK --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-danger">
                                <span class="info-box-icon">
                                    <i class="fas fa-receipt"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::dashboard.tax_isk') }}</span>
                                    <span class="info-box-number">{{ number_format($currentMonthStats['tax_isk'], 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- LAST 12 MONTHS STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::dashboard.last_12_months_stats') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-primary">
                                <div class="inner">
                                    <h3>{{ number_format($last12MonthsStats['total_quantity'], 0) }}</h3>
                                    <p>{{ trans('mining-manager::dashboard.total_quantity') }}</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-gem"></i>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-success">
                                <div class="inner">
                                    <h3>{{ number_format($last12MonthsStats['total_value'], 0) }}</h3>
                                    <p>{{ trans('mining-manager::dashboard.total_value') }} ISK</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-coins"></i>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-info">
                                <div class="inner">
                                    <h3>{{ number_format($last12MonthsStats['total_volume'], 2) }}</h3>
                                    <p>{{ trans('mining-manager::dashboard.total_volume') }} m³</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-cube"></i>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-warning">
                                <div class="inner">
                                    <h3>{{ number_format($last12MonthsStats['avg_per_month'], 0) }}</h3>
                                    <p>{{ trans('mining-manager::dashboard.avg_per_month') }} ISK</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- TOP MINER RANKINGS --}}
    <div class="row">
        {{-- All Ore Ranking --}}
        <div class="col-lg-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-trophy"></i>
                        {{ trans('mining-manager::dashboard.top_miners_all_ore') }}
                    </h3>
                    @if($userRankAllOre)
                    <div class="card-tools">
                        <span class="badge badge-info">
                            {{ trans('mining-manager::dashboard.your_rank') }}: #{{ $userRankAllOre }}
                        </span>
                    </div>
                    @endif
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 50px">{{ trans('mining-manager::dashboard.rank') }}</th>
                                    <th>{{ trans('mining-manager::dashboard.character') }}</th>
                                    <th>{{ trans('mining-manager::dashboard.corporation') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::dashboard.value') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($topMinersAllOre as $index => $miner)
                                <tr class="{{ $miner['main_character_id'] == auth()->user()->main_character_id ? 'table-primary' : '' }}">
                                    <td>
                                        @if($index < 3)
                                            <span class="badge badge-{{ $index == 0 ? 'warning' : ($index == 1 ? 'secondary' : 'bronze') }}">
                                                #{{ $index + 1 }}
                                            </span>
                                        @else
                                            <span class="text-muted">#{{ $index + 1 }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $miner['main_character_id'] }}/portrait?size=32" 
                                             class="img-circle" 
                                             style="width: 32px; height: 32px;">
                                        <strong>{{ $miner['character_name'] }}</strong>
                                        @if(!$miner['is_registered'])
                                            <span class="badge badge-warning" title="Character not registered in SeAT">
                                                <i class="fas fa-exclamation-triangle"></i> Not Registered
                                            </span>
                                        @endif
                                        @if(isset($miner['alt_count']) && $miner['alt_count'] > 0)
                                            <span class="badge badge-info" title="Total includes {{ $miner['alt_count'] }} alt character(s)">
                                                <i class="fas fa-users"></i> +{{ $miner['alt_count'] }} alts
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ $miner['corporation_name'] ?? 'Unknown Corporation' }}</td>
                                    <td class="text-right">
                                        <strong>{{ number_format($miner['total_value'], 0) }}</strong>
                                        <small class="text-muted">ISK</small>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Moon Ore Ranking --}}
        <div class="col-lg-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-moon"></i>
                        {{ trans('mining-manager::dashboard.top_miners_moon_ore') }}
                    </h3>
                    @if($userRankMoonOre)
                    <div class="card-tools">
                        <span class="badge badge-info">
                            {{ trans('mining-manager::dashboard.your_rank') }}: #{{ $userRankMoonOre }}
                        </span>
                    </div>
                    @endif
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 50px">{{ trans('mining-manager::dashboard.rank') }}</th>
                                    <th>{{ trans('mining-manager::dashboard.character') }}</th>
                                    <th>{{ trans('mining-manager::dashboard.corporation') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::dashboard.value') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($topMinersMoonOre as $index => $miner)
                                <tr class="{{ $miner['main_character_id'] == auth()->user()->main_character_id ? 'table-primary' : '' }}">
                                    <td>
                                        @if($index < 3)
                                            <span class="badge badge-{{ $index == 0 ? 'warning' : ($index == 1 ? 'secondary' : 'bronze') }}">
                                                #{{ $index + 1 }}
                                            </span>
                                        @else
                                            <span class="text-muted">#{{ $index + 1 }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $miner['main_character_id'] }}/portrait?size=32" 
                                             class="img-circle" 
                                             style="width: 32px; height: 32px;">
                                        <strong>{{ $miner['character_name'] }}</strong>
                                        @if(!$miner['is_registered'])
                                            <span class="badge badge-warning" title="Character not registered in SeAT">
                                                <i class="fas fa-exclamation-triangle"></i> Not Registered
                                            </span>
                                        @endif
                                        @if(isset($miner['alt_count']) && $miner['alt_count'] > 0)
                                            <span class="badge badge-info" title="Total includes {{ $miner['alt_count'] }} alt character(s)">
                                                <i class="fas fa-users"></i> +{{ $miner['alt_count'] }} alts
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ $miner['corporation_name'] ?? 'Unknown Corporation' }}</td>
                                    <td class="text-right">
                                        <strong>{{ number_format($miner['total_value'], 0) }}</strong>
                                        <small class="text-muted">ISK</small>
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

    {{-- CHARTS ROW 1 --}}
    <div class="row">
        {{-- Mining Performance Chart --}}
        <div class="col-lg-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-area"></i>
                        {{ trans('mining-manager::dashboard.mining_performance_last_12_months') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" onclick="refreshChart('mining_performance')">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="miningPerformanceChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- CHARTS ROW 2 --}}
    <div class="row">
        {{-- Mining by Group (Doughnut - ISK) --}}
        <div class="col-lg-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::dashboard.mining_by_group') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="miningVolumeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        {{-- Mining by Type (Top 10 Ores) --}}
        <div class="col-lg-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::dashboard.mining_by_type') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="miningByTypeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- CHARTS ROW 3 --}}
    <div class="row">
        {{-- Mining Income Chart --}}
        <div class="col-lg-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-bar"></i>
                        {{ trans('mining-manager::dashboard.mining_income_last_12_months') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="miningIncomeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// Chart.js default configuration
Chart.defaults.color = '#fff';
Chart.defaults.borderColor = '#444';

// ISK formatting helper
function formatISK(value) {
    if (value >= 1e9) return (value / 1e9).toFixed(1) + 'B';
    if (value >= 1e6) return (value / 1e6).toFixed(1) + 'M';
    if (value >= 1e3) return (value / 1e3).toFixed(1) + 'K';
    return value.toFixed(0);
}

// Group color mapping
var groupColors = {
    'Moon Ore': 'rgba(255, 206, 86, 0.8)',
    'Regular Ore': 'rgba(54, 162, 235, 0.8)',
    'Ice': 'rgba(75, 192, 192, 0.8)',
    'Gas': 'rgba(153, 102, 255, 0.8)',
    'Abyssal': 'rgba(255, 99, 132, 0.8)'
};

// Chart data from backend
const chartData = {
    miningPerformance: @json($miningPerformanceChart),
    miningVolume: @json($miningVolumeByGroupChart),
    miningByType: @json($miningByTypeChart),
    miningIncome: @json($miningIncomeChart)
};

// Mining Performance Chart
const miningPerformanceCtx = document.getElementById('miningPerformanceChart').getContext('2d');
const miningPerformanceChart = new Chart(miningPerformanceCtx, {
    type: 'bar',
    data: {
        labels: chartData.miningPerformance.labels,
        datasets: [{
            label: '{{ trans("mining-manager::dashboard.volume_of") }}',
            data: chartData.miningPerformance.data,
            backgroundColor: 'rgba(161, 198, 60, 0.8)',
            borderColor: 'rgba(161, 198, 60, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top'
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
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Mining by Group Chart (Doughnut - ISK values)
var miningVolumeColors = chartData.miningVolume.labels.map(function(label) {
    return groupColors[label] || 'rgba(201, 203, 207, 0.8)';
});

const miningVolumeCtx = document.getElementById('miningVolumeChart').getContext('2d');
const miningVolumeChart = new Chart(miningVolumeCtx, {
    type: 'doughnut',
    data: {
        labels: chartData.miningVolume.labels,
        datasets: [{
            data: chartData.miningVolume.data,
            backgroundColor: miningVolumeColors,
            borderColor: '#1a1d24',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'right'
            },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                        var pct = ((ctx.raw / total) * 100).toFixed(1);
                        return ctx.label + ': ' + formatISK(ctx.raw) + ' ISK (' + pct + '%)';
                    }
                }
            }
        }
    }
});

// Mining by Type Chart (Horizontal Bar - Top 10)
const miningByTypeCtx = document.getElementById('miningByTypeChart').getContext('2d');
const miningByTypeChart = new Chart(miningByTypeCtx, {
    type: 'bar',
    data: {
        labels: chartData.miningByType.labels,
        datasets: [{
            label: 'Value (ISK)',
            data: chartData.miningByType.data,
            backgroundColor: chartData.miningByType.colors,
            borderWidth: 1
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        return formatISK(ctx.raw) + ' ISK';
                    }
                }
            }
        },
        scales: {
            x: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) { return formatISK(value); }
                }
            },
            y: {
                ticks: { font: { size: 11 } },
                grid: { display: false }
            }
        }
    }
});

// Mining Income Chart (Stacked Bar)
const miningIncomeCtx = document.getElementById('miningIncomeChart').getContext('2d');
const miningIncomeChart = new Chart(miningIncomeCtx, {
    type: 'bar',
    data: {
        labels: chartData.miningIncome.labels,
        datasets: [
            {
                label: chartData.miningIncome.datasets[0].label,
                data: chartData.miningIncome.datasets[0].data,
                backgroundColor: 'rgba(0, 210, 255, 0.8)',
                borderColor: 'rgba(0, 210, 255, 1)',
                borderWidth: 1
            },
            {
                label: chartData.miningIncome.datasets[1].label,
                data: chartData.miningIncome.datasets[1].data,
                backgroundColor: 'rgba(255, 0, 132, 0.8)',
                borderColor: 'rgba(255, 0, 132, 1)',
                borderWidth: 1
            },
            {
                label: chartData.miningIncome.datasets[2].label,
                data: chartData.miningIncome.datasets[2].data,
                backgroundColor: 'rgba(161, 198, 60, 0.8)',
                borderColor: 'rgba(161, 198, 60, 1)',
                borderWidth: 1
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + ' ISK';
                    }
                }
            }
        },
        scales: {
            x: {
                stacked: true
            },
            y: {
                stacked: true,
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Refresh chart function
function refreshChart(chartType) {
    $.ajax({
        url: '{{ route("mining-manager.dashboard.live-data") }}',
        data: { chart_type: chartType },
        success: function(response) {
            if (response.success) {
                // Update chart data
                if (chartType === 'mining_performance') {
                    miningPerformanceChart.data.labels = response.data.labels;
                    miningPerformanceChart.data.datasets[0].data = response.data.data;
                    miningPerformanceChart.update();
                }
                
                toastr.success('{{ trans("mining-manager::dashboard.chart_updated") }}');
            }
        }
    });
}

// Auto-refresh every 5 minutes
setInterval(function() {
    refreshChart('mining_performance');
}, 300000);
</script>
@endpush
@endsection
