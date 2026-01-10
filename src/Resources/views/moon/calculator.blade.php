@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.moon_calculator'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .calculator-panel {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 10px;
        padding: 30px;
        color: white;
    }
    .ore-input-row {
        background: rgba(255, 255, 255, 0.1);
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 10px;
        border: 2px solid transparent;
        transition: all 0.3s;
    }
    .ore-input-row:hover {
        background: rgba(255, 255, 255, 0.15);
        border-color: rgba(255, 255, 255, 0.3);
    }
    .ore-select {
        background: rgba(0, 0, 0, 0.2);
        color: white;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }
    .ore-select option {
        background: #343a40;
        color: white;
    }
    .result-panel {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        border-radius: 10px;
        padding: 30px;
        color: white;
        text-align: center;
    }
    .result-value {
        font-size: 3rem;
        font-weight: bold;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
    }
    .preset-button {
        cursor: pointer;
        transition: all 0.3s;
    }
    .preset-button:hover {
        transform: scale(1.05);
    }
    .ore-category {
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 10px;
    }
    .ore-category.r64 { background: rgba(255, 215, 0, 0.1); border-left: 4px solid #FFD700; }
    .ore-category.r32 { background: rgba(192, 192, 192, 0.1); border-left: 4px solid #C0C0C0; }
    .ore-category.r16 { background: rgba(205, 127, 50, 0.1); border-left: 4px solid #CD7F32; }
    .ore-category.r8 { background: rgba(70, 130, 180, 0.1); border-left: 4px solid #4682B4; }
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


<div class="moon-calculator">
    
    {{-- CALCULATOR EXPLANATION --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="alert alert-info">
                <h5><i class="fas fa-calculator"></i> {{ trans('mining-manager::moons.calculator_title') }}</h5>
                <p class="mb-0">{{ trans('mining-manager::moons.calculator_description') }}</p>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- CALCULATOR INPUTS --}}
        <div class="col-lg-8">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::moons.ore_composition') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-sm btn-success" id="addOreRow">
                            <i class="fas fa-plus"></i> {{ trans('mining-manager::moons.add_ore') }}
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <form id="calculatorForm">
                        <div id="oreRows">
                            {{-- Initial ore row will be added by JS --}}
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label>{{ trans('mining-manager::moons.refining_efficiency') }}</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="refiningEfficiency" value="87.5" min="0" max="100" step="0.1">
                                    <div class="input-group-append">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <small class="text-muted">{{ trans('mining-manager::moons.efficiency_help') }}</small>
                            </div>
                            <div class="col-md-6">
                                <label>{{ trans('mining-manager::moons.pricing_source') }}</label>
                                <select class="form-control" id="pricingSource">
                                    <option value="jita_buy">{{ trans('mining-manager::moons.jita_buy') }}</option>
                                    <option value="jita_sell" selected>{{ trans('mining-manager::moons.jita_sell') }}</option>
                                    <option value="amarr_buy">{{ trans('mining-manager::moons.amarr_buy') }}</option>
                                    <option value="amarr_sell">{{ trans('mining-manager::moons.amarr_sell') }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-12 text-right">
                                <button type="button" class="btn btn-primary btn-lg" id="calculateButton">
                                    <i class="fas fa-calculator"></i> {{ trans('mining-manager::moons.calculate') }}
                                </button>
                                <button type="button" class="btn btn-secondary" id="clearButton">
                                    <i class="fas fa-times"></i> {{ trans('mining-manager::moons.clear') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            {{-- PRESET MOON TYPES --}}
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-star"></i>
                        {{ trans('mining-manager::moons.common_presets') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <button class="btn btn-block btn-warning preset-button" data-preset="r64-heavy">
                                <i class="fas fa-gem"></i> {{ trans('mining-manager::moons.r64_heavy') }}
                            </button>
                        </div>
                        <div class="col-md-4 mb-2">
                            <button class="btn btn-block btn-info preset-button" data-preset="balanced">
                                <i class="fas fa-balance-scale"></i> {{ trans('mining-manager::moons.balanced') }}
                            </button>
                        </div>
                        <div class="col-md-4 mb-2">
                            <button class="btn btn-block btn-secondary preset-button" data-preset="low-value">
                                <i class="fas fa-coins"></i> {{ trans('mining-manager::moons.low_value') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ORE REFERENCE --}}
            <div class="card card-secondary">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-book"></i>
                        {{ trans('mining-manager::moons.ore_reference') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="ore-category r64">
                                <h6><strong>R64 - {{ trans('mining-manager::moons.exceptional') }}</strong></h6>
                                <small>Xenotime, Monazite, Loparite, Ytterbite</small>
                            </div>
                            <div class="ore-category r32">
                                <h6><strong>R32 - {{ trans('mining-manager::moons.excellent') }}</strong></h6>
                                <small>Cinnabar, Pollucite, Titanite, Euxenite</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="ore-category r16">
                                <h6><strong>R16 - {{ trans('mining-manager::moons.good') }}</strong></h6>
                                <small>Otavite, Sperrylite, Vanadinite, Chromite</small>
                            </div>
                            <div class="ore-category r8">
                                <h6><strong>R8 - {{ trans('mining-manager::moons.standard') }}</strong></h6>
                                <small>Cobaltite, Carnotite, Zircon, Bitumens</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- RESULTS PANEL --}}
        <div class="col-lg-4">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::moons.calculation_results') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="result-panel">
                        <h5 class="mb-3">{{ trans('mining-manager::moons.total_value') }}</h5>
                        <div class="result-value" id="totalValue">
                            0 <small>ISK</small>
                        </div>
                        <p class="mt-3 mb-0">{{ trans('mining-manager::moons.per_extraction') }}</p>
                    </div>

                    <div class="mt-3">
                        <h6>{{ trans('mining-manager::moons.breakdown') }}</h6>
                        <div id="resultBreakdown">
                            <p class="text-muted text-center py-3">
                                {{ trans('mining-manager::moons.no_calculation_yet') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- QUICK STATS --}}
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i>
                        {{ trans('mining-manager::moons.quick_stats') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <small class="text-muted">{{ trans('mining-manager::moons.most_valuable_ore') }}</small>
                        <h5 id="mostValuableOre" class="text-success">-</h5>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted">{{ trans('mining-manager::moons.total_ores') }}</small>
                        <h5 id="totalOres">0</h5>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted">{{ trans('mining-manager::moons.average_ore_value') }}</small>
                        <h5 id="avgOreValue" class="text-info">0 ISK</h5>
                    </div>
                    <div>
                        <small class="text-muted">{{ trans('mining-manager::moons.moon_quality') }}</small>
                        <h5 id="moonQuality" class="text-warning">-</h5>
                    </div>
                </div>
            </div>

            {{-- EXPORT OPTIONS --}}
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-download"></i>
                        {{ trans('mining-manager::moons.export') }}
                    </h3>
                </div>
                <div class="card-body">
                    <button class="btn btn-block btn-primary" id="exportJSON">
                        <i class="fas fa-file-code"></i> {{ trans('mining-manager::moons.export_json') }}
                    </button>
                    <button class="btn btn-block btn-success" id="exportCSV">
                        <i class="fas fa-file-csv"></i> {{ trans('mining-manager::moons.export_csv') }}
                    </button>
                    <button class="btn btn-block btn-info" id="copyToClipboard">
                        <i class="fas fa-copy"></i> {{ trans('mining-manager::moons.copy_results') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script>
// Moon ore database with sample prices (these should be fetched from your pricing service)
const oreDatabase = {
    // R64 - Exceptional
    'Xenotime': { rarity: 'R64', price: 150000, m3: 8 },
    'Monazite': { rarity: 'R64', price: 145000, m3: 8 },
    'Loparite': { rarity: 'R64', price: 140000, m3: 8 },
    'Ytterbite': { rarity: 'R64', price: 138000, m3: 8 },
    
    // R32 - Excellent
    'Cinnabar': { rarity: 'R32', price: 85000, m3: 16 },
    'Pollucite': { rarity: 'R32', price: 82000, m3: 16 },
    'Titanite': { rarity: 'R32', price: 80000, m3: 16 },
    'Euxenite': { rarity: 'R32', price: 78000, m3: 16 },
    
    // R16 - Good
    'Otavite': { rarity: 'R16', price: 45000, m3: 32 },
    'Sperrylite': { rarity: 'R16', price: 43000, m3: 32 },
    'Vanadinite': { rarity: 'R16', price: 42000, m3: 32 },
    'Chromite': { rarity: 'R16', price: 40000, m3: 32 },
    
    // R8 - Standard
    'Cobaltite': { rarity: 'R8', price: 22000, m3: 64 },
    'Carnotite': { rarity: 'R8', price: 21000, m3: 64 },
    'Zircon': { rarity: 'R8', price: 20000, m3: 64 },
    'Bitumens': { rarity: 'R8', price: 19000, m3: 64 },
};

const presets = {
    'r64-heavy': [
        { ore: 'Xenotime', quantity: 35000 },
        { ore: 'Monazite', quantity: 25000 },
        { ore: 'Cinnabar', quantity: 20000 },
        { ore: 'Otavite', quantity: 20000 }
    ],
    'balanced': [
        { ore: 'Titanite', quantity: 30000 },
        { ore: 'Otavite', quantity: 25000 },
        { ore: 'Chromite', quantity: 25000 },
        { ore: 'Cobaltite', quantity: 20000 }
    ],
    'low-value': [
        { ore: 'Cobaltite', quantity: 40000 },
        { ore: 'Carnotite', quantity: 30000 },
        { ore: 'Zircon', quantity: 20000 },
        { ore: 'Bitumens', quantity: 10000 }
    ]
};

let oreRowCounter = 0;
let calculationResults = [];

$(document).ready(function() {
    // Add initial ore row
    addOreRow();
    
    // Event listeners
    $('#addOreRow').on('click', addOreRow);
    $('#calculateButton').on('click', calculateValue);
    $('#clearButton').on('click', clearCalculator);
    $('#exportJSON').on('click', exportJSON);
    $('#exportCSV').on('click', exportCSV);
    $('#copyToClipboard').on('click', copyToClipboard);
    
    $('.preset-button').on('click', function() {
        loadPreset($(this).data('preset'));
    });
});

function addOreRow() {
    const rowId = 'ore-row-' + oreRowCounter++;
    const oreOptions = Object.keys(oreDatabase).map(ore => 
        `<option value="${ore}">${ore} (${oreDatabase[ore].rarity})</option>`
    ).join('');
    
    const row = `
        <div class="ore-input-row" id="${rowId}">
            <div class="row">
                <div class="col-md-5">
                    <label>{{ trans('mining-manager::moons.ore_type') }}</label>
                    <select class="form-control ore-select ore-type">
                        <option value="">{{ trans('mining-manager::moons.select_ore') }}</option>
                        ${oreOptions}
                    </select>
                </div>
                <div class="col-md-4">
                    <label>{{ trans('mining-manager::moons.quantity') }}</label>
                    <input type="number" class="form-control ore-quantity" placeholder="0" min="0">
                </div>
                <div class="col-md-2">
                    <label>{{ trans('mining-manager::moons.percentage') }}</label>
                    <input type="number" class="form-control ore-percentage" placeholder="0" min="0" max="100" step="0.01">
                </div>
                <div class="col-md-1">
                    <label>&nbsp;</label>
                    <button type="button" class="btn btn-danger btn-block remove-ore" data-row="${rowId}">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    
    $('#oreRows').append(row);
    
    // Remove button handler
    $(`.remove-ore[data-row="${rowId}"]`).on('click', function() {
        $(`#${rowId}`).remove();
    });
}

function calculateValue() {
    calculationResults = [];
    let totalValue = 0;
    let totalOres = 0;
    let mostValuable = { ore: '-', value: 0 };
    
    const efficiency = parseFloat($('#refiningEfficiency').val()) / 100;
    
    $('.ore-input-row').each(function() {
        const oreType = $(this).find('.ore-type').val();
        const quantity = parseFloat($(this).find('.ore-quantity').val()) || 0;
        const percentage = parseFloat($(this).find('.ore-percentage').val()) || 0;
        
        if (oreType && (quantity > 0 || percentage > 0)) {
            const oreData = oreDatabase[oreType];
            const actualQuantity = quantity || (percentage * 100000); // Assume 100k m3 chunk if using percentage
            const refinedQuantity = actualQuantity * efficiency;
            const value = refinedQuantity * oreData.price;
            
            totalValue += value;
            totalOres++;
            
            calculationResults.push({
                ore: oreType,
                quantity: actualQuantity,
                value: value,
                percentage: percentage || (quantity / 100000 * 100)
            });
            
            if (value > mostValuable.value) {
                mostValuable = { ore: oreType, value: value };
            }
        }
    });
    
    // Update display
    $('#totalValue').html(totalValue.toLocaleString() + ' <small>ISK</small>');
    $('#totalOres').text(totalOres);
    $('#mostValuableOre').text(mostValuable.ore);
    $('#avgOreValue').text((totalOres > 0 ? (totalValue / totalOres).toLocaleString() : 0) + ' ISK');
    
    // Determine moon quality
    let quality = '{{ trans("mining-manager::moons.poor") }}';
    if (totalValue > 5000000000) quality = '{{ trans("mining-manager::moons.exceptional") }}';
    else if (totalValue > 3000000000) quality = '{{ trans("mining-manager::moons.excellent") }}';
    else if (totalValue > 2000000000) quality = '{{ trans("mining-manager::moons.good") }}';
    else if (totalValue > 1000000000) quality = '{{ trans("mining-manager::moons.average") }}';
    $('#moonQuality').text(quality);
    
    // Update breakdown
    let breakdownHTML = '';
    calculationResults.sort((a, b) => b.value - a.value).forEach(result => {
        breakdownHTML += `
            <div class="d-flex justify-content-between mb-2">
                <span>${result.ore}</span>
                <span class="text-success">${result.value.toLocaleString()} ISK</span>
            </div>
            <div class="progress mb-2" style="height: 5px;">
                <div class="progress-bar bg-success" style="width: ${(result.value / totalValue * 100)}%"></div>
            </div>
        `;
    });
    $('#resultBreakdown').html(breakdownHTML);
    
    toastr.success('{{ trans("mining-manager::moons.calculation_complete") }}');
}

function clearCalculator() {
    $('#oreRows').empty();
    oreRowCounter = 0;
    addOreRow();
    $('#totalValue').html('0 <small>ISK</small>');
    $('#resultBreakdown').html('<p class="text-muted text-center py-3">{{ trans("mining-manager::moons.no_calculation_yet") }}</p>');
    $('#mostValuableOre').text('-');
    $('#totalOres').text('0');
    $('#avgOreValue').text('0 ISK');
    $('#moonQuality').text('-');
    calculationResults = [];
}

function loadPreset(presetName) {
    clearCalculator();
    $('#oreRows').empty();
    
    const preset = presets[presetName];
    preset.forEach(item => {
        oreRowCounter++;
        const rowId = 'ore-row-' + (oreRowCounter - 1);
        addOreRow();
        $(`#${rowId} .ore-type`).val(item.ore);
        $(`#${rowId} .ore-quantity`).val(item.quantity);
    });
    
    toastr.info('{{ trans("mining-manager::moons.preset_loaded") }}');
}

function exportJSON() {
    const data = {
        calculation: calculationResults,
        total_value: $('#totalValue').text().replace(/[^\d]/g, ''),
        efficiency: $('#refiningEfficiency').val(),
        pricing_source: $('#pricingSource').val()
    };
    
    const dataStr = JSON.stringify(data, null, 2);
    const dataBlob = new Blob([dataStr], { type: 'application/json' });
    const url = URL.createObjectURL(dataBlob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'moon-calculation.json';
    link.click();
}

function exportCSV() {
    let csv = 'Ore,Quantity,Value,Percentage\n';
    calculationResults.forEach(result => {
        csv += `${result.ore},${result.quantity},${result.value},${result.percentage}\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'moon-calculation.csv';
    link.click();
}

function copyToClipboard() {
    let text = 'Moon Calculation Results\n';
    text += '========================\n';
    text += `Total Value: ${$('#totalValue').text()}\n`;
    text += `Moon Quality: ${$('#moonQuality').text()}\n\n`;
    text += 'Breakdown:\n';
    calculationResults.forEach(result => {
        text += `${result.ore}: ${result.value.toLocaleString()} ISK (${result.percentage.toFixed(2)}%)\n`;
    });
    
    navigator.clipboard.writeText(text).then(() => {
        toastr.success('{{ trans("mining-manager::moons.copied_to_clipboard") }}');
    });
}
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

@endsection
