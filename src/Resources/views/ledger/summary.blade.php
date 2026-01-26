@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.mining_summary'))
@section('page_header', trans('mining-manager::ledger.mining_summary'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .expandable-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .expandable-row:hover {
        background-color: rgba(0,0,0,0.05);
    }
    .daily-breakdown-row {
        background-color: #f8f9fa;
    }
    .daily-breakdown-table {
        margin: 0;
    }
    .daily-breakdown-table th {
        font-size: 0.875rem;
        background-color: #e9ecef;
    }
    .daily-breakdown-table td {
        font-size: 0.875rem;
    }
    .loading-spinner {
        text-align: center;
        padding: 20px;
    }
    .expand-icon {
        transition: transform 0.3s;
    }
    .expand-icon.expanded {
        transform: rotate(90deg);
    }
    .character-portrait {
        width: 32px;
        height: 32px;
        border-radius: 4px;
        vertical-align: middle;
    }
</style>
@endpush

@section('full')

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/ledger/summary') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.ledger.summary') }}">
                <i class="fas fa-layer-group"></i> {{ trans('mining-manager::ledger.summary_view') }}
            </a>
        </li>
        <li class="{{ Request::is('*/ledger') && !Request::is('*/ledger/*') ? '' : '' }}">
            <a href="{{ route('mining-manager.ledger.index') }}">
                <i class="fas fa-list"></i> {{ trans('mining-manager::ledger.detailed_view') }}
            </a>
        </li>
        <li class="{{ Request::is('*/ledger/my-mining') ? '' : '' }}">
            <a href="{{ route('mining-manager.ledger.my-mining') }}">
                <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
            </a>
        </li>
        @can('mining-manager.ledger.process')
        <li class="{{ Request::is('*/ledger/process') ? '' : '' }}">
            <a href="{{ route('mining-manager.ledger.process') }}">
                <i class="fas fa-cogs"></i> {{ trans('mining-manager::menu.process_ledger') }}
            </a>
        </li>
        @endcan
    </ul>
    <div class="tab-content">

