@extends('web::layouts.grids.12')

@section('title', $event->name)
@section('page_header', trans('mining-manager::menu.mining_events'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=10">
<script src="{{ asset('vendor/mining-manager/js/eve-time.js') }}?v=1" defer></script>
<style>
    .mining-manager-wrapper .event-rules dt { color: var(--mm-text-muted); font-weight: 600; }
    .mining-manager-wrapper .event-rules dd { margin-bottom: 0.6rem; }
    .mining-manager-wrapper .event-person-alts { font-size: 0.85em; color: var(--mm-text-muted); }
    .mining-manager-wrapper .event-total { font-size: 1.15rem; font-weight: 600; color: #f9fafb; }
</style>
@endpush

@section('full')
@include('mining-manager::partials.toastr')
@php
    $statuses = \MiningManager\Services\Events\EventPeople::class;

    $isk = fn ($value) => number_format((float) $value, 0) . ' ISK';
    $statusBadge = [
        $statuses::SIGNED_UP => ['badge-success', trans('mining-manager::events.status_signed_up')],
        $statuses::SIGNED_UP_WAITING => ['badge-info', trans('mining-manager::events.status_signed_up_waiting')],
        $statuses::ADDED => ['badge-secondary', trans('mining-manager::events.status_added')],
        $statuses::NOT_SIGNED_UP => ['badge-warning', trans('mining-manager::events.status_not_signed_up')],
    ];
    $statusColour = ['active' => 'success', 'cancelled' => 'danger', 'completed' => 'secondary'][$event->status] ?? 'info';

    if ($event->countsWholeDays()) {
        $when = $event->end_time
            ? trans('mining-manager::events.rule_when_days', ['from' => $event->start_time->format('D j M Y'), 'to' => $event->end_time->format('D j M Y')])
            : trans('mining-manager::events.rule_when_days_open', ['from' => $event->start_time->format('D j M Y')]);
    } else {
        $when = $event->end_time
            ? trans('mining-manager::events.rule_when_times', ['from' => $event->start_time->format('D j M Y H:i'), 'to' => $event->end_time->format('D j M Y H:i')])
            : trans('mining-manager::events.rule_when_times_open', ['from' => $event->start_time->format('D j M Y H:i')]);
    }

    $refineryCount = count($event->refineryIds());
    if ($refineryCount > 0) {
        $where = trans('mining-manager::events.rule_where_refineries', ['count' => $refineryCount]);
    } elseif ($event->getLocationName()) {
        $where = trans('mining-manager::events.rule_where_place', ['place' => $event->getLocationName(), 'scope' => strtolower($event->getLocationScopeLabel())]);
    } else {
        $where = trans('mining-manager::events.rule_where_any');
    }

    $ore = implode(', ', array_map(fn ($category) => \MiningManager\Models\MiningEvent::ORE_CATEGORY_LABELS[$category] ?? $category, $event->oreCategories()));

    if ($event->corporation_id) {
        $corporationName = $event->corporation ? '[' . $event->corporation->ticker . '] ' . $event->corporation->name : (string) $event->corporation_id;
        $who = trans('mining-manager::events.audience_corporation', ['corporation' => $corporationName]);
    } else {
        $who = trans('mining-manager::events.' . ($event->members_only ? 'audience_members' : 'audience_everyone'));
    }
@endphp
<div class="mining-manager-wrapper mining-dashboard events-show-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_events') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.active') }}">
                    <i class="fas fa-play-circle"></i> {{ trans('mining-manager::menu.active_events') }}
                </a>
            </li>
            @can('mining-manager.director')
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.create') }}">
                    <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::menu.create_event') }}
                </a>
            </li>
            @endcan
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.event_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.events.my-events') }}">
                    <i class="fas fa-user-check"></i> {{ trans('mining-manager::menu.my_events') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">

    {{-- The event --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-flag"></i> {{ $event->name }}
                <span class="badge badge-{{ $statusColour }} ml-2">{{ trans('mining-manager::events.' . $event->status) }}</span>
                <span class="badge badge-dark ml-1">{{ $event->getTypeLabel() }}</span>
            </h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    @if ($event->description)
                        <p class="lead mb-3">{{ $event->description }}</p>
                    @endif
                    <p class="mb-0 text-muted">
                        {{ trans('mining-manager::events.created_by', ['name' => $event->creator->name ?? trans('mining-manager::events.unknown')]) }}
                    </p>
                </div>
                <div class="col-md-4">
                    @if ($event->takesSignUps())
                        @if ($signedUp)
                            <p class="mb-2"><i class="fas fa-check-circle text-success"></i> {{ trans('mining-manager::events.you_are_signed_up') }}</p>
                            <button class="btn btn-outline-secondary btn-block leave-event" data-event-id="{{ $event->id }}">
                                <i class="fas fa-sign-out-alt"></i> {{ trans('mining-manager::events.leave_event') }}
                            </button>
                        @else
                            <button class="btn btn-mm-primary btn-block join-event" data-event-id="{{ $event->id }}">
                                <i class="fas fa-sign-in-alt"></i> {{ trans('mining-manager::events.join_event') }}
                            </button>
                        @endif
                        <small class="form-text text-muted">
                            {{ trans('mining-manager::events.' . ($event->needsSignUp() ? 'signup_hint_signed_up' : 'signup_hint_everyone')) }}
                        </small>
                    @elseif ($event->status !== 'cancelled')
                        <p class="text-muted mb-2">{{ trans('mining-manager::events.signups_closed') }}</p>
                    @endif

                    @can('mining-manager.director')
                        <hr>
                        <a href="{{ route('mining-manager.events.edit', $event->id) }}" class="btn btn-outline-secondary btn-block">
                            <i class="fas fa-edit"></i> {{ trans('mining-manager::events.edit') }}
                        </a>
                        <form method="POST" action="{{ route('mining-manager.events.update-data', $event->id) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-block">
                                <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::events.update_data') }}
                            </button>
                        </form>
                    @endcan
                    @can('mining-manager.admin')
                        <form method="POST" action="{{ route('mining-manager.events.destroy', $event->id) }}" class="mt-2" onsubmit='return confirm(@json(trans('mining-manager::events.confirm_delete_warning')));'>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-block">
                                <i class="fas fa-trash"></i> {{ trans('mining-manager::events.delete') }}
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- Rules --}}
        <div class="col-lg-7">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-list-ul"></i> {{ trans('mining-manager::events.rules') }}</h3>
                </div>
                <div class="card-body">
                    <dl class="event-rules mb-0">
                        <dt>{{ trans('mining-manager::events.when') }}</dt>
                        <dd>{{ $when }}</dd>
                        <dt>{{ trans('mining-manager::events.where') }}</dt>
                        <dd>{{ $where }}</dd>
                        <dt>{{ trans('mining-manager::events.what_ore') }}</dt>
                        <dd>{{ $ore }}</dd>
                        <dt>{{ trans('mining-manager::events.who') }}</dt>
                        <dd>
                            {{ $who }}.
                            {{ trans('mining-manager::events.' . ($event->needsSignUp() ? 'rule_participation_signed_up' : 'rule_participation_everyone')) }}
                        </dd>
                        <dt>{{ trans('mining-manager::events.rate') }}</dt>
                        <dd class="mb-0">
                            <span class="badge badge-{{ $event->tax_modifier < 0 ? 'success' : ($event->tax_modifier > 0 ? 'warning' : 'secondary') }}">{{ $event->getTaxModifierLabel() }}</span>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Totals --}}
        <div class="col-lg-5">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-calculator"></i> {{ trans('mining-manager::events.totals') }}</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 mb-3">
                            <div class="text-muted small">{{ trans('mining-manager::events.people') }}</div>
                            <div class="event-total">{{ $totals['people'] }}</div>
                            <div class="text-muted small">{{ trans('mining-manager::events.characters_count', ['count' => $totals['characters']]) }}</div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="text-muted small">{{ trans('mining-manager::events.signed_up_count') }}</div>
                            <div class="event-total">{{ $totals['signups'] }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">{{ trans('mining-manager::events.mined_total') }}</div>
                            <div class="event-total">{{ $isk($totals['value']) }}</div>
                            @if ($event->needsSignUp())
                                <div class="text-muted small">{{ trans('mining-manager::events.at_event_rate_amount', ['amount' => $isk($totals['qualifying'])]) }}</div>
                            @endif
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">{{ trans('mining-manager::events.discount_given') }}</div>
                            <div class="event-total">{{ $isk($totals['discount']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($reportingUntil)
        <div class="mm-notice">
            <i class="fas fa-hourglass-half"></i>
            {{ trans('mining-manager::events.reporting_until', ['until' => $reportingUntil->format('D j M H:i')]) }}
        </div>
    @endif

    {{-- Refineries --}}
    @if (!empty($refineries))
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-industry"></i> {{ trans('mining-manager::events.refineries') }}</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-dark table-striped mb-0">
                    <thead>
                        <tr>
                            <th>{{ trans('mining-manager::events.refinery') }}</th>
                            <th>{{ trans('mining-manager::events.chunk') }}</th>
                            <th class="text-right">{{ trans('mining-manager::events.mined_total') }}</th>
                            <th class="text-right">{{ trans('mining-manager::events.pilots') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($refineries as $refinery)
                            @php $figures = $byRefinery[$refinery['structure_id']] ?? null; @endphp
                            <tr>
                                <td>
                                    <strong>{{ $refinery['moon'] ?? $refinery['name'] }}</strong>
                                    <div class="event-person-alts">{{ $refinery['name'] }}{{ $refinery['system'] ? ' · ' . $refinery['system'] : '' }}</div>
                                </td>
                                <td>
                                    @forelse ($refinery['chunks'] as $chunk)
                                        <div>{{ trans('mining-manager::events.chunk_line', [
                                            'arrives' => \Carbon\Carbon::parse($chunk['arrival'])->format('D j M H:i'),
                                            'gone' => \Carbon\Carbon::parse($chunk['gone'])->format('D j M'),
                                        ]) }}</div>
                                    @empty
                                        <span class="text-muted">{{ trans('mining-manager::events.no_chunk_found') }}</span>
                                    @endforelse
                                </td>
                                <td class="text-right">{{ $figures ? $isk($figures['value']) : '-' }}</td>
                                <td class="text-right">{{ $figures['pilots'] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- People --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-users"></i> {{ trans('mining-manager::events.people') }}</h3>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-striped mb-0">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>{{ trans('mining-manager::events.pilot') }}</th>
                        <th>{{ trans('mining-manager::events.status') }}</th>
                        <th class="text-right">{{ trans('mining-manager::events.mined_total') }}</th>
                        @if ($event->needsSignUp())
                            <th class="text-right">{{ trans('mining-manager::events.at_event_rate') }}</th>
                        @endif
                        <th class="text-right">{{ trans('mining-manager::events.discount_given') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($people as $index => $person)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>
                                @if ($person['main'])
                                    <img src="https://images.evetech.net/characters/{{ $person['main'] }}/portrait?size=32" class="img-circle mr-1" style="width: 28px;" alt="">
                                @endif
                                {{ $person['name'] }}
                                @if (count($person['characters']) > 1 || (count($person['characters']) === 1 && $person['characters'][0]['id'] !== $person['main']))
                                    <div class="event-person-alts">
                                        {{ implode(', ', array_map(fn ($character) => $character['name'] . ' ' . number_format($character['value'], 0), $person['characters'])) }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $statusBadge[$person['status']][0] }}" @if ($person['status'] === $statuses::NOT_SIGNED_UP) title="{{ trans('mining-manager::events.status_not_signed_up_help') }}" @endif>{{ $statusBadge[$person['status']][1] }}</span>
                            </td>
                            <td class="text-right">{{ $isk($person['value']) }}</td>
                            @if ($event->needsSignUp())
                                <td class="text-right">{{ $isk($person['qualifying']) }}</td>
                            @endif
                            <td class="text-right">{{ $isk($person['discount']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $event->needsSignUp() ? 6 : 5 }}" class="text-center text-muted">{{ trans('mining-manager::events.no_people_yet') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- By day --}}
    @if (!empty($byDay))
        <div class="card card-dark mb-0">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-day"></i> {{ trans('mining-manager::events.by_day') }}</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-dark table-striped mb-0">
                    <thead>
                        <tr>
                            <th>{{ trans('mining-manager::events.day') }}</th>
                            <th class="text-right">{{ trans('mining-manager::events.pilots') }}</th>
                            <th class="text-right">{{ trans('mining-manager::events.mined_total') }}</th>
                            @if ($event->needsSignUp())
                                <th class="text-right">{{ trans('mining-manager::events.at_event_rate') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byDay as $day => $figures)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($day)->format('D j M Y') }}</td>
                                <td class="text-right">{{ $figures['pilots'] }}</td>
                                <td class="text-right">{{ $isk($figures['value']) }}</td>
                                @if ($event->needsSignUp())
                                    <td class="text-right">{{ $isk($figures['qualifying']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection

@push('javascript')
<script>
$(function () {
    $('.join-event, .leave-event').on('click', function (e) {
        e.preventDefault();
        const button = $(this);
        const joining = button.hasClass('join-event');

        if (!joining && !confirm(@json(trans('mining-manager::events.confirm_leave')))) {
            return;
        }

        button.prop('disabled', true);
        $.ajax({
            url: joining ? @json(route('mining-manager.events.join', $event->id)) : @json(route('mining-manager.events.leave', $event->id)),
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function (response) {
                if (typeof toastr !== 'undefined') {
                    toastr.success(response.message);
                }
                setTimeout(function () { location.reload(); }, 1000);
            },
            error: function (xhr) {
                button.prop('disabled', false);
                const message = (xhr.responseJSON && xhr.responseJSON.message) || @json(trans('mining-manager::events.join_failed'));
                if (typeof toastr !== 'undefined') {
                    toastr.error(message);
                } else {
                    alert(message);
                }
            }
        });
    });
});
</script>
@endpush
