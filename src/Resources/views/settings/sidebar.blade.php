<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-cog"></i>
            {{ trans('mining-manager::settings.settings_menu') }}
        </h3>
    </div>
    <div class="card-body p-0">
        <ul class="nav nav-pills flex-column">
            {{-- Global Settings Section --}}
            <li class="nav-header px-3 py-2 text-muted small text-uppercase" style="background: rgba(40, 167, 69, 0.1); border-left: 3px solid #28a745;">
                <i class="fas fa-globe"></i> Global Settings
            </li>
            <li class="nav-item">
                <a href="#"
                   class="nav-link active"
                   data-tab="general-settings">
                    <i class="fas fa-sliders-h"></i>
                    {{ trans('mining-manager::settings.general') }}
                    <span class="badge badge-success badge-pill float-right" title="Global setting">G</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#"
                   class="nav-link"
                   data-tab="pricing">
                    <i class="fas fa-tags"></i>
                    {{ trans('mining-manager::settings.pricing') }}
                    <span class="badge badge-success badge-pill float-right" title="Global setting">G</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#"
                   class="nav-link"
                   data-tab="features">
                    <i class="fas fa-toggle-on"></i>
                    {{ trans('mining-manager::settings.features') }}
                    <span class="badge badge-success badge-pill float-right" title="Global setting">G</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#"
                   class="nav-link"
                   data-tab="webhooks">
                    <i class="fas fa-satellite-dish"></i>
                    {{ trans('mining-manager::settings.webhooks') }}
                    <span class="badge badge-success badge-pill float-right" title="Global setting">G</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#"
                   class="nav-link"
                   data-tab="dashboard">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                    <span class="badge badge-success badge-pill float-right" title="Global setting">G</span>
                </a>
            </li>

            {{-- Corporation-Specific Settings Section --}}
            <li class="nav-header px-3 py-2 text-muted small text-uppercase mt-2" style="background: rgba(23, 162, 184, 0.1); border-left: 3px solid #17a2b8;">
                <i class="fas fa-building"></i> Corporation Settings
            </li>
            <li class="nav-item">
                <a href="#"
                   class="nav-link"
                   data-tab="tax-rates">
                    <i class="fas fa-percent"></i>
                    {{ trans('mining-manager::settings.tax_rates') }}
                    <span class="badge badge-info badge-pill float-right" title="Per-corporation setting">C</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('mining-manager.settings.configured-corporations') }}"
                   class="nav-link">
                    <i class="fas fa-building"></i>
                    Configured Corps
                    <span class="badge badge-secondary badge-pill float-right" title="View all corporations">
                        <i class="fas fa-list"></i>
                    </span>
                </a>
            </li>

            {{-- System Section --}}
            <li class="nav-header px-3 py-2 text-muted small text-uppercase mt-2" style="background: rgba(108, 117, 125, 0.1); border-left: 3px solid #6c757d;">
                <i class="fas fa-tools"></i> System
            </li>
            <li class="nav-item">
                <a href="#"
                   class="nav-link"
                   data-tab="advanced">
                    <i class="fas fa-cogs"></i>
                    {{ trans('mining-manager::settings.advanced') }}
                </a>
            </li>
            <li class="nav-item">
                <a href="#"
                   class="nav-link"
                   data-tab="help">
                    <i class="fas fa-question-circle"></i>
                    {{ trans('mining-manager::settings.help') }}
                </a>
            </li>
        </ul>
    </div>
</div>

{{-- Legend --}}
<div class="card card-dark mt-3">
    <div class="card-header py-2">
        <h6 class="card-title mb-0">
            <i class="fas fa-info-circle"></i> Settings Legend
        </h6>
    </div>
    <div class="card-body py-2">
        <div class="d-flex align-items-center mb-1">
            <span class="badge badge-success mr-2">G</span>
            <small class="text-muted">Global - applies to all corporations</small>
        </div>
        <div class="d-flex align-items-center">
            <span class="badge badge-info mr-2">C</span>
            <small class="text-muted">Corp-specific - can differ per corporation</small>
        </div>
    </div>
</div>
