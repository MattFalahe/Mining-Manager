@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::dashboard.director_dashboard'))
@section('page_header', trans('mining-manager::dashboard.director_dashboard'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
<style>
/* ULTRA-AGGRESSIVE CSS OVERRIDES - INLINE TO BEAT EVERYTHING */
.mining-dashboard .tab-content {
    background-color: #0f1115 !important;
}

.mining-dashboard .card-body,
.mining-dashboard .card-dark .card-body,
.mining-dashboard .card.card-dark .card-body,
.mining-dashboard div.card-body {
    background-color: #161922 !important;
    color: #e8e8e8 !important;
}

.mining-dashboard .card.card-dark,
.mining-dashboard .card-dark {
    background-color: #161922 !important;
    border-color: #2c3138 !important;
}

.mining-dashboard .card-dark .card-header,
.mining-dashboard .card.card-dark .card-header {
    background-color: #1a1d24 !important;
    border-bottom: 1px solid #2c3138 !important;
}

.mining-dashboard .table {
    color: #e8e8e8 !important;
}

.mining-dashboard .table thead th {
    background-color: #1a1d24 !important;
    color: #ffffff !important;
}

.mining-dashboard canvas {
    background-color: rgba(26, 29, 36, 0.5) !important;
}
</style>
@endpush

@section('full')
<div class="mining-dashboard combined-director-dashboard">

    {{-- TAB NAVIGATION --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark card-tabs">
                <div class="card-header p-0 pt-1">
                    <ul class="nav nav-tabs" id="dashboard-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="personal-tab" data-toggle="pill" href="#personal" role="tab" aria-controls="personal" aria-selected="true">
                                <i class="fas fa-user"></i> {{ trans('mining-manager::dashboard.my_mining') }}
                                <span class="badge personal-badge ml-2">Personal</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="corporation-tab" data-toggle="pill" href="#corporation" role="tab" aria-controls="corporation" aria-selected="false">
                                <i class="fas fa-building"></i> {{ trans('mining-manager::dashboard.corporation_overview') }}
                                <span class="badge corporation-badge ml-2">Corporation</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="guest-miners-tab" data-toggle="pill" href="#guest-miners" role="tab" aria-controls="guest-miners" aria-selected="false">
                                <i class="fas fa-user-friends"></i> Guest Miners
                                <span class="badge badge-secondary ml-2">Guests</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="dashboard-tabContent">

                        {{-- PERSONAL STATS TAB --}}
                        <div class="tab-pane fade show active" id="personal" role="tabpanel" aria-labelledby="personal-tab">

                            {{-- CURRENT MONTH PERSONAL STATISTICS --}}
                            <div class="row">
                                <div class="col-12">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-calendar-alt"></i>
                                                {{ trans('mining-manager::dashboard.current_month_stats') }} - {{ now()->format('F Y') }}
                                            </h3>
                                            <div class="card-tools">
                                                <span class="badge badge-success">
                                                    <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::dashboard.live') }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                {{-- Total Mined Value --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-success">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-coins"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.total_mined_value') }}</span>
                                                            <span class="info-box-number">{{ number_format($personalCurrentMonthStats['total_value'], 0) }}</span>
                                                            <small>ISK</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Total Quantity --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-warning">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-gem"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.total_mined_quantity') }}</span>
                                                            <span class="info-box-number">{{ number_format($personalCurrentMonthStats['total_quantity'], 0) }}</span>
                                                            <small>{{ trans('mining-manager::dashboard.units') }}</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Tax Owed --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-danger">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-file-invoice-dollar"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.tax_owed') }}</span>
                                                            <span class="info-box-number">{{ number_format($personalCurrentMonthStats['tax_isk'], 0) }}</span>
                                                            <small>ISK</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Mining Days --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-info">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-calendar-check"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.mining_days') }}</span>
                                                            <span class="info-box-number">{{ $personalCurrentMonthStats['mining_days'] }}</span>
                                                            <small>{{ trans('mining-manager::dashboard.days') }}</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- LAST 12 MONTHS PERSONAL STATISTICS --}}
                            <div class="row">
                                <div class="col-12">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-area"></i>
                                                {{ trans('mining-manager::dashboard.last_12_months_stats') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                {{-- Total Quantity --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-primary">
                                                        <div class="inner">
                                                            <h3>{{ number_format($personalLast12MonthsStats['total_quantity'], 0) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.total_quantity') }}</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-gem"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Total Value --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-success">
                                                        <div class="inner">
                                                            <h3>{{ number_format($personalLast12MonthsStats['total_value'], 0) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.total_value') }} ISK</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-coins"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Total Volume --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-info">
                                                        <div class="inner">
                                                            <h3>{{ number_format($personalLast12MonthsStats['total_volume'], 2) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.total_volume') }} m³</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-cube"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Avg Per Month --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-warning">
                                                        <div class="inner">
                                                            <h3>{{ number_format($personalLast12MonthsStats['avg_per_month'], 0) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.avg_per_month') }} ISK</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-chart-bar"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- PERSONAL RANKINGS --}}
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-trophy"></i>
                                                {{ trans('mining-manager::dashboard.my_rank_all_ore') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            @if($userRankAllOre)
                                                <div class="text-center">
                                                    <h1 class="display-3 text-warning">
                                                        <i class="fas fa-medal"></i> #{{ $userRankAllOre }}
                                                    </h1>
                                                    <p class="text-muted">{{ trans('mining-manager::dashboard.out_of') }} {{ count($topMinersAllOre) }} {{ trans('mining-manager::dashboard.miners') }}</p>
                                                </div>
                                            @else
                                                <p class="text-center text-muted">{{ trans('mining-manager::dashboard.no_ranking_data') }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-moon"></i>
                                                {{ trans('mining-manager::dashboard.my_rank_moon_ore') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            @if(!$hasMoons)
                                                <p class="text-center text-muted"><i class="fas fa-moon mr-1"></i> Your corporation doesn't have any moons.</p>
                                            @elseif($userRankMoonOre)
                                                <div class="text-center">
                                                    <h1 class="display-3 text-info">
                                                        <i class="fas fa-medal"></i> #{{ $userRankMoonOre }}
                                                    </h1>
                                                    <p class="text-muted">{{ trans('mining-manager::dashboard.out_of') }} {{ count($topMinersMoonOre) }} {{ trans('mining-manager::dashboard.miners') }}</p>
                                                </div>
                                            @else
                                                <p class="text-center text-muted">{{ trans('mining-manager::dashboard.no_ranking_data') }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- PERSONAL CHARTS --}}
                            <div class="row">
                                {{-- Mining Performance Chart --}}
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-line"></i>
                                                {{ trans('mining-manager::dashboard.my_mining_performance') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalMiningChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                            <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_mining_performance') }}</small>
                                        </div>
                                    </div>
                                </div>

                                {{-- Mining Value by Group (Doughnut) --}}
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-pie"></i>
                                                {{ trans('mining-manager::dashboard.mining_by_group') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalVolumeChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                            <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_mining_by_group') }}</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Mining by Type + Income --}}
                            <div class="row">
                                {{-- Mining by Ore Type (Top 10) --}}
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-gem"></i>
                                                {{ trans('mining-manager::dashboard.mining_by_type') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalByTypeChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                            <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_mining_by_type') }}</small>
                                        </div>
                                    </div>
                                </div>

                                {{-- Personal Income Chart --}}
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-bar"></i>
                                                {{ trans('mining-manager::dashboard.mining_income_last_12_months') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalIncomeChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                            <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_mining_income') }}</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        {{-- CORPORATION STATS TAB (loaded via AJAX) --}}
                        <div class="tab-pane fade" id="corporation" role="tabpanel" aria-labelledby="corporation-tab">
                            <div id="corp-tab-loading" class="text-center py-5">
                                <div class="spinner-border text-info" role="status" style="width: 3rem; height: 3rem;">
                                    <span class="sr-only">Loading...</span>
                                </div>
                                <p class="text-muted mt-3">Loading corporation data...</p>
                            </div>
                            <div id="corp-tab-content" style="display: none;">

                                {{-- CURRENT MONTH CORPORATION STATISTICS --}}
                                <div class="row">
                                    <div class="col-12">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    {{ trans('mining-manager::dashboard.corporation_stats') }} - {{ now()->format('F Y') }}
                                                </h3>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-lg-3 col-md-6">
                                                        <div class="info-box bg-gradient-success">
                                                            <span class="info-box-icon"><i class="fas fa-gem"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">All Ore Value</span>
                                                                <span class="info-box-number" id="corp-all-ore-value">--</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-3 col-md-6">
                                                        <div class="info-box bg-gradient-warning">
                                                            <span class="info-box-icon"><i class="fas fa-moon"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">Moon Ore Value</span>
                                                                <span class="info-box-number" id="corp-moon-ore-value">--</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-3 col-md-6">
                                                        <div class="info-box bg-gradient-info">
                                                            <span class="info-box-icon"><i class="fas fa-users"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">Active Miners</span>
                                                                <span class="info-box-number" id="corp-active-miners">--</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-3 col-md-6">
                                                        <div class="info-box bg-gradient-danger">
                                                            <span class="info-box-icon"><i class="fas fa-coins"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">Tax Collected</span>
                                                                <span class="info-box-number" id="corp-tax-collected">--</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- LAST 12 MONTHS CORPORATION STATISTICS --}}
                                <div class="row">
                                    <div class="col-12">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title">
                                                    <i class="fas fa-chart-area"></i>
                                                    {{ trans('mining-manager::dashboard.last_12_months_stats') }}
                                                </h3>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-lg-3 col-md-6">
                                                        <div class="small-box bg-primary">
                                                            <div class="inner">
                                                                <h3 id="corp-12m-all-ore-value">--</h3>
                                                                <p>All Ore Value ISK</p>
                                                            </div>
                                                            <div class="icon"><i class="fas fa-gem"></i></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-3 col-md-6">
                                                        <div class="small-box bg-success">
                                                            <div class="inner">
                                                                <h3 id="corp-12m-moon-ore-value">--</h3>
                                                                <p>Moon Ore Value ISK</p>
                                                            </div>
                                                            <div class="icon"><i class="fas fa-moon"></i></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-3 col-md-6">
                                                        <div class="small-box bg-info">
                                                            <div class="inner">
                                                                <h3 id="corp-12m-tax-collected">--</h3>
                                                                <p>Tax Collected ISK</p>
                                                            </div>
                                                            <div class="icon"><i class="fas fa-coins"></i></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-3 col-md-6">
                                                        <div class="small-box bg-warning">
                                                            <div class="inner">
                                                                <h3 id="corp-12m-active-miners">--</h3>
                                                                <p>Active Miners</p>
                                                            </div>
                                                            <div class="icon"><i class="fas fa-users"></i></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- TOP MINERS --}}
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-trophy"></i> Top Miners - All Ore</h3>
                                            </div>
                                            <div class="card-body p-0">
                                                <table class="table table-sm table-striped">
                                                    <thead>
                                                        <tr><th>#</th><th>Miner</th><th>Corporation</th><th>Value (ISK)</th></tr>
                                                    </thead>
                                                    <tbody id="corp-top-miners-all-ore">
                                                        <tr><td colspan="4" class="text-center text-muted">Loading...</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-moon"></i> Top Miners - Corporation Moon Ore</h3>
                                            </div>
                                            <div class="card-body p-0">
                                                <table class="table table-sm table-striped">
                                                    <thead>
                                                        <tr><th>#</th><th>Miner</th><th>Corporation</th><th>Value (ISK)</th></tr>
                                                    </thead>
                                                    <tbody id="corp-top-miners-moon-ore">
                                                        <tr><td colspan="4" class="text-center text-muted">Loading...</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- CORPORATION CHARTS ROW 1: Performance + Moon Mining --}}
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-chart-line"></i> Corporation Mining Performance (12 Months)</h3>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="corpMiningPerformanceChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_corp_mining_performance') }}</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-moon"></i> Moon Mining Performance (12 Months)</h3>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="corpMoonMiningChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_corp_moon_mining') }}</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- CORPORATION CHARTS ROW 2: Mining by Group + Top Ores --}}
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-chart-pie"></i> Mining by Group</h3>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="corpMiningByGroupChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_corp_mining_by_group') }}</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-gem"></i> Top Ores Mined</h3>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="corpMiningByTypeChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_corp_top_ores') }}</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- CORPORATION CHARTS ROW 3: Tax + Event Tax --}}
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-coins"></i> Mining Tax (12 Months)</h3>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="corpTaxChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_corp_tax') }}</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-calendar-check"></i> Event Tax (12 Months)</h3>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="corpEventTaxChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::dashboard.note_corp_event_tax') }}</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        {{-- GUEST MINERS TAB (loaded via AJAX) --}}
                        <div class="tab-pane fade" id="guest-miners" role="tabpanel" aria-labelledby="guest-miners-tab">
                            <div id="guest-tab-loading" class="text-center py-5">
                                <div class="spinner-border text-info" role="status" style="width: 3rem; height: 3rem;">
                                    <span class="sr-only">Loading...</span>
                                </div>
                                <p class="text-muted mt-3">Loading guest miner data...</p>
                            </div>
                            <div id="guest-tab-content" style="display: none;">

                                {{-- MONTH SELECTOR --}}
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <div class="d-flex align-items-center">
                                            <button class="btn btn-sm btn-outline-secondary mr-2" id="guest-month-prev" title="Previous month">
                                                <i class="fas fa-chevron-left"></i>
                                            </button>
                                            <input type="month" class="form-control form-control-sm" id="guest-month-picker"
                                                   value="{{ now()->format('Y-m') }}" max="{{ now()->format('Y-m') }}"
                                                   style="width: 180px;">
                                            <button class="btn btn-sm btn-outline-secondary ml-2" id="guest-month-next" title="Next month">
                                                <i class="fas fa-chevron-right"></i>
                                            </button>
                                            <span class="ml-3 text-muted" id="guest-month-label" style="font-size: 1.1rem; font-weight: 600;">
                                                {{ now()->format('F Y') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {{-- GUEST MINER SUMMARY --}}
                                <div class="row">
                                    <div class="col-12">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title">
                                                    <i class="fas fa-user-friends"></i>
                                                    Guest Miners — Non-Corp Pilots at Your Structures
                                                </h3>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-3">
                                                        <div class="info-box bg-gradient-info">
                                                            <span class="info-box-icon"><i class="fas fa-users"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">GUEST MINERS</span>
                                                                <span class="info-box-number" id="guest-count">0</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="info-box bg-gradient-success">
                                                            <span class="info-box-icon"><i class="fas fa-gem"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">TOTAL MINED</span>
                                                                <span class="info-box-number" id="guest-total-value">0 ISK</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="info-box bg-gradient-warning">
                                                            <span class="info-box-icon"><i class="fas fa-moon"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">MOON ORE VALUE</span>
                                                                <span class="info-box-number" id="guest-moon-value">0 ISK</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="info-box bg-gradient-secondary">
                                                            <span class="info-box-icon"><i class="fas fa-info-circle"></i></span>
                                                            <div class="info-box-content">
                                                                <span class="info-box-text">STATUS</span>
                                                                <span class="info-box-number" style="font-size: 0.9rem;">Not Registered</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- GUEST MINERS TABLE --}}
                                <div class="row">
                                    <div class="col-12">
                                        <div class="card card-dark">
                                            <div class="card-header">
                                                <h3 class="card-title"><i class="fas fa-list"></i> Guest Miners</h3>
                                            </div>
                                            <div class="card-body table-responsive p-0">
                                                <table class="table table-hover table-striped">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Miner</th>
                                                            <th>Corporation</th>
                                                            <th>Total Mined (ISK)</th>
                                                            <th>Moon Ore (ISK)</th>
                                                            <th>Est. Tax (ISK)</th>
                                                            <th>Active Days</th>
                                                            <th>Last Seen</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="guest-miners-table">
                                                        <tr><td colspan="8" class="text-center text-muted">No data</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// ISK formatting helper (global scope for AJAX lazy-load access)
function formatISK(value) {
    value = parseFloat(value) || 0;
    if (value >= 1e9) return (value / 1e9).toFixed(1) + 'B';
    if (value >= 1e6) return (value / 1e6).toFixed(1) + 'M';
    if (value >= 1e3) return (value / 1e3).toFixed(1) + 'K';
    return value.toFixed(0);
}

$(document).ready(function() {
    // Personal Mining Chart
    var personalCtx = document.getElementById('personalMiningChart').getContext('2d');
    new Chart(personalCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($personalMiningPerformanceChart['labels']) !!},
            datasets: [{
                label: 'Mining Value (ISK)',
                data: {!! json_encode($personalMiningPerformanceChart['data']) !!},
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#c2c7d0' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { color: '#c2c7d0' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                x: {
                    ticks: { color: '#c2c7d0' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                }
            }
        }
    });

    // Group color mapping
    var groupColors = {
        'Moon Ore': 'rgba(255, 206, 86, 0.8)',
        'Regular Ore': 'rgba(54, 162, 235, 0.8)',
        'Ice': 'rgba(75, 192, 192, 0.8)',
        'Gas': 'rgba(153, 102, 255, 0.8)',
        'Abyssal': 'rgba(255, 99, 132, 0.8)'
    };

    // Personal Mining by Group (Doughnut - ISK values)
    var personalVolumeCtx = document.getElementById('personalVolumeChart').getContext('2d');
    var personalGroupLabels = {!! json_encode($personalMiningVolumeByGroupChart['labels']) !!};
    var personalGroupData = {!! json_encode($personalMiningVolumeByGroupChart['data']) !!};
    var personalGroupColors = personalGroupLabels.map(function(label) {
        return groupColors[label] || 'rgba(201, 203, 207, 0.8)';
    });

    new Chart(personalVolumeCtx, {
        type: 'doughnut',
        data: {
            labels: personalGroupLabels,
            datasets: [{
                data: personalGroupData,
                backgroundColor: personalGroupColors,
                borderColor: '#1a1d24',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#c2c7d0' },
                    position: 'right'
                },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            var pct = ((ctx.raw / total) * 100).toFixed(1);
                            return ctx.label + ': ' + formatISK(ctx.raw) + ' ISK (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });

    // Personal Mining by Type (Horizontal Bar - Top 10)
    var personalByTypeCtx = document.getElementById('personalByTypeChart').getContext('2d');
    new Chart(personalByTypeCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($personalMiningByTypeChart['labels']) !!},
            datasets: [{
                label: 'Value (ISK)',
                data: {!! json_encode($personalMiningByTypeChart['data']) !!},
                backgroundColor: {!! json_encode($personalMiningByTypeChart['colors']) !!},
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return formatISK(ctx.raw) + ' ISK';
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        color: '#c2c7d0',
                        callback: function(value) { return formatISK(value); }
                    },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                y: {
                    ticks: { color: '#c2c7d0', font: { size: 11 } },
                    grid: { display: false }
                }
            }
        }
    });

    // Personal Income Chart
    var personalIncomeCtx = document.getElementById('personalIncomeChart').getContext('2d');
    new Chart(personalIncomeCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($personalMiningIncomeChart['labels']) !!},
            datasets: [{
                label: 'Refined Value',
                data: {!! json_encode($personalMiningIncomeChart['refined_value']) !!},
                backgroundColor: 'rgba(75, 192, 192, 0.8)',
                borderColor: 'rgb(75, 192, 192)',
                borderWidth: 1
            }, {
                label: 'Tax Paid',
                data: {!! json_encode($personalMiningIncomeChart['tax_paid']) !!},
                backgroundColor: 'rgba(255, 99, 132, 0.8)',
                borderColor: 'rgb(255, 99, 132)',
                borderWidth: 1
            }, {
                label: 'Event Bonus',
                data: {!! json_encode($personalMiningIncomeChart['event_bonus']) !!},
                backgroundColor: 'rgba(255, 206, 86, 0.8)',
                borderColor: 'rgb(255, 206, 86)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#c2c7d0' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    stacked: false,
                    ticks: { color: '#c2c7d0' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                },
                x: {
                    stacked: false,
                    ticks: { color: '#c2c7d0' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' }
                }
            }
        }
    });

});

// Corporation tab lazy loading
var corpTabLoaded = false;

$('#corporation-tab').on('shown.bs.tab', function() {
    if (corpTabLoaded) return;
    corpTabLoaded = true;

    $.get('{{ $corpTabUrl }}')
        .done(function(data) {
            // === CURRENT MONTH STATS ===
            var cm = data.corpCurrentMonthStats || {};
            $('#corp-all-ore-value').text(formatISK(cm.all_ore_value || 0) + ' ISK');
            $('#corp-moon-ore-value').text(formatISK(cm.moon_ore_value || 0) + ' ISK');
            $('#corp-active-miners').text(cm.active_miners || 0);
            $('#corp-tax-collected').text(formatISK(cm.tax_collected || 0) + ' ISK');

            // === LAST 12 MONTHS STATS ===
            var l12 = data.corpLast12MonthsStats || {};
            $('#corp-12m-all-ore-value').text(formatISK(l12.all_ore_value || 0));
            $('#corp-12m-moon-ore-value').text(formatISK(l12.moon_ore_value || 0));
            $('#corp-12m-tax-collected').text(formatISK(l12.tax_collected || 0));
            $('#corp-12m-active-miners').text(l12.active_miners || 0);

            // === TOP MINERS TABLES ===
            populateTopMinersTable('#corp-top-miners-all-ore', data.topMinersOverallAllOre || []);
            if (data.hasMoons === false) {
                $('#corp-top-miners-moon-ore').html(
                    '<tr><td colspan="4" class="text-center text-muted">' +
                    '<i class="fas fa-moon mr-1"></i> Your corporation doesn\'t have any moons.' +
                    '</td></tr>'
                );
            } else {
                populateTopMinersTable('#corp-top-miners-moon-ore', data.topMinersOverallMoonOre || []);
            }

            // === CHARTS ===
            initCorpCharts(data);

            // Show content, hide loading
            $('#corp-tab-loading').hide();
            $('#corp-tab-content').show();
        })
        .fail(function() {
            $('#corp-tab-loading').html(
                '<div class="alert alert-danger">' +
                '<i class="fas fa-exclamation-triangle"></i> Failed to load corporation data. ' +
                '<a href="#" onclick="location.reload()">Reload page</a></div>'
            );
        });
});

function populateTopMinersTable(selector, miners) {
    var $tbody = $(selector);
    $tbody.empty();

    if (!miners || miners.length === 0) {
        $tbody.append('<tr><td colspan="4" class="text-center text-muted">No data available</td></tr>');
        return;
    }

    for (var i = 0; i < miners.length; i++) {
        var m = miners[i];
        var name = m.character_name || 'Unknown';
        if (m.alt_count > 0) {
            name += ' <small class="text-muted">(+' + m.alt_count + ' alts)</small>';
        }
        $tbody.append(
            '<tr>' +
            '<td>' + (i + 1) + '</td>' +
            '<td>' + name + '</td>' +
            '<td>' + (m.corporation_name || '-') + '</td>' +
            '<td>' + formatISK(m.total_value || 0) + ' ISK</td>' +
            '</tr>'
        );
    }
}

function initCorpCharts(data) {
    var chartColors = {
        text: '#c2c7d0',
        grid: 'rgba(255, 255, 255, 0.1)'
    };

    var defaultScales = {
        y: {
            beginAtZero: true,
            ticks: { color: chartColors.text, callback: function(v) { return formatISK(v); } },
            grid: { color: chartColors.grid }
        },
        x: { ticks: { color: chartColors.text }, grid: { color: chartColors.grid } }
    };

    var defaultLegend = { labels: { color: chartColors.text } };

    var groupColors = {
        'Moon Ore': 'rgba(255, 206, 86, 0.8)',
        'Regular Ore': 'rgba(54, 162, 235, 0.8)',
        'Ice': 'rgba(75, 192, 192, 0.8)',
        'Gas': 'rgba(153, 102, 255, 0.8)',
        'Abyssal': 'rgba(255, 99, 132, 0.8)'
    };

    // Helper: ensure all array values are floats (JSON may deliver strings from PHP decimal casts)
    function toFloats(arr) { return (arr || []).map(function(v) { return parseFloat(v) || 0; }); }

    // 1) Corp Mining Performance (bar chart)
    if (data.corpMiningPerformanceChart) {
        new Chart(document.getElementById('corpMiningPerformanceChart'), {
            type: 'bar',
            data: {
                labels: data.corpMiningPerformanceChart.labels,
                datasets: [{
                    label: 'All Ore Value (ISK)',
                    data: toFloats(data.corpMiningPerformanceChart.data),
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.6)',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: defaultLegend }, scales: defaultScales }
        });
    }

    // 2) Moon Mining Performance (bar chart)
    if (data.moonMiningPerformanceChart) {
        new Chart(document.getElementById('corpMoonMiningChart'), {
            type: 'bar',
            data: {
                labels: data.moonMiningPerformanceChart.labels,
                datasets: [{
                    label: 'Moon Ore Value (ISK)',
                    data: toFloats(data.moonMiningPerformanceChart.data),
                    borderColor: 'rgb(255, 205, 86)',
                    backgroundColor: 'rgba(255, 205, 86, 0.6)',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: defaultLegend }, scales: defaultScales }
        });
    }

    // 3) Mining by Group (Doughnut)
    if (data.corpMiningByGroupChart && data.corpMiningByGroupChart.labels.length > 0) {
        var groupLabels = data.corpMiningByGroupChart.labels;
        var groupData = toFloats(data.corpMiningByGroupChart.data);
        var bgColors = groupLabels.map(function(label) {
            return groupColors[label] || 'rgba(201, 203, 207, 0.8)';
        });

        new Chart(document.getElementById('corpMiningByGroupChart'), {
            type: 'doughnut',
            data: {
                labels: groupLabels,
                datasets: [{
                    data: groupData,
                    backgroundColor: bgColors,
                    borderColor: '#1a1d24',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: chartColors.text }, position: 'right' },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var pct = ((ctx.raw / total) * 100).toFixed(1);
                                return ctx.label + ': ' + formatISK(ctx.raw) + ' ISK (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    } else {
        $('#corpMiningByGroupChart').parent().append('<p class="text-center text-muted mt-3">No mining data available</p>');
    }

    // 4) Top Ores Mined (Horizontal Bar)
    if (data.corpMiningByTypeChart && data.corpMiningByTypeChart.labels.length > 0) {
        new Chart(document.getElementById('corpMiningByTypeChart'), {
            type: 'bar',
            data: {
                labels: data.corpMiningByTypeChart.labels,
                datasets: [{
                    label: 'Value (ISK)',
                    data: toFloats(data.corpMiningByTypeChart.data),
                    backgroundColor: data.corpMiningByTypeChart.colors || 'rgba(54, 162, 235, 0.8)',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return formatISK(ctx.raw) + ' ISK'; }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { color: chartColors.text, callback: function(v) { return formatISK(v); } },
                        grid: { color: chartColors.grid }
                    },
                    y: {
                        ticks: { color: chartColors.text, font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });
    } else {
        $('#corpMiningByTypeChart').parent().append('<p class="text-center text-muted mt-3">No mining data available</p>');
    }

    // 5) Tax Revenue Chart (dual-dataset: Collected vs Owed)
    if (data.miningTaxChart) {
        new Chart(document.getElementById('corpTaxChart'), {
            type: 'bar',
            data: {
                labels: data.miningTaxChart.labels,
                datasets: [{
                    label: 'Tax Collected (ISK)',
                    data: toFloats(data.miningTaxChart.collected),
                    backgroundColor: 'rgba(75, 192, 192, 0.8)',
                    borderColor: 'rgb(75, 192, 192)',
                    borderWidth: 1
                }, {
                    label: 'Tax Owed (ISK)',
                    data: toFloats(data.miningTaxChart.owed),
                    backgroundColor: 'rgba(255, 99, 132, 0.8)',
                    borderColor: 'rgb(255, 99, 132)',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: defaultLegend }, scales: defaultScales }
        });
    }

    // 6) Event Tax Chart
    if (data.eventTaxChart) {
        new Chart(document.getElementById('corpEventTaxChart'), {
            type: 'bar',
            data: {
                labels: data.eventTaxChart.labels,
                datasets: [{
                    label: 'Event Tax Impact (ISK)',
                    data: toFloats(data.eventTaxChart.data),
                    backgroundColor: 'rgba(153, 102, 255, 0.6)',
                    borderColor: 'rgb(153, 102, 255)',
                    borderWidth: 1
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: defaultLegend }, scales: defaultScales }
        });
    }
}

// Guest Miners tab — month-aware lazy loading
var guestTabInitialized = false;
var guestBaseUrl = '{{ $guestTabUrl }}';

function loadGuestMinersData(month) {
    // Show loading state
    $('#guest-tab-loading').show();
    $('#guest-tab-content').hide();

    $.get(guestBaseUrl, { month: month })
        .done(function(data) {
            // Summary stats
            $('#guest-count').text(data.guestCount || 0);
            $('#guest-total-value').text(formatISK(data.totalValue || 0) + ' ISK');
            $('#guest-moon-value').text(formatISK(data.totalMoonOreValue || 0) + ' ISK');

            // Populate table
            var $tbody = $('#guest-miners-table');
            $tbody.empty();

            var miners = data.guestMiners || [];
            if (miners.length === 0) {
                $tbody.append('<tr><td colspan="8" class="text-center text-muted">No guest miners found for this month</td></tr>');
            } else {
                for (var i = 0; i < miners.length; i++) {
                    var m = miners[i];
                    var regBadge = m.is_registered
                        ? '<span class="badge badge-success">Registered</span>'
                        : '<span class="badge badge-secondary">Unregistered</span>';
                    $tbody.append(
                        '<tr>' +
                        '<td>' + (i + 1) + '</td>' +
                        '<td>' + (m.character_name || 'Unknown') + ' ' + regBadge + '</td>' +
                        '<td>' + (m.corporation_name || 'Unknown') + '</td>' +
                        '<td>' + formatISK(m.total_value || 0) + ' ISK</td>' +
                        '<td>' + formatISK(m.moon_ore_value || 0) + ' ISK</td>' +
                        '<td>' + formatISK(m.total_tax || 0) + ' ISK</td>' +
                        '<td>' + (m.active_days || 0) + '</td>' +
                        '<td>' + (m.last_seen || '-') + '</td>' +
                        '</tr>'
                    );
                }
            }

            // Update month label
            var d = new Date(month + '-15');
            var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            $('#guest-month-label').text(monthNames[d.getMonth()] + ' ' + d.getFullYear());

            // Show content, hide loading
            $('#guest-tab-loading').hide();
            $('#guest-tab-content').show();
        })
        .fail(function() {
            $('#guest-tab-loading').html(
                '<div class="alert alert-danger">' +
                '<i class="fas fa-exclamation-triangle"></i> Failed to load guest miner data. ' +
                '<a href="#" onclick="location.reload()">Reload page</a></div>'
            );
        });
}

// Month picker navigation
$('#guest-month-picker').on('change', function() {
    loadGuestMinersData($(this).val());
});

$('#guest-month-prev').on('click', function() {
    var picker = $('#guest-month-picker');
    var current = new Date(picker.val() + '-15');
    current.setMonth(current.getMonth() - 1);
    var newVal = current.getFullYear() + '-' + String(current.getMonth() + 1).padStart(2, '0');
    picker.val(newVal).trigger('change');
});

$('#guest-month-next').on('click', function() {
    var picker = $('#guest-month-picker');
    var current = new Date(picker.val() + '-15');
    var now = new Date();
    current.setMonth(current.getMonth() + 1);
    // Don't go beyond current month
    if (current.getFullYear() > now.getFullYear() ||
        (current.getFullYear() === now.getFullYear() && current.getMonth() > now.getMonth())) {
        return;
    }
    var newVal = current.getFullYear() + '-' + String(current.getMonth() + 1).padStart(2, '0');
    picker.val(newVal).trigger('change');
});

// Lazy-load on first tab open
$('#guest-miners-tab').on('shown.bs.tab', function() {
    if (guestTabInitialized) return;
    guestTabInitialized = true;
    loadGuestMinersData($('#guest-month-picker').val());
});
</script>
@endpush
