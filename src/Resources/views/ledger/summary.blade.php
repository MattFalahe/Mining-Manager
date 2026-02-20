@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.mining_summary'))
@section('page_header', trans('mining-manager::ledger.mining_summary'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .character-portrait {
        width: 32px;
        height: 32px;
        border-radius: 4px;
        vertical-align: middle;
        margin-right: 8px;
    }
    .ore-icon {
        width: 24px;
        height: 24px;
        margin: 0 2px;
        border-radius: 2px;
        vertical-align: middle;
    }
    .alt-badge {
        background-color: #17a2b8;
        color: white;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75rem;
        margin-left: 8px;
    }
    .system-badge {
        background-color: #6c757d;
        color: white;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75rem;
        margin-left: 4px;
    }
    .loading-spinner {
        text-align: center;
        padding: 20px;
    }
    .ore-type-section {
        margin: 15px 0;
    }
    .ore-category {
        display: inline-block;
        margin: 5px 10px 5px 0;
    }
    .ore-category-label {
        font-weight: bold;
        margin-right: 5px;
    }
    .system-link {
        color: #007bff;
        cursor: pointer;
        text-decoration: underline;
    }
    .system-link:hover {
        color: #0056b3;
    }
    .details-table {
        margin: 0;
        font-size: 0.875rem;
    }
    .details-table th {
        background-color: #e9ecef;
    }
    .sortable {
        cursor: pointer;
        user-select: none;
    }
    .sortable:hover {
        background-color: rgba(0,0,0,0.05);
    }
    .sort-icon {
        margin-left: 5px;
        font-size: 0.8em;
        opacity: 0.5;
    }
    .sort-icon.active {
        opacity: 1;
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-ledger-summary">

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ (Request::is('*/ledger') && !Request::is('*/ledger/*')) || Request::is('*/ledger/summary') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.ledger.index') }}">
                <i class="fas fa-layer-group"></i> {{ trans('mining-manager::ledger.mining_summary') }}
            </a>
        </li>
        <li class="{{ Request::is('*/ledger/my-mining') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.ledger.my-mining') }}">
                <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">

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
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="month">{{ trans('mining-manager::ledger.month') }}</label>
                                    <input type="month" class="form-control" id="month" name="month" value="{{ $month }}" required>
                                </div>
                            </div>

                            {{-- Corporation Filter --}}
                            <div class="col-md-3">
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

                            {{-- Group by Main Toggle --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="group_by_main">{{ trans('mining-manager::ledger.view_mode') }}</label>
                                    <select class="form-control" id="group_by_main" name="group_by_main">
                                        <option value="1" {{ $groupByMain ? 'selected' : '' }}>{{ trans('mining-manager::ledger.group_by_main') }}</option>
                                        <option value="0" {{ !$groupByMain ? 'selected' : '' }}>{{ trans('mining-manager::ledger.show_all_characters') }}</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Apply Button --}}
                            <div class="col-md-3">
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
                                <i class="fas fa-check-circle"></i> {{ trans('mining-manager::ledger.finalized') }}
                            </span>
                        @else
                            <span class="badge badge-warning ml-2">
                                <i class="fas fa-clock"></i> {{ trans('mining-manager::ledger.live') }}
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
                                    <small>{{ $groupByMain ? trans('mining-manager::ledger.mains') : trans('mining-manager::ledger.characters') }}</small>
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
                        <span class="badge badge-info">{{ $summaries->count() }} {{ $groupByMain ? trans('mining-manager::ledger.mains') : trans('mining-manager::ledger.characters') }}</span>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover table-striped" id="summaryTable">
                        <thead>
                            <tr>
                                <th class="sortable" onclick="sortTable('character_name')">
                                    {{ trans('mining-manager::ledger.character') }}
                                    <i class="fas fa-sort{{ $sortBy === 'character_name' ? ($sortDir === 'asc' ? '-up active' : '-down active') : '' }} sort-icon{{ $sortBy === 'character_name' ? ' active' : '' }}"></i>
                                </th>
                                <th>{{ trans('mining-manager::ledger.ore_types_mined') }}</th>
                                <th class="text-right sortable" onclick="sortTable('total_quantity')">
                                    {{ trans('mining-manager::ledger.total_quantity') }}
                                    <i class="fas fa-sort{{ $sortBy === 'total_quantity' ? ($sortDir === 'asc' ? '-up active' : '-down active') : '' }} sort-icon{{ $sortBy === 'total_quantity' ? ' active' : '' }}"></i>
                                </th>
                                <th class="text-right sortable" onclick="sortTable('total_value')">
                                    {{ trans('mining-manager::ledger.total_value') }}
                                    <i class="fas fa-sort{{ $sortBy === 'total_value' ? ($sortDir === 'asc' ? '-up active' : '-down active') : '' }} sort-icon{{ $sortBy === 'total_value' ? ' active' : '' }}"></i>
                                </th>
                                <th>{{ trans('mining-manager::ledger.primary_system') }}</th>
                                <th class="text-center" style="width: 120px;">{{ trans('mining-manager::ledger.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($summaries as $summary)
                                <tr>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $summary->character_id }}/portrait?size=32"
                                             class="character-portrait"
                                             alt="{{ $summary->character_info['name'] ?? ($summary->character->name ?? 'Unknown') }}">
                                        <strong>{{ $summary->character_info['name'] ?? ($summary->character->name ?? 'Unknown') }}</strong>

                                        {{-- Corporation Badge --}}
                                        @if(isset($summary->character_info['corporation_name']))
                                            <br>
                                            <span class="badge badge-secondary" style="font-size: 85%;">
                                                <img src="https://images.evetech.net/corporations/{{ $summary->character_info['corporation_id'] }}/logo?size=32"
                                                     style="width: 16px; height: 16px; vertical-align: middle; margin-right: 3px;"
                                                     alt="">
                                                {{ $summary->character_info['corporation_name'] }}
                                            </span>
                                        @endif

                                        {{-- Unregistered Badge --}}
                                        @if(isset($summary->character_info['is_registered']) && !$summary->character_info['is_registered'])
                                            <span class="badge badge-warning" style="font-size: 85%; margin-left: 5px;">
                                                <i class="fas fa-exclamation-triangle"></i> {{ trans('mining-manager::ledger.not_registered') }}
                                            </span>
                                        @endif

                                        {{-- Alt Count Badge --}}
                                        @if(isset($summary->alt_count) && $summary->alt_count > 0)
                                            <span class="alt-badge">+{{ $summary->alt_count }} {{ trans('mining-manager::ledger.alts') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(isset($summary->ore_type_ids) && count($summary->ore_type_ids) > 0)
                                            @foreach(array_slice($summary->ore_type_ids, 0, 10) as $typeId)
                                                <img src="https://images.evetech.net/types/{{ $typeId }}/icon?size=32"
                                                     class="ore-icon"
                                                     title="Type ID: {{ $typeId }}"
                                                     alt="Ore">
                                            @endforeach
                                            @if(count($summary->ore_type_ids) > 10)
                                                <span class="badge badge-secondary">+{{ count($summary->ore_type_ids) - 10 }}</span>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($summary->total_quantity, 0) }} m³</td>
                                    <td class="text-right"><strong>{{ number_format($summary->total_value, 0) }} ISK</strong></td>
                                    <td>
                                        @if(isset($summary->primary_system))
                                            {{ $summary->primary_system->solarSystem->name ?? 'Unknown' }}
                                            @if($summary->system_count > 1)
                                                <span class="system-badge">+{{ $summary->system_count - 1 }} {{ trans('mining-manager::ledger.more') }}</span>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <a href="{{ route('mining-manager.ledger.character-details', [
                                                'characterId' => $summary->character_id,
                                                'month' => $month,
                                                'include_alts' => $groupByMain ? '1' : '0'
                                            ]) }}"
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> {{ trans('mining-manager::ledger.view_details') }}
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center">
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
{{-- End tab-content --}}

</div>
{{-- End nav-tabs-custom --}}

</div>
{{-- End mining-manager-wrapper --}}

@endsection

@push('javascript')
<script>
function sortTable(column) {
    const currentSort = '{{ $sortBy }}';
    const currentDir = '{{ $sortDir }}';

    // Toggle direction if clicking the same column, otherwise default to desc
    let newDir = 'desc';
    if (column === currentSort) {
        newDir = currentDir === 'asc' ? 'desc' : 'asc';
    }

    // Get current URL parameters
    const url = new URL(window.location.href);
    url.searchParams.set('sort_by', column);
    url.searchParams.set('sort_dir', newDir);

    // Navigate to new URL
    window.location.href = url.toString();
}
</script>
@endpush
