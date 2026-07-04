@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::menu.moon_planner'))
@section('page_header', trans('mining-manager::menu.moon_planner'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=2">
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/vendor/fullcalendar.min.css') }}">
<style>
    /* Event backgrounds must be set (not just a left border) or FullCalendar's
       default blue wins and the legend doesn't match what's on the grid. */
    .fc-event.mm-plan-auto   { background-color:#8e44ad !important; border-color:#6c3483 !important; color:#fff !important; }
    .fc-event.mm-plan-manual { background-color:#16a085 !important; border-color:#0e6655 !important; color:#fff !important; }
    .fc-event.mm-plan-actual { background-color:#5d6670 !important; border-color:#454b52 !important; color:#e9ecef !important; }
    /* Locked = set in-game / reconciled — dashed edge signals "can't edit here". */
    .fc-event.mm-plan-locked { border-style:dashed !important; cursor:not-allowed !important; }
    .fc-event.mm-plan-locked .fc-event-title { font-style:italic; }
    /* Scheduled off-plan — the in-game timer diverged from the plan. */
    .fc-event.mm-plan-mismatch { background-color:#c0392b !important; border-color:#922b21 !important; color:#fff !important; }
    .mm-planner-month { margin-bottom: 0.5rem; }
    .mm-refinery-card { font-size: 0.85rem; }
    .mm-refinery-card .mm-proj { font-weight: 600; }
    .mm-conflict-row { padding: 6px 10px; border-radius: 4px; background: rgba(255,193,7,0.12); margin-bottom: 6px; }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard moon-planner-page">

{{-- TAB NAVIGATION (shared moon sub-nav + Planner) --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.moon.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.moon.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.extraction_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('mining-manager.moon.planner') }}">
                    <i class="fas fa-calendar-check"></i> {{ trans('mining-manager::menu.moon_planner') }}
                    <span class="badge badge-primary ml-1" style="font-size: 0.6em;">Moon Manager</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">

    @if(!$corporationId)
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            No <strong>Moon Owner Corporation</strong> is configured yet. Set one in
            <a href="{{ route('mining-manager.settings.index') }}">Settings &rsaquo; General</a>
            so the planner knows which refineries to schedule.
        </div>
    @else

    {{-- Toolbar --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-0"><i class="fas fa-calendar-check text-primary"></i> Moon Extraction Planner</h4>
            <small class="text-muted">
                Stagger your refinery pulls so chunks don't clump. Minimum gap before a warning:
                <strong>{{ $minGapHours }}h</strong>.
                All times shown in <strong>EVE (UTC)</strong> — moons are set in EVE time in-game.
            </small>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-sm btn-success" id="btn-add-pull">
                <i class="fas fa-plus"></i> Add Planned Pull
            </button>
            <form method="POST" action="{{ route('mining-manager.moon.planner.auto-fill') }}" class="d-inline ml-1" id="autofill-form">
                @csrf
                <input type="hidden" name="month" value="{{ $anchor->format('Y-m') }}">
                <input type="hidden" name="spread" value="1">
                <button type="submit" class="btn btn-sm btn-outline-primary"
                        title="Project each refinery's next pull across all three months and stagger to honour the gap">
                    <i class="fas fa-magic"></i> Auto-fill from History
                </button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('error') }}
        </div>
    @endif

    {{-- SCHEDULE-MISMATCH WARNINGS — a plan and the real in-game pull for the
         same moon are more than the tolerance apart. --}}
    @if(!empty($warnings))
        <div class="alert alert-warning">
            <h6 class="mb-2"><i class="fas fa-exclamation-triangle"></i> Scheduling mismatches ({{ count($warnings) }})</h6>
            <small class="d-block text-muted mb-2">These moons are scheduled in-game at a materially different time than planned. Check whether the drill was fired on the wrong timer.</small>
            <ul class="mb-0" style="font-size: 0.85em;">
                @foreach($warnings as $w)
                    <li>
                        <strong>{{ $w['moon_name'] }}</strong> ({{ $w['structure_name'] }}) —
                        planned <strong>{{ $w['planned'] }}</strong>, in-game <strong>{{ $w['actual'] }}</strong>
                        <span class="badge badge-warning">{{ $w['offset_hours'] }}h off</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        {{-- CALENDAR --}}
        <div class="col-lg-9">
            <div class="card">
                <div class="card-body">
                    <div class="mm-status-legend mb-2">
                        <div class="mm-status-legend-item"><div class="mm-status-legend-color" style="background:#8e44ad"></div> Planned (auto)</div>
                        <div class="mm-status-legend-item"><div class="mm-status-legend-color" style="background:#16a085"></div> Planned (manual)</div>
                        <div class="mm-status-legend-item"><div class="mm-status-legend-color" style="background:#5d6670"></div> <i class="fas fa-lock" style="font-size:0.75em;"></i> Actual / extracting (locked)</div>
                        <div class="mm-status-legend-item"><div class="mm-status-legend-color" style="background:#d9534f"></div> <i class="fas fa-exclamation-triangle" style="font-size:0.75em;"></i> Scheduled off-plan</div>
                    </div>

                    {{-- 3-month window nav (EVE/UTC) --}}
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="btn-group">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('mining-manager.moon.planner', ['month' => $anchor->copy()->subMonth()->format('Y-m')]) }}">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('mining-manager.moon.planner') }}">Today</a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('mining-manager.moon.planner', ['month' => $anchor->copy()->addMonth()->format('Y-m')]) }}">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                        <strong>{{ $months[0]->format('M Y') }} – {{ $months[2]->format('M Y') }} <span class="text-muted">(EVE / UTC)</span></strong>
                    </div>

                    @foreach($months as $m)
                        <h5 class="mt-3 mb-2 text-muted">{{ $m->format('F Y') }}</h5>
                        <div class="mm-planner-month" data-month="{{ $m->format('Y-m-d') }}"></div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- REFINERIES SIDEBAR --}}
        <div class="col-lg-3">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-industry"></i> Refineries</h3>
                    <div class="card-tools"><span class="badge badge-primary">{{ count($refinerySummaries) }}</span></div>
                </div>
                <div class="card-body p-2" style="max-height: 640px; overflow-y: auto;">
                    @forelse($refinerySummaries as $r)
                        <div class="mm-sidebar-item mm-refinery-card mb-2">
                            <div class="mm-structure-name">
                                <i class="fas fa-building text-primary"></i>
                                {{ $r['structure_name'] }}
                            </div>
                            @if($r['moon_name'])
                                <div class="text-muted" style="font-size: 0.8em;">
                                    <i class="fas fa-moon"></i> {{ $r['moon_name'] }}
                                </div>
                            @endif
                            @if($r['has_history'])
                                <div style="font-size: 0.8em;">
                                    <i class="fas fa-history text-muted"></i>
                                    Cadence ~{{ $r['cadence_days'] }}d ({{ $r['arrival_count'] }} arrivals)
                                </div>
                                <div class="mm-proj" style="font-size: 0.8em;">
                                    <i class="fas fa-arrow-right text-success"></i>
                                    Next: {{ $r['projected_next'] ?? '—' }}
                                </div>
                                <button type="button" class="btn btn-xs btn-outline-success mt-1 btn-plan-refinery"
                                        data-structure-id="{{ $r['structure_id'] }}"
                                        data-moon-id="{{ $r['moon_id'] }}"
                                        data-structure-name="{{ $r['structure_name'] }}"
                                        data-projected="{{ $r['projected_iso'] }}">
                                    <i class="fas fa-plus"></i> Plan this pull
                                </button>
                            @else
                                <div class="text-muted" style="font-size: 0.8em;">
                                    <i class="fas fa-question-circle"></i>
                                    Not enough history (need 2+ arrivals) — place manually.
                                </div>
                                <button type="button" class="btn btn-xs btn-outline-secondary mt-1 btn-plan-refinery"
                                        data-structure-id="{{ $r['structure_id'] }}"
                                        data-moon-id="{{ $r['moon_id'] }}"
                                        data-structure-name="{{ $r['structure_name'] }}"
                                        data-projected="">
                                    <i class="fas fa-plus"></i> Plan manually
                                </button>
                            @endif
                        </div>
                    @empty
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-industry fa-2x mb-2"></i>
                            <p class="mb-0">No Athanor/Tatara refineries found for this corporation.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @endif

    </div>
