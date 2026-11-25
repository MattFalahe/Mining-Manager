@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::menu.my_performance'))
@section('page_header', trans_choice('mining-manager::ledger.mining_ledger', 2))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=10">
<style>
    .chart-card {
        height: 100%;
        min-height: 400px;
    }
    .chart-container {
        height: 350px;
        position: relative;
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard my-performance-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.ledger.index') }}">
                    <i class="fas fa-layer-group"></i> {{ trans('mining-manager::ledger.mining_summary') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.ledger.my-mining') }}">
                    <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('mining-manager.ledger.my-performance') }}">
                    <i class="fas fa-chart-line"></i> {{ trans('mining-manager::menu.my_performance') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.ledger.reprocessing') }}">
                    <i class="fas fa-recycle"></i> {{ trans('mining-manager::ledger.reprocessing_calculator') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">

<div class="my-performance">

    @if(!$hasCharacters)
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> {{ trans('mining-manager::ledger.no_characters') }}
    </div>
    @else

    {{-- FILTERS --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-body">
                    <p class="text-muted mb-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::ledger.my_performance_intro') }}</p>
                    <form method="GET" action="{{ route('mining-manager.ledger.my-performance') }}" class="form-inline flex-wrap">
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="performance_start_date">{{ trans('mining-manager::analytics.start_date') }}</label>
                            <input type="date" name="start_date" id="performance_start_date" class="form-control" value="{{ $startDate->format('Y-m-d') }}">
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="performance_end_date">{{ trans('mining-manager::analytics.end_date') }}</label>
                            <input type="date" name="end_date" id="performance_end_date" class="form-control" value="{{ $endDate->format('Y-m-d') }}">
                        </div>

                        {{-- Only corporations one of the viewer's characters is in. --}}
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="corporation_id"><i class="fas fa-building"></i> Corporation</label>
                            <select name="corporation_id" id="corporation_id" class="form-control">
                                <option value="" {{ empty($corporationId) ? 'selected' : '' }}>{{ trans('mining-manager::ledger.my_corporations_all') }}</option>
                                @foreach($corporations as $corpId => $corpName)
                                    <option value="{{ $corpId }}" {{ $corporationId == $corpId ? 'selected' : '' }}>{{ $corpName }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="moon_source"><i class="fas fa-moon"></i> {{ trans('mining-manager::analytics.source') }}</label>
                            <select name="moon_source" id="moon_source" class="form-control">
                                @foreach(\MiningManager\Services\Analytics\ChartFilter::MOON_SOURCES as $sourceKey)
                                    <option value="{{ $sourceKey }}" {{ $filter->moonSource() === $sourceKey ? 'selected' : '' }}>
                                        {{ trans('mining-manager::analytics.source_' . $sourceKey) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="ore_category"><i class="fas fa-gem"></i> {{ trans('mining-manager::analytics.ore_type') }}</label>
                            <select name="ore_category" id="ore_category" class="form-control">
                                <option value="">{{ trans('mining-manager::analytics.ore_type_all') }}</option>
                                @foreach(\MiningManager\Services\Analytics\ChartFilter::ORE_CATEGORIES as $catKey => $catLabel)
                                    <option value="{{ $catKey }}" {{ $filter->oreCategory() === $catKey ? 'selected' : '' }}>
                                        {{ $catLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- The characters on the viewer's own account, nobody else's. --}}
                        <div class="form-group mr-3 mb-2">
                            <label class="mr-2" for="character_id"><i class="fas fa-user"></i> {{ trans('mining-manager::ledger.character') }}</label>
                            <select name="character_id" id="character_id" class="form-control">
                                <option value="">{{ trans('mining-manager::ledger.my_characters_all') }}</option>
                                @foreach($characters as $charId => $charName)
                                    <option value="{{ $charId }}" {{ $characterId == $charId ? 'selected' : '' }}>{{ $charName }}</option>
                                @endforeach
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary mr-2 mb-2">
                            <i class="fas fa-filter"></i> {{ trans('mining-manager::analytics.filter') }}
                        </button>
                        <div class="btn-group mr-2 mb-2">
                            <button type="button" class="btn btn-secondary quick-filter" data-days="7">7d</button>
                            <button type="button" class="btn btn-secondary quick-filter" data-days="30">30d</button>
                            <button type="button" class="btn btn-secondary quick-filter" data-days="90">90d</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @php
        $sliceIsEmpty = empty($chartData['mining_trends'] ?? null)
            && empty($chartData['ore_distribution']['labels'] ?? [])
            && empty($chartData['character_activity']['labels'] ?? [])
            && empty($chartData['system_activity']['labels'] ?? []);
    @endphp

    @if($sliceIsEmpty)
    <div class="row">
        <div class="col-12">
            <div class="alert alert-warning">
                <i class="fas fa-filter"></i> {{ trans('mining-manager::ledger.my_performance_empty') }}
                @if($filter->moonSource() === \MiningManager\Services\Analytics\ChartFilter::MOON_MINE)
                    <div class="mt-1">{{ trans('mining-manager::analytics.empty_slice_my_moons') }}</div>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if($filter->moonSource() === \MiningManager\Services\Analytics\ChartFilter::MOON_OTHER)
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info py-2">
                <i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.other_moons_inferred') }}
            </div>
        </div>
    </div>
    @endif

    @if($classificationCutover)
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info py-2">
                <i class="fas fa-clock"></i>
                {{ trans('mining-manager::analytics.classification_cutover_note', [
                    'date' => $classificationCutover->format('j M Y'),
                ]) }}
            </div>
        </div>
    </div>
    @endif

    {{-- MINING TRENDS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::analytics.mining_trends') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="miningTrendsChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_mining_trends') }}</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- ORE DISTRIBUTION (ISK) --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::analytics.ore_distribution') }} (ISK)
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="oreDistributionChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_ore_distribution_isk') }}</small>
                </div>
            </div>
        </div>

        {{-- ORE DISTRIBUTION (QUANTITY) --}}
        <div class="col-lg-6">
            <div class="card card-success card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-cubes"></i>
                        {{ trans('mining-manager::analytics.ore_distribution') }} ({{ trans('mining-manager::analytics.total_quantity') }})
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="oreDistributionQtyChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_ore_distribution_qty') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- CHARACTER ACTIVITY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-warning card-outline chart-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::ledger.character_activity') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="characterActivityChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::ledger.note_character_activity') }}</small>
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
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="systemActivityChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::analytics.note_system_activity') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- WEEKLY ACTIVITY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::analytics.weekly_activity') }}
                    </h3>
                    <div class="card-tools">
                        <div class="btn-group btn-group-sm" id="heatmapViewToggle">
                            <button type="button" class="btn btn-outline-light active" data-view="summary">{{ trans('mining-manager::analytics.summary') }}</button>
                            <button type="button" class="btn btn-outline-light" data-view="character">{{ trans('mining-manager::ledger.by_character') }}</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <small class="text-muted d-block mb-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::ledger.note_my_weekly_activity') }}</small>
                    <div class="chart-container" style="height: 500px;">
                        <canvas id="heatmapChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($features['allow_export_data'] ?? true)
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
                    <button class="btn btn-primary export-chart" data-chart="all">
                        <i class="fas fa-download"></i> {{ trans('mining-manager::analytics.export_all_png') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    @endif

</div>

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection

@push('javascript')
@if($hasCharacters)
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
const miningTrendsData = @json($chartData['mining_trends'] ?? []);
const oreDistributionData = @json($chartData['ore_distribution'] ?? []);
const characterActivityData = @json($chartData['character_activity'] ?? []);
const systemActivityData = @json($chartData['system_activity'] ?? []);
const heatmapData = @json($chartData['heatmap'] ?? []);

const colors = {
    primary: ['#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe', '#00f2fe', '#43e97b', '#38f9d7']
};
const heatmapColors = ['#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe', '#00f2fe', '#43e97b', '#38f9d7', '#fbbf24', '#ef4444', '#10b981', '#8b5cf6'];

let charts = {};

function showNoDataMessage(canvasId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const msg = document.createElement('div');
    msg.className = 'text-center text-muted py-4';
    msg.innerHTML = '<i class="fas fa-chart-bar fa-2x mb-2"></i><br>{{ trans("mining-manager::analytics.no_data_available") }}';
    canvas.parentElement.replaceChild(msg, canvas);
}

function getBarChartOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#fff' } } },
        scales: {
            y: { ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } },
            x: { ticks: { color: '#fff', maxRotation: 45, minRotation: 45 }, grid: { color: 'rgba(255, 255, 255, 0.1)' } }
        }
    };
}

function doughnutOptions(labelFor) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { color: '#fff' } },
            tooltip: { callbacks: { label: labelFor } }
        }
    };
}

// Mining Trends
if (!miningTrendsData || miningTrendsData.length === 0) {
    showNoDataMessage('miningTrendsChart');
} else {
    charts.miningTrends = new Chart(document.getElementById('miningTrendsChart'), {
        type: 'line',
        data: {
            labels: miningTrendsData.map(d => d.date ? d.date.substring(0, 10) : ''),
            datasets: [{
                label: '{{ trans("mining-manager::analytics.total_quantity") }}',
                data: miningTrendsData.map(d => d.total_quantity),
                borderColor: colors.primary[0],
                backgroundColor: 'rgba(102, 126, 234, 0.15)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y',
                pointRadius: 3,
                pointBackgroundColor: colors.primary[0]
            }, {
                label: '{{ trans("mining-manager::analytics.value") }}',
                data: miningTrendsData.map(d => d.total_value),
                borderColor: '#f5576c',
                backgroundColor: 'rgba(245, 87, 108, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y1',
                pointRadius: 3,
                pointBackgroundColor: '#f5576c'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#fff' } },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label ? context.dataset.label + ': ' : '';
                            return label + (context.dataset.yAxisID === 'y1'
                                ? (context.parsed.y / 1000000).toFixed(1) + ' M ISK'
                                : context.parsed.y.toLocaleString());
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    title: { display: true, text: '{{ trans("mining-manager::analytics.total_quantity") }}', color: '#fff' },
                    ticks: { color: colors.primary[0] },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    title: { display: true, text: '{{ trans("mining-manager::analytics.value") }} (ISK)', color: '#fff' },
                    ticks: { color: '#f5576c', callback: value => (value / 1000000).toFixed(0) + 'M' },
                    grid: { drawOnChartArea: false }
                },
                x: { ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } }
            }
        }
    });
}

// Ore Distribution, by ISK and by quantity
if (!oreDistributionData || !oreDistributionData.labels || oreDistributionData.labels.length === 0) {
    showNoDataMessage('oreDistributionChart');
    showNoDataMessage('oreDistributionQtyChart');
} else {
    charts.oreDistribution = new Chart(document.getElementById('oreDistributionChart'), {
        type: 'doughnut',
        data: { labels: oreDistributionData.labels, datasets: [{ data: oreDistributionData.values, backgroundColor: colors.primary, borderColor: '#343a40', borderWidth: 2 }] },
        options: doughnutOptions(context => context.label + ': ' + ((context.parsed || 0) / 1000000).toFixed(1) + 'M ISK')
    });
    charts.oreDistributionQty = new Chart(document.getElementById('oreDistributionQtyChart'), {
        type: 'doughnut',
        data: { labels: oreDistributionData.labels, datasets: [{ data: oreDistributionData.data, backgroundColor: colors.primary, borderColor: '#343a40', borderWidth: 2 }] },
        options: doughnutOptions(context => context.label + ': ' + context.parsed.toLocaleString() + ' units')
    });
}

// Character Activity
if (!characterActivityData || !characterActivityData.labels || characterActivityData.labels.length === 0) {
    showNoDataMessage('characterActivityChart');
} else {
    charts.characterActivity = new Chart(document.getElementById('characterActivityChart'), {
        type: 'bar',
        data: {
            labels: characterActivityData.labels,
            datasets: [{
                label: '{{ trans("mining-manager::analytics.value") }} (M ISK)',
                data: characterActivityData.values.map(v => v / 1000000),
                backgroundColor: colors.primary[3],
                borderColor: colors.primary[3],
                borderWidth: 1
            }]
        },
        options: getBarChartOptions()
    });
}

// System Activity
if (!systemActivityData || !systemActivityData.labels || systemActivityData.labels.length === 0) {
    showNoDataMessage('systemActivityChart');
} else {
    charts.systemActivity = new Chart(document.getElementById('systemActivityChart'), {
        type: 'bar',
        data: {
            labels: systemActivityData.labels,
            datasets: [{
                label: '{{ trans("mining-manager::analytics.value") }} (M ISK)',
                data: systemActivityData.values.map(v => v / 1000000),
                backgroundColor: colors.primary[4],
                borderColor: colors.primary[4],
                borderWidth: 1
            }]
        },
        options: getBarChartOptions()
    });
}

// Weekly Activity: the average day, or the totals split by character
function buildHeatmapChart(view) {
    if (charts.heatmap) charts.heatmap.destroy();

    const labels = heatmapData.day_labels || ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    let datasets = [];

    if (view === 'summary') {
        const values = (heatmapData.summary || []).map(d => d.avg_value);
        const max = Math.max(...values, 0);
        datasets = [{
            label: 'Avg Value (M ISK)',
            data: values.map(v => v / 1000000),
            backgroundColor: values.map(v => `rgba(102, 126, 234, ${max > 0 ? 0.3 + (v / max) * 0.7 : 0.3})`),
            borderColor: '#667eea',
            borderWidth: 1
        }];
    } else {
        (heatmapData.by_character || []).forEach((entry, i) => {
            datasets.push({
                label: entry.label,
                data: entry.data.map(v => v / 1000000),
                backgroundColor: heatmapColors[i % heatmapColors.length],
                borderColor: heatmapColors[i % heatmapColors.length],
                borderWidth: 1
            });
        });
    }

    charts.heatmap = new Chart(document.getElementById('heatmapChart'), {
        type: 'bar',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: view !== 'summary', labels: { color: '#fff' } },
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(1) + ' M ISK' } }
            },
            scales: {
                y: {
                    stacked: view !== 'summary',
                    title: { display: true, text: view === 'summary' ? 'Avg Value (M ISK)' : 'Total Value (M ISK)', color: '#fff' },
                    ticks: { color: '#fff' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                x: { stacked: view !== 'summary', ticks: { color: '#fff' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } }
            }
        }
    });
}

if (heatmapData && heatmapData.summary) {
    buildHeatmapChart('summary');
} else {
    showNoDataMessage('heatmapChart');
}

$('#heatmapViewToggle .btn').on('click', function() {
    $('#heatmapViewToggle .btn').removeClass('active');
    $(this).addClass('active');
    buildHeatmapChart($(this).data('view'));
});

$('.export-chart').on('click', function() {
    Object.keys(charts).forEach(key => {
        const link = document.createElement('a');
        link.href = charts[key].toBase64Image();
        link.download = key + '_chart.png';
        link.click();
    });
});

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
@endif
@endpush