<div class="mining-manager-wrapper mining-ledger-summary">

    {{-- FILTERS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i>
                        {{ trans('mining-manager::ledger.filters') }}
                    </h3>
                </div>
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.ledger.summary') }}" id="filterForm">
                        <div class="row">
                            {{-- Month Selector --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="month">{{ trans('mining-manager::ledger.month') }}</label>
                                    <input type="month" class="form-control" id="month" name="month" value="{{ $month }}" required>
                                </div>
                            </div>

                            {{-- Corporation Filter --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="corporation_id">{{ trans('mining-manager::ledger.corporation') }}</label>
                                    <select class="form-control" id="corporation_id" name="corporation_id">
                                        <option value="">{{ trans('mining-manager::ledger.all_corporations') }}</option>
                                        @foreach($corporations as $corp)
                                            <option value="{{ $corp->corporation_id }}" {{ $selectedCorporationId == $corp->corporation_id ? 'selected' : '' }}>
                                                [{{ $corp->ticker }}] {{ $corp->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Apply Button --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="fas fa-search"></i> {{ trans('mining-manager::ledger.apply_filters') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-bar"></i>
                        {{ trans('mining-manager::ledger.summary_statistics') }} - {{ $monthDate->format('F Y') }}
                        @if(!$isCurrentMonth)
                            <span class="badge badge-success ml-2">
                                <i class="fas fa-check-circle"></i> Finalized
                            </span>
                        @else
                            <span class="badge badge-warning ml-2">
                                <i class="fas fa-clock"></i> Live
                            </span>
                        @endif
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Total Value --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon">
                                    <i class="fas fa-coins"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_value') }}</span>
                                    <span class="info-box-number">{{ number_format($totals['total_value'], 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Tax --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-warning">
                                <span class="info-box-icon">
                                    <i class="fas fa-percent"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_tax') }}</span>
                                    <span class="info-box-number">{{ number_format($totals['total_tax'], 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Quantity --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-cubes"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_quantity') }}</span>
                                    <span class="info-box-number">{{ number_format($totals['total_quantity'], 0) }}</span>
                                    <small>m³</small>
                                </div>
                            </div>
                        </div>

                        {{-- Active Miners --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-primary">
                                <span class="info-box-icon">
                                    <i class="fas fa-users"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.active_miners') }}</span>
                                    <span class="info-box-number">{{ $summaries->count() }}</span>
                                    <small>{{ trans('mining-manager::ledger.characters') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- CHARACTER SUMMARIES TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::ledger.character_summaries') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info">{{ $summaries->count() }} {{ trans('mining-manager::ledger.characters') }}</span>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-striped">
                        <thead>
                            <tr>
                                <th style="width: 30px;"></th>
                                <th>{{ trans('mining-manager::ledger.character') }}</th>
                                <th>{{ trans('mining-manager::ledger.corporation') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.total_value') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.total_tax') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.total_quantity') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.moon_ore') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.regular_ore') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.ice') }}</th>
                                <th class="text-right">{{ trans('mining-manager::ledger.gas') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($summaries as $summary)
                                <tr class="expandable-row" data-character-id="{{ $summary->character_id }}" data-month="{{ $month }}">
                                    <td>
                                        <i class="fas fa-chevron-right expand-icon"></i>
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $summary->character_id }}/portrait?size=32"
                                             class="character-portrait"
                                             alt="{{ $summary->character->name ?? 'Unknown' }}">
                                        {{ $summary->character->name ?? 'Unknown' }}
                                    </td>
                                    <td>
                                        @if(isset($summary->character->corporation))
                                            [{{ $summary->character->corporation->ticker ?? '' }}] {{ $summary->character->corporation->name ?? 'Unknown' }}
                                        @else
                                            Unknown
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($summary->total_value, 2) }} ISK</td>
                                    <td class="text-right">{{ number_format($summary->total_tax, 2) }} ISK</td>
                                    <td class="text-right">{{ number_format($summary->total_quantity, 2) }} m³</td>
                                    <td class="text-right">{{ number_format($summary->moon_ore_value, 2) }} ISK</td>
                                    <td class="text-right">{{ number_format($summary->regular_ore_value, 2) }} ISK</td>
                                    <td class="text-right">{{ number_format($summary->ice_value, 2) }} ISK</td>
                                    <td class="text-right">{{ number_format($summary->gas_value, 2) }} ISK</td>
                                </tr>
                                <tr class="daily-breakdown-row" id="daily-breakdown-{{ $summary->character_id }}" style="display: none;">
                                    <td colspan="10">
                                        <div class="loading-spinner">
                                            <i class="fas fa-spinner fa-spin"></i> {{ trans('mining-manager::ledger.loading_daily_breakdown') }}
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center">
                                        <em>{{ trans('mining-manager::ledger.no_mining_data') }}</em>
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
</div>

@endsection

@push('javascript')
<script>
$(document).ready(function() {
    // Track expanded rows
    const expandedRows = new Set();

    // Handle expandable row clicks
    $('.expandable-row').on('click', function() {
        const characterId = $(this).data('character-id');
        const month = $(this).data('month');
        const breakdownRow = $('#daily-breakdown-' + characterId);
        const expandIcon = $(this).find('.expand-icon');

        // Toggle expansion
        if (expandedRows.has(characterId)) {
            // Collapse
            breakdownRow.hide();
            expandIcon.removeClass('expanded');
            expandedRows.delete(characterId);
        } else {
            // Expand
            breakdownRow.show();
            expandIcon.addClass('expanded');
            expandedRows.add(characterId);

            // Load daily data if not already loaded
            if (!breakdownRow.data('loaded')) {
                loadDailyBreakdown(characterId, month, breakdownRow);
            }
        }
    });

    function loadDailyBreakdown(characterId, month, targetRow) {
        $.ajax({
            url: '{{ route("mining-manager.ledger.character-daily", ":characterId") }}'.replace(':characterId', characterId),
            method: 'GET',
            data: { month: month },
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                if (response.success && response.data) {
                    renderDailyBreakdown(response.data, targetRow);
                    targetRow.data('loaded', true);
                } else {
                    targetRow.html('<td colspan="10" class="text-center text-danger">' +
                        '<i class="fas fa-exclamation-triangle"></i> {{ trans("mining-manager::ledger.failed_to_load") }}' +
                        '</td>');
                }
            },
            error: function(xhr) {
                targetRow.html('<td colspan="10" class="text-center text-danger">' +
                    '<i class="fas fa-exclamation-triangle"></i> {{ trans("mining-manager::ledger.error_loading_data") }}: ' +
                    (xhr.responseJSON?.message || '{{ trans("mining-manager::ledger.unknown_error") }}') +
                    '</td>');
            }
        });
    }

    function renderDailyBreakdown(dailyData, targetRow) {
        let html = '<td colspan="10" style="padding: 0;">' +
            '<table class="table table-sm daily-breakdown-table mb-0">' +
            '<thead>' +
            '<tr>' +
            '<th>{{ trans("mining-manager::ledger.date") }}</th>' +
            '<th class="text-right">{{ trans("mining-manager::ledger.value") }}</th>' +
            '<th class="text-right">{{ trans("mining-manager::ledger.tax") }}</th>' +
            '<th class="text-right">{{ trans("mining-manager::ledger.quantity") }}</th>' +
            '<th class="text-right">{{ trans("mining-manager::ledger.moon_ore") }}</th>' +
            '<th class="text-right">{{ trans("mining-manager::ledger.regular_ore") }}</th>' +
            '<th class="text-right">{{ trans("mining-manager::ledger.ice") }}</th>' +
            '<th class="text-right">{{ trans("mining-manager::ledger.gas") }}</th>' +
            '<th>{{ trans("mining-manager::ledger.ore_types") }}</th>' +
            '</tr>' +
            '</thead>' +
            '<tbody>';

        if (dailyData.length === 0) {
            html += '<tr><td colspan="9" class="text-center"><em>{{ trans("mining-manager::ledger.no_data") }}</em></td></tr>';
        } else {
            dailyData.forEach(function(day) {
                const oreTypes = day.ore_types && day.ore_types.length > 0
                    ? day.ore_types.join(', ')
                    : '-';

                html += '<tr>' +
                    '<td>' + day.date + '</td>' +
                    '<td class="text-right">' + day.total_value + ' ISK</td>' +
                    '<td class="text-right">' + day.total_tax + ' ISK</td>' +
                    '<td class="text-right">' + day.total_quantity + ' m³</td>' +
                    '<td class="text-right">' + day.moon_ore_value + ' ISK</td>' +
                    '<td class="text-right">' + day.regular_ore_value + ' ISK</td>' +
                    '<td class="text-right">' + day.ice_value + ' ISK</td>' +
                    '<td class="text-right">' + day.gas_value + ' ISK</td>' +
                    '<td>' + oreTypes + '</td>' +
                    '</tr>';
            });
        }

        html += '</tbody></table></td>';
        targetRow.html(html);
    }
});
</script>
@endpush
