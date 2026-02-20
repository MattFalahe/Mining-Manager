@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::reports.report_list'))
@section('page_header', trans('mining-manager::menu.reports'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/vendor/dataTables.bootstrap4.min.css') }}">
<style>
    .report-card {
        transition: all 0.3s;
        border-left: 4px solid transparent;
    }
    .report-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }
    .report-daily { border-left-color: #4e73df !important; }
    .report-weekly { border-left-color: #1cc88a !important; }
    .report-monthly { border-left-color: #f6c23e !important; }
    .report-custom { border-left-color: #e74a3b !important; }
    
    .report-type-badge {
        padding: 5px 12px;
        border-radius: 15px;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .badge-daily { background: rgba(78, 115, 223, 0.2); color: #4e73df; }
    .badge-weekly { background: rgba(28, 200, 138, 0.2); color: #1cc88a; }
    .badge-monthly { background: rgba(246, 194, 62, 0.2); color: #f6c23e; }
    .badge-custom { background: rgba(231, 74, 59, 0.2); color: #e74a3b; }
    
    .format-badge {
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 0.75rem;
        font-weight: bold;
    }
    
    .format-json { background: #667eea; color: white; }
    .format-csv { background: #1cc88a; color: white; }
    .format-pdf { background: #e74a3b; color: white; }
    
    .stats-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 10px;
        padding: 20px;
        color: white;
        text-align: center;
    }
    
    .stats-card h3 {
        font-size: 2rem;
        margin: 0;
        font-weight: bold;
    }
    
    .stats-card p {
        margin: 5px 0 0 0;
        opacity: 0.9;
    }
    
    .quick-action-btn {
        margin-bottom: 10px;
    }
    
    .filter-badge {
        cursor: pointer;
        margin-right: 5px;
        transition: all 0.2s;
    }
    
    .filter-badge:hover {
        transform: scale(1.05);
    }
    
    .filter-badge.active {
        box-shadow: 0 0 10px rgba(255,255,255,0.5);
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper reports-page">

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/reports') && !Request::is('*/reports/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.reports.index') }}">
                <i class="fas fa-list"></i> {{ trans('mining-manager::menu.view_reports') }}
            </a>
        </li>
        @can('mining-manager.admin')
        <li class="{{ Request::is('*/reports/generate') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.reports.generate') }}">
                <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::menu.generate_report') }}
            </a>
        </li>
        <li class="{{ Request::is('*/reports/scheduled') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.reports.scheduled') }}">
                <i class="fas fa-clock"></i> {{ trans('mining-manager::menu.scheduled_reports') }}
            </a>
        </li>
        @endcan
        <li class="{{ Request::is('*/reports/export') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.reports.export') }}">
                <i class="fas fa-download"></i> {{ trans('mining-manager::menu.export_data') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">

<div class="reports-index">
    
    {{-- QUICK STATS --}}
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="stats-card">
                <h3>{{ $reports->total() }}</h3>
                <p>{{ trans('mining-manager::reports.total_reports') }}</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #1cc88a 0%, #17a673 100%);">
                <h3>{{ $reports->where('report_type', 'monthly')->count() }}</h3>
                <p>{{ trans('mining-manager::reports.monthly_reports') }}</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #f6c23e 0%, #dda20a 100%);">
                <h3>{{ $reports->where('format', 'csv')->count() }}</h3>
                <p>{{ trans('mining-manager::reports.csv_reports') }}</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%);">
                <h3>{{ number_format($reports->sum(function($r) { return $r->getFileSize() ?? 0; }) / 1024 / 1024, 2) }} MB</h3>
                <p>{{ trans('mining-manager::reports.total_storage') }}</p>
            </div>
        </div>
    </div>

    {{-- QUICK ACTIONS & FILTERS --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i>
                        {{ trans('mining-manager::reports.filters_actions') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Quick Actions --}}
                        <div class="col-md-6">
                            <h5 class="mb-3">{{ trans('mining-manager::reports.quick_actions') }}</h5>
                            <div class="btn-group-vertical w-100">
                                <a href="{{ route('mining-manager.reports.generate') }}" class="btn btn-success quick-action-btn">
                                    <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::reports.generate_new_report') }}
                                </a>
                                <a href="{{ route('mining-manager.reports.scheduled') }}" class="btn btn-info quick-action-btn">
                                    <i class="fas fa-clock"></i> {{ trans('mining-manager::reports.manage_scheduled') }}
                                </a>
                                <a href="{{ route('mining-manager.reports.export') }}" class="btn btn-warning quick-action-btn">
                                    <i class="fas fa-file-export"></i> {{ trans('mining-manager::reports.quick_export') }}
                                </a>
                                <button type="button" class="btn btn-danger quick-action-btn" id="cleanupReports">
                                    <i class="fas fa-trash-alt"></i> {{ trans('mining-manager::reports.cleanup_old_reports') }}
                                </button>
                            </div>
                        </div>

                        {{-- Filters --}}
                        <div class="col-md-6">
                            <h5 class="mb-3">{{ trans('mining-manager::reports.filter_by_type') }}</h5>
                            <div class="mb-3">
                                <a href="{{ route('mining-manager.reports.index') }}" class="badge badge-secondary filter-badge {{ $type === 'all' ? 'active' : '' }}">
                                    <i class="fas fa-list"></i> {{ trans('mining-manager::reports.all_reports') }}
                                </a>
                                <a href="{{ route('mining-manager.reports.index', ['type' => 'daily']) }}" class="badge badge-primary filter-badge badge-daily {{ $type === 'daily' ? 'active' : '' }}">
                                    <i class="fas fa-calendar-day"></i> {{ trans('mining-manager::reports.daily') }}
                                </a>
                                <a href="{{ route('mining-manager.reports.index', ['type' => 'weekly']) }}" class="badge badge-success filter-badge badge-weekly {{ $type === 'weekly' ? 'active' : '' }}">
                                    <i class="fas fa-calendar-week"></i> {{ trans('mining-manager::reports.weekly') }}
                                </a>
                                <a href="{{ route('mining-manager.reports.index', ['type' => 'monthly']) }}" class="badge badge-warning filter-badge badge-monthly {{ $type === 'monthly' ? 'active' : '' }}">
                                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::reports.monthly') }}
                                </a>
                                <a href="{{ route('mining-manager.reports.index', ['type' => 'custom']) }}" class="badge badge-danger filter-badge badge-custom {{ $type === 'custom' ? 'active' : '' }}">
                                    <i class="fas fa-calendar"></i> {{ trans('mining-manager::reports.custom') }}
                                </a>
                            </div>
                            
                            <h5 class="mb-3 mt-4">{{ trans('mining-manager::reports.search_reports') }}</h5>
                            <form method="GET" action="{{ route('mining-manager.reports.index') }}" class="form-inline">
                                <input type="hidden" name="type" value="{{ $type }}">
                                <div class="input-group w-100">
                                    <input type="text" name="search" class="form-control" 
                                           placeholder="{{ trans('mining-manager::reports.search_placeholder') }}" 
                                           value="{{ request('search') }}">
                                    <div class="input-group-append">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- REPORTS LIST --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-file-alt"></i>
                        {{ trans('mining-manager::reports.reports') }}
                        @if($type !== 'all')
                        <span class="badge report-type-badge badge-{{ $type }}">
                            {{ trans('mining-manager::reports.' . $type) }}
                        </span>
                        @endif
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info">
                            {{ $reports->total() }} {{ trans('mining-manager::reports.reports') }}
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if($reports->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-hover table-striped" id="reportsTable">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::reports.type') }}</th>
                                    <th>{{ trans('mining-manager::reports.period') }}</th>
                                    <th>{{ trans('mining-manager::reports.format') }}</th>
                                    <th>{{ trans('mining-manager::reports.generated') }}</th>
                                    <th>{{ trans('mining-manager::reports.size') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::reports.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($reports as $report)
                                <tr>
                                    <td>
                                        <span class="report-type-badge badge-{{ $report->report_type }}">
                                            <i class="fas fa-calendar-{{ $report->report_type === 'daily' ? 'day' : ($report->report_type === 'weekly' ? 'week' : 'alt') }}"></i>
                                            {{ trans('mining-manager::reports.' . $report->report_type) }}
                                        </span>
                                    </td>
                                    <td>
                                        <div>
                                            <i class="fas fa-calendar-check text-success"></i>
                                            {{ $report->start_date->format('M d, Y') }}
                                        </div>
                                        <div>
                                            <i class="fas fa-calendar-times text-danger"></i>
                                            {{ $report->end_date->format('M d, Y') }}
                                        </div>
                                        <small class="text-muted">
                                            ({{ $report->start_date->diffInDays($report->end_date) }} {{ trans('mining-manager::reports.days') }})
                                        </small>
                                    </td>
                                    <td>
                                        <span class="format-badge format-{{ $report->format }}">
                                            {{ strtoupper($report->format) }}
                                        </span>
                                    </td>
                                    <td>
                                        <div>{{ $report->generated_at->format('M d, Y H:i') }}</div>
                                        <small class="text-muted">{{ $report->generated_at->diffForHumans() }}</small>
                                    </td>
                                    <td>
                                        @if($report->fileExists())
                                        <span class="badge badge-success">
                                            <i class="fas fa-check-circle"></i>
                                            {{ number_format($report->getFileSize() / 1024, 2) }} KB
                                        </span>
                                        @else
                                        <span class="badge badge-secondary">
                                            <i class="fas fa-database"></i>
                                            {{ trans('mining-manager::reports.in_database') }}
                                        </span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <div class="btn-group btn-group-sm">
                                            <a href="{{ route('mining-manager.reports.show', $report->id) }}" 
                                               class="btn btn-info" 
                                               title="{{ trans('mining-manager::reports.view_report') }}">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('mining-manager.reports.download', $report->id) }}" 
                                               class="btn btn-success" 
                                               title="{{ trans('mining-manager::reports.download') }}">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-danger delete-report" 
                                                    data-report-id="{{ $report->id }}"
                                                    title="{{ trans('mining-manager::reports.delete') }}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Pagination --}}
                    <div class="card-footer">
                        {{ $reports->links() }}
                    </div>
                    @else
                    <div class="text-center py-5">
                        <i class="fas fa-file-alt fa-5x text-muted mb-3"></i>
                        <h4>{{ trans('mining-manager::reports.no_reports_found') }}</h4>
                        <p class="text-muted">{{ trans('mining-manager::reports.no_reports_description') }}</p>
                        <a href="{{ route('mining-manager.reports.generate') }}" class="btn btn-primary btn-lg">
                            <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::reports.generate_first_report') }}
                        </a>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- RECENT ACTIVITY --}}
    @if($reports->count() > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        {{ trans('mining-manager::reports.recent_activity') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        @foreach($reports->take(5) as $report)
                        <div>
                            <i class="fas fa-file-alt bg-{{ $report->report_type === 'monthly' ? 'warning' : 'primary' }}"></i>
                            <div class="timeline-item">
                                <span class="time">
                                    <i class="fas fa-clock"></i> {{ $report->generated_at->diffForHumans() }}
                                </span>
                                <h3 class="timeline-header">
                                    <span class="report-type-badge badge-{{ $report->report_type }}">
                                        {{ trans('mining-manager::reports.' . $report->report_type) }}
                                    </span>
                                    {{ trans('mining-manager::reports.report_generated') }}
                                </h3>
                                <div class="timeline-body">
                                    {{ trans('mining-manager::reports.period') }}: 
                                    {{ $report->start_date->format('M d') }} - {{ $report->end_date->format('M d, Y') }}
                                    <span class="format-badge format-{{ $report->format }} ml-2">
                                        {{ strtoupper($report->format) }}
                                    </span>
                                </div>
                                <div class="timeline-footer">
                                    <a href="{{ route('mining-manager.reports.show', $report->id) }}" class="btn btn-sm btn-primary">
                                        {{ trans('mining-manager::reports.view') }}
                                    </a>
                                    <a href="{{ route('mining-manager.reports.download', $report->id) }}" class="btn btn-sm btn-success">
                                        {{ trans('mining-manager::reports.download') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                        @endforeach
                        <div>
                            <i class="fas fa-flag-checkered bg-gray"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/jquery.dataTables.min.js') }}"></script>
<script>
$(document).ready(function() {
    // Initialize DataTable if needed
    // $('#reportsTable').DataTable({
    //     order: [[3, 'desc']], // Sort by generated date
    //     pageLength: 20,
    // });

    // Delete report
    $('.delete-report').on('click', function() {
        const reportId = $(this).data('report-id');
        
        if (!confirm('{{ trans("mining-manager::reports.confirm_delete") }}')) {
            return;
        }
        
        $.ajax({
            url: '/mining-manager/reports/' + reportId,
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success('{{ trans("mining-manager::reports.report_deleted") }}');
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::reports.error_deleting") }}');
            }
        });
    });

    // Cleanup old reports
    $('#cleanupReports').on('click', function() {
        if (!confirm('{{ trans("mining-manager::reports.confirm_cleanup") }}')) {
            return;
        }
        
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::reports.cleaning") }}');
        
        $.ajax({
            url: '{{ route("mining-manager.reports.cleanup") }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success(response.message || '{{ trans("mining-manager::reports.cleanup_success") }}');
                setTimeout(() => location.reload(), 1500);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::reports.error_cleanup") }}');
                $('#cleanupReports').prop('disabled', false).html('<i class="fas fa-trash-alt"></i> {{ trans("mining-manager::reports.cleanup_old_reports") }}');
            }
        });
    });
});
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
