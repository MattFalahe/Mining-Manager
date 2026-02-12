@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.moon_simulator'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper moon-simulator-page">

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
                <i class="fas fa-flask"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">


<div class="moon-simulator">

    {{-- SIMULATOR EXPLANATION --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="alert alert-info">
                <h5><i class="fas fa-flask"></i> {{ trans('mining-manager::moons.simulator_title') }}</h5>
                <p class="mb-0">{{ trans('mining-manager::moons.simulator_description') }}</p>
            </div>
        </div>
    </div>

    {{-- EXTRACTION MODEL EXPLANATION --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-outline card-warning collapsed-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-lightbulb text-warning"></i>
                        {{ trans('mining-manager::moons.model_title') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="collapse">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body" style="display: none;">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-search"></i> {{ trans('mining-manager::moons.model_discovery') }}</h6>
                            <p class="small">
                                {{ trans('mining-manager::moons.model_discovery_text') }}
                            </p>
                            <p class="small">
                                {{ trans('mining-manager::moons.model_discovery_text2') }}
                            </p>
                            <h6 class="mt-3"><i class="fas fa-chart-line"></i> {{ trans('mining-manager::moons.model_observed_data') }}</h6>
                            <table class="table table-sm table-dark small">
                                <thead>
                                    <tr>
                                        <th>{{ trans('mining-manager::moons.composition') }}</th>
                                        <th>{{ trans('mining-manager::moons.extraction_rate') }}</th>
                                        <th>{{ trans('mining-manager::moons.example_ores') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><span class="badge badge-success">100%</span></td>
                                        <td>~31,000 m³/h</td>
                                        <td class="text-muted small">Sylvite 46%, Chromite 34%, Zeolites 20%</td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge badge-info">~82%</span></td>
                                        <td>~30,500 m³/h</td>
                                        <td class="text-muted small">Euxenite 36%, Coesite 24%, Cobaltite 22%</td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge badge-warning">~70%</span></td>
                                        <td>~21,600 m³/h</td>
                                        <td class="text-muted small">Sylvite 40%, Euxenite 21%, Sperrylite 9%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-calculator"></i> {{ trans('mining-manager::moons.model_formula') }}</h6>
                            <div class="bg-dark p-3 rounded mb-3">
                                <code class="text-success">
                                    rate = 21,000 + ((composition% - 70%) / 30%) × 10,000
                                </code>
                            </div>
                            <p class="small">
                                {{ trans('mining-manager::moons.model_formula_explanation') }}
                            </p>
                            <h6 class="mt-3"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::moons.model_meaning') }}</h6>
                            <ul class="small mb-0">
                                <li>{{ trans('mining-manager::moons.model_meaning_higher') }}</li>
                                <li>{{ trans('mining-manager::moons.model_meaning_lower') }}</li>
                                <li>The <span class="badge badge-success">composition %</span> badge shows your moon's ore richness</li>
                                <li>The <span class="badge badge-warning">m³/h rate</span> badge shows the calculated extraction rate</li>
                            </ul>
                            <div class="alert alert-secondary small mt-3 mb-0">
                                <i class="fas fa-flask"></i> <strong>{{ trans('mining-manager::moons.note') }}:</strong> {{ trans('mining-manager::moons.model_note') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($scannedMoons->count() > 0)
    <div class="row">
        {{-- SIMULATOR INPUTS --}}
        <div class="col-lg-5">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-moon"></i>
                        {{ trans('mining-manager::moons.select_moon') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>{{ trans('mining-manager::moons.moon') }}</label>
                        <select class="form-control" id="moonSelect">
                            <option value="">{{ trans('mining-manager::moons.select_scanned_moon') }}</option>
                            @foreach($scannedMoons as $moon)
                                <option value="{{ $moon->moon_id }}">{{ $moon->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label>{{ trans('mining-manager::moons.extraction_duration') }}</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="extractionDays" value="14" min="6" max="56">
                            <div class="input-group-append">
                                <span class="input-group-text">{{ trans('mining-manager::moons.extraction_days') }}</span>
                            </div>
                        </div>
                        <small class="text-muted">EVE allows 6-56 days extraction cycles</small>
                    </div>

                    {{-- Duration Presets --}}
                    <div class="form-group">
                        <label>{{ trans('mining-manager::moons.duration_presets') }}</label>
                        <div class="btn-group btn-group-sm d-flex" role="group">
                            <button type="button" class="btn btn-outline-secondary duration-preset" data-days="6">6d</button>
                            <button type="button" class="btn btn-outline-secondary duration-preset active" data-days="14">14d</button>
                            <button type="button" class="btn btn-outline-secondary duration-preset" data-days="28">28d</button>
                            <button type="button" class="btn btn-outline-secondary duration-preset" data-days="56">56d</button>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="button" class="btn btn-primary btn-lg btn-block" id="simulateButton">
                            <i class="fas fa-flask"></i> {{ trans('mining-manager::moons.simulate') }}
                        </button>
                    </div>
                </div>
            </div>

            {{-- EXTRACTION INFO --}}
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i>
                        {{ trans('mining-manager::moons.extraction_rate') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>{{ trans('mining-manager::moons.extraction_rate') }}:</span>
                        <strong>21-31k {{ trans('mining-manager::moons.m3_per_hour') }}</strong>
                    </div>
                    <small class="text-muted d-block mb-3">{{ trans('mining-manager::moons.extraction_rate_note') }}</small>
                    <div class="small text-muted mb-3">
                        <i class="fas fa-flask"></i> Rate formula based on moon ore %:
                        <ul class="mb-0 mt-1">
                            <li>100% moon ore → ~31,000 m³/h</li>
                            <li>80% moon ore → ~27,000 m³/h</li>
                            <li>70% moon ore → ~21,000 m³/h</li>
                        </ul>
                    </div>
                    <hr class="bg-secondary">
                    <div class="d-flex justify-content-between mb-2">
                        <span>{{ trans('mining-manager::moons.scanned_moons_available') }}:</span>
                        <strong>{{ $scannedMoons->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>{{ trans('mining-manager::moons.price_source') }}:</span>
                        <strong class="text-info">{{ trans('mining-manager::moons.configured_provider') }}</strong>
                    </div>
                </div>
            </div>
        </div>

        {{-- RESULTS PANEL --}}
        <div class="col-lg-7">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::moons.simulation_results') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info" id="resultMoonName" style="display: none;"></span>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Loading State --}}
                    <div id="loadingState" style="display: none;">
                        <div class="text-center py-5">
                            <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                            <p class="mt-3 text-muted">{{ trans('mining-manager::moons.simulating') }}</p>
                        </div>
                    </div>

                    {{-- Empty State --}}
                    <div id="emptyState">
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-flask fa-3x mb-3"></i>
                            <h5>{{ trans('mining-manager::moons.no_simulation_yet') }}</h5>
                        </div>
                    </div>

                    {{-- Results State --}}
                    <div id="resultsState" style="display: none;">
                        {{-- Total Value --}}
                        <div class="mm-result-panel text-center p-4 mb-4" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 8px;">
                            <h5 class="text-muted mb-2">{{ trans('mining-manager::moons.total_value') }}</h5>
                            <div class="mm-result-value" id="totalValue" style="font-size: 2.5rem; font-weight: bold; color: #27ae60;">
                                0 <small style="font-size: 1rem;">ISK</small>
                            </div>
                            <p class="mb-0 mt-2">
                                <span id="resultDuration" class="badge badge-secondary"></span>
                                <span id="resultVolume" class="badge badge-info ml-1"></span>
                            </p>
                            <p class="mb-0 mt-2">
                                <span id="resultComposition" class="badge badge-success" title="{{ trans('mining-manager::moons.moon_ore_richness') }}"></span>
                                <span id="resultRate" class="badge badge-warning ml-1"></span>
                            </p>
                        </div>

                        {{-- Ore Breakdown Table --}}
                        <h6><i class="fas fa-gem"></i> {{ trans('mining-manager::moons.ore_breakdown') }}</h6>
                        <div class="table-responsive">
                            <table class="table table-dark table-striped table-sm" id="oreBreakdownTable">
                                <thead>
                                    <tr>
                                        <th>{{ trans('mining-manager::moons.ore_name') }}</th>
                                        <th class="text-right">%</th>
                                        <th class="text-right">{{ trans('mining-manager::moons.volume_m3') }}</th>
                                        <th class="text-right">{{ trans('mining-manager::moons.unit_price') }}</th>
                                        <th class="text-right">{{ trans('mining-manager::moons.ore_value') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="oreBreakdownBody">
                                </tbody>
                            </table>
                        </div>

                        {{-- Value Breakdown Chart --}}
                        <div class="mt-4" id="valueChartContainer">
                            <h6><i class="fas fa-chart-bar"></i> {{ trans('mining-manager::moons.breakdown') }}</h6>
                            <div id="valueBreakdownBars"></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- QUICK STATS --}}
            <div class="row" id="quickStatsRow" style="display: none;">
                <div class="col-md-4">
                    <div class="info-box bg-gradient-success">
                        <span class="info-box-icon"><i class="fas fa-gem"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::moons.most_valuable_ore') }}</span>
                            <span class="info-box-number" id="statMostValuable">-</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-box bg-gradient-info">
                        <span class="info-box-icon"><i class="fas fa-cubes"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::moons.total_ores') }}</span>
                            <span class="info-box-number" id="statTotalOres">0</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-box bg-gradient-warning">
                        <span class="info-box-icon"><i class="fas fa-star"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::moons.moon_quality') }}</span>
                            <span class="info-box-number" id="statQuality">-</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- EXPORT OPTIONS --}}
            <div class="card card-dark" id="exportCard" style="display: none;">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-download"></i>
                        {{ trans('mining-manager::moons.export') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <button class="btn btn-block btn-primary btn-sm" id="exportJSON">
                                <i class="fas fa-file-code"></i> {{ trans('mining-manager::moons.export_json') }}
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-block btn-success btn-sm" id="exportCSV">
                                <i class="fas fa-file-csv"></i> {{ trans('mining-manager::moons.export_csv') }}
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-block btn-info btn-sm" id="copyToClipboard">
                                <i class="fas fa-copy"></i> {{ trans('mining-manager::moons.copy_results') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @else
    {{-- NO SCANNED MOONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ trans('mining-manager::moons.no_scanned_moons') }}
                    </h3>
                </div>
                <div class="card-body text-center py-5">
                    <i class="fas fa-moon fa-4x text-muted mb-3"></i>
                    <h4>{{ trans('mining-manager::moons.no_scanned_moons') }}</h4>
                    <p class="text-muted">{{ trans('mining-manager::moons.no_scanned_moons_message') }}</p>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

@push('javascript')
<script>
let simulationResults = null;

$(document).ready(function() {
    // Duration preset buttons
    $('.duration-preset').on('click', function() {
        const days = $(this).data('days');
        $('#extractionDays').val(days);
        $('.duration-preset').removeClass('active');
        $(this).addClass('active');
    });

    // Keep preset buttons in sync with manual input
    $('#extractionDays').on('change', function() {
        const days = parseInt($(this).val());
        $('.duration-preset').removeClass('active');
        $(`.duration-preset[data-days="${days}"]`).addClass('active');
    });

    // Simulate button
    $('#simulateButton').on('click', runSimulation);

    // Export buttons
    $('#exportJSON').on('click', exportJSON);
    $('#exportCSV').on('click', exportCSV);
    $('#copyToClipboard').on('click', copyToClipboard);
});

function runSimulation() {
    const moonId = $('#moonSelect').val();
    const extractionDays = parseInt($('#extractionDays').val());

    if (!moonId) {
        toastr.warning('{{ trans("mining-manager::moons.select_scanned_moon") }}');
        return;
    }

    // Show loading state
    $('#emptyState').hide();
    $('#resultsState').hide();
    $('#loadingState').show();
    $('#quickStatsRow').hide();
    $('#exportCard').hide();

    $.ajax({
        url: '{{ route("mining-manager.moon.simulate") }}',
        method: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
            moon_id: moonId,
            extraction_days: extractionDays
        },
        success: function(response) {
            simulationResults = response;
            displayResults(response);
        },
        error: function(xhr) {
            $('#loadingState').hide();
            $('#emptyState').show();
            toastr.error(xhr.responseJSON?.error || 'Simulation failed');
        }
    });
}

function displayResults(data) {
    $('#loadingState').hide();
    $('#resultsState').show();
    $('#quickStatsRow').show();
    $('#exportCard').show();

    // Update moon name badge
    $('#resultMoonName').text(data.moon_name).show();

    // Update total value
    const totalValue = parseFloat(data.total_value) || 0;
    $('#totalValue').html(formatNumber(totalValue) + ' <small style="font-size: 1rem;">ISK</small>');

    // Update duration and volume badges
    $('#resultDuration').text(data.extraction_days + ' days');
    $('#resultVolume').text(formatNumber(data.total_volume_m3) + ' m³');

    // Update composition and rate badges (new dynamic values)
    $('#resultComposition').text(data.composition_percent + '% moon ore');
    $('#resultRate').text(formatNumber(data.extraction_rate_m3h) + ' m³/h');

    // Build ore breakdown table
    let tableHtml = '';
    let mostValuable = { name: '-', value: 0 };
    let valueBreakdownHtml = '';

    if (data.composition && data.composition.length > 0) {
        // Sort by value descending
        const sortedOres = [...data.composition].sort((a, b) => b.value - a.value);

        sortedOres.forEach((ore, index) => {
            const percentage = (ore.value / totalValue * 100) || 0;
            const barColor = getBarColor(index);

            tableHtml += `
                <tr>
                    <td><i class="fas fa-gem" style="color: ${barColor};"></i> ${ore.ore_name}</td>
                    <td class="text-right">${ore.percentage.toFixed(1)}%</td>
                    <td class="text-right">${formatNumber(ore.volume)}</td>
                    <td class="text-right">${formatNumber(ore.unit_price)} ISK</td>
                    <td class="text-right text-success">${formatNumber(ore.value)} ISK</td>
                </tr>
            `;

            // Value breakdown bars
            valueBreakdownHtml += `
                <div class="mb-2">
                    <div class="d-flex justify-content-between small">
                        <span>${ore.ore_name}</span>
                        <span>${percentage.toFixed(1)}%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar" style="width: ${percentage}%; background-color: ${barColor};"></div>
                    </div>
                </div>
            `;

            // Track most valuable
            if (ore.value > mostValuable.value) {
                mostValuable = { name: ore.ore_name, value: ore.value };
            }
        });
    }

    $('#oreBreakdownBody').html(tableHtml);
    $('#valueBreakdownBars').html(valueBreakdownHtml);

    // Update quick stats
    $('#statMostValuable').text(mostValuable.name);
    $('#statTotalOres').text(data.composition?.length || 0);
    $('#statQuality').text(getMoonQuality(totalValue, data.extraction_days));

    toastr.success('{{ trans("mining-manager::moons.simulation_complete") }}');
}

function formatNumber(num) {
    if (num === null || num === undefined) return '0';
    return parseFloat(num).toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function getBarColor(index) {
    const colors = ['#27ae60', '#3498db', '#f39c12', '#e74c3c', '#9b59b6', '#1abc9c', '#e67e22', '#95a5a6'];
    return colors[index % colors.length];
}

function getMoonQuality(value, days) {
    // Normalize to 14-day value for comparison
    const normalizedValue = value / days * 14;

    if (normalizedValue > 3000000000) return '{{ trans("mining-manager::moons.exceptional") }}';
    if (normalizedValue > 2000000000) return '{{ trans("mining-manager::moons.excellent") }}';
    if (normalizedValue > 1000000000) return '{{ trans("mining-manager::moons.good") }}';
    if (normalizedValue > 500000000) return '{{ trans("mining-manager::moons.average") }}';
    return '{{ trans("mining-manager::moons.poor") }}';
}

function exportJSON() {
    if (!simulationResults) return;

    const dataStr = JSON.stringify(simulationResults, null, 2);
    const dataBlob = new Blob([dataStr], { type: 'application/json' });
    const url = URL.createObjectURL(dataBlob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'moon-simulation-' + simulationResults.moon_name.replace(/[^a-z0-9]/gi, '_') + '.json';
    link.click();
}

function exportCSV() {
    if (!simulationResults) return;

    let csv = 'Ore Name,Percentage,Volume (m3),Unit Price (ISK),Total Value (ISK)\n';
    simulationResults.composition.forEach(ore => {
        csv += `"${ore.ore_name}",${ore.percentage},${ore.volume},${ore.unit_price},${ore.value}\n`;
    });
    csv += `\nTotal,,${simulationResults.total_volume_m3},,${simulationResults.total_value}\n`;
    csv += `Moon Name,${simulationResults.moon_name}\n`;
    csv += `Extraction Days,${simulationResults.extraction_days}\n`;

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'moon-simulation-' + simulationResults.moon_name.replace(/[^a-z0-9]/gi, '_') + '.csv';
    link.click();
}

function copyToClipboard() {
    if (!simulationResults) return;

    let text = 'Moon Extraction Simulation\n';
    text += '==========================\n';
    text += `Moon: ${simulationResults.moon_name}\n`;
    text += `Duration: ${simulationResults.extraction_days} days\n`;
    text += `Total Volume: ${formatNumber(simulationResults.total_volume_m3)} m³\n`;
    text += `Total Value: ${formatNumber(simulationResults.total_value)} ISK\n\n`;
    text += 'Ore Breakdown:\n';
    simulationResults.composition.forEach(ore => {
        text += `  ${ore.ore_name}: ${formatNumber(ore.value)} ISK (${ore.percentage.toFixed(1)}%)\n`;
    });

    navigator.clipboard.writeText(text).then(() => {
        toastr.success('{{ trans("mining-manager::moons.copied_to_clipboard") }}');
    });
}
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
