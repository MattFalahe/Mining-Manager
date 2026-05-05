@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.tax_overview'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/tax-management.css') }}">
@include('mining-manager::taxes.partials.datatables-styles')
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard taxes-index-page">

@include('mining-manager::taxes.partials.tab-navigation')

<div class="tax-management">
    
    {{-- SUMMARY STATISTICS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        {{ trans('mining-manager::taxes.tax_summary') }}
                        @if(($periodType ?? 'monthly') !== 'monthly' && isset($currentPeriodLabel))
                            <small class="text-muted ml-2">
                                <i class="fas fa-calendar-alt"></i>
                                Current {{ ucfirst($periodType) }} period: {{ $currentPeriodLabel }}
                            </small>
                        @else
                            <small class="text-muted ml-2">{{ now()->format('F Y') }}</small>
                        @endif
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-sm btn-primary" id="refreshStats">
                            <i class="fas fa-sync-alt"></i> {{ trans('mining-manager::taxes.refresh') }}
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        {{-- Total Owed --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-warning">
                                <span class="info-box-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.total_owed') }}</span>
                                    <span class="info-box-number">{{ number_format($summary['total_owed'], 0) }}</span>
                                    <small>ISK ({{ $summary['unpaid_count'] }} {{ trans('mining-manager::taxes.members') }})</small>
                                </div>
                            </div>
                        </div>

                        {{-- Overdue --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-danger">
                                <span class="info-box-icon">
                                    <i class="fas fa-clock"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.overdue') }}</span>
                                    <span class="info-box-number">{{ number_format($summary['overdue_amount'], 0) }}</span>
                                    <small>ISK ({{ $summary['overdue_count'] }} {{ trans('mining-manager::taxes.members') }})</small>
                                </div>
                            </div>
                        </div>

                        {{-- Collected This Calendar Month --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-success">
                                <span class="info-box-icon">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">
                                        {{ trans('mining-manager::taxes.collected') }}
                                        <small class="text-white-50">&mdash; {{ now()->format('F') }}</small>
                                    </span>
                                    <span class="info-box-number">{{ number_format($summary['collected'], 0) }}</span>
                                    <small>ISK ({{ $summary['paid_count'] }} {{ trans('mining-manager::taxes.payments') }})</small>
                                    @if(isset($collectedThisPeriod) && ($periodType ?? 'monthly') !== 'monthly')
                                        <small class="d-block text-white-75 mt-1" title="Payments tagged to the current {{ $periodType }} period (period_start match)">
                                            <i class="fas fa-calendar-check"></i>
                                            {{ number_format($collectedThisPeriod, 0) }} ISK for {{ $currentPeriodLabel }}
                                        </small>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Collection Rate --}}
                        <div class="col-lg-3 col-md-6">
                            <div class="info-box bg-gradient-info">
                                <span class="info-box-icon">
                                    <i class="fas fa-percentage"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">{{ trans('mining-manager::taxes.collection_rate') }}</span>
                                    <span class="info-box-number">{{ number_format($summary['collection_rate'], 1) }}%</span>
                                    <small>{{ trans('mining-manager::taxes.this_month') }} ({{ now()->format('F') }})</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Corp vs Guest Breakdown (if moon owner corp is configured) --}}
                    @if($moonOwnerCorpId && isset($summary['corp_members']) && isset($summary['guest_miners']))
                    <hr>
                    <div class="row mt-3">
                        <div class="col-12">
                            <h5><i class="fas fa-users"></i> {{ trans('mining-manager::taxes.miner_type_breakdown') }}</h5>
                        </div>

                        {{-- Corp Members --}}
                        <div class="col-lg-6">
                            <div class="card bg-dark">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-building text-primary"></i> {{ trans('mining-manager::taxes.corp_members') }}
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-4 text-center">
                                            <div class="text-warning">
                                                <strong style="font-size: 1.5rem;">{{ number_format($summary['corp_members']['owed'], 0) }}</strong>
                                                <br><small>{{ trans('mining-manager::taxes.isk_owed') }}</small>
                                            </div>
                                        </div>
                                        <div class="col-4 text-center">
                                            <div class="text-info">
                                                <strong style="font-size: 1.5rem;">{{ $summary['corp_members']['count'] }}</strong>
                                                <br><small>{{ trans('mining-manager::taxes.active_miners') }}</small>
                                            </div>
                                        </div>
                                        <div class="col-4 text-center">
                                            <div class="text-success">
                                                <strong style="font-size: 1.5rem;">{{ number_format($summary['corp_members']['collected'], 0) }}</strong>
                                                <br><small>{{ trans('mining-manager::taxes.isk_paid') }}</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Guest Miners --}}
                        <div class="col-lg-6">
                            <div class="card bg-dark">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-user-friends text-secondary"></i> {{ trans('mining-manager::taxes.guest_miners') }}
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-4 text-center">
                                            <div class="text-warning">
                                                <strong style="font-size: 1.5rem;">{{ number_format($summary['guest_miners']['owed'], 0) }}</strong>
                                                <br><small>{{ trans('mining-manager::taxes.isk_owed') }}</small>
                                            </div>
                                        </div>
                                        <div class="col-4 text-center">
                                            <div class="text-info">
                                                <strong style="font-size: 1.5rem;">{{ $summary['guest_miners']['count'] }}</strong>
                                                <br><small>{{ trans('mining-manager::taxes.active_miners') }}</small>
                                            </div>
                                        </div>
                                        <div class="col-4 text-center">
                                            <div class="text-success">
                                                <strong style="font-size: 1.5rem;">{{ number_format($summary['guest_miners']['collected'], 0) }}</strong>
                                                <br><small>{{ trans('mining-manager::taxes.isk_paid') }}</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- FILTERS AND ACTIONS --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-filter"></i>
                        {{ trans('mining-manager::taxes.filters') }}
                    </h3>
                    <div class="card-tools">
                        @php $features = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags(); @endphp
                        @if($features['allow_export_data'] ?? true)
                        <button type="button" class="btn btn-sm btn-info" id="exportTaxes">
                            <i class="fas fa-download"></i> {{ trans('mining-manager::taxes.export') }}
                        </button>
                        @endif
                        @if($isAdmin ?? false)
                        <button type="button" class="btn btn-sm btn-warning" id="remindAllUnpaid" data-toggle="tooltip" title="Send reminders to all unpaid/overdue taxes">
                            <i class="fas fa-bullhorn"></i> Remind All Unpaid
                        </button>
                        <button type="button" class="btn btn-sm btn-success" id="sendReminders" data-toggle="tooltip" title="{{ trans('mining-manager::taxes.send_reminders_to_selected') }}">
                            <i class="fas fa-envelope"></i> {{ trans('mining-manager::taxes.send_reminders') }}
                        </button>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <form id="filterForm">
                        <div class="row">
                            {{-- Status Filter --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="statusFilter">{{ trans('mining-manager::taxes.status') }}</label>
                                    <select class="form-control" id="statusFilter" name="status">
                                        <option value="">{{ trans('mining-manager::taxes.all_statuses') }}</option>
                                        <option value="unpaid" {{ request('status') == 'unpaid' ? 'selected' : '' }}>{{ trans('mining-manager::taxes.unpaid') }}</option>
                                        <option value="paid" {{ request('status') == 'paid' ? 'selected' : '' }}>{{ trans('mining-manager::taxes.paid') }}</option>
                                        <option value="overdue" {{ request('status') == 'overdue' ? 'selected' : '' }}>{{ trans('mining-manager::taxes.overdue') }}</option>
                                        <option value="partial" {{ request('status') == 'partial' ? 'selected' : '' }}>{{ trans('mining-manager::taxes.partial') }}</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Miner Type Filter (Corp/Guest) - only when viewing all --}}
                            @if(($viewAll ?? false) && $moonOwnerCorpId)
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="minerTypeFilter">
                                        <i class="fas fa-users"></i> Miner Type
                                    </label>
                                    <select class="form-control" id="minerTypeFilter" name="miner_type">
                                        <option value="all" {{ request('miner_type', 'all') == 'all' ? 'selected' : '' }}>All Miners</option>
                                        <option value="corp" {{ request('miner_type') == 'corp' ? 'selected' : '' }}>
                                            <i class="fas fa-building"></i> Corp Members
                                        </option>
                                        <option value="guest" {{ request('miner_type') == 'guest' ? 'selected' : '' }}>
                                            <i class="fas fa-user-friends"></i> Guest Miners
                                        </option>
                                    </select>
                                </div>
                            </div>
                            @endif

                            {{-- Month Filter --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="monthFilter">
                                        {{ trans('mining-manager::taxes.month') }}
                                        @if(($periodType ?? 'monthly') !== 'monthly')
                                            <small class="text-muted" title="Filtering by calendar month returns every {{ $periodType }} period that falls within the selected month.">
                                                <i class="fas fa-info-circle"></i>
                                            </small>
                                        @endif
                                    </label>
                                    <input type="month" class="form-control" id="monthFilter" name="month" value="{{ request('month', '') }}" placeholder="All Months">
                                </div>
                            </div>

                            {{-- Corporation Filter - only when viewing all --}}
                            @if($viewAll ?? false)
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="corporationFilter">{{ trans('mining-manager::taxes.corporation') }}</label>
                                    <select class="form-control" id="corporationFilter" name="corporation_id">
                                        <option value="">{{ trans('mining-manager::taxes.all_corporations') }}</option>
                                        @foreach($corporations as $corp)
                                        <option value="{{ $corp->corporation_id }}" {{ request('corporation_id') == $corp->corporation_id ? 'selected' : '' }}>
                                            [{{ $corp->ticker }}] {{ $corp->name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            @endif

                            {{-- Character Search --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="characterSearch">{{ trans('mining-manager::taxes.search_character') }}</label>
                                    <input type="text" class="form-control" id="characterSearch" name="character" placeholder="{{ trans('mining-manager::taxes.enter_character_name') }}" value="{{ request('character') }}">
                                </div>
                            </div>

                            {{-- Apply Button --}}
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="fas fa-search"></i> {{ trans('mining-manager::taxes.apply') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- TAX LIST TABLE --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        {{ trans('mining-manager::taxes.tax_entries') }}
                    </h3>
                    <div class="card-tools">
                        <div class="btn-group btn-group-sm mr-2" role="group">
                            <button type="button" class="btn btn-outline-primary active" id="view-flat-btn">
                                <i class="fas fa-list"></i> {{ trans('mining-manager::taxes.flat_view') }}
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="view-grouped-btn">
                                <i class="fas fa-layer-group"></i> {{ trans('mining-manager::taxes.grouped_by_account') }}
                            </button>
                        </div>
                        <span class="badge badge-info" id="resultCount">{{ $taxes->count() }} {{ trans('mining-manager::taxes.entries') }}</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped table-hover" id="taxTable">
                            <thead>
                                <tr>
                                    @if($isAdmin ?? false)
                                    <th style="width: 40px">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    @endif
                                    <th>{{ trans('mining-manager::taxes.character') }}</th>
                                    <th>{{ trans('mining-manager::taxes.corporation') }}</th>
                                    <th>Period</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.amount_owed') }}</th>
                                    <th class="text-right">{{ trans('mining-manager::taxes.amount_paid') }}</th>
                                    <th>{{ trans('mining-manager::taxes.status') }}</th>
                                    <th>{{ trans('mining-manager::taxes.due_date') }}</th>
                                    <th>Payment Date</th>
                                    <th>{{ trans('mining-manager::taxes.tax_code') }}</th>
                                    <th>Triggered By</th>
                                    <th class="text-center">{{ trans('mining-manager::taxes.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($taxes as $tax)
                                <tr data-tax-id="{{ $tax->id }}"
                                    data-character-id="{{ $tax->character_id }}"
                                    data-character-name="{{ $tax->character_info['name'] ?? $tax->character->name ?? 'Unknown' }}"
                                    data-amount-owed="{{ $tax->amount_owed }}"
                                    data-amount-paid="{{ $tax->amount_paid }}"
                                    data-status="{{ $tax->status }}"
                                    class="tax-row">
                                    @if($isAdmin ?? false)
                                    <td>
                                        <input type="checkbox" class="tax-checkbox" value="{{ $tax->id }}">
                                    </td>
                                    @endif
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $tax->character_id }}/portrait?size=32"
                                             class="img-circle"
                                             style="width: 32px; height: 32px;">
                                        <a href="{{ route('mining-manager.taxes.details', $tax->id) }}">
                                            {{ $tax->character_info['name'] ?? $tax->character->name ?? 'Unknown' }}
                                        </a>
                                        @if($moonOwnerCorpId)
                                            @php $taxCorpId = $tax->character_info['corporation_id'] ?? $tax->corporation_id ?? null; @endphp
                                            @if($taxCorpId == $moonOwnerCorpId)
                                                <span class="badge badge-primary ml-1" data-toggle="tooltip" title="{{ trans('mining-manager::taxes.corporation_member') }}">
                                                    <i class="fas fa-building"></i>
                                                </span>
                                            @else
                                                <span class="badge badge-secondary ml-1" data-toggle="tooltip" title="{{ trans('mining-manager::taxes.guest_miner') }}">
                                                    <i class="fas fa-user-friends"></i>
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                    <td>
                                        {{ $tax->character_info['corporation_name'] ?? $tax->affiliation->corporation_name ?? 'Unknown' }}
                                        @php $taxCorpId = $tax->character_info['corporation_id'] ?? $tax->corporation_id ?? null; @endphp
                                        @if($moonOwnerCorpId && $taxCorpId == $moonOwnerCorpId)
                                            <span class="text-primary"><i class="fas fa-star ml-1"></i></span>
                                        @endif
                                    </td>
                                    <td>{{ $tax->formatted_period ?? \Carbon\Carbon::parse($tax->month)->format('F Y') }}</td>
                                    <td class="text-right">
                                        <strong>{{ number_format($tax->amount_owed, 0) }}</strong>
                                        <small class="text-muted">ISK</small>
                                        @if(in_array($tax->status, ['unpaid', 'overdue', 'partial']))
                                        <button type="button" class="btn btn-xs btn-link p-0 ml-1" onclick="copyToClipboard('{{ round($tax->amount_owed) }}', 'ISK amount')" data-toggle="tooltip" title="Copy ISK amount">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <strong>{{ number_format($tax->amount_paid, 0) }}</strong>
                                        <small class="text-muted">ISK</small>
                                    </td>
                                    <td>
                                        @switch($tax->status)
                                            @case('paid')
                                                <span class="badge badge-success">
                                                    <i class="fas fa-check"></i> {{ trans('mining-manager::taxes.paid') }}
                                                </span>
                                                @break
                                            @case('unpaid')
                                                <span class="badge badge-warning">
                                                    <i class="fas fa-clock"></i> {{ trans('mining-manager::taxes.unpaid') }}
                                                </span>
                                                @break
                                            @case('overdue')
                                                <span class="badge badge-danger">
                                                    <i class="fas fa-exclamation-triangle"></i> {{ trans('mining-manager::taxes.overdue') }}
                                                </span>
                                                @break
                                            @case('partial')
                                                <span class="badge badge-info">
                                                    <i class="fas fa-adjust"></i> {{ trans('mining-manager::taxes.partial') }}
                                                </span>
                                                @break
                                        @endswitch
                                    </td>
                                    <td>
                                        @if($tax->due_date)
                                            @php
                                                $dueDate = \Carbon\Carbon::parse($tax->due_date);
                                                $isOverdue = $dueDate->isPast() && $tax->status !== 'paid';
                                            @endphp
                                            <span class="{{ $isOverdue ? 'text-danger' : '' }}">
                                                {{ $dueDate->format('Y-m-d') }}
                                                @if($isOverdue)
                                                    <br><small>({{ $dueDate->diffForHumans() }})</small>
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($tax->paid_at)
                                            {{ \Carbon\Carbon::parse($tax->paid_at)->format('Y-m-d H:i') }}
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $taxCode = $tax->taxCodes->where('status', 'active')->first()
                                                     ?? $tax->taxCodes->where('status', 'used')->first();
                                        @endphp
                                        @if($taxCode)
                                            <code>{{ $taxCode->getFullCode() }}</code>
                                            <button type="button" class="btn btn-xs btn-link p-0 ml-1" onclick="copyToClipboard('{{ $taxCode->getFullCode() }}', 'Tax code')" data-toggle="tooltip" title="Copy tax code">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($tax->triggered_by)
                                            <small class="text-muted" data-toggle="tooltip" title="{{ $tax->triggered_by }}">
                                                @if(str_starts_with($tax->triggered_by, 'Scheduled'))
                                                    <i class="fas fa-clock"></i> Scheduled
                                                @elseif(str_starts_with($tax->triggered_by, 'Manual Entry:'))
                                                    <i class="fas fa-hand-holding-usd"></i> {{ str_replace('Manual Entry: ', '', $tax->triggered_by) }}
                                                @elseif(str_starts_with($tax->triggered_by, 'Manual:'))
                                                    <i class="fas fa-user"></i> {{ str_replace('Manual: ', '', $tax->triggered_by) }}
                                                @elseif(str_starts_with($tax->triggered_by, 'Regenerate:'))
                                                    <i class="fas fa-sync"></i> {{ str_replace('Regenerate: ', '', $tax->triggered_by) }}
                                                @else
                                                    {{ $tax->triggered_by }}
                                                @endif
                                            </small>
                                        @else
                                            <small class="text-muted">-</small>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <a href="{{ route('mining-manager.taxes.details', $tax->id) }}"
                                               class="btn btn-sm btn-info"
                                               data-toggle="tooltip"
                                               title="{{ trans('mining-manager::taxes.view_details') }}">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            @if(($isAdmin ?? false) && $tax->status !== 'paid')
                                            <button type="button"
                                                    class="btn btn-sm btn-success mark-paid"
                                                    data-tax-id="{{ $tax->id }}"
                                                    data-toggle="tooltip"
                                                    title="{{ trans('mining-manager::taxes.mark_as_paid') }}">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button"
                                                    class="btn btn-sm btn-warning send-reminder"
                                                    data-tax-id="{{ $tax->id }}"
                                                    data-character-name="{{ $tax->character_info['name'] ?? $tax->character->name ?? 'Unknown' }}"
                                                    data-toggle="tooltip"
                                                    title="{{ trans('mining-manager::taxes.send_reminder') }}">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                            <button type="button"
                                                    class="btn btn-sm btn-danger delete-tax"
                                                    data-tax-id="{{ $tax->id }}"
                                                    data-character-name="{{ $tax->character_info['name'] ?? $tax->character->name ?? 'Unknown' }}"
                                                    data-month="{{ $tax->formatted_period ?? \Carbon\Carbon::parse($tax->month)->format('F Y') }}"
                                                    data-toggle="tooltip"
                                                    title="Delete tax record">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="{{ ($isAdmin ?? false) ? 11 : 10 }}" class="text-center text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 mt-3"></i>
                                        <p>{{ trans('mining-manager::taxes.no_taxes_found') }}</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                {{-- Pagination handled by DataTables --}}
            </div>
        </div>
    </div>

    {{-- BULK ACTIONS SUMMARY --}}
    <div class="row" id="bulkActionsBar" style="display: none;">
        <div class="col-12">
            <div class="card card-primary">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <strong><span id="selectedCount">0</span> {{ trans('mining-manager::taxes.items_selected') }}</strong>
                        </div>
                        <div class="col-md-6 text-right">
                            <button type="button" class="btn btn-success" id="bulkMarkPaid">
                                <i class="fas fa-check"></i> {{ trans('mining-manager::taxes.mark_as_paid') }}
                            </button>
                            <button type="button" class="btn btn-warning" id="bulkSendReminders">
                                <i class="fas fa-envelope"></i> {{ trans('mining-manager::taxes.send_reminders') }}
                            </button>
                            <button type="button" class="btn btn-danger" id="bulkDelete">
                                <i class="fas fa-trash"></i> Delete Selected
                            </button>
                            <button type="button" class="btn btn-secondary" id="clearSelection">
                                <i class="fas fa-times"></i> {{ trans('mining-manager::taxes.clear') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- MARK AS PAID MODAL --}}
<div class="modal fade" id="markPaidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('mining-manager::taxes.mark_as_paid') }}</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="markPaidForm">
                    <input type="hidden" id="taxIdInput" name="tax_id">
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.amount_paid') }}</label>
                        <input type="number" class="form-control" id="amountPaidInput" name="amount_paid" required>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.payment_date') }}</label>
                        <input type="date" class="form-control" id="paymentDateInput" name="payment_date" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('mining-manager::taxes.notes') }}</label>
                        <textarea class="form-control" id="notesInput" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    {{ trans('mining-manager::taxes.cancel') }}
                </button>
                <button type="button" class="btn btn-success" id="confirmMarkPaid">
                    <i class="fas fa-check"></i> {{ trans('mining-manager::taxes.confirm') }}
                </button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/jquery.dataTables.min.js') }}"></script>
<script>
// Copy to clipboard helper
function copyToClipboard(text, label) {
    navigator.clipboard.writeText(text).then(function() {
        toastr.success((label || 'Value') + ' copied: ' + text);
    }, function(err) {
        toastr.error('Failed to copy to clipboard');
    });
}

$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Initialize DataTables
    if ($('#taxTable tbody tr').length > 0 && !$('#taxTable tbody tr td[colspan]').length) {
        $('#taxTable').DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            order: [[{{ ($isAdmin ?? false) ? 4 : 3 }}, 'desc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries found",
                infoFiltered: "(filtered from _MAX_ total entries)",
                paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
            },
            columnDefs: [
                @if($isAdmin ?? false)
                { orderable: false, targets: [0, 9] },
                @else
                { orderable: false, targets: [8] },
                @endif
            ]
        });
    }

    // Select all checkbox
    $('#selectAll').on('change', function() {
        $('.tax-checkbox').prop('checked', $(this).prop('checked'));
        updateBulkActionsBar();
    });

    // Individual checkboxes
    $('.tax-checkbox').on('change', function() {
        updateBulkActionsBar();
    });

    // Update bulk actions bar
    function updateBulkActionsBar() {
        const selectedCount = $('.tax-checkbox:checked').length;
        $('#selectedCount').text(selectedCount);
        
        if (selectedCount > 0) {
            $('#bulkActionsBar').slideDown();
        } else {
            $('#bulkActionsBar').slideUp();
        }
    }

    // Clear selection
    $('#clearSelection').on('click', function() {
        $('.tax-checkbox, #selectAll').prop('checked', false);
        updateBulkActionsBar();
    });

    // Filter form submission
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        window.location.href = '{{ route("mining-manager.taxes.index") }}?' + formData;
    });

    // Refresh stats
    $('#refreshStats').on('click', function() {
        location.reload();
    });

    // Mark as paid - single
    $('.mark-paid').on('click', function() {
        const taxId = $(this).data('tax-id');
        const row = $('tr[data-tax-id="' + taxId + '"]');
        const amountOwed = row.find('td:eq(4)').text().replace(/[^\d]/g, '');
        
        $('#taxIdInput').val(taxId);
        $('#amountPaidInput').val(amountOwed);
        $('#markPaidModal').modal('show');
    });

    // Confirm mark as paid
    $('#confirmMarkPaid').on('click', function() {
        const formData = $('#markPaidForm').serialize();
        
        $.ajax({
            url: '{{ route("mining-manager.taxes.mark-paid") }}',
            method: 'POST',
            data: formData,
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success(response.message || '{{ trans("mining-manager::taxes.marked_as_paid_success") }}');
                $('#markPaidModal').modal('hide');
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_marking_paid") }}');
            }
        });
    });

    // Send reminder - single
    $('.send-reminder').on('click', function() {
        const taxId = $(this).data('tax-id');
        const characterName = $(this).data('character-name');
        
        if (confirm('{{ trans("mining-manager::taxes.confirm_send_reminder") }} ' + characterName + '?')) {
            $.ajax({
                url: '{{ route("mining-manager.taxes.send-reminder") }}',
                method: 'POST',
                data: { tax_id: taxId },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::taxes.reminder_sent") }}');
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_sending_reminder") }}');
                }
            });
        }
    });

    // Bulk mark as paid
    $('#bulkMarkPaid').on('click', function() {
        const selectedIds = $('.tax-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (confirm('{{ trans("mining-manager::taxes.confirm_bulk_mark_paid") }} ' + selectedIds.length + ' {{ trans("mining-manager::taxes.items") }}?')) {
            $.ajax({
                url: '{{ route("mining-manager.taxes.bulk-mark-paid") }}',
                method: 'POST',
                data: { tax_ids: selectedIds },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::taxes.bulk_marked_success") }}');
                    setTimeout(() => location.reload(), 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_bulk_marking") }}');
                }
            });
        }
    });

    // Bulk send reminders
    // Remind All Unpaid — sends reminders for all unpaid/overdue taxes without checkbox selection
    $('#remindAllUnpaid').on('click', function() {
        // Collect all tax IDs with unpaid/overdue/partial status
        var unpaidIds = [];
        $('.tax-row').each(function() {
            var status = $(this).data('status');
            if (status === 'unpaid' || status === 'overdue' || status === 'partial') {
                unpaidIds.push($(this).data('tax-id'));
            }
        });

        if (unpaidIds.length === 0) {
            toastr.info('No unpaid taxes to remind about.');
            return;
        }

        if (confirm('Send payment reminders for ' + unpaidIds.length + ' unpaid tax entries?')) {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');

            $.ajax({
                url: '{{ route("mining-manager.taxes.bulk-send-reminders") }}',
                method: 'POST',
                data: { tax_ids: unpaidIds },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || 'Reminders sent!');
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || 'Failed to send reminders');
                },
                complete: function() {
                    btn.prop('disabled', false).html('<i class="fas fa-bullhorn"></i> Remind All Unpaid');
                }
            });
        }
    });

    $('#bulkSendReminders, #sendReminders').on('click', function() {
        const selectedIds = $('.tax-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (selectedIds.length === 0) {
            toastr.warning('{{ trans("mining-manager::taxes.select_items_first") }}');
            return;
        }
        
        if (confirm('{{ trans("mining-manager::taxes.confirm_bulk_reminders") }} ' + selectedIds.length + ' {{ trans("mining-manager::taxes.members") }}?')) {
            $.ajax({
                url: '{{ route("mining-manager.taxes.bulk-send-reminders") }}',
                method: 'POST',
                data: { tax_ids: selectedIds },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || '{{ trans("mining-manager::taxes.reminders_sent") }}');
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_sending_reminders") }}');
                }
            });
        }
    });

    // Delete single tax record
    $('.delete-tax').on('click', function() {
        const taxId = $(this).data('tax-id');
        const characterName = $(this).data('character-name');
        const month = $(this).data('month');

        if (confirm('Delete tax record for ' + characterName + ' (' + month + ')?\n\nThis action cannot be undone.')) {
            $.ajax({
                url: '{{ url("mining-manager/tax") }}/' + taxId,
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    toastr.success(response.message || 'Tax record deleted');
                    setTimeout(() => location.reload(), 1000);
                },
                error: function(xhr) {
                    toastr.error(xhr.responseJSON?.message || 'Cannot delete this tax record');
                }
            });
        }
    });

    // Bulk delete
    $('#bulkDelete').on('click', function() {
        const selectedIds = $('.tax-checkbox:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedIds.length === 0) {
            toastr.warning('Select items first');
            return;
        }

        if (confirm('Delete ' + selectedIds.length + ' tax record(s)?\n\nPaid records cannot be deleted. This action cannot be undone.')) {
            var deleted = 0;
            var errors = 0;
            var total = selectedIds.length;

            selectedIds.forEach(function(id) {
                $.ajax({
                    url: '{{ url("mining-manager/tax") }}/' + id,
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function() { deleted++; checkDone(); },
                    error: function() { errors++; checkDone(); }
                });
            });

            function checkDone() {
                if (deleted + errors === total) {
                    if (errors > 0) {
                        toastr.warning('Deleted ' + deleted + ' record(s). ' + errors + ' could not be deleted (paid records are protected).');
                    } else {
                        toastr.success('Deleted ' + deleted + ' record(s).');
                    }
                    setTimeout(() => location.reload(), 1000);
                }
            }
        }
    });

    // Export taxes
    $('#exportTaxes').on('click', function() {
        const formData = $('#filterForm').serialize();
        window.location.href = '{{ route("mining-manager.taxes.export") }}?' + formData;
    });

    // View toggle: Flat / Grouped by Account
    var isGrouped = false;

    $('#view-flat-btn').on('click', function() {
        if (!isGrouped) return;
        isGrouped = false;
        $(this).addClass('active');
        $('#view-grouped-btn').removeClass('active');
        showFlatView();
    });

    $('#view-grouped-btn').on('click', function() {
        if (isGrouped) return;
        isGrouped = true;
        $(this).addClass('active');
        $('#view-flat-btn').removeClass('active');
        showGroupedView();
    });

    function showFlatView() {
        // Remove grouped header rows
        $('.grouped-header-row').remove();
        // Show all original tax rows
        $('.tax-row').show();
    }

    function showGroupedView() {
        // Group rows by character_id
        var groups = {};
        $('.tax-row').each(function() {
            var charId = $(this).data('character-id');
            if (!groups[charId]) {
                groups[charId] = {
                    name: $(this).data('character-name'),
                    totalOwed: 0,
                    totalPaid: 0,
                    rows: [],
                    statuses: {}
                };
            }
            groups[charId].totalOwed += parseFloat($(this).data('amount-owed')) || 0;
            groups[charId].totalPaid += parseFloat($(this).data('amount-paid')) || 0;
            groups[charId].rows.push($(this));
            var st = $(this).data('status');
            groups[charId].statuses[st] = (groups[charId].statuses[st] || 0) + 1;
        });

        // Hide all original rows and insert grouped headers
        $('.tax-row').hide();
        $('.grouped-header-row').remove();

        var tbody = $('#taxTable tbody');
        var colSpan = {{ ($isAdmin ?? false) ? 11 : 10 }};

        // Sort groups by totalOwed descending
        var sortedKeys = Object.keys(groups).sort(function(a, b) {
            return groups[b].totalOwed - groups[a].totalOwed;
        });

        sortedKeys.forEach(function(charId) {
            var g = groups[charId];
            var statusBadge = '';
            if (g.statuses['overdue']) statusBadge += '<span class="badge badge-danger mr-1">' + g.statuses['overdue'] + ' {{ trans("mining-manager::taxes.overdue") }}</span>';
            if (g.statuses['unpaid']) statusBadge += '<span class="badge badge-warning mr-1">' + g.statuses['unpaid'] + ' {{ trans("mining-manager::taxes.unpaid") }}</span>';
            if (g.statuses['paid']) statusBadge += '<span class="badge badge-success mr-1">' + g.statuses['paid'] + ' {{ trans("mining-manager::taxes.paid") }}</span>';
            if (g.statuses['partial']) statusBadge += '<span class="badge badge-info mr-1">' + g.statuses['partial'] + ' {{ trans("mining-manager::taxes.partial") }}</span>';

            var headerRow = $('<tr class="grouped-header-row" style="cursor: pointer; background: rgba(78, 115, 223, 0.15) !important;">' +
                '<td colspan="' + colSpan + '">' +
                '<div class="d-flex justify-content-between align-items-center">' +
                '<div>' +
                '<img src="https://images.evetech.net/characters/' + charId + '/portrait?size=32" class="img-circle mr-2" style="width: 32px; height: 32px;">' +
                '<strong>' + g.name + '</strong>' +
                '<small class="text-muted ml-2">(' + g.rows.length + ' {{ trans("mining-manager::taxes.entries") }})</small>' +
                '</div>' +
                '<div class="text-right">' +
                '<span class="mr-3"><strong>' + Number(g.totalOwed).toLocaleString() + '</strong> <small class="text-muted">ISK {{ trans("mining-manager::taxes.owed") }}</small></span>' +
                '<span class="mr-3"><strong>' + Number(g.totalPaid).toLocaleString() + '</strong> <small class="text-muted">ISK {{ trans("mining-manager::taxes.paid") }}</small></span>' +
                statusBadge +
                '<i class="fas fa-chevron-down ml-2"></i>' +
                '</div>' +
                '</div>' +
                '</td>' +
                '</tr>');

            headerRow.data('character-id', charId);
            headerRow.on('click', function() {
                var cid = $(this).data('character-id');
                var icon = $(this).find('.fa-chevron-down, .fa-chevron-up');
                var rows = $('.tax-row[data-character-id="' + cid + '"]');
                rows.toggle();
                icon.toggleClass('fa-chevron-down fa-chevron-up');
            });

            tbody.append(headerRow);
            // Move the original rows after the header (hidden)
            g.rows.forEach(function(row) {
                tbody.append(row);
            });
        });
    }
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
