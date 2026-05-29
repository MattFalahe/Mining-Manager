@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::menu.metenox_cargo'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=3">
<script src="{{ asset('vendor/mining-manager/js/eve-time.js') }}?v=1" defer></script>
<script src="{{ asset('vendor/mining-manager/js/eve-countdown.js') }}?v=1" defer></script>
<style>
    /* === Metenox cargo page chrome ============================
       Director-only readout of every Metenox Moon Drill's
       MoonMaterialBay contents across the configured corps. */
    .metenox-cargo-page .system-group-label {
        font-size: 0.78rem !important;
        text-transform: uppercase !important;
        letter-spacing: 0.7px !important;
        color: #8b95a5 !important;
        font-weight: 600 !important;
        margin: 1.5rem 0 0.6rem !important;
        padding-bottom: 0.3rem !important;
        border-bottom: 1px solid #2a2f3a !important;
    }
    .metenox-cargo-page .drill-card {
        background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%) !important;
        border: 1px solid rgba(102, 126, 234, 0.2) !important;
        border-radius: 8px !important;
        margin-bottom: 1rem !important;
        overflow: hidden !important;
    }
    .metenox-cargo-page .drill-card-header {
        padding: 0.85rem 1.1rem !important;
        background: rgba(0, 0, 0, 0.15) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05) !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        flex-wrap: wrap !important;
        gap: 0.6rem !important;
    }
    .metenox-cargo-page .drill-card-title {
        margin: 0 !important;
        color: #fff !important;
        font-size: 1.05rem !important;
        font-weight: 600 !important;
    }
    .metenox-cargo-page .drill-card-title .drill-id {
        font-family: 'Courier New', monospace !important;
        font-size: 0.72rem !important;
        color: #666c76 !important;
        font-weight: 400 !important;
        margin-left: 0.5rem !important;
    }
    .metenox-cargo-page .drill-isk-badge {
        display: inline-block;
        background: rgba(40, 167, 69, 0.15);
        border: 1px solid rgba(40, 167, 69, 0.4);
        color: #5fdb85 !important;
        padding: 2px 9px;
        border-radius: 12px;
        font-size: 0.78rem;
        font-weight: 600;
    }
    .metenox-cargo-page .drill-isk-badge i {
        color: #fbbf24 !important;
        margin-right: 3px;
    }

    /* Bay-fill badge in the drill card title — colour-graded by fill state.
       Same three-tier palette as the fill-bar below: ok (green) / warning
       (yellow) / critical (red). !important per the inline-color-vs-custom-
       CSS rule (feedback_help_docs_visual_design.md rule #5) so custom
       SeAT themes don't wash out the state colour. */
    .metenox-cargo-page .drill-fill-badge {
        display: inline-block;
        padding: 2px 9px;
        border-radius: 12px;
        font-size: 0.78rem;
        font-weight: 600;
        border: 1px solid;
    }
    .metenox-cargo-page .drill-fill-badge.state-ok {
        background: rgba(40, 167, 69, 0.15) !important;
        border-color: rgba(40, 167, 69, 0.4) !important;
        color: #5fdb85 !important;
    }
    .metenox-cargo-page .drill-fill-badge.state-warning {
        background: rgba(255, 193, 7, 0.15) !important;
        border-color: rgba(255, 193, 7, 0.5) !important;
        color: #ffd96a !important;
    }
    .metenox-cargo-page .drill-fill-badge.state-critical {
        background: rgba(220, 53, 69, 0.18) !important;
        border-color: rgba(220, 53, 69, 0.55) !important;
        color: #ff7a85 !important;
    }
    .metenox-cargo-page .drill-fill-badge i {
        margin-right: 3px;
    }

    /* Bay-fill progress bar — large surface at the top of every drill
       card showing X / capacity m³ + % indicator + textual state. */
    .metenox-cargo-page .fill-bar-wrap {
        margin: 0 0 0.8rem;
    }
    .metenox-cargo-page .fill-bar-track {
        background: rgba(0, 0, 0, 0.35);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 4px;
        height: 10px;
        overflow: hidden;
    }
    .metenox-cargo-page .fill-bar {
        height: 100%;
        border-radius: 3px;
        transition: width 0.3s ease;
    }
    .metenox-cargo-page .fill-bar.state-ok {
        background: linear-gradient(90deg, #28a745, #52d465) !important;
    }
    .metenox-cargo-page .fill-bar.state-warning {
        background: linear-gradient(90deg, #ff9800, #ffc107) !important;
    }
    .metenox-cargo-page .fill-bar.state-critical {
        background: linear-gradient(90deg, #dc3545, #ff5252) !important;
    }
    .metenox-cargo-page .drill-card-body {
        padding: 0.85rem 1.1rem !important;
    }
    .metenox-cargo-page .drill-state-pill {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .metenox-cargo-page .drill-state-pill.state-shield_vulnerable { background: #28a745; color: #fff; }
    .metenox-cargo-page .drill-state-pill.state-armor_reinforce,
    .metenox-cargo-page .drill-state-pill.state-hull_reinforce { background: #dc3545; color: #fff; }
    .metenox-cargo-page .drill-state-pill.state-anchoring,
    .metenox-cargo-page .drill-state-pill.state-unanchoring { background: #ffc107; color: #000; }
    .metenox-cargo-page .drill-state-pill.state-onlining { background: #17a2b8; color: #fff; }
    .metenox-cargo-page .ore-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.88rem;
    }
    .metenox-cargo-page .ore-table th {
        text-align: left;
        color: #8b95a5;
        font-weight: 500;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.3rem 0.5rem;
        border-bottom: 1px solid #2a2f3a;
    }
    .metenox-cargo-page .ore-table td {
        padding: 0.45rem 0.5rem;
        border-bottom: 1px solid rgba(42, 47, 58, 0.5);
        color: #e2e8f0;
    }
    .metenox-cargo-page .ore-table tr:last-child td { border-bottom: none; }
    .metenox-cargo-page .ore-table .ore-bar-wrap {
        background: rgba(0, 0, 0, 0.3);
        border-radius: 3px;
        height: 6px;
        overflow: hidden;
        min-width: 80px;
    }
    .metenox-cargo-page .ore-table .ore-bar {
        height: 100%;
        background: linear-gradient(90deg, #667eea, #764ba2);
        border-radius: 3px;
    }
    .metenox-cargo-page .empty-drill {
        color: #8b95a5;
        font-style: italic;
        padding: 0.6rem 0;
    }
    .metenox-cargo-page .freshness-stale {
        color: #ffd96a;
    }
    .metenox-cargo-page .freshness-fresh {
        color: #28a745;
    }
    .metenox-cargo-page .corp-section-header {
        background: rgba(102, 126, 234, 0.1);
        border-left: 3px solid #667eea;
        padding: 0.75rem 1rem;
        margin: 1.5rem 0 1rem;
        border-radius: 0 6px 6px 0;
    }
    .metenox-cargo-page .corp-section-header .corp-name {
        color: #fff;
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0;
    }
    .metenox-cargo-page .corp-section-header .corp-id {
        font-family: 'Courier New', monospace;
        font-size: 0.78rem;
        color: #8b95a5;
        margin-left: 0.5rem;
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard metenox-cargo-page">

{{-- TAB NAVIGATION (matches the rest of the moon section) --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.moon.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.moon.active') }}">
                    <i class="fas fa-hourglass-half"></i> {{ trans('mining-manager::menu.active_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.moon.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.extraction_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.moon.compositions') }}">
                    <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::menu.moon_compositions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('mining-manager.moon.calculator') }}">
                    <i class="fas fa-flask"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
                </a>
            </li>
            @can('mining-manager.director')
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('mining-manager.moon.metenox-cargo') }}">
                    <i class="fas fa-box-open"></i> {{ trans('mining-manager::menu.metenox_cargo') }}
                    <span class="badge badge-info ml-1" style="font-size: 0.6em;">Director</span>
                </a>
            </li>
            @endcan
        </ul>
    </div>
    <div class="card-body">

<div class="metenox-cargo-view">

    {{-- HEADER --}}
    <div class="mb-3">
        <h3 class="mb-1">
            <i class="fas fa-box-open"></i>
            Metenox Drill Cargo
            @if($isAdmin)
                <span class="badge badge-warning ml-2" title="SeAT-wide admin view">Admin</span>
            @else
                <span class="badge badge-info ml-2" title="Director-only">Director</span>
            @endif
        </h3>
        <p class="text-muted mb-0" style="font-size: 0.88rem;">
            @if($isAdmin && $showAllCorps)
                Live readout of every Metenox Moon Drill across <strong>all corporations</strong>
                with at least one drill. Data mirrors SeAT's existing corp-assets poll
                (ESI cache ~1h). Use the picker below to narrow scope.
            @elseif($isAdmin && $filterCorpId !== null && $filterCorpId !== $moonOwnerCorpId)
                Live readout of every Metenox Moon Drill for the selected corporation.
                Data mirrors SeAT's existing corp-assets poll (ESI cache ~1h).
            @else
                Live readout of every Metenox Moon Drill's cargo bay owned by the Moon Owner
                Corporation. Data mirrors SeAT's existing corp-assets poll (ESI cache ~1h).
            @endif
        </p>
    </div>

    {{-- Scope bar: dedicated row so the picker is in a predictable
         location regardless of viewport width or filter state. Admins
         get the dropdown; directors get the Moon Owner Corp pill. --}}
    @if($isAdmin && $availableCorps->count() > 0)
        <div class="card-dark mb-3" style="padding: 12px 16px;">
            <form method="GET" action="{{ route('mining-manager.moon.metenox-cargo') }}"
                  class="form-inline" style="gap: 8px;">
                <label for="corp-picker" class="mb-0 mr-2" style="font-weight: 600;">
                    <i class="fas fa-filter"></i> Corporation scope:
                </label>
                <select name="corporation_id" id="corp-picker"
                        class="form-control form-control-sm"
                        onchange="this.form.submit()"
                        style="min-width: 280px; max-width: 100%;">
                    <option value="all" @if($showAllCorps) selected @endif>
                        All corps ({{ $availableCorps->count() }})
                    </option>
                    @foreach($availableCorps as $corp)
                        @php
                            $isMoonOwner = $moonOwnerCorpId !== null
                                && (int) $corp->corporation_id === $moonOwnerCorpId;
                        @endphp
                        <option value="{{ $corp->corporation_id }}"
                            @if($filterCorpId === (int) $corp->corporation_id) selected @endif>
                            {{ $corp->name }} ({{ $corp->drill_count }}){{ $isMoonOwner ? ' [Moon Owner]' : '' }}
                        </option>
                    @endforeach
                </select>
                @if($filterCorpId !== null && $filterCorpId !== $moonOwnerCorpId && $moonOwnerCorpId !== null)
                    <a href="{{ route('mining-manager.moon.metenox-cargo') }}"
                       class="btn btn-sm btn-outline-secondary ml-2">
                        <i class="fas fa-undo"></i> Back to Moon Owner
                    </a>
                @elseif($showAllCorps && $moonOwnerCorpId !== null)
                    <a href="{{ route('mining-manager.moon.metenox-cargo') }}"
                       class="btn btn-sm btn-outline-secondary ml-2">
                        <i class="fas fa-undo"></i> Back to Moon Owner
                    </a>
                @endif
                <span class="text-muted ml-auto" style="font-size: 0.8rem;">
                    <i class="fas fa-info-circle"></i>
                    Picker only affects this page. Notifications still scope to Moon Owner Corp.
                </span>
            </form>
        </div>
    @elseif(!$isAdmin && $moonOwnerCorpId !== null)
        <div class="card-dark mb-3" style="padding: 10px 16px;">
            <span class="text-muted mr-2" style="font-size: 0.85rem;">
                <i class="fas fa-building"></i> Showing:
            </span>
            <span class="badge badge-secondary" style="font-size: 0.85rem; padding: 5px 10px;">
                {{ $moonOwnerCorpName }}
            </span>
            <span class="text-muted ml-2" style="font-size: 0.78rem;">(Moon Owner Corporation)</span>
        </div>
    @endif

    {{-- Empty state: no moon-owner corp configured. Shown to directors
         only — admins see the SeAT-wide picker instead, so they can
         still browse drills even without the operator setting. --}}
    @if(!$isAdmin && $moonOwnerCorpId === null)
        <div class="alert alert-warning">
            <h5><i class="fas fa-exclamation-triangle"></i> Moon Owner Corporation not configured</h5>
            <p class="mb-2">
                The Metenox Cargo page reads drills owned by the corp configured as the
                <strong>Moon Owner Corporation</strong> in Mining Manager settings.
                That setting is currently unset, so this page has nothing to show.
            </p>
            <p class="mb-0">
                Fix it: <a href="{{ route('mining-manager.settings.index') }}" class="btn btn-sm btn-warning">
                    <i class="fas fa-cog"></i> Open Settings → General
                </a> and set the Moon Owner Corporation.
            </p>
        </div>
    @endif

    {{-- Admin empty state: no Metenoxes anywhere in the install. --}}
    @if($isAdmin && $availableCorps->count() === 0)
        <div class="alert alert-info">
            <h5><i class="fas fa-info-circle"></i> No Metenox drills detected</h5>
            <p class="mb-0">
                SeAT hasn't seen any Metenox Moon Drills (type 81826) for any configured
                corporation yet. Either no corp owns one, or SeAT's corp-structures poll
                hasn't run since one was anchored. Check back after the next ESI poll.
            </p>
        </div>
    @endif

    {{-- HEADER CHIPS --}}
    <div class="row mb-3">
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon"><i class="fas fa-industry"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Metenox drills</span>
                    <span class="info-box-number">{{ number_format($totalDrills) }}</span>
                    <small>
                        @if($isAdmin && $showAllCorps)
                            across {{ count($homeCorpIds) }} corp{{ count($homeCorpIds) === 1 ? '' : 's' }}
                        @elseif($isAdmin && $filterCorpId !== null && $filterCorpId !== $moonOwnerCorpId)
                            owned by {{ $homeCorpNames[$filterCorpId] ?? ('Corporation #' . $filterCorpId) }}
                        @else
                            owned by Moon Owner Corp
                        @endif
                    </small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon"><i class="fas fa-coins"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">ISK in cargo</span>
                    <span class="info-box-number">
                        @if($pricingAvailable)
                            ~ {{ number_format($totalIsk / 1000000, 1) }}M
                        @else
                            &mdash;
                        @endif
                    </span>
                    <small>
                        @if($pricingAvailable)
                            {{ number_format($totalUnits) }} units total
                        @else
                            pricing unavailable
                        @endif
                    </small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            @php
                // Fill-state palette: green when avg is comfortable, yellow
                // when bays are getting full, red when at least one drill
                // has crossed the critical threshold (matches the in-card
                // fill_state classification and the notification trigger).
                $fillChipState = 'secondary';
                if ($avgFillPct !== null) {
                    if ($criticalCount > 0)        $fillChipState = 'danger';
                    elseif ($avgFillPct >= 60)     $fillChipState = 'warning';
                    elseif ($avgFillPct > 0)       $fillChipState = 'success';
                }
            @endphp
            <div class="info-box bg-gradient-{{ $fillChipState }}">
                <span class="info-box-icon"><i class="fas fa-{{ $criticalCount > 0 ? 'exclamation-triangle' : 'tachometer-alt' }}"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Avg bay fill</span>
                    <span class="info-box-number">
                        @if($avgFillPct !== null)
                            {{ $avgFillPct }}%
                        @else
                            &mdash;
                        @endif
                    </span>
                    <small>
                        @if($criticalCount > 0)
                            <strong>{{ $criticalCount }}</strong> drill{{ $criticalCount === 1 ? '' : 's' }} at &ge;85% &mdash; pull soon
                        @elseif($emptyDrills > 0)
                            {{ $emptyDrills }} empty bay{{ $emptyDrills === 1 ? '' : 's' }}
                        @elseif($avgFillPct !== null && $avgFillPct >= 60)
                            getting full
                        @else
                            healthy
                        @endif
                    </small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="info-box bg-gradient-dark">
                <span class="info-box-icon"><i class="fas fa-clock"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Oldest sample</span>
                    <span class="info-box-number">
                        @if($oldestPollAt)
                            <span class="eve-countdown" data-target="{{ $oldestPollAt->toIso8601String() }}">
                                {{ $oldestPollAt->diffForHumans() }}
                            </span>
                        @else
                            &mdash;
                        @endif
                    </span>
                    <small>
                        @if($oldestPollAt)
                            <span class="eve-time" data-eve-time="{{ $oldestPollAt->toIso8601String() }}">
                                {{ $oldestPollAt->format('M d, H:i') }} EVE
                            </span>
                        @else
                            no data yet
                        @endif
                    </small>
                </div>
            </div>
        </div>
    </div>

    {{-- PER-CORP SECTIONS --}}
    @forelse($perCorp as $corpId => $entry)
        @php
            // Group the cargo rows by structure_id so the blade can render one
            // card per drill, with that drill's ore stacks listed inside.
            $cargoByStructure = $entry['cargo_rows']->groupBy('structure_id');
            // Group all known drills (incl. empties) by system for a tidy heading.
            $structuresBySystem = $entry['known_structures']->groupBy('system_id');
            // Total summed quantity per drill, used to size the percent bars
            // inside each drill's ore-stack table.
            $drillTotals = $entry['summaries']->keyBy('structure_id');
        @endphp

        @if(count($perCorp) > 1)
            <div class="corp-section-header">
                <span class="corp-name">
                    <i class="fas fa-building"></i> {{ $entry['corporation_name'] }}
                </span>
                <span class="corp-id">#{{ $corpId }}</span>
                <span class="text-muted small ml-3">
                    {{ $entry['known_structures']->count() }} Metenox{{ $entry['known_structures']->count() === 1 ? '' : 'es' }}
                </span>
            </div>
        @endif

        @if($entry['known_structures']->isEmpty())
            <div class="alert alert-secondary py-2 mb-3" style="font-size: 0.88rem;">
                <i class="fas fa-info-circle"></i>
                No Metenox structures detected for this corporation. If you expected some to be
                here, check that your corp has Director ESI tokens and that SeAT's
                <code>character-update</code> + <code>corporation-update</code> jobs have
                completed at least once.
            </div>
        @endif

        @foreach($structuresBySystem as $systemId => $structures)
            @php
                // Pull the system name from the first structure in the group
                // (all structures in the group share the same system_id, so
                // any of their joined system_name values would do). Fall
                // back to the numeric id when the SDE row is missing — e.g.
                // brand-new systems CCP added since the operator last
                // refreshed their SDE seed.
                $systemName = $structures->first()->system_name ?? null;
            @endphp
            <div class="system-group-label">
                <i class="fas fa-globe"></i> System
                @if($systemName)
                    <strong style="color:#fbbf24;">{{ $systemName }}</strong>
                    <code class="text-muted ml-1" style="font-size: 0.75em;">#{{ $systemId }}</code>
                @else
                    <code style="color:#fbbf24; font-size: 0.85em;">{{ $systemId }}</code>
                @endif
                &middot; {{ $structures->count() }} drill{{ $structures->count() === 1 ? '' : 's' }}
            </div>

            @foreach($structures as $structure)
                @php
                    $structureId = $structure->structure_id;
                    $cargo       = $cargoByStructure->get($structureId, collect());
                    $drillTotal  = $drillTotals->get($structureId);
                    $totalQty    = $drillTotal ? (int) $drillTotal->total_units : 0;
                    $lastPoll    = $drillTotal && $drillTotal->last_polled_at
                        ? \Carbon\Carbon::parse($drillTotal->last_polled_at)
                        : null;
                @endphp

                <div class="drill-card">
                    <div class="drill-card-header">
                        <h5 class="drill-card-title">
                            <i class="fas fa-industry text-info"></i>
                            {{ $structure->structure_name ?? ('Structure #' . $structureId) }}
                            <span class="drill-id">#{{ $structureId }}</span>
                            @php
                                $drillIsk   = $perDrillIsk[$structureId] ?? null;
                                $drillFillPct   = $drillTotal->fill_pct   ?? 0;
                                $drillTotalM3   = $drillTotal->total_m3   ?? 0;
                                $drillFillState = $drillTotal->fill_state ?? 'ok';
                            @endphp
                            @if($drillTotal)
                                <span class="drill-fill-badge state-{{ $drillFillState }} ml-2"
                                      title="Bay fill — {{ number_format($drillTotalM3, 0) }} / {{ number_format($bayCapacityM3, 0) }} m³ (Metenox MoonMaterialBay capacity)">
                                    <i class="fas fa-box"></i>
                                    {{ $drillFillPct }}% full
                                </span>
                            @endif
                            @if($drillIsk !== null && $drillIsk > 0)
                                <span class="drill-isk-badge ml-2" title="Approx ISK value at current market prices">
                                    <i class="fas fa-coins"></i>
                                    ~ {{ number_format($drillIsk / 1000000, 1) }}M ISK
                                </span>
                            @endif
                        </h5>
                        <div class="d-flex align-items-center" style="gap: 0.6rem;">
                            @if(!empty($structure->state))
                                <span class="drill-state-pill state-{{ $structure->state }}" title="Structure state">
                                    {{ str_replace('_', ' ', $structure->state) }}
                                </span>
                            @endif
                            @if($lastPoll)
                                @php
                                    // ESI corp-assets cache is ~1h; flag stale > 2h.
                                    $isStale = $lastPoll->lt(\Carbon\Carbon::now()->subHours(2));
                                @endphp
                                <small class="{{ $isStale ? 'freshness-stale' : 'freshness-fresh' }}">
                                    <i class="fas fa-clock"></i>
                                    Last polled
                                    <span class="eve-time" data-eve-time="{{ $lastPoll->toIso8601String() }}">
                                        {{ $lastPoll->format('M d, H:i') }} EVE
                                    </span>
                                    (<span class="eve-countdown" data-target="{{ $lastPoll->toIso8601String() }}">{{ $lastPoll->diffForHumans() }}</span>)
                                </small>
                            @endif
                        </div>
                    </div>
                    <div class="drill-card-body">
                        @if($drillTotal && $drillTotal->total_m3 > 0)
                            <div class="fill-bar-wrap mb-3">
                                <div class="fill-bar-track">
                                    <div class="fill-bar state-{{ $drillFillState }}"
                                         style="width: {{ max(2, min(100, $drillFillPct)) }}%;">
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between mt-1" style="font-size: 0.78rem; color: #8b95a5;">
                                    <span>
                                        <i class="fas fa-box"></i>
                                        {{ number_format($drillTotalM3, 0) }} / {{ number_format($bayCapacityM3, 0) }} m³
                                    </span>
                                    <span>
                                        @if($drillFillState === 'critical')
                                            <i class="fas fa-exclamation-triangle text-danger"></i>
                                            <strong>Pull soon</strong>
                                        @elseif($drillFillState === 'warning')
                                            <i class="fas fa-clock text-warning"></i>
                                            getting full
                                        @else
                                            <i class="fas fa-check-circle text-success"></i>
                                            healthy
                                        @endif
                                    </span>
                                </div>
                            </div>
                        @endif

                        @if($cargo->isEmpty())
                            <div class="empty-drill">
                                <i class="fas fa-box-open"></i>
                                Cargo bay empty &mdash; either freshly pulled, or this drill hasn't
                                produced its first cycle yet (Metenoxes produce ~1 cycle every 14 days).
                            </div>
                        @else
                            <table class="ore-table">
                                <thead>
                                    <tr>
                                        <th style="width: 30%;">Ore type</th>
                                        <th class="text-right" style="width: 15%;">Units</th>
                                        <th class="text-right" style="width: 18%;">ISK value</th>
                                        <th class="text-right" style="width: 10%;">Share</th>
                                        <th style="width: 27%;">% of cargo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($cargo as $row)
                                        @php
                                            $share = $totalQty > 0 ? ($row->total_quantity / $totalQty * 100) : 0;
                                        @endphp
                                        <tr>
                                            <td>
                                                <i class="fas fa-cube text-info"></i>
                                                {{ $row->type_name ?? ('Type ' . $row->type_id) }}
                                                <small class="text-muted ml-1">#{{ $row->type_id }}</small>
                                            </td>
                                            <td class="text-right">{{ number_format($row->total_quantity) }}</td>
                                            <td class="text-right">
                                                @if(!empty($row->isk_value))
                                                    <span class="text-success">~ {{ number_format($row->isk_value / 1000000, 2) }}M</span>
                                                    <small class="text-muted d-block" style="font-size: 0.7em;">
                                                        @ {{ number_format($row->unit_price, 2) }} ISK
                                                    </small>
                                                @else
                                                    <span class="text-muted" style="font-size: 0.85em;">&mdash;</span>
                                                @endif
                                            </td>
                                            <td class="text-right">{{ number_format($share, 1) }}%</td>
                                            <td>
                                                <div class="ore-bar-wrap">
                                                    <div class="ore-bar" style="width: {{ max(1, min(100, $share)) }}%;"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                    <tr style="border-top: 1px solid #3a4049;">
                                        <td style="font-weight: 600; color: #fff;">
                                            <i class="fas fa-calculator"></i>
                                            Total in drill
                                        </td>
                                        <td class="text-right" style="font-weight: 600; color: #fff;">
                                            {{ number_format($totalQty) }}
                                        </td>
                                        <td class="text-right" style="font-weight: 600;">
                                            @if(($perDrillIsk[$structureId] ?? null) !== null)
                                                <span class="text-success">~ {{ number_format($perDrillIsk[$structureId] / 1000000, 2) }}M</span>
                                            @else
                                                <span class="text-muted">&mdash;</span>
                                            @endif
                                        </td>
                                        <td class="text-right">100%</td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            @endforeach
        @endforeach
    @empty
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>No corporations visible.</strong> Configure at least one corporation in
            Mining Manager Settings before this page can show anything. The Metenox Cargo
            page reads from each configured corp's existing ESI assets data &mdash; no
            additional setup needed.
        </div>
    @endforelse
</div>

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
