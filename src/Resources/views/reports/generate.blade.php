@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::reports.generate_report'))
@section('page_header', trans('mining-manager::menu.reports'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
@endpush

@section('full')
<div class="mining-manager-wrapper reports-generate-page">

{{-- TAB NAVIGATION --}}
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="{{ Request::is('*/reports') && !Request::is('*/reports/*') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.reports.index') }}">
                <i class="fas fa-list"></i> {{ trans('mining-manager::menu.view_reports') }}
            </a>
        </li>
        @can('mining-manager.admin')
        <li class="{{ Request::is('*/reports/generate') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.admin') }}">
                <i class="fas fa-plus-circle"></i> {{ trans('mining-manager::menu.generate_report') }}
            </a>
        </li>
        <li class="{{ Request::is('*/reports/scheduled') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.reports.scheduled') }}">
                <i class="fas fa-clock"></i> {{ trans('mining-manager::menu.scheduled_reports') }}
            </a>
        </li>
        @endcan
        <li class="{{ Request::is('*/reports/export') ? 'active' : '' }}">
            <a href="{{ route('mining-manager.reports.export') }}">
                <i class="fas fa-download"></i> {{ trans('mining-manager::menu.export_data') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">


<div class="reports-generate">
    
    {{-- STEP INDICATOR --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="step-indicator">
                <div class="step active" id="step1">
                    <div class="step-circle">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>{{ trans('mining-manager::reports.select_type') }}</div>
                </div>
                <div class="step" id="step2">
                    <div class="step-circle">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div>{{ trans('mining-manager::reports.select_format') }}</div>
                </div>
                <div class="step" id="step3">
                    <div class="step-circle">
                        <i class="fas fa-cog"></i>
                    </div>
                    <div>{{ trans('mining-manager::reports.configure') }}</div>
                </div>
                <div class="step" id="step4">
                    <div class="step-circle">
                        <i class="fas fa-check"></i>
                    </div>
                    <div>{{ trans('mining-manager::reports.generate') }}</div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('mining-manager.reports.store') }}" id="reportForm">
        @csrf

        {{-- STEP 1: SELECT REPORT TYPE --}}
        <div class="row mb-4">
            <div class="col-12">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-calendar-alt"></i>
                            {{ trans('mining-manager::reports.step_1_select_type') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="info-badge">
                            <i class="fas fa-info-circle"></i>
                            {{ trans('mining-manager::reports.type_selection_help') }}
                        </div>
                        
                        <div class="row">
                            @foreach($reportTypes as $typeKey => $typeName)
                            <div class="col-md-3 mb-3">
                                <div class="card report-option-card" data-report-type="{{ $typeKey }}">
                                    <div class="card-body text-center">
                                        <div class="report-icon icon-{{ $typeKey }}">
                                            <i class="fas fa-calendar-{{ $typeKey === 'daily' ? 'day' : ($typeKey === 'weekly' ? 'week' : ($typeKey === 'monthly' ? 'alt' : 'check')) }}"></i>
                                        </div>
                                        <h4>{{ $typeName }}</h4>
                                        <p class="text-muted">
                                            {{ trans('mining-manager::reports.type_' . $typeKey . '_description') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        
                        <input type="hidden" name="report_type" id="report_type" required>
                        @error('report_type')
                        <div class="alert alert-danger mt-2">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- STEP 2: SELECT FORMAT --}}
        <div class="row mb-4" id="formatSection" style="display: none;">
            <div class="col-12">
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-file-alt"></i>
                            {{ trans('mining-manager::reports.step_2_select_format') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="info-badge">
                            <i class="fas fa-info-circle"></i>
                            {{ trans('mining-manager::reports.format_selection_help') }}
                        </div>
                        
                        <div class="row">
                            @foreach($formats as $formatKey => $formatName)
                            <div class="col-md-4">
                                <div class="format-option" data-format="{{ $formatKey }}">
                                    <div class="format-icon">
                                        <i class="fas fa-file-{{ $formatKey === 'json' ? 'code' : ($formatKey === 'csv' ? 'csv' : 'pdf') }} text-{{ $formatKey === 'json' ? 'primary' : ($formatKey === 'csv' ? 'success' : 'danger') }}"></i>
                                    </div>
                                    <h5>{{ $formatName }}</h5>
                                    <small class="text-muted">
                                        {{ trans('mining-manager::reports.format_' . $formatKey . '_description') }}
                                    </small>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        
                        <input type="hidden" name="format" id="format" required>
                        @error('format')
                        <div class="alert alert-danger mt-2">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- STEP 3: CONFIGURE (CUSTOM DATE RANGE) --}}
        <div class="row mb-4 date-range-card" id="customDateSection">
            <div class="col-12">
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-cog"></i>
                            {{ trans('mining-manager::reports.step_3_configure') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="info-badge">
                            <i class="fas fa-info-circle"></i>
                            {{ trans('mining-manager::reports.custom_date_help') }}
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="start_date">
                                        <i class="fas fa-calendar-check"></i>
                                        {{ trans('mining-manager::reports.start_date') }}
                                    </label>
                                    <input type="date" class="form-control" name="start_date" id="start_date" 
                                           value="{{ old('start_date', now()->subDays(30)->format('Y-m-d')) }}">
                                    @error('start_date')
                                    <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="end_date">
                                        <i class="fas fa-calendar-times"></i>
                                        {{ trans('mining-manager::reports.end_date') }}
                                    </label>
                                    <input type="date" class="form-control" name="end_date" id="end_date" 
                                           value="{{ old('end_date', now()->format('Y-m-d')) }}">
                                    @error('end_date')
                                    <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-12">
                                <h5>{{ trans('mining-manager::reports.quick_ranges') }}</h5>
                                <div class="btn-group mb-3">
                                    <button type="button" class="btn btn-outline-secondary quick-range" data-days="7">
                                        {{ trans('mining-manager::reports.last_7_days') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary quick-range" data-days="30">
                                        {{ trans('mining-manager::reports.last_30_days') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary quick-range" data-days="90">
                                        {{ trans('mining-manager::reports.last_90_days') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary quick-range" data-days="365">
                                        {{ trans('mining-manager::reports.last_year') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- STEP 4: PREVIEW & GENERATE --}}
        <div class="row mb-4" id="previewSection" style="display: none;">
            <div class="col-12">
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-eye"></i>
                            {{ trans('mining-manager::reports.step_4_preview') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="generation-preview">
                            <h4><i class="fas fa-file-alt"></i> {{ trans('mining-manager::reports.report_summary') }}</h4>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <strong>{{ trans('mining-manager::reports.type') }}:</strong>
                                    <div id="preview-type" class="h5">-</div>
                                </div>
                                <div class="col-md-3">
                                    <strong>{{ trans('mining-manager::reports.format') }}:</strong>
                                    <div id="preview-format" class="h5">-</div>
                                </div>
                                <div class="col-md-6">
                                    <strong>{{ trans('mining-manager::reports.period') }}:</strong>
                                    <div id="preview-period" class="h5">-</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="preview-section">
                            <h5><i class="fas fa-chart-bar"></i> {{ trans('mining-manager::reports.report_will_include') }}</h5>
                            <ul class="list-unstyled">
                                <li><i class="fas fa-check text-success"></i> {{ trans('mining-manager::reports.include_mining_activity') }}</li>
                                <li><i class="fas fa-check text-success"></i> {{ trans('mining-manager::reports.include_value_calculations') }}</li>
                                <li><i class="fas fa-check text-success"></i> {{ trans('mining-manager::reports.include_miner_breakdown') }}</li>
                                <li><i class="fas fa-check text-success"></i> {{ trans('mining-manager::reports.include_ore_distribution') }}</li>
                                <li><i class="fas fa-check text-success"></i> {{ trans('mining-manager::reports.include_system_analysis') }}</li>
                                <li><i class="fas fa-check text-success"></i> {{ trans('mining-manager::reports.include_tax_data') }}</li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i>
                            {{ trans('mining-manager::reports.generation_time_notice') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ACTION BUTTONS --}}
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center">
                        <button type="submit" class="btn btn-success btn-lg" id="generateBtn" disabled>
                            <i class="fas fa-cogs"></i>
                            {{ trans('mining-manager::reports.generate_report') }}
                        </button>
                        <a href="{{ route('mining-manager.reports.index') }}" class="btn btn-secondary btn-lg">
                            <i class="fas fa-arrow-left"></i>
                            {{ trans('mining-manager::reports.back_to_list') }}
                        </a>
                        <button type="button" class="btn btn-info btn-lg" id="resetBtn">
                            <i class="fas fa-redo"></i>
                            {{ trans('mining-manager::reports.reset_form') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>

</div>

@push('javascript')
<script>
$(document).ready(function() {
    let selectedType = null;
    let selectedFormat = null;
    
    // Step 1: Select Report Type
    $('.report-option-card').on('click', function() {
        $('.report-option-card').removeClass('selected');
        $(this).addClass('selected');
        
        selectedType = $(this).data('report-type');
        $('#report_type').val(selectedType);
        
        // Update step indicator
        $('#step1').addClass('completed');
        $('#step2').addClass('active');
        
        // Show format section
        $('#formatSection').slideDown();
        
        // Show/hide custom date section
        if (selectedType === 'custom') {
            $('#customDateSection').addClass('show').slideDown();
            $('#step3').addClass('active');
        } else {
            $('#customDateSection').removeClass('show').slideUp();
            $('#step3').removeClass('active');
        }
        
        updatePreview();
    });
    
    // Step 2: Select Format
    $('.format-option').on('click', function() {
        $('.format-option').removeClass('selected');
        $(this).addClass('selected');
        
        selectedFormat = $(this).data('format');
        $('#format').val(selectedFormat);
        
        // Update step indicator
        $('#step2').addClass('completed');
        if (selectedType !== 'custom') {
            $('#step3').addClass('completed');
        }
        $('#step4').addClass('active');
        
        // Show preview section
        $('#previewSection').slideDown();
        
        // Enable generate button
        if ((selectedType !== 'custom') || ($('#start_date').val() && $('#end_date').val())) {
            $('#generateBtn').prop('disabled', false);
        }
        
        updatePreview();
    });
    
    // Quick date range buttons
    $('.quick-range').on('click', function() {
        const days = $(this).data('days');
        const endDate = new Date();
        const startDate = new Date();
        startDate.setDate(startDate.getDate() - days);
        
        $('#end_date').val(endDate.toISOString().split('T')[0]);
        $('#start_date').val(startDate.toISOString().split('T')[0]);
        
        updatePreview();
    });
    
    // Date changes
    $('#start_date, #end_date').on('change', function() {
        if ($('#start_date').val() && $('#end_date').val() && selectedFormat) {
            $('#generateBtn').prop('disabled', false);
            $('#step3').addClass('completed');
        }
        updatePreview();
    });
    
    // Reset form
    $('#resetBtn').on('click', function() {
        location.reload();
    });
    
    // Update preview
    function updatePreview() {
        if (selectedType) {
            const typeText = $('.report-option-card.selected h4').text();
            $('#preview-type').text(typeText);
        }
        
        if (selectedFormat) {
            $('#preview-format').text(selectedFormat.toUpperCase());
        }
        
        if (selectedType === 'custom' && $('#start_date').val() && $('#end_date').val()) {
            const start = new Date($('#start_date').val());
            const end = new Date($('#end_date').val());
            const days = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
            $('#preview-period').text(start.toLocaleDateString() + ' - ' + end.toLocaleDateString() + ' (' + days + ' days)');
        } else if (selectedType && selectedType !== 'custom') {
            let period = '';
            switch(selectedType) {
                case 'daily':
                    period = 'Yesterday';
                    break;
                case 'weekly':
                    period = 'Last Week';
                    break;
                case 'monthly':
                    period = 'Last Month';
                    break;
            }
            $('#preview-period').text(period);
        }
    }
    
    // Form submission
    $('#reportForm').on('submit', function() {
        $('#generateBtn').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::reports.generating") }}');
    });
});
</script>
@endpush

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

</div>{{-- /.mining-manager-wrapper --}}
@endsection