</div>{{-- /.card-tabs --}}
</div>{{-- /.mining-manager-wrapper --}}

{{-- ADD / EDIT MODAL --}}
<div class="modal fade" id="planModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-calendar-plus"></i> <span id="planModalTitle">Plan Pull</span></h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="plan-id" value="">
                <div class="form-group" id="refinery-select-group">
                    <label>Refinery</label>
                    <select class="form-control" id="plan-structure-id"></select>
                </div>
                <div class="form-group">
                    <label>Planned arrival <span class="badge badge-info">EVE / UTC</span></label>
                    <input type="datetime-local" class="form-control" id="plan-arrival">
                    <small class="form-text text-muted">
                        Enter the time in <strong>EVE (UTC)</strong> — the same time you set the drill to in-game.
                        <span id="plan-local-confirm"></span>
                    </small>
                </div>
                <div class="form-group">
                    <label>Notes <small class="text-muted">(optional)</small></label>
                    <input type="text" class="form-control" id="plan-notes" maxlength="500" placeholder="e.g. shifted to Tuesday for evening fleet">
                </div>
                <div id="plan-error" class="text-danger" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger mr-auto" id="btn-delete-plan" style="display:none;">
                    <i class="fas fa-trash"></i> Remove
                </button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="btn-save-plan"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>
