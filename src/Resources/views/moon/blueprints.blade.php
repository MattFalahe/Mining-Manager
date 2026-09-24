@extends('web::layouts.grids.12')

@section('title', 'Moon Blueprints')
@section('page_header', 'Moon Blueprints')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=8">
<style>
    /* The grid is the blueprint: a row per week, a column per weekday. Cells
       set their own colours so a skin cannot wash the pattern out. */
    .moon-blueprints-page .mm-bp-grid { width: 100%; table-layout: fixed; }
    .moon-blueprints-page .mm-bp-grid th {
        text-align: center;
        font-size: 0.8rem;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: #cbd5e1;
        border-bottom: 2px solid #4b5563;
        padding: 0.4rem 0.25rem;
    }
    .moon-blueprints-page .mm-bp-grid td {
        vertical-align: top;
        border: 1px solid #374151;
        background: rgba(17, 24, 39, 0.6);
        padding: 0.35rem;
        height: 96px;
    }
    .moon-blueprints-page .mm-bp-week-label {
        width: 78px;
        background: rgba(102, 126, 234, 0.15) !important;
        border-left: 4px solid #667eea !important;
        color: #e2e8f0;
        font-weight: 600;
    }
    .moon-blueprints-page .mm-bp-slot {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        border-radius: 4px;
        padding: 0.2rem 0.35rem;
        margin-bottom: 0.25rem;
        font-size: 0.75rem;
        line-height: 1.25;
    }
    .moon-blueprints-page .mm-bp-slot { cursor: pointer; }
    .moon-blueprints-page .mm-bp-slot:hover { filter: brightness(1.15); }
    .moon-blueprints-page .mm-bp-slot .mm-bp-slot-remove {
        float: right;
        cursor: pointer;
        color: #fff;
        background: rgba(0, 0, 0, 0.35);
        border-radius: 3px;
        line-height: 1;
        padding: 0 4px;
        margin-left: 4px;
    }
    .moon-blueprints-page .mm-bp-slot .mm-bp-slot-remove:hover { background: #dc3545; }
    /* Unanchored, destroyed or handed over: still in the pattern, but nothing
       can pull from it. */
    .moon-blueprints-page .mm-bp-slot-missing {
        background: rgba(220, 53, 69, 0.85);
        border: 1px dashed #fca5a5;
    }
    .moon-blueprints-page .mm-bp-slot small { color: #e9d5ff; }
    .moon-blueprints-page .mm-bp-add {
        width: 100%;
        border: 1px dashed #4b5563;
        background: transparent;
        color: #9ca3af;
        border-radius: 4px;
        font-size: 0.75rem;
        padding: 0.15rem;
    }
    .moon-blueprints-page .mm-bp-add:hover { border-color: #667eea; color: #cbd5e1; }
    .moon-blueprints-page .mm-bp-card {
        border-left: 4px solid #667eea;
        background: rgba(30, 41, 59, 0.7);
        border-radius: 6px;
        padding: 0.6rem 0.75rem;
        margin-bottom: 0.6rem;
        cursor: pointer;
    }
    .moon-blueprints-page .mm-bp-card.active { background: rgba(102, 126, 234, 0.22); }
    .moon-blueprints-page .mm-bp-card h6 { color: #f3f4f6; margin-bottom: 0.2rem; }
    .moon-blueprints-page .mm-note {
        border-left: 4px solid #667eea;
        border-radius: 6px;
        background: rgba(102, 126, 234, 0.15);
        color: #e2e8f0 !important;
        font-size: 0.875rem;
        padding: 0.6rem 0.9rem;
        margin-bottom: 0.75rem;
    }
    .moon-blueprints-page .mm-note-warn {
        border-left-color: #f59e0b;
        background: rgba(245, 158, 11, 0.15);
    }
    .moon-blueprints-page .mm-preview-skip { color: #9ca3af; }
    .moon-blueprints-page .mm-preview-clash { color: #fbbf24; }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard moon-blueprints-page">

{{-- TAB NAVIGATION (shared moon sub-nav) --}}
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
                <a class="nav-link" href="{{ route('mining-manager.moon.planner') }}">
                    <i class="fas fa-calendar-check"></i> {{ trans('mining-manager::menu.moon_planner') }}
                    <span class="badge badge-primary ml-1" style="font-size: 0.6em;">Moon Manager</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('mining-manager.moon.blueprints') }}">
                    <i class="fas fa-drafting-compass"></i> Blueprints
                    <span class="badge badge-primary ml-1" style="font-size: 0.6em;">Moon Manager</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">

    @if(!$corporationId)
        <div class="mm-note mm-note-warn">
            <i class="fas fa-exclamation-triangle"></i>
            No <strong>Moon Owner Corporation</strong> is configured yet. Set one in
            <a href="{{ route('mining-manager.settings.index') }}">Settings &rsaquo; General</a>
            so a blueprint knows which refineries it can use.
        </div>
    @else

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h4 class="mb-0"><i class="fas fa-drafting-compass text-primary"></i> Moon Blueprints</h4>
            <small class="text-muted">
                A repeating pattern of pulls: which refinery, which day, what time, over
                {{ $maxWeeks }} weeks at most. Apply one to the planner from any date, for as many
                cycles as you want. All times are <strong>EVE (UTC)</strong>.
            </small>
        </div>
        <div>
            <button type="button" class="btn btn-sm btn-success" id="btn-new-blueprint">
                <i class="fas fa-plus"></i> New Blueprint
            </button>
            <a class="btn btn-sm btn-outline-secondary ml-1" href="{{ route('mining-manager.moon.planner') }}">
                <i class="fas fa-calendar-check"></i> Back to Planner
            </a>
        </div>
    </div>

    <div class="row">
        {{-- Saved blueprints --}}
        <div class="col-md-3">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-layer-group"></i> Saved</h3>
                </div>
                <div class="card-body" id="bp-list"></div>
            </div>
        </div>

        {{-- The pattern --}}
        <div class="col-md-9">
            <div class="card card-dark">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title"><i class="fas fa-th"></i> <span id="bp-editor-title">New blueprint</span></h3>
                    <div>
                        <button type="button" class="btn btn-xs btn-outline-danger mr-1" id="btn-delete-blueprint" style="display:none;">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                        <button type="button" class="btn btn-xs btn-outline-primary mr-1" id="btn-apply-blueprint" style="display:none;">
                            <i class="fas fa-calendar-plus"></i> Apply to Planner
                        </button>
                        <button type="button" class="btn btn-xs btn-mm-primary" id="btn-save-blueprint">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-row mb-3">
                        <div class="col-md-6">
                            <label for="bp-name" class="small text-muted mb-1">Name</label>
                            <input type="text" class="form-control form-control-sm" id="bp-name"
                                   maxlength="100" placeholder="Standard fortnight">
                        </div>
                        <div class="col-md-3">
                            <label for="bp-weeks" class="small text-muted mb-1">Length</label>
                            <select class="form-control form-control-sm" id="bp-weeks">
                                @for($w = 1; $w <= $maxWeeks; $w++)
                                    <option value="{{ $w }}">{{ $w }} {{ $w === 1 ? 'week' : 'weeks' }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <span class="small text-muted" id="bp-slot-count">No pulls yet</span>
                        </div>
                    </div>

                    <div id="bp-missing" class="mm-note mm-note-warn" style="display:none;">
                        <span id="bp-missing-text"></span>
                        <button type="button" class="btn btn-xs btn-outline-danger ml-2" id="btn-prune-missing">
                            <i class="fas fa-broom"></i> Remove them
                        </button>
                    </div>

                    <div id="bp-error" class="mm-note mm-note-warn" style="display:none;"></div>

                    <div class="table-responsive">
                        <table class="mm-bp-grid">
                            <thead>
                                <tr>
                                    <th class="mm-bp-week-label"></th>
                                    <th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th><th>Sun</th>
                                </tr>
                            </thead>
                            <tbody id="bp-grid-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    </div>
</div>

{{-- Add a pull to a cell --}}
<div class="modal fade" id="slotModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-moon"></i> <span id="slot-title">Add pull</span></h5>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2" id="slot-when"></p>
                <div class="form-group">
                    <label for="slot-structure" class="small text-muted mb-1">Refinery</label>
                    <select class="form-control form-control-sm" id="slot-structure"></select>
                </div>
                <div class="form-group mb-0">
                    <label for="slot-time" class="small text-muted mb-1">Time (EVE)</label>
                    <input type="time" class="form-control form-control-sm" id="slot-time" value="17:00">
                    <small class="text-muted" id="slot-local"></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-danger mr-auto" id="btn-remove-slot" style="display:none;">
                    <i class="fas fa-trash"></i> Remove
                </button>
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-success" id="btn-add-slot">Add</button>
            </div>
        </div>
    </div>
</div>

@include('mining-manager::moon.partials._blueprint_apply', ['blueprints' => $blueprintList])

</div>
@endsection

@push('javascript')
<script>
const CSRF = '{{ csrf_token() }}';
const BP_ROUTES = {
    store: '{{ route('mining-manager.moon.blueprints.store') }}',
    base: '{{ route('mining-manager.moon.blueprints') }}',
    planner: '{{ route('mining-manager.moon.planner') }}',
    prune: '{{ route('mining-manager.moon.blueprints.prune') }}',
};
const MAX_WEEKS = {{ $maxWeeks }};
const REFINERIES = @json($refineries ?? []);
let blueprints = @json($blueprints ?? []);

// The blueprint being edited. id null until it has been saved once.
let current = { id: null, name: '', weeks: 2, slots: [] };
// What the slot dialog is working on: a new pull in a cell, or one already
// in the pattern.
let slotEditor = null;   // {mode: 'add'|'edit', week, day, slot}
let previewed = null;     // {start, cycles} the preview on screen was built from

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function refinery(structureId) {
    return REFINERIES.find(r => r.structure_id === structureId) || null;
}

function refineryName(structureId) {
    const hit = refinery(structureId);
    return hit ? hit.structure_name : ('Structure ' + structureId);
}

const RARITY_CLASS = { R4: 'badge-r4', R8: 'badge-r8', R16: 'badge-r16', R32: 'badge-r32', R64: 'badge-r64' };

// ---- The saved list ----
function renderList() {
    const $list = $('#bp-list').empty();
    if (!blueprints.length) {
        $list.append('<p class="text-muted small mb-0">Nothing saved yet. Build a pattern and save it.</p>');
        return;
    }
    blueprints.forEach(bp => {
        const ahead = bp.planned_ahead
            ? '<br><small class="text-muted">' + bp.planned_ahead + ' pull(s) planned ahead</small>'
            : '';
        $list.append(
            '<div class="mm-bp-card' + (current.id === bp.id ? ' active' : '') + '" data-id="' + bp.id + '">' +
            '<h6>' + $('<div>').text(bp.name).html() + '</h6>' +
            '<small class="text-muted">' + bp.weeks + (bp.weeks === 1 ? ' week' : ' weeks') +
            ', ' + bp.slots.length + ' pull(s)</small>' + ahead +
            '</div>'
        );
    });
}

// ---- The grid ----
function renderGrid() {
    const $body = $('#bp-grid-body').empty();
    for (let week = 1; week <= current.weeks; week++) {
        const $row = $('<tr>').append(
            $('<td class="mm-bp-week-label">').text('Week ' + week)
        );
        for (let day = 1; day <= 7; day++) {
            const $cell = $('<td>');
            current.slots
                .filter(s => s.week_number === week && s.day_of_week === day)
                .sort((a, b) => a.time_of_day.localeCompare(b.time_of_day))
                .forEach(s => {
                    const rig = refinery(s.structure_id);
                    const $chip = $('<div class="mm-bp-slot">')
                        .attr('title', rig ? 'Click to edit this pull' : 'This refinery is no longer owned')
                        .data('slot', s)
                        .append(
                            $('<span class="mm-bp-slot-remove" title="Remove">&times;</span>'),
                            $('<div>').text(s.time_of_day)
                        );
                    if (!rig) { $chip.addClass('mm-bp-slot-missing'); }
                    if (rig && rig.rarity) {
                        $chip.append(
                            $('<span class="badge mr-1">')
                                .addClass(RARITY_CLASS[rig.rarity] || 'badge-secondary')
                                .text(rig.rarity)
                        );
                    }
                    $chip.append($('<small>').text(refineryName(s.structure_id)));
                    $cell.append($chip);
                });
            $cell.append(
                $('<button type="button" class="mm-bp-add">+</button>')
                    .attr('data-week', week).attr('data-day', day)
            );
            $row.append($cell);
        }
        $body.append($row);
    }

    const count = current.slots.length;
    const left = REFINERIES.length - count;
    $('#bp-slot-count').text(
        count
            ? count + ' of ' + REFINERIES.length + ' refineries placed, ' + left + ' left'
            : 'No pulls yet, ' + REFINERIES.length + ' refineries to place'
    );
    const gone = current.slots.filter(sl => !refinery(sl.structure_id)).length;
    $('#bp-missing').toggle(gone > 0);
    $('#bp-missing-text').text(
        gone === 1
            ? 'One refinery in this blueprint is no longer owned, so nothing can pull from it.'
            : gone + ' refineries in this blueprint are no longer owned, so nothing can pull from them.'
    );

    $('#btn-apply-blueprint').toggle(!!current.id && count > 0);
    $('#btn-delete-blueprint').toggle(!!current.id);
    $('#bp-editor-title').text(current.id ? current.name : 'New blueprint');
}

function loadBlueprint(bp) {
    current = {
        id: bp.id,
        name: bp.name,
        weeks: bp.weeks,
        slots: bp.slots.map(s => ({
            id: s.id,
            week_number: s.week_number,
            day_of_week: s.day_of_week,
            time_of_day: s.time_of_day,
            structure_id: s.structure_id,
        })),
    };
    $('#bp-name').val(bp.name);
    $('#bp-weeks').val(bp.weeks);
    $('#bp-error').hide();
    renderList();
    renderGrid();
}

function newBlueprint() {
    current = { id: null, name: '', weeks: 2, slots: [] };
    $('#bp-name').val('');
    $('#bp-weeks').val(2);
    $('#bp-error').hide();
    renderList();
    renderGrid();
}

$(document).on('click', '.mm-bp-card', function () {
    const bp = blueprints.find(b => b.id === $(this).data('id'));
    if (bp) { loadBlueprint(bp); }
});

$('#btn-new-blueprint').on('click', newBlueprint);

$('#bp-weeks').on('change', function () {
    const weeks = parseInt($(this).val(), 10) || 1;
    // Shortening the pattern drops whatever sat in the weeks that went.
    current.slots = current.slots.filter(s => s.week_number <= weeks);
    current.weeks = weeks;
    renderGrid();
});

$('#bp-name').on('input', function () { current.name = $(this).val(); });

// ---- Slots ----
function openSlotDialog(editor) {
    slotEditor = editor;
    const editing = editor.mode === 'edit';

    $('#slot-title').text(editing ? 'Edit pull' : 'Add pull');
    $('#btn-add-slot').text(editing ? 'Save' : 'Add');
    $('#btn-remove-slot').toggle(editing);
    $('#slot-when').text('Week ' + editor.week + ', ' + DAYS[editor.day - 1]);

    // A refinery already in the pattern is not offered again: one moon, one
    // pull per blueprint, so nothing is scheduled twice by accident. The one
    // being edited stays in its own list, obviously.
    const used = current.slots
        .filter(sl => sl !== editor.slot)
        .map(sl => sl.structure_id);
    const free = REFINERIES.filter(r => used.indexOf(r.structure_id) === -1);

    if (!free.length) {
        $('#bp-error').addClass('mm-note-warn').show()
            .text('Every refinery is already in this blueprint.');
        return;
    }

    const $sel = $('#slot-structure').empty();
    free.forEach(r => $sel.append(
        $('<option>').val(r.structure_id).text((r.rarity ? r.rarity + ' — ' : '') + r.structure_name)
    ));

    $('#slot-structure').val(editing ? editor.slot.structure_id : $sel.find('option').first().val());
    $('#slot-time').val(editing ? editor.slot.time_of_day : '17:00');
    updateSlotLocal();
    // The modal is moved to <body>, outside the wrapper the plugin's styles are
    // scoped to, so it takes the wrapper classes with it or it renders unstyled.
    $('#slotModal')
        .appendTo('body')
        .addClass('mining-manager-wrapper mining-dashboard moon-blueprints-page')
        .modal('show');
}

// An empty cell adds; a pull already in the pattern opens for editing.
$(document).on('click', '.mm-bp-add', function () {
    openSlotDialog({
        mode: 'add',
        week: parseInt($(this).data('week'), 10),
        day: parseInt($(this).data('day'), 10),
    });
});

$(document).on('click', '.mm-bp-slot', function (event) {
    if ($(event.target).hasClass('mm-bp-slot-remove')) { return; }
    const slot = $(this).data('slot');
    if (slot) {
        openSlotDialog({ mode: 'edit', week: slot.week_number, day: slot.day_of_week, slot: slot });
    }
});

$(document).on('click', '.mm-bp-slot-remove', function (event) {
    event.stopPropagation();
    const slot = $(this).closest('.mm-bp-slot').data('slot');
    current.slots = current.slots.filter(s => s !== slot);
    renderGrid();
});

// EVE time is what the in-game scheduler takes, so the modal confirms what
// that is locally rather than quietly converting.
function updateSlotLocal() {
    const value = $('#slot-time').val();
    if (!value) { $('#slot-local').text(''); return; }
    const [h, m] = value.split(':').map(Number);
    const d = new Date();
    d.setUTCHours(h, m, 0, 0);
    $('#slot-local').text('That is ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ' your time.');
}
$(document).on('input change', '#slot-time', updateSlotLocal);

$('#btn-add-slot').on('click', function () {
    const structureId = parseInt($('#slot-structure').val(), 10);
    const time = $('#slot-time').val();
    if (!structureId || !time || !slotEditor) { return; }

    if (slotEditor.mode === 'edit') {
        // Keeps its id, so the blueprint can still tell the server which pull
        // this is and carry the change to the calendar.
        slotEditor.slot.structure_id = structureId;
        slotEditor.slot.time_of_day = time;
    } else {
        current.slots.push({
            id: null,
            week_number: slotEditor.week,
            day_of_week: slotEditor.day,
            time_of_day: time,
            structure_id: structureId,
        });
    }

    $('#slotModal').modal('hide');
    renderGrid();
});

$('#btn-remove-slot').on('click', function () {
    if (!slotEditor || slotEditor.mode !== 'edit') { return; }
    current.slots = current.slots.filter(sl => sl !== slotEditor.slot);
    $('#slotModal').modal('hide');
    renderGrid();
});

// ---- Save / delete ----
$('#btn-save-blueprint').on('click', function () {
    const name = ($('#bp-name').val() || '').trim();
    if (!name) {
        $('#bp-error').show().text('Give the blueprint a name first.');
        return;
    }

    // Pulls this blueprint already put on the calendar follow it, if you say
    // so. Anything already pulling, or already past, is left alone either way.
    const saved = blueprints.find(b => b.id === current.id);
    const ahead = saved ? saved.planned_ahead : 0;
    let resync = false;
    if (current.id && ahead > 0) {
        resync = confirm(
            'This blueprint has ' + ahead + ' pull(s) planned ahead.\n\n' +
            'OK: update them to match the blueprint.\n' +
            'Cancel: save the blueprint and leave them as they are.\n\n' +
            'Pulls already matched to a real extraction never move.'
        );
    }

    $.ajax({
        url: BP_ROUTES.store,
        method: 'POST',
        data: {
            _token: CSRF,
            id: current.id,
            name: name,
            weeks: current.weeks,
            slots: current.slots,
            resync: resync ? 1 : 0,
        },
    }).done(res => {
        blueprints = res.blueprints;
        BlueprintApply.replace(blueprints.map(b => ({
            id: b.id, name: b.name, weeks: b.weeks,
            slot_count: b.slots.length, planned_ahead: b.planned_ahead,
        })));
        const justSaved = blueprints.find(b => b.id === res.blueprint_id);
        if (justSaved) { loadBlueprint(justSaved); }
        if (res.resync) {
            const r = res.resync;
            $('#bp-error').removeClass('mm-note-warn').show().text(
                'Saved. On the planner: ' + r.moved + ' moved, ' + r.added + ' added, ' +
                r.removed + ' removed, ' + r.kept + ' left as they were.'
            );
        } else {
            $('#bp-error').hide();
        }
    }).fail(xhr => {
        $('#bp-error').addClass('mm-note-warn');
        const msg = (xhr.responseJSON && (xhr.responseJSON.error
            || Object.values(xhr.responseJSON.errors || {})[0])) || 'Save failed.';
        $('#bp-error').show().text(msg);
    });
});

$('#btn-delete-blueprint').on('click', function () {
    if (!current.id) { return; }
    if (!confirm('Delete this blueprint? Pulls it already wrote stay on the planner.')) { return; }

    $.ajax({
        url: BP_ROUTES.base + '/' + current.id,
        method: 'POST',
        data: { _token: CSRF, _method: 'DELETE' },
    }).done(res => {
        blueprints = res.blueprints;
        BlueprintApply.replace(blueprints.map(b => ({
            id: b.id, name: b.name, weeks: b.weeks,
            slot_count: b.slots.length, planned_ahead: b.planned_ahead,
        })));
        newBlueprint();
    }).fail(() => $('#bp-error').show().text('Could not delete that blueprint.'));
});

$('#btn-prune-missing').on('click', function () {
    if (!confirm('Remove every refinery that is no longer owned from your blueprints, '
        + 'along with the pulls they had planned ahead?')) { return; }

    $.ajax({
        url: BP_ROUTES.prune,
        method: 'POST',
        data: { _token: CSRF },
    }).done(res => {
        blueprints = res.blueprints;
        const still = blueprints.find(b => b.id === current.id);
        if (still) { loadBlueprint(still); } else { newBlueprint(); }
        $('#bp-error').removeClass('mm-note-warn').show().text(
            'Removed ' + res.removed.slots + ' refinery slot(s) and ' + res.removed.plans + ' planned pull(s).'
        );
    }).fail(() => $('#bp-error').addClass('mm-note-warn').show().text('Could not remove them.'));
});

// Applying is the same dialog the planner uses, opened on this blueprint.
$('#btn-apply-blueprint').on('click', function () {
    if (current.id) { BlueprintApply.open(current.id); }
});

$(document).ready(function () {
    renderList();
    renderGrid();
});
</script>
@endpush
