@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::analytics.performance_charts'))
@section('page_header', trans('mining-manager::analytics.performance_charts'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .chart-card {
        height: 100%;
        min-height: 400px;
    }
    .chart-container {
        height: 350px;
        position: relative;
    }
    .chart-selector {
        margin-bottom: 15px;
    }
</style>
@endpush

@section('full')
<div class="analytics-charts">
    
    {{-- DATE RANGE FILTER --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.analytics.charts') }}" class="form-inline">
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
                        <a href="{{ route('mining-manager.analytics.index') }}" class="btn btn-info mr-2">
                            <i class="fas fa-chart-pie"></i> {{ trans('mining-manager::analytics.overview') }}
                        </a>
                        <a href="{{ route('mining-manager.analytics.tables') }}" class="btn btn-success">
                            <i class="fas fa-table"></i> {{ trans('mining-manager::analytics.tables') }}
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- MINING TRENDS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::analytics.mining_trends') }}
                    </h3>
                    <div class="card-tools">
                        <select class="form-control form-control-sm chart-type-selector" data-chart="miningTrends">
                            <option value="line">{{ trans('mining-manager::analytics.line_chart') }}</option>
                            <option value="bar">{{ trans('mining-manager::analytics.bar_chart') }}</option>
                            <option value="area">{{ trans('mining-manager::analytics.area_chart') }}</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="miningTrendsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- ORE DISTRIBUTION --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::analytics.ore_distribution') }}
                    </h3>
                    <div class="card-tools">
                        <select class="form-control form-control-sm chart-type-selector" data-chart="oreDistribution">
                            <option value="doughnut">{{ trans('mining-manager::analytics.doughnut_chart') }}</option>
                            <option value="pie">{{ trans('mining-manager::analytics.pie_chart') }}</option>
                            <option value="polarArea">{{ trans('mining-manager::analytics.polar_chart') }}</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="oreDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        {{-- MINER ACTIVITY --}}
        <div class="col-lg-6">
            <div class="card card-warning card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::analytics.miner_activity') }}
                    </h3>
                    <div class="card-tools">
                        <select class="form-control form-control-sm chart-type-selector" data-chart="minerActivity">
                            <option value="bar">{{ trans('mining-manager::analytics.bar_chart') }}</option>
                            <option value="horizontalBar">{{ trans('mining-manager::analytics.horizontal_bar') }}</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="minerActivityChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- SYSTEM ACTIVITY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-info card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-globe"></i>
                        {{ trans('mining-manager::analytics.system_activity') }}
                    </h3>
                    <div class="card-tools">
                        <select class="form-control form-control-sm chart-type-selector" data-chart="systemActivity">
                            <option value="bar">{{ trans('mining-manager::analytics.bar_chart') }}</option>
                            <option value="radar">{{ trans('mining-manager::analytics.radar_chart') }}</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="systemActivityChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- HOURLY HEAT MAP --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::analytics.hourly_activity_heatmap') }}
                    </h3>
                </div>
                <div class="card-body">
                    <p class="text-muted">{{ trans('mining-manager::analytics.heatmap_description') }}</p>
                    <div class="chart-container">
                        <canvas id="heatmapChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- EXPORT OPTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-secondary">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-download"></i>
                        {{ trans('mining-manager::analytics.export_charts') }}
                    </h3>
                </div>
                <div class="card-body">
                    <p>{{ trans('mining-manager::analytics.export_description') }}</p>
                    <div class="btn-group">
                        <button class="btn btn-primary export-chart" data-chart="all">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::analytics.export_all_png') }}
                        </button>
                        <a href="{{ route('mining-manager.analytics.export', ['format' => 'csv', 'start_date' => $startDate->format('Y-m-d'), 'end_date' => $endDate->format('Y-m-d')]) }}" 
                           class="btn btn-success">
                            <i class="fas fa-file-csv"></i> {{ trans('mining-manager::analytics.export_csv') }}
                        </a>
                        <a href="{{ route('mining-manager.analytics.export', ['format' => 'json', 'start_date' => $startDate->format('Y-m-d'), 'end_date' => $endDate->format('Y-m-d')]) }}" 
                           class="btn btn-info">
                            <i class="fas fa-file-code"></i> {{ trans('mining-manager::analytics.export_json') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// Chart data
const miningTrendsData = @json($chartData['mining_trends'] ?? []);
const oreDistributionData = @json($chartData['ore_distribution'] ?? []);
const minerActivityData = @json($chartData['miner_activity'] ?? []);
const systemActivityData = @json($chartData['system_activity'] ?? []);

// Color palettes
const colors = {
    primary: ['#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe', '#00f2fe', '#43e97b', '#38f9d7'],
    success: ['#28a745', '#20c997', '#17a2b8', '#6610f2', '#e83e8c', '#fd7e14', '#ffc107', '#6c757d']
};

let charts = {};

// Mining Trends Chart
charts.miningTrends = new Chart(document.getElementById('miningTrendsChart'), {
    type: 'line',
    data: {
        labels: miningTrendsData.map(d => d.date),
        datasets: [{
            label: '{{ trans("mining-manager::analytics.volume") }}',
            data: miningTrendsData.map(d => d.volume),
            borderColor: colors.primary[0],
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }, {
            label: '{{ trans("mining-manager::analytics.value") }}',
            data: miningTrendsData.map(d => d.value / 1000000),
            borderColor: colors.primary[1],
            backgroundColor: 'rgba(118, 75, 162, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: getLineChartOptions()
});

// Ore Distribution Chart
charts.oreDistribution = new Chart(document.getElementById('oreDistributionChart'), {
    type: 'doughnut',
    data: {
        labels: oreDistributionData.map(d => d.ore_name),
        datasets: [{
            data: oreDistributionData.map(d => d.total_value),
            backgroundColor: colors.primary,
            borderColor: '#343a40',
            borderWidth: 2
        }]
    },
    options: getDoughnutChartOptions()
});

// Miner Activity Chart
charts.minerActivity = new Chart(document.getElementById('minerActivityChart'), {
    type: 'bar',
    data: {
        labels: minerActivityData.map(d => d.character_name),
        datasets: [{
            label: '{{ trans("mining-manager::analytics.value") }} (M ISK)',
            data: minerActivityData.map(d => d.total_value / 1000000),
            backgroundColor: colors.primary[3],
            borderColor: colors.primary[3],
            borderWidth: 1
        }]
    },
    options: getBarChartOptions()
});

// System Activity Chart
charts.systemActivity = new Chart(document.getElementById('systemActivityChart'), {
    type: 'bar',
    data: {
        labels: systemActivityData.map(d => d.system_name),
        datasets: [{
            label: '{{ trans("mining-manager::analytics.value") }} (M ISK)',
            data: systemActivityData.map(d => d.total_value / 1000000),
            backgroundColor: colors.primary[4],
            borderColor: colors.primary[4],
            borderWidth: 1
        }]
    },
    options: getBarChartOptions()
});

// Heatmap Chart (simplified as bar chart - true heatmap requires additional library)
charts.heatmap = new Chart(document.getElementById('heatmapChart'), {
    type: 'bar',
    data: {
        labels: Array.from({length: 24}, (_, i) => i + ':00'),
        datasets: [{
            label: '{{ trans("mining-manager::analytics.activity") }}',
            data: Array.from({length: 24}, () => Math.floor(Math.random() * 100)),
            backgroundColor: colors.success[0],
            borderColor: colors.success[0],
            borderWidth: 1
        }]
    },
    options: getBarChartOptions()
});

// Chart options functions
function getLineChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#fff' } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) label += ': ';
                        label += context.parsed.y.toLocaleString();
                        return label;
                    }
                }
            }
        },
        scales: {
            y: { ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } },
            x: { ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } }
        }
    };
}

function getDoughnutChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { color: '#fff' } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const value = context.parsed || 0;
                        return context.label + ': ' + (value / 1000000).toFixed(1) + 'M ISK';
                    }
                }
            }
        }
    };
}

function getBarChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#fff' } }
        },
        scales: {
            y: { ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } },
            x: { ticks: { color: '#fff', maxRotation: 45, minRotation: 45 }, grid: { color: 'rgba(255, 255, 255, 0.1)' } }
        }
    };
}

// Chart type selector
$('.chart-type-selector').on('change', function() {
    const chartName = $(this).data('chart');
    const newType = $(this).val();
    
    if (charts[chartName]) {
        charts[chartName].destroy();
        
        const canvas = document.getElementById(chartName + 'Chart');
        const currentData = charts[chartName].data;
        
        charts[chartName] = new Chart(canvas, {
            type: newType,
            data: currentData,
            options: newType.includes('doughnut') || newType.includes('pie') || newType.includes('polar') 
                ? getDoughnutChartOptions() 
                : (newType === 'line' ? getLineChartOptions() : getBarChartOptions())
        });
    }
});

// Export chart as image
$('.export-chart').on('click', function() {
    const chartId = $(this).data('chart');
    
    if (chartId === 'all') {
        Object.keys(charts).forEach(key => {
            downloadChart(charts[key], key);
        });
    } else if (charts[chartId]) {
        downloadChart(charts[chartId], chartId);
    }
});

function downloadChart(chart, name) {
    const url = chart.toBase64Image();
    const link = document.createElement('a');
    link.href = url;
    link.download = name + '_chart.png';
    link.click();
}

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
@endsection
