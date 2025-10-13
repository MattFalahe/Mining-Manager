@extends('web::layouts.grids.12')

@section('title', 'Mining Manager - Dashboard')

@push('head')
<link rel="stylesheet" href="{{ asset('web/assets/mining-manager/css/mining-manager.css') }}">
@endpush

@section('content')
<div class="mining-dashboard">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-primary text-white">
                <div class="card-body">
                    <h1 class="mb-0">
                        <i class="fas fa-gem"></i> Mining Operations Dashboard
                    </h1>
                    <p class="mb-0">Real-time mining analytics and management</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        @foreach($summary as $metric)
        <div class="col-md-3 col-sm-6">
            <div class="info-box shadow-sm">
                <span class="info-box-icon bg-{{ $metric['color'] }}">
                    <i class="{{ $metric['icon'] }}"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ $metric['title'] }}</span>
                    <span class="info-box-number">
                        {{ $metric['value'] }}
                    </span>
                    @if($metric['change'])
                    <div class="progress">
                        <div class="progress-bar bg-{{ $metric['color'] }}" style="width: {{ abs($metric['change']) }}%"></div>
                    </div>
                    <span class="progress-description">
                        <i class="fas fa-{{ $metric['change'] > 0 ? 'arrow-up' : 'arrow-down' }}"></i>
                        {{ $metric['change'] }}% from last period
                    </span>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <!-- Mining Trends Chart -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header border-0">
                    <div class="d-flex justify-content-between">
                        <h3 class="card-title">
                            <i class="fas fa-chart-area"></i> Mining Trends
                        </h3>
                        <div class="card-tools">
                            <div class="btn-group btn-group-sm" id="trend-period">
                                <button type="button" class="btn btn-primary" data-period="7">7D</button>
                                <button type="button" class="btn btn-outline-primary" data-period="30">30D</button>
                                <button type="button" class="btn btn-outline-primary" data-period="90">90D</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="trendsChart" height="300"></canvas>
                </div>
            </div>
        </div>

        <!-- Ore Distribution -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header border-0">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i> Ore Distribution
                    </h3>
                </div>
                <div class="card-body">
                    <canvas id="distributionChart" height="300"></canvas>
                    <div id="distributionLegend" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tables Row -->
    <div class="row">
        <!-- Top Miners -->
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header border-0">
                    <h3 class="card-title">
                        <i class="fas fa-trophy"></i> Top Miners This Month
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="collapse">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover m-0">
                            <thead>
                                <tr>
                                    <th width="40">#</th>
                                    <th>Miner</th>
                                    <th>Volume</th>
                                    <th>Value</th>
                                    <th>Tax Due</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($topMiners as $index => $miner)
                                <tr>
                                    <td>
                                        @if($index < 3)
                                        <span class="badge badge-{{ ['warning', 'secondary', 'success'][$index] }}">
                                            {{ $index + 1 }}
                                        </span>
                                        @else
                                        {{ $index + 1 }}
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="https://images.evetech.net/characters/{{ $miner->character_id }}/portrait?size=32" 
                                                 class="img-circle mr-2" 
                                                 alt="{{ $miner->character_name }}"
                                                 width="32">
                                            <div>
                                                <strong>{{ $miner->character_name }}</strong>
                                                @if($miner->main_name && $miner->main_name !== $miner->character_name)
                                                <br><small class="text-muted">{{ $miner->main_name }}</small>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ number_format($miner->total_volume) }} m³</td>
                                    <td>{{ number_format($miner->total_value / 1000000, 1) }}M ISK</td>
                                    <td>
                                        <span class="badge badge-info">
                                            {{ number_format($miner->total_value * 0.1 / 1000000, 1) }}M
                                        </span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upcoming Extractions -->
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header border-0">
                    <h3 class="card-title">
                        <i class="fas fa-moon"></i> Upcoming Moon Extractions
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="collapse">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover m-0">
                            <thead>
                                <tr>
                                    <th>Moon</th>
                                    <th>Arrival</th>
                                    <th>Composition</th>
                                    <th>Est. Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($upcomingExtractions as $extraction)
                                <tr>
                                    <td>
                                        <strong>{{ $extraction->moon_name }}</strong>
                                        <br><small class="text-muted">{{ $extraction->system_name }}</small>
                                    </td>
                                    <td>
                                        <countdown-timer 
                                            :end-time="{{ json_encode($extraction->chunk_arrival_at) }}"
                                            class="badge badge-primary">
                                        </countdown-timer>
                                    </td>
                                    <td>
                                        @foreach(json_decode($extraction->ore_composition) as $ore)
                                        <span class="badge badge-{{ $ore->rarity_class }}">
                                            {{ $ore->percentage }}% {{ $ore->name }}
                                        </span>
                                        @endforeach
                                    </td>
                                    <td>
                                        <strong>{{ number_format($extraction->estimated_value / 1000000000, 1) }}B ISK</strong>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted">
                                        No upcoming extractions scheduled
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

    <!-- Recent Activity -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header border-0">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i> Recent Mining Activity
                    </h3>
                </div>
                <div class="card-body">
                    <div id="activityTimeline" class="timeline">
                        @foreach($recentActivity as $activity)
                        <div class="timeline-item">
                            <span class="time">
                                <i class="fas fa-clock"></i> {{ $activity->time_ago }}
                            </span>
                            <h3 class="timeline-header">
                                <img src="https://images.evetech.net/characters/{{ $activity->character_id }}/portrait?size=32" 
                                     class="img-circle" 
                                     width="24">
                                {{ $activity->character_name }}
                            </h3>
                            <div class="timeline-body">
                                Mined <strong>{{ number_format($activity->quantity) }}</strong> units of 
                                <span class="badge badge-info">{{ $activity->ore_name }}</span>
                                in {{ $activity->system_name }}
                                <span class="float-right text-muted">
                                    Value: {{ number_format($activity->value / 1000000, 1) }}M ISK
                                </span>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script src="{{ asset('web/assets/mining-manager/js/chart.min.js') }}"></script>
