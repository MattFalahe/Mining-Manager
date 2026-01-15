@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::taxes.calculate_title'))
@section('page_header', trans('mining-manager::menu.tax_management'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper taxes-calculate-page">


{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/tax') && !Request::is('*/tax/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.index') }}">
                <i class="fas fa-chart-pie"></i> {{ trans('mining-manager::menu.tax_overview') }}
            </a>
        </li>
        @can('mining-manager.tax.calculate')
        <li class="{{ Request::is('*/tax/calculate') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.calculate') }}">
                <i class="fas fa-calculator"></i> {{ trans('mining-manager::menu.calculate_taxes') }}
            </a>
        </li>
        @endcan
        <li class="{{ Request::is('*/tax/my-taxes') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.my-taxes') }}">
                <i class="fas fa-receipt"></i> {{ trans('mining-manager::menu.my_taxes') }}
            </a>
        </li>
        <li class="{{ Request::is('*/tax/codes') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.codes') }}">
                <i class="fas fa-barcode"></i> {{ trans('mining-manager::menu.tax_codes') }}
            </a>
        </li>
        @can('mining-manager.tax.generate_invoices')
        <li class="{{ Request::is('*/tax/contracts') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.contracts') }}">
                <i class="fas fa-file-contract"></i> {{ trans('mining-manager::menu.tax_contracts') }}
            </a>
        </li>
        @endcan
        @can('mining-manager.tax.verify_payments')
        <li class="{{ Request::is('*/tax/wallet') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.taxes.wallet') }}">
                <i class="fas fa-wallet"></i> {{ trans('mining-manager::menu.wallet_verification') }}
            </a>
        </li>
        @endcan
    </ul>
    <div class="tab-content">


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
                                        @if ($paymentSettings['method'] == 'contract')
                                            <i class="fas fa-file-contract"></i>
                                            {{ trans('mining-manager::taxes.payment_contracts') }}
                                        @else
                                            <i class="fas fa-money-bill-wave"></i>
                                            {{ trans('mining-manager::taxes.payment_wallet') }}
                                        @endif
                                    </div>
                                    <div class="input-group-append">
                                        <a href="{{ route('mining-manager.settings', ['tab' => 'tax_payment']) }}" 
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
                                {{ $paymentSettings['method'] == 'contract' 
                                    ? trans('mining-manager::taxes.regenerate_contracts') 
                                    : trans('mining-manager::taxes.regenerate_codes') }}
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

                        <!-- Mining Activity Table -->
                        <div class="table-responsive mt-3">
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
                                            <td>{{ $entry['character']['name'] ?? 'Unknown' }}</td>
                                            <td>{{ number_format($entry['quantity']) }}</td>
                                            <td>{{ number_format($entry['quantity'] * ($entry['volume'] ?? 0), 2) }} m³</td>
                                            <td>{{ number_format($entry['value'] ?? 0, 0) }} ISK</td>
                                            <td>{{ number_format(($entry['value'] ?? 0) * 0.10, 0) }} ISK</td>
                                            <td>0</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Info -->
                        <div class="text-muted mt-2">
                            {{ trans('mining-manager::taxes.showing_entries', ['count' => min(10, count($liveTracking['entries'])), 'total' => count($liveTracking['entries'])]) }}
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

        $.ajax({
            url: '{{ route("mining-manager.taxes.process-calculation") }}',
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                $('#calculation-progress').hide();
                $('#calculate-btn').prop('disabled', false);

                if (response.success) {
                    $('#results-alert')
                        .removeClass('alert-danger')
                        .addClass('alert-success')
                        .html(`
                            <h5><i class="fas fa-check-circle"></i> ${response.message}</h5>
                            <hr>
                            <p><strong>{{ trans('mining-manager::taxes.scope') }}:</strong> ${response.data.scope}</p>
                            <p><strong>{{ trans('mining-manager::taxes.month') }}:</strong> ${response.data.month}</p>
                            <p><strong>{{ trans('mining-manager::taxes.taxes_calculated') }}:</strong> ${response.data.count}</p>
                            <p><strong>{{ trans('mining-manager::taxes.total_tax') }}:</strong> ${Number(response.data.total).toLocaleString()} ISK</p>
                            ${response.data.errors.length > 0 ? '<p class="text-warning"><strong>{{ trans("mining-manager::taxes.errors") }}:</strong> ' + response.data.errors.length + '</p>' : ''}
                        `);
                    $('#calculation-results').show();

                    // Refresh live tracking
                    refreshLiveTracking();
                } else {
                    $('#results-alert')
                        .removeClass('alert-success')
                        .addClass('alert-danger')
                        .html(`<i class="fas fa-exclamation-triangle"></i> ${response.message}`);
                    $('#calculation-results').show();
                }
            },
            error: function(xhr) {
                $('#calculation-progress').hide();
                $('#calculate-btn').prop('disabled', false');
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
                if (response.success) {
                    alert(response.message);
                } else {
                    alert('{{ trans("mining-manager::taxes.error") }}: ' + response.message);
                }
                $('#regenerate-payments-btn').prop('disabled', false).html('<i class="fas fa-sync"></i> ' + (paymentMethod == 'contract' ? '{{ trans("mining-manager::taxes.regenerate_contracts") }}' : '{{ trans("mining-manager::taxes.regenerate_codes") }}'));
            },
            error: function(xhr) {
                alert('{{ trans("mining-manager::taxes.error_occurred") }}');
                $('#regenerate-payments-btn').prop('disabled', false).html('<i class="fas fa-sync"></i> ' + (paymentMethod == 'contract' ? '{{ trans("mining-manager::taxes.regenerate_contracts") }}' : '{{ trans("mining-manager::taxes.regenerate_codes") }}'));
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
                if (response.success && response.data.has_data) {
                    // Update the live tracking display
                    // (You can implement a more sophisticated update here)
                    $('#last-updated').text('{{ trans("mining-manager::taxes.updated") }}: ' + new Date().toLocaleTimeString());
                }
            }
        });
    }

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

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
