@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::reports.scheduled_reports'))
@section('page_header', trans('mining-manager::menu.reports'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v={{ time() }}">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard reports-scheduled-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/reports') && !Request::is('*/reports/*') ? 'active' : '' }}" href="{{ route('mining-manager.reports.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.view_reports') }}
                </a>
            </li>
            @can('mining-manager.admin')
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/reports/generate') ? 'active' : '' }}" href="{{ route('mining-manager.reports.generate') }}">
                    <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::menu.generate_report') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/reports/scheduled') ? 'active' : '' }}" href="{{ route('mining-manager.reports.scheduled') }}">
                    <i class="fas fa-clock"></i> {{ trans('mining-manager::menu.scheduled_reports') }}
                </a>
            </li>
            @endcan
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/reports/export') ? 'active' : '' }}" href="{{ route('mining-manager.reports.export') }}">
                    <i class="fas fa-download"></i> {{ trans('mining-manager::menu.export_data') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">


<div class="reports-scheduled">
    
    {{-- INFO BANNER --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="alert alert-info">
                <h5>
                    <i class="fas fa-info-circle"></i>
                    {{ trans('mining-manager::reports.scheduled_reports_info') }}
                </h5>
                <p class="mb-0">
                    {{ trans('mining-manager::reports.scheduled_reports_description') }}
                </p>
            </div>
        </div>
    </div>

    {{-- QUICK STATS --}}
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="card bg-gradient-success">
                <div class="card-body text-center">
                    <h3 class="mb-0">{{ $schedules->where('is_active', true)->count() }}</h3>
                    <p class="mb-0">{{ trans('mining-manager::reports.active_schedules') }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-gradient-warning">
                <div class="card-body text-center">
                    <h3 class="mb-0">{{ $schedules->where('is_active', false)->count() }}</h3>
                    <p class="mb-0">{{ trans('mining-manager::reports.paused_schedules') }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-gradient-info">
                <div class="card-body text-center">
                    <h3 class="mb-0">{{ $schedules->sum('reports_generated') }}</h3>
                    <p class="mb-0">{{ trans('mining-manager::reports.total_generated') }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-gradient-primary">
                <div class="card-body text-center">
                    <h3 class="mb-0">{{ $schedules->where('next_run', '<=', now()->addDay())->count() }}</h3>
                    <p class="mb-0">{{ trans('mining-manager::reports.running_soon') }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- SCHEDULED REPORTS LIST --}}
    <div class="row">
        {{-- CREATE NEW SCHEDULE CARD --}}
        <div class="col-lg-4 mb-4">
            <div class="create-schedule-card" data-toggle="modal" data-target="#createScheduleModal">
                <i class="fas fa-plus-circle fa-5x mb-3"></i>
                <h4>{{ trans('mining-manager::reports.create_new_schedule') }}</h4>
                <p>{{ trans('mining-manager::reports.automate_report_generation') }}</p>
            </div>
        </div>

        {{-- EXISTING SCHEDULES --}}
        @forelse($schedules as $schedule)
        <div class="col-lg-4 mb-4">
            <div class="card schedule-card schedule-{{ $schedule->is_active ? 'active' : 'inactive' }}">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-clock"></i>
                        {{ $schedule->name }}
                    </h5>
                    <div class="card-tools">
                        <label class="status-toggle">
                            <input type="checkbox" class="toggle-schedule" 
                                   data-schedule-id="{{ $schedule->id }}" 
                                   {{ $schedule->is_active ? 'checked' : '' }}>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Frequency --}}
                    <div class="mb-2">
                        <strong>{{ trans('mining-manager::reports.frequency') }}:</strong>
                        <span class="frequency-badge freq-{{ $schedule->frequency }}">
                            <i class="fas fa-calendar-{{ $schedule->frequency === 'daily' ? 'day' : ($schedule->frequency === 'weekly' ? 'week' : 'alt') }}"></i>
                            {{ trans('mining-manager::reports.' . $schedule->frequency) }}
                        </span>
                    </div>

                    {{-- Format --}}
                    <div class="mb-2">
                        <strong>{{ trans('mining-manager::reports.format') }}:</strong>
                        <span class="badge badge-{{ $schedule->format === 'json' ? 'primary' : ($schedule->format === 'csv' ? 'success' : 'danger') }}">
                            {{ strtoupper($schedule->format) }}
                        </span>
                    </div>

                    {{-- Last Run --}}
                    @if($schedule->last_run)
                    <div class="mb-2">
                        <strong>{{ trans('mining-manager::reports.last_run') }}:</strong>
                        <div class="text-muted">
                            <i class="fas fa-clock"></i>
                            {{ $schedule->last_run->diffForHumans() }}
                        </div>
                    </div>
                    @endif

                    {{-- Next Run --}}
                    @if($schedule->is_active && $schedule->next_run)
                    <div class="next-run-countdown">
                        <strong>{{ trans('mining-manager::reports.next_run') }}:</strong>
                        <div class="countdown" data-next-run="{{ $schedule->next_run->toIso8601String() }}">
                            <i class="fas fa-hourglass-half"></i>
                            {{ $schedule->next_run->diffForHumans() }}
                        </div>
                    </div>
                    @endif

                    {{-- Reports Generated Count --}}
                    <div class="mt-3 text-center">
                        <h4 class="mb-0">{{ $schedule->reports_generated }}</h4>
                        <small class="text-muted">{{ trans('mining-manager::reports.reports_generated') }}</small>
                    </div>

                    {{-- Description --}}
                    @if($schedule->description)
                    <div class="mt-3">
                        <small class="text-muted">{{ $schedule->description }}</small>
                    </div>
                    @endif
                </div>
                <div class="card-footer">
                    <div class="btn-group btn-group-sm w-100">
                        <button type="button" class="btn btn-info edit-schedule" data-schedule-id="{{ $schedule->id }}">
                            <i class="fas fa-edit"></i> {{ trans('mining-manager::reports.edit') }}
                        </button>
                        <button type="button" class="btn btn-primary run-now" data-schedule-id="{{ $schedule->id }}">
                            <i class="fas fa-play"></i> {{ trans('mining-manager::reports.run_now') }}
                        </button>
                        <button type="button" class="btn btn-danger delete-schedule" data-schedule-id="{{ $schedule->id }}">
                            <i class="fas fa-trash"></i> {{ trans('mining-manager::reports.delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @empty
        {{-- No schedules message --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-calendar-times fa-5x text-muted mb-3"></i>
                    <h4>{{ trans('mining-manager::reports.no_schedules_yet') }}</h4>
                    <p class="text-muted">{{ trans('mining-manager::reports.no_schedules_description') }}</p>
                </div>
            </div>
        </div>
        @endforelse
    </div>

    {{-- RECENT SCHEDULED REPORTS --}}
    @if($recentReports && $recentReports->count() > 0)
    <div class="row mt-4">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        {{ trans('mining-manager::reports.recent_scheduled_reports') }}
                    </h3>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::reports.schedule') }}</th>
                                <th>{{ trans('mining-manager::reports.generated') }}</th>
                                <th>{{ trans('mining-manager::reports.period') }}</th>
                                <th>{{ trans('mining-manager::reports.format') }}</th>
                                <th class="text-right">{{ trans('mining-manager::reports.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentReports as $report)
                            <tr>
                                <td>{{ $report->schedule->name ?? trans('mining-manager::reports.manual') }}</td>
                                <td>{{ $report->generated_at->diffForHumans() }}</td>
                                <td>
                                    {{ $report->start_date->format('M d') }} - {{ $report->end_date->format('M d, Y') }}
                                </td>
                                <td>
                                    <span class="badge badge-{{ $report->format === 'json' ? 'primary' : ($report->format === 'csv' ? 'success' : 'danger') }}">
                                        {{ strtoupper($report->format) }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('mining-manager.reports.show', $report->id) }}" class="btn btn-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="{{ route('mining-manager.reports.download', $report->id) }}" class="btn btn-success">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

{{-- CREATE SCHEDULE MODAL --}}
<div class="modal fade modal-dark" id="createScheduleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i>
                    {{ trans('mining-manager::reports.create_new_schedule') }}
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="{{ route('mining-manager.reports.schedules.store') }}" id="createScheduleForm">
                @csrf
                <div class="modal-body">
                    <div class="form-group">
                        <label for="schedule_name">{{ trans('mining-manager::reports.schedule_name') }}</label>
                        <input type="text" class="form-control" id="schedule_name" name="name" required 
                               placeholder="{{ trans('mining-manager::reports.schedule_name_placeholder') }}">
                    </div>

                    <div class="form-group">
                        <label for="schedule_description">{{ trans('mining-manager::reports.description') }}</label>
                        <textarea class="form-control" id="schedule_description" name="description" rows="2" 
                                  placeholder="{{ trans('mining-manager::reports.description_placeholder') }}"></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="schedule_frequency">{{ trans('mining-manager::reports.frequency') }}</label>
                                <select class="form-control" id="schedule_frequency" name="frequency" required>
                                    <option value="daily">{{ trans('mining-manager::reports.daily') }}</option>
                                    <option value="weekly">{{ trans('mining-manager::reports.weekly') }}</option>
                                    <option value="monthly">{{ trans('mining-manager::reports.monthly') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="schedule_format">{{ trans('mining-manager::reports.format') }}</label>
                                <select class="form-control" id="schedule_format" name="format" required>
                                    <option value="json">JSON</option>
                                    <option value="csv">CSV</option>
                                    <option value="pdf">PDF</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="schedule_time">{{ trans('mining-manager::reports.run_time') }}</label>
                        <input type="time" class="form-control" id="schedule_time" name="run_time" value="00:00" required>
                        <small class="form-text text-muted">{{ trans('mining-manager::reports.run_time_help') }}</small>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="schedule_active" name="is_active" checked>
                        <label class="form-check-label" for="schedule_active">
                            {{ trans('mining-manager::reports.activate_immediately') }}
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        {{ trans('mining-manager::reports.cancel') }}
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus-circle"></i>
                        {{ trans('mining-manager::reports.create_schedule') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Toggle schedule active status
    $('.toggle-schedule').on('change', function() {
        const scheduleId = $(this).data('schedule-id');
        const isActive = $(this).is(':checked');
        
        $.ajax({
            url: `/mining-manager/reports/schedules/${scheduleId}/toggle`,
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            data: { is_active: isActive },
            success: function(response) {
                toastr.success(response.message);
                location.reload();
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message);
                $(this).prop('checked', !isActive);
            }
        });
    });

    // Run schedule now
    $('.run-now').on('click', function() {
        const scheduleId = $(this).data('schedule-id');
        
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
        
        $.ajax({
            url: `/mining-manager/reports/schedules/${scheduleId}/run`,
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success('{{ trans("mining-manager::reports.report_generation_started") }}');
                setTimeout(() => location.reload(), 2000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message);
                $(this).prop('disabled', false).html('<i class="fas fa-play"></i> {{ trans("mining-manager::reports.run_now") }}');
            }
        });
    });

    // Delete schedule
    $('.delete-schedule').on('click', function() {
        if (!confirm('{{ trans("mining-manager::reports.confirm_delete_schedule") }}')) {
            return;
        }
        
        const scheduleId = $(this).data('schedule-id');
        
        $.ajax({
            url: `/mining-manager/reports/schedules/${scheduleId}`,
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success('{{ trans("mining-manager::reports.schedule_deleted") }}');
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message);
            }
        });
    });

    // Update countdown timers
    function updateCountdowns() {
        $('.countdown').each(function() {
            const nextRun = new Date($(this).data('next-run'));
            const now = new Date();
            const diff = nextRun - now;
            
            if (diff > 0) {
                const hours = Math.floor(diff / (1000 * 60 * 60));
                const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                $(this).html(`<i class="fas fa-hourglass-half"></i> ${hours}h ${minutes}m`);
            } else {
                $(this).html('<i class="fas fa-sync fa-spin"></i> Running...');
            }
        });
    }
    
    // Update countdowns every minute
    setInterval(updateCountdowns, 60000);
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
