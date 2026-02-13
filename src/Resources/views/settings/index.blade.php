@extends('web::layouts.grids.12')

@section('title', trans('mining-manager::settings.settings'))
@section('page_header', trans('mining-manager::settings.settings'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .settings-wrapper {
        display: flex;
        gap: 20px;
    }
    
    .settings-sidebar {
        flex: 0 0 250px;
    }
    
    .settings-content {
        flex: 1;
    }
    
    .nav-pills .nav-link {
        color: #e2e8f0;
        border-radius: 5px;
        margin-bottom: 5px;
        transition: all 0.3s;
    }
    
    .nav-pills .nav-link:hover {
        background: rgba(102, 126, 234, 0.2);
    }
    
    .nav-pills .nav-link.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    
    .nav-pills .nav-link i {
        width: 20px;
        text-align: center;
        margin-right: 10px;
    }
    
    .settings-section {
        display: none;
    }
    
    .settings-section.active {
        display: block;
    }
    
    .action-buttons {
        position: sticky;
        bottom: 0;
        background: #2d3748;
        padding: 20px;
        border-top: 2px solid rgba(102, 126, 234, 0.3);
        margin: 0 -20px -20px -20px;
        border-radius: 0 0 10px 10px;
    }
    
    .info-banner {
        background: rgba(23, 162, 184, 0.15);
        border: 1px solid rgba(23, 162, 184, 0.3);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .warning-banner {
        background: rgba(255, 193, 7, 0.15);
        border: 1px solid rgba(255, 193, 7, 0.3);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .success-banner {
        background: rgba(28, 200, 138, 0.15);
        border: 1px solid rgba(28, 200, 138, 0.3);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    @media (max-width: 768px) {
        .settings-wrapper {
            flex-direction: column;
        }
        
        .settings-sidebar {
            flex: 1;
        }
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper settings-page">
    
    {{-- Success/Error Messages --}}
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle"></i>
        {{ session('success') }}
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    @endif

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle"></i>
        {{ session('error') }}
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    @endif

    @if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>{{ trans('mining-manager::settings.validation_errors') }}</strong>
        <ul class="mb-0">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    @endif

    {{-- Corporation Settings Status Indicators --}}
    @if(isset($corporationId) && $corporationId)
        @if($isFirstTimeSetup)
            <div class="info-banner">
                <h5><i class="fas fa-info-circle"></i> First Time Setup</h5>
                <p class="mb-0">
                    You are setting up tax settings for this corporation for the first time.
                    These settings will be saved specifically for this corporation and can be different from your other corporations.
                </p>
            </div>
        @elseif($hasCustomSettings)
            <div class="success-banner">
                <h5><i class="fas fa-check-circle"></i> Custom Corporation Settings Active</h5>
                <p class="mb-0">
                    This corporation has custom tax settings configured.
                    You are viewing and editing settings specific to this corporation.
                </p>
            </div>
        @endif
    @else
        <div class="warning-banner">
            <h5><i class="fas fa-exclamation-triangle"></i> Global Settings Mode</h5>
            <p class="mb-0">
                You are currently viewing global settings. To configure corporation-specific tax rates:
                <br><strong>1.</strong> Go to General Settings tab
                <br><strong>2.</strong> Select a corporation from the dropdown
                <br><strong>3.</strong> Click the "Switch Corporation" button to load that corporation's settings
            </p>
        </div>
    @endif

    <div class="settings-wrapper">
        {{-- Sidebar --}}
        <div class="settings-sidebar">
            @include('mining-manager::settings.sidebar')
        </div>

        {{-- Content --}}
        <div class="settings-content">
            <div class="card card-dark">
                <div class="card-body">

                    {{-- General Settings Tab --}}
                    <div id="general-settings" class="settings-section active">
                        @include('mining-manager::settings.tabs.general', [
                            'settings' => (object)$settings['general'],
                            'corporations' => $corporations,
                            'selectedCorporationId' => $corporationId ?? null
                        ])
                    </div>

                    {{-- Tax Rates Tab --}}
                    <div id="tax-rates" class="settings-section">
                        @php
                            // Flatten moon ore nested array for easier form access
                            $taxRatesFlattened = $settings['tax_rates'];
                            if (isset($taxRatesFlattened['moon_ore']) && is_array($taxRatesFlattened['moon_ore'])) {
                                foreach ($taxRatesFlattened['moon_ore'] as $rarity => $rate) {
                                    $taxRatesFlattened['moon_ore_' . $rarity] = $rate;
                                }
                                unset($taxRatesFlattened['moon_ore']);
                            }

                            // Merge all tax-related settings
                            $taxSettings = array_merge(
                                $taxRatesFlattened,
                                $settings['tax_selector'],
                                $settings['exemptions']
                            );
                        @endphp
                        @include('mining-manager::settings.tabs.tax_rates', ['settings' => (object)$taxSettings])
                    </div>

                    {{-- Pricing Tab --}}
                    <div id="pricing" class="settings-section">
                        @include('mining-manager::settings.tabs.pricing', ['settings' => $settings])
                    </div>

                    {{-- Features Tab --}}
                    <div id="features" class="settings-section">
                        @include('mining-manager::settings.tabs.features', ['settings' => (object)$settings['features']])
                    </div>

                    {{-- Webhooks Tab --}}
                    <div id="webhooks" class="settings-section">
                        @include('mining-manager::settings.tabs.webhooks', ['webhooks' => $webhooks ?? collect()])
                    </div>

                    {{-- Dashboard Settings Tab --}}
                    <div id="dashboard" class="settings-section">
                        @include('mining-manager::settings.tabs.dashboard', [
                            'settings' => $settings,
                            'corporations' => $corporations,
                            'selectedCorporationId' => $corporationId ?? null
                        ])
                    </div>

                    {{-- Advanced Settings --}}
                    <div id="advanced" class="settings-section">
                        <h4>
                            <i class="fas fa-cogs"></i>
                            {{ trans('mining-manager::settings.advanced_settings') }}
                        </h4>
                        <hr>

                        <div class="warning-banner">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>{{ trans('mining-manager::settings.warning') }}:</strong>
                            {{ trans('mining-manager::settings.advanced_warning') }}
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="card bg-dark">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="fas fa-file-export"></i>
                                            {{ trans('mining-manager::settings.export_settings') }}
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <p>{{ trans('mining-manager::settings.export_description') }}</p>
                                        <a href="{{ route('mining-manager.settings.export') }}" 
                                           class="btn btn-info btn-block">
                                            <i class="fas fa-download"></i>
                                            {{ trans('mining-manager::settings.export_now') }}
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card bg-dark">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="fas fa-file-import"></i>
                                            {{ trans('mining-manager::settings.import_settings') }}
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <p>{{ trans('mining-manager::settings.import_description') }}</p>
                                        <form method="POST" action="{{ route('mining-manager.settings.import') }}" 
                                              enctype="multipart/form-data">
                                            @csrf
                                            <div class="custom-file mb-3">
                                                <input type="file" class="custom-file-input" name="settings_file" 
                                                       id="settingsFile" accept=".json" required>
                                                <label class="custom-file-label" for="settingsFile">
                                                    {{ trans('mining-manager::settings.choose_file') }}
                                                </label>
                                            </div>
                                            <button type="submit" class="btn btn-warning btn-block">
                                                <i class="fas fa-upload"></i>
                                                {{ trans('mining-manager::settings.import_now') }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="card bg-dark">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="fas fa-sync"></i>
                                            {{ trans('mining-manager::settings.clear_cache') }}
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <p>{{ trans('mining-manager::settings.cache_description') }}</p>
                                        <button type="button" class="btn btn-primary btn-block" id="clearCacheBtn">
                                            <i class="fas fa-trash-alt"></i>
                                            {{ trans('mining-manager::settings.clear_now') }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card bg-dark border-danger">
                                    <div class="card-header bg-danger">
                                        <h5 class="card-title mb-0">
                                            <i class="fas fa-undo"></i>
                                            {{ trans('mining-manager::settings.reset_settings') }}
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <p>{{ trans('mining-manager::settings.reset_description') }}</p>
                                        <button type="button" class="btn btn-danger btn-block" id="resetSettingsBtn">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            {{ trans('mining-manager::settings.reset_now') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Help Tab --}}
                    <div id="help" class="settings-section">
                        <h4>
                            <i class="fas fa-question-circle"></i>
                            {{ trans('mining-manager::settings.help_documentation') }}
                        </h4>
                        <hr>

                        <div class="info-banner">
                            <h5>
                                <i class="fas fa-info-circle"></i>
                                {{ trans('mining-manager::settings.help_title') }}
                            </h5>
                            <p>{{ trans('mining-manager::settings.help_intro') }}</p>
                        </div>

                        {{-- Settings Help Cards --}}
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="card bg-dark">
                                    <div class="card-body">
                                        <h5>
                                            <i class="fas fa-cog text-primary"></i>
                                            {{ trans('mining-manager::settings.help_general') }}
                                        </h5>
                                        <p>{{ trans('mining-manager::settings.help_general_desc') }}</p>
                                        <ul>
                                            <li>{{ trans('mining-manager::settings.help_general_1') }}</li>
                                            <li>{{ trans('mining-manager::settings.help_general_2') }}</li>
                                            <li>{{ trans('mining-manager::settings.help_general_3') }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <div class="card bg-dark">
                                    <div class="card-body">
                                        <h5>
                                            <i class="fas fa-coins text-success"></i>
                                            {{ trans('mining-manager::settings.help_tax') }}
                                        </h5>
                                        <p>{{ trans('mining-manager::settings.help_tax_desc') }}</p>
                                        <ul>
                                            <li>{{ trans('mining-manager::settings.help_tax_1') }}</li>
                                            <li>{{ trans('mining-manager::settings.help_tax_2') }}</li>
                                            <li>{{ trans('mining-manager::settings.help_tax_3') }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <div class="card bg-dark">
                                    <div class="card-body">
                                        <h5>
                                            <i class="fas fa-chart-line text-warning"></i>
                                            {{ trans('mining-manager::settings.help_pricing') }}
                                        </h5>
                                        <p>{{ trans('mining-manager::settings.help_pricing_desc') }}</p>
                                        <ul>
                                            <li>{{ trans('mining-manager::settings.help_pricing_1') }}</li>
                                            <li>{{ trans('mining-manager::settings.help_pricing_2') }}</li>
                                            <li>{{ trans('mining-manager::settings.help_pricing_3') }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <div class="card bg-dark">
                                    <div class="card-body">
                                        <h5>
                                            <i class="fas fa-toggle-on text-info"></i>
                                            {{ trans('mining-manager::settings.help_features') }}
                                        </h5>
                                        <p>{{ trans('mining-manager::settings.help_features_desc') }}</p>
                                        <ul>
                                            <li>{{ trans('mining-manager::settings.help_features_1') }}</li>
                                            <li>{{ trans('mining-manager::settings.help_features_2') }}</li>
                                            <li>{{ trans('mining-manager::settings.help_features_3') }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Moon Extraction Lifecycle --}}
                        <div class="card bg-dark mb-3">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-moon text-info"></i>
                                    {{ trans('mining-manager::settings.help_moon_lifecycle') }}
                                </h5>
                            </div>
                            <div class="card-body">
                                <p>{{ trans('mining-manager::settings.help_moon_lifecycle_desc') }}</p>
                                <div class="row">
                                    <div class="col-md-3 text-center mb-2">
                                        <span class="badge badge-warning p-2" style="font-size: 0.9rem;">
                                            <i class="fas fa-satellite-dish"></i> Extracting
                                        </span>
                                        <p class="small mt-2 mb-0">{{ trans('mining-manager::settings.help_moon_extracting') }}</p>
                                    </div>
                                    <div class="col-md-3 text-center mb-2">
                                        <span class="badge badge-success p-2" style="font-size: 0.9rem;">
                                            <i class="fas fa-check-circle"></i> Ready
                                        </span>
                                        <p class="small mt-2 mb-0">{{ trans('mining-manager::settings.help_moon_ready') }}</p>
                                    </div>
                                    <div class="col-md-3 text-center mb-2">
                                        <span class="badge badge-danger p-2" style="font-size: 0.9rem;">
                                            <i class="fas fa-exclamation-triangle"></i> Unstable
                                        </span>
                                        <p class="small mt-2 mb-0">{{ trans('mining-manager::settings.help_moon_unstable') }}</p>
                                    </div>
                                    <div class="col-md-3 text-center mb-2">
                                        <span class="badge badge-secondary p-2" style="font-size: 0.9rem;">
                                            <i class="fas fa-clock"></i> Expired
                                        </span>
                                        <p class="small mt-2 mb-0">{{ trans('mining-manager::settings.help_moon_expired') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Scheduled Tasks --}}
                        <div class="card bg-dark mb-3">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-clock text-primary"></i>
                                    {{ trans('mining-manager::settings.help_scheduled') }}
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">{{ trans('mining-manager::settings.help_scheduled_desc') }}</p>
                                <div class="table-responsive">
                                    <table class="table table-sm table-dark table-striped mb-0">
                                        <thead>
                                            <tr>
                                                <th>Command</th>
                                                <th>Schedule</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><code>mining-manager:process-ledger</code></td>
                                                <td><span class="badge badge-info">{{ trans('mining-manager::settings.every_30_min') }}</span></td>
                                                <td>Process mining ledger data from ESI</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:update-extractions</code></td>
                                                <td><span class="badge badge-info">{{ trans('mining-manager::settings.every_6_hours') }}</span></td>
                                                <td>Update moon extraction data from ESI</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:update-events</code></td>
                                                <td><span class="badge badge-info">{{ trans('mining-manager::settings.every_2_hours') }}</span></td>
                                                <td>Update mining events</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:cache-prices</code></td>
                                                <td><span class="badge badge-info">{{ trans('mining-manager::settings.every_4_hours') }}</span></td>
                                                <td>Cache ore prices from price provider</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:recalculate-extraction-values</code></td>
                                                <td><span class="badge badge-info">{{ trans('mining-manager::settings.twice_daily') }}</span></td>
                                                <td>Update extraction values with current prices</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:calculate-taxes</code></td>
                                                <td><span class="badge badge-primary">{{ trans('mining-manager::settings.daily') }}</span></td>
                                                <td>Calculate monthly tax obligations</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:archive-extractions</code></td>
                                                <td><span class="badge badge-primary">{{ trans('mining-manager::settings.daily') }}</span></td>
                                                <td>Archive completed extractions</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:detect-jackpots</code></td>
                                                <td><span class="badge badge-primary">{{ trans('mining-manager::settings.daily') }}</span></td>
                                                <td>Detect jackpot moon ores</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:generate-reports</code></td>
                                                <td><span class="badge badge-primary">{{ trans('mining-manager::settings.daily') }}</span></td>
                                                <td>Generate daily reports</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:verify-payments</code></td>
                                                <td><span class="badge badge-info">{{ trans('mining-manager::settings.every_6_hours') }}</span></td>
                                                <td>Verify wallet payments</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:detect-theft</code></td>
                                                <td><span class="badge badge-warning">{{ trans('mining-manager::settings.twice_monthly') }}</span></td>
                                                <td>Full theft detection scan</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:monitor-active-thefts</code></td>
                                                <td><span class="badge badge-info">{{ trans('mining-manager::settings.every_6_hours') }}</span></td>
                                                <td>Monitor active theft incidents</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:generate-invoices</code></td>
                                                <td><span class="badge badge-success">{{ trans('mining-manager::settings.monthly') }}</span></td>
                                                <td>Generate tax invoices</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:finalize-month</code></td>
                                                <td><span class="badge badge-success">2nd of month</span></td>
                                                <td>Finalize previous month summaries</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:calculate-monthly-stats</code></td>
                                                <td><span class="badge badge-success">2nd of month</span></td>
                                                <td>Pre-calculate dashboard statistics</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:send-reminders</code></td>
                                                <td><span class="badge badge-success">25th of month</span></td>
                                                <td>Send tax payment reminders</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        {{-- CLI Commands Reference --}}
                        <div class="card bg-dark mb-3">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-terminal text-success"></i>
                                    {{ trans('mining-manager::settings.help_commands') }}
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">{{ trans('mining-manager::settings.help_commands_desc') }}</p>

                                <h6 class="text-info mt-3"><i class="fas fa-wrench"></i> Diagnostic Commands</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-dark mb-3">
                                        <tbody>
                                            <tr>
                                                <td style="width: 45%;"><code>mining-manager:diagnose-character {character_id}</code></td>
                                                <td>Diagnose a specific character's data</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:diagnose-affiliations</code></td>
                                                <td>Check character affiliations</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:diagnose-extractions</code></td>
                                                <td>Diagnose moon extraction issues</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:diagnose-prices</code></td>
                                                <td>Test price provider connectivity</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:diagnose-type-ids</code></td>
                                                <td>Validate ore type ID mappings</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <h6 class="text-warning mt-3"><i class="fas fa-sync"></i> Manual Execution</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-dark mb-3">
                                        <tbody>
                                            <tr>
                                                <td style="width: 45%;"><code>mining-manager:process-ledger --force</code></td>
                                                <td>Force process all mining data</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:update-extractions --corporation={id}</code></td>
                                                <td>Update extractions for specific corp</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:detect-theft --dry-run</code></td>
                                                <td>Preview theft detection without changes</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:archive-extractions --dry-run</code></td>
                                                <td>Preview archival without changes</td>
                                            </tr>
                                            <tr>
                                                <td><code>mining-manager:backfill-ore-flags</code></td>
                                                <td>Backfill ore type flags</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <h6 class="text-danger mt-3"><i class="fas fa-flask"></i> Test Data (Development Only)</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-dark mb-0">
                                        <tbody>
                                            <tr>
                                                <td style="width: 45%;"><code>mining-manager:generate-test-data</code></td>
                                                <td>Generate test mining data</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        {{-- Developer Tools --}}
                        <div class="card bg-dark border-info mb-3">
                            <div class="card-header bg-info bg-opacity-25">
                                <h5 class="mb-0">
                                    <i class="fas fa-code text-info"></i>
                                    {{ trans('mining-manager::settings.help_developer') }}
                                </h5>
                            </div>
                            <div class="card-body">
                                <p>{{ trans('mining-manager::settings.help_diagnostics_info') }}</p>
                                <div class="row">
                                    <div class="col-md-6">
                                        <ul class="mb-0">
                                            <li>Price provider testing</li>
                                            <li>Type ID validation</li>
                                            <li>Cache health monitoring</li>
                                            <li>Webhook testing</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <ul class="mb-0">
                                            <li>Settings health check</li>
                                            <li>Tax diagnostic tools</li>
                                            <li>Data integrity validation</li>
                                            <li>Valuation testing</li>
                                        </ul>
                                    </div>
                                </div>
                                <hr>
                                <a href="{{ route('mining-manager.diagnostic.index') }}"
                                   class="btn btn-info">
                                    <i class="fas fa-stethoscope"></i>
                                    {{ trans('mining-manager::settings.help_diagnostics_link') }}
                                </a>
                            </div>
                        </div>

                        {{-- Troubleshooting --}}
                        <div class="card bg-dark border-warning mb-3">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-tools text-warning"></i>
                                    {{ trans('mining-manager::settings.help_troubleshooting') }}
                                </h5>
                            </div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <li><strong>No mining data?</strong> {{ trans('mining-manager::settings.help_no_data') }}</li>
                                    <li><strong>Pricing issues?</strong> {{ trans('mining-manager::settings.help_price_issues') }}</li>
                                    <li><strong>ESI/API problems?</strong> {{ trans('mining-manager::settings.help_esi_issues') }}</li>
                                    <li><strong>Moon extractions empty?</strong> Run <code>php artisan mining-manager:update-extractions</code> and check corp tokens</li>
                                    <li><strong>Taxes not calculating?</strong> Run <code>php artisan mining-manager:calculate-taxes --debug</code></li>
                                </ul>
                            </div>
                        </div>

                        {{-- GitHub Link --}}
                        <div class="alert alert-info">
                            <h5>
                                <i class="fas fa-book"></i>
                                {{ trans('mining-manager::settings.need_more_help') }}
                            </h5>
                            <p>{{ trans('mining-manager::settings.documentation_text') }}</p>
                            <a href="https://github.com/MattFalahe/seat-corp-mining-manager"
                               target="_blank"
                               class="btn btn-info">
                                <i class="fab fa-github"></i>
                                {{ trans('mining-manager::settings.view_documentation') }}
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

</div>

@push('javascript')
<script>
$(document).ready(function() {
    // Corporation switching functionality
    $('#switchCorporationBtn').on('click', function() {
        const corporationId = $('#corporation_id').val();

        if (!corporationId) {
            alert('Please select a corporation first.');
            return;
        }

        // Redirect to settings page with corporation_id parameter
        window.location.href = '{{ route("mining-manager.settings.index") }}?corporation_id=' + corporationId;
    });

    // Update hidden field when corporation changes
    $('#corporation_id').on('change', function() {
        $('#selected_corporation_id').val($(this).val());
    });

    // Tab switching
    $('.nav-link[data-tab]').on('click', function(e) {
        e.preventDefault();

        const tab = $(this).data('tab');

        // Update active nav
        $('.nav-link').removeClass('active');
        $(this).addClass('active');

        // Update active content
        $('.settings-section').removeClass('active');
        $(`#${tab}`).addClass('active');

        // Update URL hash
        window.location.hash = tab;
    });

    // Load tab from URL hash on page load
    if (window.location.hash) {
        const hash = window.location.hash.substring(1);
        $(`.nav-link[data-tab="${hash}"]`).click();
    }

    // Custom file input label update
    $('.custom-file-input').on('change', function() {
        const fileName = $(this).val().split('\\').pop();
        $(this).siblings('.custom-file-label').addClass('selected').html(fileName);
    });
    
    // Clear cache
    $('#clearCacheBtn').on('click', function() {
        if (!confirm('{{ trans("mining-manager::settings.confirm_clear_cache") }}')) {
            return;
        }
        
        $(this).prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::settings.clearing") }}');
        
        $.ajax({
            url: '{{ route("mining-manager.settings.clear-cache") }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success('{{ trans("mining-manager::settings.cache_cleared") }}');
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::settings.error_clearing_cache") }}');
                $('#clearCacheBtn').prop('disabled', false)
                    .html('<i class="fas fa-trash-alt"></i> {{ trans("mining-manager::settings.clear_now") }}');
            }
        });
    });
    
    // Reset settings
    $('#resetSettingsBtn').on('click', function() {
        if (!confirm('{{ trans("mining-manager::settings.confirm_reset") }}')) {
            return;
        }
        
        if (!confirm('{{ trans("mining-manager::settings.confirm_reset_final") }}')) {
            return;
        }
        
        $(this).prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::settings.resetting") }}');
        
        $.ajax({
            url: '{{ route("mining-manager.settings.reset") }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success('{{ trans("mining-manager::settings.settings_reset") }}');
                setTimeout(() => location.reload(), 1500);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::settings.error_resetting") }}');
                $('#resetSettingsBtn').prop('disabled', false)
                    .html('<i class="fas fa-exclamation-triangle"></i> {{ trans("mining-manager::settings.reset_now") }}');
            }
        });
    });
});
</script>

{{-- Webhook Management JavaScript --}}
<script src="{{ asset('vendor/mining-manager/js/webhooks.js') }}?v={{ time() }}"></script>
@endpush
@endsection
