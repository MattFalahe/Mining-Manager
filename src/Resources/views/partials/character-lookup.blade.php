{{--
    Characters this page could not name yet are being looked up in the
    background, so the page shows them as in progress instead of waiting on
    ESI. Say so, and refresh once they are in, unless the reader is busy in a
    form, in which case just tell them.
--}}
@php
    $pendingCharacters = app(\MiningManager\Services\Character\AffiliationResolutionService::class)->requestedThisPage();
@endphp
@if (!empty($pendingCharacters))
@push('javascript')
<script>
(function () {
    'use strict';

    var ids = @json(array_values($pendingCharacters));
    var url = @json(route('mining-manager.characters.pending'));
    var readyText = @json(trans('mining-manager::common.characters_ready_notice'));
    var checks = 0;

    if (window.toastr) {
        toastr.info(@json(trans('mining-manager::common.characters_pending_notice', ['count' => count($pendingCharacters)])), '', { timeOut: 0, extendedTimeOut: 0 });
    }

    function check() {
        checks++;
        $.getJSON(url, { ids: ids.join(',') }).done(function (data) {
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

    setTimeout(check, 10000);
})();
</script>
@endpush
@endif
