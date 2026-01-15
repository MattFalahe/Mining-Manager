@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.tax_overview'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/tax-management.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper taxes-index-page">

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/tax') && !Request::is('*/tax/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.index') }}">
                <i class="fas fa-chart-pie"></i> {{ trans('mining-manager::menu.tax_overview') }}
            </a>
        </li>
        @can('mining-manager.tax.calculate')
        <li class="{{ Request::is('*/tax/calculate') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.calculate') }}">
                <i class="fas fa-calculator"></i> {{ trans('mining-manager::menu.calculate_taxes') }}
            </a>
        </li>
        @endcan
        <li class="{{ Request::is('*/tax/my-taxes') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.my-taxes') }}">
                <i class="fas fa-receipt"></i> {{ trans('mining-manager::menu.my_taxes') }}
            </a>
        </li>
        <li class="{{ Request::is('*/tax/codes') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.codes') }}">
                <i class="fas fa-barcode"></i> {{ trans('mining-manager::menu.tax_codes') }}
            </a>
        </li>
        @can('mining-manager.tax.generate_invoices')
        <li class="{{ Request::is('*/tax/contracts') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.contracts') }}">
                <i class="fas fa-file-contract"></i> {{ trans('mining-manager::menu.tax_contracts') }}
            </a>
        </li>
        @endcan
        @can('mining-manager.tax.verify_payments')
        <li class="{{ Request::is('*/tax/wallet') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.wallet') }}">
                <i class="fas fa-wallet"></i> {{ trans('mining-manager::menu.wallet_verification') }}
            </a>
        </li>
        @endcan
    </ul>
    <div class="tab-content">


