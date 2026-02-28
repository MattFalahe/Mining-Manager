@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::dashboard.director_dashboard'))
@section('page_header', trans('mining-manager::dashboard.director_dashboard'))

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
<div class="mining-dashboard combined-director-dashboard">

    {{-- TAB NAVIGATION --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark card-tabs">
                <div class="card-header p-0 pt-1">
                    <ul class="nav nav-tabs" id="dashboard-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="personal-tab" data-toggle="pill" href="#personal" role="tab" aria-controls="personal" aria-selected="true">
                                <i class="fas fa-user"></i> {{ trans('mining-manager::dashboard.my_mining') }}
                                <span class="badge personal-badge ml-2">Personal</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="corporation-tab" data-toggle="pill" href="#corporation" role="tab" aria-controls="corporation" aria-selected="false">
                                <i class="fas fa-building"></i> {{ trans('mining-manager::dashboard.corporation_overview') }}
                                <span class="badge corporation-badge ml-2">Corporation</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="dashboard-tabContent">

                        {{-- PERSONAL STATS TAB --}}
                        <div class="tab-pane fade show active" id="personal" role="tabpanel" aria-labelledby="personal-tab">

                            {{-- CURRENT MONTH PERSONAL STATISTICS --}}
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
                                                {{-- Total Mined Value --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-success">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-coins"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.total_mined_value') }}</span>
                                                            <span class="info-box-number">{{ number_format($personalCurrentMonthStats['total_value'], 0) }}</span>
                                                            <small>ISK</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Total Quantity --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-warning">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-gem"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.total_mined_quantity') }}</span>
                                                            <span class="info-box-number">{{ number_format($personalCurrentMonthStats['total_quantity'], 0) }}</span>
                                                            <small>{{ trans('mining-manager::dashboard.units') }}</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Tax Owed --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-danger">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-file-invoice-dollar"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.tax_owed') }}</span>
                                                            <span class="info-box-number">{{ number_format($personalCurrentMonthStats['tax_isk'], 0) }}</span>
                                                            <small>ISK</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Mining Days --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-info">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-calendar-check"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.mining_days') }}</span>
                                                            <span class="info-box-number">{{ $personalCurrentMonthStats['mining_days'] }}</span>
                                                            <small>{{ trans('mining-manager::dashboard.days') }}</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- LAST 12 MONTHS PERSONAL STATISTICS --}}
                            <div class="row">
                                <div class="col-12">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-area"></i>
                                                {{ trans('mining-manager::dashboard.last_12_months_stats') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                {{-- Total Quantity --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-primary">
                                                        <div class="inner">
                                                            <h3>{{ number_format($personalLast12MonthsStats['total_quantity'], 0) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.total_quantity') }}</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-gem"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Total Value --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-success">
                                                        <div class="inner">
                                                            <h3>{{ number_format($personalLast12MonthsStats['total_value'], 0) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.total_value') }} ISK</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-coins"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Total Volume --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-info">
                                                        <div class="inner">
                                                            <h3>{{ number_format($personalLast12MonthsStats['total_volume'], 2) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.total_volume') }} m³</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-cube"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Avg Per Month --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-warning">
                                                        <div class="inner">
                                                            <h3>{{ number_format($personalLast12MonthsStats['avg_per_month'], 0) }}</h3>
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

                            {{-- PERSONAL RANKINGS --}}
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-trophy"></i>
                                                {{ trans('mining-manager::dashboard.my_rank_all_ore') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            @if($userRankAllOre)
                                                <div class="text-center">
                                                    <h1 class="display-3 text-warning">
                                                        <i class="fas fa-medal"></i> #{{ $userRankAllOre }}
                                                    </h1>
                                                    <p class="text-muted">{{ trans('mining-manager::dashboard.out_of') }} {{ count($topMinersAllOre) }} {{ trans('mining-manager::dashboard.miners') }}</p>
                                                </div>
                                            @else
                                                <p class="text-center text-muted">{{ trans('mining-manager::dashboard.no_ranking_data') }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-moon"></i>
                                                {{ trans('mining-manager::dashboard.my_rank_moon_ore') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            @if($userRankMoonOre)
                                                <div class="text-center">
                                                    <h1 class="display-3 text-info">
                                                        <i class="fas fa-medal"></i> #{{ $userRankMoonOre }}
                                                    </h1>
                                                    <p class="text-muted">{{ trans('mining-manager::dashboard.out_of') }} {{ count($topMinersMoonOre) }} {{ trans('mining-manager::dashboard.miners') }}</p>
                                                </div>
                                            @else
                                                <p class="text-center text-muted">{{ trans('mining-manager::dashboard.no_ranking_data') }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- PERSONAL CHARTS --}}
                            <div class="row">
                                {{-- Mining Performance Chart --}}
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-line"></i>
                                                {{ trans('mining-manager::dashboard.my_mining_performance') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalMiningChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                        </div>
                                    </div>
                                </div>

                                {{-- Mining Value by Group (Doughnut) --}}
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-pie"></i>
                                                {{ trans('mining-manager::dashboard.mining_by_group') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalVolumeChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Mining by Type + Income --}}
                            <div class="row">
                                {{-- Mining by Ore Type (Top 10) --}}
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-gem"></i>
                                                {{ trans('mining-manager::dashboard.mining_by_type') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalByTypeChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                        </div>
                                    </div>
                                </div>

                                {{-- Personal Income Chart --}}
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-bar"></i>
                                                {{ trans('mining-manager::dashboard.mining_income_last_12_months') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalIncomeChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        {{-- CORPORATION STATS TAB (loaded via AJAX) --}}
                        <div class="tab-pane fade" id="corporation" role="tabpanel" aria-labelledby="corporation-tab">
                            <div id="corp-tab-loading" class="text-center py-5">
                                <div class="spinner-border text-info" role="status" style="width: 3rem; height: 3rem;">
                                    <span class="sr-only">Loading...</span>
                                </div>
                                <p class="text-muted mt-3">Loading corporation data...</p>
                            </div>
                            <div id="corp-tab-content" style="display: none;">

                                {{-- CURRENT MONTH CORPORATION STATISTICS --}}
                                <div class="row">
                                    <div class="col-12">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    {{ trans('mining-manager::dashboard.corporation_stats') }} - {{ now()->format('F Y') }}
                                                </h3>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-3 col-sm-6">
                                                        <div class="info-box bg-gradient-dark">
                                                            <span class="info-box-icon"><i class="fas fa-gem"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">All Ore Value</span>
                                                                <span class="info-box-number" id="corp-all-ore-value">--</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3 col-sm-6">
                                                        <div class="info-box bg-gradient-dark">
                                                            <span class="info-box-icon"><i class="fas fa-moon"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">Moon Ore Value</span>
                                                                <span class="info-box-number" id="corp-moon-ore-value">--</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3 col-sm-6">
                                                        <div class="info-box bg-gradient-dark">
                                                            <span class="info-box-icon"><i class="fas fa-users"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">Active Miners</span>
                                                                <span class="info-box-number" id="corp-active-miners">--</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3 col-sm-6">
                                                        <div class="info-box bg-gradient-dark">
                                                            <span class="info-box-icon"><i class="fas fa-coins"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">Tax Collected</span>
                                                                <span class="info-box-number" id="corp-tax-collected">--</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- CORPORATION CHARTS --}}
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-chart-line"></i> Mining Performance (12 Months)</h3>
                                            </div>
                                            <div class="card-body" style="height: 300px;">
                                                <canvas id="corpMiningPerformanceChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-moon"></i> Moon Mining (12 Months)</h3>
                                            </div>
                                            <div class="card-body" style="height: 300px;">
                                                <canvas id="corpMoonMiningChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-coins"></i> Tax Revenue (12 Months)</h3>
                                            </div>
                                            <div class="card-body" style="height: 300px;">
                                                <canvas id="corpTaxChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-calendar-check"></i> Event Tax (12 Months)</h3>
                                            </div>
                                            <div class="card-body" style="height: 300px;">
                                                <canvas id="corpEventTaxChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// ISK formatting helper (global scope for AJAX lazy-load access)
function formatISK(value) {
    if (value >= 1e9) return (value / 1e9).toFixed(1) + 'B';
    if (value >= 1e6) return (value / 1e6).toFixed(1) + 'M';
    if (value >= 1e3) return (value / 1e3).toFixed(1) + 'K';
    return value.toFixed(0);
}

$(document).ready(function() {
    // Personal Mining Chart
    var personalCtx = document.getElementById('personalMiningChart').getContext('2d');
    new Chart(personalCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($personalMiningPerformanceChart['labels']) !!},
            datasets: [{
                label: 'Mining Value (ISK)',
                data: {!! json_encode($personalMiningPerformanceChart['data']) !!},
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#c2c7d0' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { color: '#c2c7d0' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                x: {
                    ticks: { color: '#c2c7d0' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                }
            }
        }
    });

    // Group color mapping
    var groupColors = {
        'Moon Ore': 'rgba(255, 206, 86, 0.8)',
        'Regular Ore': 'rgba(54, 162, 235, 0.8)',
        'Ice': 'rgba(75, 192, 192, 0.8)',
        'Gas': 'rgba(153, 102, 255, 0.8)',
        'Abyssal': 'rgba(255, 99, 132, 0.8)'
    };

    // Personal Mining by Group (Doughnut - ISK values)
    var personalVolumeCtx = document.getElementById('personalVolumeChart').getContext('2d');
    var personalGroupLabels = {!! json_encode($personalMiningVolumeByGroupChart['labels']) !!};
    var personalGroupData = {!! json_encode($personalMiningVolumeByGroupChart['data']) !!};
    var personalGroupColors = personalGroupLabels.map(function(label) {
        return groupColors[label] || 'rgba(201, 203, 207, 0.8)';
    });

    new Chart(personalVolumeCtx, {
        type: 'doughnut',
        data: {
            labels: personalGroupLabels,
            datasets: [{
                data: personalGroupData,
                backgroundColor: personalGroupColors,
                borderColor: '#1a1d24',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#c2c7d0' },
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

    // Personal Mining by Type (Horizontal Bar - Top 10)
    var personalByTypeCtx = document.getElementById('personalByTypeChart').getContext('2d');
    new Chart(personalByTypeCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($personalMiningByTypeChart['labels']) !!},
            datasets: [{
                label: 'Value (ISK)',
                data: {!! json_encode($personalMiningByTypeChart['data']) !!},
                backgroundColor: {!! json_encode($personalMiningByTypeChart['colors']) !!},
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
                        color: '#c2c7d0',
                        callback: function(value) { return formatISK(value); }
                    },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                y: {
                    ticks: { color: '#c2c7d0', font: { size: 11 } },
                    grid: { display: false }
                }
            }
        }
    });

    // Personal Income Chart
    var personalIncomeCtx = document.getElementById('personalIncomeChart').getContext('2d');
    new Chart(personalIncomeCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($personalMiningIncomeChart['labels']) !!},
            datasets: [{
                label: 'Refined Value',
                data: {!! json_encode($personalMiningIncomeChart['refined_value']) !!},
                backgroundColor: 'rgba(75, 192, 192, 0.8)',
                borderColor: 'rgb(75, 192, 192)',
                borderWidth: 1
            }, {
                label: 'Tax Paid',
                data: {!! json_encode($personalMiningIncomeChart['tax_paid']) !!},
                backgroundColor: 'rgba(255, 99, 132, 0.8)',
                borderColor: 'rgb(255, 99, 132)',
                borderWidth: 1
            }, {
                label: 'Event Bonus',
                data: {!! json_encode($personalMiningIncomeChart['event_bonus']) !!},
                backgroundColor: 'rgba(255, 206, 86, 0.8)',
                borderColor: 'rgb(255, 206, 86)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#c2c7d0' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    stacked: false,
                    ticks: { color: '#c2c7d0' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                x: {
                    stacked: false,
                    ticks: { color: '#c2c7d0' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                }
            }
        }
    });

});

// Corporation tab lazy loading
var corpTabLoaded = false;

$('#corporation-tab').on('shown.bs.tab', function() {
    if (corpTabLoaded) return;
    corpTabLoaded = true;

    $.get('{{ $corpTabUrl }}')
        .done(function(data) {
            // Fill in stats
            $('#corp-all-ore-value').text(formatISK(data.corpCurrentMonthStats.total_value || 0) + ' ISK');
            $('#corp-moon-ore-value').text(formatISK(data.corpCurrentMonthStats.moon_ore_value || 0) + ' ISK');
            $('#corp-active-miners').text(data.corpCurrentMonthStats.active_miners || 0);
            $('#corp-tax-collected').text(formatISK(data.corpCurrentMonthStats.tax_collected || 0) + ' ISK');

            // Initialize charts
            initCorpCharts(data);

            // Show content, hide loading
            $('#corp-tab-loading').hide();
            $('#corp-tab-content').show();
        })
        .fail(function() {
            $('#corp-tab-loading').html(
                '<div class="alert alert-danger">' +
                '<i class="fas fa-exclamation-triangle"></i> Failed to load corporation data. ' +
                '<a href="#" onclick="location.reload()">Reload page</a></div>'
            );
        });
});

function initCorpCharts(data) {
    var chartColors = {
        text: '#c2c7d0',
        grid: 'rgba(255, 255, 255, 0.1)'
    };

    var defaultScales = {
        y: { beginAtZero: true, ticks: { color: chartColors.text }, grid: { color: chartColors.grid } },
        x: { ticks: { color: chartColors.text }, grid: { color: chartColors.grid } }
    };

    var defaultLegend = { labels: { color: chartColors.text } };

    // Corp Mining Performance
    if (data.corpMiningPerformanceChart) {
        new Chart(document.getElementById('corpMiningPerformanceChart'), {
            type: 'line',
            data: {
                labels: data.corpMiningPerformanceChart.labels,
                datasets: [{
                    label: 'All Ore Value (ISK)',
                    data: data.corpMiningPerformanceChart.data,
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: defaultLegend }, scales: defaultScales }
        });
    }

    // Moon Mining Performance
    if (data.moonMiningPerformanceChart) {
        new Chart(document.getElementById('corpMoonMiningChart'), {
            type: 'line',
            data: {
                labels: data.moonMiningPerformanceChart.labels,
                datasets: [{
                    label: 'Moon Ore Value (ISK)',
                    data: data.moonMiningPerformanceChart.data,
                    borderColor: 'rgb(255, 205, 86)',
                    backgroundColor: 'rgba(255, 205, 86, 0.2)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: defaultLegend }, scales: defaultScales }
        });
    }

    // Tax Revenue Chart
    if (data.miningTaxChart) {
        new Chart(document.getElementById('corpTaxChart'), {
            type: 'line',
            data: {
                labels: data.miningTaxChart.labels,
                datasets: [{
                    label: 'Tax Revenue (ISK)',
                    data: data.miningTaxChart.data,
                    borderColor: 'rgb(54, 162, 235)',
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: defaultLegend }, scales: defaultScales }
        });
    }

    // Event Tax Chart
    if (data.eventTaxChart) {
        new Chart(document.getElementById('corpEventTaxChart'), {
            type: 'line',
            data: {
                labels: data.eventTaxChart.labels,
                datasets: [{
                    label: 'Event Tax (ISK)',
                    data: data.eventTaxChart.data,
                    borderColor: 'rgb(153, 102, 255)',
                    backgroundColor: 'rgba(153, 102, 255, 0.2)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: defaultLegend }, scales: defaultScales }
        });
    }
}
</script>
@endpush
