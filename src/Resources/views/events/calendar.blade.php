@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::events.event_calendar'))
@section('page_header', trans('mining-manager::menu.mining_events'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/vendor/fullcalendar.min.css') }}">
<style>
    .fc {
        background: #343a40;
        color: #fff;
    }
    .fc-theme-standard td, .fc-theme-standard th {
        border-color: #4a5057;
    }
    .fc-theme-standard .fc-scrollgrid {
        border-color: #4a5057;
    }
    .fc .fc-button {
        background-color: #007bff;
        border-color: #007bff;
        color: #fff;
    }
    .fc .fc-button:hover {
        background-color: #0056b3;
        border-color: #0056b3;
    }
    .fc .fc-button-active {
        background-color: #0056b3;
        border-color: #004085;
    }
    .fc-event {
        cursor: pointer;
        border-radius: 3px;
    }
    .fc-event.status-planned {
        background-color: #17a2b8;
        border-color: #17a2b8;
    }
    .fc-event.status-active {
        background-color: #28a745;
        border-color: #28a745;
    }
    .fc-event.status-completed {
        background-color: #6c757d;
        border-color: #6c757d;
    }
    .fc-event.status-cancelled {
        background-color: #dc3545;
        border-color: #dc3545;
    }
    .fc-daygrid-day-number {
        color: #fff !important;
    }
    .fc-col-header-cell-cushion {
        color: #fff !important;
    }
    .fc-toolbar-title {
        color: #fff !important;
    }
    .calendar-legend {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        padding: 15px;
        background: #454d55;
        border-radius: 5px;
        margin-bottom: 15px;
    }
    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .legend-color {
        width: 20px;
        height: 20px;
        border-radius: 3px;
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper events-calendar-page">

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


<div class="event-calendar">
    
    {{-- CALENDAR LEGEND --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-body">
                    <div class="calendar-legend">
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #17a2b8;"></div>
                            <span>{{ trans('mining-manager::events.planned') }}</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #28a745;"></div>
                            <span>{{ trans('mining-manager::events.active') }}</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #6c757d;"></div>
                            <span>{{ trans('mining-manager::events.completed') }}</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: #dc3545;"></div>
                            <span>{{ trans('mining-manager::events.cancelled') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- CALENDAR VIEW --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::events.calendar_view') }}
                    </h3>
                    <div class="card-tools">
                        @can('mining-manager.director')
                        <a href="{{ route('mining-manager.events.create') }}" class="btn btn-sm btn-success">
                            <i class="fas fa-plus"></i> {{ trans('mining-manager::events.create_event') }}
                        </a>
                        @endcan
                        <a href="{{ route('mining-manager.events.index') }}" class="btn btn-sm btn-info">
                            <i class="fas fa-list"></i> {{ trans('mining-manager::events.list_view') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div id="calendar"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- UPCOMING EVENTS SIDEBAR --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-clock"></i>
                        {{ trans('mining-manager::events.upcoming_events') }}
                    </h3>
                </div>
                <div class="card-body">
                    @forelse($upcomingEvents as $event)
                    <div class="event-item mb-3 p-3" style="background: #454d55; border-radius: 5px; border-left: 4px solid 
                        @if($event->status === 'planned') #17a2b8
                        @elseif($event->status === 'active') #28a745
                        @elseif($event->status === 'completed') #6c757d
                        @else #dc3545 @endif;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="mb-1">
                                    <a href="{{ route('mining-manager.events.show', $event->id) }}" class="text-light">
                                        {{ $event->name }}
                                    </a>
                                </h5>
                                <p class="mb-1 text-muted">
                                    <i class="fas fa-calendar"></i> 
                                    {{ $event->start_time->format('M d, Y H:i') }} - {{ $event->end_time->format('H:i') }}
                                </p>
                                @if($event->location)
                                <p class="mb-1 text-muted">
                                    <i class="fas fa-map-marker-alt"></i> {{ $event->location }}
                                </p>
                                @endif
                            </div>
                            <div>
                                <span class="badge badge-{{ 
                                    $event->status === 'planned' ? 'info' : 
                                    ($event->status === 'active' ? 'success' : 
                                    ($event->status === 'completed' ? 'secondary' : 'danger')) 
                                }}">
                                    {{ trans('mining-manager::events.' . $event->status) }}
                                </span>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="text-center text-muted">
                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                        <p>{{ trans('mining-manager::events.no_upcoming_events') }}</p>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

</div>

{{-- EVENT DETAILS MODAL --}}
<div class="modal fade" id="eventModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title" id="eventModalTitle"></h5>
                <button type="button" class="close text-light" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body" id="eventModalBody">
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="eventViewButton" class="btn btn-info" target="_blank">
                    <i class="fas fa-eye"></i> {{ trans('mining-manager::events.view_details') }}
                </a>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    {{ trans('mining-manager::events.close') }}
                </button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/fullcalendar.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');
    
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
        },
        height: 'auto',
        events: {!! json_encode($formattedEvents) !!},
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            showEventDetails(info.event);
        },
        eventContent: function(arg) {
            return {
                html: '<div class="fc-event-title fc-sticky">' + arg.event.title + '</div>'
            };
        }
    });
    
    calendar.render();
    
    function showEventDetails(event) {
        const props = event.extendedProps;
        
        $('#eventModalTitle').text(event.title);
        $('#eventViewButton').attr('href', '{{ route("mining-manager.events.show", ":id") }}'.replace(':id', event.id));
        
        let html = '<div class="event-details">';
        html += '<p><strong>{{ trans("mining-manager::events.status") }}:</strong> <span class="badge badge-';
        html += props.status === 'planned' ? 'info' : (props.status === 'active' ? 'success' : (props.status === 'completed' ? 'secondary' : 'danger'));
        html += '">' + props.status.charAt(0).toUpperCase() + props.status.slice(1) + '</span></p>';
        
        html += '<p><strong>{{ trans("mining-manager::events.type") }}:</strong> ' + props.type + '</p>';
        html += '<p><strong>{{ trans("mining-manager::events.start_time") }}:</strong> ' + new Date(event.start).toLocaleString() + '</p>';
        html += '<p><strong>{{ trans("mining-manager::events.end_time") }}:</strong> ' + new Date(event.end).toLocaleString() + '</p>';
        
        if (props.location) {
            html += '<p><strong>{{ trans("mining-manager::events.location") }}:</strong> ' + props.location + '</p>';
        }
        
        html += '<p><strong>{{ trans("mining-manager::events.participants") }}:</strong> ' + props.participants + '</p>';
        
        html += '<p><strong>{{ trans("mining-manager::events.tax_modifier") }}:</strong> ';
        html += '<span class="badge badge-' + (props.tax_modifier < 0 ? 'success' : (props.tax_modifier > 0 ? 'warning' : 'secondary')) + '">';
        html += props.tax_modifier_label + '</span></p>';
        
        if (props.description) {
            html += '<hr><p><strong>{{ trans("mining-manager::events.description") }}:</strong></p>';
            html += '<p>' + props.description + '</p>';
        }
        
        html += '</div>';
        
        $('#eventModalBody').html(html);
        $('#eventModal').modal('show');
    }
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
