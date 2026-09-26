@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.extraction_calendar'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=8">
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/vendor/fullcalendar.min.css') }}">
<style>
    /* One pull reads left to right as time, moon tier, refinery. Same badge
       family as the planner, the Blueprints grid and the refinery cards. */
    .moon-calendar-page .mm-cal-event { display:flex; align-items:center; gap:4px; min-width:0; }
    .moon-calendar-page .mm-cal-time { flex:none; font-weight:600; }
    .moon-calendar-page .mm-cal-tier { flex:none; font-size:0.62rem; padding:1px 4px; line-height:1.25; }
    .moon-calendar-page .mm-cal-title { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .moon-calendar-page .fc-daygrid-event { overflow:hidden; }
    /* A grid per month, headed and boxed, so the boundaries are obvious. */
    .moon-calendar-page .mm-cal-month { margin-bottom:1rem; border:1px solid rgba(255,255,255,0.06); border-radius:8px; overflow:hidden; }
    .moon-calendar-page .mm-cal-month .fc-col-header-cell { background:rgba(255,255,255,0.04); }
    .moon-calendar-page .mm-month-heading { display:flex; align-items:center; gap:8px; font-weight:600; color:#cfd6df; }
    .moon-calendar-page .mm-month-heading .mm-month-pill { font-size:0.65rem; background:rgba(255,255,255,0.06); color:#9aa4b2; padding:1px 8px; border-radius:10px; }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard moon-calendar-page">

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
            @can('mining-manager.director')
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/metenox-cargo') ? 'active' : '' }}" href="{{ route('mining-manager.moon.metenox-cargo') }}">
                    <i class="fas fa-box-open"></i> {{ trans('mining-manager::menu.metenox_cargo') }}
                    <span class="badge badge-info ml-1" style="font-size: 0.6em;">Director</span>
                </a>
            </li>
            @endcan
        </ul>
    </div>
    <div class="card-body">

<div class="extraction-calendar">

    {{-- CALENDAR CARD --}}
    <div class="row">
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::moons.calendar_view') }}</h3>
                    <div class="card-tools">
                        <a href="{{ route('mining-manager.moon.index') }}" class="btn btn-sm btn-outline-secondary mr-1">
                            <i class="fas fa-list"></i> {{ trans('mining-manager::moons.list_view') }}
                        </a>
                        <a href="{{ route('mining-manager.moon.active') }}" class="btn btn-sm btn-warning">
                            <i class="fas fa-broadcast-tower"></i> {{ trans('mining-manager::moons.active_extractions') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Status legend --}}
                    <div class="mm-status-legend">
                        <div class="mm-status-legend-item">
                            <div class="mm-status-legend-color mm-extracting"></div>
                            {{ trans('mining-manager::moons.extracting') }}
                        </div>
                        <div class="mm-status-legend-item">
                            <div class="mm-status-legend-color mm-ready"></div>
                            {{ trans('mining-manager::moons.ready') }}
                        </div>
                        <div class="mm-status-legend-item">
                            <div class="mm-status-legend-color mm-unstable"></div>
                            {{ trans('mining-manager::moons.unstable') }}
                        </div>
                        <div class="mm-status-legend-item">
                            <div class="mm-status-legend-color mm-expired"></div>
                            {{ trans('mining-manager::moons.expired') }}
                        </div>
                    </div>

                    {{-- 3-month window nav (EVE/UTC) --}}
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="btn-group">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('mining-manager.moon.calendar', ['month' => $months[0]->copy()->subMonth()->format('Y-m-d')]) }}">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('mining-manager.moon.calendar') }}">{{ trans('mining-manager::moons.today') }}</a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('mining-manager.moon.calendar', ['month' => $months[0]->copy()->addMonth()->format('Y-m-d')]) }}">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                        <strong>{{ $months[0]->format('M Y') }} &ndash; {{ $months[2]->format('M Y') }} <span class="text-muted">(EVE / UTC)</span></strong>
                        <div class="btn-group btn-group-sm" id="cal-views">
                            <button type="button" class="btn btn-outline-secondary active" data-view="months">3 months</button>
                            <button type="button" class="btn btn-outline-secondary" data-view="timeGridWeek">Week</button>
                            <button type="button" class="btn btn-outline-secondary" data-view="listWeek">List</button>
                        </div>
                    </div>

                    <div id="calendar-months">
                        @foreach($months as $m)
                            <h5 class="mt-3 mb-2 mm-month-heading">
                                <i class="fas fa-calendar-day text-primary"></i> {{ $m->format('F Y') }}
                                @if($m->isSameMonth(\Carbon\Carbon::now()))
                                    <span class="mm-month-pill">current</span>
                                @endif
                            </h5>
                            <div class="mm-cal-month" data-month="{{ $m->format('Y-m-d') }}"></div>
                        @endforeach
                    </div>

                    <div id="calendar" style="display:none;"></div>
                </div>
            </div>
        </div>

        {{-- SIDEBAR --}}
        <div class="col-lg-3">
            {{-- TODAY'S EXTRACTIONS --}}
            <div class="card card-warning card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-day"></i>
                        {{ trans('mining-manager::moons.today') }}
                    </h3>
                    <div class="card-tools">
                        @php
                            $today = \Carbon\Carbon::now()->format('Y-m-d');
                            $todayExtractions = $calendar[$today] ?? [];
                        @endphp
                        <span class="badge badge-warning">{{ count($todayExtractions) }}</span>
                    </div>
                </div>
                <div class="card-body p-2">
                    @forelse($todayExtractions as $extraction)
                        @php $effectiveStatus = $extraction->getEffectiveStatus(); @endphp
                        <div class="mm-sidebar-item mm-status-{{ $effectiveStatus }}">
                            <div class="mm-structure-name">
                                <i class="fas fa-building"></i>
                                {{ $extraction->structure_name ?? 'Unknown' }}
                            </div>
                            <div class="mm-extraction-time">
                                <i class="fas fa-clock"></i>
                                {{ $extraction->chunk_arrival_time->format('H:i') }} EVE
                                <span class="badge badge-sm badge-{{
                                    $effectiveStatus === 'extracting' ? 'warning' :
                                    ($effectiveStatus === 'ready' ? 'success' :
                                    ($effectiveStatus === 'unstable' ? 'warning mm-badge-unstable' :
                                    ($effectiveStatus === 'expired' ? 'dark' : 'secondary')))
                                }}">
                                    {{ trans('mining-manager::moons.' . $effectiveStatus) }}
                                </span>
                            </div>
                            @if($extraction->calculated_value ?? $extraction->estimated_value ?? 0 > 0)
                                <div class="mm-extraction-value">
                                    <i class="fas fa-coins"></i>
                                    {{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-calendar-times fa-2x mb-2"></i>
                            <p class="mb-0">{{ trans('mining-manager::moons.no_extractions_today') }}</p>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- READY TO MINE --}}
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-check-circle"></i>
                        {{ trans('mining-manager::moons.ready_to_mine') }}
                    </h3>
                    <div class="card-tools">
                        @php
                            $now = \Carbon\Carbon::now();
                            $readyExtractions = collect();
                            foreach ($calendar as $date => $extractions) {
                                foreach ($extractions as $extraction) {
                                    $effectiveStatus = $extraction->getEffectiveStatus();
                                    if (in_array($effectiveStatus, ['ready', 'unstable'])) {
                                        $readyExtractions->push($extraction);
                                    }
                                }
                            }
                            $readyExtractions = $readyExtractions->sortBy('chunk_arrival_time');
                        @endphp
                        <span class="badge badge-success">{{ $readyExtractions->count() }}</span>
                    </div>
                </div>
                <div class="card-body p-2">
                    @forelse($readyExtractions as $extraction)
                        @php
                            $effectiveStatus = $extraction->getEffectiveStatus();
                            $fractureTime = $extraction->getFractureTime() ?? $extraction->chunk_arrival_time;
                            $hoursSinceFracture = $now->diffInHours($fractureTime, false) * -1;
                            $readyHours = $extraction->getReadyDurationHours();
                            $hoursUntilUnstable = $readyHours - $hoursSinceFracture;
                            $hoursUntilExpired = ($readyHours + 2) - $hoursSinceFracture;
                        @endphp
                        <div class="mm-sidebar-item mm-status-{{ $effectiveStatus }}">
                            <div class="mm-structure-name">
                                <i class="fas fa-building {{ $effectiveStatus === 'unstable' ? 'text-danger' : 'text-success' }}"></i>
                                {{ $extraction->structure_name ?? 'Unknown' }}
                            </div>
                            <div class="mm-extraction-time text-muted" style="font-size: 0.85em;">
                                <i class="fas fa-moon"></i>
                                {{ $extraction->moon_name ?? 'Unknown Moon' }}
                            </div>
                            <div class="mm-extraction-time">
                                <i class="fas fa-clock"></i>
                                {{ trans('mining-manager::moons.ready_since') }}: {{ $fractureTime->format('M d, H:i') }} EVE
                                @if($extraction->auto_fractured)
                                    <span class="badge badge-sm badge-secondary" title="Auto-fractured at {{ $fractureTime->format('M d, H:i') }}">AF</span>
                                @endif
                            </div>
                            @if($effectiveStatus === 'ready')
                                <div class="mm-countdown mm-countdown-warning" data-hours-until-unstable="{{ $hoursUntilUnstable }}">
                                    <i class="fas fa-exclamation-triangle text-warning"></i>
                                    <span class="countdown-text">
                                        {{ trans('mining-manager::moons.unstable_in') }}:
                                        <strong>{{ floor(max(0, $hoursUntilUnstable)) }}h {{ round((max(0, $hoursUntilUnstable) - floor(max(0, $hoursUntilUnstable))) * 60) }}m</strong>
                                    </span>
                                </div>
                            @else
                                <div class="mm-countdown mm-countdown-danger" data-hours-until-fracture="{{ $hoursUntilExpired }}">
                                    <i class="fas fa-exclamation-triangle text-danger"></i>
                                    <span class="countdown-text">
                                        {{ trans('mining-manager::moons.unstable') }} -
                                        {{ trans('mining-manager::moons.belt_expires') }}:
                                        <strong class="text-danger">{{ floor(max(0, $hoursUntilExpired)) }}h {{ round((max(0, $hoursUntilExpired) - floor(max(0, $hoursUntilExpired))) * 60) }}m</strong>
                                    </span>
                                </div>
                            @endif
                            @if($extraction->calculated_value ?? $extraction->estimated_value ?? 0 > 0)
                                <div class="mm-extraction-value">
                                    {{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-check-circle fa-2x mb-2"></i>
                            <p class="mb-0">{{ trans('mining-manager::moons.no_ready_extractions') }}</p>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- NEXT 7 DAYS --}}
            <div class="card card-info card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::moons.next_7_days') }}
                    </h3>
                    <div class="card-tools">
                        @php
                            $weekStart = \Carbon\Carbon::now();
                            $weekEnd = \Carbon\Carbon::now()->addDays(7);
                            $weekExtractions = collect();
                            foreach ($calendar as $date => $extractions) {
                                $carbonDate = \Carbon\Carbon::parse($date);
                                if ($carbonDate->between($weekStart, $weekEnd)) {
                                    foreach ($extractions as $extraction) {
                                        if ($extraction->chunk_arrival_time->isAfter(\Carbon\Carbon::now())) {
                                            $weekExtractions->push($extraction);
                                        }
                                    }
                                }
                            }
                            $weekExtractions = $weekExtractions->sortBy('chunk_arrival_time')->take(8);
                        @endphp
                        <span class="badge badge-info">{{ $weekExtractions->count() }}</span>
                    </div>
                </div>
                <div class="card-body p-2">
                    @forelse($weekExtractions as $extraction)
                        @php $effectiveStatus = $extraction->getEffectiveStatus(); @endphp
                        <div class="mm-sidebar-item mm-status-{{ $effectiveStatus }}">
                            <div class="mm-structure-name">
                                <i class="fas fa-building text-info"></i>
                                {{ $extraction->structure_name ?? 'Unknown' }}
                            </div>
                            <div class="mm-extraction-time">
                                <i class="fas fa-calendar"></i>
                                {{ $extraction->chunk_arrival_time->format('D, M d') }}
                                <br>
                                <i class="fas fa-clock"></i>
                                {{ $extraction->chunk_arrival_time->format('H:i') }} EVE
                            </div>
                            @if($extraction->calculated_value ?? $extraction->estimated_value ?? 0 > 0)
                                <div class="mm-extraction-value">
                                    {{ number_format($extraction->calculated_value ?? $extraction->estimated_value ?? 0, 0) }} ISK
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-calendar-times fa-2x mb-2"></i>
                            <p class="mb-0">{{ trans('mining-manager::moons.no_upcoming_next_7') }}</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/fullcalendar.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inject modal at body level to avoid stacking context issues
    var modalHtml = `
    <div class="modal fade" id="extractionModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-gem"></i>
                        <span id="extractionModalTitle"></span>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body" id="extractionModalBody">
                    <div class="mm-event-detail-row">
                        <div class="mm-event-detail-label">{{ trans('mining-manager::moons.status') }}</div>
                        <div class="mm-event-detail-value" id="eventStatus"></div>
                    </div>
                    <div class="mm-event-detail-row">
                        <div class="mm-event-detail-label">{{ trans('mining-manager::moons.moon') }}</div>
                        <div class="mm-event-detail-value" id="eventMoon"></div>
                    </div>
                    <div class="mm-event-detail-row">
                        <div class="mm-event-detail-label">{{ trans('mining-manager::moons.chunk_arrival') }}</div>
                        <div class="mm-event-detail-value" id="eventArrival"></div>
                    </div>
                    <div class="mm-event-detail-row" id="eventValueRow">
                        <div class="mm-event-detail-label">{{ trans('mining-manager::moons.estimated_value') }}</div>
                        <div class="mm-event-detail-value" id="eventValue"></div>
                    </div>
                    <div class="mm-event-detail-row" id="eventOreRow" style="display: none;">
                        <div class="mm-event-detail-label">{{ trans('mining-manager::moons.ore_composition') }}</div>
                        <div class="mm-event-detail-value" id="eventOres"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" id="extractionViewButton" class="btn btn-info">
                        <i class="fas fa-eye"></i> {{ trans('mining-manager::moons.view_details') }}
                    </a>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        {{ trans('mining-manager::moons.close') }}
                    </button>
                </div>
            </div>
        </div>
    </div>`;
    $('body').append(modalHtml);

    const calendarEl = document.getElementById('calendar');
    const calendarData = @json($calendar);

    // Convert calendar data to FullCalendar events
    const events = [];
    for (const [date, extractions] of Object.entries(calendarData)) {
        extractions.forEach(extraction => {
            // Determine effective status
            let effectiveStatus = extraction.status || 'extracting';
            const isArchived = extraction.is_archived || false;
            const autoFractured = extraction.auto_fractured || false;

            if (extraction.chunk_arrival_time) {
                // Parse datetime - Carbon serializes to ISO 8601 format
                // Handle both "2026-02-09T17:00:00.000000Z" and "2026-02-09 17:00:00" formats
                let arrivalStr = extraction.chunk_arrival_time;
                let decayStr = extraction.natural_decay_time;

                // If it's an object with date property (Carbon serialization), extract the date string
                if (typeof arrivalStr === 'object' && arrivalStr.date) {
                    arrivalStr = arrivalStr.date;
                }
                if (typeof decayStr === 'object' && decayStr.date) {
                    decayStr = decayStr.date;
                }

                // Ensure proper ISO format for parsing
                if (typeof arrivalStr === 'string') {
                    arrivalStr = arrivalStr.replace(' ', 'T');
                    if (!arrivalStr.includes('Z') && !arrivalStr.includes('+')) {
                        arrivalStr += 'Z'; // Assume UTC
                    }
                }
                if (typeof decayStr === 'string') {
                    decayStr = decayStr.replace(' ', 'T');
                    if (!decayStr.includes('Z') && !decayStr.includes('+')) {
                        decayStr += 'Z';
                    }
                }

                const arrivalTime = new Date(arrivalStr);
                const decayTime = decayStr ? new Date(decayStr) : null;
                const now = new Date();

                // Use fractured_at if available, otherwise estimate from arrival
                let fractureTime = arrivalTime;
                if (extraction.fractured_at) {
                    let fracturedStr = extraction.fractured_at;
                    if (typeof fracturedStr === 'object' && fracturedStr.date) {
                        fracturedStr = fracturedStr.date;
                    }
                    if (typeof fracturedStr === 'string') {
                        fracturedStr = fracturedStr.replace(' ', 'T');
                        if (!fracturedStr.includes('Z') && !fracturedStr.includes('+')) {
                            fracturedStr += 'Z';
                        }
                    }
                    fractureTime = new Date(fracturedStr);
                } else if (autoFractured) {
                    // No fractured_at recorded, estimate as arrival + 3h
                    fractureTime = new Date(arrivalTime.getTime() + 3 * 60 * 60 * 1000);
                }

                const hoursSinceFracture = (now - fractureTime) / (1000 * 60 * 60);

                if (arrivalTime > now) {
                    effectiveStatus = 'extracting';
                } else if (hoursSinceFracture < 48) {
                    effectiveStatus = 'ready';
                } else if (hoursSinceFracture < 50) {
                    effectiveStatus = 'unstable';
                } else {
                    effectiveStatus = 'expired';
                }
            }

            // Override for archived extractions
            if (isArchived) {
                effectiveStatus = extraction.status || 'expired';
            }

            // Ensure we only use valid statuses that have CSS defined
            const validStatuses = ['extracting', 'ready', 'unstable', 'expired', 'fractured'];
            if (!validStatuses.includes(effectiveStatus)) {
                effectiveStatus = 'extracting'; // fallback
            }

            events.push({
                id: extraction.id,
                title: extraction.structure_name || 'Unknown',
                start: extraction.chunk_arrival_time,
                className: 'mm-status-' + effectiveStatus,
                extendedProps: {
                    status: effectiveStatus,
                    rarity: extraction.rarity || null,
                    moon: extraction.moon_name || 'Unknown',
                    structure: extraction.structure_name || 'Unknown',
                    estimatedValue: extraction.calculated_value || extraction.estimated_value || 0,
                    oreComposition: extraction.ore_composition,
                    autoFractured: autoFractured
                }
            });
        });
    }

    const RARITY_CLASS = { R4: 'badge-r4', R8: 'badge-r8', R16: 'badge-r16', R32: 'badge-r32', R64: 'badge-r64' };

    // What the page actually loaded. Navigating inside it is free; leaving it
    // is what needs a trip to the server.
    const WINDOW_START = new Date('{{ $windowStart->toDateString() }}T00:00:00Z');
    const WINDOW_END = new Date('{{ $windowEnd->copy()->addDay()->toDateString() }}T00:00:00Z');

    function renderEvent(arg) {
        const props = arg.event.extendedProps;
        const wrap = document.createElement('div');
        wrap.className = 'mm-cal-event';
        wrap.title = props.structure + (props.moon ? ' (' + props.moon + ')' : '');

        if (arg.timeText) {
            const time = document.createElement('span');
            time.className = 'mm-cal-time';
            time.textContent = arg.timeText;
            wrap.appendChild(time);
        }

        if (props.rarity) {
            const badge = document.createElement('span');
            badge.className = 'badge mm-cal-tier ' + (RARITY_CLASS[props.rarity] || 'badge-secondary');
            badge.textContent = props.rarity;
            wrap.appendChild(badge);
        }

        const title = document.createElement('span');
        title.className = 'mm-cal-title';
        title.textContent = arg.event.title;
        wrap.appendChild(title);

        return { domNodes: [wrap] };
    }

    // Shared by every grid on the page, so a pull looks and behaves the same
    // whichever one you are looking at.
    const GRID = {
        // Extraction times are EVE time, which is what every other time on this
        // page is labelled as. Without this the grid places them in the
        // browser's zone and a late-night chunk lands on the wrong day.
        timeZone: 'UTC',
        events: events,
        eventContent: renderEvent,
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            showExtractionDetails(info.event);
        },
        firstDay: 1,
        eventDisplay: 'block',
        dayMaxEvents: 4,
        moreLinkClick: 'popover',
    };

    // One grid per month with its own heading, like the planner. Three months
    // as a single rolling grid left the boundaries invisible.
    document.querySelectorAll('.mm-cal-month').forEach(function (el) {
        new FullCalendar.Calendar(el, Object.assign({}, GRID, {
            initialView: 'dayGridMonth',
            initialDate: el.dataset.month,
            headerToolbar: false,
            showNonCurrentDates: false,
            fixedWeekCount: false,
            height: 'auto',
        })).render();
    });

    // Week and list share one calendar, shown in place of the months.
    const calendar = new FullCalendar.Calendar(calendarEl, Object.assign({}, GRID, {
        initialView: 'timeGridWeek',
        initialDate: '{{ $initialDate }}',
        headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
        height: 700,
        nowIndicator: true,
        slotEventOverlap: false,
        slotDuration: '01:00:00',
        expandRows: true,
        eventMaxStack: 5,
        datesSet: function (dateInfo) {
            // currentStart/currentEnd are the logical range, not the grid,
            // which spills into the neighbouring months and would send the page
            // straight back to the server on every render.
            var from = dateInfo.view.currentStart;
            var to = dateInfo.view.currentEnd;
            if (from >= WINDOW_START && to <= WINDOW_END) {
                return;
            }

            var newMonth = from.getUTCFullYear() + '-'
                + String(from.getUTCMonth() + 1).padStart(2, '0') + '-01';
            window.location.href = '{{ route("mining-manager.moon.calendar") }}?month=' + newMonth;
        },
    }));

    // A grid measures itself wrong while its container is hidden, so the week
    // calendar is not built until something asks for it.
    let weekBuilt = false;
    function showRange(view) {
        const months = view === 'months';
        $('#calendar-months').toggle(months);
        $('#calendar').toggle(!months);
        $('#cal-views button').removeClass('active')
            .filter('[data-view="' + view + '"]').addClass('active');

        if (months) {
            return;
        }

        if (!weekBuilt) {
            calendar.render();
            weekBuilt = true;
        }
        calendar.changeView(view);
        calendar.updateSize();
    }

    $('#cal-views button').on('click', function () {
        showRange($(this).data('view'));
    });

    function showExtractionDetails(event) {
        const props = event.extendedProps;
        const startDate = event.start;

        // Format time as EVE
        const timeStr = startDate.getUTCFullYear() + '-' +
            String(startDate.getUTCMonth() + 1).padStart(2, '0') + '-' +
            String(startDate.getUTCDate()).padStart(2, '0') + ' ' +
            String(startDate.getUTCHours()).padStart(2, '0') + ':' +
            String(startDate.getUTCMinutes()).padStart(2, '0') + ' EVE';

        $('#extractionModalTitle').text(props.structure);
        $('#extractionViewButton').attr('href', '{{ route("mining-manager.moon.show", ":id") }}'.replace(':id', event.id));

        // Status badge
        let badgeClass = props.status === 'extracting' ? 'warning' :
                        (props.status === 'ready' ? 'success' :
                        (props.status === 'unstable' ? 'warning mm-badge-unstable' : 'secondary'));
        $('#eventStatus').html('<span class="badge badge-' + badgeClass + '">' +
            props.status.charAt(0).toUpperCase() + props.status.slice(1) + '</span>');

        $('#eventMoon').html('<i class="fas fa-moon text-info"></i> ' + props.moon);
        $('#eventArrival').text(timeStr);

        // Value
        if (props.estimatedValue && props.estimatedValue > 0) {
            $('#eventValue').html('<span class="text-success font-weight-bold">' +
                props.estimatedValue.toLocaleString() + ' ISK</span>');
            $('#eventValueRow').show();
        } else {
            $('#eventValueRow').hide();
        }

        // Ore composition indicator
        if (props.oreComposition) {
            $('#eventOres').html('<span class="text-muted">Click "View Details" for full composition</span>');
            $('#eventOreRow').show();
        } else {
            $('#eventOreRow').hide();
        }

        // appendTo('body') escapes AdminLTE's CSS stacking context so the
        // modal-backdrop (inserted at body level by Bootstrap) doesn't end
        // up above the modal-dialog. See the same fix in taxes/index.blade.php.
        $('#extractionModal').appendTo('body').modal('show');
    }
});
</script>
@endpush

    </div>
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
