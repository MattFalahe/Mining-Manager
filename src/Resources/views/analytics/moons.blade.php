@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::analytics.moon_analytics'))
@section('page_header', trans('mining-manager::menu.analytics'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
<style>
    .moon-stat-card {
        border-radius: 10px;
        padding: 20px;
        color: white;
        text-align: center;
        transition: transform 0.3s;
    }
    .moon-stat-card:hover { transform: translateY(-3px); }
    .moon-stat-card h3 { font-size: 2rem; margin: 0; font-weight: bold; }
    .moon-stat-card p { margin: 5px 0 0; opacity: 0.9; }
    .chart-container { height: 350px; position: relative; }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard analytics-moons-page">

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics') && !Request::is('*/analytics/*') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.index') }}">
                    <i class="fas fa-chart-area"></i> {{ trans('mining-manager::menu.analytics_overview') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/charts') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.charts') }}">
                    <i class="fas fa-chart-line"></i> {{ trans('mining-manager::menu.performance_charts') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/moons') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.moons') }}">
                    <i class="fas fa-moon"></i> {{ trans('mining-manager::analytics.moon_analytics') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/tables') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.tables') }}">
                    <i class="fas fa-table"></i> {{ trans('mining-manager::menu.data_tables') }}
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ Request::is('*/analytics/compare') ? 'active' : '' }}" href="{{ route('mining-manager.analytics.compare') }}">
                    <i class="fas fa-balance-scale"></i> {{ trans('mining-manager::menu.comparative_analysis') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">

{{-- CONTROLS --}}
<div class="row mb-3">
    <div class="col-12">
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-sliders-h"></i>
                    {{ trans('mining-manager::analytics.moon_analytics_settings') }}
                </h3>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('mining-manager.analytics.moons') }}" class="form-inline">
                    {{-- View Mode Toggle --}}
                    <div class="btn-group btn-group-toggle mr-3 mb-2" data-toggle="buttons">
                        <label class="btn btn-outline-primary {{ ($viewMode ?? 'monthly') === 'monthly' ? 'active' : '' }}">
                            <input type="radio" name="view_mode" value="monthly" {{ ($viewMode ?? 'monthly') === 'monthly' ? 'checked' : '' }} onchange="this.form.submit()">
                            <i class="fas fa-calendar-alt"></i> {{ trans('mining-manager::analytics.monthly_view') }}
                        </label>
                        <label class="btn btn-outline-primary {{ ($viewMode ?? 'monthly') === 'extraction' ? 'active' : '' }}">
                            <input type="radio" name="view_mode" value="extraction" {{ ($viewMode ?? 'monthly') === 'extraction' ? 'checked' : '' }} onchange="toggleExtractionPicker()">
                            <i class="fas fa-crosshairs"></i> {{ trans('mining-manager::analytics.per_extraction') }}
                        </label>
                    </div>

                    {{-- Month Picker (monthly mode) --}}
                    <div id="monthPicker" class="form-group mr-3 mb-2" style="{{ ($viewMode ?? 'monthly') === 'extraction' ? 'display:none' : '' }}">
                        <label class="mr-2">{{ trans('mining-manager::analytics.month') }}:</label>
                        <input type="month" name="month" class="form-control" value="{{ ($month ?? now())->format('Y-m') }}" onchange="this.form.submit()">
                    </div>

                    {{-- Extraction Picker (extraction mode) --}}
                    <div id="extractionPicker" class="form-group mr-3 mb-2" style="{{ ($viewMode ?? 'monthly') !== 'extraction' ? 'display:none' : '' }}">
                        <label class="mr-2">{{ trans('mining-manager::analytics.select_extraction') }}:</label>
                        <select name="extraction_id" class="form-control" onchange="this.form.submit()">
                            <option value="">-- {{ trans('mining-manager::analytics.choose_extraction') }} --</option>
                            @foreach(($availableExtractions ?? collect()) as $ext)
                                <option value="{{ $ext->id }}" {{ ($selectedExtraction ?? '') == $ext->id ? 'selected' : '' }}>
                                    {{ $ext->label }}
                                </option>
                            @endforeach
                        </select>
                        {{-- Keep month in sync --}}
                        <input type="hidden" name="month" value="{{ ($month ?? now())->format('Y-m') }}">
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@if(($viewMode ?? 'monthly') === 'monthly')
    @include('mining-manager::analytics.partials.moon-monthly', [
        'summary' => $summary ?? [],
        'utilization' => $utilization ?? collect(),
        'popularity' => $popularity ?? collect(),
        'orePopularity' => $orePopularity ?? collect(),
    ])
@elseif(isset($extractionData) && $extractionData)
    @include('mining-manager::analytics.partials.moon-extraction', [
        'data' => $extractionData,
    ])
@else
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-moon fa-5x text-muted mb-3"></i>
                    <h4>{{ trans('mining-manager::analytics.select_extraction_prompt') }}</h4>
                    <p class="text-muted">{{ trans('mining-manager::analytics.select_extraction_description') }}</p>
                </div>
            </div>
        </div>
    </div>
@endif

    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}
</div>{{-- /.mining-manager-wrapper --}}

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/chart.min.js') }}"></script>
<script>
function toggleExtractionPicker() {
    document.getElementById('monthPicker').style.display = 'none';
    document.getElementById('extractionPicker').style.display = '';
}
</script>
@endpush

@endsection
