@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::dashboard.director_dashboard'))
@section('page_header', trans('mining-manager::dashboard.director_dashboard'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
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
                                            @if($userRankMoonOre)
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
                                        </div>
                                    </div>
                                </div>

                                {{-- Mining Volume by Group --}}
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-pie"></i>
                                                {{ trans('mining-manager::dashboard.mining_volume_by_group') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalVolumeChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Personal Income Chart --}}
                            <div class="row">
                                <div class="col-12">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-bar"></i>
                                                {{ trans('mining-manager::dashboard.mining_income_last_12_months') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="personalIncomeChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        {{-- CORPORATION STATS TAB --}}
                        <div class="tab-pane fade" id="corporation" role="tabpanel" aria-labelledby="corporation-tab">

                            {{-- CURRENT MONTH CORPORATION STATISTICS --}}
                            <div class="row">
                                <div class="col-12">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-calendar-alt"></i>
                                                {{ trans('mining-manager::dashboard.corporation_stats') }} - {{ now()->format('F Y') }}
                                            </h3>
                                            <div class="card-tools">
                                                <span class="badge badge-success">
                                                    <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::dashboard.live') }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                {{-- All Ore Value --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-primary">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-gem"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.all_ore_value') }}</span>
                                                            <span class="info-box-number">{{ number_format($corpCurrentMonthStats['all_ore_value'], 0) }}</span>
                                                            <small>ISK ({{ number_format($corpCurrentMonthStats['all_ore_quantity'], 0) }} units)</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Moon Ore Value --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-secondary">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-moon"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.moon_ore_value') }}</span>
                                                            <span class="info-box-number">{{ number_format($corpCurrentMonthStats['moon_ore_value'], 0) }}</span>
                                                            <small>ISK ({{ number_format($corpCurrentMonthStats['moon_ore_quantity'], 0) }} units)</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Tax Collected --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-success">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-coins"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.tax_collected') }}</span>
                                                            <span class="info-box-number">{{ number_format($corpCurrentMonthStats['tax_collected'], 0) }}</span>
                                                            <small>ISK / {{ number_format($corpCurrentMonthStats['tax_amount'], 0) }} owed</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Active Miners --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="info-box bg-gradient-warning">
                                                        <span class="info-box-icon">
                                                            <i class="fas fa-users"></i>
                                                        </span>
                                                        <div class="info-box-content">
                                                            <span class="info-box-text">{{ trans('mining-manager::dashboard.active_miners') }}</span>
                                                            <span class="info-box-number">{{ $corpCurrentMonthStats['active_miners'] }}</span>
                                                            <small>{{ trans('mining-manager::dashboard.characters') }}</small>
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
                                                {{-- All Ore Total Value --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-info">
                                                        <div class="inner">
                                                            <h3>{{ number_format($corpLast12MonthsStats['all_ore_total_value'], 0) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.all_ore_total_value') }}</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-gem"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Moon Ore Total Value --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-primary">
                                                        <div class="inner">
                                                            <h3>{{ number_format($corpLast12MonthsStats['moon_ore_total_value'], 0) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.moon_ore_total_value') }}</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-moon"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Total Tax Collected --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-success">
                                                        <div class="inner">
                                                            <h3>{{ number_format($corpLast12MonthsStats['tax_collected'], 0) }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.total_tax_collected') }}</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-coins"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Active Miners --}}
                                                <div class="col-lg-3 col-md-6">
                                                    <div class="small-box bg-warning">
                                                        <div class="inner">
                                                            <h3>{{ $corpLast12MonthsStats['active_miners'] }}</h3>
                                                            <p>{{ trans('mining-manager::dashboard.total_active_miners') }}</p>
                                                        </div>
                                                        <div class="icon">
                                                            <i class="fas fa-users"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- CORPORATION TOP MINERS --}}
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-trophy"></i>
                                                {{ trans('mining-manager::dashboard.top_miners_all_ore') }}
                                            </h3>
                                        </div>
                                        <div class="card-body p-0">
                                            <table class="table table-striped table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>{{ trans('mining-manager::dashboard.character') }}</th>
                                                        <th class="text-right">{{ trans('mining-manager::dashboard.value') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($topMinersOverallAllOre as $index => $miner)
                                                    <tr>
                                                        <td>{{ $index + 1 }}</td>
                                                        <td>
                                                            {{ $miner['character_name'] }}
                                                            @if($miner['alt_count'] > 0)
                                                                <span class="badge badge-info">+{{ $miner['alt_count'] }} alts</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-right">{{ number_format($miner['total_value'], 0) }} ISK</td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-moon"></i>
                                                {{ trans('mining-manager::dashboard.top_miners_moon_ore') }}
                                            </h3>
                                        </div>
                                        <div class="card-body p-0">
                                            <table class="table table-striped table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>{{ trans('mining-manager::dashboard.character') }}</th>
                                                        <th class="text-right">{{ trans('mining-manager::dashboard.value') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($topMinersOverallMoonOre as $index => $miner)
                                                    <tr>
                                                        <td>{{ $index + 1 }}</td>
                                                        <td>
                                                            {{ $miner['character_name'] }}
                                                            @if($miner['alt_count'] > 0)
                                                                <span class="badge badge-info">+{{ $miner['alt_count'] }} alts</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-right">{{ number_format($miner['total_value'], 0) }} ISK</td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- CORPORATION CHARTS --}}
                            <div class="row">
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-chart-line"></i>
                                                {{ trans('mining-manager::dashboard.corp_mining_performance') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="corpMiningChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-moon"></i>
                                                {{ trans('mining-manager::dashboard.moon_mining_performance') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="moonMiningChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Tax Collection Charts --}}
                            <div class="row">
                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                                {{ trans('mining-manager::dashboard.mining_tax_last_12_months') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="miningTaxChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-6">
                                    <div class="card card-dark">
                                        <div class="card-header">
                                            <h3 class="card-title">
                                                <i class="fas fa-calendar-alt"></i>
                                                {{ trans('mining-manager::dashboard.event_tax_last_12_months') }}
                                            </h3>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="eventTaxChart" style="min-height: 300px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
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

    // Corporation Mining Chart
    var corpCtx = document.getElementById('corpMiningChart').getContext('2d');
    new Chart(corpCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($corpMiningPerformanceChart['labels']) !!},
            datasets: [{
                label: 'Corporation Mining Value (ISK)',
                data: {!! json_encode($corpMiningPerformanceChart['data']) !!},
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
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

    // Moon Mining Chart
    var moonCtx = document.getElementById('moonMiningChart').getContext('2d');
    new Chart(moonCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($moonMiningPerformanceChart['labels']) !!},
            datasets: [{
                label: 'Moon Mining Value (ISK)',
                data: {!! json_encode($moonMiningPerformanceChart['data']) !!},
                borderColor: 'rgb(255, 206, 86)',
                backgroundColor: 'rgba(255, 206, 86, 0.2)',
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

    // Personal Volume by Group Chart
    var personalVolumeCtx = document.getElementById('personalVolumeChart').getContext('2d');
    new Chart(personalVolumeCtx, {
        type: 'pie',
        data: {
            labels: {!! json_encode($personalMiningVolumeByGroupChart['labels']) !!},
            datasets: [{
                data: {!! json_encode($personalMiningVolumeByGroupChart['data']) !!},
                backgroundColor: [
                    'rgba(255, 99, 132, 0.8)',
                    'rgba(54, 162, 235, 0.8)',
                    'rgba(255, 206, 86, 0.8)',
                    'rgba(75, 192, 192, 0.8)',
                    'rgba(153, 102, 255, 0.8)'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#c2c7d0' },
                    position: 'right'
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

    // Mining Tax Chart
    var miningTaxCtx = document.getElementById('miningTaxChart').getContext('2d');
    new Chart(miningTaxCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($miningTaxChart['labels']) !!},
            datasets: [{
                label: 'Tax Collected',
                data: {!! json_encode($miningTaxChart['collected']) !!},
                backgroundColor: 'rgba(75, 192, 192, 0.8)',
                borderColor: 'rgb(75, 192, 192)',
                borderWidth: 1
            }, {
                label: 'Tax Owed',
                data: {!! json_encode($miningTaxChart['owed']) !!},
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

    // Event Tax Chart
    var eventTaxCtx = document.getElementById('eventTaxChart').getContext('2d');
    new Chart(eventTaxCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($eventTaxChart['labels']) !!},
            datasets: [{
                label: 'Event Tax (ISK)',
                data: {!! json_encode($eventTaxChart['data']) !!},
                borderColor: 'rgb(153, 102, 255)',
                backgroundColor: 'rgba(153, 102, 255, 0.2)',
                tension: 0.1,
                fill: true
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
});
</script>
@endpush
