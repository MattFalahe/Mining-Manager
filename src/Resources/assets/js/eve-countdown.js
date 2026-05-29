/**
 * Mining Manager — Live-tick countdown widget.
 *
 * Convention: any element that should live-update its "in X" / "X ago"
 * relative-time text carries `class="eve-countdown" data-target="ISO"`:
 *
 *     <span class="eve-countdown" data-target="2026-05-26T18:00:00Z">
 *       in 2 hours
 *     </span>
 *
 * The server-rendered text (typically Carbon::diffForHumans()) is replaced
 * on DOM ready with a JS-formatted equivalent that ticks every second.
 * Color class is applied/swapped based on time-to-target:
 *
 *     >24h     .eve-countdown-distant   (green)
 *     1-24h    .eve-countdown-near      (yellow)
 *     <1h      .eve-countdown-imminent  (red, bold)
 *     past     .eve-countdown-past      (muted grey)
 *
 * Format (richer than diffForHumans, picks units to keep ~2 digits per unit):
 *     diff > 1d   →  "in 5d 4h"     or  "5d 4h ago"
 *     diff 1h-1d  →  "in 5h 30m"    or  "5h 30m ago"
 *     diff 1m-1h  →  "in 30m 15s"   or  "30m 15s ago"
 *     diff <1m    →  "in 30s"       or  "30s ago"
 *
 * Companion to eve-time.js (which handles absolute-time tooltip/pill
 * conversion). Both can be present on the same page; they target
 * different elements (.eve-time vs .eve-countdown).
 *
 * AJAX content: call `window.EveCountdown.refresh(rootEl)` after new
 * .eve-countdown nodes land (DataTables redraws, calendar event renders).
 */
(function () {
    'use strict';

    var registry = [];

    var ONE_SECOND = 1000;
    var ONE_MINUTE = 60 * ONE_SECOND;
    var ONE_HOUR   = 60 * ONE_MINUTE;
    var ONE_DAY    = 24 * ONE_HOUR;

    function format(diff) {
        // diff = now - target. Positive = past. Negative = future.
        var future = diff < 0;
        var abs    = Math.abs(diff);
        var prefix = future ? 'in '   : '';
        var suffix = future ? ''      : ' ago';

        if (abs < ONE_MINUTE) {
            var s = Math.floor(abs / ONE_SECOND);
            return prefix + s + 's' + suffix;
        }
        if (abs < ONE_HOUR) {
            var m  = Math.floor(abs / ONE_MINUTE);
            var s2 = Math.floor((abs - m * ONE_MINUTE) / ONE_SECOND);
            return prefix + m + 'm ' + s2 + 's' + suffix;
        }
        if (abs < ONE_DAY) {
            var h  = Math.floor(abs / ONE_HOUR);
            var m2 = Math.floor((abs - h * ONE_HOUR) / ONE_MINUTE);
            return prefix + h + 'h ' + m2 + 'm' + suffix;
        }
        var d  = Math.floor(abs / ONE_DAY);
        var h2 = Math.floor((abs - d * ONE_DAY) / ONE_HOUR);
        return prefix + d + 'd ' + h2 + 'h' + suffix;
    }

    var COLOR_CLASSES = [
        'eve-countdown-distant',
        'eve-countdown-near',
        'eve-countdown-imminent',
        'eve-countdown-past',
    ];

    function colorFor(diff) {
        // diff = now - target. Positive = past. Negative = future.
        if (diff > 0)            return 'eve-countdown-past';
        var abs = Math.abs(diff);
        if (abs < ONE_HOUR)      return 'eve-countdown-imminent';
        if (abs < ONE_DAY)       return 'eve-countdown-near';
        return 'eve-countdown-distant';
    }

    function tickOne(state) {
        var diff = Date.now() - state.target.getTime();
        state.el.textContent = format(diff);

        var next = colorFor(diff);
        if (next !== state.lastClass) {
            // Remove every color class, then add the current one. Cheap and
            // safe against external mutations.
            for (var i = 0; i < COLOR_CLASSES.length; i++) {
                state.el.classList.remove(COLOR_CLASSES[i]);
            }
            state.el.classList.add(next);
            state.lastClass = next;
        }
    }

    function init(el) {
        if (!el || el.dataset.eveCountdownInit === '1') return;
        var iso = el.getAttribute('data-target');
        if (!iso) return;
        var target = new Date(iso);
        if (isNaN(target.getTime())) return;

        el.dataset.eveCountdownInit = '1';
        var state = { el: el, target: target, lastClass: '' };
        tickOne(state);
        registry.push(state);
    }

    function refresh(root) {
        var scope = root || document;
        var nodes = scope.querySelectorAll('.eve-countdown[data-target]');
        for (var i = 0; i < nodes.length; i++) {
            init(nodes[i]);
        }
    }

    // Single 1-second loop ticks every registered countdown. With ~50 active
    // countdowns on the busiest MM page (events index + my-events combined)
    // this is well under 1ms per tick — cheap.
    function tickLoop() {
        for (var i = 0; i < registry.length; i++) {
            tickOne(registry[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { refresh(); });
    } else {
        refresh();
    }
    setInterval(tickLoop, ONE_SECOND);

    window.EveCountdown = {
        refresh:    refresh,
        refreshOne: init,
    };
})();
