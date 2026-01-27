@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.process_ledger'))
@section('page_header', trans_choice('mining-manager::ledger.mining_ledger', 2))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper ledger-process-page">

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ (Request::is('*/ledger') && !Request::is('*/ledger/*')) || Request::is('*/ledger/summary') ? '' : '' }}">
            <a href="{{ route('mining-manager.ledger.index') }}">
                <i class="fas fa-layer-group"></i> {{ trans('mining-manager::ledger.mining_summary') }}
            </a>
        </li>
        <li class="{{ Request::is('*/ledger/my-mining') ? '' : '' }}">
            <a href="{{ route('mining-manager.ledger.my-mining') }}">
                <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
            </a>
        </li>
        @can('mining-manager.ledger.process')
        <li class="{{ Request::is('*/ledger/process') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.ledger.process') }}">
                <i class="fas fa-cogs"></i> {{ trans('mining-manager::menu.process_ledger') }}
            </a>
        </li>
        @endcan
    </ul>
    <div class="tab-content">

<div class="process-ledger">
    
    {{-- PROCESSING STATUS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i>
                        {{ trans('mining-manager::ledger.processing_status') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon"><i class="fas fa-clock"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.pending') }}</span>
                                    <span class="info-box-number">{{ $stats['pending'] ?? 0 }}</span>
                                    <small>{{ trans('mining-manager::ledger.entries') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon"><i class="fas fa-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.processed') }}</span>
                                    <span class="info-box-number">{{ $stats['processed'] ?? 0 }}</span>
                                    <small>{{ trans('mining-manager::ledger.entries') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-gradient-danger">
                                <span class="info-box-icon"><i class="fas fa-exclamation-triangle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.failed') }}</span>
                                    <span class="info-box-number">{{ $stats['failed'] ?? 0 }}</span>
                                    <small>{{ trans('mining-manager::ledger.entries') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-gradient-warning">
                                <span class="info-box-icon"><i class="fas fa-sync-alt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.last_sync') }}</span>
                                    <span class="info-box-number">
                                        @if($lastSync)
                                            {{ $lastSync->diffForHumans() }}
                                        @else
                                            N/A
                                        @endif
                                    </span>
                                    <small>{{ trans('mining-manager::ledger.from_esi') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- PROCESSING METHODS --}}
    <div class="row">
        {{-- Import from ESI --}}
        <div class="col-md-6">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-cloud-download-alt"></i>
                        {{ trans('mining-manager::ledger.import_from_esi') }}
                    </h3>
                </div>
                <div class="card-body">
                    <p>{{ trans('mining-manager::ledger.esi_import_description') }}</p>
                    <form id="esiImportForm">
                        <div class="form-group">
                            <label>{{ trans('mining-manager::ledger.import_for') }}</label>
                            <select class="form-control" name="import_type">
                                <option value="corporation">{{ trans('mining-manager::ledger.entire_corporation') }}</option>
                                <option value="character">{{ trans('mining-manager::ledger.specific_character') }}</option>
                            </select>
                        </div>
                        <div class="form-group" id="characterSelect" style="display: none;">
                            <label>{{ trans('mining-manager::ledger.select_character') }}</label>
                            <select class="form-control" name="character_id">
                                <option value="">{{ trans('mining-manager::ledger.select_character') }}</option>
                                @foreach($characters ?? [] as $character)
                                <option value="{{ $character->character_id }}">{{ $character->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>{{ trans('mining-manager::ledger.date_range') }}</label>
                            <div class="row">
                                <div class="col-6">
                                    <input type="date" class="form-control" name="date_from" value="{{ now()->subDays(30)->format('Y-m-d') }}">
                                </div>
                                <div class="col-6">
                                    <input type="date" class="form-control" name="date_to" value="{{ now()->format('Y-m-d') }}">
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::ledger.import_now') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Upload CSV --}}
        <div class="col-md-6">
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-file-csv"></i>
                        {{ trans('mining-manager::ledger.upload_csv') }}
                    </h3>
                </div>
                <div class="card-body">
                    <p>{{ trans('mining-manager::ledger.csv_upload_description') }}</p>
                    <form id="csvUploadForm" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>{{ trans('mining-manager::ledger.select_csv_file') }}</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" id="csvFile" name="csv_file" accept=".csv">
                                <label class="custom-file-label" for="csvFile">{{ trans('mining-manager::ledger.choose_file') }}</label>
                            </div>
                            <small class="form-text text-muted">
                                {{ trans('mining-manager::ledger.csv_format_help') }}
                            </small>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="skipDuplicates" name="skip_duplicates" checked>
                            <label class="form-check-label" for="skipDuplicates">
                                {{ trans('mining-manager::ledger.skip_duplicates') }}
                            </label>
                        </div>
                        <button type="submit" class="btn btn-success btn-block">
                            <i class="fas fa-upload"></i> {{ trans('mining-manager::ledger.upload_process') }}
                        </button>
                    </form>
                    <hr>
                    <a href="{{ route('mining-manager.ledger.download-template') }}" class="btn btn-sm btn-outline-info">
                        <i class="fas fa-download"></i> {{ trans('mining-manager::ledger.download_template') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- PROCESSING QUEUE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-tasks"></i>
                        {{ trans('mining-manager::ledger.processing_queue') }}
                    </h3>
                    <div class="card-tools">
                        <button class="btn btn-sm btn-warning" id="pauseQueue">
                            <i class="fas fa-pause"></i> {{ trans('mining-manager::ledger.pause') }}
                        </button>
                        <button class="btn btn-sm btn-info" id="refreshQueue">
                            <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::ledger.refresh') }}
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::ledger.job_id') }}</th>
                                    <th>{{ trans('mining-manager::ledger.type') }}</th>
                                    <th>{{ trans('mining-manager::ledger.source') }}</th>
                                    <th>{{ trans('mining-manager::ledger.status') }}</th>
                                    <th>{{ trans('mining-manager::ledger.progress') }}</th>
                                    <th>{{ trans('mining-manager::ledger.started') }}</th>
                                    <th class="text-center">{{ trans('mining-manager::ledger.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($queue ?? [] as $job)
                                <tr>
                                    <td><code>{{ $job->id }}</code></td>
                                    <td>
                                        @if($job->type === 'esi_import')
                                            <span class="badge badge-primary">ESI Import</span>
                                        @else
                                            <span class="badge badge-success">CSV Upload</span>
                                        @endif
                                    </td>
                                    <td><small>{{ $job->source }}</small></td>
                                    <td>
                                        @switch($job->status)
                                            @case('pending')
                                                <span class="badge badge-info">{{ trans('mining-manager::ledger.pending') }}</span>
                                                @break
                                            @case('processing')
                                                <span class="badge badge-warning">{{ trans('mining-manager::ledger.processing') }}</span>
                                                @break
                                            @case('completed')
                                                <span class="badge badge-success">{{ trans('mining-manager::ledger.completed') }}</span>
                                                @break
                                            @case('failed')
                                                <span class="badge badge-danger">{{ trans('mining-manager::ledger.failed') }}</span>
                                                @break
                                        @endswitch
                                    </td>
                                    <td>
                                        <div class="progress">
                                            <div class="progress-bar" role="progressbar" 
                                                 style="width: {{ $job->progress }}%" 
                                                 aria-valuenow="{{ $job->progress }}" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                {{ $job->progress }}%
                                            </div>
                                        </div>
                                    </td>
                                    <td><small>{{ $job->created_at->diffForHumans() }}</small></td>
                                    <td class="text-center">
                                        @if($job->status === 'failed')
                                        <button class="btn btn-sm btn-warning retry-job" data-job-id="{{ $job->id }}">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                        @endif
                                        <button class="btn btn-sm btn-info view-log" data-job-id="{{ $job->id }}">
                                            <i class="fas fa-file-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">
                                        {{ trans('mining-manager::ledger.no_jobs_in_queue') }}
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

    {{-- PROCESSING LOG --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        {{ trans('mining-manager::ledger.processing_history') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::ledger.date') }}</th>
                                    <th>{{ trans('mining-manager::ledger.action') }}</th>
                                    <th>{{ trans('mining-manager::ledger.result') }}</th>
                                    <th>{{ trans('mining-manager::ledger.processed_by') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($history ?? [] as $log)
                                <tr>
                                    <td><small>{{ $log->created_at->format('Y-m-d H:i') }}</small></td>
                                    <td>{{ $log->action }}</td>
                                    <td>
                                        <small>{{ $log->entries_processed }} {{ trans('mining-manager::ledger.entries') }}</small>
                                        @if($log->errors > 0)
                                            <span class="text-danger">({{ $log->errors }} {{ trans('mining-manager::ledger.errors') }})</span>
                                        @endif
                                    </td>
                                    <td><small>{{ $log->user_name }}</small></td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted">
                                        {{ trans('mining-manager::ledger.no_processing_history') }}
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

{{-- JOB LOG MODAL --}}
<div class="modal fade" id="jobLogModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('mining-manager::ledger.job_log') }}</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <pre id="jobLogContent" style="max-height: 400px; overflow-y: auto; background: #1a1a1a; padding: 15px; border-radius: 5px;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('mining-manager::ledger.close') }}</button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Show/hide character select
    $('select[name="import_type"]').on('change', function() {
        if ($(this).val() === 'character') {
            $('#characterSelect').slideDown();
        } else {
            $('#characterSelect').slideUp();
        }
    });

    // Custom file input label
    $('.custom-file-input').on('change', function() {
        const fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').html(fileName);
    });

    // ESI Import Form
    $('#esiImportForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        
        $.ajax({
            url: '{{ route("mining-manager.ledger.import-esi") }}',
            method: 'POST',
            data: formData,
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success(response.message || '{{ trans("mining-manager::ledger.import_started") }}');
                setTimeout(() => location.reload(), 2000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::ledger.import_failed") }}');
            }
        });
    });

    // CSV Upload Form
    $('#csvUploadForm').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        $.ajax({
            url: '{{ route("mining-manager.ledger.upload-csv") }}',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success(response.message || '{{ trans("mining-manager::ledger.upload_success") }}');
                setTimeout(() => location.reload(), 2000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::ledger.upload_failed") }}');
            }
        });
    });

    // Pause/Resume Queue
    $('#pauseQueue').on('click', function() {
        const btn = $(this);
        const action = btn.find('i').hasClass('fa-pause') ? 'pause' : 'resume';
        
        $.ajax({
            url: '{{ route("mining-manager.ledger.toggle-queue") }}',
            method: 'POST',
            data: { action: action },
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                if (action === 'pause') {
                    btn.html('<i class="fas fa-play"></i> {{ trans("mining-manager::ledger.resume") }}');
                    btn.removeClass('btn-warning').addClass('btn-success');
                } else {
                    btn.html('<i class="fas fa-pause"></i> {{ trans("mining-manager::ledger.pause") }}');
                    btn.removeClass('btn-success').addClass('btn-warning');
                }
                toastr.success(response.message);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::ledger.action_failed") }}');
            }
        });
    });

    // Refresh Queue
    $('#refreshQueue').on('click', function() {
        location.reload();
    });

    // Retry Job
    $('.retry-job').on('click', function() {
        const jobId = $(this).data('job-id');
        
        $.ajax({
            url: '{{ route("mining-manager.ledger.retry-job", ":id") }}'.replace(':id', jobId),
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success(response.message || '{{ trans("mining-manager::ledger.job_requeued") }}');
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::ledger.retry_failed") }}');
            }
        });
    });

    // View Log
    $('.view-log').on('click', function() {
        const jobId = $(this).data('job-id');
        
        $.ajax({
            url: '{{ route("mining-manager.ledger.job-log", ":id") }}'.replace(':id', jobId),
            method: 'GET',
            success: function(response) {
                $('#jobLogContent').text(response.log);
                $('#jobLogModal').modal('show');
            },
            error: function(xhr) {
                toastr.error('{{ trans("mining-manager::ledger.log_not_found") }}');
            }
        });
    });

    // Auto-refresh queue every 10 seconds if there are active jobs
    @if(($stats['pending'] ?? 0) > 0 || ($stats['processing'] ?? 0) > 0)
    setInterval(function() {
        location.reload();
    }, 10000);
    @endif
});
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
