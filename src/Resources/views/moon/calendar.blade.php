@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.extraction_calendar'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/vendor/fullcalendar.min.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper moon-calendar-page">

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/moon') && !Request::is('*/moon/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.index') }}">
                <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_extractions') }}
            </a>
        </li>
        <li class="{{ Request::is('*/moon/active') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.active') }}">
                <i class="fas fa-hourglass-half"></i> {{ trans('mining-manager::menu.active_extractions') }}
            </a>
        </li>
        <li class="{{ Request::is('*/moon/calendar') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.calendar') }}">
                <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.extraction_calendar') }}
            </a>
        </li>
        <li class="{{ Request::is('*/moon/compositions') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.compositions') }}">
                <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::menu.moon_compositions') }}
            </a>
        </li>
        <li class="{{ Request::is('*/moon/calculator') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.moon.calculator') }}">
                <i class="fas fa-coins"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">


<div class="extraction-calendar">
    
    {{-- CONTROLS --}}
    <div class="row mb-3">
        <div class="col-12">
            <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-secondary">
                <i class="fas fa-list"></i> {{ trans('mining-manager::moons.list_view') }}
            </a>
            <a href="{{ route('mining-manager.moon.compositions') }}" class="btn btn-success">
                <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::moons.compositions') }}
            </a>
        </div>
    </div>

    {{-- CALENDAR --}}
    <div class="row">
        <div class="col-lg-9">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::moons.calendar_view') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div id="calendar"></div>
                </div>
            </div>
        </div>

        {{-- TODAY'S EXTRACTIONS --}}
        <div class="col-lg-3">
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-day"></i>
                        {{ trans('mining-manager::moons.today') }}
                    </h3>
                </div>
                <div class="card-body">
                    @php
                        $today = \Carbon\Carbon::now()->format('Y-m-d');
                        $todayExtractions = $calendar[$today] ?? [];
                    @endphp
                    
                    @forelse($todayExtractions as $extraction)
                    <div class="extraction-item status-{{ $extraction->status }}">
                        <h6 class="mb-1">{{ $extraction->structure_name ?? 'Unknown' }}</h6>
                        <p class="mb-1 small text-muted">
                            <i class="fas fa-clock"></i> 
                            {{ $extraction->chunk_arrival_time->format('H:i') }}
                        </p>
                        <p class="mb-0 small">
                            <span class="badge badge-{{ 
                                $extraction->status === 'extracting' ? 'warning' : 
                                ($extraction->status === 'ready' ? 'success' : 'secondary') 
                            }}">
                                {{ trans('mining-manager::moons.' . $extraction->status) }}
                            </span>
                        </p>
                    </div>
                    @empty
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                        <p>{{ trans('mining-manager::moons.no_extractions_today') }}</p>
                    </div>
                    @endforelse
                </div>
            </div>

            {{-- UPCOMING THIS WEEK --}}
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-week"></i>
                        {{ trans('mining-manager::moons.this_week') }}
                    </h3>
                </div>
                <div class="card-body">
                    @php
                        $weekStart = \Carbon\Carbon::now()->startOfWeek();
                        $weekEnd = \Carbon\Carbon::now()->endOfWeek();
                        $weekExtractions = collect();
                        foreach ($calendar as $date => $extractions) {
                            $carbonDate = \Carbon\Carbon::parse($date);
                            if ($carbonDate->between($weekStart, $weekEnd) && $carbonDate->isAfter(\Carbon\Carbon::now())) {
                                foreach ($extractions as $extraction) {
                                    $weekExtractions->push($extraction);
                                }
                            }
                        }
                        $weekExtractions = $weekExtractions->sortBy('chunk_arrival_time')->take(10);
                    @endphp
                    
                    @forelse($weekExtractions as $extraction)
                    <div class="extraction-item status-{{ $extraction->status }} mb-2">
                        <h6 class="mb-1">{{ $extraction->structure_name ?? 'Unknown' }}</h6>
                        <p class="mb-1 small">
                            <i class="fas fa-calendar"></i> 
                            {{ $extraction->chunk_arrival_time->format('D, M d') }}
                        </p>
                        <p class="mb-0 small">
                            <i class="fas fa-clock"></i> 
                            {{ $extraction->chunk_arrival_time->format('H:i') }}
                        </p>
                    </div>
                    @empty
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                        <p>{{ trans('mining-manager::moons.no_upcoming_week') }}</p>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

</div>

{{-- EXTRACTION DETAILS MODAL --}}
<div class="modal fade" id="extractionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title" id="extractionModalTitle"></h5>
                <button type="button" class="close text-light" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body" id="extractionModalBody">
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="extractionViewButton" class="btn btn-info" target="_blank">
                    <i class="fas fa-eye"></i> {{ trans('mining-manager::moons.view_details') }}
                </a>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    {{ trans('mining-manager::moons.close') }}
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
    const calendarData = @json($calendar);
    
    // Convert calendar data to FullCalendar events
    const events = [];
    for (const [date, extractions] of Object.entries(calendarData)) {
        extractions.forEach(extraction => {
            events.push({
                id: extraction.id,
                title: extraction.structure_name || 'Unknown',
                start: extraction.chunk_arrival_time,
                className: 'status-' + extraction.status,
                extendedProps: {
                    status: extraction.status,
                    moon: extraction.moon_name || 'Unknown',
                    structure: extraction.structure_name || 'Unknown',
                    estimatedValue: extraction.calculated_value || extraction.estimated_value || 0,
                    oreComposition: extraction.ore_composition
                }
            });
        });
    }
    
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        initialDate: '{{ $month->format("Y-m-d") }}',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listWeek'
        },
        height: 'auto',
        events: events,
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            showExtractionDetails(info.event);
        },
        eventContent: function(arg) {
            return {
                html: '<div class="fc-event-title fc-sticky">' + arg.event.title + '</div>'
            };
        }
    });
    
    calendar.render();
    
    function showExtractionDetails(event) {
        const props = event.extendedProps;
        
        $('#extractionModalTitle').text(props.structure);
        $('#extractionViewButton').attr('href', '{{ route("mining-manager.moon.show", ":id") }}'.replace(':id', event.id));
        
        let html = '<div class="extraction-details">';
        html += '<p><strong>{{ trans("mining-manager::moons.status") }}:</strong> <span class="badge badge-';
        html += props.status === 'extracting' ? 'warning' : (props.status === 'ready' ? 'success' : 'secondary');
        html += '">' + props.status.charAt(0).toUpperCase() + props.status.slice(1) + '</span></p>';
        
        html += '<p><strong>{{ trans("mining-manager::moons.moon") }}:</strong> ' + props.moon + '</p>';
        html += '<p><strong>{{ trans("mining-manager::moons.chunk_arrival") }}:</strong> ' + new Date(event.start).toLocaleString() + '</p>';
        
        if (props.estimatedValue && props.estimatedValue > 0) {
            html += '<p><strong>{{ trans("mining-manager::moons.estimated_value") }}:</strong> ';
            html += '<span class="text-success h5">' + props.estimatedValue.toLocaleString() + ' ISK</span></p>';
        }
        
        if (props.oreComposition) {
            html += '<hr><p><strong>{{ trans("mining-manager::moons.ore_composition") }}:</strong></p>';
            html += '<p class="text-muted small">{{ trans("mining-manager::moons.view_full_details") }}</p>';
        }
        
        html += '</div>';
        
        $('#extractionModalBody').html(html);
        $('#extractionModal').modal('show');
    }
});
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
