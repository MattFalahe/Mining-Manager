@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::dashboard.director_dashboard'))
@section('page_header', trans('mining-manager::dashboard.director_dashboard'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-dashboard director-dashboard">
    
    {{-- CURRENT MONTH STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::dashboard.corporation_stats') }} - {{ now()->format('F Y') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-success">
                            <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::dashboard.live') }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- All Ore Value --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-primary">
                                <span class="info-box-icon">
                                    <i class="fas fa-gem"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::dashboard.all_ore_value') }}</span>
                                    <span class="info-box-number">{{ number_format($currentMonthStats['all_ore_value'], 0) }}</span>
                                    <small>ISK ({{ number_format($currentMonthStats['all_ore_quantity'], 0) }} units)</small>
                                </div>
                            </div>
                        </div>

                        {{-- Moon Ore Value --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-secondary">
                                <span class="info-box-icon">
                                    <i class="fas fa-moon"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::dashboard.moon_ore_value') }}</span>
                                    <span class="info-box-number">{{ number_format($currentMonthStats['moon_ore_value'], 0) }}</span>
                                    <small>ISK ({{ number_format($currentMonthStats['moon_ore_quantity'], 0) }} units)</small>
                                </div>
                            </div>
                        </div>

                        {{-- Tax Amount --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon">
                                    <i class="fas fa-coins"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::dashboard.tax_amount') }}</span>
                                    <span class="info-box-number">{{ number_format($currentMonthStats['tax_amount'], 0) }}</span>
                                    <small>ISK ({{ number_format($currentMonthStats['tax_collected'], 0) }} collected)</small>
                                </div>
                            </div>
                        </div>

                        {{-- Active Miners --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-users"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::dashboard.active_miners') }}</span>
                                    <span class="info-box-number">{{ $currentMonthStats['active_miners'] }}</span>
                                    <small>{{ trans('mining-manager::dashboard.this_month') }}</small>
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
                        <div class="col-lg-6 col-md-6">
                            <div class="small-box bg-primary">
                                <div class="inner">
                                    <h3>{{ number_format($last12MonthsStats['all_ore_value'], 0) }}</h3>
                                    <p>{{ trans('mining-manager::dashboard.all_ore_total_value') }}</p>
                                    <small>{{ number_format($last12MonthsStats['all_ore_quantity'], 0) }} units</small>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-gem"></i>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6 col-md-6">
                            <div class="small-box bg-secondary">
                                <div class="inner">
                                    <h3>{{ number_format($last12MonthsStats['moon_ore_value'], 0) }}</h3>
                                    <p>{{ trans('mining-manager::dashboard.moon_ore_total_value') }}</p>
                                    <small>{{ number_format($last12MonthsStats['moon_ore_quantity'], 0) }} units</small>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-moon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- TOP 5 MINERS - OVERALL --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-trophy"></i>
                        {{ trans('mining-manager::dashboard.top_5_miners_overall') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- All Ore --}}
                        <div class="col-lg-6">
                            <h5 class="text-center mb-3">
                                <i class="fas fa-gem"></i> {{ trans('mining-manager::dashboard.all_ore') }}
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-dark table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px">{{ trans('mining-manager::dashboard.rank') }}</th>
                                            <th>{{ trans('mining-manager::dashboard.character') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::dashboard.value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($topMinersOverallAllOre as $index => $miner)
                                        <tr>
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
                                                <img src="https://images.evetech.net/characters/{{ $miner['character_id'] }}/portrait?size=32" 
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
                                                <br>
                                                <small class="text-muted">{{ $miner['corporation_name'] ?? 'Unknown Corporation' }}</small>
                                            </td>
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

                        {{-- Moon Ore --}}
                        <div class="col-lg-6">
                            <h5 class="text-center mb-3">
                                <i class="fas fa-moon"></i> {{ trans('mining-manager::dashboard.corp_moon_ore') }}
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-dark table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px">{{ trans('mining-manager::dashboard.rank') }}</th>
                                            <th>{{ trans('mining-manager::dashboard.character') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::dashboard.value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($topMinersOverallMoonOre as $index => $miner)
                                        <tr>
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
                                                <img src="https://images.evetech.net/characters/{{ $miner['character_id'] }}/portrait?size=32" 
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
                                                <br>
                                                <small class="text-muted">{{ $miner['corporation_name'] ?? 'Unknown Corporation' }}</small>
                                            </td>
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
        </div>
    </div>

    {{-- TOP 5 MINERS - LAST MONTH --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-medal"></i>
                        {{ trans('mining-manager::dashboard.top_5_miners_last_month') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- All Ore --}}
                        <div class="col-lg-6">
                            <h5 class="text-center mb-3">
                                <i class="fas fa-gem"></i> {{ trans('mining-manager::dashboard.all_ore') }}
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-dark table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px">{{ trans('mining-manager::dashboard.rank') }}</th>
                                            <th>{{ trans('mining-manager::dashboard.character') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::dashboard.value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($topMinersLastMonthAllOre as $index => $miner)
                                        <tr>
                                            <td>
                                                <span class="badge badge-{{ $index == 0 ? 'warning' : ($index == 1 ? 'secondary' : 'info') }}">
                                                    #{{ $index + 1 }}
                                                </span>
                                            </td>
                                            <td>
                                                <img src="https://images.evetech.net/characters/{{ $miner['character_id'] }}/portrait?size=32" 
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
                                                <br>
                                                <small class="text-muted">{{ $miner['corporation_name'] ?? 'Unknown Corporation' }}</small>
                                            </td>
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

                        {{-- Moon Ore --}}
                        <div class="col-lg-6">
                            <h5 class="text-center mb-3">
                                <i class="fas fa-moon"></i> {{ trans('mining-manager::dashboard.corp_moon_ore') }}
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-dark table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px">{{ trans('mining-manager::dashboard.rank') }}</th>
                                            <th>{{ trans('mining-manager::dashboard.character') }}</th>
                                            <th class="text-right">{{ trans('mining-manager::dashboard.value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($topMinersLastMonthMoonOre as $index => $miner)
                                        <tr>
                                            <td>
                                                <span class="badge badge-{{ $index == 0 ? 'warning' : ($index == 1 ? 'secondary' : 'info') }}">
                                                    #{{ $index + 1 }}
                                                </span>
                                            </td>
                                            <td>
                                                <img src="https://images.evetech.net/characters/{{ $miner['character_id'] }}/portrait?size=32" 
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
                                                <br>
                                                <small class="text-muted">{{ $miner['corporation_name'] ?? 'Unknown Corporation' }}</small>
                                            </td>
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
        </div>
    </div>

    {{-- CHARTS ROW 1 --}}
    <div class="row">
        {{-- Mining Performance Chart --}}
        <div class="col-lg-6">
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

        {{-- Moon Mining Performance Chart --}}
        <div class="col-lg-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-moon"></i>
                        {{ trans('mining-manager::dashboard.corp_moon_mining_performance') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" onclick="refreshChart('moon_mining_performance')">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="moonMiningPerformanceChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- CHARTS ROW 2 --}}
    <div class="row">
        {{-- Mining Tax Chart --}}
        <div class="col-lg-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-coins"></i>
                        {{ trans('mining-manager::dashboard.mining_tax_last_12_months') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="miningTaxChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        {{-- Event Tax Chart --}}
        <div class="col-lg-6">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-check"></i>
                        {{ trans('mining-manager::dashboard.event_tax_last_12_months') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="eventTaxChart"></canvas>
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

// Chart data from backend
const chartData = {
    miningPerformance: @json($miningPerformanceChart),
    moonMiningPerformance: @json($moonMiningPerformanceChart),
    miningTax: @json($miningTaxChart),
    eventTax: @json($eventTaxChart)
};

// Mining Performance Chart
const miningPerformanceCtx = document.getElementById('miningPerformanceChart').getContext('2d');
const miningPerformanceChart = new Chart(miningPerformanceCtx, {
    type: 'bar',
    data: {
        labels: chartData.miningPerformance.labels,
        datasets: [{
            label: '{{ trans("mining-manager::dashboard.all_ore_types") }}',
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
                display: true
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

// Moon Mining Performance Chart
const moonMiningPerformanceCtx = document.getElementById('moonMiningPerformanceChart').getContext('2d');
const moonMiningPerformanceChart = new Chart(moonMiningPerformanceCtx, {
    type: 'bar',
    data: {
        labels: chartData.moonMiningPerformance.labels,
        datasets: [{
            label: '{{ trans("mining-manager::dashboard.corp_moons_only") }}',
            data: chartData.moonMiningPerformance.data,
            backgroundColor: 'rgba(255, 0, 132, 0.8)',
            borderColor: 'rgba(255, 0, 132, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true
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

// Mining Tax Chart
const miningTaxCtx = document.getElementById('miningTaxChart').getContext('2d');
const miningTaxChart = new Chart(miningTaxCtx, {
    type: 'line',
    data: {
        labels: chartData.miningTax.labels,
        datasets: [{
            label: '{{ trans("mining-manager::dashboard.tax_isk") }}',
            data: chartData.miningTax.data,
            backgroundColor: 'rgba(0, 210, 255, 0.2)',
            borderColor: 'rgba(0, 210, 255, 1)',
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
                display: true
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

// Event Tax Chart
const eventTaxCtx = document.getElementById('eventTaxChart').getContext('2d');
const eventTaxChart = new Chart(eventTaxCtx, {
    type: 'line',
    data: {
        labels: chartData.eventTax.labels,
        datasets: [{
            label: '{{ trans("mining-manager::dashboard.event_tax_isk") }}',
            data: chartData.eventTax.data,
            backgroundColor: 'rgba(255, 159, 64, 0.2)',
            borderColor: 'rgba(255, 159, 64, 1)',
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
                display: true
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

// Refresh chart function
function refreshChart(chartType) {
    $.ajax({
        url: '{{ route("mining-manager.dashboard.live-data") }}',
        data: { chart_type: chartType },
        success: function(response) {
            if (response.success) {
                // Update corresponding chart
                let chart;
                switch(chartType) {
                    case 'mining_performance':
                        chart = miningPerformanceChart;
                        break;
                    case 'moon_mining_performance':
                        chart = moonMiningPerformanceChart;
                        break;
                    case 'mining_tax':
                        chart = miningTaxChart;
                        break;
                    case 'event_tax':
                        chart = eventTaxChart;
                        break;
                }
                
                if (chart) {
                    chart.data.labels = response.data.labels;
                    chart.data.datasets[0].data = response.data.data;
                    chart.update();
                }
                
                toastr.success('{{ trans("mining-manager::dashboard.chart_updated") }}');
            }
        }
    });
}

// Auto-refresh every 5 minutes
setInterval(function() {
    refreshChart('mining_performance');
    refreshChart('moon_mining_performance');
}, 300000);
</script>
@endpush
@endsection
