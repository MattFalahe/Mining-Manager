@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::ledger.my_mining'))
@section('page_header', trans_choice('mining-manager::ledger.mining_ledger', 2))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=9">
@endpush

@section('full')
<div class="mining-dashboard">
{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ (Request::is('*/ledger') && !Request::is('*/ledger/*')) || Request::is('*/ledger/summary') ? 'active' : '' }}" href="{{ route('mining-manager.ledger.index') }}">
                    <i class="fas fa-layer-group"></i> {{ trans('mining-manager::ledger.mining_summary') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/ledger/my-mining') ? 'active' : '' }}" href="{{ route('mining-manager.ledger.my-mining') }}">
                    <i class="fas fa-user"></i> {{ trans('mining-manager::menu.my_mining') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/ledger/reprocessing') ? 'active' : '' }}" href="{{ route('mining-manager.ledger.reprocessing') }}">
                    <i class="fas fa-recycle"></i> {{ trans('mining-manager::ledger.reprocessing_calculator') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">

<div class="mining-manager-wrapper my-mining">
    
    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::ledger.your_mining_summary') }}
                        @if(!empty($rangeLabel))
                            <span class="badge badge-secondary ml-2">{{ $rangeLabel }}</span>
                        @endif
                    </h3>
                    <div class="card-tools">
                        {{-- The tax periods line up with the bills; the rest are rolling windows ending today --}}
                        @php
                            $activePeriod = $period ?? request('period', 'period');
                            $periodButtons = [
                                'period' => trans('mining-manager::ledger.this_period'),
                                'last_period' => trans('mining-manager::ledger.last_period'),
                                'week' => trans('mining-manager::ledger.last_7_days'),
                                'month' => trans('mining-manager::ledger.last_30_days'),
                                'year' => trans('mining-manager::ledger.last_12_months'),
                                'all' => trans('mining-manager::ledger.all_time'),
                            ];
                        @endphp
                        <div class="btn-group">
                            @foreach($periodButtons as $periodKey => $periodName)
                                <button type="button" class="btn btn-sm {{ $activePeriod === $periodKey ? 'btn-primary' : 'btn-outline-secondary' }}" onclick="changePeriod('{{ $periodKey }}')">{{ $periodName }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Total Value Mined --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-primary">
                                <div class="inner">
                                    <h3>{{ number_format($stats['total_value'] ?? 0, 0) }}</h3>
                                    <p>{{ trans('mining-manager::ledger.total_value_mined') }} (ISK)</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-coins"></i>
                                </div>
                            </div>
                        </div>

                        {{-- Total Quantity --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-success">
                                <div class="inner">
                                    <h3>{{ number_format($stats['total_quantity'] ?? 0, 0) }}</h3>
                                    <p>{{ trans('mining-manager::ledger.units_mined') }}</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-cubes"></i>
                                </div>
                            </div>
                        </div>

                        {{-- Total Volume --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-info">
                                <div class="inner">
                                    <h3>{{ number_format($stats['total_volume'] ?? 0, 0) }}</h3>
                                    <p>{{ trans('mining-manager::ledger.total_volume') }} (m&sup3;)</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-cube"></i>
                                </div>
                            </div>
                        </div>

                        {{-- Active Days --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-warning">
                                <div class="inner">
                                    <h3>{{ $stats['active_days'] ?? 0 }}</h3>
                                    <p>{{ trans('mining-manager::ledger.active_days') }}</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Additional Stats Row --}}
                    <div class="row">
                        <div class="col-md-3">
                            <div class="info-box bg-dark">
                                <span class="info-box-icon bg-gradient-primary"><i class="fas fa-gem"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.favorite_ore') }}</span>
                                    <span class="info-box-number">{{ $stats['favorite_ore']['name'] ?? 'N/A' }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-dark">
                                <span class="info-box-icon bg-gradient-success"><i class="fas fa-trophy"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.best_day') }}</span>
                                    <span class="info-box-number">{{ number_format($stats['best_day_value'] ?? 0, 0) }} ISK</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-dark">
                                <span class="info-box-icon bg-gradient-info"><i class="fas fa-chart-line"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.daily_average') }}</span>
                                    <span class="info-box-number">{{ number_format($stats['daily_average'] ?? 0, 0) }} ISK</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box bg-dark">
                                <span class="info-box-icon bg-gradient-warning"><i class="fas fa-star"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::ledger.corp_rank') }}</span>
                                    <span class="info-box-number">#{{ $stats['corp_rank'] ?? '-' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- TAX STATUS ROW: the estimate is the mining shown, paid and
                         left to pay are the bills covering those days, and the
                         last tile is what to pay next whatever the period. --}}
                    @php
                        $billsShown = $stats['bills_covering'] ?? 0;
                        $nextPayment = $stats['next_payment'] ?? ['overdue_count' => 0, 'overdue_amount' => 0, 'next' => null];
                    @endphp
                    <div class="row mt-3">
                        {{-- Estimated Tax, for the period picked above --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-danger">
                                <div class="inner">
                                    <h3>{{ number_format($stats['estimated_tax_this_month'] ?? 0, 0) }}</h3>
                                    <p>{{ trans('mining-manager::ledger.estimated_tax_this_month') }} (ISK)</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                </div>
                            </div>
                        </div>

                        {{-- Tax Already Paid --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box bg-gradient-success">
                                <div class="inner">
                                    <h3>{{ number_format($stats['total_tax_paid'] ?? 0, 0) }}</h3>
                                    <p>
                                        {{ trans('mining-manager::ledger.tax_paid') }} (ISK)
                                        @if($billsShown > 0)
                                            <br><small>{{ trans('mining-manager::ledger.of_billed', ['amount' => number_format($stats['total_tax_billed'] ?? 0, 0)]) }}</small>
                                        @endif
                                    </p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                        </div>

                        {{-- Outstanding Balance --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="small-box {{ ($stats['tax_outstanding'] ?? 0) > 0 ? 'bg-gradient-warning' : 'bg-gradient-success' }}">
                                <div class="inner">
                                    @if($billsShown > 0)
                                        <h3>{{ number_format($stats['tax_outstanding'] ?? 0, 0) }}</h3>
                                        <p>
                                            {{ trans('mining-manager::ledger.tax_outstanding') }} (ISK)
                                            <br><small>{{ trans_choice('mining-manager::ledger.bills_covering', $billsShown, ['count' => $billsShown]) }}</small>
                                        </p>
                                    @elseif(($stats['estimated_tax_this_month'] ?? 0) > 0 && $billedOn && $billedOn->isFuture())
                                        <h3>{{ trans('mining-manager::ledger.not_billed_yet') }}</h3>
                                        <p>{{ trans('mining-manager::ledger.bills_go_out', ['date' => $billedOn->format('M j')]) }}</p>
                                    @else
                                        <h3>0</h3>
                                        <p>{{ trans('mining-manager::ledger.no_bill_for_days') }}</p>
                                    @endif
                                </div>
                                <div class="icon">
                                    <i class="fas fa-balance-scale"></i>
                                </div>
                            </div>
                        </div>

                        {{-- What to pay next, whatever the period above: overdue first --}}
                        <div class="col-lg-3 col-md-6">
                            @if($nextPayment['overdue_count'] > 0)
                                <div class="small-box bg-gradient-danger">
                                    <div class="inner">
                                        <h3>{{ number_format($nextPayment['overdue_amount'], 0) }}</h3>
                                        <p>{{ trans_choice('mining-manager::ledger.overdue_bills', $nextPayment['overdue_count'], ['count' => $nextPayment['overdue_count']]) }}</p>
                                    </div>
                                    <div class="icon">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <a href="{{ route('mining-manager.taxes.my-taxes') }}" class="small-box-footer">
                                        {{ trans('mining-manager::ledger.view_my_taxes') }} <i class="fas fa-arrow-circle-right"></i>
                                    </a>
                                </div>
                            @elseif($nextPayment['next'])
                                <div class="small-box bg-gradient-info">
                                    <div class="inner">
                                        <h3>{{ number_format($nextPayment['next']->getRemainingBalance(), 0) }}</h3>
                                        <p>{{ trans('mining-manager::ledger.next_payment_due', ['date' => $nextPayment['next']->effectiveDueDate()->format('M j'), 'countdown' => $nextPayment['next']->dueCountdown()]) }}</p>
                                    </div>
                                    <div class="icon">
                                        <i class="fas fa-calendar-day"></i>
                                    </div>
                                    <a href="{{ route('mining-manager.taxes.my-taxes') }}" class="small-box-footer">
                                        {{ trans('mining-manager::ledger.view_my_taxes') }} <i class="fas fa-arrow-circle-right"></i>
                                    </a>
                                </div>
                            @else
                                <div class="small-box bg-gradient-success">
                                    <div class="inner">
                                        <h3>0</h3>
                                        <p>{{ trans('mining-manager::ledger.nothing_to_pay') }}</p>
                                    </div>
                                    <div class="icon">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- EVENT DISCOUNT CALLOUT — shown only if any event waived tax this month OR all-time --}}
                    @if(($stats['event_discount_total'] ?? 0) > 0 || ($stats['event_savings_all_time'] ?? 0) > 0)
                    <div class="row mt-2">
                        @if(($stats['event_discount_total'] ?? 0) > 0)
                        <div class="col-md-8">
                            <div class="mm-notice mm-notice-success">
                                <h5><i class="fas fa-gift text-warning"></i> Event Discount Applied</h5>
                                <p class="mb-0">
                                    You saved <strong>{{ number_format($stats['event_discount_total'], 0) }} ISK</strong>
                                    in taxes this period thanks to active mining events.
                                    <small class="text-muted d-block mt-1">
                                        The discount is already applied to your "Estimated Tax This Month" figure above.
                                        <a href="{{ route('mining-manager.events.my-events') }}">View your events</a>
                                        to see which ones contributed.
                                    </small>
                                </p>
                            </div>
                        </div>
                        @endif

                        {{-- All-time event savings card — shown whenever user has ever saved anything --}}
                        @if(($stats['event_savings_all_time'] ?? 0) > 0)
                        <div class="col-md-4">
                            <div class="small-box bg-gradient-warning">
                                <div class="inner">
                                    <h3>{{ number_format($stats['event_savings_all_time'], 0) }}</h3>
                                    <p>Total Event Savings (All Time)<br><small>ISK saved from event participation</small></p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-piggy-bank"></i>
                                </div>
                                <a href="{{ route('mining-manager.events.my-events') }}" class="small-box-footer">
                                    View events <i class="fas fa-arrow-circle-right"></i>
                                </a>
                            </div>
                        </div>
                        @endif
                    </div>
                    @endif

                    {{-- TAX TRANSPARENCY NOTICE --}}
                    @if(($stats['estimated_tax_this_month'] ?? 0) > 0)
                    <div class="row mt-2">
                        <div class="col-12">
                            <div class="mm-notice">
                                <h5><i class="fas fa-info-circle"></i> {{ trans('mining-manager::ledger.about_estimated_tax') }}</h5>
                                <p class="mb-0">{{ trans('mining-manager::ledger.estimated_tax_notice') }}</p>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- CHARTS --}}
    <div class="row">
        {{-- Mining Trend Chart --}}
        <div class="col-md-8">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i>
                        {{ trans('mining-manager::ledger.mining_trend') }}
                    </h3>
                    <div class="card-tools">
                        <div class="btn-group btn-group-sm" role="group" id="trendModeSwitch">
                            <button type="button" class="btn btn-primary" data-mode="total">{{ trans('mining-manager::ledger.trend_total') }}</button>
                            <button type="button" class="btn btn-outline-secondary" data-mode="type">{{ trans('mining-manager::ledger.trend_by_type') }}</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="miningTrendChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::ledger.note_mining_trend') }}</small>
                </div>
            </div>
        </div>

        {{-- Ore Distribution Chart --}}
        <div class="col-md-4">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::ledger.ore_distribution') }}
                    </h3>
                    <div class="card-tools">
                        <div class="btn-group btn-group-sm" role="group" id="distributionModeSwitch">
                            <button type="button" class="btn btn-primary" data-mode="ore">{{ trans('mining-manager::ledger.distribution_by_ore') }}</button>
                            <button type="button" class="btn btn-outline-secondary" data-mode="type">{{ trans('mining-manager::ledger.trend_by_type') }}</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 300px;">
                        <canvas id="oreDistributionChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::ledger.note_ore_distribution') }}</small>
                </div>
            </div>
        </div>
    </div>

    {{-- CHARACTER BREAKDOWN --}}
    @if(count($characterStats ?? []) > 1)
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i>
                        {{ trans('mining-manager::ledger.character_breakdown') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::ledger.character') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.total_value') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.volume') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.sessions') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.percentage') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($characterStats as $charStat)
                                <tr>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $charStat['character_id'] }}/portrait?size=32" 
                                             class="img-circle" 
                                             style="width: 32px; height: 32px;">
                                        {{ $charStat['name'] }}
                                    </td>
                                    <td class="text-right">{{ number_format($charStat['total_value'], 0) }} ISK</td>
                                    <td class="text-right">{{ number_format($charStat['quantity'], 0) }}</td>
                                    <td class="text-right">{{ number_format($charStat['total_volume_m3'] ?? 0, 0) }} m³</td>
                                    <td class="text-right">{{ $charStat['sessions'] }}</td>
                                    <td class="text-right">{{ number_format($charStat['percentage'], 1) }}%</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- RECENT ACTIVITY --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-clock"></i>
                        {{ trans('mining-manager::ledger.recent_activity') }}
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('mining-manager.ledger.index', ['character_id' => auth()->user()->main_character_id]) }}" class="btn btn-sm btn-info">
                            <i class="fas fa-list"></i> {{ trans('mining-manager::ledger.view_all') }}
                        </a>
                        <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                            <i class="fas fa-print"></i> {{ trans('mining-manager::ledger.print') }}
                        </button>
                        @php $features = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags(); @endphp
                        @if($features['allow_export_data'] ?? true)
                        <button type="button" class="btn btn-sm btn-success" onclick="exportPersonalData()">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::ledger.export') }}
                        </button>
                        @endif
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>{{ trans('mining-manager::ledger.date') }}</th>
                                    <th>{{ trans('mining-manager::ledger.character') }}</th>
                                    <th>{{ trans('mining-manager::ledger.ore_type') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.quantity') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.volume') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::ledger.value') }}</th>
                                    <th>{{ trans('mining-manager::ledger.system') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentActivity ?? [] as $activity)
                                <tr>
                                    <td>
                                        <small>{{ $activity->date->format('Y-m-d H:i') }}</small>
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $activity->character_id }}/portrait?size=32" 
                                             class="img-circle" 
                                             style="width: 32px; height: 32px;">
                                        {{ $activity->character_name }}
                                    </td>
                                    <td>
                                        <img src="https://images.evetech.net/types/{{ $activity->type_id }}/icon?size=32" 
                                             style="width: 32px; height: 32px;">
                                        {{ $activity->type->typeName ?? 'Unknown' }}
                                        @if($activity->is_moon_ore)
                                            <span class="badge badge-warning" title="Moon Ore">
                                                <i class="fas fa-moon"></i>
                                            </span>
                                        @elseif($activity->is_ice)
                                            <span class="badge badge-info" title="Ice">
                                                <i class="fas fa-snowflake"></i>
                                            </span>
                                        @elseif($activity->is_gas)
                                            <span class="badge badge-success" title="Gas">
                                                <i class="fas fa-cloud"></i>
                                            </span>
                                        @elseif($activity->is_abyssal)
                                            <span class="badge badge-danger" title="Abyssal">
                                                <i class="fas fa-fire"></i>
                                            </span>
                                        @elseif($activity->is_triglavian)
                                            <span class="badge badge-dark" title="Triglavian">
                                                <i class="fas fa-bolt"></i>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($activity->quantity, 0) }}</td>
                                    <td class="text-right">{{ number_format($activity->quantity * ($activity->type->volume ?? 0), 2) }} m³</td>
                                    <td class="text-right">
                                        <strong>{{ number_format($activity->total_value, 0) }}</strong>
                                        <small class="text-muted">ISK</small>
                                    </td>
                                    <td><small>{{ $activity->solarSystem->name ?? 'Unknown' }}</small></td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 mt-3"></i>
                                        <p>{{ trans('mining-manager::ledger.no_recent_activity') }}</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- MONTHLY COMPARISON --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        {{ trans('mining-manager::ledger.monthly_comparison') }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="position: relative; height: 250px;">
                        <canvas id="monthlyComparisonChart"></canvas>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> {{ trans('mining-manager::ledger.note_monthly_comparison') }}</small>
                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
// Change period function (preserves character filter if set)
function changePeriod(period) {
    var url = '{{ route("mining-manager.ledger.my-mining") }}?period=' + period;
    @if(request('character_id'))
    url += '&character_id={{ request('character_id') }}';
    @endif
    window.location.href = url;
}

// Export personal data
function exportPersonalData() {
    window.location.href = '{{ route("mining-manager.ledger.export-personal") }}';
}

// Mining Trend Chart: the value of each day, or each day split by kind of
// ore, stacked. The switch is remembered in this browser.
const trendCtx = document.getElementById('miningTrendChart').getContext('2d');
const trendLabels = @json($trendData['labels'] ?? []);
const trendTotal = (@json($trendData['values'] ?? [])).map(Number);
const trendByType = @json($trendData['by_type'] ?? []);
const trendTypes = [
    { key: 'moon', label: @json(trans('mining-manager::ledger.moon_ore')), rgb: '255, 0, 132' },
    { key: 'ore', label: @json(trans('mining-manager::ledger.regular_ore')), rgb: '161, 198, 60' },
    { key: 'ice', label: @json(trans('mining-manager::ledger.ice')), rgb: '0, 210, 255' },
    { key: 'gas', label: @json(trans('mining-manager::ledger.gas')), rgb: '255, 159, 64' },
    { key: 'abyssal', label: @json(trans('mining-manager::ledger.abyssal')), rgb: '255, 205, 86' },
    { key: 'triglavian', label: @json(trans('mining-manager::ledger.triglavian')), rgb: '220, 53, 69' }
];

function trendConfig(mode) {
    const byType = mode === 'type';

    // Kinds of ore never mined in the period stay out of the legend.
    const datasets = byType
        ? trendTypes
            .map(function (type) {
                return { type: type, values: (trendByType[type.key] || []).map(Number) };
            })
            .filter(function (series) {
                return series.values.some(function (value) { return value > 0; });
            })
            .map(function (series) {
                return {
                    label: series.type.label,
                    data: series.values,
                    backgroundColor: 'rgba(' + series.type.rgb + ', 0.75)',
                    borderColor: 'rgba(' + series.type.rgb + ', 1)',
                    borderWidth: 1
                };
            })
        : [{
            label: @json(trans('mining-manager::ledger.daily_value')),
            data: trendTotal,
            borderColor: 'rgba(0, 210, 255, 1)',
            backgroundColor: 'rgba(0, 210, 255, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }];

    return {
        type: byType ? 'bar' : 'line',
        data: { labels: trendLabels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    labels: { color: '#fff' }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + ' ISK';
                        },
                        footer: function(items) {
                            if (!byType || items.length < 2) {
                                return '';
                            }
                            const total = items.reduce(function (sum, item) { return sum + item.parsed.y; }, 0);
                            return @json(trans('mining-manager::ledger.trend_total')) + ': ' + total.toLocaleString() + ' ISK';
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: byType,
                    ticks: { color: '#fff' },
                    grid: { color: '#444' }
                },
                y: {
                    stacked: byType,
                    beginAtZero: true,
                    ticks: {
                        color: '#fff',
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    },
                    grid: { color: '#444' }
                }
            }
        }
    };
}

// Ore Distribution Chart: the top ores by value, or the same mining by kind
// of ore, from the same figures as the trend chart's By type.
const distributionCtx = document.getElementById('oreDistributionChart').getContext('2d');
const distributionOreLabels = @json($oreDistribution['labels'] ?? []);
const distributionOreValues = (@json($oreDistribution['values'] ?? [])).map(Number);
const distributionOreColors = [
    'rgba(0, 210, 255, 0.8)',
    'rgba(255, 0, 132, 0.8)',
    'rgba(161, 198, 60, 0.8)',
    'rgba(255, 159, 64, 0.8)',
    'rgba(255, 205, 86, 0.8)',
    'rgba(75, 192, 192, 0.8)'
];

function distributionConfig(mode) {
    // Everything past the top ten is one grey slice, set apart from the ores.
    const otherOres = @json(trans('mining-manager::ledger.other_ores'));
    let labels = distributionOreLabels;
    let values = distributionOreValues;
    let colors = distributionOreLabels.map(function (label, index) {
        return label === otherOres ? 'rgba(108, 117, 125, 0.8)' : distributionOreColors[index % distributionOreColors.length];
    });

    if (mode === 'type') {
        const kinds = trendTypes
            .map(function (type) {
                const total = (trendByType[type.key] || []).reduce(function (sum, value) { return sum + Number(value); }, 0);
                return { type: type, total: total };
            })
            .filter(function (kind) { return kind.total > 0; });

        labels = kinds.map(function (kind) { return kind.type.label; });
        values = kinds.map(function (kind) { return kind.total; });
        colors = kinds.map(function (kind) { return 'rgba(' + kind.type.rgb + ', 0.8)'; });
    }

    return {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{ data: values, backgroundColor: colors }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: '#fff' }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed;
                            const total = context.dataset.data.reduce(function (sum, item) { return sum + item; }, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                            return context.label + ': ' + value.toLocaleString() + ' ISK (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    };
}

// A two-way switch over a chart: marks the button picked, rebuilds the chart
// that way and remembers the choice in this browser.
function chartSwitch(selector, storageKey, defaultMode, build) {
    const buttons = document.querySelectorAll(selector + ' [data-mode]');
    const modes = Array.prototype.map.call(buttons, function (button) { return button.dataset.mode; });
    let mode = defaultMode;
    try {
        const saved = localStorage.getItem(storageKey);
        if (modes.indexOf(saved) !== -1) {
            mode = saved;
        }
    } catch (e) {}

    let chart = build(mode);
    const mark = function () {
        buttons.forEach(function (button) {
            const active = button.dataset.mode === mode;
            button.classList.toggle('btn-primary', active);
            button.classList.toggle('btn-outline-secondary', !active);
        });
    };
    mark();

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.dataset.mode === mode) {
                return;
            }
            mode = button.dataset.mode;
            chart.destroy();
            chart = build(mode);
            mark();
            try {
                localStorage.setItem(storageKey, mode);
            } catch (e) {}
        });
    });
}

chartSwitch('#trendModeSwitch', 'mm.myMining.trendMode', 'total', function (mode) {
    return new Chart(trendCtx, trendConfig(mode));
});
chartSwitch('#distributionModeSwitch', 'mm.myMining.distributionMode', 'ore', function (mode) {
    return new Chart(distributionCtx, distributionConfig(mode));
});

// Monthly Comparison Chart
const comparisonCtx = document.getElementById('monthlyComparisonChart').getContext('2d');
const comparisonChart = new Chart(comparisonCtx, {
    type: 'bar',
    data: {
        labels: @json($monthlyComparison['labels'] ?? []),
        datasets: [{
            label: '{{ trans("mining-manager::ledger.monthly_value") }}',
            data: @json($monthlyComparison['values'] ?? []),
            backgroundColor: 'rgba(0, 210, 255, 0.6)',
            borderColor: 'rgba(0, 210, 255, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { 
                display: false
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.parsed.y.toLocaleString() + ' ISK';
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: { color: '#fff' },
                grid: { color: '#444' }
            },
            y: {
                beginAtZero: true,
                ticks: { 
                    color: '#fff',
                    callback: function(value) {
                        return value.toLocaleString();
                    }
                },
                grid: { color: '#444' }
            }
        }
    }
});

// Print styles
window.onbeforeprint = function() {
    $('.card-tools').hide();
    $('.btn').hide();
};

window.onafterprint = function() {
    $('.card-tools').show();
    $('.btn').show();
};
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}
</div>{{-- /.mining-dashboard --}}

@endsection
