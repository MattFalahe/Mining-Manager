{{--
    The apply dialog, shared by the planner and the Blueprints tab.

    Both places ask the same three questions (which blueprint, from when, how
    many cycles) and both show the same preview before anything is written, so
    they share one dialog rather than drifting apart.

    Expects $blueprints: the list from MoonRotationService::listForCorporation.
--}}
<div class="modal fade" id="blueprintApplyModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-drafting-compass"></i> Plan from a blueprint</h5>
                <button type="button" class="close text-light" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row mb-2">
                    <div class="col-md-12 mb-2">
                        <label for="ba-blueprint" class="small text-muted mb-1">Blueprint</label>
                        <select class="form-control form-control-sm" id="ba-blueprint"></select>
                    </div>
                    <div class="col-md-5">
                        <label for="ba-start" class="small text-muted mb-1">Start from</label>
                        <input type="date" class="form-control form-control-sm" id="ba-start">
                    </div>
                    <div class="col-md-4">
                        <label for="ba-cycles" class="small text-muted mb-1">Cycles</label>
                        <input type="number" class="form-control form-control-sm" id="ba-cycles" min="1" value="6">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-block" id="ba-preview">
                            <i class="fas fa-eye"></i> Preview
                        </button>
                    </div>
                </div>

                <div class="custom-control custom-checkbox mb-2">
                    <input type="checkbox" class="custom-control-input" id="ba-takeover">
                    <label class="custom-control-label small" for="ba-takeover">
                        Make this blueprint the plan for these weeks
                        <span class="text-muted d-block">
                            Anything else planned in them is removed, including days the blueprint
                            does not use. Pulls that are already running are left alone.
                        </span>
                    </label>
                </div>

                <div class="alert alert-info py-2 px-3 small mb-2" id="ba-summary" style="display:none;"></div>
                <div class="alert alert-warning py-2 px-3 small mb-2" id="ba-error" style="display:none;"></div>

                <div id="ba-removals" style="display:none;">
                    <div class="alert alert-danger py-2 px-3 small mb-2" id="ba-removals-note"></div>
                    <div class="table-responsive mb-3" style="max-height: 200px; overflow-y: auto;">
                        <table class="table table-sm table-dark table-striped mb-0">
                            <thead><tr><th>When (EVE)</th><th>Refinery</th><th>Planned by</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 340px; overflow-y: auto;">
                    <table class="table table-sm table-dark table-striped mb-0" id="ba-table" style="display:none;">
                        <thead><tr><th>Cycle</th><th>When (EVE)</th><th>Refinery</th><th>Status</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-success" id="ba-confirm" disabled>
                    <i class="fas fa-check"></i> Write these pulls
                </button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
