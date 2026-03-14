@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.mining_events'))
@section('page_header', trans('mining-manager::menu.mining_events'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard events-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events') && !Request::is('*/events/*') ? 'active' : '' }}" href="{{ route('mining-manager.events.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_events') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events/active') ? 'active' : '' }}" href="{{ route('mining-manager.events.active') }}">
                    <i class="fas fa-play-circle"></i> {{ trans('mining-manager::menu.active_events') }}
                </a>
            </li>
            @can('mining-manager.director')
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events/create') ? 'active' : '' }}" href="{{ route('mining-manager.events.create') }}">
                    <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::menu.create_event') }}
                </a>
            </li>
            @endcan
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events/calendar') ? 'active' : '' }}" href="{{ route('mining-manager.events.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.event_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/events/my-events') ? 'active' : '' }}" href="{{ route('mining-manager.events.my-events') }}">
                    <i class="fas fa-user-check"></i> {{ trans('mining-manager::menu.my_events') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">


<div class="mining-manager-wrapper mining-events">
    
    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::events.event_summary') }}
                    </h3>
                    <div class="card-tools">
                        @can('mining-manager.director')
                        <a href="{{ route('mining-manager.events.create') }}" class="btn btn-sm btn-success">
                            <i class="fas fa-plus"></i> {{ trans('mining-manager::events.create_event') }}
                        </a>
                        @endcan
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Active Events --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon">
                                    <i class="fas fa-play-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::events.active_events') }}</span>
                                    <span class="info-box-number">{{ $stats['active'] ?? 0 }}</span>
                                    <small>{{ trans('mining-manager::events.happening_now') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Upcoming Events --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::events.upcoming_events') }}</span>
                                    <span class="info-box-number">{{ $stats['upcoming'] ?? 0 }}</span>
                                    <small>{{ trans('mining-manager::events.next_7_days') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Participants --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-primary">
                                <span class="info-box-icon">
                                    <i class="fas fa-users"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::events.total_participants') }}</span>
                                    <span class="info-box-number">{{ $stats['participants'] ?? 0 }}</span>
                                    <small>{{ trans('mining-manager::events.active_miners') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Total Mined This Month --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-warning">
                                <span class="info-box-icon">
                                    <i class="fas fa-gem"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::events.total_mined') }}</span>
                                    <span class="info-box-number">{{ number_format($stats['total_value'] ?? 0, 0) }}</span>
                                    <small>ISK {{ trans('mining-manager::events.this_month') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- FILTERS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i>
                        {{ trans('mining-manager::events.filters') }}
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('mining-manager.events.calendar') }}" class="btn btn-sm btn-info">
                            <i class="fas fa-calendar"></i> {{ trans('mining-manager::events.calendar_view') }}
                        </a>
                        <a href="{{ route('mining-manager.events.active') }}" class="btn btn-sm btn-success">
                            <i class="fas fa-broadcast-tower"></i> {{ trans('mining-manager::events.active_now') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <form id="filterForm">
                        <div class="row">
                            {{-- Status Filter --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="statusFilter">{{ trans('mining-manager::events.status') }}</label>
                                    <select class="form-control" id="statusFilter" name="status">
                                        <option value="">{{ trans('mining-manager::events.all_statuses') }}</option>
                                        <option value="planned" {{ request('status') == 'planned' ? 'selected' : '' }}>{{ trans('mining-manager::events.planned') }}</option>
                                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>{{ trans('mining-manager::events.active') }}</option>
                                        <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>{{ trans('mining-manager::events.completed') }}</option>
                                        <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>{{ trans('mining-manager::events.cancelled') }}</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Event Type --}}
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="typeFilter">{{ trans('mining-manager::events.event_type') }}</label>
                                    <select class="form-control" id="typeFilter" name="type">
                                        <option value="">{{ trans('mining-manager::events.all_types') }}</option>
                                        <option value="mining_op" {{ request('type') == 'mining_op' ? 'selected' : '' }}>{{ trans('mining-manager::events.mining_op') }}</option>
                                        <option value="moon_extraction" {{ request('type') == 'moon_extraction' ? 'selected' : '' }}>{{ trans('mining-manager::events.moon_extraction') }}</option>
                                        <option value="ice_mining" {{ request('type') == 'ice_mining' ? 'selected' : '' }}>{{ trans('mining-manager::events.ice_mining') }}</option>
                                        <option value="gas_huffing" {{ request('type') == 'gas_huffing' ? 'selected' : '' }}>{{ trans('mining-manager::events.gas_huffing') }}</option>
                                        <option value="special" {{ request('type') == 'special' ? 'selected' : '' }}>{{ trans('mining-manager::events.special') }}</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Date Range --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{ trans('mining-manager::events.date_range') }}</label>
                                    <div class="input-group">
                                        <input type="date" class="form-control" name="date_from" value="{{ request('date_from', now()->format('Y-m-d')) }}">
                                        <div class="input-group-prepend input-group-append">
                                            <span class="input-group-text">{{ trans('mining-manager::events.to') }}</span>
                                        </div>
                                        <input type="date" class="form-control" name="date_to" value="{{ request('date_to', now()->addDays(30)->format('Y-m-d')) }}">
                                    </div>
                                </div>
                            </div>

                            {{-- Apply Button --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="fas fa-search"></i> {{ trans('mining-manager::events.apply') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- EVENT LIST --}}
    <div class="row">
        @forelse($events as $event)
        <div class="col-md-6 col-lg-4">
            <div class="card card-dark card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-{{ $event->icon ?? 'calendar' }}"></i>
                        {{ $event->name }}
                    </h3>
                    <div class="card-tools">
                        @switch($event->status)
                            @case('planned')
                                <span class="badge badge-info">{{ trans('mining-manager::events.planned') }}</span>
                                @break
                            @case('active')
                                <span class="badge badge-success">
                                    <i class="fas fa-circle pulse"></i> {{ trans('mining-manager::events.active') }}
                                </span>
                                @break
                            @case('completed')
                                <span class="badge badge-secondary">{{ trans('mining-manager::events.completed') }}</span>
                                @break
                            @case('cancelled')
                                <span class="badge badge-danger">{{ trans('mining-manager::events.cancelled') }}</span>
                                @break
                        @endswitch
                    </div>
                </div>
                <div class="card-body">
                    {{-- Event Type Badge --}}
                    <div class="mb-2">
                        <span class="badge badge-primary">
                            {{ $event->getTypeLabel() }}
                        </span>
                        @if($event->is_mandatory)
                            <span class="badge badge-warning">
                                <i class="fas fa-exclamation-circle"></i> {{ trans('mining-manager::events.mandatory') }}
                            </span>
                        @endif
                    </div>

                    {{-- Description --}}
                    <p class="text-muted">
                        {{ Str::limit($event->description, 100) }}
                    </p>

                    {{-- Event Details --}}
                    <div class="event-details">
                        <p class="mb-1">
                            <i class="fas fa-calendar-alt text-info"></i>
                            <strong>{{ trans('mining-manager::events.starts') }}:</strong>
                            {{ $event->start_time->format('M d, Y H:i') }}
                        </p>
                        @if($event->end_time)
                        <p class="mb-1">
                            <i class="fas fa-clock text-warning"></i>
                            <strong>{{ trans('mining-manager::events.duration') }}:</strong>
                            {{ $event->start_time->diffForHumans($event->end_time, true) }}
                        </p>
                        @endif
                        <p class="mb-1">
                            <i class="fas fa-users text-success"></i>
                            <strong>{{ trans('mining-manager::events.participants') }}:</strong>
                            {{ $event->participants_count ?? 0 }} / {{ $event->max_participants ?? '∞' }}
                        </p>
                        @if($event->getLocationName())
                        <p class="mb-1">
                            <i class="fas fa-map-marker-alt text-danger"></i>
                            <strong>{{ trans('mining-manager::events.location') }}:</strong>
                            {{ $event->getLocationName() }}
                        </p>
                        @elseif($event->location_scope !== 'any')
                        <p class="mb-1">
                            <i class="fas fa-globe text-info"></i>
                            <strong>{{ trans('mining-manager::events.location') }}:</strong>
                            {{ $event->getLocationScopeLabel() }}
                        </p>
                        @endif
                    </div>

                    {{-- Tax Bonus/Penalty --}}
                    @if($event->tax_modifier != 0)
                    <div class="alert alert-{{ $event->tax_modifier < 0 ? 'success' : 'warning' }} mt-2 mb-0">
                        <i class="fas fa-percentage"></i>
                        <strong>{{ trans('mining-manager::events.tax_modifier') }}:</strong>
                        {{ $event->tax_modifier > 0 ? '+' : '' }}{{ $event->tax_modifier }}%
                    </div>
                    @endif
                </div>
                <div class="card-footer">
                    <div class="row">
                        <div class="col-6">
                            <a href="{{ route('mining-manager.events.show', $event->id) }}" class="btn btn-info btn-block btn-sm">
                                <i class="fas fa-eye"></i> {{ trans('mining-manager::events.view') }}
                            </a>
                        </div>
                        <div class="col-6">
                            @if($event->status === 'planned' || $event->status === 'active')
                                @if($event->isParticipating(auth()->user()))
                                    <button class="btn btn-danger btn-block btn-sm leave-event" data-event-id="{{ $event->id }}">
                                        <i class="fas fa-sign-out-alt"></i> {{ trans('mining-manager::events.leave') }}
                                    </button>
                                @else
                                    <button class="btn btn-success btn-block btn-sm join-event" data-event-id="{{ $event->id }}">
                                        <i class="fas fa-sign-in-alt"></i> {{ trans('mining-manager::events.join') }}
                                    </button>
                                @endif
                            @else
                                <a href="{{ route('mining-manager.events.show', $event->id) }}" class="btn btn-secondary btn-block btn-sm">
                                    <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::events.results') }}
                                </a>
                            @endif
                        </div>
                    </div>
                    @can('mining-manager.director')
                    <div class="row mt-2">
                        <div class="col-6">
                            <a href="{{ route('mining-manager.events.edit', $event->id) }}" class="btn btn-warning btn-block btn-sm">
                                <i class="fas fa-edit"></i> {{ trans('mining-manager::events.edit') }}
                            </a>
                        </div>
                        <div class="col-6">
                            <button class="btn btn-danger btn-block btn-sm delete-event" data-event-id="{{ $event->id }}">
                                <i class="fas fa-trash"></i> {{ trans('mining-manager::events.delete') }}
                            </button>
                        </div>
                    </div>
                    @endcan
                </div>
            </div>
        </div>
        @empty
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-body text-center text-muted">
                    <i class="fas fa-calendar-times fa-3x mb-3"></i>
                    <h4>{{ trans('mining-manager::events.no_events') }}</h4>
                    <p>{{ trans('mining-manager::events.no_events_description') }}</p>
                    @can('mining-manager.director')
                    <a href="{{ route('mining-manager.events.create') }}" class="btn btn-success">
                        <i class="fas fa-plus"></i> {{ trans('mining-manager::events.create_first_event') }}
                    </a>
                    @endcan
                </div>
            </div>
        </div>
        @endforelse
    </div>

    {{-- PAGINATION --}}
    @if($events->hasPages())
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-body">
                    {{ $events->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Filter form submission
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        window.location.href = '{{ route("mining-manager.events.index") }}?' + formData;
    });

    // Join event
    $('.join-event').on('click', function() {
        const eventId = $(this).data('event-id');
        const btn = $(this);
        
        $.ajax({
            url: '{{ route("mining-manager.events.join", ":id") }}'.replace(':id', eventId),
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success(response.message || '{{ trans("mining-manager::events.joined_success") }}');
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::events.join_failed") }}');
            }
        });
    });

    // Leave event
    $('.leave-event').on('click', function() {
        const eventId = $(this).data('event-id');
        
        if (confirm('{{ trans("mining-manager::events.confirm_leave") }}')) {
            $.ajax({
                url: '{{ route("mining-manager.events.leave", ":id") }}'.replace(':id', eventId),
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::events.left_success") }}');
                    setTimeout(() => location.reload(), 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::events.leave_failed") }}');
                }
            });
        }
    });

    // Delete event
    $('.delete-event').on('click', function() {
        const eventId = $(this).data('event-id');
        
        if (confirm('{{ trans("mining-manager::events.confirm_delete") }}')) {
            $.ajax({
                url: '{{ route("mining-manager.events.destroy", ":id") }}'.replace(':id', eventId),
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::events.deleted_success") }}');
                    setTimeout(() => location.reload(), 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::events.delete_failed") }}');
                }
            });
        }
    });
});

// Pulse animation for active events
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.pulse {
    animation: pulse 2s infinite;
}
</script>

<style>
.event-details p {
    font-size: 0.9rem;
}

.card-dark.card-outline {
    border-top: 3px solid #667eea;
}

.card-dark.card-outline:hover {
    box-shadow: 0 0 15px rgba(102, 126, 234, 0.3);
    transition: box-shadow 0.3s ease;
}
</style>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
