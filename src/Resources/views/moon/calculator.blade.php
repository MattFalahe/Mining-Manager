@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::moons.moon_simulator'))
@section('page_header', trans('mining-manager::menu.moon_extractions'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=2">
<style>
    .moon-simulator-page .finder-section { margin-bottom: 12px; }
    .moon-simulator-page .finder-section-title {
        color: var(--mm-text-muted) !important;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        padding-bottom: 6px;
        margin-bottom: 14px;
        border-bottom: 1px solid var(--mm-border);
    }
    .moon-simulator-page .finder-section-title i { margin-right: 4px; }
    .moon-simulator-page #moonFinderCard label:not(.custom-control-label) { font-weight: 600; margin-bottom: 6px; }
    .moon-simulator-page .finder-checks { display: flex; flex-wrap: wrap; align-items: center; min-height: calc(2.25rem + 2px); }
    .moon-simulator-page .finder-checks .custom-control { margin-right: 16px; }
    .moon-simulator-page .finder-checks .badge { font-size: 0.8rem; padding: 0.3em 0.6em; vertical-align: top; margin-top: 2px; }
    .moon-simulator-page .finder-rule { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
    .moon-simulator-page .finder-rule select,
    .moon-simulator-page .finder-rule input { max-width: 110px; }
    .moon-simulator-page .finder-actions { border-top: 1px solid var(--mm-border); padding-top: 15px; }
    .moon-simulator-page .finder-sortable { cursor: pointer; white-space: nowrap; }
    .moon-simulator-page .finder-sortable .finder-sort-mark { opacity: 0.45; margin-left: 4px; }
    .moon-simulator-page .finder-sortable.finder-sorted .finder-sort-mark { opacity: 1; }
    .moon-simulator-page #finderTable td,
    .moon-simulator-page #suggestionsTable td { vertical-align: middle; }
    .moon-simulator-page .finder-ore-badge { margin: 1px 3px 1px 0; font-weight: 500; }
    .moon-simulator-page .quality-rank { display: block; font-size: 0.72rem; opacity: 0.85; }
    /* Moon classes, coloured as SeAT's own extraction page colours them. Set
       here rather than left to the generic badge classes, because a skin can
       dull those into something hard to read, yellow worst of all. */
    .moon-simulator-page .badge-r4 { background-color: #28a745; color: #fff !important; }
    .moon-simulator-page .badge-r8 { background-color: #007bff; color: #fff !important; }
    .moon-simulator-page .badge-r16 { background-color: #17a2b8; color: #fff !important; }
    .moon-simulator-page .badge-r32 { background-color: #ffc107; color: #212529 !important; }
    .moon-simulator-page .badge-r64 { background-color: #dc3545; color: #fff !important; }
    .moon-simulator-page .badge-sec-high { background-color: #28a745; color: #fff !important; }
    .moon-simulator-page .badge-sec-low { background-color: #ffc107; color: #212529 !important; }
    .moon-simulator-page .badge-sec-null { background-color: #dc3545; color: #fff !important; }
    .moon-simulator-page .badge-sec-wormhole { background-color: #343a40; color: #fff !important; }
    .moon-simulator-page .badge-quality-exceptional { background: linear-gradient(135deg, #9b59b6, #8e44ad); color: #fff !important; }
    .moon-simulator-page .badge-quality-excellent { background-color: #28a745; color: #fff !important; }
    .moon-simulator-page .badge-quality-good { background-color: #17a2b8; color: #fff !important; }
    .moon-simulator-page .badge-quality-average { background-color: #ffc107; color: #212529 !important; }
    .moon-simulator-page .badge-quality-poor { background-color: #6c757d; color: #fff !important; }
    .moon-simulator-page .badge-watching {
        background: #0d9488;
        color: #fff !important;
        margin-left: 4px;
    }
    .moon-simulator-page .badge-claimed {
        background: #b45309;
        color: #fff !important;
        margin-left: 4px;
    }
    .moon-simulator-page .badge-ours {
        background: linear-gradient(135deg, var(--mm-primary-start), var(--mm-primary-end));
        color: #fff !important;
        margin-left: 4px;
    }
    /* Select2 lists open outside the page wrapper, so this cannot be scoped to it. */
    .finder-place-where { display: block; font-size: 0.8em; opacity: 0.75; }
    /* The claim form hangs off the body, outside the wrapper, so its buttons
       carry their own colours or a skin leaves them as bare text. Yellow for
       the one that marks a moon, matching the badge it produces. */
    #claimModal .btn-claim-save { background-color: #ffc107; border-color: #ffc107; color: #212529 !important; font-weight: 600; }
    #claimModal .btn-claim-save:hover { background-color: #e0a800; border-color: #d39e00; }
    #claimModal .btn-claim-clear { background-color: transparent; border: 1px solid #28a745; color: #28a745 !important; }
    #claimModal .btn-claim-clear:hover { background-color: #28a745; color: #fff !important; }
    #claimModal .btn-claim-cancel, #watchModal .btn-claim-cancel { background-color: transparent; border: 1px solid #6c757d; color: #adb5bd !important; }
    #claimModal .btn-claim-cancel:hover, #watchModal .btn-claim-cancel:hover { background-color: #6c757d; color: #fff !important; }
    #watchModal .btn-watch-save { background-color: #0d9488; border-color: #0d9488; color: #fff !important; font-weight: 600; }
    #watchModal .btn-watch-save:hover { background-color: #0f766e; border-color: #0f766e; }
    #watchModal .btn-watch-remove { background-color: transparent; border: 1px solid #dc3545; color: #dc3545 !important; }
    #watchModal .btn-watch-remove:hover { background-color: #dc3545; color: #fff !important; }
    /* Select2's clear cross is easy to lose against a dark skin, and it is the
       only way to put a place filter back to Any. */
    .moon-simulator-page .select2-selection__clear {
        color: #dc3545 !important;
        font-size: 1.15rem;
        font-weight: 700;
        margin-right: 6px;
    }
    .moon-simulator-page .select2-selection__choice__remove { color: #dc3545 !important; font-weight: 700; }
</style>
@endpush

@section('full')
@include('mining-manager::partials.toastr')
@php
    // One colour per moon class and per security band, used by the filters,
    // the results and the simulator alike, so a class reads the same wherever
    // it appears on this page.
    // SeAT's own moon extraction page colours the tiers green, blue, cyan,
    // yellow and red, so a moon reads the same here as it does there. The
    // classes are ours and the page styles them, so a skin cannot dull them.
    $rarityBadges = ['R4' => 'badge-r4', 'R8' => 'badge-r8', 'R16' => 'badge-r16', 'R32' => 'badge-r32', 'R64' => 'badge-r64'];
    $securityBadges = ['high' => 'badge-sec-high', 'low' => 'badge-sec-low', 'null' => 'badge-sec-null', 'wormhole' => 'badge-sec-wormhole'];
@endphp
<div class="mining-manager-wrapper mining-dashboard moon-simulator-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon') && !Request::is('*/moon/*') ? 'active' : '' }}" href="{{ route('mining-manager.moon.index') }}">
                    <i class="fas fa-list"></i> {{ trans('mining-manager::menu.all_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/active') ? 'active' : '' }}" href="{{ route('mining-manager.moon.active') }}">
                    <i class="fas fa-hourglass-half"></i> {{ trans('mining-manager::menu.active_extractions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/calendar') ? 'active' : '' }}" href="{{ route('mining-manager.moon.calendar') }}">
                    <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::menu.extraction_calendar') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/compositions') ? 'active' : '' }}" href="{{ route('mining-manager.moon.compositions') }}">
                    <i class="fas fa-chart-bar"></i> {{ trans('mining-manager::menu.moon_compositions') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/calculator') ? 'active' : '' }}" href="{{ route('mining-manager.moon.calculator') }}">
                    <i class="fas fa-flask"></i> {{ trans('mining-manager::menu.moon_value_calculator') }}
                </a>
            </li>
            @can('mining-manager.director')
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/moon/metenox-cargo') ? 'active' : '' }}" href="{{ route('mining-manager.moon.metenox-cargo') }}">
                    <i class="fas fa-box-open"></i> {{ trans('mining-manager::menu.metenox_cargo') }}
                    <span class="badge badge-info ml-1" style="font-size: 0.6em;">Director</span>
                </a>
            </li>
            @endcan
        </ul>
    </div>
    <div class="card-body">


<div class="moon-simulator">

    {{-- SIMULATOR EXPLANATION --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="alert alert-info">
                <h5><i class="fas fa-flask"></i> {{ trans('mining-manager::moons.simulator_title') }}</h5>
                <p class="mb-0">{{ trans('mining-manager::moons.simulator_description') }}</p>
            </div>
        </div>
    </div>

    {{-- EXTRACTION MODEL EXPLANATION --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-outline card-warning collapsed-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-lightbulb text-warning"></i>
                        {{ trans('mining-manager::moons.model_title') }}
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="collapse">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body" style="display: none;">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-search"></i> {{ trans('mining-manager::moons.model_discovery') }}</h6>
                            <p class="small">
                                {{ trans('mining-manager::moons.model_discovery_text') }}
                            </p>
                            <p class="small">
                                {{ trans('mining-manager::moons.model_discovery_text2') }}
                            </p>
                            <h6 class="mt-3"><i class="fas fa-chart-line"></i> {{ trans('mining-manager::moons.model_observed_data') }}</h6>
                            <table class="table table-sm table-dark small">
                                <thead>
                                    <tr>
                                        <th>{{ trans('mining-manager::moons.composition') }}</th>
                                        <th>{{ trans('mining-manager::moons.extraction_rate') }}</th>
                                        <th>{{ trans('mining-manager::moons.example_ores') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><span class="badge badge-success">100%</span></td>
                                        <td>~31,000 m³/h</td>
                                        <td class="text-muted small">Sylvite 46%, Chromite 34%, Zeolites 20%</td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge badge-info">~82%</span></td>
                                        <td>~30,500 m³/h</td>
                                        <td class="text-muted small">Euxenite 36%, Coesite 24%, Cobaltite 22%</td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge badge-warning">~70%</span></td>
                                        <td>~21,600 m³/h</td>
                                        <td class="text-muted small">Sylvite 40%, Euxenite 21%, Sperrylite 9%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-calculator"></i> {{ trans('mining-manager::moons.model_formula') }}</h6>
                            <div class="bg-dark p-3 rounded mb-3">
                                <code class="text-success">
                                    rate = 21,000 + ((composition% - 70%) / 30%) × 10,000
                                </code>
                            </div>
                            <p class="small">
                                {{ trans('mining-manager::moons.model_formula_explanation') }}
                            </p>
                            <h6 class="mt-3"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::moons.model_meaning') }}</h6>
                            <ul class="small mb-0">
                                <li>{{ trans('mining-manager::moons.model_meaning_higher') }}</li>
                                <li>{{ trans('mining-manager::moons.model_meaning_lower') }}</li>
                                <li>The <span class="badge badge-success">composition %</span> badge shows your moon's ore richness</li>
                                <li>The <span class="badge badge-warning">m³/h rate</span> badge shows the calculated extraction rate</li>
                            </ul>
                            <div class="alert alert-secondary small mt-3 mb-0">
                                <i class="fas fa-flask"></i> <strong>{{ trans('mining-manager::moons.note') }}:</strong> {{ trans('mining-manager::moons.model_note') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($scannedMoonCount > 0)

    @if($canFindMoons)
    {{-- FIND MOONS --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-dark" id="moonFinderCard">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-search-location"></i>
                        {{ trans('mining-manager::moons.finder_title') }}
                    </h3>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">{{ trans('mining-manager::moons.finder_intro', ['count' => number_format($scannedMoonCount)]) }}</p>

                    <div class="finder-section">
                        <h6 class="finder-section-title"><i class="fas fa-map-marker-alt"></i> {{ trans('mining-manager::moons.finder_section_location') }}</h6>
                        <div class="row">
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label for="finderName">{{ trans('mining-manager::moons.finder_name') }}</label>
                                    <input type="text" class="form-control" id="finderName" maxlength="100" placeholder="{{ trans('mining-manager::moons.finder_name_placeholder') }}">
                                    <small class="form-text text-muted">{{ trans('mining-manager::moons.finder_name_help') }}</small>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label for="finderRegion">{{ trans('mining-manager::moons.finder_region') }}</label>
                                    <select class="form-control" id="finderRegion" style="width: 100%;">
                                        <option value=""></option>
                                        @foreach($finderRegions as $region)
                                            <option value="{{ $region['id'] }}" data-moons="{{ $region['moons'] }}">{{ $region['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label for="finderConstellation">{{ trans('mining-manager::moons.finder_constellation') }}</label>
                                    <select class="form-control" id="finderConstellation" style="width: 100%;"></select>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label for="finderSystem">{{ trans('mining-manager::moons.finder_system') }}</label>
                                    <select class="form-control" id="finderSystem" style="width: 100%;"></select>
                                    <small class="form-text text-muted">{{ trans('mining-manager::moons.finder_location_help') }}</small>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label class="d-block">{{ trans('mining-manager::moons.finder_security') }}</label>
                                    <div class="finder-checks">
                                        @foreach($securityBadges as $band => $badge)
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input finder-security" id="finderSecurity_{{ $band }}" value="{{ $band }}">
                                                <label class="custom-control-label" for="finderSecurity_{{ $band }}"><span class="badge {{ $badge }}">{{ trans('mining-manager::moons.security_' . $band) }}</span></label>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label for="finderStation">{{ trans('mining-manager::moons.finder_station') }}</label>
                                    <select class="form-control" id="finderStation">
                                        <option value="">{{ trans('mining-manager::moons.finder_station_any') }}</option>
                                        <option value="ours">{{ trans('mining-manager::moons.finder_station_ours') }}</option>
                                        <option value="free">{{ trans('mining-manager::moons.finder_station_free') }}</option>
                                    </select>
                                    <small class="form-text text-muted">{{ trans('mining-manager::moons.finder_station_help') }}</small>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label for="finderClaim">{{ trans('mining-manager::moons.finder_claim') }}</label>
                                    <select class="form-control" id="finderClaim">
                                        <option value="">{{ trans('mining-manager::moons.finder_claim_any') }}</option>
                                        <option value="claimed">{{ trans('mining-manager::moons.finder_claim_claimed') }}</option>
                                        <option value="free">{{ trans('mining-manager::moons.finder_claim_free') }}</option>
                                    </select>
                                    <small class="form-text text-muted">{{ trans('mining-manager::moons.finder_claim_help') }}</small>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label for="finderWatch">{{ trans('mining-manager::moons.finder_watch') }}</label>
                                    <select class="form-control" id="finderWatch">
                                        <option value="">{{ trans('mining-manager::moons.finder_watch_any') }}</option>
                                        <option value="watched">{{ trans('mining-manager::moons.finder_watch_watched') }}</option>
                                        <option value="free">{{ trans('mining-manager::moons.finder_watch_free') }}</option>
                                    </select>
                                    <small class="form-text text-muted">{{ trans('mining-manager::moons.finder_watch_help') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="finder-section">
                        <h6 class="finder-section-title"><i class="fas fa-gem"></i> {{ trans('mining-manager::moons.finder_section_composition') }}</h6>
                        <div class="row">
                            <div class="col-lg-4 col-md-6">
                                <div class="form-group">
                                    <label class="d-block">{{ trans('mining-manager::moons.finder_class') }}</label>
                                    <div class="finder-checks">
                                        @foreach($rarityBadges as $class => $badge)
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input finder-class" id="finderClass_{{ $class }}" value="{{ $class }}">
                                                <label class="custom-control-label" for="finderClass_{{ $class }}"><span class="badge {{ $badge }}">{{ $class }}</span></label>
                                            </div>
                                        @endforeach
                                    </div>
                                    <small class="form-text text-muted">{{ trans('mining-manager::moons.finder_class_help') }}</small>
                                </div>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <div class="form-group">
                                    <label for="finderRichness">{{ trans('mining-manager::moons.finder_richness') }}</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="finderRichness" min="0" max="100" step="1">
                                        <div class="input-group-append"><span class="input-group-text">%</span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label for="finderOres">{{ trans('mining-manager::moons.finder_ores') }}</label>
                                    <select class="form-control" id="finderOres" multiple style="width: 100%;">
                                        @foreach(collect($finderOres)->groupBy('rarity') as $rarity => $ores)
                                            <optgroup label="{{ $rarity }}">
                                                @foreach($ores as $ore)
                                                    <option value="{{ $ore['id'] }}">{{ $ore['name'] }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="d-block">{{ trans('mining-manager::moons.finder_rules') }}</label>
                            <div id="finderRules"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="finderAddRule">
                                <i class="fas fa-plus"></i> {{ trans('mining-manager::moons.finder_add_rule') }}
                            </button>
                            <small class="form-text text-muted">{{ trans('mining-manager::moons.finder_rules_help') }}</small>
                        </div>
                    </div>

                    <div class="finder-section">
                        <h6 class="finder-section-title"><i class="fas fa-coins"></i> {{ trans('mining-manager::moons.finder_section_value') }}</h6>
                        <div class="row">
                            <div class="col-lg-2 col-md-6">
                                <div class="form-group">
                                    <label for="finderDays">{{ trans('mining-manager::moons.finder_days') }}</label>
                                    <input type="number" class="form-control" id="finderDays" value="28" min="6" max="56">
                                </div>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <div class="form-group">
                                    <label for="finderBasis">{{ trans('mining-manager::moons.finder_basis') }}</label>
                                    <select class="form-control" id="finderBasis">
                                        <option value="ore" {{ $defaultBasis === 'ore' ? 'selected' : '' }}>{{ trans('mining-manager::moons.basis_ore') }}</option>
                                        <option value="refined" {{ $defaultBasis === 'refined' ? 'selected' : '' }}>{{ trans('mining-manager::moons.basis_refined') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label for="finderValueMin">{{ trans('mining-manager::moons.finder_value_min') }}</label>
                                    <input type="text" class="form-control" id="finderValueMin">
                                    <small class="form-text text-muted">{{ trans('mining-manager::moons.finder_value_help') }}</small>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label for="finderValueMax">{{ trans('mining-manager::moons.finder_value_max') }}</label>
                                    <input type="text" class="form-control" id="finderValueMax">
                                </div>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <div class="form-group">
                                    <label for="finderQuality">{{ trans('mining-manager::moons.finder_quality_min') }}</label>
                                    <select class="form-control" id="finderQuality">
                                        <option value="">{{ trans('mining-manager::moons.finder_quality_any') }}</option>
                                        <option value="average">{{ trans('mining-manager::moons.average') }}</option>
                                        <option value="good">{{ trans('mining-manager::moons.good') }}</option>
                                        <option value="excellent">{{ trans('mining-manager::moons.excellent') }}</option>
                                        <option value="exceptional">{{ trans('mining-manager::moons.exceptional') }}</option>
                                    </select>
                                    <small class="form-text text-muted">{{ trans('mining-manager::moons.finder_quality_help') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="finder-actions d-flex flex-wrap align-items-center">
                        <button type="button" class="btn btn-mm-primary mr-2 mb-2" id="finderSearch">
                            <i class="fas fa-search"></i> {{ trans('mining-manager::moons.finder_search') }}
                        </button>
                        <button type="button" class="btn btn-outline-secondary mr-2 mb-2" id="finderReset">
                            <i class="fas fa-undo"></i> {{ trans('mining-manager::moons.finder_reset') }}
                        </button>
                        @if($features['allow_export_data'] ?? true)
                            <button type="button" class="btn btn-outline-success mr-2 mb-2" id="finderExport" disabled>
                                <i class="fas fa-file-csv"></i> {{ trans('mining-manager::moons.finder_export') }}
                            </button>
                        @endif
                        <span class="ml-auto text-muted small mb-2" id="finderSummary"></span>
                    </div>

                    <div id="finderResults" class="mt-2" style="display: none;">
                        <div class="d-flex flex-wrap align-items-center mb-2">
                            <span class="small text-muted mr-3">{{ trans('mining-manager::moons.finder_sort_hint') }}</span>
                            <label for="finderPerPage" class="small text-muted mb-0 mr-2">{{ trans('mining-manager::moons.finder_per_page') }}</label>
                            <select class="form-control form-control-sm" id="finderPerPage" style="width: auto;">
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-dark table-striped table-sm" id="finderTable">
                                <thead>
                                    <tr>
                                        <th class="finder-sortable" data-sort="name">{{ trans('mining-manager::moons.moon') }}</th>
                                        <th class="finder-sortable" data-sort="system">{{ trans('mining-manager::moons.finder_col_system') }}</th>
                                        <th class="finder-sortable" data-sort="constellation">{{ trans('mining-manager::moons.finder_col_constellation') }}</th>
                                        <th class="finder-sortable" data-sort="region">{{ trans('mining-manager::moons.finder_col_region') }}</th>
                                        <th class="text-center finder-sortable" data-sort="class" data-default="desc">{{ trans('mining-manager::moons.finder_col_class') }}</th>
                                        <th class="text-right finder-sortable" data-sort="share" data-default="desc">{{ trans('mining-manager::moons.finder_col_moon_ore') }}</th>
                                        <th>{{ trans('mining-manager::moons.finder_col_ores') }}</th>
                                        <th class="text-right finder-sortable" data-sort="value" data-default="desc" id="finderValueHeader"></th>
                                        <th class="text-center finder-sortable" data-sort="quality" data-default="desc">{{ trans('mining-manager::moons.finder_col_quality') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="d-flex align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary mr-2" id="finderPrev">
                                <i class="fas fa-chevron-left"></i> {{ trans('mining-manager::moons.finder_previous') }}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary mr-3" id="finderNext">
                                {{ trans('mining-manager::moons.finder_next') }} <i class="fas fa-chevron-right"></i>
                            </button>
                            <span class="small text-muted" id="finderPageInfo"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- WATCH A MOON --}}
    <div class="modal fade" id="watchModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-star"></i> {{ trans('mining-manager::moons.watch_title') }}</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong id="watchMoonName"></strong></p>
                    <p class="small text-muted" id="watchCurrent" style="display: none;"></p>
                    <div class="form-group mb-0">
                        <label for="watchNote">{{ trans('mining-manager::moons.watch_note') }}</label>
                        <input type="text" class="form-control" id="watchNote" maxlength="255" placeholder="{{ trans('mining-manager::moons.watch_note_placeholder') }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-claim-cancel" data-dismiss="modal">{{ trans('mining-manager::moons.claim_cancel') }}</button>
                    <button type="button" class="btn btn-watch-remove" id="watchRemove" style="display: none;">
                        <i class="fas fa-times"></i> {{ trans('mining-manager::moons.watch_remove') }}
                    </button>
                    <button type="button" class="btn btn-watch-save" id="watchSave">
                        <i class="fas fa-star"></i> {{ trans('mining-manager::moons.watch_save') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- REPORT A CLAIM --}}
    <div class="modal fade" id="claimModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-flag"></i> {{ trans('mining-manager::moons.claim_title') }}</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong id="claimMoonName"></strong></p>
                    <p class="small text-muted" id="claimCurrent" style="display: none;"></p>
                    <div class="form-group">
                        <label for="claimHeldBy">{{ trans('mining-manager::moons.claim_held_by') }}</label>
                        <input type="text" class="form-control" id="claimHeldBy" maxlength="100" placeholder="{{ trans('mining-manager::moons.claim_held_by_placeholder') }}">
                    </div>
                    <div class="form-group mb-0">
                        <label for="claimNote">{{ trans('mining-manager::moons.claim_note') }}</label>
                        <input type="text" class="form-control" id="claimNote" maxlength="255" placeholder="{{ trans('mining-manager::moons.claim_note_placeholder') }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-claim-cancel" data-dismiss="modal">{{ trans('mining-manager::moons.claim_cancel') }}</button>
                    <button type="button" class="btn btn-claim-clear" id="claimClear" style="display: none;">
                        <i class="fas fa-flag-checkered"></i> {{ trans('mining-manager::moons.claim_clear') }}
                    </button>
                    <button type="button" class="btn btn-claim-save" id="claimSave">
                        <i class="fas fa-flag"></i> {{ trans('mining-manager::moons.claim_save') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="row" id="simulatorRow">
        {{-- SIMULATOR INPUTS --}}
        <div class="col-lg-5">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-moon"></i>
                        {{ trans('mining-manager::moons.select_moon') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="moonSelect">{{ trans('mining-manager::moons.moon') }}</label>
                        <select class="form-control" id="moonSelect" style="width: 100%;"></select>
                    </div>

                    <div class="form-group">
                        <label>{{ trans('mining-manager::moons.extraction_duration') }}</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="extractionDays" value="14" min="6" max="56">
                            <div class="input-group-append">
                                <span class="input-group-text">{{ trans('mining-manager::moons.extraction_days') }}</span>
                            </div>
                        </div>
                        <small class="text-muted">EVE allows 6-56 days extraction cycles</small>
                    </div>

                    {{-- Duration Presets --}}
                    <div class="form-group">
                        <label>{{ trans('mining-manager::moons.duration_presets') }}</label>
                        <div class="btn-group btn-group-sm d-flex" role="group">
                            <button type="button" class="btn btn-outline-secondary duration-preset" data-days="6">6d</button>
                            <button type="button" class="btn btn-outline-secondary duration-preset active" data-days="14">14d</button>
                            <button type="button" class="btn btn-outline-secondary duration-preset" data-days="28">28d</button>
                            <button type="button" class="btn btn-outline-secondary duration-preset" data-days="56">56d</button>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="button" class="btn btn-primary btn-lg btn-block" id="simulateButton">
                            <i class="fas fa-flask"></i> {{ trans('mining-manager::moons.simulate') }}
                        </button>
                    </div>
                </div>
            </div>

            {{-- EXTRACTION INFO --}}
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i>
                        {{ trans('mining-manager::moons.extraction_rate') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>{{ trans('mining-manager::moons.extraction_rate') }}:</span>
                        <strong>21-31k {{ trans('mining-manager::moons.m3_per_hour') }}</strong>
                    </div>
                    <small class="text-muted d-block mb-3">{{ trans('mining-manager::moons.extraction_rate_note') }}</small>
                    <div class="small text-muted mb-3">
                        <i class="fas fa-flask"></i> Rate formula based on moon ore %:
                        <ul class="mb-0 mt-1">
                            <li>100% moon ore → ~31,000 m³/h</li>
                            <li>80% moon ore → ~27,000 m³/h</li>
                            <li>70% moon ore → ~21,000 m³/h</li>
                        </ul>
                    </div>
                    <hr class="bg-secondary">
                    <div class="d-flex justify-content-between mb-2">
                        <span>{{ trans('mining-manager::moons.scanned_moons_available') }}:</span>
                        <strong>{{ number_format($scannedMoonCount) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>{{ trans('mining-manager::moons.price_source') }}:</span>
                        <strong class="text-info text-right">
                            {{ trans('mining-manager::moons.price_source_cached') }},
                            @if($pricesUpdatedAt)
                                {{ trans('mining-manager::moons.prices_refreshed', ['time' => \Carbon\Carbon::parse($pricesUpdatedAt)->diffForHumans()]) }}
                            @else
                                {{ trans('mining-manager::moons.prices_never') }}
                            @endif
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        {{-- RESULTS PANEL --}}
        <div class="col-lg-7">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::moons.simulation_results') }}
                    </h3>
                    <div class="card-tools">
                        <span class="badge badge-info" id="resultMoonName" style="display: none;"></span>
                        <span id="resultStation"></span>
                        <span id="resultClaim"></span>
                        <span id="resultWatch"></span>
                        @if($canFindMoons)
                            <button type="button" class="btn btn-xs btn-outline-warning ml-1" id="resultClaimButton" style="display: none;">
                                <i class="fas fa-flag"></i> {{ trans('mining-manager::moons.claim_button') }}
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-info ml-1" id="resultWatchButton" style="display: none;">
                                <i class="fas fa-star"></i> {{ trans('mining-manager::moons.watch_button') }}
                            </button>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    {{-- Loading State --}}
                    <div id="loadingState" style="display: none;">
                        <div class="text-center py-5">
                            <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                            <p class="mt-3 text-muted">{{ trans('mining-manager::moons.simulating') }}</p>
                        </div>
                    </div>

                    {{-- Empty State --}}
                    <div id="emptyState">
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-flask fa-3x mb-3"></i>
                            <h5>{{ trans('mining-manager::moons.no_simulation_yet') }}</h5>
                        </div>
                    </div>

                    {{-- Results State --}}
                    <div id="resultsState" style="display: none;">
                        {{-- Total Value --}}
                        <div class="mm-result-panel text-center p-4 mb-4" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 8px;">
                            <h5 class="text-muted mb-2" id="totalValueLabel">{{ trans('mining-manager::moons.total_value') }}</h5>
                            <div class="mm-result-value" id="totalValue" style="font-size: 2.5rem; font-weight: bold; color: #27ae60;">
                                0 <small style="font-size: 1rem;">ISK</small>
                            </div>
                            <div class="text-muted" id="otherValue"></div>
                            <p class="mb-0 mt-2">
                                <span id="resultDuration" class="badge badge-secondary"></span>
                                <span id="resultVolume" class="badge badge-info ml-1"></span>
                            </p>
                            <p class="mb-0 mt-2">
                                <span id="resultComposition" class="badge badge-success" title="{{ trans('mining-manager::moons.moon_ore_richness') }}"></span>
                                <span id="resultRate" class="badge badge-warning ml-1"></span>
                            </p>
                        </div>

                        <div class="alert alert-warning small" id="unpricedOres" style="display: none;"></div>

                        {{-- Ore Breakdown Table --}}
                        <h6><i class="fas fa-gem"></i> {{ trans('mining-manager::moons.ore_breakdown') }}</h6>
                        <div class="table-responsive">
                            <table class="table table-dark table-striped table-sm" id="oreBreakdownTable">
                                <thead>
                                    <tr>
                                        <th>{{ trans('mining-manager::moons.ore_name') }}</th>
                                        <th class="text-center">{{ trans('mining-manager::moons.rarity') }}</th>
                                        <th class="text-right">%</th>
                                        <th class="text-right">{{ trans('mining-manager::moons.volume_m3') }}</th>
                                        <th class="text-right">{{ trans('mining-manager::moons.unit_price') }}</th>
                                        <th class="text-right">{{ trans('mining-manager::moons.ore_value') }}</th>
                                        <th class="text-right">{{ trans('mining-manager::moons.refined_value') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="oreBreakdownBody">
                                </tbody>
                            </table>
                        </div>

                        {{-- Value Breakdown Chart --}}
                        <div class="mt-4" id="valueChartContainer">
                            <h6><i class="fas fa-chart-bar"></i> {{ trans('mining-manager::moons.breakdown') }}</h6>
                            <div id="valueBreakdownBars"></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- QUICK STATS --}}
            <div class="row" id="quickStatsRow" style="display: none;">
                <div class="col-md-3">
                    <div class="info-box bg-gradient-success">
                        <span class="info-box-icon"><i class="fas fa-gem"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::moons.most_valuable_ore') }}</span>
                            <span class="info-box-number" id="statMostValuable">-</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="info-box bg-gradient-info">
                        <span class="info-box-icon"><i class="fas fa-cubes"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::moons.total_ores') }}</span>
                            <span class="info-box-number" id="statTotalOres">0</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="info-box bg-gradient-purple">
                        <span class="info-box-icon"><i class="fas fa-layer-group"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::moons.moon_class') }}</span>
                            <span class="info-box-number" id="statClassification">-</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="info-box bg-gradient-warning">
                        <span class="info-box-icon"><i class="fas fa-star"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ trans('mining-manager::moons.moon_quality') }}</span>
                            <span class="info-box-number" id="statQuality">-</span>
                            <span class="quality-rank" id="statQualityRank" style="display: none;"></span>
                        </div>
                    </div>
                </div>
            </div>

            @if($canFindMoons)
            {{-- BETTER MOONS NEARBY --}}
            <div class="card card-dark" id="suggestionsCard" style="display: none;">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-lightbulb"></i>
                        <span id="suggestionsTitle"></span>
                    </h3>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-2" id="suggestionsIntro"></p>
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-sm mb-0" id="suggestionsTable">
                            <tbody id="suggestionsBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            @if($features['allow_export_data'] ?? true)
            {{-- EXPORT OPTIONS --}}
            <div class="card card-dark" id="exportCard" style="display: none;">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-download"></i>
                        {{ trans('mining-manager::moons.export') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <button class="btn btn-block btn-primary btn-sm" id="exportJSON">
                                <i class="fas fa-file-code"></i> {{ trans('mining-manager::moons.export_json') }}
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-block btn-success btn-sm" id="exportCSV">
                                <i class="fas fa-file-csv"></i> {{ trans('mining-manager::moons.export_csv') }}
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-block btn-info btn-sm" id="copyToClipboard">
                                <i class="fas fa-copy"></i> {{ trans('mining-manager::moons.copy_results') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
    @else
    {{-- NO SCANNED MOONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ trans('mining-manager::moons.no_scanned_moons') }}
                    </h3>
                </div>
                <div class="card-body text-center py-5">
                    <i class="fas fa-moon fa-4x text-muted mb-3"></i>
                    <h4>{{ trans('mining-manager::moons.no_scanned_moons') }}</h4>
                    <p class="text-muted">{{ trans('mining-manager::moons.no_scanned_moons_message') }}</p>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

@push('javascript')
@php
    $moonLang = [
        'select_scanned_moon' => trans('mining-manager::moons.select_scanned_moon'),
        'search_moon' => trans('mining-manager::moons.search_moon'),
        'simulation_complete' => trans('mining-manager::moons.simulation_complete'),
        'copied_to_clipboard' => trans('mining-manager::moons.copied_to_clipboard'),
        'refined_total' => trans('mining-manager::moons.refined_total'),
        'refined_headline' => trans('mining-manager::moons.refined_headline'),
        'ore_total' => trans('mining-manager::moons.ore_total'),
        'basis_ore' => trans('mining-manager::moons.basis_ore'),
        'unpriced_ores' => trans('mining-manager::moons.unpriced_ores'),
        'quality_rank' => trans('mining-manager::moons.quality_rank'),
        'station_badge' => trans('mining-manager::moons.station_badge'),
        'station_tooltip' => trans('mining-manager::moons.station_tooltip'),
        'claim_badge' => trans('mining-manager::moons.claim_badge'),
        'claim_unknown' => trans('mining-manager::moons.claim_unknown'),
        'claim_reported_by' => trans('mining-manager::moons.claim_reported_by'),
        'claim_title' => trans('mining-manager::moons.claim_title'),
        'claim_saved' => trans('mining-manager::moons.claim_saved'),
        'claim_cleared' => trans('mining-manager::moons.claim_cleared'),
        'claim_failed' => trans('mining-manager::moons.claim_failed'),
        'watch_badge' => trans('mining-manager::moons.watch_badge'),
        'watch_reason' => trans('mining-manager::moons.watch_reason'),
        'watch_added_by' => trans('mining-manager::moons.watch_added_by'),
        'watch_title' => trans('mining-manager::moons.watch_title'),
        'watch_saved' => trans('mining-manager::moons.watch_saved'),
        'watch_removed' => trans('mining-manager::moons.watch_removed'),
        'watch_failed' => trans('mining-manager::moons.watch_failed'),
        'suggestions_title' => trans('mining-manager::moons.suggestions_title'),
        'suggestions_intro' => trans('mining-manager::moons.suggestions_intro'),
        'suggestions_none' => trans('mining-manager::moons.suggestions_none'),
        'finder_simulate' => trans('mining-manager::moons.finder_simulate'),
        'finder_any' => trans('mining-manager::moons.finder_any'),
        'finder_rule_at_least' => trans('mining-manager::moons.finder_rule_at_least'),
        'finder_ores_placeholder' => trans('mining-manager::moons.finder_ores_placeholder'),
        'finder_value_invalid' => trans('mining-manager::moons.finder_value_invalid'),
        'finder_loading' => trans('mining-manager::moons.finder_loading'),
        'finder_error' => trans('mining-manager::moons.finder_error'),
        'finder_summary' => trans('mining-manager::moons.finder_summary'),
        'finder_no_results' => trans('mining-manager::moons.finder_no_results'),
        'finder_col_value_ore' => trans('mining-manager::moons.finder_col_value_ore'),
        'finder_col_value_refined' => trans('mining-manager::moons.finder_col_value_refined'),
        'finder_page_of' => trans('mining-manager::moons.finder_page_of'),
    ];
    $qualityLabels = [];
    foreach (\MiningManager\Services\Moon\MoonFinderService::QUALITY_ORDER as $qualityKey) {
        $qualityLabels[$qualityKey] = trans('mining-manager::moons.' . $qualityKey);
    }
    $moonRoutes = [
        'simulate' => route('mining-manager.moon.simulate'),
        'scanned' => route('mining-manager.moon.scanned-moons'),
    ];
    if ($canFindMoons) {
        $moonRoutes['locations'] = route('mining-manager.moon.finder.locations');
        $moonRoutes['search'] = route('mining-manager.moon.finder.search');
        $moonRoutes['export'] = route('mining-manager.moon.finder.export');
        $moonRoutes['claim'] = route('mining-manager.moon.finder.claim');
        $moonRoutes['claim_clear'] = route('mining-manager.moon.finder.claim-clear');
        $moonRoutes['watch'] = route('mining-manager.moon.finder.watch');
        $moonRoutes['watch_remove'] = route('mining-manager.moon.finder.watch-remove');
    }
@endphp
<script>
const MOON_LANG = @json($moonLang);
const QUALITY_LABELS = @json($qualityLabels);
const MOON_ROUTES = @json($moonRoutes);
const RARITY_BADGES = @json($rarityBadges);
const SECURITY_BADGES = @json($securityBadges);
const CSRF_TOKEN = @json(csrf_token());

let simulationResults = null;
// Where "better moons" suggestions look, and which value they compare. Set
// when a moon is simulated from Find Moons so suggestions follow that search.
let simulationScope = {};
let simulationBasis = @json($defaultBasis);

$(document).ready(function() {
    // Duration preset buttons
    $('.duration-preset').on('click', function() {
        const days = $(this).data('days');
        $('#extractionDays').val(days);
        $('.duration-preset').removeClass('active');
        $(this).addClass('active');
    });

    // Keep preset buttons in sync with manual input
    $('#extractionDays').on('change', function() {
        const days = parseInt($(this).val(), 10);
        $('.duration-preset').removeClass('active');
        $(`.duration-preset[data-days="${days}"]`).addClass('active');
    });

    // The moon box searches by name instead of listing every scanned moon,
    // which on a busy install is tens of thousands of options.
    $('#moonSelect').select2({
        width: '100%',
        placeholder: MOON_LANG.search_moon,
        allowClear: true,
        minimumInputLength: 2,
        ajax: {
            url: MOON_ROUTES.scanned,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { q: params.term };
            },
            processResults: function(data) {
                return { results: data.results || [] };
            }
        }
    });

    $('#simulateButton').on('click', function() {
        simulationScope = {};
        runSimulation();
    });

    $(document).on('click', '.simulate-moon', function() {
        const $button = $(this);
        selectMoon($button.data('moon-id'), $button.data('moon-name'));

        if ($button.data('from-finder') && typeof finderCriteriaUsed !== 'undefined' && finderCriteriaUsed) {
            $('#extractionDays').val(finderCriteriaUsed.days).trigger('change');
            simulationBasis = finderCriteriaUsed.basis;
            simulationScope = {};
            if (finderCriteriaUsed.constellation_id) {
                simulationScope.scope_constellation_id = finderCriteriaUsed.constellation_id;
            } else if (finderCriteriaUsed.region_id) {
                simulationScope.scope_region_id = finderCriteriaUsed.region_id;
            }
        }

        runSimulation();
        $('html, body').animate({ scrollTop: $('#simulatorRow').offset().top - 20 }, 300);
    });

    // Export buttons
    $('#exportJSON').on('click', exportJSON);
    $('#exportCSV').on('click', exportCSV);
    $('#copyToClipboard').on('click', copyToClipboard);

    if (typeof initMoonFinder === 'function') {
        initMoonFinder();
    }
});

function selectMoon(moonId, moonName) {
    const $select = $('#moonSelect');
    if ($select.find(`option[value="${moonId}"]`).length === 0) {
        $select.append(new Option(moonName, moonId, true, true));
    }
    $select.val(String(moonId)).trigger('change');
}

function runSimulation() {
    const moonId = $('#moonSelect').val();
    const extractionDays = parseInt($('#extractionDays').val(), 10);

    if (!moonId) {
        toastr.warning(MOON_LANG.select_scanned_moon);
        return;
    }

    // Show loading state
    $('#emptyState').hide();
    $('#resultsState').hide();
    $('#loadingState').show();
    $('#quickStatsRow').hide();
    $('#exportCard').hide();
    $('#suggestionsCard').hide();

    $.ajax({
        url: MOON_ROUTES.simulate,
        method: 'POST',
        data: Object.assign({
            _token: CSRF_TOKEN,
            moon_id: moonId,
            extraction_days: extractionDays,
            basis: simulationBasis
        }, simulationScope),
        success: function(response) {
            simulationResults = response;
            displayResults(response);
        },
        error: function(xhr) {
            $('#loadingState').hide();
            $('#emptyState').show();
            toastr.error(xhr.responseJSON?.error || 'Simulation failed');
        }
    });
}

function displayResults(data) {
    $('#loadingState').hide();
    $('#resultsState').show();
    $('#quickStatsRow').show();
    $('#exportCard').show();

    // Update moon name badge
    $('#resultMoonName').text(data.moon_name).show();
    $('#resultStation').html(stationBadge(data.station));
    $('#resultClaim').html(claimBadge(data.claim));
    $('#resultWatch').html(watchBadge(data.watch));
    $('#resultClaimButton, #resultWatchButton').show();
    if (typeof rememberClaim === 'function') {
        rememberClaim($('#moonSelect').val(), data.claim, data.moon_name);
        rememberWatch($('#moonSelect').val(), data.watch);
    }

    // The page leads with the value it is working in, and names both, so the
    // ore value and the refined value can never be read as the same number.
    const oreValue = parseFloat(data.total_value) || 0;
    const refinedValue = parseFloat(data.total_refined_value) || 0;
    const leadsWithRefined = simulationBasis === 'refined';

    $('#totalValueLabel').text(
        leadsWithRefined
            ? MOON_LANG.refined_headline.replace(':efficiency', data.refining_efficiency)
            : MOON_LANG.basis_ore
    );
    $('#totalValue').html(formatNumber(leadsWithRefined ? refinedValue : oreValue) + ' <small style="font-size: 1rem;">ISK</small>');
    $('#otherValue').text(
        leadsWithRefined
            ? MOON_LANG.ore_total.replace(':value', formatNumber(oreValue))
            : MOON_LANG.refined_total
                .replace(':value', formatNumber(refinedValue))
                .replace(':efficiency', data.refining_efficiency)
    );

    // Update duration and volume badges
    $('#resultDuration').text(data.extraction_days + ' days');
    $('#resultVolume').text(formatNumber(data.total_volume_m3) + ' m³');

    // Update composition and rate badges
    $('#resultComposition').text(data.composition_percent + '% moon ore');
    $('#resultRate').text(formatNumber(data.extraction_rate_m3h) + ' m³/h');

    if (data.unpriced_ores && data.unpriced_ores.length > 0) {
        $('#unpricedOres').text(MOON_LANG.unpriced_ores.replace(':ores', data.unpriced_ores.join(', '))).show();
    } else {
        $('#unpricedOres').hide();
    }

    // Build ore breakdown table
    let tableHtml = '';
    let mostValuable = { name: '-', value: 0 };
    let valueBreakdownHtml = '';

    if (data.composition && data.composition.length > 0) {
        // Sort by value descending
        const sortedOres = [...data.composition].sort((a, b) => b.value - a.value);

        sortedOres.forEach((ore, index) => {
            const percentage = (ore.value / oreValue * 100) || 0;
            const barColor = getBarColor(index);
            const rarityBadge = getRarityBadge(ore.rarity);

            tableHtml += `
                <tr>
                    <td><i class="fas fa-gem" style="color: ${barColor};"></i> ${escapeHtml(ore.ore_name)}</td>
                    <td class="text-center">${rarityBadge}</td>
                    <td class="text-right">${ore.percentage.toFixed(1)}%</td>
                    <td class="text-right">${formatNumber(ore.volume)}</td>
                    <td class="text-right">${formatNumber(ore.unit_price)} ISK</td>
                    <td class="text-right text-success">${formatNumber(ore.value)} ISK</td>
                    <td class="text-right">${formatNumber(ore.refined_value)} ISK</td>
                </tr>
            `;

            // Value breakdown bars
            valueBreakdownHtml += `
                <div class="mb-2">
                    <div class="d-flex justify-content-between small">
                        <span>${escapeHtml(ore.ore_name)}</span>
                        <span>${percentage.toFixed(1)}%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar" style="width: ${percentage}%; background-color: ${barColor};"></div>
                    </div>
                </div>
            `;

            // Track most valuable
            if (ore.value > mostValuable.value) {
                mostValuable = { name: ore.ore_name, value: ore.value };
            }
        });
    }

    $('#oreBreakdownBody').html(tableHtml);
    $('#valueBreakdownBars').html(valueBreakdownHtml);

    // Update quick stats
    $('#statMostValuable').text(mostValuable.name);
    $('#statTotalOres').text(data.composition?.length || 0);
    $('#statClassification').text(data.moon_classification || 'Standard');

    if (data.quality) {
        $('#statQuality').text(QUALITY_LABELS[data.quality.key] || '-');
        $('#statQualityRank').text(qualityRankText(data.quality)).show();
    } else {
        $('#statQuality').text('-');
        $('#statQualityRank').hide();
    }

    renderSuggestions(data.suggestions);

    toastr.success(MOON_LANG.simulation_complete);
}

function renderSuggestions(suggestions) {
    const $card = $('#suggestionsCard');
    if ($card.length === 0 || !suggestions || !suggestions.class || !suggestions.scope) {
        $card.hide();
        return;
    }

    const fill = text => text
        .replace(':class', suggestions.class)
        .replace(':scope', suggestions.scope.name || '')
        .replace(':days', suggestions.days);

    $('#suggestionsTitle').text(fill(MOON_LANG.suggestions_title));

    if (!suggestions.moons || suggestions.moons.length === 0) {
        $('#suggestionsIntro').text(fill(MOON_LANG.suggestions_none));
        $('#suggestionsBody').html('');
        $card.show();
        return;
    }

    $('#suggestionsIntro').text(fill(MOON_LANG.suggestions_intro));

    let html = '';
    suggestions.moons.forEach(moon => {
        html += `
            <tr>
                <td>${escapeHtml(moon.name)}${stationBadge(moon.station)}</td>
                <td>${securityBadge(moon)} ${escapeHtml(moon.system || '-')}</td>
                <td class="text-right">${moon.moon_ore_percent}%</td>
                <td class="text-right text-success">
                    ${formatNumber(moon.value)} ISK
                    <small class="d-block text-muted">+${formatNumber(moon.difference)} ISK</small>
                </td>
                <td class="text-right">
                    <button type="button" class="btn btn-xs btn-outline-info simulate-moon" data-moon-id="${moon.moon_id}" data-moon-name="${escapeHtml(moon.name)}">
                        ${escapeHtml(MOON_LANG.finder_simulate)}
                    </button>
                </td>
            </tr>
        `;
    });

    $('#suggestionsBody').html(html);
    $card.show();
}

function qualityRankText(quality) {
    return MOON_LANG.quality_rank
        .replace(':percent', quality.top_percent)
        .replace(':count', formatNumber(quality.class_size))
        .replace(':class', quality.class);
}

function qualityBadge(quality) {
    if (!quality) {
        return '<span class="text-muted">-</span>';
    }

    return `<span class="badge badge-quality-${escapeHtml(quality.key)}">${escapeHtml(QUALITY_LABELS[quality.key] || quality.key)}</span>`
        + `<span class="quality-rank text-muted">${escapeHtml(qualityRankText(quality))}</span>`;
}

// Marks a moon one of our refineries still sits on, named in the tooltip.
function stationBadge(station) {
    if (!station) {
        return '';
    }

    const name = [station.structure, station.corporation].filter(Boolean).join(', ') || String(station.structure_id);

    return `<span class="badge badge-ours" title="${escapeHtml(MOON_LANG.station_tooltip.replace(':name', name))}">`
        + `<i class="fas fa-industry"></i> ${escapeHtml(MOON_LANG.station_badge)}</span>`;
}

// Marks a moon somebody else already holds, with what was reported in the
// tooltip. Every member sees it; reporting needs Find Moons.
function claimBadge(claim) {
    if (!claim) {
        return '';
    }

    const parts = [claim.claimed_by || MOON_LANG.claim_unknown];
    if (claim.note) {
        parts.push(claim.note);
    }
    if (claim.reported_by) {
        parts.push(MOON_LANG.claim_reported_by
            .replace(':who', claim.reported_by)
            .replace(':when', (claim.reported_at || '').substring(0, 10)));
    }

    return `<span class="badge badge-claimed" title="${escapeHtml(parts.join(' - '))}">`
        + `<i class="fas fa-flag"></i> ${escapeHtml(MOON_LANG.claim_badge)}</span>`;
}

// Marks a moon somebody wants to come back to, with the reason in the
// tooltip. Everyone sees it; the list is kept by Find Moons users.
function watchBadge(watch) {
    if (!watch) {
        return '';
    }

    const parts = [watch.note || MOON_LANG.watch_reason];
    if (watch.added_by) {
        parts.push(MOON_LANG.watch_added_by
            .replace(':who', watch.added_by)
            .replace(':when', (watch.added_at || '').substring(0, 10)));
    }

    return `<span class="badge badge-watching" title="${escapeHtml(parts.join(' - '))}">`
        + `<i class="fas fa-star"></i> ${escapeHtml(MOON_LANG.watch_badge)}</span>`;
}

function securityBadge(moon) {
    if (moon.security_band === 'wormhole') {
        return `<span class="badge ${SECURITY_BADGES.wormhole}">WH</span>`;
    }

    const security = parseFloat(moon.security);
    // As the game shows it: anything above zero reads at least 0.1.
    const shown = security > 0 && security < 0.05 ? '0.1' : (Math.round(security * 10) / 10).toFixed(1);
    return `<span class="badge ${SECURITY_BADGES[moon.security_band] || 'badge-secondary'}">${shown}</span>`;
}

function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function(character) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
    });
}

function formatNumber(num) {
    if (num === null || num === undefined) return '0';
    return parseFloat(num).toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function getBarColor(index) {
    const colors = ['#27ae60', '#3498db', '#f39c12', '#e74c3c', '#9b59b6', '#1abc9c', '#e67e22', '#95a5a6'];
    return colors[index % colors.length];
}

function getRarityBadge(rarity) {
    if (!rarity) return '<span class="badge badge-secondary">-</span>';

    return `<span class="badge ${RARITY_BADGES[rarity] || 'badge-secondary'}">${escapeHtml(rarity)}</span>`;
}

function exportJSON() {
    if (!simulationResults) return;

    const dataStr = JSON.stringify(simulationResults, null, 2);
    const dataBlob = new Blob([dataStr], { type: 'application/json' });
    const url = URL.createObjectURL(dataBlob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'moon-simulation-' + simulationResults.moon_name.replace(/[^a-z0-9]/gi, '_') + '.json';
    link.click();
}

function exportCSV() {
    if (!simulationResults) return;

    let csv = 'Ore Name,Percentage,Volume (m3),Unit Price (ISK),Ore Value (ISK),Refined Value (ISK)\n';
    simulationResults.composition.forEach(ore => {
        csv += `"${ore.ore_name}",${ore.percentage},${ore.volume},${ore.unit_price},${ore.value},${ore.refined_value}\n`;
    });
    csv += `\nTotal,,${simulationResults.total_volume_m3},,${simulationResults.total_value},${simulationResults.total_refined_value}\n`;
    csv += `Moon Name,${simulationResults.moon_name}\n`;
    csv += `Extraction Days,${simulationResults.extraction_days}\n`;

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'moon-simulation-' + simulationResults.moon_name.replace(/[^a-z0-9]/gi, '_') + '.csv';
    link.click();
}

function copyToClipboard() {
    if (!simulationResults) return;

    let text = 'Moon Extraction Simulation\n';
    text += '==========================\n';
    text += `Moon: ${simulationResults.moon_name}\n`;
    text += `Duration: ${simulationResults.extraction_days} days\n`;
    text += `Total Volume: ${formatNumber(simulationResults.total_volume_m3)} m³\n`;
    text += `Ore Value: ${formatNumber(simulationResults.total_value)} ISK\n`;
    text += `Refined Value: ${formatNumber(simulationResults.total_refined_value)} ISK\n\n`;
    text += 'Ore Breakdown:\n';
    simulationResults.composition.forEach(ore => {
        text += `  ${ore.ore_name}: ${formatNumber(ore.value)} ISK (${ore.percentage.toFixed(1)}%)\n`;
    });

    navigator.clipboard.writeText(text).then(() => {
        toastr.success(MOON_LANG.copied_to_clipboard);
    });
}
</script>

@if($canFindMoons)
<script>
let finderPage = 1;
let finderCriteriaUsed = null;
let finderSort = 'value';
let finderDirection = 'desc';
let claims = {};
let claimNames = {};
let claimMoonId = null;
let watches = {};
// The constellation and system picked, with the places above them, so a
// region picked later can tell whether they still lie inside it.
let finderPlaces = { constellation: null, system: null };

function initMoonFinder() {
    $('#finderOres').select2({ width: '100%', placeholder: MOON_LANG.finder_ores_placeholder });

    // Region, constellation and system can be picked in any order. Picking a
    // place fills in the places above it, and picking a region or
    // constellation clears anything below it that lies elsewhere.
    $('#finderRegion').select2({
        width: '100%',
        placeholder: MOON_LANG.finder_any,
        allowClear: true,
        templateResult: finderPlaceResult
    });
    $('#finderConstellation').select2(finderPlaceBox('constellation'));
    $('#finderSystem').select2(finderPlaceBox('system'));

    $('#finderRegion').on('select2:select', function(event) {
        const regionId = parseInt(event.params.data.id, 10);
        if (finderPlaces.constellation && finderPlaces.constellation.region_id !== regionId) {
            clearFinderPlace('constellation');
        }
        if (finderPlaces.system && finderPlaces.system.region_id !== regionId) {
            clearFinderPlace('system');
        }
    }).on('select2:unselect', function() {
        clearFinderPlace('constellation');
        clearFinderPlace('system');
    });

    $('#finderConstellation').on('select2:select', function(event) {
        const place = event.params.data;
        finderPlaces.constellation = { id: parseInt(place.id, 10), region_id: place.region_id };
        showFinderPlace('#finderRegion', place.region_id, place.region);
        if (finderPlaces.system && finderPlaces.system.constellation_id !== finderPlaces.constellation.id) {
            clearFinderPlace('system');
        }
    }).on('select2:unselect', function() {
        finderPlaces.constellation = null;
        clearFinderPlace('system');
    });

    $('#finderSystem').on('select2:select', function(event) {
        const place = event.params.data;
        finderPlaces.system = { id: parseInt(place.id, 10), constellation_id: place.constellation_id, region_id: place.region_id };
        finderPlaces.constellation = { id: place.constellation_id, region_id: place.region_id };
        showFinderPlace('#finderConstellation', place.constellation_id, place.constellation);
        showFinderPlace('#finderRegion', place.region_id, place.region);
    }).on('select2:unselect', function() {
        finderPlaces.system = null;
    });

    $('#finderTable').on('click', '.claim-moon', function() {
        openClaimModal($(this).data('moon-id'), $(this).data('moon-name'));
    });
    $('#resultClaimButton').on('click', function() {
        const moonId = $('#moonSelect').val();
        if (moonId) {
            openClaimModal(moonId, claimNames[moonId] || $('#moonSelect option:selected').text());
        }
    });
    $('#finderTable').on('click', '.watch-moon', function() {
        openWatchModal($(this).data('moon-id'), $(this).data('moon-name'));
    });
    $('#resultWatchButton').on('click', function() {
        const moonId = $('#moonSelect').val();
        if (moonId) {
            openWatchModal(moonId, claimNames[moonId] || $('#moonSelect option:selected').text());
        }
    });
    $('#watchSave').on('click', function() {
        saveWatch(false);
    });
    $('#watchRemove').on('click', function() {
        saveWatch(true);
    });
    $('#claimSave').on('click', function() {
        saveClaim(false);
    });
    $('#claimClear').on('click', function() {
        saveClaim(true);
    });

    $('#finderAddRule').on('click', function() {
        addFinderRule('R16', '');
    });

    $('#finderRules').on('click', '.finder-rule-remove', function() {
        $(this).closest('.finder-rule').remove();
    });

    $('#finderSearch').on('click', function() {
        runFinder(1);
    });
    $('#finderReset').on('click', resetFinder);
    $('#finderExport').on('click', exportFinder);
    $('#finderPrev').on('click', function() {
        runFinder(finderPage - 1);
    });
    $('#finderNext').on('click', function() {
        runFinder(finderPage + 1);
    });
    $('#finderPerPage').on('change', function() {
        if (finderCriteriaUsed) {
            runFinder(1);
        }
    });

    // Clicking a column sorts by it, and clicking the column already sorted
    // turns it around.
    $('#finderTable').on('click', '.finder-sortable', function() {
        const sort = $(this).data('sort');
        if (sort === finderSort) {
            finderDirection = finderDirection === 'asc' ? 'desc' : 'asc';
        } else {
            finderSort = sort;
            finderDirection = $(this).data('default') === 'desc' ? 'desc' : 'asc';
        }

        runFinder(1);
    });
}

// A searchable constellation or system box. Its list follows whatever region
// and constellation are picked at the moment it opens.
function finderPlaceBox(level) {
    return {
        width: '100%',
        placeholder: MOON_LANG.finder_any,
        allowClear: true,
        templateResult: finderPlaceResult,
        ajax: {
            url: MOON_ROUTES.locations,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    level: level,
                    q: params.term || '',
                    page: params.page || 1,
                    region_id: $('#finderRegion').val() || '',
                    constellation_id: level === 'system' ? ($('#finderConstellation').val() || '') : ''
                };
            },
            processResults: function(data) {
                return {
                    results: (data.results || []).map(place => Object.assign({ text: place.name }, place)),
                    pagination: { more: !!data.more }
                };
            }
        }
    };
}

function finderPlaceResult(place) {
    if (place.loading || !place.id) {
        return place.text;
    }

    const moons = place.moons !== undefined ? place.moons : $(place.element).data('moons');
    const $result = $('<span>').text(`${place.text} (${formatNumber(moons)})`);
    const where = [place.constellation, place.region].filter(Boolean).join(', ');
    if (where) {
        $result.append($('<span class="finder-place-where">').text(where));
    }

    return $result;
}

// Shows a place in a box without treating it as a pick, so nothing below it
// is cleared.
function showFinderPlace(selector, id, name) {
    const $select = $(selector);
    if ($select.find(`option[value="${id}"]`).length === 0) {
        $select.append(new Option(name, id, false, false));
    }
    $select.val(String(id)).trigger('change');
}

function clearFinderPlace(level) {
    finderPlaces[level] = null;
    $(level === 'system' ? '#finderSystem' : '#finderConstellation').val(null).trigger('change');
}

function addFinderRule(rarity, minimum) {
    const options = ['R4', 'R8', 'R16', 'R32', 'R64']
        .map(value => `<option value="${value}"${value === rarity ? ' selected' : ''}>${value}</option>`)
        .join('');

    $('#finderRules').append(`
        <div class="finder-rule">
            <select class="form-control form-control-sm finder-rule-rarity">${options}</select>
            <span class="small text-muted">${escapeHtml(MOON_LANG.finder_rule_at_least)}</span>
            <input type="number" class="form-control form-control-sm finder-rule-min" min="0" max="100" step="1" value="${escapeHtml(minimum)}">
            <span class="small text-muted">%</span>
            <button type="button" class="btn btn-sm btn-outline-danger finder-rule-remove"><i class="fas fa-times"></i></button>
        </div>
    `);
}

// ISK as typed: plain numbers, or with k, m or b. Returns null when empty and
// NaN when it cannot be read.
function parseIsk(text) {
    const cleaned = String(text || '').trim().toLowerCase().replace(/[,\s_]/g, '').replace(/isk$/, '');
    if (cleaned === '') {
        return null;
    }

    const match = cleaned.match(/^(\d+(?:\.\d+)?)([kmb]?)$/);
    if (!match) {
        return NaN;
    }

    return parseFloat(match[1]) * { '': 1, k: 1e3, m: 1e6, b: 1e9 }[match[2]];
}

function finderCriteria() {
    const valueMin = parseIsk($('#finderValueMin').val());
    const valueMax = parseIsk($('#finderValueMax').val());

    if (Number.isNaN(valueMin) || Number.isNaN(valueMax)) {
        toastr.warning(MOON_LANG.finder_value_invalid);
        return null;
    }

    const rules = [];
    $('#finderRules .finder-rule').each(function() {
        const minimum = $(this).find('.finder-rule-min').val();
        if (minimum !== '') {
            rules.push({ rarity: $(this).find('.finder-rule-rarity').val(), min: minimum });
        }
    });

    return {
        region_id: $('#finderRegion').val() || '',
        constellation_id: $('#finderConstellation').val() || '',
        system_id: $('#finderSystem').val() || '',
        security: $('.finder-security:checked').map((index, element) => element.value).get(),
        classes: $('.finder-class:checked').map((index, element) => element.value).get(),
        rules: rules,
        richness_min: $('#finderRichness').val(),
        ores: $('#finderOres').val() || [],
        days: $('#finderDays').val(),
        basis: $('#finderBasis').val(),
        value_min: valueMin === null ? '' : valueMin,
        value_max: valueMax === null ? '' : valueMax,
        quality_min: $('#finderQuality').val(),
        station: $('#finderStation').val(),
        claim: $('#finderClaim').val(),
        watch: $('#finderWatch').val(),
        name: $('#finderName').val(),
        sort: finderSort,
        direction: finderDirection,
        per_page: $('#finderPerPage').val()
    };
}

function runFinder(page) {
    const criteria = finderCriteria();
    if (!criteria) {
        return;
    }
    criteria.page = Math.max(1, page);

    $('#finderSearch').prop('disabled', true);
    $('#finderSummary').text(MOON_LANG.finder_loading);

    $.ajax({
        url: MOON_ROUTES.search,
        method: 'GET',
        data: criteria,
        success: function(data) {
            finderCriteriaUsed = criteria;
            finderPage = data.page;
            renderFinder(data);
        },
        error: function(xhr) {
            $('#finderSummary').text('');
            toastr.error(xhr.responseJSON?.message || MOON_LANG.finder_error);
        },
        complete: function() {
            $('#finderSearch').prop('disabled', false);
        }
    });
}

function renderFinder(data) {
    $('#finderSummary').text(
        MOON_LANG.finder_summary
            .replace(':matched', formatNumber(data.matched))
            .replace(':total', formatNumber(data.total_scanned))
    );
    $('#finderValueHeader').text(
        MOON_LANG[data.basis === 'refined' ? 'finder_col_value_refined' : 'finder_col_value_ore'].replace(':days', data.days)
    );
    markFinderSort(data.sort, data.direction);

    let html = '';
    if (data.rows.length === 0) {
        html = `<tr><td colspan="10" class="text-center text-muted py-4">${escapeHtml(MOON_LANG.finder_no_results)}</td></tr>`;
    }

    data.rows.forEach(row => {
        rememberClaim(row.moon_id, row.claim, row.name);
        rememberWatch(row.moon_id, row.watch);
        const ores = row.ores.map(ore => {
            return `<span class="badge ${RARITY_BADGES[ore.rarity] || 'badge-dark'} finder-ore-badge">${escapeHtml(ore.name)} ${ore.percent}%</span>`;
        }).join('');

        html += `
            <tr>
                <td>${escapeHtml(row.name)}${stationBadge(row.station)}${claimBadge(row.claim)}${watchBadge(row.watch)}</td>
                <td class="text-nowrap">${securityBadge(row)} ${escapeHtml(row.system || '-')}</td>
                <td>${escapeHtml(row.constellation || '-')}</td>
                <td>${escapeHtml(row.region || '-')}</td>
                <td class="text-center">${getRarityBadge(row.class)}</td>
                <td class="text-right">${row.moon_ore_percent}%</td>
                <td>${ores}</td>
                <td class="text-right text-success text-nowrap">${formatNumber(row.value)} ISK</td>
                <td class="text-center">${qualityBadge(row.quality)}</td>
                <td class="text-right text-nowrap">
                    <button type="button" class="btn btn-xs btn-outline-info simulate-moon" data-moon-id="${row.moon_id}" data-moon-name="${escapeHtml(row.name)}" data-from-finder="1">
                        ${escapeHtml(MOON_LANG.finder_simulate)}
                    </button>
                    <button type="button" class="btn btn-xs btn-outline-warning claim-moon ml-1" data-moon-id="${row.moon_id}" data-moon-name="${escapeHtml(row.name)}" title="${escapeHtml(MOON_LANG.claim_title)}">
                        <i class="fas fa-flag"></i>
                    </button>
                    <button type="button" class="btn btn-xs btn-outline-info watch-moon ml-1" data-moon-id="${row.moon_id}" data-moon-name="${escapeHtml(row.name)}" title="${escapeHtml(MOON_LANG.watch_title)}">
                        <i class="fas fa-star"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    $('#finderTable tbody').html(html);
    $('#finderPageInfo').text(MOON_LANG.finder_page_of.replace(':page', data.page).replace(':pages', data.pages));
    $('#finderPrev').prop('disabled', data.page <= 1);
    $('#finderNext').prop('disabled', data.page >= data.pages);
    $('#finderExport').prop('disabled', data.matched === 0);
    $('#finderResults').show();
}

// What has been reported about each moon on screen, so the form opens on
// what is already known rather than empty.
function rememberClaim(moonId, claim, name) {
    if (claim) {
        claims[moonId] = claim;
    } else {
        delete claims[moonId];
    }

    if (name) {
        claimNames[moonId] = name;
    }
}

function rememberWatch(moonId, watch) {
    if (watch) {
        watches[moonId] = watch;
    } else {
        delete watches[moonId];
    }
}

function openWatchModal(moonId, moonName) {
    const watch = watches[moonId] || null;

    claimMoonId = moonId;
    $('#watchMoonName').text(moonName || '');
    $('#watchNote').val(watch ? (watch.note || '') : '');
    $('#watchRemove').toggle(watch !== null);

    if (watch && watch.added_by) {
        $('#watchCurrent').text(MOON_LANG.watch_added_by
            .replace(':who', watch.added_by)
            .replace(':when', (watch.added_at || '').substring(0, 16))).show();
    } else {
        $('#watchCurrent').hide();
    }

    $('#watchModal').appendTo('body').modal('show');
}

function saveWatch(removing) {
    const moonId = claimMoonId;
    if (!moonId) {
        return;
    }

    $.ajax({
        url: removing ? MOON_ROUTES.watch_remove : MOON_ROUTES.watch,
        method: 'POST',
        data: {
            _token: CSRF_TOKEN,
            moon_id: moonId,
            note: $('#watchNote').val()
        },
        success: function(response) {
            showWatch(moonId, response.watch);
            $('#watchModal').modal('hide');
            toastr.success(removing ? MOON_LANG.watch_removed : MOON_LANG.watch_saved);
        },
        error: function(xhr) {
            toastr.error(xhr.responseJSON?.error || MOON_LANG.watch_failed);
        }
    });
}

function showWatch(moonId, watch) {
    rememberWatch(moonId, watch);

    const $cell = $(`#finderTable .watch-moon[data-moon-id="${moonId}"]`).closest('tr').find('td').first();
    $cell.find('.badge-watching').remove();
    if (watch) {
        $cell.append(watchBadge(watch));
    }

    if (String(moonId) === String($('#moonSelect').val())) {
        $('#resultWatch').html(watchBadge(watch));
    }
}

function openClaimModal(moonId, moonName) {
    const claim = claims[moonId] || null;

    claimMoonId = moonId;
    $('#claimMoonName').text(moonName || '');
    $('#claimHeldBy').val(claim ? (claim.claimed_by || '') : '');
    $('#claimNote').val(claim ? (claim.note || '') : '');
    $('#claimClear').toggle(claim !== null);

    if (claim && claim.reported_by) {
        $('#claimCurrent').text(MOON_LANG.claim_reported_by
            .replace(':who', claim.reported_by)
            .replace(':when', (claim.reported_at || '').substring(0, 16))).show();
    } else {
        $('#claimCurrent').hide();
    }

    // AdminLTE stacks a modal behind the page unless it hangs off the body.
    $('#claimModal').appendTo('body').modal('show');
}

function saveClaim(clearing) {
    const moonId = claimMoonId;
    if (!moonId) {
        return;
    }

    $.ajax({
        url: clearing ? MOON_ROUTES.claim_clear : MOON_ROUTES.claim,
        method: 'POST',
        data: {
            _token: CSRF_TOKEN,
            moon_id: moonId,
            claimed_by: $('#claimHeldBy').val(),
            note: $('#claimNote').val()
        },
        success: function(response) {
            showClaim(moonId, response.claim);
            $('#claimModal').modal('hide');
            toastr.success(clearing ? MOON_LANG.claim_cleared : MOON_LANG.claim_saved);
        },
        error: function(xhr) {
            toastr.error(xhr.responseJSON?.error || MOON_LANG.claim_failed);
        }
    });
}

// Moves the badge on the row and on the simulator without running the search
// again, so a page of results stays where it was.
function showClaim(moonId, claim) {
    rememberClaim(moonId, claim);

    const $cell = $(`#finderTable .claim-moon[data-moon-id="${moonId}"]`).closest('tr').find('td').first();
    $cell.find('.badge-claimed').remove();
    if (claim) {
        $cell.append(claimBadge(claim));
    }

    if (String(moonId) === String($('#moonSelect').val())) {
        $('#resultClaim').html(claimBadge(claim));
    }
}

// Puts an arrow on the sorted column and a faint one on the rest.
function markFinderSort(sort, direction) {
    $('#finderTable .finder-sortable').each(function() {
        const $header = $(this);
        const sorted = $header.data('sort') === sort;
        const icon = !sorted ? 'fa-sort' : (direction === 'asc' ? 'fa-sort-up' : 'fa-sort-down');

        $header.toggleClass('finder-sorted', sorted).find('.finder-sort-mark').remove();
        $header.append(` <i class="fas ${icon} finder-sort-mark"></i>`);
    });
}

function resetFinder() {
    $('#finderRegion').val(null).trigger('change');
    clearFinderPlace('constellation');
    clearFinderPlace('system');
    $('.finder-security, .finder-class').prop('checked', false);
    $('#finderRichness, #finderValueMin, #finderValueMax').val('');
    $('#finderOres').val(null).trigger('change');
    $('#finderRules').empty();
    $('#finderDays').val(28);
    $('#finderQuality').val('');
    $('#finderStation').val('');
    $('#finderClaim').val('');
    $('#finderWatch').val('');
    $('#finderName').val('');
    finderSort = 'value';
    finderDirection = 'desc';
    $('#finderPerPage').val('25');
    $('#finderResults').hide();
    $('#finderSummary').text('');
    $('#finderExport').prop('disabled', true);
    finderCriteriaUsed = null;
}

// The file matches the table: the last search that ran, every match rather
// than one page.
function exportFinder() {
    if (!finderCriteriaUsed) {
        return;
    }

    const params = Object.assign({}, finderCriteriaUsed);
    delete params.page;
    delete params.per_page;
    window.location.href = MOON_ROUTES.export + '?' + $.param(params);
}
</script>
@endif
@endpush

    </div>
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