window.BlueprintApply = (function () {
    const CSRF = '{{ csrf_token() }}';
    const BASE = '{{ route('mining-manager.moon.blueprints') }}';
    const PLANNER = '{{ route('mining-manager.moon.planner') }}';
    let list = @json($blueprints ?? []);
    let previewed = null;   // the start and cycles the table on screen came from

    // How a pull that is about to be removed got onto the calendar.
    const SOURCES = {
        manual: 'hand',
        auto: 'auto-fill',
        rotation: 'another blueprint',
    };

    function selected() {
        return parseInt($('#ba-blueprint').val(), 10) || null;
    }

    function nextMonday() {
        const d = new Date();
        d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7));
        return d.toISOString().slice(0, 10);
    }

    function invalidate() {
        previewed = null;
        $('#ba-confirm').prop('disabled', true);
        $('#ba-removals').hide().find('tbody').empty();
    }

    function open(blueprintId) {
        const $sel = $('#ba-blueprint').empty();
        list.forEach(bp => {
            const label = bp.name + ' (' + bp.weeks + (bp.weeks === 1 ? ' week' : ' weeks') +
                ', ' + bp.slot_count + ' pull' + (bp.slot_count === 1 ? '' : 's') + ')';
            $sel.append($('<option>').val(bp.id).text(label));
        });
        if (blueprintId) { $sel.val(blueprintId); }

        $('#ba-start').val(nextMonday());
        $('#ba-takeover').prop('checked', false);
        $('#ba-summary').hide();
        $('#ba-error').hide();
        $('#ba-table').hide().find('tbody').empty();
        invalidate();

        // The dialog lives on <body>, outside the page wrapper the plugin's
        // styles are scoped to, so it carries the wrapper classes with it.
        $('#blueprintApplyModal')
            .appendTo('body')
            .addClass('mining-manager-wrapper mining-dashboard')
            .modal('show');
    }

    function failure(xhr, fallback) {
        return (xhr.responseJSON && (xhr.responseJSON.error
            || Object.values(xhr.responseJSON.errors || {})[0])) || fallback;
    }

    $(document).on('input change', '#ba-blueprint, #ba-start, #ba-cycles, #ba-takeover', invalidate);

    $(document).on('click', '#ba-preview', function () {
        const id = selected();
        const start = $('#ba-start').val();
        const cycles = parseInt($('#ba-cycles').val(), 10);
        const takeOver = $('#ba-takeover').is(':checked') ? 1 : 0;

        if (!id || !start || !cycles) {
            $('#ba-error').show().text('Pick a blueprint, a start date and how many cycles.');
            return;
        }

        $.ajax({
            url: BASE + '/' + id + '/preview',
            method: 'POST',
            data: { _token: CSRF, start_date: start, cycles: cycles, take_over: takeOver },
        }).done(res => {
            const $tbody = $('#ba-table').show().find('tbody').empty();
            res.rows.forEach(row => {
                let status = '<span class="text-success">will be planned</span>';
                if (row.skip) {
                    status = '<span class="text-muted">skipped, ' + row.skip + '</span>';
                } else if (row.clashes) {
                    status = '<span class="text-warning">planned, ' + row.clashes +
                        ' within the gap of another moon</span>';
                }
                $tbody.append(
                    '<tr><td>' + row.cycle + '</td><td>' + row.arrival + '</td><td>' +
                    $('<div>').text(row.structure_name).html() + '</td><td>' + status + '</td></tr>'
                );
            });

            const removals = res.removals || [];
            const $removed = $('#ba-removals').toggle(removals.length > 0).find('tbody').empty();
            removals.forEach(row => {
                $removed.append(
                    '<tr><td>' + row.arrival + '</td><td>' +
                    $('<div>').text(row.structure_name).html() + '</td><td>' +
                    (row.from_this_blueprint ? 'this blueprint' : SOURCES[row.source] || row.source) +
                    '</td></tr>'
                );
            });
            $('#ba-removals-note').html(
                '<i class="fas fa-exclamation-triangle"></i> <strong>' + removals.length +
                '</strong> planned pull(s) in these weeks will be removed first.'
            );

            $('#ba-summary').show().html(
                '<strong>' + res.summary.plan + '</strong> pull(s) would be written, ' +
                res.summary.skip + ' skipped' +
                (res.summary.clash ? ', ' + res.summary.clash + ' land within the minimum gap of another moon' : '') +
                '. Nothing is saved until you confirm.'
            );
            $('#ba-error').hide();
            previewed = { id: id, start: start, cycles: cycles, takeOver: takeOver };
            $('#ba-confirm').prop('disabled', res.summary.plan === 0 && removals.length === 0);
        }).fail(xhr => {
            $('#ba-error').show().text(failure(xhr, 'Could not work that out.'));
            invalidate();
        });
    });

    $(document).on('click', '#ba-confirm', function () {
        if (!previewed) { return; }

        const removing = $('#ba-removals').find('tbody tr').length;
        if (removing && !confirm('This removes ' + removing + ' planned pull(s) from those weeks '
            + 'and replaces them with the blueprint. Go ahead?')) {
            return;
        }

        $(this).prop('disabled', true);

        $.ajax({
            url: BASE + '/' + previewed.id + '/apply',
            method: 'POST',
            data: {
                _token: CSRF,
                start_date: previewed.start,
                cycles: previewed.cycles,
                take_over: previewed.takeOver,
            },
        }).done(() => {
            window.location = PLANNER;
        }).fail(xhr => {
            $('#ba-error').show().text(failure(xhr, 'Could not write those pulls.'));
        });
    });

    return {
        open: open,
        count: function () { return list.length; },
        replace: function (blueprints) { list = blueprints; },
    };
})();
</script>
@endpush
