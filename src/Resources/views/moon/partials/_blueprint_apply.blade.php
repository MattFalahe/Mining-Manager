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

                <div class="alert alert-info py-2 px-3 small mb-2" id="ba-summary" style="display:none;"></div>
                <div class="alert alert-warning py-2 px-3 small mb-2" id="ba-error" style="display:none;"></div>

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

    $(document).on('input change', '#ba-blueprint, #ba-start, #ba-cycles', invalidate);

    $(document).on('click', '#ba-preview', function () {
        const id = selected();
        const start = $('#ba-start').val();
        const cycles = parseInt($('#ba-cycles').val(), 10);

        if (!id || !start || !cycles) {
            $('#ba-error').show().text('Pick a blueprint, a start date and how many cycles.');
            return;
        }

        $.ajax({
            url: BASE + '/' + id + '/preview',
            method: 'POST',
            data: { _token: CSRF, start_date: start, cycles: cycles },
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

            $('#ba-summary').show().html(
                '<strong>' + res.summary.plan + '</strong> pull(s) would be written, ' +
                res.summary.skip + ' skipped' +
                (res.summary.clash ? ', ' + res.summary.clash + ' land within the minimum gap of another moon' : '') +
                '. Nothing is saved until you confirm.'
            );
            $('#ba-error').hide();
            previewed = { id: id, start: start, cycles: cycles };
            $('#ba-confirm').prop('disabled', res.summary.plan === 0);
        }).fail(xhr => {
            $('#ba-error').show().text(failure(xhr, 'Could not work that out.'));
            invalidate();
        });
    });

    $(document).on('click', '#ba-confirm', function () {
        if (!previewed) { return; }
        $(this).prop('disabled', true);

        $.ajax({
            url: BASE + '/' + previewed.id + '/apply',
            method: 'POST',
            data: { _token: CSRF, start_date: previewed.start, cycles: previewed.cycles },
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
