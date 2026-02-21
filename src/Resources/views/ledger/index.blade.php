@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.mining_ledger'))
@section('page_header', trans_choice('mining-manager::ledger.mining_ledger', 2))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/ledger') && !Request::is('*/ledger/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.ledger.index') }}">
                <i class="fas fa-list"></i> {{ trans('mining-manager::menu.view_ledger') }}
            </a>
        </li>
        <li class="{{ Request::is('*/ledger/my-mining') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.ledger.my-mining') }}">
                <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">

<div class="mining-manager-wrapper mining-ledger">
    
    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-bar"></i>
                        {{ trans('mining-manager::ledger.summary_statistics') }} - {{ now()->format('F Y') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-sm btn-primary" id="refreshStats">
                            <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::ledger.refresh') }}
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Total Entries --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-clipboard-list"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_entries') }}</span>
                                    <span class="info-box-number">{{ number_format($summary['total_entries'] ?? 0) }}</span>
                                    <small>{{ trans('mining-manager::ledger.this_month') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Value --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon">
                                    <i class="fas fa-coins"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.total_value') }}</span>
                                    <span class="info-box-number">{{ number_format($summary['total_value'] ?? 0, 0) }}</span>
                                    <small>ISK</small>
                                </div>
                            </div>
                        </div>

                        {{-- Active Miners --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-warning">
                                <span class="info-box-icon">
                                    <i class="fas fa-users"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.active_miners') }}</span>
                                    <span class="info-box-number">{{ $summary['active_miners'] ?? 0 }}</span>
                                    <small>{{ trans('mining-manager::ledger.characters') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Top Ore Type --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-primary">
                                <span class="info-box-icon">
                                    <i class="fas fa-gem"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.top_ore_type') }}</span>
                                    <span class="info-box-number">{{ $summary['top_ore_type'] ?? 'N/A' }}</span>
                                    <small>{{ trans('mining-manager::ledger.most_mined') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- FILTERS AND ACTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i>
                        {{ trans('mining-manager::ledger.filters') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-sm btn-info" id="exportLedger">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::ledger.export') }}
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <form id="filterForm">
                        <div class="row">
                            {{-- Date Range --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="dateFrom">{{ trans('mining-manager::ledger.date_from') }}</label>
                                    <input type="date" class="form-control" id="dateFrom" name="date_from" value="{{ request('date_from', now()->startOfMonth()->format('Y-m-d')) }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="dateTo">{{ trans('mining-manager::ledger.date_to') }}</label>
                                    <input type="date" class="form-control" id="dateTo" name="date_to" value="{{ request('date_to', now()->format('Y-m-d')) }}">
                                </div>
                            </div>

                            {{-- Character Filter --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="characterFilter">{{ trans('mining-manager::ledger.character') }}</label>
                                    <select class="form-control" id="characterFilter" name="character_id">
                                        <option value="">{{ trans('mining-manager::ledger.all_characters') }}</option>
                                        @foreach($characters as $char)
                                        <option value="{{ $char['character_id'] }}" {{ request('character_id') == $char['character_id'] ? 'selected' : '' }}>
                                            {{ $char['name'] }}
                                            @if(!$char['is_registered'])
                                                (Not Registered)
                                            @endif
                                        </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Corporation Filter --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="corporationFilter">{{ trans('mining-manager::ledger.corporation') }}</label>
                                    <select class="form-control" id="corporationFilter" name="corporation_id">
                                        <option value="">{{ trans('mining-manager::ledger.all_corporations') }}</option>
                                        @foreach($corporations as $corp)
                                        <option value="{{ $corp->corporation_id }}" {{ request('corporation_id') == $corp->corporation_id ? 'selected' : '' }}>
                                            [{{ $corp->ticker }}] {{ $corp->name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Ore Type Filter --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="oreTypeFilter">{{ trans('mining-manager::ledger.ore_type') }}</label>
                                    <select class="form-control" id="oreTypeFilter" name="ore_type">
                                        <option value="">{{ trans('mining-manager::ledger.all_types') }}</option>
                                        <option value="ore" {{ request('ore_type') == 'ore' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.standard_ore') }}</option>
                                        <option value="ice" {{ request('ore_type') == 'ice' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.ice') }}</option>
                                        <option value="gas" {{ request('ore_type') == 'gas' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.gas') }}</option>
                                        <option value="moon" {{ request('ore_type') == 'moon' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.moon_ore') }}</option>
                                        <option value="abyssal" {{ request('ore_type') == 'abyssal' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.abyssal') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            {{-- System Filter --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="systemFilter">{{ trans('mining-manager::ledger.system') }}</label>
                                    <input type="text" class="form-control" id="systemFilter" name="system" placeholder="{{ trans('mining-manager::ledger.search_system') }}" value="{{ request('system') }}">
                                </div>
                            </div>

                            {{-- Sort By --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="sortBy">{{ trans('mining-manager::ledger.sort_by') }}</label>
                                    <select class="form-control" id="sortBy" name="sort_by">
                                        <option value="date_desc" {{ request('sort_by') == 'date_desc' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.date_newest') }}</option>
                                        <option value="date_asc" {{ request('sort_by') == 'date_asc' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.date_oldest') }}</option>
                                        <option value="value_desc" {{ request('sort_by') == 'value_desc' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.value_highest') }}</option>
                                        <option value="value_asc" {{ request('sort_by') == 'value_asc' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.value_lowest') }}</option>
                                        <option value="quantity_desc" {{ request('sort_by') == 'quantity_desc' ? 'selected' : '' }}>{{ trans('mining-manager::ledger.quantity_highest') }}</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Per Page --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="perPage">{{ trans('mining-manager::ledger.per_page') }}</label>
                                    <select class="form-control" id="perPage" name="per_page">
                                        <option value="25" {{ request('per_page') == 25 ? 'selected' : '' }}>25</option>
                                        <option value="50" {{ request('per_page') == 50 ? 'selected' : '' }}>50</option>
                                        <option value="100" {{ request('per_page') == 100 ? 'selected' : '' }}>100</option>
                                        <option value="250" {{ request('per_page') == 250 ? 'selected' : '' }}>250</option>
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

    {{-- LEDGER TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        {{ trans('mining-manager::ledger.ledger_entries') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info" id="resultCount">
                            {{ $ledgerEntries->total() }} {{ trans('mining-manager::ledger.entries') }}
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover" id="ledgerTable">
                            <thead>
                                <tr>
                                    <th style="width: 40px">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <th>{{ trans('mining-manager::ledger.date') }}</th>
                                    <th>{{ trans('mining-manager::ledger.character') }}</th>
                                    <th>{{ trans('mining-manager::ledger.ore_type') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.unit_price') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.total_value') }}</th>
                                    <th>{{ trans('mining-manager::ledger.system') }}</th>
                                    <th class="text-center">{{ trans('mining-manager::ledger.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($ledgerEntries as $entry)
                                <tr data-entry-id="{{ $entry->id }}">
                                    <td>
                                        <input type="checkbox" class="entry-checkbox" value="{{ $entry->id }}">
                                    </td>
                                    <td>
                                        <small>{{ $entry->date->format('Y-m-d H:i') }}</small>
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $entry->character_id }}/portrait?size=32" 
                                             class="img-circle" 
                                             style="width: 32px; height: 32px;"
                                             alt="{{ $entry->character_info['name'] ?? 'Character' }}">
                                        
                                        <strong>{{ $entry->character_info['name'] ?? ($entry->character->name ?? "Character {$entry->character_id}") }}</strong>
                                        
                                        @if(isset($entry->character_info))
                                            {{-- Show "Not Registered" badge for external characters --}}
                                            @if(!$entry->character_info['is_registered'])
                                                <span class="badge badge-warning"
                                                      title="{{ trans('mining-manager::ledger.not_registered') }}"
                                                      data-toggle="tooltip">
                                                    <i class="fas fa-exclamation-triangle"></i> {{ trans('mining-manager::ledger.not_registered') }}
                                                </span>
                                            @endif
                                            
                                            {{-- Show corporation name --}}
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-building"></i>
                                                {{ $entry->character_info['corporation_name'] }}
                                            </small>
                                        @elseif($entry->character)
                                            {{-- Fallback to relationship if character_info not available --}}
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-building"></i>
                                                {{ $entry->character->corporation->name ?? trans('mining-manager::ledger.unknown') }}
                                            </small>
                                        @endif
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/types/{{ $entry->type_id }}/icon?size=32" 
                                             class="img-circle" 
                                             style="width: 32px; height: 32px;">
                                        {{ $entry->type_name ?? trans('mining-manager::ledger.unknown') }}
                                        @if($entry->is_moon_ore)
                                            <span class="badge badge-secondary">
                                                <i class="fas fa-moon"></i>
                                            </span>
                                        @endif
                                        @if($entry->is_ice)
                                            <span class="badge badge-info">
                                                <i class="fas fa-snowflake"></i>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <strong>{{ number_format($entry->quantity, 0) }}</strong>
                                    </td>
                                    <td class="text-right">
                                        {{ number_format($entry->price_per_unit, 2) }} ISK
                                    </td>
                                    <td class="text-right">
                                        <strong>{{ number_format($entry->total_value, 0) }}</strong>
                                        <small class="text-muted">ISK</small>
                                    </td>
                                    <td>
                                        @if($entry->solar_system_name)
                                            <i class="fas fa-map-marker-alt"></i>
                                            <small>{{ $entry->solar_system_name }}</small>
                                        @elseif($entry->solarSystem)
                                            <i class="fas fa-map-marker-alt"></i>
                                            <small>{{ $entry->solarSystem->name }}</small>
                                        @else
                                            <small class="text-muted">{{ trans('mining-manager::ledger.unknown') }}</small>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <button type="button" 
                                                    class="btn btn-sm btn-info view-details" 
                                                    data-entry-id="{{ $entry->id }}"
                                                    data-toggle="tooltip" 
                                                    title="{{ trans('mining-manager::ledger.view_details') }}">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            @can('mining-manager.director')
                                            <button type="button" 
                                                    class="btn btn-sm btn-danger delete-entry" 
                                                    data-entry-id="{{ $entry->id }}"
                                                    data-toggle="tooltip" 
                                                    title="{{ trans('mining-manager::ledger.delete') }}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 mt-3"></i>
                                        <p>{{ trans('mining-manager::ledger.no_entries') }}</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                            @if($ledgerEntries->count() > 0)
                            <tfoot class="bg-secondary">
                                <tr>
                                    <td colspan="4"><strong>{{ trans('mining-manager::ledger.page_totals') }}</strong></td>
                                    <td class="text-right"><strong>{{ number_format($ledgerEntries->sum('quantity'), 0) }}</strong></td>
                                    <td></td>
                                    <td class="text-right"><strong>{{ number_format($ledgerEntries->sum('total_value'), 0) }} ISK</strong></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
                @if($ledgerEntries->hasPages())
                <div class="card-footer clearfix">
                    {{ $ledgerEntries->appends(request()->query())->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- BULK ACTIONS BAR --}}
    <div class="row" id="bulkActionsBar" style="display: none;">
        <div class="col-12">
            <div class="card card-primary">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <strong><span id="selectedCount">0</span> {{ trans('mining-manager::ledger.items_selected') }}</strong>
                        </div>
                        <div class="col-md-6 text-right">
                            @can('mining-manager.director')
                            <button type="button" class="btn btn-danger" id="bulkDelete">
                                <i class="fas fa-trash"></i> {{ trans('mining-manager::ledger.delete_selected') }}
                            </button>
                            @endcan
                            <button type="button" class="btn btn-info" id="bulkExport">
                                <i class="fas fa-download"></i> {{ trans('mining-manager::ledger.export_selected') }}
                            </button>
                            <button type="button" class="btn btn-secondary" id="clearSelection">
                                <i class="fas fa-times"></i> {{ trans('mining-manager::ledger.clear') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ENTRY DETAILS MODAL --}}
<div class="modal fade" id="entryDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('mining-manager::ledger.entry_details') }}</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body" id="entryDetailsContent">
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin fa-3x"></i>
                    <p>{{ trans('mining-manager::ledger.loading') }}</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    {{ trans('mining-manager::ledger.close') }}
                </button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Select all checkbox
    $('#selectAll').on('change', function() {
        $('.entry-checkbox').prop('checked', $(this).prop('checked'));
        updateBulkActionsBar();
    });

    // Individual checkboxes
    $('.entry-checkbox').on('change', function() {
        updateBulkActionsBar();
    });

    // Update bulk actions bar
    function updateBulkActionsBar() {
        const selectedCount = $('.entry-checkbox:checked').length;
        $('#selectedCount').text(selectedCount);
        
        if (selectedCount > 0) {
            $('#bulkActionsBar').slideDown();
        } else {
            $('#bulkActionsBar').slideUp();
        }
    }

    // Clear selection
    $('#clearSelection').on('click', function() {
        $('.entry-checkbox, #selectAll').prop('checked', false);
        updateBulkActionsBar();
    });

    // Filter form submission
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        window.location.href = '{{ route("mining-manager.ledger.index") }}?' + formData;
    });

    // Refresh stats
    $('#refreshStats').on('click', function() {
        location.reload();
    });

    // View details
    $('.view-details').on('click', function() {
        const entryId = $(this).data('entry-id');
        
        $('#entryDetailsModal').modal('show');
        $('#entryDetailsContent').html('<div class="text-center"><i class="fas fa-spinner fa-spin fa-3x"></i></div>');
        
        $.ajax({
            url: '{{ route("mining-manager.ledger.details", ":id") }}'.replace(':id', entryId),
            method: 'GET',
            success: function(response) {
                $('#entryDetailsContent').html(response);
            },
            error: function(xhr) {
                $('#entryDetailsContent').html('<div class="alert alert-danger">{{ trans("mining-manager::ledger.error_loading") }}</div>');
            }
        });
    });

    // Delete entry
    $('.delete-entry').on('click', function() {
        const entryId = $(this).data('entry-id');
        
        if (confirm('{{ trans("mining-manager::ledger.confirm_delete") }}')) {
            $.ajax({
                url: '{{ route("mining-manager.ledger.delete", ":id") }}'.replace(':id', entryId),
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::ledger.deleted_success") }}');
                    setTimeout(() => location.reload(), 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::ledger.error_deleting") }}');
                }
            });
        }
    });

    // Bulk delete
    $('#bulkDelete').on('click', function() {
        const selectedIds = $('.entry-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (confirm('{{ trans("mining-manager::ledger.confirm_bulk_delete") }} ' + selectedIds.length + ' {{ trans("mining-manager::ledger.items") }}?')) {
            $.ajax({
                url: '{{ route("mining-manager.ledger.bulk-delete") }}',
                method: 'POST',
                data: { entry_ids: selectedIds },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::ledger.bulk_deleted_success") }}');
                    setTimeout(() => location.reload(), 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::ledger.error_bulk_deleting") }}');
                }
            });
        }
    });

    // Export ledger
    $('#exportLedger').on('click', function() {
        const formData = $('#filterForm').serialize();
        window.location.href = '{{ route("mining-manager.ledger.export") }}?' + formData;
    });

    // Bulk export
    $('#bulkExport').on('click', function() {
        const selectedIds = $('.entry-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        window.location.href = '{{ route("mining-manager.ledger.export") }}?entry_ids=' + selectedIds.join(',');
    });
});
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

@endsection
