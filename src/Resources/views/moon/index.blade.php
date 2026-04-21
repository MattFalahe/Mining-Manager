@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.moon_mining'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard moon-index-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon') && !Request::is('*/moon/*') ? 'active' : '' }}" href="{{ route('mining-manager.moon.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/active') ? 'active' : '' }}" href="{{ route('mining-manager.moon.active') }}">
                    <i class="fas fa-hourglass-half"></i> {{ trans('mining-manager::menu.active_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/calendar') ? 'active' : '' }}" href="{{ route('mining-manager.moon.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.extraction_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/compositions') ? 'active' : '' }}" href="{{ route('mining-manager.moon.compositions') }}">
                    <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::menu.moon_compositions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/calculator') ? 'active' : '' }}" href="{{ route('mining-manager.moon.calculator') }}">
                    <i class="fas fa-flask"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">


<div class="mining-manager-wrapper moon-extractions">
    
    {{-- QUICK STATS --}}
    <div class="row">
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon">
                    <i class="fas fa-circle-notch fa-spin"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.extracting') }}</span>
                    <span class="info-box-number">{{ $stats['extracting'] ?? 0 }}</span>
                    <small>{{ trans('mining-manager::moons.active_extractions') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon">
                    <i class="fas fa-gem"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.ready') }}</span>
                    <span class="info-box-number">{{ $stats['ready'] ?? 0 }}</span>
                    <small>{{ trans('mining-manager::moons.ready_to_mine') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon">
                    <i class="fas fa-clock"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.upcoming_7d') }}</span>
                    <span class="info-box-number">{{ $upcoming->count() }}</span>
                    <small>{{ trans('mining-manager::moons.next_week') }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-secondary">
                <span class="info-box-icon">
                    <i class="fas fa-check-circle"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::moons.completed') }}</span>
                    <span class="info-box-number">{{ $stats['completed'] ?? 0 }}</span>
                    <small>{{ trans('mining-manager::moons.this_month') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- UPCOMING EXTRACTIONS (NEXT 7 DAYS) --}}
    @if($upcoming->count() > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-week"></i>
                        {{ trans('mining-manager::moons.upcoming_extractions') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-warning">{{ $upcoming->count() }} {{ trans('mining-manager::moons.upcoming') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::moons.structure') }}</th>
                                    <th>{{ trans('mining-manager::moons.moon') }}</th>
                                    <th>{{ trans('mining-manager::moons.chunk_arrival') }}</th>
                                    <th>{{ trans('mining-manager::moons.time_remaining') }}</th>
                                    <th>{{ trans('mining-manager::moons.estimated_value') }}</th>
                                    <th>{{ trans('mining-manager::moons.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($upcoming as $extraction)
                                <tr>
                                    <td>
                                        {{ $extraction->structure_name ?? 'Unknown' }}
                                    </td>
                                    <td>
                                        <i class="fas fa-moon text-info"></i>
                                        {{ $extraction->moon_name ?? 'N/A' }}
                                    </td>
                                    <td>
                                        {{ $extraction->chunk_arrival_time->format('M d, Y H:i') }}
                                    </td>
                                    <td>
                                        <span class="countdown text-warning">
                                            {{ $extraction->chunk_arrival_time->diffForHumans() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($extraction->ore_composition)
                                            <span class="text-success">~{{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK</span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('mining-manager.moon.show', $extraction->id) }}" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
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

    {{-- FILTERS & ACTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i>
                        {{ trans('mining-manager::moons.filters') }}
                    </h3>
                    <div class="card-tools">
                        @can('mining-manager.director')
                        <form action="{{ route('mining-manager.moon.refresh-all') }}" method="POST" style="display: inline;">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::moons.refresh_all') }}
                            </button>
                        </form>
                        @endcan
                        <a href="{{ route('mining-manager.moon.calendar') }}" class="btn btn-sm btn-info">
                            <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::moons.calendar_view') }}
                        </a>
                        <a href="{{ route('mining-manager.moon.compositions') }}" class="btn btn-sm btn-success">
                            <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::moons.compositions') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <form method="GET" action="{{ route('mining-manager.moon.index') }}">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{ trans('mining-manager::moons.status') }}</label>
                                    <select name="status" class="form-control">
                                        <option value="all" {{ $status === 'all' ? 'selected' : '' }}>{{ trans('mining-manager::moons.all') }}</option>
                                        <option value="extracting" {{ $status === 'extracting' ? 'selected' : '' }}>{{ trans('mining-manager::moons.extracting') }}</option>
                                        <option value="ready" {{ $status === 'ready' ? 'selected' : '' }}>{{ trans('mining-manager::moons.ready') }}</option>
                                        <option value="completed" {{ $status === 'completed' ? 'selected' : '' }}>{{ trans('mining-manager::moons.completed') }} ({{ trans('mining-manager::moons.past') }})</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i> {{ trans('mining-manager::moons.filter') }}
                                        </button>
                                        <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> {{ trans('mining-manager::moons.clear') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ALL EXTRACTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        {{ trans('mining-manager::moons.all_extractions') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info">{{ $extractions->total() }} {{ trans('mining-manager::moons.total') }}</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    @forelse($extractions as $extraction)
                    <div class="extraction-card card mb-2 status-{{ $extraction->status }}">
                        <div class="card-body">
                            <div class="row align-items-center">
                                {{-- Structure & Moon Info --}}
                                <div class="col-md-4">
                                    <h5 class="mb-1">
                                        <i class="fas fa-building text-primary"></i>
                                        {{ $extraction->structure_name ?? 'Unknown Structure' }}
                                    </h5>
                                    <p class="mb-0 text-muted">
                                        <i class="fas fa-moon text-info"></i>
                                        {{ $extraction->moon_name ?? 'Unknown Moon' }}
                                    </p>
                                </div>

                                {{-- Status & Times --}}
                                <div class="col-md-4">
                                    <div class="mb-2">
                                        @php $effectiveStatus = $extraction->getEffectiveStatus(); @endphp
                                        <span class="status-badge badge badge-{{
                                            $effectiveStatus === 'extracting' ? 'warning' :
                                            ($effectiveStatus === 'ready' ? 'success' :
                                            ($effectiveStatus === 'unstable' ? 'warning mm-badge-unstable' :
                                            ($effectiveStatus === 'expired' ? 'dark' : 'secondary')))
                                        }}">
                                            {{ trans('mining-manager::moons.' . $effectiveStatus) }}
                                        </span>
                                        @if($extraction->is_jackpot)
                                            <span class="badge badge-warning ml-1" style="background: linear-gradient(45deg, #ffd700, #ffed4e); color: #000;">
                                                <i class="fas fa-star"></i> JACKPOT
                                                @if($extraction->jackpot_verified === true)
                                                    <i class="fas fa-check-circle ml-1" title="Verified by mining data"></i>
                                                @elseif($extraction->jackpot_verified === false)
                                                    <i class="fas fa-times-circle ml-1" style="color: #c00;" title="Could not verify"></i>
                                                @elseif($extraction->jackpot_reported_by)
                                                    <i class="fas fa-hourglass-half ml-1" title="Awaiting verification"></i>
                                                @endif
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mb-1">
                                        <strong>{{ trans('mining-manager::moons.chunk_arrival') }}:</strong><br>
                                        {{ $extraction->chunk_arrival_time->format('M d, Y H:i') }}
                                        @if($extraction->chunk_arrival_time->isFuture())
                                            <span class="text-warning">({{ $extraction->chunk_arrival_time->diffForHumans() }})</span>
                                        @endif
                                    </p>
                                    @if($extraction->fractured_at)
                                    <p class="mb-0 text-muted small">
                                        <strong>Fractured:</strong> {{ $extraction->fractured_at->format('M d, H:i') }}
                                        @if($extraction->fractured_by)
                                            by {{ $extraction->fractured_by }}
                                        @elseif($extraction->auto_fractured)
                                            (auto)
                                        @endif
                                        @if($extraction->getTimeUntilUnstable())
                                            &mdash; Mining: {{ $extraction->getTimeUntilUnstable() }} left
                                        @endif
                                        @if($extraction->isUnstable())
                                            <span class="badge badge-warning ml-1">{{ trans('mining-manager::moons.unstable') }}</span>
                                        @endif
                                    </p>
                                    @elseif($extraction->natural_decay_time)
                                    <p class="mb-0 text-muted small">
                                        <strong>Expires:</strong> {{ $extraction->getExpiryTime() ? $extraction->getExpiryTime()->format('M d, H:i') : $extraction->natural_decay_time->format('M d, H:i') }}
                                        @if($extraction->isUnstable())
                                            <span class="badge badge-warning ml-1">{{ trans('mining-manager::moons.unstable') }}</span>
                                        @endif
                                    </p>
                                    @endif
                                </div>

                                {{-- Value & Actions --}}
                                <div class="col-md-4 text-right">
                                    @if($extraction->ore_composition)
                                        <h4 class="mb-2 text-success">
                                            ~{{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK
                                        </h4>
                                        <p class="mb-2 text-muted small">{{ trans('mining-manager::moons.estimated_value') }}</p>
                                    @else
                                        <p class="mb-2 text-muted">{{ trans('mining-manager::moons.no_composition_data') }}</p>
                                    @endif
                                    
                                    <div class="btn-group">
                                        <a href="{{ route('mining-manager.moon.show', $extraction->id) }}" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i> {{ trans('mining-manager::moons.details') }}
                                        </a>
                                        @can('mining-manager.director')
                                        <form action="{{ route('mining-manager.moon.update', $extraction->id) }}" method="POST" style="display: inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::moons.update') }}
                                            </button>
                                        </form>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-moon fa-3x mb-3"></i>
                        <h4>{{ trans('mining-manager::moons.no_extractions') }}</h4>
                        <p>{{ trans('mining-manager::moons.no_extractions_message') }}</p>
                    </div>
                    @endforelse
                </div>
                @if($extractions->hasPages())
                <div class="card-footer">
                    {{ $extractions->appends(request()->query())->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ARCHIVED PAST EXTRACTIONS (from history table) --}}
    @if(isset($historyExtractions) && $historyExtractions->count() > 0)
    <div class="row">
        <div class="col-12">
            <div class="card card-secondary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-archive"></i>
                        {{ trans('mining-manager::moons.past_extractions') }} (Archived)
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-secondary">{{ $historyExtractions->count() }} archived</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="archivedHistoryTable" class="table table-dark table-striped table-hover mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::moons.moon') }}</th>
                                    <th>Structure</th>
                                    <th>{{ trans('mining-manager::moons.chunk_arrival') }}</th>
                                    <th>{{ trans('mining-manager::moons.status') }}</th>
                                    <th class="text-right">Value at Arrival</th>
                                    <th class="text-right">Actual Mined</th>
                                    <th class="text-center">Completion</th>
                                    <th>Archived</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($historyExtractions as $history)
                                <tr>
                                    <td>
                                        <i class="fas fa-moon text-secondary"></i>
                                        {{ $history->moon_name ?? 'Unknown Moon' }}
                                    </td>
                                    <td>
                                        <i class="fas fa-industry text-muted"></i>
                                        {{ $history->structure_name ?? ('Structure ' . $history->structure_id) }}
                                    </td>
                                    <td data-order="{{ $history->chunk_arrival_time->timestamp }}">
                                        {{ $history->chunk_arrival_time->format('M d, Y H:i') }}
                                    </td>
                                    <td>
                                        @if($history->final_status === 'cancelled')
                                            <span class="badge badge-dark" title="Director cancelled this extraction before chunk arrival">
                                                <i class="fas fa-ban"></i> Cancelled
                                            </span>
                                        @else
                                            <span class="badge badge-{{
                                                $history->final_status === 'fractured' ? 'success' :
                                                ($history->final_status === 'expired' ? 'dark' : 'secondary')
                                            }}">
                                                {{ ucfirst($history->final_status) }}
                                            </span>
                                        @endif
                                        @if($history->is_jackpot)
                                            <span class="badge badge-warning ml-1" style="background: linear-gradient(45deg, #ffd700, #ffed4e); color: #000;">
                                                <i class="fas fa-star"></i> JACKPOT
                                            </span>
                                        @endif
                                    </td>
                                    @php
                                        // Prefer arrival-time snapshot; fall back to archive-time value; else N/A
                                        $arrivalValue = $history->estimated_value_at_arrival
                                            ?? $history->final_estimated_value
                                            ?? null;
                                    @endphp
                                    <td class="text-right" data-order="{{ $arrivalValue ?? 0 }}">
                                        @if($arrivalValue)
                                            <span class="text-success">{{ number_format($arrivalValue, 0) }} ISK</span>
                                        @else
                                            <span class="text-muted" title="Historical price data unavailable for this backfilled row">N/A</span>
                                        @endif
                                    </td>
                                    <td class="text-right" data-order="{{ $history->actual_mined_value ?? 0 }}">
                                        @if($history->actual_mined_value)
                                            <span class="text-info">{{ number_format($history->actual_mined_value, 0) }} ISK</span>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td class="text-center" data-order="{{ $history->completion_percentage ?? 0 }}">
                                        @if($history->completion_percentage > 0)
                                            <div class="mm-progress-wrap">
                                                <div class="progress" style="height: 18px; min-width: 60px;">
                                                    <div class="progress-bar bg-{{ $history->completion_percentage >= 80 ? 'success' : ($history->completion_percentage >= 50 ? 'warning' : 'danger') }}"
                                                         style="width: {{ min($history->completion_percentage, 100) }}%"></div>
                                                </div>
                                                <span class="mm-pct-label">{{ number_format($history->completion_percentage, 0) }}%</span>
                                            </div>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td data-order="{{ $history->archived_at ? $history->archived_at->timestamp : 0 }}">
                                        <small class="text-muted">{{ $history->archived_at ? $history->archived_at->diffForHumans() : 'N/A' }}</small>
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

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/jquery.dataTables.min.js') }}"></script>
<script>
// Auto-refresh countdown timers
setInterval(function() {
    $('.countdown').each(function() {
        // Simple refresh - in production you might want to update via AJAX
    });
}, 60000); // Update every minute

$(document).ready(function() {
    // Past Extractions (Archived) — DataTables with sorting + filtering
    if ($('#archivedHistoryTable tbody tr').length > 0) {
        var archivedTable = $('#archivedHistoryTable').DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            order: [[2, 'desc']],  // chunk arrival, newest first
            dom: '<"row"<"col-md-4"l><"col-md-4 text-center"<"archivedStatusFilter">><"col-md-4"f>>tip',
            language: {
                search: 'Search all columns:',
                lengthMenu: 'Show _MENU_ per page',
                info: 'Showing _START_ to _END_ of _TOTAL_ archived extractions',
                infoEmpty: 'No archived extractions found',
                infoFiltered: '(filtered from _MAX_ total)',
                paginate: { first: 'First', last: 'Last', next: 'Next', previous: 'Previous' }
            },
            columnDefs: [
                { orderable: false, targets: [6] },  // completion bar not sortable by visual, uses data-order
            ]
        });

        // Build and inject a Status filter dropdown
        var statuses = archivedTable.column(3).data().unique().sort().toArray();
        var cleanStatuses = [];
        statuses.forEach(function(html) {
            // Extract status name from badge HTML (Cancelled, Fractured, Expired)
            var match = html.match(/>\s*([A-Za-z]+)\s*</);
            if (match && cleanStatuses.indexOf(match[1]) === -1) {
                cleanStatuses.push(match[1]);
            }
        });

        var statusSelectHtml = '<div class="d-inline-block"><label class="mr-2">Status:</label>' +
            '<select id="archivedStatusSelect" class="form-control form-control-sm d-inline-block" style="width:auto;">' +
            '<option value="">All statuses</option>';
        cleanStatuses.forEach(function(s) {
            statusSelectHtml += '<option value="' + s + '">' + s + '</option>';
        });
        statusSelectHtml += '</select></div>';

        $('.archivedStatusFilter').html(statusSelectHtml);

        $('#archivedStatusSelect').on('change', function() {
            var val = $(this).val();
            // Regex-match the badge text content; empty value shows all
            archivedTable.column(3).search(val ? val : '', true, false).draw();
        });
    }
});
</script>
@endpush

    </div>
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
