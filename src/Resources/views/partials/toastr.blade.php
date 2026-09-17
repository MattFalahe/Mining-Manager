{{--
    Toastr assets, plus a fallback for when they are not there.

    Every interactive page in this plugin reports success and failure through
    toastr, but SeAT's layout does not ship it and neither did we, so the
    global was undefined on every page. In an AJAX success handler that means
    the very first line throws, location.reload() never runs, and a button
    that worked perfectly well looks completely dead. Include this anywhere a
    view calls toastr.

    Loaded after jQuery because SeAT puts its script tags above @stack('javascript').
--}}
@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/vendor/toastr.min.css') }}">
@endpush

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/toastr.min.js') }}"></script>
<script>
(function () {
    'use strict';

    if (window.toastr) {
        toastr.options = {
            closeButton: true,
            progressBar: true,
            positionClass: 'toast-bottom-right',
            timeOut: 5000,
            extendedTimeOut: 2000,
        };
        return;
    }

    // The vendor asset did not load: assets never published, a 404 after a
    // partial upgrade, an extension blocking it. Rather than let every button
    // on the page die silently on the first toastr call the way they did
    // before, stand in with something that needs no CSS and no dependencies.
    console.warn('[Mining Manager] toastr did not load; using the built-in fallback. Check that plugin assets are published.');

    var HOST_ID = 'mm-toast-host';
    var COLORS = {
        success: '#1abc9c',
        info: '#3498db',
        warning: '#e67e22',
        error: '#c0392b',
    };

    function host() {
        var el = document.getElementById(HOST_ID);
        if (el) {
            return el;
        }

        el = document.createElement('div');
        el.id = HOST_ID;
        el.style.cssText = [
            'position:fixed', 'bottom:16px', 'right:16px', 'z-index:2147483647',
            'display:flex', 'flex-direction:column', 'gap:8px',
            'max-width:min(380px, calc(100vw - 32px))', 'pointer-events:none',
        ].join(';');
        document.body.appendChild(el);

        return el;
    }

    function show(level, message, title) {
        try {
            var toast = document.createElement('div');
            toast.setAttribute('role', 'status');
            toast.style.cssText = [
                'pointer-events:auto', 'cursor:pointer',
                'background:' + (COLORS[level] || COLORS.info),
                'color:#fff', 'padding:12px 14px', 'border-radius:4px',
                'font-size:14px', 'line-height:1.4', 'word-break:break-word',
                'box-shadow:0 2px 8px rgba(0,0,0,.4)',
            ].join(';');

            if (title) {
                var heading = document.createElement('strong');
                heading.style.cssText = 'display:block;margin-bottom:2px';
                heading.textContent = String(title);
                toast.appendChild(heading);
            }

            // textContent, never innerHTML: some of these messages carry
            // server-supplied text.
            toast.appendChild(document.createTextNode(String(message == null ? '' : message)));

            var remove = function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            };

            toast.addEventListener('click', remove);
            host().appendChild(toast);
            window.setTimeout(remove, level === 'error' ? 9000 : 5000);
        } catch (e) {
            // Absolute last resort. A thrown toast must never be the reason a
            // caller's location.reload() does not run.
            console.log('[Mining Manager] ' + level + ': ' + message);
        }
    }

    window.toastr = {
        success: function (m, t) { show('success', m, t); },
        info: function (m, t) { show('info', m, t); },
        warning: function (m, t) { show('warning', m, t); },
        error: function (m, t) { show('error', m, t); },
        clear: function () {
            var el = document.getElementById(HOST_ID);
            if (el) { el.innerHTML = ''; }
        },
        remove: function () { window.toastr.clear(); },
        options: {},
    };
})();
</script>
@endpush