</div>

{{-- CONFLICT CONFIRM MODAL --}}
<div class="modal fade" id="conflictModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Arrivals too close together</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <p>This pull lands within the <strong id="conflict-gap"></strong>-hour minimum gap of:</p>
                <div id="conflict-list"></div>
                <p class="text-muted mb-0"><small>
                    Chunks not mined promptly can be wasted. Plan it this way anyway?
                </small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Pick another time</button>
                <button type="button" class="btn btn-warning" id="btn-confirm-conflict">
                    <i class="fas fa-check"></i> Plan anyway
                </button>
            </div>
        </div>
    </div>
</div>

{{-- LOCKED-ENTRY INFO MODAL --}}
<div class="modal fade" id="lockedModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-lock text-muted"></i> Set in-game — can't be changed here</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body" id="locked-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/fullcalendar.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const CSRF = $('meta[name="csrf-token"]').attr('content');
    const calendarData = @json($calendar ?? []);
    const refineries = @json($refinerySummaries ?? []);
    const minGap = {{ $minGapHours }};
    const routes = {
        store: '{{ route('mining-manager.moon.planner.store') }}',
        update: '{{ url('mining-manager/moon/planner') }}',
        destroy: '{{ url('mining-manager/moon/planner') }}',
        checkConflicts: '{{ route('mining-manager.moon.planner.check-conflicts') }}',
    };

    // ---- Build FullCalendar events from the day-grouped payload ----
    const events = [];
    for (const [day, entries] of Object.entries(calendarData)) {
        entries.forEach(e => {
            if (e.kind === 'plan') {
                // A plan reconciled to a live extraction is locked — it's a
                // record of reality now, not an editable intent.
                const locked = e.status === 'confirmed';
                let cls = e.source === 'auto' ? 'mm-plan-auto' : 'mm-plan-manual';
                if (locked) cls += ' mm-plan-locked';
                events.push({
                    id: 'plan-' + e.id,
                    title: (locked ? '🔒 ' : '') + (e.structure_name || 'Refinery'),
                    start: e.iso,
                    className: cls,
                    extendedProps: { type: 'plan', raw: e, locked: locked },
                });
            } else {
                // Actual extraction — set in-game, can't be changed here.
                // A mismatch flag means the in-game timer diverged from a plan.
                let cls = 'mm-plan-actual mm-plan-locked';
                if (e.mismatch) cls = 'mm-plan-mismatch mm-plan-locked';
                events.push({
                    id: 'actual-' + e.id,
                    title: (e.mismatch ? '⚠ ' : '🔒 ') + (e.structure_name || 'Refinery'),
                    start: e.iso,
                    className: cls,
                    extendedProps: { type: 'actual', raw: e, locked: true, mismatch: !!e.mismatch },
                });
            }
        });
    }

    function onEventClick(info) {
        info.jsEvent.preventDefault();
        const p = info.event.extendedProps;
        // Locked entries (live/completed extractions + reconciled plans) are
        // set in-game — explain why they can't be edited instead of doing nothing.
        if (p.type === 'actual' || p.locked) {
            showLockedInfo(p.raw, p.mismatch ? 'mismatch' : (p.type === 'actual' ? 'actual' : 'confirmed'));
            return;
        }
        openEditModal(p.raw);
    }

    // Render one dayGridMonth per visible month. timeZone:'UTC' makes every
    // event time render in EVE time (what moons are set to in-game) rather
    // than the browser's local zone.
    document.querySelectorAll('.mm-planner-month').forEach(function (el) {
        const cal = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            initialDate: el.dataset.month,
            timeZone: 'UTC',
            headerToolbar: false,
            events: events,
            firstDay: 1,
            showNonCurrentDates: false,
            fixedWeekCount: false,
            height: 'auto',
            eventDisplay: 'block',
            dayMaxEvents: 4,
            eventClick: onEventClick,
        });
        cal.render();
    });

    // ---- Modal helpers ----
    function fillRefinerySelect(selectedId) {
        const $sel = $('#plan-structure-id').empty();
        refineries.forEach(r => {
            const label = r.structure_name + (r.moon_name ? ' — ' + r.moon_name : '');
            $sel.append($('<option>').val(r.structure_id).text(label));
        });
        if (selectedId) $sel.val(selectedId);
    }

    // Convert an ISO string to the value a datetime-local input expects (UTC, no seconds).
    function isoToLocalInput(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        return d.getUTCFullYear() + '-' +
            String(d.getUTCMonth() + 1).padStart(2, '0') + '-' +
            String(d.getUTCDate()).padStart(2, '0') + 'T' +
            String(d.getUTCHours()).padStart(2, '0') + ':' +
            String(d.getUTCMinutes()).padStart(2, '0');
    }

    // datetime-local value (treated as UTC) → ISO string for the server.
    function inputToIso(val) {
        if (!val) return null;
        return val + ':00Z';
    }

    // Live "that's HH:MM your local time" confirmation under the EVE input.
    function updateLocalConfirm() {
        const val = $('#plan-arrival').val();
        if (!val) { $('#plan-local-confirm').text(''); return; }
        try {
            const d = new Date(val + ':00Z');
            $('#plan-local-confirm').html(
                '<br><i class="fas fa-user-clock"></i> That\'s <strong>' +
                d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) +
                '</strong> your local time.'
            );
        } catch (e) { $('#plan-local-confirm').text(''); }
    }
    $(document).on('input change', '#plan-arrival', updateLocalConfirm);

    function openAddModal(structureId, projectedIso) {
        $('#planModalTitle').text('Plan Pull');
        $('#plan-id').val('');
        $('#refinery-select-group').show();
        fillRefinerySelect(structureId);
        $('#plan-arrival').val(isoToLocalInput(projectedIso) || isoToLocalInput(new Date().toISOString()));
        $('#plan-notes').val('');
        $('#btn-delete-plan').hide();
        $('#plan-error').hide().text('');
        updateLocalConfirm();
        $('#planModal').appendTo('body').modal('show');
    }

    function openEditModal(raw) {
        $('#planModalTitle').text('Edit Planned Pull');
        $('#plan-id').val(raw.id);
        $('#refinery-select-group').hide();
        fillRefinerySelect(raw.structure_id);
        $('#plan-arrival').val(isoToLocalInput(raw.iso));
        $('#plan-notes').val(raw.notes || '');
        $('#btn-delete-plan').show().data('id', raw.id);
        $('#plan-error').hide().text('');
        updateLocalConfirm();
        $('#planModal').appendTo('body').modal('show');
    }

    // Render an ISO instant in the browser's local zone (confirmation only).
    function localFromIso(iso) {
        if (!iso) return '';
        try { return new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); }
        catch (e) { return ''; }
    }

    // Explain why a locked entry can't be edited from the planner.
    function showLockedInfo(raw, kind) {
        const moon = raw.moon_name || ('Moon ' + (raw.moon_id || ''));
        const structure = raw.structure_name || 'Refinery';
        const when = raw.iso ? new Date(raw.iso) : null;
        const eveStr = when
            ? when.getUTCFullYear() + '-' + String(when.getUTCMonth() + 1).padStart(2, '0') + '-' +
              String(when.getUTCDate()).padStart(2, '0') + ' ' +
              String(when.getUTCHours()).padStart(2, '0') + ':' + String(when.getUTCMinutes()).padStart(2, '0') + ' EVE'
            : '';
        const localStr = localFromIso(raw.iso);
        let lead;
        if (kind === 'mismatch') {
            lead = '⚠️ This extraction is scheduled in-game at a <strong>different time than planned</strong>. Check whether the drill was fired on the wrong timer — the in-game time below is what EVE will actually run.';
        } else if (kind === 'actual') {
            lead = 'This is a <strong>live / completed extraction</strong> — it was set in-game and reflects what EVE actually scheduled.';
        } else {
            lead = 'This planned pull has been <strong>confirmed against a real extraction</strong>, so it now records what actually happened.';
        }
        $('#locked-body').html(
            lead + ' It can\'t be moved or removed from the planner.' +
            '<div class="mt-2"><i class="fas fa-moon text-info"></i> ' + moon + '<br>' +
            '<i class="fas fa-building text-primary"></i> ' + structure +
            (eveStr ? '<br><i class="fas fa-clock"></i> ' + eveStr : '') +
            (localStr ? '<br><small class="text-muted"><i class="fas fa-user-clock"></i> ' + localStr + ' your time</small>' : '') +
            '</div>'
        );
        $('#lockedModal').appendTo('body').modal('show');
    }

    $('#btn-add-pull').on('click', () => openAddModal(null, null));
    $('.btn-plan-refinery').on('click', function () {
        openAddModal($(this).data('structure-id'), $(this).data('projected') || null);
    });

    // ---- Save (create or update), with the gap-confirm flow ----
    let pendingPayload = null; // stashed across the conflict modal

    function postPlan(payload, isUpdate, planId) {
        const url = isUpdate ? (routes.update + '/' + planId) : routes.store;
        const method = isUpdate ? 'PUT' : 'POST';
        return $.ajax({
            url: url,
            method: method,
            data: Object.assign({ _token: CSRF }, payload),
        });
    }

    function submitPlan(confirmed) {
        const planId = $('#plan-id').val();
        const isUpdate = planId !== '';
        const iso = inputToIso($('#plan-arrival').val());
        if (!iso) { $('#plan-error').show().text('Pick a planned arrival time.'); return; }

        const payload = {
            structure_id: $('#plan-structure-id').val(),
            planned_arrival_time: iso,
            notes: $('#plan-notes').val(),
            confirmed: confirmed ? 1 : 0,
        };
        pendingPayload = { payload, isUpdate, planId };

        postPlan(payload, isUpdate, planId)
            .done(() => window.location.reload())
            .fail(xhr => {
                if (xhr.status === 409 && xhr.responseJSON && xhr.responseJSON.requires_confirmation) {
                    showConflicts(xhr.responseJSON);
                } else {
                    const msg = (xhr.responseJSON && (xhr.responseJSON.error
                        || Object.values(xhr.responseJSON.errors || {})[0])) || 'Save failed.';
                    $('#plan-error').show().text(msg);
                }
            });
    }

    function showConflicts(data) {
        $('#conflict-gap').text(data.min_gap_hours);
        const $list = $('#conflict-list').empty();
        (data.conflicts || []).forEach(c => {
            $list.append(
                '<div class="mm-conflict-row">' +
                '<strong>' + c.moon_name + '</strong> (' + c.structure_name + ')<br>' +
                '<small>' + c.arrival + ' — ' + (c.type === 'actual' ? 'live extraction' : 'planned') +
                ', ' + Math.abs(c.gap_hours) + 'h apart</small></div>'
            );
        });
        $('#planModal').modal('hide');
        $('#conflictModal').appendTo('body').modal('show');
    }

    $('#btn-save-plan').on('click', () => submitPlan(false));
    $('#btn-confirm-conflict').on('click', function () {
        $('#conflictModal').modal('hide');
        if (pendingPayload) {
            postPlan(Object.assign({}, pendingPayload.payload, { confirmed: 1 }),
                     pendingPayload.isUpdate, pendingPayload.planId)
                .done(() => window.location.reload())
                .fail(() => alert('Save failed.'));
        }
    });

    $('#btn-delete-plan').on('click', function () {
        const id = $(this).data('id');
        if (!confirm('Remove this planned pull?')) return;
        $.ajax({
            url: routes.destroy + '/' + id,
            method: 'DELETE',
            data: { _token: CSRF },
        }).done(() => window.location.reload()).fail(() => alert('Delete failed.'));
    });
});
</script>
@endpush
