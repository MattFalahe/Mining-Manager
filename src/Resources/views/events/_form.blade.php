{{--
    The event form's fields, shared by Create and Edit.

    Expects:
      $taxCompat    EventController::buildTaxCompatibilityMap()
      $corporations the corporations an event can be for
      $refineries   EventRefineries::choices()
    Optional:
      $event        the event being edited
      $prefill      what the form starts with when opened from a chunk
      $limits       EventController::formLimits(): how far back, and past events
--}}
@php
    $event = $event ?? null;
    $prefill = $prefill ?? [];
    $limits = ($limits ?? []) + ['governed' => false, 'creating' => $event === null, 'held' => false, 'earliest_day' => null, 'enforce_earliest' => false, 'can_past' => false, 'hold_minutes' => 0, 'today' => now()->toDateString(), 'manager' => false, 'enforce_manager_start' => false, 'approval_rule' => 'never'];
    // The first day the date picker offers: for a moon manager how far ahead
    // they may start, for anyone else the first day not billed yet.
    $minDay = ($limits['manager'] ? $limits['enforce_manager_start'] : $limits['enforce_earliest']) ? $limits['earliest_day'] : null;
    $minDayAttribute = $minDay ? 'min="' . e($minDay) . '"' : '';
    $everyoneKey = \MiningManager\Models\MiningEvent::PARTICIPATION_EVERYONE;
    $signedUpKey = \MiningManager\Models\MiningEvent::PARTICIPATION_SIGNED_UP;

    $nameValue = old('name', $event->name ?? ($prefill['name'] ?? ''));
    $descriptionValue = old('description', $event->description ?? '');
    $typeValue = old('type', $event->type ?? ($prefill['type'] ?? ''));
    $startDay = old('start_day', $event ? $event->start_time->toDateString() : ($prefill['start_day'] ?? ''));
    $endDay = old('end_day', $event && $event->end_time ? $event->end_time->toDateString() : ($prefill['end_day'] ?? ''));
    $startTime = old('start_time', $event ? $event->start_time->format('Y-m-d\TH:i') : '');
    $endTime = old('end_time', $event && $event->end_time ? $event->end_time->format('Y-m-d\TH:i') : '');
    $scopeValue = old('location_scope', $event->location_scope ?? 'any');
    $locationId = old('solar_system_id', $event->solar_system_id ?? null);
    $locationName = $event && $event->solar_system_id && (int) $locationId === (int) $event->solar_system_id ? $event->getLocationName() : null;
    $pickedRefineries = array_map('intval', (array) old('refineries', $event ? $event->refineryIds() : ($prefill['refineries'] ?? [])));
    $storedCategories = $event && is_array($event->ore_categories) ? $event->ore_categories : [];
    $pickedCategories = old('ore_categories_shown') ? (array) old('ore_categories', []) : $storedCategories;
    $isPicked = fn (string $category) => $pickedCategories === [] || in_array($category, $pickedCategories, true);
    $audienceValue = (string) old('audience', $event ? ($event->corporation_id ? $event->corporation_id : ($event->members_only ? 'members' : 'everyone')) : 'everyone');
    $participationValue = old('participation', $event->participation ?? $everyoneKey);
    $modifierValue = (int) old('tax_modifier', $event->tax_modifier ?? 0);

    $everything = \MiningManager\Models\MiningEvent::ORE_CATEGORY_LABELS;
    $rarities = array_intersect_key($everything, array_flip(\MiningManager\Models\MiningEvent::MOON_CATEGORIES));
    $modifiers = [
        -100 => 'modifier_tax_free', -75 => 'modifier_reduced_75', -50 => 'modifier_reduced_50', -25 => 'modifier_reduced_25',
        0 => 'modifier_normal', 25 => 'modifier_increase_25', 50 => 'modifier_increase_50', 75 => 'modifier_increase_75', 100 => 'modifier_double',
    ];
    if ($limits['manager']) {
        // Raising tax is for directors and admins.
        $modifiers = array_filter($modifiers, fn ($modifier) => $modifier <= 0, ARRAY_FILTER_USE_KEY);
    }
