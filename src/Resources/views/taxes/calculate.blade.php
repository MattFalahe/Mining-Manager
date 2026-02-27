@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.calculate_title'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper taxes-calculate-page">


@include('mining-manager::taxes.partials.tab-navigation')


<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ trans('mining-manager::taxes.calculate_mining_tax') }}</h3>
            </div>
            <div class="card-body">
                <form id="tax-calculation-form">
                    @csrf
                    <div class="row">
                        <!-- Month Selection -->
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="month">{{ trans('mining-manager::taxes.month') }}</label>
                                <select class="form-control" id="month" name="month" required>
                                    @for ($i = 1; $i <= 12; $i++)
                                        <option value="{{ $i }}" {{ $i == now()->month ? 'selected' : '' }}>
                                            {{ \Carbon\Carbon::create()->month($i)->format('F') }}
                                        </option>
                                    @endfor
                                </select>
                            </div>
                        </div>

                        <!-- Year Selection -->
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="year">{{ trans('mining-manager::taxes.year') }}</label>
                                <select class="form-control" id="year" name="year" required>
                                    @foreach ($years as $year)
                                        <option value="{{ $year }}" {{ $year == now()->year ? 'selected' : '' }}>
                                            {{ $year }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <!-- Corporation Selection -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="corporation_id">{{ trans('mining-manager::taxes.corporation') }}</label>
                                <select class="form-control" id="corporation_id" name="corporation_id">
                                    <option value="">{{ trans('mining-manager::taxes.all_corporations') }}</option>
                                    @foreach ($corporations as $corporation)
                                        <option value="{{ $corporation->corporation_id }}">
                                            {{ $corporation->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Tax Calculation Method -->
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="calculation_method">{{ trans('mining-manager::taxes.tax_calculation') }}</label>
                                <select class="form-control" id="calculation_method" name="calculation_method" required>
                                    <option value="accumulated" {{ $generalSettings['tax_calculation_method'] == 'accumulated' ? 'selected' : '' }}>
                                        {{ trans('mining-manager::taxes.combined') }}
                                    </option>
                                    <option value="individually" {{ $generalSettings['tax_calculation_method'] == 'individually' ? 'selected' : '' }}>
                                        {{ trans('mining-manager::taxes.individual') }}
                                    </option>
                                </select>
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle"></i>
                                    {{ trans('mining-manager::taxes.calculation_method_help') }}
                                </small>
                            </div>
                        </div>

                        <!-- Calculation Data Source -->
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="data_source">{{ trans('mining-manager::taxes.calculation_methods') }}</label>
                                <select class="form-control" id="data_source" name="data_source" required>
                                    <option value="archived" {{ $sourceSettings['source'] == 'archived' ? 'selected' : '' }}>
                                        {{ trans('mining-manager::taxes.archived_data') }}
                                    </option>
                                    <option value="live">
                                        {{ trans('mining-manager::taxes.live_data') }}
                                    </option>
                                </select>
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle"></i>
                                    {{ trans('mining-manager::taxes.data_source_help') }}
                                </small>
                            </div>
                        </div>

                        <!-- Payment Method Display -->
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>{{ trans('mining-manager::taxes.payment_method') }}</label>
                                <div class="input-group">
                                    <div class="form-control" readonly>
                                        <i class="fas fa-money-bill-wave"></i>
                                        {{ trans('mining-manager::taxes.payment_wallet') }}
                                    </div>
                                    <div class="input-group-append">
                                        <a href="{{ route('mining-manager.settings.index') }}#tax-rates"
                                           class="btn btn-outline-secondary"
                                           data-toggle="tooltip"
                                           title="{{ trans('mining-manager::taxes.change_in_settings') }}">
                                            <i class="fas fa-cog"></i>
                                        </a>
                                    </div>
                                </div>
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle"></i>
                                    {{ trans('mining-manager::taxes.payment_method_help') }}
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Options (Collapsible) -->
                    <div class="row">
                        <div class="col-md-12">
                            <a data-toggle="collapse" href="#advanced-options" role="button" aria-expanded="false">
                                <i class="fas fa-chevron-down"></i>
                                {{ trans('mining-manager::taxes.advanced_options') }}
                            </a>
                            <div class="collapse" id="advanced-options">
                                <div class="row mt-3">
                                    <!-- Specific Character -->
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="character_id">{{ trans('mining-manager::taxes.specific_character') }}</label>
                                            <select class="form-control select2" id="character_id" name="character_id">
                                                <option value="">{{ trans('mining-manager::taxes.all_characters') }}</option>
                                                {{-- Characters will be loaded via AJAX based on corporation selection --}}
                                            </select>
                                            <small class="form-text text-muted">
                                                {{ trans('mining-manager::taxes.specific_character_help') }}
                                            </small>
                                        </div>
                                    </div>

                                    <!-- Recalculate Option -->
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>&nbsp;</label>
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="recalculate" name="recalculate" value="1">
                                                <label class="custom-control-label" for="recalculate">
                                                    {{ trans('mining-manager::taxes.recalculate') }}
                                                </label>
                                            </div>
                                            <small class="form-text text-muted">
                                                {{ trans('mining-manager::taxes.recalculate_help') }}
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="row">
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary" id="calculate-btn">
                                <i class="fas fa-calculator"></i>
                                {{ trans('mining-manager::taxes.calculate') }}
                            </button>
                            <button type="button" class="btn btn-success ml-2" id="regenerate-payments-btn">
                                <i class="fas fa-sync"></i>
                                {{ trans('mining-manager::taxes.regenerate_codes') }}
                            </button>
                            <button type="button" class="btn btn-info ml-2" id="refresh-tracking-btn">
                                <i class="fas fa-refresh"></i>
                                {{ trans('mining-manager::taxes.refresh_tracking') }}
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Calculation Progress -->
                <div id="calculation-progress" class="mt-3" style="display: none;">
                    <div class="progress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%">
                            {{ trans('mining-manager::taxes.calculating') }}
                        </div>
                    </div>
                </div>

                <!-- Results Display -->
                <div id="calculation-results" class="mt-3" style="display: none;">
                    <div class="alert" id="results-alert"></div>
                </div>
            </div>
        </div>

        <!-- Live Tax Tracking Card -->
        <div class="card mt-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-chart-line"></i>
                    {{ trans('mining-manager::taxes.mining_tax_summary') }} ({{ trans('mining-manager::taxes.this_month') }})
                </h3>
                <div class="card-tools">
                    <span class="badge badge-info" id="last-updated">
                        {{ trans('mining-manager::taxes.updated') }}: {{ now()->format('H:i:s') }}
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div id="live-tracking-container">
                    @if ($liveTracking['has_data'])
                        <!-- Summary Stats -->
                        <div class="row">
                            <div class="col-md-3">
                                <div class="info-box">
                                    <span class="info-box-icon bg-info"><i class="fas fa-gem"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">{{ trans('mining-manager::taxes.total_value') }}</span>
                                        <span class="info-box-number">{{ number_format($liveTracking['total_value'], 0) }} ISK</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-box">
                                    <span class="info-box-icon bg-success"><i class="fas fa-coins"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">{{ trans('mining-manager::taxes.estimated_tax') }}</span>
                                        <span class="info-box-number">{{ number_format($liveTracking['estimated_tax'], 0) }} ISK</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-box">
                                    <span class="info-box-icon bg-warning"><i class="fas fa-users"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">{{ trans('mining-manager::taxes.active_miners') }}</span>
                                        <span class="info-box-number">{{ $liveTracking['character_count'] }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-box">
                                    <span class="info-box-icon bg-primary"><i class="fas fa-calendar"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">{{ trans('mining-manager::taxes.month') }}</span>
                                        <span class="info-box-number">{{ $liveTracking['month'] }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- View Toggle -->
                        <div class="mb-3 mt-3">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary active" id="view-flat-btn">
                                    <i class="fas fa-list"></i> Flat View
                                </button>
                                <button type="button" class="btn btn-outline-primary" id="view-grouped-btn">
                                    <i class="fas fa-layer-group"></i> Grouped by Account
                                    @if(isset($liveTracking['account_count']))
                                        <span class="badge badge-light">{{ $liveTracking['account_count'] }}</span>
                                    @endif
                                </button>
                            </div>
                        </div>

                        <!-- FLAT VIEW (default) -->
                        <div id="flat-view">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="live-tracking-table">
                                    <thead>
                                        <tr>
                                            <th>{{ trans('mining-manager::taxes.date') }}</th>
                                            <th>{{ trans('mining-manager::taxes.character_name') }}</th>
                                            <th>{{ trans('mining-manager::taxes.mined_units') }}</th>
                                            <th>{{ trans('mining-manager::taxes.mined_volume') }}</th>
                                            <th>{{ trans('mining-manager::taxes.mineral_price') }}</th>
                                            <th>{{ trans('mining-manager::taxes.tax') }}</th>
                                            <th>{{ trans('mining-manager::taxes.event_tax') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($liveTracking['entries'] as $entry)
                                            <tr>
                                                <td>{{ \Carbon\Carbon::parse($entry['date'])->format('Y-m-d') }}</td>
                                                <td>
                                                    {{ $entry['character']['name'] ?? 'Unknown' }}
                                                    @if(!($entry['character']['is_registered'] ?? true))
                                                        <span class="badge badge-secondary" style="font-size: 0.65em;">Not in SeAT</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    {{ number_format($entry['quantity']) }}
                                                    <small class="text-muted d-block">{{ $entry['ore_name'] ?? '' }}</small>
                                                </td>
                                                <td>{{ number_format($entry['quantity'] * ($entry['volume'] ?? 0), 2) }} m³</td>
                                                <td>{{ number_format($entry['total_value'] ?? 0, 0) }} ISK</td>
                                                <td>{{ number_format($entry['tax_amount'] ?? 0, 0) }} ISK</td>
                                                <td>{{ number_format($entry['event_tax'] ?? 0, 0) }} ISK</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- GROUPED BY ACCOUNT VIEW -->
                        <div id="grouped-view" style="display:none;">
                            @php
                                $groupedEntries = collect($liveTracking['entries'])->groupBy('main_character_id');
                            @endphp

                            @foreach($groupedEntries as $mainCharId => $accountEntries)
                            @php
                                $accountTotalValue = $accountEntries->sum('total_value');
                                $accountTotalTax = $accountEntries->sum('tax_amount');
                                $accountCharacters = $accountEntries->unique('character_id');
                                $mainCharName = $accountEntries->first()['main_character_name'] ?? 'Unknown Account';
                            @endphp
                            <div class="account-group-card">
                                <div class="account-group-header"
                                     data-toggle="collapse"
                                     data-target="#account-group-{{ $mainCharId }}"
                                     aria-expanded="false">
                                    <img src="https://images.evetech.net/characters/{{ $mainCharId }}/portrait?size=64"
                                         class="img-circle mr-3" style="width:40px;height:40px;">
                                    <div>
                                        <strong>{{ $mainCharName }}</strong>
                                        <br>
                                        <small class="text-muted">
                                            {{ $accountCharacters->count() }} character(s)
                                            @if($accountCharacters->count() > 1)
                                                -
                                                @foreach($accountCharacters as $ac)
                                                    {{ $ac['character']['name'] }}@if(!$loop->last), @endif
                                                @endforeach
                                            @endif
                                        </small>
                                    </div>
                                    <div class="ml-auto text-right">
                                        <span class="badge badge-primary" style="font-size: 0.85em;">
                                            {{ number_format($accountTotalValue, 0) }} ISK mined
                                        </span>
                                        <span class="badge badge-danger ml-1" style="font-size: 0.85em;">
                                            {{ number_format($accountTotalTax, 0) }} ISK tax
                                        </span>
                                        <i class="fas fa-chevron-down ml-2 chevron-icon"></i>
                                    </div>
                                </div>
                                <div class="collapse" id="account-group-{{ $mainCharId }}">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-sm mb-0">
                                            <thead>
                                                <tr>
                                                    <th>{{ trans('mining-manager::taxes.date') }}</th>
                                                    <th>{{ trans('mining-manager::taxes.character_name') }}</th>
                                                    <th>Ore</th>
                                                    <th class="text-right">Qty</th>
                                                    <th class="text-right">Value (ISK)</th>
                                                    <th class="text-right">Tax (ISK)</th>
                                                    <th class="text-right">Event Tax</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($accountEntries as $entry)
                                                <tr>
                                                    <td>{{ \Carbon\Carbon::parse($entry['date'])->format('Y-m-d') }}</td>
                                                    <td>
                                                        {{ $entry['character']['name'] }}
                                                        @if(!($entry['character']['is_registered'] ?? true))
                                                            <span class="badge badge-secondary" style="font-size: 0.6em;">Not in SeAT</span>
                                                        @endif
                                                    </td>
                                                    <td>{{ $entry['ore_name'] }}</td>
                                                    <td class="text-right">{{ number_format($entry['quantity']) }}</td>
                                                    <td class="text-right">{{ number_format($entry['total_value'], 0) }}</td>
                                                    <td class="text-right">{{ number_format($entry['tax_amount'], 0) }}</td>
                                                    <td class="text-right">{{ number_format($entry['event_tax'] ?? 0, 0) }}</td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                            <tfoot class="bg-light">
                                                <tr>
                                                    <td colspan="4"><strong>Account Total</strong></td>
                                                    <td class="text-right"><strong>{{ number_format($accountTotalValue, 0) }} ISK</strong></td>
                                                    <td class="text-right"><strong>{{ number_format($accountTotalTax, 0) }} ISK</strong></td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>

                        <!-- Pagination Info -->
                        <div class="text-muted mt-2">
                            {{ trans('mining-manager::taxes.showing_entries', ['count' => min(50, count($liveTracking['entries'])), 'total' => count($liveTracking['entries'])]) }}
                        </div>
                    @else
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            {{ trans('mining-manager::taxes.no_mining_activity_this_month') }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Tax Calculation Form Submission
    $('#tax-calculation-form').on('submit', function(e) {
        e.preventDefault();

        $('#calculate-btn').prop('disabled', true);
        $('#calculation-progress').show();
        $('#calculation-results').hide();

        // Build month in YYYY-MM format from separate month/year fields
        var monthVal = String($('#month').val()).padStart(2, '0');
        var yearVal = $('#year').val();
        var formData = $(this).serializeArray().filter(function(field) {
            return field.name !== 'month' && field.name !== 'year';
        });
        formData.push({ name: 'month', value: yearVal + '-' + monthVal });

        $.ajax({
            url: '{{ route("mining-manager.taxes.process-calculation") }}',
            method: 'POST',
            data: $.param(formData),
            success: function(response) {
                $('#calculation-progress').hide();
                $('#calculate-btn').prop('disabled', false);

                if (response.status === 'success') {
                    $('#results-alert')
                        .removeClass('alert-danger alert-warning')
                        .addClass('alert-success')
                        .html(`
                            <h5><i class="fas fa-check-circle"></i> ${response.message}</h5>
                            <hr>
                            <p><strong>{{ trans('mining-manager::taxes.scope') }}:</strong> ${response.results.method}</p>
                            <p><strong>{{ trans('mining-manager::taxes.taxes_calculated') }}:</strong> ${response.results.count}</p>
                            <p><strong>{{ trans('mining-manager::taxes.total_tax') }}:</strong> ${Number(response.results.total).toLocaleString()} ISK</p>
                            ${response.results.errors && response.results.errors.length > 0 ? '<p class="text-warning"><strong>{{ trans("mining-manager::taxes.errors") }}:</strong> ' + response.results.errors.length + '</p>' : ''}
                        `);
                    $('#calculation-results').show();

                    // Refresh live tracking
                    refreshLiveTracking();
                } else if (response.status === 'warning') {
                    $('#results-alert')
                        .removeClass('alert-success alert-danger')
                        .addClass('alert-warning')
                        .html(`<i class="fas fa-exclamation-triangle"></i> ${response.message}`);
                    $('#calculation-results').show();
                } else {
                    $('#results-alert')
                        .removeClass('alert-success alert-warning')
                        .addClass('alert-danger')
                        .html(`<i class="fas fa-exclamation-triangle"></i> ${response.message}`);
                    $('#calculation-results').show();
                }
            },
            error: function(xhr) {
                $('#calculation-progress').hide();
                $('#calculate-btn').prop('disabled', false);
                $('#results-alert')
                    .removeClass('alert-success')
                    .addClass('alert-danger')
                    .html(`<i class="fas fa-exclamation-triangle"></i> ${xhr.responseJSON?.message || '{{ trans("mining-manager::taxes.error_occurred") }}'}`);
                $('#calculation-results').show();
            }
        });
    });

    // Regenerate Payments Button
    $('#regenerate-payments-btn').on('click', function() {
        if (!confirm('{{ trans("mining-manager::taxes.regenerate_confirm") }}')) {
            return;
        }

        const month = $('#year').val() + '-' + String($('#month').val()).padStart(2, '0');
        const corporationId = $('#corporation_id').val();
        const characterId = $('#character_id').val();
        const paymentMethod = '{{ $paymentSettings["method"] }}';

        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::taxes.regenerating") }}');

        $.ajax({
            url: '{{ route("mining-manager.taxes.regenerate-payments") }}',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                month: month,
                corporation_id: corporationId,
                character_id: characterId,
                payment_method: paymentMethod
            },
            success: function(response) {
                if (response.status === 'success') {
                    toastr.success(response.message);
                } else {
                    toastr.error(response.message);
                }
                $('#regenerate-payments-btn').prop('disabled', false).html('<i class="fas fa-sync"></i> {{ trans("mining-manager::taxes.regenerate_codes") }}');
            },
            error: function(xhr) {
                alert('{{ trans("mining-manager::taxes.error_occurred") }}');
                $('#regenerate-payments-btn').prop('disabled', false).html('<i class="fas fa-sync"></i> {{ trans("mining-manager::taxes.regenerate_codes") }}');
            }
        });
    });

    // Refresh Live Tracking
    $('#refresh-tracking-btn').on('click', refreshLiveTracking);

    // Auto-refresh live tracking every 5 minutes
    setInterval(refreshLiveTracking, 300000);

    function refreshLiveTracking() {
        $.ajax({
            url: '{{ route("mining-manager.taxes.live-tracking") }}',
            method: 'GET',
            success: function(response) {
                if (response.status === 'success' && response.data.has_data) {
                    // Update summary stats
                    $('.info-box-number').eq(0).text(Number(response.data.total_value).toLocaleString() + ' ISK');
                    $('.info-box-number').eq(1).text(Number(response.data.estimated_tax).toLocaleString() + ' ISK');
                    $('.info-box-number').eq(2).text(response.data.character_count);
                    $('.info-box-number').eq(3).text(response.data.month);
                }
                $('#last-updated').text('{{ trans("mining-manager::taxes.updated") }}: ' + new Date().toLocaleTimeString());
            },
            error: function() {
                toastr.warning('{{ trans("mining-manager::taxes.error_refreshing_tracking") }}');
            }
        });
    }

    // View Toggle: Flat vs Grouped
    $('#view-flat-btn').on('click', function() {
        $('#flat-view').show();
        $('#grouped-view').hide();
        $(this).addClass('active');
        $('#view-grouped-btn').removeClass('active');
    });

    $('#view-grouped-btn').on('click', function() {
        $('#flat-view').hide();
        $('#grouped-view').show();
        $(this).addClass('active');
        $('#view-flat-btn').removeClass('active');
    });

    // Load characters when corporation changes
    $('#corporation_id').on('change', function() {
        const corpId = $(this).val();
        if (!corpId) {
            $('#character_id').empty().append('<option value="">{{ trans("mining-manager::taxes.all_characters") }}</option>');
            return;
        }

        $.ajax({
            url: '{{ route("mining-manager.api.corporation-characters") }}',
            data: { corporation_id: corpId },
            success: function(characters) {
                $('#character_id').empty().append('<option value="">{{ trans("mining-manager::taxes.all_characters") }}</option>');
                characters.forEach(function(char) {
                    $('#character_id').append(`<option value="${char.character_id}">${char.name}</option>`);
                });
            }
        });
    });
});
</script>
@endpush

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