<div class="tax-management">
    
    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::taxes.tax_summary') }} - {{ now()->format('F Y') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-sm btn-primary" id="refreshStats">
                            <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::taxes.refresh') }}
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Total Owed --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-warning">
                                <span class="info-box-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.total_owed') }}</span>
                                    <span class="info-box-number">{{ number_format($summary['total_owed'], 0) }}</span>
                                    <small>ISK ({{ $summary['unpaid_count'] }} {{ trans('mining-manager::taxes.members') }})</small>
                                </div>
                            </div>
                        </div>

                        {{-- Overdue --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-danger">
                                <span class="info-box-icon">
                                    <i class="fas fa-clock"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.overdue') }}</span>
                                    <span class="info-box-number">{{ number_format($summary['overdue_amount'], 0) }}</span>
                                    <small>ISK ({{ $summary['overdue_count'] }} {{ trans('mining-manager::taxes.members') }})</small>
                                </div>
                            </div>
                        </div>

                        {{-- Collected This Month --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.collected') }}</span>
                                    <span class="info-box-number">{{ number_format($summary['collected'], 0) }}</span>
                                    <small>ISK ({{ $summary['paid_count'] }} {{ trans('mining-manager::taxes.payments') }})</small>
                                </div>
                            </div>
                        </div>

                        {{-- Collection Rate --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-percentage"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.collection_rate') }}</span>
                                    <span class="info-box-number">{{ number_format($summary['collection_rate'], 1) }}%</span>
                                    <small>{{ trans('mining-manager::taxes.this_month') }}</small>
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
                        {{ trans('mining-manager::taxes.filters') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-sm btn-info" id="exportTaxes">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::taxes.export') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-success" id="sendReminders" data-toggle="tooltip" title="{{ trans('mining-manager::taxes.send_reminders_to_selected') }}">
                            <i class="fas fa-envelope"></i> {{ trans('mining-manager::taxes.send_reminders') }}
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <form id="filterForm">
                        <div class="row">
                            {{-- Status Filter --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="statusFilter">{{ trans('mining-manager::taxes.status') }}</label>
                                    <select class="form-control" id="statusFilter" name="status">
                                        <option value="">{{ trans('mining-manager::taxes.all_statuses') }}</option>
                                        <option value="unpaid">{{ trans('mining-manager::taxes.unpaid') }}</option>
                                        <option value="paid">{{ trans('mining-manager::taxes.paid') }}</option>
                                        <option value="overdue">{{ trans('mining-manager::taxes.overdue') }}</option>
                                        <option value="partial">{{ trans('mining-manager::taxes.partial') }}</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Month Filter --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="monthFilter">{{ trans('mining-manager::taxes.month') }}</label>
                                    <input type="month" class="form-control" id="monthFilter" name="month" value="{{ now()->format('Y-m') }}">
                                </div>
                            </div>

                            {{-- Character Search --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="characterSearch">{{ trans('mining-manager::taxes.search_character') }}</label>
                                    <input type="text" class="form-control" id="characterSearch" name="character" placeholder="{{ trans('mining-manager::taxes.enter_character_name') }}">
                                </div>
                            </div>

                            {{-- Apply Button --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="fas fa-search"></i> {{ trans('mining-manager::taxes.apply') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- TAX LIST TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        {{ trans('mining-manager::taxes.tax_entries') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info" id="resultCount">{{ $taxes->total() }} {{ trans('mining-manager::taxes.entries') }}</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover" id="taxTable">
                            <thead>
                                <tr>
                                    <th style="width: 40px">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <th>{{ trans('mining-manager::taxes.character') }}</th>
                                    <th>{{ trans('mining-manager::taxes.corporation') }}</th>
                                    <th>{{ trans('mining-manager::taxes.month') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.amount_owed') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.amount_paid') }}</th>
                                    <th>{{ trans('mining-manager::taxes.status') }}</th>
                                    <th>{{ trans('mining-manager::taxes.due_date') }}</th>
                                    <th class="text-center">{{ trans('mining-manager::taxes.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($taxes as $tax)
                                <tr data-tax-id="{{ $tax->id }}">
                                    <td>
                                        <input type="checkbox" class="tax-checkbox" value="{{ $tax->id }}">
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $tax->character_id }}/portrait?size=32" 
                                             class="img-circle" 
                                             style="width: 32px; height: 32px;">
                                        <a href="{{ route('mining-manager.taxes.details', $tax->id) }}">
                                            {{ $tax->character->name ?? 'Unknown' }}
                                        </a>
                                    </td>
                                    <td>{{ $tax->character->corporation->name ?? 'Unknown' }}</td>
                                    <td>{{ \Carbon\Carbon::parse($tax->month)->format('F Y') }}</td>
                                    <td class="text-right">
                                        <strong>{{ number_format($tax->amount_owed, 0) }}</strong>
                                        <small class="text-muted">ISK</small>
                                    </td>
                                    <td class="text-right">
                                        <strong>{{ number_format($tax->amount_paid, 0) }}</strong>
                                        <small class="text-muted">ISK</small>
                                    </td>
                                    <td>
                                        @switch($tax->status)
                                            @case('paid')
                                                <span class="badge badge-success">
                                                    <i class="fas fa-check"></i> {{ trans('mining-manager::taxes.paid') }}
                                                </span>
                                                @break
                                            @case('unpaid')
                                                <span class="badge badge-warning">
                                                    <i class="fas fa-clock"></i> {{ trans('mining-manager::taxes.unpaid') }}
                                                </span>
                                                @break
                                            @case('overdue')
                                                <span class="badge badge-danger">
                                                    <i class="fas fa-exclamation-triangle"></i> {{ trans('mining-manager::taxes.overdue') }}
                                                </span>
                                                @break
                                            @case('partial')
                                                <span class="badge badge-info">
                                                    <i class="fas fa-adjust"></i> {{ trans('mining-manager::taxes.partial') }}
                                                </span>
                                                @break
                                        @endswitch
                                    </td>
                                    <td>
                                        @if($tax->due_date)
                                            @php
                                                $dueDate = \Carbon\Carbon::parse($tax->due_date);
                                                $isOverdue = $dueDate->isPast() && $tax->status !== 'paid';
                                            @endphp
                                            <span class="{{ $isOverdue ? 'text-danger' : '' }}">
                                                {{ $dueDate->format('Y-m-d') }}
                                                @if($isOverdue)
                                                    <br><small>({{ $dueDate->diffForHumans() }})</small>
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <a href="{{ route('mining-manager.taxes.details', $tax->id) }}" 
                                               class="btn btn-sm btn-info" 
                                               data-toggle="tooltip" 
                                               title="{{ trans('mining-manager::taxes.view_details') }}">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            @if($tax->status !== 'paid')
                                            <button type="button" 
                                                    class="btn btn-sm btn-success mark-paid" 
                                                    data-tax-id="{{ $tax->id }}"
                                                    data-toggle="tooltip" 
                                                    title="{{ trans('mining-manager::taxes.mark_as_paid') }}">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-sm btn-warning send-reminder" 
                                                    data-tax-id="{{ $tax->id }}"
                                                    data-character-name="{{ $tax->character->name ?? 'Unknown' }}"
                                                    data-toggle="tooltip" 
                                                    title="{{ trans('mining-manager::taxes.send_reminder') }}">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 mt-3"></i>
                                        <p>{{ trans('mining-manager::taxes.no_taxes_found') }}</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($taxes->hasPages())
                <div class="card-footer clearfix">
                    {{ $taxes->appends(request()->query())->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- BULK ACTIONS SUMMARY --}}
    <div class="row" id="bulkActionsBar" style="display: none;">
        <div class="col-12">
            <div class="card card-primary">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <strong><span id="selectedCount">0</span> {{ trans('mining-manager::taxes.items_selected') }}</strong>
                        </div>
                        <div class="col-md-6 text-right">
                            <button type="button" class="btn btn-success" id="bulkMarkPaid">
                                <i class="fas fa-check"></i> {{ trans('mining-manager::taxes.mark_as_paid') }}
                            </button>
                            <button type="button" class="btn btn-warning" id="bulkSendReminders">
                                <i class="fas fa-envelope"></i> {{ trans('mining-manager::taxes.send_reminders') }}
                            </button>
                            <button type="button" class="btn btn-secondary" id="clearSelection">
                                <i class="fas fa-times"></i> {{ trans('mining-manager::taxes.clear') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- MARK AS PAID MODAL --}}
<div class="modal fade" id="markPaidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('mining-manager::taxes.mark_as_paid') }}</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="markPaidForm">
                    <input type="hidden" id="taxIdInput" name="tax_id">
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.amount_paid') }}</label>
                        <input type="number" class="form-control" id="amountPaidInput" name="amount_paid" required>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.payment_date') }}</label>
                        <input type="date" class="form-control" id="paymentDateInput" name="payment_date" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.notes') }}</label>
                        <textarea class="form-control" id="notesInput" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    {{ trans('mining-manager::taxes.cancel') }}
                </button>
                <button type="button" class="btn btn-success" id="confirmMarkPaid">
                    <i class="fas fa-check"></i> {{ trans('mining-manager::taxes.confirm') }}
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
        $('.tax-checkbox').prop('checked', $(this).prop('checked'));
        updateBulkActionsBar();
    });

    // Individual checkboxes
    $('.tax-checkbox').on('change', function() {
        updateBulkActionsBar();
    });

    // Update bulk actions bar
    function updateBulkActionsBar() {
        const selectedCount = $('.tax-checkbox:checked').length;
        $('#selectedCount').text(selectedCount);
        
        if (selectedCount > 0) {
            $('#bulkActionsBar').slideDown();
        } else {
            $('#bulkActionsBar').slideUp();
        }
    }

    // Clear selection
    $('#clearSelection').on('click', function() {
        $('.tax-checkbox, #selectAll').prop('checked', false);
        updateBulkActionsBar();
    });

    // Filter form submission
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        window.location.href = '{{ route("mining-manager.taxes.index") }}?' + formData;
    });

    // Refresh stats
    $('#refreshStats').on('click', function() {
        location.reload();
    });

    // Mark as paid - single
    $('.mark-paid').on('click', function() {
        const taxId = $(this).data('tax-id');
        const row = $('tr[data-tax-id="' + taxId + '"]');
        const amountOwed = row.find('td:eq(4)').text().replace(/[^\d]/g, '');
        
        $('#taxIdInput').val(taxId);
        $('#amountPaidInput').val(amountOwed);
        $('#markPaidModal').modal('show');
    });

    // Confirm mark as paid
    $('#confirmMarkPaid').on('click', function() {
        const formData = $('#markPaidForm').serialize();
        
        $.ajax({
            url: '{{ route("mining-manager.taxes.mark-paid") }}',
            method: 'POST',
            data: formData,
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success(response.message || '{{ trans("mining-manager::taxes.marked_as_paid_success") }}');
                $('#markPaidModal').modal('hide');
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_marking_paid") }}');
            }
        });
    });

    // Send reminder - single
    $('.send-reminder').on('click', function() {
        const taxId = $(this).data('tax-id');
        const characterName = $(this).data('character-name');
        
        if (confirm('{{ trans("mining-manager::taxes.confirm_send_reminder") }} ' + characterName + '?')) {
            $.ajax({
                url: '{{ route("mining-manager.taxes.send-reminder") }}',
                method: 'POST',
                data: { tax_id: taxId },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::taxes.reminder_sent") }}');
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_sending_reminder") }}');
                }
            });
        }
    });

    // Bulk mark as paid
    $('#bulkMarkPaid').on('click', function() {
        const selectedIds = $('.tax-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (confirm('{{ trans("mining-manager::taxes.confirm_bulk_mark_paid") }} ' + selectedIds.length + ' {{ trans("mining-manager::taxes.items") }}?')) {
            $.ajax({
                url: '{{ route("mining-manager.taxes.bulk-mark-paid") }}',
                method: 'POST',
                data: { tax_ids: selectedIds },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::taxes.bulk_marked_success") }}');
                    setTimeout(() => location.reload(), 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_bulk_marking") }}');
                }
            });
        }
    });

    // Bulk send reminders
    $('#bulkSendReminders, #sendReminders').on('click', function() {
        const selectedIds = $('.tax-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (selectedIds.length === 0) {
            toastr.warning('{{ trans("mining-manager::taxes.select_items_first") }}');
            return;
        }
        
        if (confirm('{{ trans("mining-manager::taxes.confirm_bulk_reminders") }} ' + selectedIds.length + ' {{ trans("mining-manager::taxes.members") }}?')) {
            $.ajax({
                url: '{{ route("mining-manager.taxes.bulk-send-reminders") }}',
                method: 'POST',
                data: { tax_ids: selectedIds },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::taxes.reminders_sent") }}');
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_sending_reminders") }}');
                }
            });
        }
    });

    // Export taxes
    $('#exportTaxes').on('click', function() {
        const formData = $('#filterForm').serialize();
        window.location.href = '{{ route("mining-manager.taxes.export") }}?' + formData;
    });
});
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
