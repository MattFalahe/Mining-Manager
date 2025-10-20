@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::reports.quick_export'))
@section('page_header', trans('mining-manager::reports.quick_export'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .export-option-card {
        transition: all 0.3s;
        cursor: pointer;
        border: 2px solid transparent;
        height: 100%;
        min-height: 250px;
    }
    
    .export-option-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        border-color: rgba(102, 126, 234, 0.5);
    }
    
    .export-option-card.selected {
        border-color: #667eea;
        background: rgba(102, 126, 234, 0.1);
    }
    
    .export-icon {
        font-size: 4rem;
        margin-bottom: 20px;
    }
    
    .icon-mining { color: #4e73df; }
    .icon-taxes { color: #1cc88a; }
    .icon-miners { color: #f6c23e; }
    .icon-systems { color: #e74a3b; }
    .icon-ores { color: #858796; }
    .icon-events { color: #5a5c69; }
    
    .format-selector {
        display: none;
        margin-top: 20px;
    }
    
    .format-selector.show {
        display: block;
    }
    
    .format-button {
        padding: 20px;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.3s;
        border: 2px solid rgba(255,255,255,0.1);
    }
    
    .format-button:hover {
        border-color: rgba(255,255,255,0.3);
        transform: scale(1.05);
    }
    
    .format-button.selected {
        border-color: #667eea;
        background: rgba(102, 126, 234, 0.2);
    }
    
    .quick-export-stats {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 20px;
        border-radius: 10px;
        color: white;
        margin-bottom: 20px;
    }
    
    .filter-section {
        display: none;
        background: rgba(0,0,0,0.2);
        padding: 20px;
        border-radius: 10px;
        margin-top: 20px;
    }
    
    .filter-section.show {
        display: block;
    }
    
    .export-preview {
        background: rgba(28, 200, 138, 0.1);
        border: 1px solid rgba(28, 200, 138, 0.3);
        padding: 15px;
        border-radius: 8px;
        margin-top: 20px;
    }
</style>
@endpush

@section('full')
<div class="reports-export">
    
    {{-- INFO BANNER --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="quick-export-stats">
                <div class="row">
                    <div class="col-md-8">
                        <h4>
                            <i class="fas fa-file-export"></i>
                            {{ trans('mining-manager::reports.quick_export_tool') }}
                        </h4>
                        <p class="mb-0">
                            {{ trans('mining-manager::reports.quick_export_description') }}
                        </p>
                    </div>
                    <div class="col-md-4 text-right">
                        <i class="fas fa-download fa-5x opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('mining-manager.reports.export.process') }}" id="exportForm">
        @csrf

        {{-- STEP 1: SELECT DATA TYPE --}}
        <div class="row mb-4">
            <div class="col-12">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-database"></i>
                            {{ trans('mining-manager::reports.step_1_select_data') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-lg-4 mb-3">
                                <div class="card export-option-card" data-export-type="mining_activity">
                                    <div class="card-body text-center">
                                        <div class="export-icon icon-mining">
                                            <i class="fas fa-gem"></i>
                                        </div>
                                        <h5>{{ trans('mining-manager::reports.mining_activity') }}</h5>
                                        <p class="text-muted">
                                            {{ trans('mining-manager::reports.export_mining_desc') }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4 mb-3">
                                <div class="card export-option-card" data-export-type="tax_records">
                                    <div class="card-body text-center">
                                        <div class="export-icon icon-taxes">
                                            <i class="fas fa-coins"></i>
                                        </div>
                                        <h5>{{ trans('mining-manager::reports.tax_records') }}</h5>
                                        <p class="text-muted">
                                            {{ trans('mining-manager::reports.export_tax_desc') }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4 mb-3">
                                <div class="card export-option-card" data-export-type="miner_stats">
                                    <div class="card-body text-center">
                                        <div class="export-icon icon-miners">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <h5>{{ trans('mining-manager::reports.miner_statistics') }}</h5>
                                        <p class="text-muted">
                                            {{ trans('mining-manager::reports.export_miners_desc') }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4 mb-3">
                                <div class="card export-option-card" data-export-type="system_stats">
                                    <div class="card-body text-center">
                                        <div class="export-icon icon-systems">
                                            <i class="fas fa-globe"></i>
                                        </div>
                                        <h5>{{ trans('mining-manager::reports.system_statistics') }}</h5>
                                        <p class="text-muted">
                                            {{ trans('mining-manager::reports.export_systems_desc') }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4 mb-3">
                                <div class="card export-option-card" data-export-type="ore_breakdown">
                                    <div class="card-body text-center">
                                        <div class="export-icon icon-ores">
                                            <i class="fas fa-cubes"></i>
                                        </div>
                                        <h5>{{ trans('mining-manager::reports.ore_breakdown') }}</h5>
                                        <p class="text-muted">
                                            {{ trans('mining-manager::reports.export_ores_desc') }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4 mb-3">
                                <div class="card export-option-card" data-export-type="event_data">
                                    <div class="card-body text-center">
                                        <div class="export-icon icon-events">
                                            <i class="fas fa-calendar-alt"></i>
                                        </div>
                                        <h5>{{ trans('mining-manager::reports.event_data') }}</h5>
                                        <p class="text-muted">
                                            {{ trans('mining-manager::reports.export_events_desc') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="export_type" id="export_type" required>
                        @error('export_type')
                        <div class="alert alert-danger mt-2">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- STEP 2: SELECT FORMAT & FILTERS --}}
        <div class="row format-selector" id="formatSection">
            <div class="col-md-6 mb-4">
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-file-alt"></i>
                            {{ trans('mining-manager::reports.step_2_select_format') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="format-button text-center" data-format="csv">
                                    <i class="fas fa-file-csv fa-3x text-success mb-2"></i>
                                    <h6>CSV</h6>
                                    <small class="text-muted">{{ trans('mining-manager::reports.best_for_excel') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="format-button text-center" data-format="json">
                                    <i class="fas fa-file-code fa-3x text-primary mb-2"></i>
                                    <h6>JSON</h6>
                                    <small class="text-muted">{{ trans('mining-manager::reports.best_for_api') }}</small>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="format" id="format" required>
                        @error('format')
                        <div class="alert alert-danger mt-2">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-filter"></i>
                            {{ trans('mining-manager::reports.step_3_filters') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        {{-- Date Range --}}
                        <div class="form-group">
                            <label>{{ trans('mining-manager::reports.date_range') }}</label>
                            <div class="row">
                                <div class="col-6">
                                    <input type="date" class="form-control" name="start_date" 
                                           value="{{ request('start_date', now()->subDays(30)->format('Y-m-d')) }}">
                                </div>
                                <div class="col-6">
                                    <input type="date" class="form-control" name="end_date" 
                                           value="{{ request('end_date', now()->format('Y-m-d')) }}">
                                </div>
                            </div>
                        </div>

                        {{-- Quick Range Buttons --}}
                        <div class="btn-group btn-group-sm w-100 mb-3">
                            <button type="button" class="btn btn-outline-secondary quick-range" data-days="7">7d</button>
                            <button type="button" class="btn btn-outline-secondary quick-range" data-days="30">30d</button>
                            <button type="button" class="btn btn-outline-secondary quick-range" data-days="90">90d</button>
                            <button type="button" class="btn btn-outline-secondary quick-range" data-days="365">1y</button>
                        </div>

                        {{-- Additional Filters (Dynamic based on export type) --}}
                        <div id="additionalFilters"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- EXPORT PREVIEW & SUBMIT --}}
        <div class="row filter-section" id="submitSection">
            <div class="col-12">
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-check-circle"></i>
                            {{ trans('mining-manager::reports.ready_to_export') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="export-preview">
                            <h5><i class="fas fa-info-circle"></i> {{ trans('mining-manager::reports.export_summary') }}</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>{{ trans('mining-manager::reports.data_type') }}:</strong>
                                    <div id="preview-type">-</div>
                                </div>
                                <div class="col-md-4">
                                    <strong>{{ trans('mining-manager::reports.format') }}:</strong>
                                    <div id="preview-format">-</div>
                                </div>
                                <div class="col-md-4">
                                    <strong>{{ trans('mining-manager::reports.period') }}:</strong>
                                    <div id="preview-period">-</div>
                                </div>
                            </div>
                        </div>

                        <div class="text-center mt-4">
                            <button type="submit" class="btn btn-success btn-lg" id="exportBtn" disabled>
                                <i class="fas fa-download"></i>
                                {{ trans('mining-manager::reports.export_data') }}
                            </button>
                            <a href="{{ route('mining-manager.reports.index') }}" class="btn btn-secondary btn-lg">
                                <i class="fas fa-arrow-left"></i>
                                {{ trans('mining-manager::reports.back_to_reports') }}
                            </a>
                            <button type="button" class="btn btn-info btn-lg" id="resetBtn">
                                <i class="fas fa-redo"></i>
                                {{ trans('mining-manager::reports.reset') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- RECENT EXPORTS --}}
    @if(isset($recentExports) && $recentExports->count() > 0)
    <div class="row mt-4">
        <div class="col-12">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        {{ trans('mining-manager::reports.recent_exports') }}
                    </h3>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>{{ trans('mining-manager::reports.type') }}</th>
                                <th>{{ trans('mining-manager::reports.exported') }}</th>
                                <th>{{ trans('mining-manager::reports.period') }}</th>
                                <th>{{ trans('mining-manager::reports.format') }}</th>
                                <th class="text-right">{{ trans('mining-manager::reports.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentExports as $export)
                            <tr>
                                <td>{{ trans('mining-manager::reports.' . $export->export_type) }}</td>
                                <td>{{ $export->created_at->diffForHumans() }}</td>
                                <td>{{ $export->start_date->format('M d') }} - {{ $export->end_date->format('M d, Y') }}</td>
                                <td>
                                    <span class="badge badge-{{ $export->format === 'json' ? 'primary' : 'success' }}">
                                        {{ strtoupper($export->format) }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('mining-manager.reports.export.download', $export->id) }}" 
                                       class="btn btn-sm btn-success">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

@push('javascript')
<script>
$(document).ready(function() {
    let selectedType = null;
    let selectedFormat = null;

    // Select export type
    $('.export-option-card').on('click', function() {
        $('.export-option-card').removeClass('selected');
        $(this).addClass('selected');
        
        selectedType = $(this).data('export-type');
        $('#export_type').val(selectedType);
        
        // Show format section
        $('#formatSection').addClass('show').slideDown();
        
        // Update additional filters based on type
        updateAdditionalFilters(selectedType);
        
        updatePreview();
    });

    // Select format
    $('.format-button').on('click', function() {
        $('.format-button').removeClass('selected');
        $(this).addClass('selected');
        
        selectedFormat = $(this).data('format');
        $('#format').val(selectedFormat);
        
        // Show submit section
        $('#submitSection').addClass('show').slideDown();
        
        // Enable export button
        $('#exportBtn').prop('disabled', false);
        
        updatePreview();
    });

    // Quick range buttons
    $('.quick-range').on('click', function() {
        const days = $(this).data('days');
        const endDate = new Date();
        const startDate = new Date();
        startDate.setDate(startDate.getDate() - days);
        
        $('input[name="end_date"]').val(endDate.toISOString().split('T')[0]);
        $('input[name="start_date"]').val(startDate.toISOString().split('T')[0]);
        
        updatePreview();
    });

    // Date changes
    $('input[name="start_date"], input[name="end_date"]').on('change', function() {
        updatePreview();
    });

    // Reset form
    $('#resetBtn').on('click', function() {
        location.reload();
    });

    // Update additional filters based on export type
    function updateAdditionalFilters(type) {
        let html = '';
        
        switch(type) {
            case 'miner_stats':
                html = `
                    <div class="form-group">
                        <label>{{ trans("mining-manager::reports.minimum_value") }}</label>
                        <input type="number" class="form-control" name="min_value" placeholder="0">
                    </div>
                `;
                break;
            case 'system_stats':
                html = `
                    <div class="form-group">
                        <label>{{ trans("mining-manager::reports.region_filter") }}</label>
                        <select class="form-control" name="region_id">
                            <option value="">{{ trans("mining-manager::reports.all_regions") }}</option>
                        </select>
                    </div>
                `;
                break;
            case 'tax_records':
                html = `
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="only_unpaid" id="only_unpaid">
                        <label class="form-check-label" for="only_unpaid">
                            {{ trans("mining-manager::reports.only_unpaid") }}
                        </label>
                    </div>
                `;
                break;
        }
        
        $('#additionalFilters').html(html);
    }

    // Update preview
    function updatePreview() {
        if (selectedType) {
            const typeText = $(`.export-option-card[data-export-type="${selectedType}"] h5`).text();
            $('#preview-type').text(typeText);
        }
        
        if (selectedFormat) {
            $('#preview-format').text(selectedFormat.toUpperCase());
        }
        
        if ($('input[name="start_date"]').val() && $('input[name="end_date"]').val()) {
            const start = new Date($('input[name="start_date"]').val());
            const end = new Date($('input[name="end_date"]').val());
            const days = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
            $('#preview-period').text(start.toLocaleDateString() + ' - ' + end.toLocaleDateString() + ' (' + days + ' days)');
        }
    }

    // Form submission
    $('#exportForm').on('submit', function() {
        $('#exportBtn').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::reports.exporting") }}');
    });
});
</script>
@endpush
@endsection
