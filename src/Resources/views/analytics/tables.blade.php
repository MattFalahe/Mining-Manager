@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::analytics.data_tables'))
@section('page_header', trans('mining-manager::menu.analytics'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/vendor/dataTables.bootstrap4.min.css') }}">
<style>
    .table-container {
        background: #343a40;
        border-radius: 8px;
        padding: 15px;
    }
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
        background: #454d55;
        color: #fff;
        border: 1px solid #6c757d;
    }
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        color: #fff;
    }
    .stat-badge {
        font-size: 0.85rem;
        padding: 0.35rem 0.65rem;
    }
</style>
@endpush

@section('full')


{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/analytics') && !Request::is('*/analytics/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.analytics.index') }}">
                <i class="fas fa-chart-area"></i> {{ trans('mining-manager::menu.analytics_overview') }}
            </a>
        </li>
        <li class="{{ Request::is('*/analytics/charts') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.analytics.charts') }}">
                <i class="fas fa-chart-line"></i> {{ trans('mining-manager::menu.performance_charts') }}
            </a>
        </li>
        <li class="{{ Request::is('*/analytics/tables') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.analytics.tables') }}">
                <i class="fas fa-table"></i> {{ trans('mining-manager::menu.data_tables') }}
            </a>
        </li>
        <li class="{{ Request::is('*/analytics/compare') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.analytics.compare') }}">
                <i class="fas fa-balance-scale"></i> {{ trans('mining-manager::menu.comparative_analysis') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">


<div class="analytics-tables">
    
    {{-- DATE RANGE FILTER --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.analytics.tables') }}" class="form-inline">
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
                        <a href="{{ route('mining-manager.analytics.charts') }}" class="btn btn-warning">
                            <i class="fas fa-chart-line"></i> {{ trans('mining-manager::analytics.charts') }}
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- MINER STATISTICS TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::analytics.miner_statistics') }}
                    </h3>
                    <div class="card-tools">
                        <span class="stat-badge badge badge-info">
                            {{ count($tableData['miner_stats'] ?? []) }} {{ trans('mining-manager::analytics.miners') }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table id="minerStatsTable" class="table table-dark table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::analytics.rank') }}</th>
                                    <th>{{ trans('mining-manager::analytics.character') }}</th>
                                    <th>{{ trans('mining-manager::analytics.corporation') }}</th>
                                    <th>{{ trans('mining-manager::analytics.volume') }}</th>
                                    <th>{{ trans('mining-manager::analytics.value') }}</th>
                                    <th>{{ trans('mining-manager::analytics.sessions') }}</th>
                                    <th>{{ trans('mining-manager::analytics.avg_session') }}</th>
                                    <th>{{ trans('mining-manager::analytics.last_activity') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($tableData['miner_stats'] ?? [] as $index => $miner)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $miner->character_id }}/portrait?size=32" 
                                             class="img-circle" 
                                             style="width: 32px;">
                                        {{ $miner->character_name }}
                                    </td>
                                    <td>{{ $miner->corporation_name ?? 'N/A' }}</td>
                                    <td data-order="{{ $miner->total_volume ?? 0 }}">{{ number_format($miner->total_volume ?? 0, 0) }} m³</td>
                                    <td data-order="{{ $miner->total_value ?? 0 }}">
                                        <span class="text-success">{{ number_format(($miner->total_value ?? 0) / 1000000, 2) }}M ISK</span>
                                    </td>
                                    <td>{{ $miner->session_count ?? 0 }}</td>
                                    <td data-order="{{ ($miner->session_count ?? 0) > 0 ? (($miner->total_value ?? 0) / ($miner->session_count ?? 1)) : 0 }}">
                                        {{ number_format(($miner->session_count ?? 0) > 0 ? (($miner->total_value ?? 0) / ($miner->session_count ?? 1)) / 1000000 : 0, 2) }}M ISK
                                    </td>
                                    <td data-order="{{ $miner->last_activity ? $miner->last_activity->timestamp : 0 }}">
                                        {{ $miner->last_activity ? $miner->last_activity->diffForHumans() : 'N/A' }}
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

    {{-- ORE STATISTICS TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::analytics.ore_statistics') }}
                    </h3>
                    <div class="card-tools">
                        <span class="stat-badge badge badge-info">
                            {{ count($tableData['ore_stats'] ?? []) }} {{ trans('mining-manager::analytics.ore_types') }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table id="oreStatsTable" class="table table-dark table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::analytics.ore_type') }}</th>
                                    <th>{{ trans('mining-manager::analytics.total_quantity') }}</th>
                                    <th>{{ trans('mining-manager::analytics.total_volume') }}</th>
                                    <th>{{ trans('mining-manager::analytics.total_value') }}</th>
                                    <th>{{ trans('mining-manager::analytics.avg_price') }}</th>
                                    <th>{{ trans('mining-manager::analytics.percentage') }}</th>
                                    <th>{{ trans('mining-manager::analytics.unique_miners') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $totalValue = collect($tableData['ore_stats'] ?? [])->sum('total_value');
                                @endphp
                                @foreach($tableData['ore_stats'] ?? [] as $ore)
                                <tr>
                                    <td>
                                        <i class="fas fa-cube text-info"></i>
                                        {{ $ore->ore_name }}
                                    </td>
                                    <td data-order="{{ $ore->total_quantity ?? 0 }}">{{ number_format($ore->total_quantity ?? 0, 0) }}</td>
                                    <td data-order="{{ $ore->total_volume ?? 0 }}">{{ number_format($ore->total_volume ?? 0, 0) }} m³</td>
                                    <td data-order="{{ $ore->total_value ?? 0 }}">
                                        <span class="text-success">{{ number_format(($ore->total_value ?? 0) / 1000000, 2) }}M ISK</span>
                                    </td>
                                    <td data-order="{{ ($ore->total_quantity ?? 0) > 0 ? (($ore->total_value ?? 0) / ($ore->total_quantity ?? 1)) : 0 }}">
                                        {{ number_format(($ore->total_quantity ?? 0) > 0 ? (($ore->total_value ?? 0) / ($ore->total_quantity ?? 1)) : 0, 0) }} ISK
                                    </td>
                                    <td data-order="{{ $totalValue > 0 ? (($ore->total_value ?? 0) / $totalValue) * 100 : 0 }}">
                                        <div class="progress" style="height: 20px;">
                                            @php
                                                $percentage = $totalValue > 0 ? (($ore->total_value ?? 0) / $totalValue) * 100 : 0;
                                            @endphp
                                            <div class="progress-bar bg-success" style="width: {{ $percentage }}%">
                                                {{ number_format($percentage, 1) }}%
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $ore->unique_miners ?? 0 }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- SYSTEM STATISTICS TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-globe"></i>
                        {{ trans('mining-manager::analytics.system_statistics') }}
                    </h3>
                    <div class="card-tools">
                        <span class="stat-badge badge badge-info">
                            {{ count($tableData['system_stats'] ?? []) }} {{ trans('mining-manager::analytics.systems') }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table id="systemStatsTable" class="table table-dark table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::analytics.system') }}</th>
                                    <th>{{ trans('mining-manager::analytics.region') }}</th>
                                    <th>{{ trans('mining-manager::analytics.security') }}</th>
                                    <th>{{ trans('mining-manager::analytics.total_volume') }}</th>
                                    <th>{{ trans('mining-manager::analytics.total_value') }}</th>
                                    <th>{{ trans('mining-manager::analytics.unique_miners') }}</th>
                                    <th>{{ trans('mining-manager::analytics.avg_per_miner') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($tableData['system_stats'] ?? [] as $system)
                                <tr>
                                    <td>
                                        <i class="fas fa-map-marker-alt text-warning"></i>
                                        {{ $system->system_name }}
                                    </td>
                                    <td>{{ $system->region_name ?? 'N/A' }}</td>
                                    <td>
                                        @php
                                            $security = $system->security_status ?? 0;
                                            $secColor = $security >= 0.5 ? 'success' : ($security > 0 ? 'warning' : 'danger');
                                        @endphp
                                        <span class="badge badge-{{ $secColor }}">
                                            {{ number_format($security, 1) }}
                                        </span>
                                    </td>
                                    <td data-order="{{ $system->total_volume ?? 0 }}">{{ number_format($system->total_volume ?? 0, 0) }} m³</td>
                                    <td data-order="{{ $system->total_value ?? 0 }}">
                                        <span class="text-success">{{ number_format(($system->total_value ?? 0) / 1000000, 2) }}M ISK</span>
                                    </td>
                                    <td>{{ $system->unique_miners ?? 0 }}</td>
                                    <td data-order="{{ ($system->unique_miners ?? 0) > 0 ? (($system->total_value ?? 0) / ($system->unique_miners ?? 1)) : 0 }}">
                                        {{ number_format(($system->unique_miners ?? 0) > 0 ? (($system->total_value ?? 0) / ($system->unique_miners ?? 1)) / 1000000 : 0, 2) }}M ISK
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

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/jquery.dataTables.min.js') }}"></script>
<script>
$(document).ready(function() {
    // Initialize DataTables
    const tableOptions = {
        pageLength: 25,
        order: [[4, 'desc']], // Sort by value column
        language: {
            search: '{{ trans("mining-manager::analytics.search") }}:',
            lengthMenu: '{{ trans("mining-manager::analytics.show") }} _MENU_ {{ trans("mining-manager::analytics.entries") }}',
            info: '{{ trans("mining-manager::analytics.showing") }} _START_ {{ trans("mining-manager::analytics.to") }} _END_ {{ trans("mining-manager::analytics.of") }} _TOTAL_ {{ trans("mining-manager::analytics.entries") }}',
            paginate: {
                first: '{{ trans("mining-manager::analytics.first") }}',
                last: '{{ trans("mining-manager::analytics.last") }}',
                next: '{{ trans("mining-manager::analytics.next") }}',
                previous: '{{ trans("mining-manager::analytics.previous") }}'
            }
        }
    };
    
    $('#minerStatsTable').DataTable(tableOptions);
    $('#oreStatsTable').DataTable(tableOptions);
    $('#systemStatsTable').DataTable(tableOptions);
});

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

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

@endsection