@endphp

@if ($limits['manager'] && $limits['governed'])
    <div class="mm-notice">
        <i class="fas fa-user-astronaut"></i>
        {{ trans('mining-manager::events.manager_form_notice', ['hours' => $limits['lead_hours'], 'day' => $limits['manager_first_label'], 'time' => $limits['manager_start_label']]) }}
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row">
    <div class="col-lg-8">
        {{-- The event --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-flag"></i> {{ trans('mining-manager::events.basic_information') }}</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="name">{{ trans('mining-manager::events.event_name') }} <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ $nameValue }}" required maxlength="255">
                </div>

                <div class="form-group">
                    <label for="description">{{ trans('mining-manager::events.description') }}</label>
                    <textarea class="form-control" id="description" name="description" rows="3">{{ $descriptionValue }}</textarea>
                </div>

                <div class="form-group mb-0">
                    <label for="type">{{ trans('mining-manager::events.event_type') }} <span class="text-danger">*</span></label>
                    <select class="form-control @error('type') is-invalid @enderror" id="type" name="type" required>
                        <option value="">{{ trans('mining-manager::events.select_type') }}</option>
                        @foreach (['mining_op', 'moon_extraction', 'ice_mining', 'gas_huffing', 'special'] as $typeKey)
                            <option value="{{ $typeKey }}" {{ $typeValue === $typeKey ? 'selected' : '' }}>{{ trans('mining-manager::events.' . $typeKey) }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">{{ trans('mining-manager::events.type_help') }}</small>

                    @include('mining-manager::events._tax_compatibility_panel')
                </div>
            </div>
        </div>

        {{-- When --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::events.when') }}</h3>
            </div>
            <div class="card-body">
                <div id="when-days">
                    <div class="mm-notice">
                        <i class="fas fa-info-circle"></i> {{ trans('mining-manager::events.whole_days_help') }}
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="start_day">{{ trans('mining-manager::events.first_day') }} <span class="text-danger">*</span></label>
                                <input type="date" class="form-control @error('start_day') is-invalid @enderror" id="start_day" name="start_day" value="{{ $startDay }}" {!! $minDayAttribute !!}>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="end_day">{{ trans('mining-manager::events.last_day') }}</label>
                                <input type="date" class="form-control @error('end_day') is-invalid @enderror" id="end_day" name="end_day" value="{{ $endDay }}">
                                <small class="form-text text-muted">{{ trans('mining-manager::events.open_ended_help') }}</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="when-times">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="start_time">
                                    {{ trans('mining-manager::events.start_time') }}
                                    <span class="text-muted" id="startModeHint">(EVE Time / UTC)</span>
                                    <span class="text-danger">*</span>
                                </label>
                                <input type="datetime-local" class="form-control @error('start_time') is-invalid @enderror" id="start_time" name="start_time" value="{{ $startTime }}">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="end_time">
                                    {{ trans('mining-manager::events.end_time') }}
                                    <span class="text-muted" id="endModeHint">(EVE Time / UTC)</span>
                                </label>
                                <input type="datetime-local" class="form-control @error('end_time') is-invalid @enderror" id="end_time" name="end_time" value="{{ $endTime }}">
                                <small class="form-text text-muted">{{ trans('mining-manager::events.open_ended_help') }}</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label class="mr-2" style="font-size: 0.9em;">Enter time in:</label>
                        <div class="btn-group btn-group-toggle btn-group-sm" data-toggle="buttons">
                            <label class="btn btn-outline-secondary active">
                                <input type="radio" name="time_input_mode" value="eve" checked> EVE / UTC
                            </label>
                            <label class="btn btn-outline-secondary">
                                <input type="radio" name="time_input_mode" value="local"> My local time
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            <i class="fas fa-globe"></i> Browser timezone: <code id="detected-tz">detecting&hellip;</code>.
                            {{ trans('mining-manager::events.sub_day_help') }}
                        </small>
                        <div class="mm-notice mm-notice-secondary mt-2 mb-0" id="time-confirmation" style="display: none;">
                            <strong><i class="fas fa-calendar-check"></i> Starts:</strong> <span id="eve-time-display">--</span> (<span id="local-time-display">--</span>)
                            <span id="end-confirmation" style="display: none;">
                                <br><strong><i class="fas fa-flag-checkered"></i> Ends:</strong> <span id="end-eve-display">--</span> (<span id="end-local-display">--</span>)
                            </span>
                        </div>
                    </div>
                </div>

                @if ($limits['enforce_earliest'])
                    <small class="form-text text-muted mt-2">
                        <i class="fas fa-file-invoice"></i>
                        {{ trans('mining-manager::events.earliest_day_hint', ['last' => $limits['last_billed'], 'first' => $limits['earliest_label']]) }}
                    </small>
                @endif

                @if ($limits['held'] && $event && $event->applies_at)
                    <div class="mm-notice mm-notice-warning mt-3 mb-0">
                        <i class="fas fa-hourglass-half"></i>
                        {{ trans('mining-manager::events.held_edit_notice', ['time' => $event->applies_at->format('D j M H:i')]) }}
                    </div>
                @endif

                @if ($limits['governed'] && $limits['creating'])
                    <div class="mm-notice mm-notice-warning mt-3 mb-0" id="past-notice" style="display: none;">
                        @if ($limits['can_past'])
                            <p class="mb-2">
                                <i class="fas fa-hourglass-half"></i>
                                {{ $limits['hold_minutes'] > 0 ? trans('mining-manager::events.past_notice', ['minutes' => $limits['hold_minutes']]) : trans('mining-manager::events.past_notice_now') }}
                            </p>
                            <div class="form-group mb-0">
                                <label for="reason">{{ trans('mining-manager::events.reason_for_past') }} <span class="text-danger">*</span></label>
                                <textarea class="form-control @error('reason') is-invalid @enderror" id="reason" name="reason" rows="2" maxlength="500" disabled>{{ old('reason') }}</textarea>
                                <small class="form-text text-muted">{{ trans('mining-manager::events.reason_for_past_help') }}</small>
                            </div>
                        @else
                            <p class="mb-0"><i class="fas fa-lock"></i> {{ trans('mining-manager::events.past_directors_only') }}</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Where --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-map-marker-alt"></i> {{ trans('mining-manager::events.where') }}</h3>
            </div>
            <div class="card-body">
                <div id="refinery-picker" class="mb-3">
                    <label class="d-block">{{ trans('mining-manager::events.refineries') }}</label>
                    <small class="form-text text-muted mt-0 mb-2">{{ trans('mining-manager::events.refineries_help') }}</small>

                    @if (empty($refineries))
                        <div class="mm-notice mm-notice-secondary mb-0">{{ trans('mining-manager::events.no_refineries') }}</div>
                    @else
                        <div class="event-refinery-list">
                            @foreach ($refineries as $refinery)
                                @php
                                    $refineryId = $refinery['structure_id'];
                                    $title = $refinery['moon'] ?? $refinery['name'];
                                @endphp
                                <div class="event-refinery" data-chunks='@json($refinery['chunks'])'>
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="refinery-{{ $refineryId }}" name="refineries[]" value="{{ $refineryId }}" {{ in_array($refineryId, $pickedRefineries, true) ? 'checked' : '' }}>
                                        <label class="custom-control-label" for="refinery-{{ $refineryId }}">
                                            <strong>{{ $title }}</strong>
                                            <span class="text-muted">{{ $refinery['name'] }}{{ $refinery['system'] ? ' · ' . $refinery['system'] : '' }}</span>
                                            <span class="badge badge-success chunk-in-window" style="display: none;">{{ trans('mining-manager::events.chunk_in_days') }}</span>
                                        </label>
                                    </div>
                                    @if (!empty($refinery['chunks']))
                                        <ul class="event-refinery-chunks">
                                            @foreach ($refinery['chunks'] as $chunk)
                                                <li>
                                                    {{ trans('mining-manager::events.chunk_line', [
                                                        'arrives' => \Carbon\Carbon::parse($chunk['arrival'])->format('D j M H:i'),
                                                        'gone' => \Carbon\Carbon::parse($chunk['gone'])->format('D j M'),
                                                    ]) }}
                                                    <a href="#" class="use-chunk-days ml-1" data-from="{{ $chunk['arrival_day'] }}" data-to="{{ $chunk['gone_day'] }}">{{ trans('mining-manager::events.use_these_days') }}</a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="mm-notice mm-notice-success mb-0" id="location-narrowed-note" style="display: none;">
                    <i class="fas fa-check-circle"></i> {{ trans('mining-manager::events.narrowed_note') }}
                </div>

                <div class="row" id="location-fields">
                    <div class="col-md-4">
                        <div class="form-group mb-0">
                            <label for="location_scope">{{ trans('mining-manager::events.location_scope') }}</label>
                            <select class="form-control" id="location_scope" name="location_scope">
                                @foreach (['any' => 'scope_any', 'region' => 'scope_region', 'constellation' => 'scope_constellation', 'system' => 'scope_system'] as $scopeKey => $scopeLabel)
                                    <option value="{{ $scopeKey }}" {{ $scopeValue === $scopeKey ? 'selected' : '' }}>{{ trans('mining-manager::events.' . $scopeLabel) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group mb-0" id="location-select-group" style="{{ $scopeValue === 'any' ? 'display:none;' : '' }}">
                            <label for="solar_system_id">{{ trans('mining-manager::events.location') }}</label>
                            <select class="form-control select2-location @error('solar_system_id') is-invalid @enderror" id="solar_system_id" name="solar_system_id" style="width: 100%;">
                                @if ($locationId)
                                    <option value="{{ $locationId }}" selected>{{ $locationName ?? trans('mining-manager::events.search_location') }}</option>
                                @endif
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- What ore --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-gem"></i> {{ trans('mining-manager::events.what_ore') }}</h3>
            </div>
            <div class="card-body">
                <input type="hidden" id="ore-categories-shown" name="ore_categories_shown" value="1">

                <div id="ore-moon">
                    <small class="form-text text-muted mt-0 mb-2">{{ trans('mining-manager::events.rarity_help') }}</small>
                    @foreach ($rarities as $category => $label)
                        <div class="custom-control custom-checkbox custom-control-inline">
                            <input type="checkbox" class="custom-control-input" id="ore-moon-{{ $category }}" name="ore_categories[]" value="{{ $category }}" {{ $isPicked($category) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="ore-moon-{{ $category }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>

                <div id="ore-special">
                    <small class="form-text text-muted mt-0 mb-2">{{ trans('mining-manager::events.categories_help') }}</small>
                    @foreach ($everything as $category => $label)
                        <div class="custom-control custom-checkbox custom-control-inline">
                            <input type="checkbox" class="custom-control-input" id="ore-special-{{ $category }}" name="ore_categories[]" value="{{ $category }}" {{ $isPicked($category) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="ore-special-{{ $category }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>

                <p class="mb-0" id="ore-fixed"></p>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Who --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-users"></i> {{ trans('mining-manager::events.who') }}</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="audience">{{ trans('mining-manager::events.corporation_scope') }}</label>
                    <select class="form-control @error('audience') is-invalid @enderror" id="audience" name="audience">
                        <option value="everyone" {{ $audienceValue === 'everyone' ? 'selected' : '' }}>{{ trans('mining-manager::events.audience_everyone') }}</option>
                        <option value="members" {{ $audienceValue === 'members' ? 'selected' : '' }}>{{ trans('mining-manager::events.audience_members') }}</option>
                        @foreach ($corporations ?? [] as $corp)
                            <option value="{{ $corp->corporation_id }}" {{ $audienceValue === (string) $corp->corporation_id ? 'selected' : '' }}>
                                {{ trans('mining-manager::events.audience_corporation', ['corporation' => '[' . $corp->ticker . '] ' . $corp->name]) }}
                            </option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">{{ trans('mining-manager::events.corporation_scope_help') }}</small>
                </div>

                <label class="d-block">{{ trans('mining-manager::events.participation') }}</label>
                <div class="custom-control custom-radio">
                    <input type="radio" class="custom-control-input" id="participation-everyone" name="participation" value="{{ $everyoneKey }}" {{ $participationValue === $signedUpKey ? '' : 'checked' }}>
                    <label class="custom-control-label" for="participation-everyone">{{ trans('mining-manager::events.participation_everyone') }}</label>
                    <small class="form-text text-muted mt-0">{{ trans('mining-manager::events.participation_everyone_help') }}</small>
                </div>
                <div class="custom-control custom-radio mt-2" id="participation-signed-up-wrap">
                    <input type="radio" class="custom-control-input" id="participation-signed-up" name="participation" value="{{ $signedUpKey }}" {{ $participationValue === $signedUpKey ? 'checked' : '' }}>
                    <label class="custom-control-label" for="participation-signed-up">{{ trans('mining-manager::events.participation_signed_up') }}</label>
                    <small class="form-text text-muted mt-0">{{ trans('mining-manager::events.participation_signed_up_help') }}</small>
                </div>
            </div>
        </div>

        {{-- Rate --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-percent"></i> {{ trans('mining-manager::events.rate') }}</h3>
            </div>
            <div class="card-body">
                <div class="form-group {{ $event ? '' : 'mb-2' }}">
                    <label for="tax_modifier">{{ trans('mining-manager::events.tax_modifier') }}</label>
                    <select class="form-control" id="tax_modifier" name="tax_modifier" required>
                        @foreach ($modifiers as $modifier => $label)
                            <option value="{{ $modifier }}" {{ $modifierValue === $modifier ? 'selected' : '' }}>{{ trans('mining-manager::events.' . $label) }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">{{ trans('mining-manager::events.tax_modifier_help') }}</small>
                    @if ($limits['manager'] && $limits['governed'])
                        <div class="mm-notice mm-notice-warning mt-2 mb-0" id="approval-hint" style="display: none;">
                            <i class="fas fa-user-check"></i> {{ trans('mining-manager::events.needs_approval_notice') }}
                        </div>
                    @endif
                </div>

                @if ($event)
                    {{-- Status is not set here: cancelling and bringing back are on the event page, and the rest follows the event's times. --}}
                    @if ($event->hasStarted() && $event->isAnnounced())
                        <div class="form-group mb-0">
                            <label for="reason">{{ trans('mining-manager::events.reason_for_change') }} <span class="text-danger">*</span></label>
                            <textarea class="form-control @error('reason') is-invalid @enderror" id="reason" name="reason" rows="2" maxlength="500" required>{{ old('reason') }}</textarea>
                            <small class="form-text text-muted">{{ trans('mining-manager::events.reason_for_change_help') }}</small>
                        </div>
                    @endif
                @else
                    <div class="custom-control custom-checkbox" id="send-notifications-wrap">
                        <input type="checkbox" class="custom-control-input" id="send_notifications" name="send_notifications" value="1" {{ old('send_notifications', true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="send_notifications">{{ trans('mining-manager::events.send_notifications') }}</label>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@push('head')
<style>
    .mining-manager-wrapper .event-refinery-list { max-height: 360px; overflow-y: auto; border: 1px solid var(--mm-border); border-radius: var(--mm-radius-md); }
    .mining-manager-wrapper .event-refinery { padding: 8px 12px; border-bottom: 1px solid var(--mm-border); }
    .mining-manager-wrapper .event-refinery:last-child { border-bottom: none; }
    .mining-manager-wrapper .event-refinery.has-chunk { background-color: #1d2b25; }
    .mining-manager-wrapper .event-refinery-chunks { margin: 4px 0 0 1.5rem; padding: 0; list-style: none; font-size: 0.85em; color: var(--mm-text-muted); }
    .mining-manager-wrapper .mm-notice-warning { background-color: #2b2618 !important; border-left-color: var(--mm-warning) !important; }
</style>
@endpush

@push('javascript')
<script>
$(function () {
    const typeSelect = $('#type');
    const fixedOre = {
        mining_op: @json(trans('mining-manager::events.ore_fixed_mining_op')),
        ice_mining: @json(trans('mining-manager::events.ore_fixed_ice_mining')),
        gas_huffing: @json(trans('mining-manager::events.ore_fixed_gas_huffing')),
        '': @json(trans('mining-manager::events.ore_fixed_none')),
    };
    const limits = @json($limits);

    // Only the fields the event type uses are sent: the rest are disabled.
    function show(selector, on) {
        $(selector).toggle(on).find('input, select').prop('disabled', !on);
    }

    function refineriesTicked() {
        return typeSelect.val() === 'moon_extraction' && $('input[name="refineries[]"]:checked').length > 0;
    }

    function applyLocation() {
        const narrowed = refineriesTicked();
        $('#location-narrowed-note').toggle(narrowed);
        show('#location-fields', !narrowed);
        if (!narrowed) {
            $('#location-select-group').toggle($('#location_scope').val() !== 'any');
        }
    }

    function markChunks() {
        const from = $('#start_day').val();
        const to = $('#end_day').val() || from;
        $('.event-refinery').each(function () {
            const chunks = $(this).data('chunks') || [];
            const hit = !!from && chunks.some(function (chunk) { return chunk.arrival_day <= to && chunk.gone_day >= from; });
            $(this).toggleClass('has-chunk', hit).find('.chunk-in-window').toggle(hit);
        });
    }

    // Days that have passed make a past event: held before it applies, for
    // everyone who mined, with a reason. The server decides; this only shows
    // the form it will take.
    function isPast() {
        if (typeSelect.val() === 'moon_extraction') {
            const end = $('#end_day').val();
            return !!end && end < limits.today;
        }
        const end = parseByMode($('#end_time').val());
        return !!end && end.getTime() <= Date.now();
    }

    function applyPast() {
        if (!limits.governed || !(limits.creating || limits.held)) {
            return;
        }
        const past = isPast();
        if (limits.creating) {
            $('#past-notice').toggle(past);
            $('#reason').prop('disabled', !past).prop('required', past && limits.can_past);
            $('#send-notifications-wrap').toggle(!past);
        }
        $('#participation-signed-up-wrap').toggle(!past);
        if (past) {
            $('#participation-everyone').prop('checked', true);
        }
    }

    // Whether the Events settings make a moon manager's event at this rate
    // wait for a director; the server decides the same way.
    function applyApproval() {
        const rule = limits.approval_rule;
        const modifier = parseInt($('#tax_modifier').val(), 10) || 0;
        const needs = rule === 'always' || (rule !== 'never' && modifier <= -parseInt(rule, 10));
        $('#approval-hint').toggle(!!limits.manager && needs);
    }

    function applyType() {
        const type = typeSelect.val();
        const moon = type === 'moon_extraction';
        show('#when-days', moon);
        show('#when-times', !moon);
        show('#refinery-picker', moon);
        show('#ore-moon', moon);
        show('#ore-special', type === 'special');
        $('#ore-categories-shown').prop('disabled', !(moon || type === 'special'));
        $('#ore-fixed').toggle(!moon && type !== 'special').text(fixedOre[type] || fixedOre['']);
        $('#start_day').prop('required', moon);
        $('#start_time').prop('required', !moon);
        applyLocation();
        markChunks();
        applyPast();
    }

    typeSelect.on('change', applyType);
    $('#start_day, #end_day').on('change input', markChunks);
    $(document).on('change', 'input[name="refineries[]"]', applyLocation);

    $(document).on('click', '.use-chunk-days', function (e) {
        e.preventDefault();
        $('#start_day').val($(this).data('from'));
        $('#end_day').val($(this).data('to'));
        $(this).closest('.event-refinery').find('input[name="refineries[]"]').prop('checked', true);
        applyLocation();
        markChunks();
    });

    $('.select2-location').select2({
        theme: 'bootstrap4',
        ajax: {
            url: @json(route('mining-manager.events.search-locations')),
            dataType: 'json',
            delay: 300,
            data: function (params) {
                return { q: params.term, scope: $('#location_scope').val() };
            },
            processResults: function (data) {
                return { results: data };
            },
            cache: true
        },
        placeholder: @json(trans('mining-manager::events.search_location')),
        minimumInputLength: 2,
        allowClear: true
    });

    $('#location_scope').on('change', function () {
        $('#location-select-group').toggle($(this).val() !== 'any');
        $('#solar_system_id').val(null).trigger('change');
    });

    // Times are typed in EVE time or the browser's own, and always sent as
    // EVE time (UTC).
    try {
        $('#detected-tz').text(Intl.DateTimeFormat().resolvedOptions().timeZone || 'unknown');
    } catch (e) {
        $('#detected-tz').text('unknown');
    }

    function inputMode() {
        const checked = document.querySelector('input[name="time_input_mode"]:checked');
        return checked ? checked.value : 'eve';
    }

    function parseByMode(value) {
        if (!value) return null;
        if (inputMode() === 'local') {
            const local = new Date(value);
            return isNaN(local.getTime()) ? null : local;
        }
        const parts = value.split('T');
        if (parts.length !== 2) return null;
        const d = parts[0].split('-').map(Number);
        const t = parts[1].split(':').map(Number);
        return new Date(Date.UTC(d[0], d[1] - 1, d[2], t[0] || 0, t[1] || 0));
    }

    function toUtcInput(date) {
        const pad = function (n) { return String(n).padStart(2, '0'); };
        return date.getUTCFullYear() + '-' + pad(date.getUTCMonth() + 1) + '-' + pad(date.getUTCDate()) + 'T' + pad(date.getUTCHours()) + ':' + pad(date.getUTCMinutes());
    }

    function formatLocal(date) {
        try {
            return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' }).format(date);
        } catch (e) {
            return date.toString();
        }
    }

    function updateConfirmation() {
        const start = parseByMode($('#start_time').val());
        const end = parseByMode($('#end_time').val());
        $('#time-confirmation').toggle(!!start);
        if (start) {
            $('#eve-time-display').text(toUtcInput(start).replace('T', ' ') + ' EVE');
            $('#local-time-display').text(formatLocal(start));
        }
        $('#end-confirmation').toggle(!!end);
        if (end) {
            $('#end-eve-display').text(toUtcInput(end).replace('T', ' ') + ' EVE');
            $('#end-local-display').text(formatLocal(end));
        }
        const local = inputMode() === 'local';
        $('#startModeHint, #endModeHint').text(local ? '(your local time)' : '(EVE Time / UTC)');
    }

    $('#start_time, #end_time').on('change input', updateConfirmation);
    $('input[name="time_input_mode"]').on('change', updateConfirmation);
    $('#start_day, #end_day, #start_time, #end_time').on('change input', applyPast);
    $('input[name="time_input_mode"]').on('change', applyPast);
    $('#tax_modifier').on('change', applyApproval);

    $('#eventForm').on('submit', function () {
        if (inputMode() !== 'local') return;
        $('#start_time, #end_time').each(function () {
            const date = parseByMode($(this).val());
            if (date) $(this).val(toUtcInput(date));
        });
    });

    applyType();
    updateConfirmation();
    applyApproval();
});
</script>
@endpush
