@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::reports.view_report'))
@section('page_header', trans('mining-manager::menu.reports'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
<style>
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

    .meta-item {
        display: inline-flex;
        align-items: center;
        margin-right: 20px;
        margin-bottom: 5px;
    }

    .meta-item i {
        margin-right: 6px;
        opacity: 0.7;
    }

    .tax-summary-card {
        border-left: 4px solid #f6c23e;
    }

    .tax-summary-card .tax-total {
        font-size: 1.8rem;
        font-weight: bold;
        color: #f6c23e;
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard reports-page">

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

<div class="report-show">

    {{-- BACK LINK --}}
    <div class="mb-3">
        <a href="{{ route('mining-manager.reports.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> {{ trans('mining-manager::reports.back_to_reports') }}
        </a>
    </div>

    {{-- REPORT METADATA --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-file-alt"></i>
                {{ trans('mining-manager::reports.report_details') }}
            </h3>
            <div class="card-tools">
                @if(isset($webhooks) && $webhooks->count() > 0)
                <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#sendDiscordModal">
                    <i class="fab fa-discord"></i> Send to Discord
                </button>
                @endif
                <a href="{{ route('mining-manager.reports.download', $report->id) }}" class="btn btn-sm btn-success">
                    <i class="fas fa-download"></i> {{ trans('mining-manager::reports.download') }}
                </a>
                <button type="button" class="btn btn-sm btn-danger delete-report" data-report-id="{{ $report->id }}">
                    <i class="fas fa-trash"></i> {{ trans('mining-manager::reports.delete') }}
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center">
                <div class="meta-item">
                    <span class="report-type-badge badge-{{ $report->report_type }}">
                        <i class="fas fa-calendar-{{ $report->report_type === 'daily' ? 'day' : ($report->report_type === 'weekly' ? 'week' : 'alt') }}"></i>
                        {{ trans('mining-manager::reports.' . $report->report_type) }}
                    </span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-calendar-check text-success"></i>
                    {{ $report->start_date->format('M d, Y') }} &mdash; {{ $report->end_date->format('M d, Y') }}
                    <small class="text-muted ml-1">({{ $report->start_date->diffInDays($report->end_date) }} {{ trans('mining-manager::reports.days') }})</small>
                </div>
                <div class="meta-item">
                    <span class="format-badge format-{{ $report->format }}">{{ strtoupper($report->format) }}</span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-clock text-info"></i>
                    {{ $report->generated_at->format('M d, Y H:i') }}
                    <small class="text-muted ml-1">({{ $report->generated_at->diffForHumans() }})</small>
                </div>
                <div class="meta-item">
                    @if($report->fileExists())
                    <span class="badge badge-success">
                        <i class="fas fa-check-circle"></i>
                        {{ $report->getHumanFileSize() ?? number_format(($report->getFileSize() ?? 0) / 1024, 2) . ' KB' }}
                    </span>
                    @else
                    <span class="badge badge-secondary">
                        <i class="fas fa-database"></i>
                        {{ trans('mining-manager::reports.in_database') }}
                    </span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($reportData)

    {{-- SUMMARY STATS --}}
    @if(isset($reportData['summary']))
    <div class="row mb-3">
        <div class="col-md-4">
            <div class="stats-card">
                <h3>{{ number_format($reportData['summary']['total_quantity'] ?? 0) }}</h3>
                <p>{{ trans('mining-manager::reports.total_quantity') }}</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card" style="background: linear-gradient(135deg, #1cc88a 0%, #17a673 100%);">
                <h3>{{ number_format($reportData['summary']['total_value'] ?? 0, 2) }}</h3>
                <p>{{ trans('mining-manager::reports.total_value_isk') }}</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card" style="background: linear-gradient(135deg, #f6c23e 0%, #dda20a 100%);">
                <h3>{{ number_format($reportData['summary']['unique_miners'] ?? 0) }}</h3>
                <p>{{ trans('mining-manager::reports.unique_miners') }}</p>
            </div>
        </div>
    </div>
    @endif

    {{-- TOP MINERS TABLE --}}
    @if(!empty($reportData['miners']['top_miners']))
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::reports.top_miners') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ trans('mining-manager::reports.miner_name') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::reports.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::reports.value_isk') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::reports.percentage') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($reportData['miners']['top_miners'] as $index => $miner)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <i class="fas fa-user text-primary"></i>
                                        {{ $miner['name'] ?? trans('mining-manager::reports.unknown') }}
                                    </td>
                                    <td class="text-right">{{ number_format($miner['quantity'] ?? 0) }}</td>
                                    <td class="text-right">{{ number_format($miner['value'] ?? 0, 2) }} ISK</td>
                                    <td class="text-right">
                                        @if(isset($miner['percentage']))
                                        <span class="badge badge-info">{{ number_format($miner['percentage'], 1) }}%</span>
                                        @else
                                        <span class="text-muted">-</span>
                                        @endif
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
    @endif

    {{-- ORE TYPES & SYSTEMS --}}
    <div class="row mb-3">
        {{-- ORE TYPE BREAKDOWN --}}
        @if(!empty($reportData['ore_types']))
        <div class="col-md-6">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-gem"></i>
                        {{ trans('mining-manager::reports.ore_breakdown') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::reports.ore_type') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::reports.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::reports.value_isk') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($reportData['ore_types'] as $ore)
                                <tr>
                                    <td>
                                        <i class="fas fa-cube text-success"></i>
                                        {{ $ore['name'] ?? trans('mining-manager::reports.unknown') }}
                                    </td>
                                    <td class="text-right">{{ number_format($ore['quantity'] ?? 0) }}</td>
                                    <td class="text-right">{{ number_format($ore['value'] ?? 0, 2) }} ISK</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- SYSTEM BREAKDOWN --}}
        @if(!empty($reportData['systems']))
        <div class="col-md-6">
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-globe"></i>
                        {{ trans('mining-manager::reports.system_breakdown') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::reports.system_name') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::reports.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::reports.value_isk') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($reportData['systems'] as $system)
                                <tr>
                                    <td>
                                        <i class="fas fa-map-marker-alt text-info"></i>
                                        {{ $system['name'] ?? trans('mining-manager::reports.unknown') }}
                                    </td>
                                    <td class="text-right">{{ number_format($system['quantity'] ?? 0) }}</td>
                                    <td class="text-right">{{ number_format($system['value'] ?? 0, 2) }} ISK</td>
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

    {{-- TAX SUMMARY --}}
    @if(!empty($reportData['taxes']))
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="card tax-summary-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-percentage"></i>
                        {{ trans('mining-manager::reports.tax_summary') }}
                        @if($reportData['taxes']['is_current_month'] ?? false)
                            <span class="badge badge-warning ml-2">Estimated</span>
                        @else
                            <span class="badge badge-success ml-2">Final</span>
                        @endif
                    </h3>
                </div>
                <div class="card-body">
                    {{-- Estimated tax from daily summaries --}}
                    @if(isset($reportData['taxes']['estimated_tax']))
                    <div class="tax-total mb-3">
                        {{ number_format($reportData['taxes']['estimated_tax'], 2) }} ISK
                        <small class="d-block text-muted mt-1">
                            @if($reportData['taxes']['is_current_month'] ?? false)
                                Estimated Tax (month in progress)
                            @else
                                Total Tax
                            @endif
                        </small>
                    </div>
                    @endif

                    <table class="table table-sm mb-0">
                        @if(isset($reportData['taxes']['total_owed']) && $reportData['taxes']['total_owed'] > 0)
                        <tr>
                            <td><i class="fas fa-file-invoice-dollar text-warning"></i> Total Owed</td>
                            <td class="text-right">{{ number_format($reportData['taxes']['total_owed'], 2) }} ISK</td>
                        </tr>
                        @endif
                        @if(isset($reportData['taxes']['total_paid']))
                        <tr>
                            <td><i class="fas fa-check-circle text-success"></i> {{ trans('mining-manager::reports.total_paid') }}</td>
                            <td class="text-right">{{ number_format($reportData['taxes']['total_paid'], 2) }} ISK</td>
                        </tr>
                        @endif
                        @if(isset($reportData['taxes']['unpaid']) && $reportData['taxes']['unpaid'] > 0)
                        <tr>
                            <td><i class="fas fa-exclamation-circle text-danger"></i> {{ trans('mining-manager::reports.total_outstanding') }}</td>
                            <td class="text-right text-danger font-weight-bold">{{ number_format($reportData['taxes']['unpaid'], 2) }} ISK</td>
                        </tr>
                        @endif
                        @if(isset($reportData['taxes']['collection_rate']) && !($reportData['taxes']['is_current_month'] ?? false))
                        <tr>
                            <td><i class="fas fa-chart-pie text-info"></i> Collection Rate</td>
                            <td class="text-right">
                                <span class="badge badge-{{ $reportData['taxes']['collection_rate'] >= 80 ? 'success' : ($reportData['taxes']['collection_rate'] >= 50 ? 'warning' : 'danger') }}">
                                    {{ number_format($reportData['taxes']['collection_rate'], 1) }}%
                                </span>
                            </td>
                        </tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

    @else
    {{-- NO DATA --}}
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
            <h4>{{ trans('mining-manager::reports.no_report_data') }}</h4>
            <p class="text-muted">{{ trans('mining-manager::reports.no_report_data_description') }}</p>
        </div>
    </div>
    @endif

    {{-- BACK LINK (bottom) --}}
    <div class="mt-3">
        <a href="{{ route('mining-manager.reports.index') }}" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> {{ trans('mining-manager::reports.back_to_reports') }}
        </a>
    </div>

</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Delete report
    $('.delete-report').on('click', function() {
        const reportId = $(this).data('report-id');

        if (!confirm('{{ trans("mining-manager::reports.confirm_delete") }}')) {
            return;
        }

        $.ajax({
            url: '{{ route("mining-manager.reports.destroy", $report->id) }}',
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success('{{ trans("mining-manager::reports.report_deleted") }}');
                setTimeout(function() {
                    window.location.href = '{{ route("mining-manager.reports.index") }}';
                }, 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::reports.error_deleting") }}');
            }
        });
    });

    // Send to Discord via modal
    $('#sendDiscordBtn').on('click', function() {
        const webhookId = $('#discord_webhook_select').val();
        const reportId = {{ $report->id }};
        const $btn = $(this);

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');

        $.ajax({
            url: '{{ route("mining-manager.reports.send-discord", $report->id) }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            data: { webhook_id: webhookId },
            success: function(response) {
                toastr.success(response.message || 'Report sent to Discord');
                $btn.prop('disabled', false).html('<i class="fab fa-discord"></i> Send');
                $('#sendDiscordModal').modal('hide');
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || 'Failed to send to Discord');
                $btn.prop('disabled', false).html('<i class="fab fa-discord"></i> Send');
            }
        });
    });
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}

{{-- SEND TO DISCORD MODAL --}}
@if(isset($webhooks) && $webhooks->count() > 0)
<div class="modal fade" id="sendDiscordModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title">
                    <i class="fab fa-discord"></i> Send Report to Discord
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="discord_webhook_select">Select Webhook</label>
                    <select class="form-control" id="discord_webhook_select">
                        @foreach($webhooks as $webhook)
                        <option value="{{ $webhook->id }}">
                            {{ $webhook->name }}
                            ({{ $webhook->type }})
                        </option>
                        @endforeach
                    </select>
                </div>
                <p class="text-muted mb-0">
                    <i class="fas fa-info-circle"></i>
                    The report summary will be sent as an embed to the selected webhook.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="sendDiscordBtn">
                    <i class="fab fa-discord"></i> Send
                </button>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
