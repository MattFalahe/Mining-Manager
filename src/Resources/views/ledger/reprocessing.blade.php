@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.reprocessing_calculator'))
@section('page_header', trans('mining-manager::ledger.mining_ledger'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
@endpush

@section('full')
<div class="mining-dashboard">
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
                <a class="nav-link active" href="{{ route('mining-manager.ledger.reprocessing') }}">
                    <i class="fas fa-recycle"></i> {{ trans('mining-manager::ledger.reprocessing_calculator') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">

<div class="mining-manager-wrapper reprocessing-calculator">
    <div class="row">
        {{-- LEFT SIDE: Settings --}}
        <div class="col-md-4">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-cogs"></i>
                        {{ trans('mining-manager::ledger.reprocessing_calculator') }}
                    </h3>
                </div>
                <div class="card-body">
                    {{-- Yield Input --}}
                    <div class="form-group">
                        <label for="yieldPercent">{{ trans('mining-manager::ledger.reprocessing_yield') }}</label>
                        <input type="number" class="form-control" id="yieldPercent"
                               value="72.36" min="50" max="100" step="0.01">
                    </div>

                    {{-- Ore Textarea --}}
                    <div class="form-group">
                        <label for="oreInput">{{ trans('mining-manager::ledger.paste_ores') }}</label>
                        <textarea class="form-control" id="oreInput" rows="12"
                                  placeholder="Veldspar&#9;10000&#10;56,325 Scordite&#10;Pyroxeres 5000"></textarea>
                        <small class="form-text text-muted">
                            {{ trans('mining-manager::ledger.paste_help') }}
                        </small>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="form-group">
                        <button type="button" class="btn btn-success btn-block" id="btnCalculate">
                            <i class="fas fa-calculator"></i> {{ trans('mining-manager::ledger.calculate') }}
                        </button>
                        <button type="button" class="btn btn-secondary btn-block mt-2" id="btnClear">
                            <i class="fas fa-eraser"></i> {{ trans('mining-manager::ledger.clear_input') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT SIDE: Results --}}
        <div class="col-md-8">
            {{-- Summary Stats --}}
            <div class="row" id="summaryStats" style="display: none;">
                <div class="col-md-4">
                    <div class="info-box bg-gradient-info">
                        <span class="info-box-icon"><i class="fas fa-gem"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::ledger.total_ore_value') }}</span>
                            <span class="info-box-number" id="statOreValue">0</span>
                            <small>ISK</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-box bg-gradient-success">
                        <span class="info-box-icon"><i class="fas fa-coins"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::ledger.total_mineral_value') }}</span>
                            <span class="info-box-number" id="statMineralValue">0</span>
                            <small>ISK</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-box bg-gradient-warning">
                        <span class="info-box-icon"><i class="fas fa-cubes"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::ledger.total_items') }}</span>
                            <span class="info-box-number" id="statTotalItems">0</span>
                            <small>{{ trans('mining-manager::ledger.units') }}</small>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Minerals Output Table --}}
            <div class="card card-dark" id="mineralsCard" style="display: none;">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-flask"></i>
                        {{ trans('mining-manager::ledger.minerals_output') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover" id="mineralsTable">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::ledger.mineral_name') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.price_per_unit') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.total_value') }}</th>
                                </tr>
                            </thead>
                            <tbody id="mineralsBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Ore Input Breakdown Table --}}
            <div class="card card-dark" id="oresCard" style="display: none;">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-mountain"></i>
                        {{ trans('mining-manager::ledger.ore_input_breakdown') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover" id="oresTable">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::ledger.ore_type') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.quantity') }}</th>
                                    <th class="text-center">{{ trans('mining-manager::ledger.type') }}</th>
                                    <th>{{ trans('mining-manager::ledger.minerals_output') }}</th>
                                </tr>
                            </thead>
                            <tbody id="oresBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Empty State --}}
            <div class="card card-dark" id="emptyState">
                <div class="card-body text-center text-muted py-5">
                    <i class="fas fa-recycle fa-3x mb-3"></i>
                    <p>{{ trans('mining-manager::ledger.paste_help') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    var calculateUrl = '{{ route("mining-manager.ledger.reprocessing.calculate") }}';
    var csrfToken = $('meta[name="csrf-token"]').attr('content');

    function formatNumber(num) {
        return Number(num).toLocaleString('en-US');
    }

    function formatIsk(num) {
        return Number(num).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function parseOreInput(text) {
        var lines = text.trim().split('\n');
        var ores = [];

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            if (!line) continue;

            var typeName = null;
            var quantity = null;

            // Format 1: "Ore Name\tQuantity" (EVE clipboard with tab)
            if (line.indexOf('\t') !== -1) {
                var parts = line.split('\t');
                typeName = parts[0].trim();
                quantity = parseInt(parts[1].replace(/[,.\s]/g, ''), 10);
            }
            // Format 2: "56,325 Ore Name" (quantity first)
            else if (/^[\d,.\s]+\s+/.test(line)) {
                var match = line.match(/^([\d,.\s]+)\s+(.+)$/);
                if (match) {
                    quantity = parseInt(match[1].replace(/[,.\s]/g, ''), 10);
                    typeName = match[2].trim();
                }
            }
            // Format 3: "Ore Name 56325" (name first, space-separated number at end)
            else if (/\s+[\d,.\s]+$/.test(line)) {
                var match = line.match(/^(.+?)\s+([\d,.\s]+)$/);
                if (match) {
                    typeName = match[1].trim();
                    quantity = parseInt(match[2].replace(/[,.\s]/g, ''), 10);
                }
            }

            if (typeName && quantity && quantity > 0) {
                ores.push({type_name: typeName, quantity: quantity});
            }
        }

        return ores;
    }

    function getCategoryBadge(category) {
        var badges = {
            'moon': '<span class="badge badge-secondary"><i class="fas fa-moon"></i> Moon</span>',
            'regular': '<span class="badge badge-primary"><i class="fas fa-gem"></i> Regular</span>',
            'ice': '<span class="badge badge-info"><i class="fas fa-snowflake"></i> Ice</span>',
            'gas': '<span class="badge badge-warning"><i class="fas fa-cloud"></i> Gas</span>',
            'compressed moon': '<span class="badge badge-secondary"><i class="fas fa-compress-alt"></i> Compressed Moon</span>',
            'compressed regular': '<span class="badge badge-primary"><i class="fas fa-compress-alt"></i> Compressed Regular</span>',
            'compressed ice': '<span class="badge badge-info"><i class="fas fa-compress-alt"></i> Compressed Ice</span>',
            'compressed gas': '<span class="badge badge-warning"><i class="fas fa-compress-alt"></i> Compressed Gas</span>'
        };
        return badges[category] || '<span class="badge badge-primary"><i class="fas fa-gem"></i> ' + category.charAt(0).toUpperCase() + category.slice(1) + '</span>';
    }

    function renderResults(data) {
        // Show summary stats
        $('#statOreValue').text(formatIsk(data.summary.total_ore_value));
        $('#statMineralValue').text(formatIsk(data.summary.total_mineral_value));
        $('#statTotalItems').text(formatNumber(data.summary.total_items));
        $('#summaryStats').show();

        // Render minerals table
        var mineralsHtml = '';
        for (var i = 0; i < data.minerals.length; i++) {
            var m = data.minerals[i];
            mineralsHtml += '<tr>' +
                '<td><img src="https://images.evetech.net/types/' + m.type_id + '/icon?size=32" class="img-circle" style="width:24px;height:24px;margin-right:8px;">' + m.name + '</td>' +
                '<td class="text-right"><strong>' + formatNumber(m.quantity) + '</strong></td>' +
                '<td class="text-right">' + formatIsk(m.price_per_unit) + ' ISK</td>' +
                '<td class="text-right"><strong>' + formatIsk(m.total_value) + '</strong> <small class="text-muted">ISK</small></td>' +
                '</tr>';
        }
        $('#mineralsBody').html(mineralsHtml);
        $('#mineralsCard').show();

        // Render ores table
        var oresHtml = '';
        for (var j = 0; j < data.ores.length; j++) {
            var o = data.ores[j];
            var mineralSummary = '';
            if (o.minerals && o.minerals.length > 0) {
                var parts = [];
                for (var k = 0; k < o.minerals.length; k++) {
                    parts.push(formatNumber(o.minerals[k].quantity) + ' ' + o.minerals[k].name);
                }
                mineralSummary = parts.join(', ');
            } else {
                mineralSummary = '<span class="text-muted">No reprocessing data</span>';
            }
            oresHtml += '<tr>' +
                '<td><img src="https://images.evetech.net/types/' + o.type_id + '/icon?size=32" class="img-circle" style="width:24px;height:24px;margin-right:8px;">' + o.type_name + '</td>' +
                '<td class="text-right"><strong>' + formatNumber(o.quantity) + '</strong></td>' +
                '<td class="text-center">' + getCategoryBadge(o.category) + '</td>' +
                '<td><small>' + mineralSummary + '</small></td>' +
                '</tr>';
        }
        $('#oresBody').html(oresHtml);
        $('#oresCard').show();

        // Hide empty state
        $('#emptyState').hide();
    }

    $('#btnCalculate').on('click', function() {
        var ores = parseOreInput($('#oreInput').val());

        if (ores.length === 0) {
            toastr.error('{{ trans("mining-manager::ledger.no_valid_ores") }}');
            return;
        }

        var yieldPercent = parseFloat($('#yieldPercent').val());
        if (isNaN(yieldPercent) || yieldPercent < 50 || yieldPercent > 100) {
            yieldPercent = 72.36;
            $('#yieldPercent').val(yieldPercent);
        }

        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::ledger.loading") }}');

        $.ajax({
            url: calculateUrl,
            method: 'POST',
            contentType: 'application/json',
            headers: {
                'X-CSRF-TOKEN': csrfToken
            },
            data: JSON.stringify({
                ores: ores,
                yield_percent: yieldPercent
            }),
            success: function(response) {
                if (response.success) {
                    renderResults(response);
                } else {
                    toastr.error(response.message || '{{ trans("mining-manager::ledger.unknown_error") }}');
                }
            },
            error: function(xhr) {
                var msg = xhr.responseJSON ? xhr.responseJSON.message : '{{ trans("mining-manager::ledger.unknown_error") }}';
                toastr.error(msg);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fas fa-calculator"></i> {{ trans("mining-manager::ledger.calculate") }}');
            }
        });
    });

    $('#btnClear').on('click', function() {
        $('#oreInput').val('');
        $('#summaryStats').hide();
        $('#mineralsCard').hide();
        $('#oresCard').hide();
        $('#emptyState').show();
    });
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}
</div>{{-- /.mining-dashboard --}}

@endsection
