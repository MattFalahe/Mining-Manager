<div class="card card-dark">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-cog"></i>
            {{ trans('mining-manager::settings.settings_menu') }}
        </h3>
    </div>
    <div class="card-body p-0">
        <ul class="nav nav-pills flex-column">
            <li class="nav-item">
                <a href="#" 
                   class="nav-link active" 
                   data-tab="general-settings">
                    <i class="fas fa-sliders-h"></i>
                    {{ trans('mining-manager::settings.general') }}
                </a>
            </li>
            <li class="nav-item">
                <a href="#" 
                   class="nav-link" 
                   data-tab="tax-rates">
                    <i class="fas fa-percent"></i>
                    {{ trans('mining-manager::settings.tax_rates') }}
                </a>
            </li>
            <li class="nav-item">
                <a href="#" 
                   class="nav-link" 
                   data-tab="pricing">
                    <i class="fas fa-tags"></i>
                    {{ trans('mining-manager::settings.pricing') }}
                </a>
            </li>
            <li class="nav-item">
                <a href="#" 
                   class="nav-link" 
                   data-tab="features">
                    <i class="fas fa-toggle-on"></i>
                    {{ trans('mining-manager::settings.features') }}
                </a>
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
