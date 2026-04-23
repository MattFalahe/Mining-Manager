{{--
    Tax Compatibility Panel for Event Create/Edit Forms
    ====================================================
    Shows which ore categories are currently being taxed, warns if the
    selected event type has no tax surface, and suggests alternative
    event types that would be meaningful given the current tax settings.

    Required data:
      $taxCompat — array produced by EventController::buildTaxCompatibilityMap().

    Optional:
      $targetTypeSelect — CSS selector of the event-type <select>
                          (default: '#type'). Must exist in the same form.

    The panel reacts to <select> changes via inline JS so the warning
    updates without a page reload. Renders a static "current settings"
    row plus a dynamic status block that reflects the currently-chosen
    event type.
--}}
@php
    $targetSelect = $targetTypeSelect ?? '#type';
    $moonModeLabel = [
        'none' => 'Not taxed',
        'corp_only' => 'Corp-only moons taxed',
        'all' => 'All moons taxed',
    ][$taxCompat['moon_mode']] ?? 'Unknown';
    $moonBadge = [
        'none' => 'badge-secondary',
        'corp_only' => 'badge-info',
        'all' => 'badge-success',
    ][$taxCompat['moon_mode']] ?? 'badge-secondary';
    $moonIcon = $taxCompat['moon_mode'] === 'none' ? 'fa-times' : 'fa-check';
@endphp

<div class="event-tax-compat-panel mt-3" data-tax-compat='{!! json_encode($taxCompat) !!}'>

    {{-- Current tax settings summary --}}
    <div class="alert alert-secondary py-2 px-3 mb-2">
        <small class="d-block mb-1">
            <strong><i class="fas fa-sliders-h"></i> Currently-taxed ore categories:</strong>
        </small>
        <div>
            @foreach([
                'ore' => 'Regular Ore',
                'ice' => 'Ice',
                'gas' => 'Gas',
                'abyssal' => 'Abyssal',
                'triglavian' => 'Triglavian',
            ] as $cat => $label)
                @if($taxCompat['category_taxed'][$cat])
                    <span class="badge badge-success mr-1"><i class="fas fa-check"></i> {{ $label }}</span>
                @else
                    <span class="badge badge-secondary mr-1"><i class="fas fa-times"></i> {{ $label }}</span>
                @endif
            @endforeach
            <span class="badge {{ $moonBadge }} mr-1"><i class="fas {{ $moonIcon }}"></i> Moon &mdash; {{ $moonModeLabel }}</span>
        </div>
        <small class="text-muted d-block mt-1">
            Event tracking mirrors these settings &mdash; untaxed categories produce no participant data.
            Change in <a href="{{ route('mining-manager.settings.index') }}">Settings &rarr; Tax</a>.
        </small>
    </div>

    {{-- Dynamic per-event-type status (populated by JS) --}}
    <div id="event-type-compat-status" class="py-2 px-3 mb-2" style="display:none;"></div>

    {{-- Suggested event types --}}
    @php
        $suggested = collect($taxCompat['event_type_compat'])
            ->filter(fn($c) => $c['status'] === 'full')
            ->keys();
        $partial = collect($taxCompat['event_type_compat'])
            ->filter(fn($c) => $c['status'] === 'partial')
            ->keys();
        $empty = collect($taxCompat['event_type_compat'])
            ->filter(fn($c) => $c['status'] === 'empty')
            ->keys();
        $labels = [
            'mining_op' => 'Mining Operation',
            'moon_extraction' => 'Moon Extraction',
            'ice_mining' => 'Ice Mining',
            'gas_huffing' => 'Gas Huffing',
            'special' => 'Special Event',
        ];
    @endphp
    <div class="small text-muted">
        @if($suggested->isNotEmpty() || $partial->isNotEmpty())
            <strong>Suggested event types for your current tax settings:</strong>
            <ul class="mb-1 mt-1">
                @foreach($suggested as $et)
                    <li>
                        <span class="badge badge-success">Fully taxed</span>
                        <strong>{{ $labels[$et] ?? $et }}</strong>
                        &mdash; all ore categories in scope are taxed
                    </li>
                @endforeach
                @foreach($partial as $et)
                    <li>
                        <span class="badge badge-warning">Partial</span>
                        <strong>{{ $labels[$et] ?? $et }}</strong>
                        &mdash; tracks only the taxed subset
                        ({{ $taxCompat['event_type_compat'][$et]['taxed'] }}/{{ $taxCompat['event_type_compat'][$et]['total'] }} categories)
                    </li>
                @endforeach
            </ul>
        @endif
        @if($empty->isNotEmpty())
            <strong>Not meaningful right now:</strong>
            <span class="text-danger">
                @foreach($empty as $i => $et){{ $labels[$et] ?? $et }}@if($i < $empty->count() - 1), @endif@endforeach
            </span>
            &mdash; none of these event types' ore categories are currently taxed.
        @endif
    </div>
</div>

<script>
    (function() {
        var panel = document.querySelector('.event-tax-compat-panel');
        if (!panel) return;

        var selectEl = document.querySelector('{{ $targetSelect }}');
        if (!selectEl) return;

        var statusEl = document.getElementById('event-type-compat-status');
        if (!statusEl) return;

        var compat;
        try {
            compat = JSON.parse(panel.getAttribute('data-tax-compat'));
        } catch (e) {
            return;
        }

        var labels = @json($labels);

        function renderStatus(eventType) {
            if (!eventType) {
                statusEl.style.display = 'none';
                statusEl.className = 'py-2 px-3 mb-2';
                statusEl.innerHTML = '';
                return;
            }
            var data = compat.event_type_compat[eventType];
            if (!data) {
                statusEl.style.display = 'none';
                return;
            }

            var cls, icon, msg;
            if (data.status === 'empty') {
                cls = 'alert alert-danger';
                icon = 'fa-exclamation-triangle';
                msg = '<strong>This event won\'t produce participant data.</strong> None of the ore categories for ' +
                    (labels[eventType] || eventType) + ' are taxed (' +
                    data.untaxed_categories.join(', ') + '). ' +
                    'Either adjust tax settings or choose a different event type.';
            } else if (data.status === 'partial') {
                cls = 'alert alert-warning';
                icon = 'fa-info-circle';
                msg = '<strong>Partial coverage:</strong> will track only taxed categories (' +
                    data.taxed_categories.join(', ') + '). ' +
                    'Untaxed: <span class="text-muted">' + data.untaxed_categories.join(', ') + '</span>.';
            } else {
                cls = 'alert alert-success';
                icon = 'fa-check-circle';
                msg = '<strong>All set.</strong> Every ore category for ' +
                    (labels[eventType] || eventType) + ' is taxed &mdash; the event will track full participation.';
            }

            statusEl.className = cls + ' py-2 px-3 mb-2';
            statusEl.innerHTML = '<i class="fas ' + icon + '"></i> ' + msg;
            statusEl.style.display = '';
        }

        selectEl.addEventListener('change', function() { renderStatus(this.value); });
        // Fire once on page load so edit-form pre-selected value shows status immediately
        renderStatus(selectEl.value);
    })();
</script>
