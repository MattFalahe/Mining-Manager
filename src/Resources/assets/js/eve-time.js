/**
 * Mining Manager — EVE → local time auto-converter.
 *
 * Convention: any element rendered server-side that displays an EVE (UTC)
 * timestamp can opt into automatic local-time conversion by carrying:
 *
 *     <span class="eve-time" data-eve-time="2026-05-25T18:00:00Z">
 *       2026-05-25 18:00 EVE
 *     </span>
 *
 * Behaviour:
 *   - Default: a `title` attribute is set on the element carrying the local
 *     time formatted using the browser's locale + timezone. The visible text
 *     stays as the server-rendered EVE string (single source of truth).
 *   - Opt-in inline pill: add `data-show-local` to also append a small
 *     " · 14:00 local" suffix. Use this on high-priority surfaces (calendar
 *     event modal, active-extraction pages, my-events upcoming list).
 *
 * Conversion is fully browser-side via the standard `Intl.DateTimeFormat` and
 * `Date(iso)` parser. No per-user TZ configuration: the converter follows
 * whatever the browser reports. Works on every evergreen browser since 2018.
 *
 * Dynamic content: re-run the converter after AJAX content lands (DataTables
 * draws, FullCalendar event renders, modal openings) via:
 *
 *     window.EveTime.convert();           // whole document
 *     window.EveTime.convert(rootEl);     // limited scope
 *
 * Canonical source: SeAT Broadcast commit 924cfaf. Each plugin keeps its own
 * copy per the standalone-plugin principle.
 */
(function () {
    'use strict';

    // Long form (used in tooltips): "2026-05-25, 14:00 EDT"
    var longFmt;
    // Short form (used in inline pills): "14:00 local"
    var shortFmt;
    try {
        longFmt = new Intl.DateTimeFormat(undefined, {
            year:    'numeric',
            month:   '2-digit',
            day:     '2-digit',
            hour:    '2-digit',
            minute:  '2-digit',
            timeZoneName: 'short',
        });
        shortFmt = new Intl.DateTimeFormat(undefined, {
            hour:   '2-digit',
            minute: '2-digit',
        });
    } catch (e) {
        // Very old browser: leave the EVE text in place; nothing breaks.
        return;
    }

    function convertOne(el) {
        if (!el || el.dataset.eveTimeConverted === '1') {
            return; // idempotent — never double-process an element
        }
        var iso = el.getAttribute('data-eve-time');
        if (!iso) return;

        var d = new Date(iso);
        if (isNaN(d.getTime())) return;

        var longLocal  = longFmt.format(d);
        var shortLocal = shortFmt.format(d);

        // Tooltip carries the full local time (date + time + TZ abbr).
        // Always set — operators get it on any tagged surface for free.
        el.setAttribute('title', 'Local: ' + longLocal);

        // Opt-in inline pill — append " · HH:MM local" after the EVE text.
        // Keep it as a child <small> so CSS can style/hide it independently.
        if (el.hasAttribute('data-show-local')) {
            var pill = document.createElement('small');
            pill.className = 'eve-time-local text-muted';
            pill.style.marginLeft = '0.35rem';
            pill.style.opacity = '0.85';
            pill.textContent = '· ' + shortLocal + ' local';
            el.appendChild(pill);
        }

        el.dataset.eveTimeConverted = '1';
    }

    function convertAll(root) {
        var scope = root || document;
        var nodes = scope.querySelectorAll('.eve-time[data-eve-time]');
        for (var i = 0; i < nodes.length; i++) {
            convertOne(nodes[i]);
        }
    }

    // First pass on DOM ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { convertAll(); });
    } else {
        convertAll();
    }

    // Public entry point for AJAX-driven UI that lands new .eve-time elements
    // (DataTables row draws, FullCalendar event renders, modal popups).
    window.EveTime = {
        convert:    convertAll,
        convertOne: convertOne,
    };
})();