<script src="{{ asset('web/assets/mining-manager/js/moment.min.js') }}"></script>
<script>
$(document).ready(function() {
    // Initialize dashboard
    const dashboard = new MiningDashboard();
    dashboard.init();
    
    // Period selector
    $('#trend-period button').click(function() {
        $('#trend-period button').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).removeClass('btn-outline-primary').addClass('btn-primary');
        dashboard.updatePeriod($(this).data('period'));
    });
    
    // Auto-refresh every 60 seconds
    setInterval(() => dashboard.refresh(), 60000);
});

class MiningDashboard {
    constructor() {
        this.trendsChart = null;
        this.distributionChart = null;
        this.currentPeriod = 30;
    }
    
    init() {
        this.initCharts();
        this.loadData();
    }
    
    initCharts() {
        // Trends Chart
        const trendsCtx = document.getElementById('trendsChart').getContext('2d');
        this.trendsChart = new Chart(trendsCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Total Value (ISK)',
                    data: [],
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.1,
                    fill: true
                }, {
                    label: 'Active Miners',
                    data: [],
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    tension: 0.1,
                    fill: false,
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
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        ticks: {
                            callback: function(value) {
                                return (value / 1000000000).toFixed(1) + 'B';
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (context.parsed.y !== null) {
                                    if (context.datasetIndex === 0) {
                                        label += ': ' + (context.parsed.y / 1000000000).toFixed(2) + 'B ISK';
                                    } else {
                                        label += ': ' + context.parsed.y;
                                    }
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
        
        // Distribution Chart
        const distCtx = document.getElementById('distributionChart').getContext('2d');
        this.distributionChart = new Chart(distCtx, {
            type: 'doughnut',
            data: {
                labels: [],
                datasets: [{
                    data: [],
                    backgroundColor: [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#4BC0C0',
                        '#9966FF',
                        '#FF9F40',
                        '#FF6384',
                        '#C9CBCF'
                    ]
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
                                const label = context.label || '';
                                const value = (context.parsed / 1000000000).toFixed(2);
                                const percentage = ((context.parsed / context.dataset.data.reduce((a, b) => a + b, 0)) * 100).toFixed(1);
                                return `${label}: ${value}B ISK (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
    
    loadData() {
        $.get('/mining/dashboard/data', { period: this.currentPeriod })
            .done(data => {
                this.updateCharts(data);
            });
    }
    
    updateCharts(data) {
        // Update trends chart
        if (data.trends) {
            this.trendsChart.data.labels = data.trends.map(t => t.date);
            this.trendsChart.data.datasets[0].data = data.trends.map(t => t.total_value);
            this.trendsChart.data.datasets[1].data = data.trends.map(t => t.unique_miners);
            this.trendsChart.update();
        }
        
        // Update distribution chart
        if (data.distribution) {
            this.distributionChart.data.labels = data.distribution.map(d => d.ore_group);
            this.distributionChart.data.datasets[0].data = data.distribution.map(d => d.value);
            this.distributionChart.update();
            
            // Update legend
            this.updateDistributionLegend(data.distribution);
        }
    }
    
    updateDistributionLegend(distribution) {
        let legendHtml = '<div class="row">';
        distribution.forEach((item, index) => {
            const color = this.distributionChart.data.datasets[0].backgroundColor[index];
            legendHtml += `
                <div class="col-6">
                    <span style="background-color: ${color}; width: 12px; height: 12px; display: inline-block; margin-right: 5px;"></span>
                    <small>${item.ore_group}: ${item.percentage}%</small>
                </div>
            `;
        });
        legendHtml += '</div>';
        $('#distributionLegend').html(legendHtml);
    }
    
    updatePeriod(period) {
        this.currentPeriod = period;
        this.loadData();
    }
    
    refresh() {
        this.loadData();
    }
}
</script>
@endpush
