@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.my_events'))
@section('page_header', trans('mining-manager::menu.mining_events'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v={{ time() }}">
<style>
    .event-card {
        transition: transform 0.2s, box-shadow 0.2s;
        border-left: 4px solid;
    }
    .event-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
    }
    .event-card.status-planned {
        border-left-color: #17a2b8;
    }
    .event-card.status-active {
        border-left-color: #28a745;
    }
    .event-card.status-completed {
        border-left-color: #6c757d;
    }
    .stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 8px;
        padding: 20px;
        color: white;
    }
    .stat-card h3 {
        margin: 0;
        font-size: 2rem;
        font-weight: bold;
    }
    .stat-card p {
        margin: 5px 0 0;
        opacity: 0.9;
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard events-my-events-page">

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


<div class="my-events">
    
    {{-- STATISTICS OVERVIEW --}}
    <div class="row">
        {{-- Events Participated --}}
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon">
                    <i class="fas fa-calendar-check"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::events.total_events') }}</span>
                    <span class="info-box-number">{{ $stats['total'] ?? 0 }}</span>
                    <small>{{ trans('mining-manager::events.events_participated') }}</small>
                </div>
            </div>
        </div>

        {{-- Currently Active --}}
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon">
                    <i class="fas fa-broadcast-tower"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::events.active_now') }}</span>
                    <span class="info-box-number">{{ $stats['active'] ?? 0 }}</span>
                    <small>{{ trans('mining-manager::events.mining_right_now') }}</small>
                </div>
            </div>
        </div>

        {{-- Total Mined --}}
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon">
                    <i class="fas fa-gem"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::events.total_mined') }}</span>
                    <span class="info-box-number">{{ number_format($stats['total_mined'] ?? 0, 0) }}</span>
                    <small>ISK</small>
                </div>
            </div>
        </div>

        {{-- Average Per Event --}}
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-primary">
                <span class="info-box-icon">
                    <i class="fas fa-chart-line"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('mining-manager::events.avg_per_event') }}</span>
                    <span class="info-box-number">{{ number_format($stats['avg_per_event'] ?? 0, 0) }}</span>
                    <small>ISK</small>
                </div>
            </div>
        </div>
    </div>

    {{-- FILTER TABS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark card-tabs">
                <div class="card-header p-0 pt-1">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="tab" href="#active-tab" role="tab">
                                <i class="fas fa-broadcast-tower"></i> 
                                {{ trans('mining-manager::events.active') }} 
                                <span class="badge badge-success ml-1">{{ $activeEvents->count() }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#upcoming-tab" role="tab">
                                <i class="fas fa-calendar-alt"></i> 
                                {{ trans('mining-manager::events.upcoming') }}
                                <span class="badge badge-info ml-1">{{ $upcomingEvents->count() }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#completed-tab" role="tab">
                                <i class="fas fa-check-circle"></i> 
                                {{ trans('mining-manager::events.completed') }}
                                <span class="badge badge-secondary ml-1">{{ $completedEvents->count() }}</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        
                        {{-- ACTIVE EVENTS TAB --}}
                        <div class="tab-pane fade show active" id="active-tab" role="tabpanel">
                            @forelse($activeEvents as $event)
                            <div class="card event-card status-active mb-3">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-broadcast-tower text-success pulse"></i>
                                        {{ $event->name }}
                                    </h3>
                                    <div class="card-tools">
                                        <span class="badge badge-success">{{ trans('mining-manager::events.live') }}</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>{{ trans('mining-manager::events.type') }}:</strong> {{ trans('mining-manager::events.' . $event->type) }}</p>
                                            <p><strong>{{ trans('mining-manager::events.started') }}:</strong> {{ $event->start_time->diffForHumans() }}</p>
                                            <p><strong>{{ trans('mining-manager::events.ends') }}:</strong> {{ $event->end_time->format('M d, Y H:i') }}</p>
                                            @if($event->location)
                                            <p><strong>{{ trans('mining-manager::events.location') }}:</strong> {{ $event->location }}</p>
                                            @endif
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>{{ trans('mining-manager::events.participants') }}:</strong> {{ $event->participants->count() }}</p>
                                            <p><strong>{{ trans('mining-manager::events.your_contribution') }}:</strong></p>
                                            @php
                                                $myParticipation = $event->participants->where('character_id', auth()->user()->id)->first();
                                            @endphp
                                            <div class="ml-3">
                                                <p class="mb-1">{{ trans('mining-manager::events.mined') }}: 
                                                    <strong class="text-warning">{{ number_format($myParticipation->total_mined ?? 0, 0) }} ISK</strong>
                                                </p>
                                                <p class="mb-1">{{ trans('mining-manager::events.quantity') }}: 
                                                    <strong>{{ number_format($myParticipation->quantity_mined ?? 0, 0) }}</strong>
                                                </p>
                                                <p class="mb-1">{{ trans('mining-manager::events.tax_modifier') }}:
                                                    <span class="badge badge-{{ $event->tax_modifier < 0 ? 'success' : ($event->tax_modifier > 0 ? 'warning' : 'secondary') }}">
                                                        {{ $event->getTaxModifierLabel() }}
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <div class="btn-group">
                                        <a href="{{ route('mining-manager.events.show', $event->id) }}" class="btn btn-info">
                                            <i class="fas fa-eye"></i> {{ trans('mining-manager::events.view_details') }}
                                        </a>
                                        <button class="btn btn-danger leave-event" data-event-id="{{ $event->id }}">
                                            <i class="fas fa-sign-out-alt"></i> {{ trans('mining-manager::events.leave') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            @empty
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-calendar-times fa-3x mb-3"></i>
                                <h4>{{ trans('mining-manager::events.not_in_active_events') }}</h4>
                                <p>{{ trans('mining-manager::events.join_active_event') }}</p>
                                <a href="{{ route('mining-manager.events.active') }}" class="btn btn-success">
                                    <i class="fas fa-broadcast-tower"></i> {{ trans('mining-manager::events.view_active_events') }}
                                </a>
                            </div>
                            @endforelse
                        </div>

                        {{-- UPCOMING EVENTS TAB --}}
                        <div class="tab-pane fade" id="upcoming-tab" role="tabpanel">
                            @forelse($upcomingEvents as $event)
                            <div class="card event-card status-planned mb-3">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-calendar-alt text-info"></i>
                                        {{ $event->name }}
                                    </h3>
                                    <div class="card-tools">
                                        <span class="badge badge-info">{{ trans('mining-manager::events.upcoming') }}</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>{{ trans('mining-manager::events.type') }}:</strong> {{ trans('mining-manager::events.' . $event->type) }}</p>
                                            <p><strong>{{ trans('mining-manager::events.starts') }}:</strong> {{ $event->start_time->format('M d, Y H:i') }}</p>
                                            <p><strong>{{ trans('mining-manager::events.starts_in') }}:</strong> {{ $event->start_time->diffForHumans() }}</p>
                                            @if($event->location)
                                            <p><strong>{{ trans('mining-manager::events.location') }}:</strong> {{ $event->location }}</p>
                                            @endif
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>{{ trans('mining-manager::events.duration') }}:</strong> 
                                                {{ $event->start_time->diff($event->end_time)->format('%h hours %i minutes') }}
                                            </p>
                                            <p><strong>{{ trans('mining-manager::events.registered_participants') }}:</strong> {{ $event->participants->count() }}</p>
                                            <p><strong>{{ trans('mining-manager::events.tax_modifier') }}:</strong>
                                                <span class="badge badge-{{ $event->tax_modifier < 0 ? 'success' : ($event->tax_modifier > 0 ? 'warning' : 'secondary') }}">
                                                    {{ $event->getTaxModifierLabel() }}
                                                </span>
                                            </p>
                                            @if($event->description)
                                            <p class="text-muted mt-2">{{ Str::limit($event->description, 100) }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <div class="btn-group">
                                        <a href="{{ route('mining-manager.events.show', $event->id) }}" class="btn btn-info">
                                            <i class="fas fa-eye"></i> {{ trans('mining-manager::events.view_details') }}
                                        </a>
                                        <button class="btn btn-danger leave-event" data-event-id="{{ $event->id }}">
                                            <i class="fas fa-user-minus"></i> {{ trans('mining-manager::events.unregister') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            @empty
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-calendar-times fa-3x mb-3"></i>
                                <h4>{{ trans('mining-manager::events.no_upcoming_registrations') }}</h4>
                                <p>{{ trans('mining-manager::events.browse_upcoming') }}</p>
                                <a href="{{ route('mining-manager.events.index') }}" class="btn btn-info">
                                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::events.browse_events') }}
                                </a>
                            </div>
                            @endforelse
                        </div>

                        {{-- COMPLETED EVENTS TAB --}}
                        <div class="tab-pane fade" id="completed-tab" role="tabpanel">
                            @forelse($completedEvents as $event)
                            <div class="card event-card status-completed mb-3">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-check-circle text-secondary"></i>
                                        {{ $event->name }}
                                    </h3>
                                    <div class="card-tools">
                                        <span class="badge badge-secondary">{{ trans('mining-manager::events.completed') }}</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>{{ trans('mining-manager::events.type') }}:</strong> {{ trans('mining-manager::events.' . $event->type) }}</p>
                                            <p><strong>{{ trans('mining-manager::events.completed_on') }}:</strong> {{ $event->end_time->format('M d, Y H:i') }}</p>
                                            <p><strong>{{ trans('mining-manager::events.completed') }}:</strong> {{ $event->end_time->diffForHumans() }}</p>
                                            @if($event->location)
                                            <p><strong>{{ trans('mining-manager::events.location') }}:</strong> {{ $event->location }}</p>
                                            @endif
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>{{ trans('mining-manager::events.total_participants') }}:</strong> {{ $event->participants->count() }}</p>
                                            <p><strong>{{ trans('mining-manager::events.your_performance') }}:</strong></p>
                                            @php
                                                $myParticipation = $event->participants->where('character_id', auth()->user()->id)->first();
                                                $topParticipants = $event->topParticipants(10);
                                                $myRank = $topParticipants->search(function($p) use ($myParticipation) {
                                                    return $p->id === $myParticipation->id;
                                                });
                                            @endphp
                                            <div class="ml-3">
                                                <p class="mb-1">{{ trans('mining-manager::events.mined') }}: 
                                                    <strong class="text-warning">{{ number_format($myParticipation->total_mined ?? 0, 0) }} ISK</strong>
                                                </p>
                                                <p class="mb-1">{{ trans('mining-manager::events.quantity') }}: 
                                                    <strong>{{ number_format($myParticipation->quantity_mined ?? 0, 0) }}</strong>
                                                </p>
                                                @if($myRank !== false)
                                                <p class="mb-1">{{ trans('mining-manager::events.ranking') }}: 
                                                    <span class="badge badge-{{ $myRank < 3 ? 'success' : 'info' }}">
                                                        #{{ $myRank + 1 }}
                                                    </span>
                                                </p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <a href="{{ route('mining-manager.events.show', $event->id) }}" class="btn btn-info">
                                        <i class="fas fa-eye"></i> {{ trans('mining-manager::events.view_details') }}
                                    </a>
                                </div>
                            </div>
                            @empty
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-history fa-3x mb-3"></i>
                                <h4>{{ trans('mining-manager::events.no_completed_events') }}</h4>
                                <p>{{ trans('mining-manager::events.participate_to_see_history') }}</p>
                            </div>
                            @endforelse
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script>
$(document).ready(function() {
    $('.leave-event').on('click', function() {
        const eventId = $(this).data('event-id');
        const confirmMsg = '{{ trans("mining-manager::events.confirm_leave") }}';
        
        if (!confirm(confirmMsg)) return;
        
        $.ajax({
            url: '{{ route("mining-manager.events.leave", ":id") }}'.replace(':id', eventId),
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function(response) {
                toastr.success(response.message);
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::events.error_leaving") }}');
            }
        });
    });
});

// Pulse animation for active events
const style = document.createElement('style');
style.textContent = `
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    .pulse { animation: pulse 2s infinite; }
`;
document.head.appendChild(style);
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
