{{--
    Characters this page could not name yet are being looked up in the
    background, so the page shows them as in progress instead of waiting on
    ESI. Say so, and refresh once they are in, unless the reader is busy in a
    form, in which case just tell them. A chart or table the page loads after
    itself reports its own in a response header, and is handled the same way.
--}}
@php
    $pendingCharacters = app(\MiningManager\Services\Character\AffiliationResolutionService::class)->requestedThisPage();
@endphp
@push('javascript')
<script>
(function () {
    'use strict';

    if (window.mmCharacterLookup) {
        window.mmCharacterLookup.watch(@json(array_values($pendingCharacters)));
        return;
    }

    var url = @json(route('mining-manager.characters.pending'));
    var header = @json(\MiningManager\Http\Middleware\PendingCharactersHeader::HEADER);
    var pendingText = @json(trans('mining-manager::common.characters_pending_notice', ['count' => '__COUNT__']));
    var readyText = @json(trans('mining-manager::common.characters_ready_notice'));
    var ids = [];
    var checks = 0;
    var started = false;

    function check() {
        checks++;
        $.getJSON(url, { ids: ids.slice(0, 500).join(',') }).done(function (data) {
            if (data && Array.isArray(data.pending) && data.pending.length === 0) {
                var field = document.activeElement;
                if (field && /^(INPUT|TEXTAREA|SELECT)$/.test(field.tagName)) {
                    if (window.toastr) {
                        toastr.clear();
                        toastr.success(readyText, '', { timeOut: 0, extendedTimeOut: 0 });
                    }
                    return;
                }
                window.location.reload();
                return;
            }
            if (checks < 20) {
                setTimeout(check, 15000);
            }
        }).fail(function () {
            if (checks < 20) {
                setTimeout(check, 30000);
            }
        });
    }

    function watch(more) {
        (more || []).forEach(function (id) {
            id = parseInt(id, 10);
            if (id > 0 && ids.indexOf(id) === -1) {
                ids.push(id);
            }
        });

        if (!ids.length || started) {
            return;
        }

        started = true;
        if (window.toastr) {
            toastr.info(pendingText.replace('__COUNT__', ids.length), '', { timeOut: 0, extendedTimeOut: 0 });
        }
        setTimeout(check, 10000);
    }

    window.mmCharacterLookup = { watch: watch };

    $(document).ajaxComplete(function (event, xhr) {
        var listed = xhr && xhr.getResponseHeader ? xhr.getResponseHeader(header) : null;
        if (listed) {
            watch(listed.split(','));
        }
    });

    watch(@json(array_values($pendingCharacters)));
})();
</script>
@endpush
