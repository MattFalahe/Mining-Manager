@extends('web::layouts.grids.12')

@section('title', 'Mining Manager - Diagnostic Tools')
@section('page_header', 'Mining Manager - Diagnostic Tools')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}?v=1.0.1">
<style>
/* Diagnostic Page Specific Styles - Inline to override caching issues */
.mining-manager-wrapper.diagnostic-page .warning-box {
    background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 152, 0, 0.05) 100%) !important;
    border-left: 4px solid #ffc107 !important;
    padding: 15px 20px !important;
    border-radius: 8px !important;
    margin-bottom: 20px !important;
    color: #c2c7d0 !important;
}

.mining-manager-wrapper.diagnostic-page .stat-box {
    background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%) !important;
    border: 1px solid #454d55 !important;
    border-radius: 10px !important;
    padding: 20px !important;
    text-align: center !important;
    margin-bottom: 20px !important;
}

.mining-manager-wrapper.diagnostic-page .stat-box .stat-number {
    font-size: 2.5rem !important;
    font-weight: bold !important;
    color: #00d4ff !important;
    margin-bottom: 10px !important;
}

.mining-manager-wrapper.diagnostic-page .stat-box .stat-label {
    font-size: 0.95rem !important;
    color: #8b95a5 !important;
    text-transform: uppercase !important;
    letter-spacing: 1px !important;
}

.mining-manager-wrapper.diagnostic-page .danger-box {
    background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(200, 35, 51, 0.05) 100%) !important;
    border-left: 4px solid #dc3545 !important;
    padding: 15px 20px !important;
    border-radius: 8px !important;
    margin-bottom: 20px !important;
    color: #c2c7d0 !important;
}

/* Provider Test Results Styling */
.mining-manager-wrapper.diagnostic-page .provider-test-result {
    background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%) !important;
    border: 1px solid #454d55 !important;
    border-radius: 10px !important;
    padding: 20px !important;
    margin-top: 20px !important;
    color: #c2c7d0 !important;
}

.mining-manager-wrapper.diagnostic-page .provider-test-result.success {
    border-left: 4px solid #28a745 !important;
    background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(40, 167, 69, 0.05) 100%) !important;
}

.mining-manager-wrapper.diagnostic-page .provider-test-result.warning {
    border-left: 4px solid #ffc107 !important;
    background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 152, 0, 0.05) 100%) !important;
}

.mining-manager-wrapper.diagnostic-page .provider-test-result.error,
.mining-manager-wrapper.diagnostic-page .provider-test-result.danger {
    border-left: 4px solid #dc3545 !important;
    background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(200, 35, 51, 0.05) 100%) !important;
}

.mining-manager-wrapper.diagnostic-page .provider-test-result h5 {
    font-size: 1.3rem !important;
    margin-bottom: 15px !important;
    color: #ffffff !important;
}

.mining-manager-wrapper.diagnostic-page .provider-test-result p {
    margin-bottom: 8px !important;
    color: #c2c7d0 !important;
}

.mining-manager-wrapper.diagnostic-page .provider-test-result strong {
    color: #00d4ff !important;
}

.mining-manager-wrapper.diagnostic-page .price-item {
    display: flex !important;
    justify-content: space-between !important;
    padding: 10px !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05) !important;
}

.mining-manager-wrapper.diagnostic-page .price-item:last-child {
    border-bottom: none !important;
}

/* Alert boxes within test results */
.mining-manager-wrapper.diagnostic-page .provider-test-result .alert {
    color: #ffffff !important;
    background: rgba(0, 0, 0, 0.3) !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
}

.mining-manager-wrapper.diagnostic-page .provider-test-result .alert strong {
    color: #ffffff !important;
}

.mining-manager-wrapper.diagnostic-page .provider-test-result .alert code {
    background: rgba(0, 0, 0, 0.5) !important;
    color: #00d4ff !important;
    padding: 2px 6px !important;
    border-radius: 3px !important;
}

/* Notification Testing Terminal */
.mining-manager-wrapper.diagnostic-page .notification-terminal {
    background: #0d1117 !important;
    border: 1px solid #30363d !important;
    border-radius: 8px !important;
    padding: 16px !important;
    font-family: 'Courier New', 'Consolas', 'Monaco', monospace !important;
    font-size: 13px !important;
    line-height: 1.7 !important;
    max-height: 500px !important;
    min-height: 120px !important;
    overflow-y: auto !important;
    color: #c9d1d9 !important;
}

.mining-manager-wrapper.diagnostic-page .notification-terminal .log-line {
    padding: 1px 0 !important;
    white-space: pre-wrap !important;
    word-break: break-word !important;
    animation: ntFadeIn 0.2s ease-in !important;
}

@keyframes ntFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

.mining-manager-wrapper.diagnostic-page .notification-terminal .log-time {
    color: #8b949e !important;
}

.mining-manager-wrapper.diagnostic-page .notification-terminal .log-level {
    font-weight: bold !important;
    display: inline-block !important;
    min-width: 50px !important;
}

.mining-manager-wrapper.diagnostic-page .notification-terminal .log-info .log-level { color: #58a6ff !important; }
.mining-manager-wrapper.diagnostic-page .notification-terminal .log-ok .log-level { color: #3fb950 !important; }
.mining-manager-wrapper.diagnostic-page .notification-terminal .log-ok { color: #3fb950 !important; }
.mining-manager-wrapper.diagnostic-page .notification-terminal .log-warn .log-level { color: #d29922 !important; }
.mining-manager-wrapper.diagnostic-page .notification-terminal .log-warn { color: #d29922 !important; }
.mining-manager-wrapper.diagnostic-page .notification-terminal .log-error .log-level { color: #f85149 !important; }
.mining-manager-wrapper.diagnostic-page .notification-terminal .log-error { color: #f85149 !important; }
.mining-manager-wrapper.diagnostic-page .notification-terminal .log-skip .log-level { color: #8b949e !important; }
.mining-manager-wrapper.diagnostic-page .notification-terminal .log-skip { color: #8b949e !important; }

.diagnostic-page .nav-tabs .nav-link {
    font-size: 0.95rem !important;
    padding: 10px 16px !important;
}
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper mining-dashboard diagnostic-page">

    {{-- DEV banner — this page is intentionally NOT in the Mining Manager
         sidebar. Admins reach it by manually navigating to
         /mining-manager/diagnostic. Treat as an internal / dev tool, not a
         user-facing feature. The Master Test tab + per-area tabs are safe
         to run in production (read-only); the test-data generation tab
         creates fake corps/characters/mining for testing and SHOULD NOT
         be used on a live install. --}}
    <div class="alert" style="background: linear-gradient(135deg, rgba(220, 53, 69, 0.15) 0%, rgba(220, 53, 69, 0.05) 100%); border-left: 4px solid #dc3545; color: #f8d7da;" role="alert">
        <div class="d-flex align-items-center">
            <span class="badge badge-danger mr-2" style="font-size: 0.85rem; padding: 0.4em 0.7em;">
                <i class="fas fa-flask"></i> DEV
            </span>
            <div>
                <strong>Diagnostic Tools — internal / dev page.</strong>
                This page is intentionally not in the Mining Manager sidebar. Admins reach it by typing
                <code style="background: rgba(0,0,0,0.3); color: #f8d7da; padding: 1px 6px; border-radius: 3px;">/mining-manager/diagnostic</code>
                manually. The <strong>Master Test</strong> tab is safe to run anytime (read-only). The <strong>Test Data Generation</strong> tab creates fake corporations/characters/mining and is intended for development environments only — do not use on a live install.
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    @endif

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    @endif

{{-- TAB NAVIGATION --}}
<div class="card card-dark card-tabs">
    <div class="card-header p-0 pt-1">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link active" href="#master-test" data-toggle="tab">
                    <i class="fas fa-rocket"></i> Master Test
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#test-data" data-toggle="tab">
                    <i class="fas fa-database"></i> Test Data Generation
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#price-provider" data-toggle="tab">
                    <i class="fas fa-dollar-sign"></i> Price Provider Testing
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#cache-health" data-toggle="tab" onclick="loadCacheHealth()">
                    <i class="fas fa-heartbeat"></i> Price Cache Health
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#system-validation" data-toggle="tab">
                    <i class="fas fa-check-circle"></i> System Validation
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#settings-health" data-toggle="tab">
                    <i class="fas fa-cogs"></i> Settings Health
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#tax-trace" data-toggle="tab">
                    <i class="fas fa-calculator"></i> Tax Trace
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#data-integrity" data-toggle="tab">
                    <i class="fas fa-shield-alt"></i> Data Integrity
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#valuation-test" data-toggle="tab">
                    <i class="fas fa-search-dollar"></i> Valuation Test
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#system-status" data-toggle="tab" onclick="loadSystemStatus()">
                    <i class="fas fa-heartbeat"></i> System Status
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#notification-testing" data-toggle="tab">
                    <i class="fas fa-bell"></i> Notification Testing
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#moon-diagnostic" data-toggle="tab">
                    <i class="fas fa-moon"></i> Moon Extractions
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#tax-pipeline" data-toggle="tab">
                    <i class="fas fa-file-invoice-dollar"></i> Tax Pipeline
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#theft-diagnostic" data-toggle="tab">
                    <i class="fas fa-user-secret"></i> Theft Detection
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#event-diagnostic" data-toggle="tab">
                    <i class="fas fa-calendar-alt"></i> Event Lifecycle
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#analytics-diagnostic" data-toggle="tab">
                    <i class="fas fa-chart-bar"></i> Analytics & Reports
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">
      <div class="tab-content">

        {{-- ==========================================================
             Master Test Tab — one-click read-only smoke chain.
             Exercises every major area of the plugin and shows a
             pass/warn/fail/skip table grouped by category.
             ========================================================== --}}
        <div class="tab-pane active" id="master-test">
            <div class="row">
                <div class="col-md-12">
                    <div class="alert alert-info">
                        <h5><i class="fas fa-rocket"></i> What this is</h5>
                        <p class="mb-2">A comprehensive read-only smoke check of every major area of the plugin — schema, settings, cross-plugin integration, pricing, notifications, lifecycle, tax pipeline, and security hardening. Click <em>Run Master Test</em> below to execute the full chain.</p>
                        <p class="mb-0"><strong>Idempotent:</strong> never mutates production data. Safe to run anytime — including in production. Each test takes &lt;1 second; the full chain typically completes in under 30 seconds.</p>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    <button type="button" class="btn btn-lg btn-primary" id="master-test-run-btn">
                        <i class="fas fa-rocket"></i> Run Master Test
                    </button>
                    <span id="master-test-spinner" class="ml-2" style="display:none;">
                        <i class="fas fa-spinner fa-spin"></i> Running tests...
                    </span>
                </div>
            </div>

            {{-- Results panel — populated by JS --}}
            <div id="master-test-results" class="row" style="display:none;">
                <div class="col-md-12">
                    {{-- Summary card --}}
                    <div class="card card-dark mb-3" id="master-test-summary-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-pie"></i> Summary</h3>
                            <div class="card-tools">
                                <span id="master-test-overall-badge" class="badge badge-secondary">—</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-2">
                                    <h4 class="text-success" id="master-test-pass-count">0</h4>
                                    <small>Pass</small>
                                </div>
                                <div class="col-md-2">
                                    <h4 class="text-warning" id="master-test-warn-count">0</h4>
                                    <small>Warn</small>
                                </div>
                                <div class="col-md-2">
                                    <h4 class="text-danger" id="master-test-fail-count">0</h4>
                                    <small>Fail</small>
                                </div>
                                <div class="col-md-2">
                                    <h4 class="text-muted" id="master-test-skip-count">0</h4>
                                    <small>Skip</small>
                                </div>
                                <div class="col-md-2">
                                    <h4 id="master-test-total-count">0</h4>
                                    <small>Total</small>
                                </div>
                                <div class="col-md-2">
                                    <h4 id="master-test-duration">0 ms</h4>
                                    <small>Duration</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Per-test results table --}}
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-list-check"></i> Per-test results</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-outline-light" id="master-test-filter-issues">
                                    <i class="fas fa-filter"></i> Show only issues
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-dark table-striped table-sm mb-0" id="master-test-table">
                                <thead>
                                    <tr>
                                        <th style="width:80px;">Status</th>
                                        <th style="width:140px;">Category</th>
                                        <th>Test</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody id="master-test-table-body">
                                    {{-- Rows injected by JS --}}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Data Generation Tab -->
        <div class="tab-pane" id="test-data">
            <div class="row">
                <div class="col-md-12">
                    <div class="warning-box">
                        <strong><i class="fas fa-exclamation-triangle"></i> Testing Environment Only</strong>
                        <p class="mb-0">These tools generate fake corporations, characters, and mining data for testing multi-corporation tax settings. Use only in development/testing environments.</p>
                    </div>
                </div>
            </div>

            <!-- Current Test Data Stats -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="stat-number">{{ $testDataCounts['corporations'] }}</div>
                        <div class="stat-label">Test Corporations</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="stat-number">{{ $testDataCounts['characters'] }}</div>
                        <div class="stat-label">Test Characters</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="stat-number">{{ $testDataCounts['mining_ledger'] }}</div>
                        <div class="stat-label">Mining Ledger Entries</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="stat-number">{{ $testDataCounts['mining_taxes'] }}</div>
                        <div class="stat-label">Tax Records</div>
                    </div>
                </div>
            </div>

            <!-- Step 1: Generate Test Corporations -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-building"></i> Step 1: Generate Test Corporations
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Create test corporations with IDs starting from 98000001. Each corporation will have a CEO and unique ticker.</p>

                            <form action="{{ route('mining-manager.diagnostic.generate-corporations') }}" method="POST">
                                @csrf
                                <div class="form-group">
                                    <label>Number of Corporations</label>
                                    <input type="number" name="count" class="form-control" value="3" min="1" max="10">
                                    <small class="form-text text-muted">Creates corporations with IDs 98000001, 98000002, etc.</small>
                                </div>
                                <button type="submit" class="btn btn btn-mm-primary">
                                    <i class="fas fa-plus-circle"></i> Generate Corporations
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Generate Test Characters -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-users"></i> Step 2: Generate Test Characters
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Create test characters (miners) for each corporation. Characters will be linked to corporations via character_affiliations.</p>

                            <form action="{{ route('mining-manager.diagnostic.generate-characters') }}" method="POST">
                                @csrf
                                <div class="form-group">
                                    <label>Characters per Corporation</label>
                                    <input type="number" name="characters_per_corp" class="form-control" value="5" min="1" max="20">
                                    <small class="form-text text-muted">Creates miners with IDs starting from 91000001</small>
                                </div>
                                <button type="submit" class="btn btn btn-mm-primary">
                                    <i class="fas fa-user-plus"></i> Generate Characters
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 3: Generate Mining Data -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-gem"></i> Step 3: Generate Mining Data
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Create mining ledger entries for test characters. Generates random ore types (including moon ore) in various systems.</p>

                            <form action="{{ route('mining-manager.diagnostic.generate-mining-data') }}" method="POST">
                                @csrf
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Number of Days</label>
                                            <input type="number" name="days" class="form-control" value="30" min="1" max="90">
                                            <small class="form-text text-muted">Generate data for the last N days</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Entries per Day per Character</label>
                                            <input type="number" name="entries_per_day" class="form-control" value="10" min="1" max="50">
                                            <small class="form-text text-muted">Mining entries per character per day</small>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn btn-mm-primary">
                                    <i class="fas fa-database"></i> Generate Mining Data
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cleanup Section -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-trash-alt"></i> Cleanup Test Data
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="danger-box">
                                <strong><i class="fas fa-exclamation-triangle"></i> Warning</strong>
                                <p class="mb-0">This will permanently delete all test corporations, characters, mining data, tax records, and settings created by this tool.</p>
                            </div>

                            <form action="{{ route('mining-manager.diagnostic.cleanup') }}" method="POST" onsubmit="return confirm('Are you sure you want to delete ALL test data? This cannot be undone!');">
                                @csrf
                                <button type="submit" class="btn btn btn-danger">
                                    <i class="fas fa-trash"></i> Delete All Test Data
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Instructions -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle"></i> Testing Multi-Corporation Settings
                            </h3>
                        </div>
                        <div class="card-body">
                            <ol>
                                <li>Generate test corporations using Step 1</li>
                                <li>Generate test characters for those corporations using Step 2</li>
                                <li>Generate mining data using Step 3</li>
                                <li>Go to <strong>Settings → Configured Corporations</strong> to configure different tax rates for each test corporation</li>
                                <li>Run tax calculations and verify that each corporation uses its own tax settings</li>
                                <li>When done testing, use the Cleanup button to remove all test data</li>
                            </ol>
                            <p class="mb-0"><strong>CLI Alternative:</strong> You can also use the artisan command:</p>
                            <code>php artisan mining-manager:generate-test-data --corporations=3 --characters=5 --days=30 --entries=10</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Price Provider Testing Tab -->
        <div class="tab-pane" id="price-provider" >
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-dollar-sign"></i> Price Provider Testing
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Test different price providers to ensure they are configured correctly and returning prices.</p>

                            <!-- Provider Selection -->
                            <div class="form-group">
                                <label>Select Price Provider to Test</label>
                                <select id="providerSelect" class="form-control" onchange="checkProviderRequirements()">
                                    <option value="seat">SeAT Database (Default)</option>
                                    <option value="janice">Janice API (Requires API Key)</option>
                                    <option value="fuzzwork">Fuzzwork Market</option>
                                    <option value="manager-core"
                                        {{ \MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled() ? '' : 'disabled' }}>
                                        Manager Core {{ \MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled() ? '(Installed)' : '(Not Installed)' }}
                                    </option>
                                </select>
                            </div>

                            <!-- Warning for providers that need configuration -->
                            <div id="providerWarning" class="warning-box" style="display: none;">
                                <strong><i class="fas fa-exclamation-triangle"></i> Configuration Required</strong>
                                <p id="providerWarningText" class="mb-0"></p>
                            </div>

                            <button type="button" class="btn btn btn-mm-primary" onclick="testProvider()">
                                <i class="fas fa-vial"></i> <span id="testBtnText">Test Provider</span>
                                <span id="testSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                            </button>

                            <button type="button" class="btn btn-secondary ml-2" onclick="loadProviderConfig()">
                                <i class="fas fa-cog"></i> View Configuration
                            </button>

                            <button type="button" class="btn btn-secondary ml-2" onclick="testConnectivity()">
                                <i class="fas fa-network-wired"></i> Test Connection
                            </button>

                            <!-- Test Results Container -->
                            <div id="testResults"></div>

                            <!-- Configuration Display -->
                            <div id="configDisplay" style="display: none; margin-top: 20px;">
                                <h5>Current Configuration</h5>
                                <div class="provider-test-result" id="configContent"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Batch Testing -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-list"></i> Batch Price Testing
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Test price fetching for multiple ore types at once to check performance and accuracy.</p>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Provider</label>
                                        <select id="batchProvider" class="form-control">
                                            <option value="seat">SeAT Database</option>
                                            <option value="janice">Janice API</option>
                                            <option value="fuzzwork">Fuzzwork Market</option>
                                            @if(\MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled())
                                            <option value="manager-core">Manager Core</option>
                                            @endif
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Ore Category</label>
                                        <select id="batchCategory" class="form-control">
                                            <option value="all">Mixed (Sample)</option>
                                            <option value="ore">Regular Ore</option>
                                            <option value="moon">Moon Ore (Raw Rocks)</option>
                                            <option value="moon-materials">Moon Materials (Refined)</option>
                                            <option value="ice">Ice</option>
                                            <option value="gas">Gas</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn btn-mm-primary" onclick="testBatchPricing()">
                                <i class="fas fa-list-check"></i> <span id="batchBtnText">Run Batch Test</span>
                                <span id="batchSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                            </button>

                            <div id="batchResults"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Price Cache Health Tab -->
        <div class="tab-pane" id="cache-health" >
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-heartbeat"></i> Price Cache Health Status
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Monitor the health of your price cache. The cache stores prices locally for fast tax calculations.</p>

                            <button type="button" class="btn btn btn-mm-primary" onclick="loadCacheHealth()">
                                <i class="fas fa-sync"></i> <span id="healthBtnText">Check Health</span>
                                <span id="healthSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                            </button>

                            <div id="cacheHealthResults"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cache Warming -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-fire"></i> Warm Cache (Quick Fix)
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Quickly populate the cache with prices from your configured provider. Use this if cache is empty or stale.</p>

                            <div class="form-group">
                                <label>Select Category to Cache</label>
                                <select id="warmCategory" class="form-control">
                                    <option value="essential">Essential Only (Fast - Minerals + Common Ores)</option>
                                    <option value="ore">Regular Ore</option>
                                    <option value="moon">Moon Ore</option>
                                    <option value="ice">Ice Products</option>
                                    <option value="gas">Gas</option>
                                    <option value="all">All Types (Slow - May take several minutes)</option>
                                </select>
                            </div>

                            <button type="button" class="btn btn btn-mm-primary" onclick="warmCache()">
                                <i class="fas fa-fire"></i> <span id="warmBtnText">Warm Cache</span>
                                <span id="warmSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                            </button>

                            <div id="warmResults"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Manual Cache Command -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-terminal"></i> Manual Cache Management
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>For best results, use the artisan command which supports more options:</p>
                            <code>php artisan mining-manager:cache-prices --type=all</code>
                            <br><br>
                            <p><strong>Options:</strong></p>
                            <ul>
                                <li><code>--type=ore</code> - Cache regular ore prices</li>
                                <li><code>--type=moon</code> - Cache moon ore prices</li>
                                <li><code>--type=ice</code> - Cache ice product prices</li>
                                <li><code>--type=minerals</code> - Cache mineral prices</li>
                                <li><code>--type=all</code> - Cache all types</li>
                                <li><code>--force</code> - Force refresh even if cache is fresh</li>
                            </ul>
                            <p><strong>Recommendation:</strong> Set up a cron job to refresh prices every hour:</p>
                            <code>0 * * * * php /path/to/artisan mining-manager:cache-prices --type=all</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Validation Tab -->
        <div class="tab-pane" id="system-validation" >
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-check-circle"></i> Type ID Registry Validation
                            </h3>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Validate that all type IDs in TypeIdRegistry exist in the local SeAT database (invTypes table).</p>

                            <div class="form-group">
                                <label>Select Category</label>
                                <select id="validateCategory" class="form-control">
                                    <option value="refined-materials">All Refined Materials (35 items) - Moon + Minerals + Ice Products</option>
                                    <option value="materials">Moon Materials Only (20 items)</option>
                                    <option value="minerals">Minerals Only (8 items)</option>
                                    <option value="ice-products">Ice Products Only (7 items)</option>
                                    <option value="moon">Moon Ores (60 items)</option>
                                    <option value="ore">Regular Ores (48 items)</option>
                                    <option value="compressed-ore">Compressed Ores (63 items)</option>
                                    <option value="ice">Ice (20 items)</option>
                                    <option value="gas">Gas (12 items)</option>
                                    <option value="new-ores">New Ores YC124-YC126 (72 items)</option>
                                    <option value="abyssal">Abyssal Ores (10 items)</option>
                                    <option value="triglavian">Triglavian Ores (9 items)</option>
                                    <option value="all">All Categories</option>
                                </select>
                            </div>

                            <button class="btn btn btn-mm-primary" onclick="validateTypeIds()">
                                <span id="validate-btn-text">Validate Type IDs</span>
                                <span id="validate-spinner" class="spinner-border spinner-border-sm" role="status" style="display:none;"></span>
                            </button>

                            <div id="validate-results" style="margin-top: 20px;"></div>
                        </div>
                    </div>

                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-terminal"></i> Console Commands
                            </h3>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Run these commands via SSH for advanced diagnostics:</p>

                            <div class="alert alert-info">
                                <strong><i class="fas fa-info-circle"></i> Quick Validation (Refined Materials)</strong>
                                <pre class="mb-0" style="background: rgba(0,0,0,0.2); padding: 10px; border-radius: 5px; margin-top: 10px;">php artisan mining-manager:diagnose-type-ids --category=refined-materials --verify-db</pre>
                            </div>

                            <div class="alert alert-info">
                                <strong><i class="fas fa-database"></i> Full Database Validation</strong>
                                <pre class="mb-0" style="background: rgba(0,0,0,0.2); padding: 10px; border-radius: 5px; margin-top: 10px;">php artisan mining-manager:diagnose-type-ids --verify-db</pre>
                            </div>

                            <div class="alert alert-info">
                                <strong><i class="fas fa-globe"></i> ESI API Validation (slower, requires internet)</strong>
                                <pre class="mb-0" style="background: rgba(0,0,0,0.2); padding: 10px; border-radius: 5px; margin-top: 10px;">php artisan mining-manager:diagnose-type-ids --category=moon</pre>
                            </div>

                            <div class="alert alert-warning">
                                <strong><i class="fas fa-flask"></i> With Jackpot Detection Tests</strong>
                                <pre class="mb-0" style="background: rgba(0,0,0,0.2); padding: 10px; border-radius: 5px; margin-top: 10px;">php artisan mining-manager:diagnose-type-ids --verify-db --test-jackpot</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Health Tab -->
        <div class="tab-pane" id="settings-health">
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-cogs"></i> Settings Health Check
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>View all plugin settings, their sources (database, config, or default), and detect any issues like orphaned corporation settings.</p>

                            <button type="button" class="btn btn-mm-primary" onclick="loadSettingsHealth()">
                                <i class="fas fa-stethoscope"></i> <span id="settingsHealthBtnText">Run Health Check</span>
                                <span id="settingsHealthSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                            </button>

                            <div id="settingsHealthResults" style="margin-top: 20px;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tax Trace Tab -->
        <div class="tab-pane" id="tax-trace">
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-calculator"></i> Tax Calculation Trace
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Full diagnostic: stored daily summaries, live recalculation with current prices, account/bill info, and mismatch detection.</p>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Character ID</label>
                                        <input type="number" id="taxTraceCharId" class="form-control" placeholder="e.g. 90000001">
                                        <small class="form-text text-muted">Enter the character_id to trace (can be main or alt)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Month (YYYY-MM)</label>
                                        <input type="month" id="taxTraceMonth" class="form-control" value="{{ \Carbon\Carbon::now()->subMonth()->format('Y-m') }}">
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-mm-primary" onclick="runTaxTrace()">
                                <i class="fas fa-search"></i> <span id="taxTraceBtnText">Trace Tax Calculation</span>
                                <span id="taxTraceSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                            </button>

                            <div id="taxTraceResults" style="margin-top: 20px;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Integrity Tab -->
        <div class="tab-pane" id="data-integrity">
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-shield-alt"></i> Data Integrity Scan
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Scan your mining data for problems: unknown type IDs, zero quantities, orphaned records, duplicate entries, corrupt settings, and more.</p>

                            <button type="button" class="btn btn-mm-primary" onclick="runDataIntegrity()">
                                <i class="fas fa-search"></i> <span id="integrityBtnText">Run Integrity Scan</span>
                                <span id="integritySpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                            </button>

                            <div id="integrityResults" style="margin-top: 20px;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Valuation Test Tab -->
        <div class="tab-pane" id="valuation-test">
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-search-dollar"></i> Ore Valuation Test
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Enter an ore type ID and quantity to see step-by-step how the plugin values it: settings loaded, price fetched, tax rate applied, and final value.</p>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Type ID</label>
                                        <input type="number" id="valuationTypeId" class="form-control" placeholder="e.g. 45506 (Xenotime)">
                                        <small class="form-text text-muted">Common IDs: 34=Tritanium, 1230=Veldspar, 45506=Xenotime, 16262=Clear Icicle</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Quantity</label>
                                        <input type="number" id="valuationQuantity" class="form-control" value="1000" min="1">
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-mm-primary" onclick="runValuationTest()">
                                <i class="fas fa-calculator"></i> <span id="valuationBtnText">Test Valuation</span>
                                <span id="valuationSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                            </button>

                            <div id="valuationResults" style="margin-top: 20px;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Status Tab -->
        <div class="tab-pane" id="system-status">
            <div id="system-status-loading" class="text-center py-5">
                <p class="text-muted"><i class="fas fa-heartbeat"></i> Click the tab to load system status...</p>
            </div>
            <div id="system-status-content" style="display: none;">

                {{-- Daily Summaries --}}
                <div class="card card-dark mb-3">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-calendar-day"></i> Daily Summary Status</h3></div>
                    <div class="card-body" id="ss-daily-summaries"></div>
                </div>

                {{-- Multi-Corp Settings --}}
                <div class="card card-dark mb-3">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-building"></i> Multi-Corporation Settings</h3></div>
                    <div class="card-body" id="ss-multi-corp"></div>
                </div>

                {{-- Price Cache --}}
                <div class="card card-dark mb-3">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-tags"></i> Price Cache Freshness</h3></div>
                    <div class="card-body" id="ss-price-cache"></div>
                </div>

                {{-- Scheduled Jobs --}}
                <div class="card card-dark mb-3">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-clock"></i> Scheduled Jobs Last Activity</h3></div>
                    <div class="card-body" id="ss-jobs"></div>
                </div>

                {{-- Data Counts --}}
                <div class="card card-dark mb-3">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-database"></i> Data Counts</h3></div>
                    <div class="card-body" id="ss-data-counts"></div>
                </div>
            </div>
        </div>

        <!-- Notification Testing Tab -->
        <div class="tab-pane" id="notification-testing">
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <h3 class="card-title">
                                <i class="fas fa-bell"></i> Notification Testing & Diagnostics
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Test all notification types across all channels. EVE Mail runs as a dry-run (no ESI call), Discord and Slack send real messages.</p>

                            <div class="row">
                                <!-- Left Column: Configuration -->
                                <div class="col-md-6">

                                    <!-- Notification Type -->
                                    <div class="form-group">
                                        <label><i class="fas fa-tag"></i> Notification Type</label>
                                        <select id="ntNotificationType" class="form-control" onchange="updateNotifTestFields()">
                                            <optgroup label="Tax Notifications">
                                                <option value="tax_generated">📋 Mining Taxes Summary (General)</option>
                                                <option value="tax_announcement">📢 New Invoices Announcement (General)</option>
                                                <option value="tax_reminder">⏰ Tax Payment Reminder (Individual)</option>
                                                <option value="tax_invoice">📧 Tax Invoice Created (Individual)</option>
                                                <option value="tax_overdue">❌ Tax Payment Overdue (Individual)</option>
                                            </optgroup>
                                            <optgroup label="Event Notifications">
                                                <option value="event_created">📅 Mining Event Created</option>
                                                <option value="event_started">🚀 Mining Event Started</option>
                                                <option value="event_completed">🏁 Mining Event Completed</option>
                                            </optgroup>
                                            <optgroup label="Moon Notifications">
                                                <option value="moon_ready">🌙 Moon Chunk Ready</option>
                                                <option value="jackpot_detected">🎰 Jackpot Detected</option>
                                                <option value="moon_chunk_unstable">⚠️ Moon Chunk Unstable (capital safety)</option>
                                                <option value="extraction_at_risk">🔥 Extraction at Risk (cross-plugin — MC+SM)</option>
                                                <option value="extraction_lost">☠️ Extraction Lost (cross-plugin — MC+SM)</option>
                                            </optgroup>
                                            <optgroup label="Theft Detection">
                                                <option value="theft_detected">⚠️ Theft Detected</option>
                                                <option value="critical_theft">🔴 Critical Theft</option>
                                                <option value="active_theft">🔥 Active Theft in Progress</option>
                                                <option value="incident_resolved">✅ Incident Resolved</option>
                                            </optgroup>
                                            <optgroup label="Reports">
                                                <option value="report_generated">📊 Report Generated</option>
                                            </optgroup>
                                        </select>
                                    </div>

                                    <!-- Channels -->
                                    <div class="form-group">
                                        <label><i class="fas fa-broadcast-tower"></i> Channels</label>
                                        <div class="d-flex flex-wrap" style="gap: 15px;">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="ntChannelEsi" value="esi">
                                                <label class="custom-control-label" for="ntChannelEsi">
                                                    <i class="fas fa-envelope"></i> EVE Mail (dry run)
                                                </label>
                                            </div>
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="ntChannelDiscord" value="discord" checked>
                                                <label class="custom-control-label" for="ntChannelDiscord">
                                                    <i class="fab fa-discord"></i> Discord
                                                </label>
                                            </div>
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="ntChannelSlack" value="slack">
                                                <label class="custom-control-label" for="ntChannelSlack">
                                                    <i class="fab fa-slack"></i> Slack
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Target Character Selection -->
                                    <div class="form-group">
                                        <label>
                                            <i class="fas fa-user"></i> Target Character <small class="text-muted">(receives notification)</small>
                                            <button type="button" class="btn btn-xs btn-outline-info ml-2" onclick="toggleNtCharInput()">
                                                <i class="fas fa-exchange-alt"></i> <span id="ntCharToggleText">Enter ID</span>
                                            </button>
                                        </label>
                                        <div id="ntCharDropdown">
                                            <select id="ntCharacterSelect" class="form-control">
                                                <option value="">-- Select Character --</option>
                                                @foreach($seatCharacters as $char)
                                                    <option value="{{ $char->character_id }}">{{ $char->name }} ({{ $char->character_id }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div id="ntCharManual" style="display:none;">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <input type="number" id="ntCharacterId" class="form-control" placeholder="Character ID">
                                                </div>
                                                <div class="col-md-6">
                                                    <input type="text" id="ntCharacterName" class="form-control" placeholder="Character Name" value="Test Character">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- EVE Mail Sender (shown when ESI channel is checked) -->
                                    <div class="form-group" id="ntSenderSection" style="display:none;">
                                        <label><i class="fas fa-paper-plane"></i> EVE Mail Sender <small class="text-muted">(sends the mail)</small></label>
                                        <div class="d-flex mb-2" style="gap: 10px;">
                                            <div class="custom-control custom-radio">
                                                <input type="radio" class="custom-control-input" id="ntSenderSettings" name="ntSenderMode" value="settings" checked>
                                                <label class="custom-control-label" for="ntSenderSettings">
                                                    <i class="fas fa-cog"></i> From Settings
                                                </label>
                                            </div>
                                            <div class="custom-control custom-radio">
                                                <input type="radio" class="custom-control-input" id="ntSenderCharacter" name="ntSenderMode" value="character">
                                                <label class="custom-control-label" for="ntSenderCharacter">
                                                    <i class="fas fa-user"></i> Character
                                                </label>
                                            </div>
                                        </div>
                                        <div id="ntSenderSettingsInfo" class="small text-muted mb-1">
                                            Uses the sender configured in Settings &gt; Notifications.
                                        </div>
                                        <div id="ntSenderCharSelect" style="display:none;">
                                            <select id="ntSenderCharacterId" class="form-control">
                                                <option value="">-- Select Sender Character --</option>
                                                @foreach($seatCharacters as $char)
                                                    <option value="{{ $char->character_id }}">
                                                        {{ $char->name }} ({{ $char->character_id }})
                                                        {{ $char->has_mail_scope ? '✓ mail' : '⚠ no mail scope' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right Column: Webhook & Test Data -->
                                <div class="col-md-6">

                                    <!-- Webhook Selection (Discord) -->
                                    <div class="form-group">
                                        <label>
                                            <i class="fab fa-discord"></i> Discord Webhook
                                            <button type="button" class="btn btn-xs btn-outline-info ml-2" onclick="toggleNtWebhookInput()">
                                                <i class="fas fa-exchange-alt"></i> <span id="ntWebhookToggleText">Custom URL</span>
                                            </button>
                                        </label>
                                        <div id="ntWebhookDropdown">
                                            <select id="ntWebhookSelect" class="form-control">
                                                <option value="">-- Auto-select (first enabled) --</option>
                                                @foreach($webhooks->where('type', 'discord') as $wh)
                                                    <option value="{{ $wh->id }}">
                                                        {{ $wh->name }} {{ $wh->is_enabled ? '(Enabled)' : '(Disabled)' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div id="ntWebhookManual" style="display:none;">
                                            <input type="url" id="ntCustomWebhookUrl" class="form-control" placeholder="https://discord.com/api/webhooks/...">
                                        </div>
                                    </div>

                                    <!-- Discord Ping Test -->
                                    <div class="form-group" id="ntPingSection">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="ntTestPing"
                                                   {{ ($notificationSettings['discord_pinging_enabled'] ?? false) ? 'checked' : '' }}>
                                            <label class="custom-control-label" for="ntTestPing">
                                                <i class="fas fa-at text-info"></i> Test Discord Ping
                                            </label>
                                            <small class="form-text text-muted">
                                                Mention target character in Discord (requires seat-connector).
                                                {{ ($notificationSettings['discord_pinging_enabled'] ?? false) ? '(Currently enabled in settings)' : '(Currently disabled in settings — check to test anyway)' }}
                                            </small>
                                        </div>
                                    </div>

                                    <!-- Slack URL Override -->
                                    <div class="form-group">
                                        <label><i class="fab fa-slack"></i> Slack Webhook URL (optional override)</label>
                                        <input type="url" id="ntCustomSlackUrl" class="form-control" placeholder="Leave empty to use configured URL">
                                    </div>

                                    <!-- Test Data -->
                                    <div class="card bg-secondary" id="ntTestDataCard">
                                        <div class="card-header py-2">
                                            <h6 class="card-title mb-0"><i class="fas fa-edit"></i> Test Data</h6>
                                        </div>
                                        <div class="card-body py-2">
                                            <!-- Tax fields -->
                                            <div id="ntTaxFields">
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Amount (ISK)</label>
                                                    <input type="number" id="ntAmount" class="form-control form-control-sm" value="5000000" min="0" step="100000">
                                                </div>
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Due Date</label>
                                                    <input type="date" id="ntDueDate" class="form-control form-control-sm" value="{{ now()->addDays(7)->format('Y-m-d') }}">
                                                </div>
                                                <div class="form-group mb-2" id="ntDaysRemainingGroup">
                                                    <label class="small mb-1">Days Remaining</label>
                                                    <input type="number" id="ntDaysRemaining" class="form-control form-control-sm" value="7" min="0">
                                                </div>
                                                <div class="form-group mb-2" id="ntDaysOverdueGroup" style="display:none;">
                                                    <label class="small mb-1">Days Overdue</label>
                                                    <input type="number" id="ntDaysOverdue" class="form-control form-control-sm" value="3" min="0">
                                                </div>
                                            </div>
                                            <!-- Event fields -->
                                            <div id="ntEventFields" style="display:none;">
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Event Name</label>
                                                    <input type="text" id="ntEventName" class="form-control form-control-sm" value="Test Mining Event">
                                                </div>
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Location</label>
                                                    <input type="text" id="ntLocation" class="form-control form-control-sm" value="Jita">
                                                </div>
                                            </div>
                                            <!-- Moon fields -->
                                            <div id="ntMoonFields" style="display:none;">
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Structure Name</label>
                                                    <input type="text" id="ntStructureName" class="form-control form-control-sm" value="Athanor - Test Moon">
                                                </div>
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Moon Name</label>
                                                    <input type="text" id="ntMoonName" class="form-control form-control-sm" value="Perimeter I - Moon 1">
                                                </div>
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Structure ID</label>
                                                    <input type="number" id="ntStructureId" class="form-control form-control-sm" value="1000000000001">
                                                </div>
                                                {{-- Flavor selector — only shown for extraction_at_risk (4 flavors) --}}
                                                <div class="form-group mb-2" id="ntExtractionFlavorGroup" style="display:none;">
                                                    <label class="small mb-1">Threat Flavor</label>
                                                    <select id="ntExtractionFlavor" class="form-control form-control-sm">
                                                        <option value="fuel_critical">🔥 Fuel Critical (MOON CHUNK COMPROMISED)</option>
                                                        <option value="shield_reinforced">⚠️ Shield Reinforced (EXTRACTION IN DANGER)</option>
                                                        <option value="armor_reinforced">🚨 Armor Reinforced (EXTRACTION IN DANGER)</option>
                                                        <option value="hull_reinforced">💀 Hull Reinforced (MOON CHUNK DESTABILISED)</option>
                                                    </select>
                                                    <small class="form-text text-muted">Picks which flavor of extraction_at_risk to preview. Each flavor has its own Discord title + color + description.</small>
                                                </div>
                                            </div>
                                            <!-- Theft fields -->
                                            <div id="ntTheftFields" style="display:none;">
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Severity</label>
                                                    <select id="ntSeverity" class="form-control form-control-sm">
                                                        <option value="low">Low</option>
                                                        <option value="medium" selected>Medium</option>
                                                        <option value="high">High</option>
                                                        <option value="critical">Critical</option>
                                                    </select>
                                                </div>
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Ore Value (ISK)</label>
                                                    <input type="number" id="ntOreValue" class="form-control form-control-sm" value="50000000" min="0" step="1000000">
                                                </div>
                                                <div class="form-group mb-2">
                                                    <label class="small mb-1">Tax Owed (ISK)</label>
                                                    <input type="number" id="ntTaxOwed" class="form-control form-control-sm" value="5000000" min="0" step="100000">
                                                </div>
                                                <div class="form-group mb-2" id="ntActiveTheftFields" style="display:none;">
                                                    <label class="small mb-1">Activity Count</label>
                                                    <input type="number" id="ntActivityCount" class="form-control form-control-sm" value="3" min="1">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="mt-3 mb-3">
                                <button type="button" class="btn btn-mm-primary" onclick="runNotificationTest()">
                                    <i class="fas fa-play"></i> <span id="ntRunBtnText">Preview Test</span>
                                    <span id="ntRunSpinner" class="spinner-border spinner-border-sm ml-2" style="display:none;"></span>
                                </button>
                                <button type="button" class="btn btn-warning ml-2" onclick="runNotificationLiveFire()" title="Fire a REAL notification through the full NotificationService pipeline — hits every subscribed, enabled webhook (not just the selected one). Respects per-type toggles and corp scoping. Use to verify the end-to-end pipeline works without waiting for a natural trigger.">
                                    <i class="fas fa-bolt"></i> <span id="ntFireBtnText">Fire Live Notification</span>
                                    <span id="ntFireSpinner" class="spinner-border spinner-border-sm ml-2" style="display:none;"></span>
                                </button>
                                <button type="button" class="btn btn-danger ml-2" onclick="runFireAllNotifications()" title="Fire every notification type (all 18) sequentially through the full pipeline. 1.5s delay between each so Discord rate limits stay happy. Useful as a post-deploy end-to-end smoke test — every subscribed webhook will receive 18 messages in quick succession, so only run this when you want to QA the whole surface.">
                                    <i class="fas fa-rocket"></i> <span id="ntFireAllBtnText">Fire ALL (Chain)</span>
                                    <span id="ntFireAllSpinner" class="spinner-border spinner-border-sm ml-2" style="display:none;"></span>
                                </button>
                                <button type="button" class="btn btn-secondary ml-2" onclick="clearNotificationLog()">
                                    <i class="fas fa-trash-alt"></i> Clear Log
                                </button>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <strong>Preview Test:</strong> renders + POSTs to one webhook (selected or custom URL). Best for checking embed layout + wiring.<br>
                                        <strong>Fire Live Notification:</strong> routes through NotificationService wrappers. Fires the selected type to ALL subscribed webhooks with scope + type toggles applied. Writes to the audit log.<br>
                                        <strong>Fire ALL (Chain):</strong> end-to-end QA pass — fires every notification type one after another with a 1.5s delay between each. Every subscribed webhook receives 15 messages. Use after a deploy to verify nothing regressed.
                                    </small>
                                </div>
                            </div>

                            <!-- Terminal Log View -->
                            <div class="notification-terminal" id="ntTerminal">
                                <div class="log-line log-skip">[--:--:--.---] [INFO] Ready. "Preview Test" sends to one webhook. "Fire Live Notification" dispatches through the real pipeline. "Fire ALL" chains through all 15 types.</div>
                            </div>

                            <!-- Summary Card -->
                            <div id="ntSummary" class="mt-3" style="display:none;">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="stat-box">
                                            <div class="stat-number" id="ntSumChannels">0</div>
                                            <div class="stat-label">Channels Tested</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="stat-box">
                                            <div class="stat-number" id="ntSumSent" style="color: #3fb950 !important;">0</div>
                                            <div class="stat-label">Sent</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="stat-box">
                                            <div class="stat-number" id="ntSumFailed" style="color: #f85149 !important;">0</div>
                                            <div class="stat-label">Failed</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="stat-box">
                                            <div class="stat-number" id="ntSumSkipped" style="color: #8b949e !important;">0</div>
                                            <div class="stat-label">Skipped</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Moon Extraction Diagnostic Tab --}}
        <div class="tab-pane" id="moon-diagnostic">
            <div class="row"><div class="col-md-12">
                <div class="card card-dark">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-moon"></i> Moon Extraction Diagnostic</h3></div>
                    <div class="card-body">
                        <p>Test fracture detection, status transitions, jackpot detection, and value calculations for moon extractions.</p>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Extraction ID</label>
                                    <input type="number" id="moonDiagExtractionId" class="form-control" placeholder="e.g. 42">
                                    <small class="form-text text-muted">Enter a specific extraction ID, or use Structure ID below</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Structure ID (shows last 5 extractions)</label>
                                    <input type="number" id="moonDiagStructureId" class="form-control" placeholder="e.g. 1035466617946">
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-mm-primary" onclick="runMoonDiagnostic()">
                            <i class="fas fa-search"></i> <span id="moonDiagBtnText">Run Moon Diagnostic</span>
                            <span id="moonDiagSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                        </button>
                        <div id="moonDiagResults" style="margin-top: 20px;"></div>
                    </div>
                </div>
            </div></div>
        </div>

        {{-- Tax Pipeline Diagnostic Tab --}}
        <div class="tab-pane" id="tax-pipeline">
            <div class="row"><div class="col-md-12">
                <div class="card card-dark">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-file-invoice-dollar"></i> Tax Pipeline Diagnostic</h3></div>
                    <div class="card-body">
                        <p>Test the full tax pipeline: daily summaries → tax calculation → codes → payment matching → overdue detection.</p>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Month</label>
                                    <input type="month" id="taxPipeMonth" class="form-control" value="{{ \Carbon\Carbon::now()->subMonth()->format('Y-m') }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Character ID (optional)</label>
                                    <input type="number" id="taxPipeCharId" class="form-control" placeholder="For per-character calculation">
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-mm-primary" onclick="runTaxPipeline()">
                            <i class="fas fa-search"></i> <span id="taxPipeBtnText">Run Pipeline Check</span>
                            <span id="taxPipeSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                        </button>
                        <div id="taxPipeResults" style="margin-top: 20px;"></div>
                    </div>
                </div>
            </div></div>
        </div>

        {{-- Theft Detection Diagnostic Tab --}}
        <div class="tab-pane" id="theft-diagnostic">
            <div class="row"><div class="col-md-12">
                <div class="card card-dark">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-user-secret"></i> Theft Detection Diagnostic</h3></div>
                    <div class="card-body">
                        <p>Test theft detection logic, external miner identification, and severity calculations. Leave Character ID empty for overall statistics.</p>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Character ID (optional)</label>
                                    <input type="number" id="theftDiagCharId" class="form-control" placeholder="Analyze specific character">
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-mm-primary" onclick="runTheftDiagnostic()">
                            <i class="fas fa-search"></i> <span id="theftDiagBtnText">Run Theft Detection</span>
                            <span id="theftDiagSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                        </button>
                        <div id="theftDiagResults" style="margin-top: 20px;"></div>
                    </div>
                </div>
            </div></div>
        </div>

        {{-- Event Lifecycle Diagnostic Tab --}}
        <div class="tab-pane" id="event-diagnostic">
            <div class="row"><div class="col-md-12">
                <div class="card card-dark">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-calendar-alt"></i> Event Lifecycle Diagnostic</h3></div>
                    <div class="card-body">
                        <p>Test event management, participant tracking, and progress calculations. Leave Event ID empty for an overview of all events.</p>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Event ID (optional)</label>
                                    <input type="number" id="eventDiagId" class="form-control" placeholder="Analyze specific event">
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-mm-primary" onclick="runEventDiagnostic()">
                            <i class="fas fa-search"></i> <span id="eventDiagBtnText">Run Event Diagnostic</span>
                            <span id="eventDiagSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                        </button>
                        <div id="eventDiagResults" style="margin-top: 20px;"></div>
                    </div>
                </div>
            </div></div>
        </div>

        {{-- Analytics & Reports Diagnostic Tab --}}
        <div class="tab-pane" id="analytics-diagnostic">
            <div class="row"><div class="col-md-12">
                <div class="card card-dark">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-bar"></i> Analytics & Reports Diagnostic</h3></div>
                    <div class="card-body">
                        <p>Test dashboard metrics, mining analytics, and report generation.</p>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Start Date</label>
                                    <input type="date" id="analyticsDiagStart" class="form-control" value="{{ \Carbon\Carbon::now()->subDays(30)->format('Y-m-d') }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>End Date</label>
                                    <input type="date" id="analyticsDiagEnd" class="form-control" value="{{ \Carbon\Carbon::now()->format('Y-m-d') }}">
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-mm-primary" onclick="runAnalyticsDiagnostic()">
                            <i class="fas fa-search"></i> <span id="analyticsDiagBtnText">Run Analytics Check</span>
                            <span id="analyticsDiagSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                        </button>
                        <div id="analyticsDiagResults" style="margin-top: 20px;"></div>
                    </div>
                </div>
            </div></div>
        </div>

      </div>{{-- /.tab-content --}}
    </div>{{-- /.card-body --}}
</div>{{-- /.card-tabs --}}

</div>{{-- /.mining-manager-wrapper --}}

@push('javascript')
<script>
// Initialize tab switching
$(document).ready(function() {
    // Make tabs work with Bootstrap
    $('.nav-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        console.log('Tab switched to:', $(e.target).attr('href'));
    });

    // Handle tab clicks
    $('.nav-tabs a[data-toggle="tab"]').on('click', function(e) {
        e.preventDefault();

        // Remove active from all tabs and tab-panes
        $('.nav-tabs li').removeClass('active');
        $('.tab-pane').removeClass('active');

        // Add active to clicked tab
        $(this).parent('li').addClass('active');

        // Add active to corresponding tab-pane
        const target = $(this).attr('href');
        $(target).addClass('active');

        // Trigger tab-specific functions if needed
        if ($(this).attr('onclick')) {
            eval($(this).attr('onclick'));
        }
    });

    // ===========================================================
    // MASTER TEST — one-click smoke chain runner
    // ===========================================================
    //
    // Pure relative URL — fetch() resolves it against the current
    // document's origin automatically. Three reasons for this rather
    // than route('...') or config('app.url'):
    //
    //   1. route('mining-manager.diagnostic.master-test') at blade-
    //      render time throws RouteNotFoundException if the route
    //      cache is stale after a deploy that added the route.
    //      That throw surfaces as 5xx (or 404 in some host configs),
    //      breaking the entire diagnostic page render — not just
    //      this button. Hardcoding the URL keeps the page renderable
    //      when the route cache is stale; the AJAX call itself will
    //      still 404 until the cache clears, but the rest of the
    //      diagnostic tabs remain accessible.
    //
    //   2. config('app.url') defaults to 'http://localhost' if the
    //      operator hasn't set APP_URL in their .env. A user on
    //      https://their-domain.example would then trigger a mixed-
    //      content block by Chrome (https page fetching http URL)
    //      and the fetch rejects with "Failed to fetch" before
    //      reaching the server. Pure relative URL dodges this
    //      entirely.
    //
    //   3. The diagnostic prefix is locked at /mining-manager/
    //      diagnostic/ in src/Http/routes.php and isn't going to
    //      move, so hardcoding is safe.
    const masterTestUrl = '/mining-manager/diagnostic/master-test';
    const masterTestBtn = document.getElementById('master-test-run-btn');
    const masterTestSpinner = document.getElementById('master-test-spinner');
    const masterTestResults = document.getElementById('master-test-results');
    const masterTestTableBody = document.getElementById('master-test-table-body');
    const masterTestFilterBtn = document.getElementById('master-test-filter-issues');

    let masterTestLastReport = null;
    let masterTestShowOnlyIssues = false;

    function masterTestStatusBadge(status) {
        const map = {
            pass: { cls: 'badge-success', icon: 'check', label: 'PASS' },
            warn: { cls: 'badge-warning', icon: 'exclamation-triangle', label: 'WARN' },
            fail: { cls: 'badge-danger', icon: 'times', label: 'FAIL' },
            skip: { cls: 'badge-secondary', icon: 'minus', label: 'SKIP' },
        };
        const m = map[status] || map.skip;
        return `<span class="badge ${m.cls}"><i class="fas fa-${m.icon}"></i> ${m.label}</span>`;
    }

    function masterTestEscape(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function masterTestRenderDetail(detail) {
        if (detail === null || detail === undefined) return '';
        if (typeof detail === 'string') {
            return `<pre class="mb-0 mt-1 small text-muted" style="white-space:pre-wrap;">${masterTestEscape(detail)}</pre>`;
        }
        // Object/array — pretty-print as JSON.
        try {
            return `<pre class="mb-0 mt-1 small text-muted" style="white-space:pre-wrap;">${masterTestEscape(JSON.stringify(detail, null, 2))}</pre>`;
        } catch (e) {
            return '';
        }
    }

    function masterTestRenderRows() {
        if (!masterTestLastReport) return;
        const rows = masterTestLastReport.results || [];
        const filtered = masterTestShowOnlyIssues
            ? rows.filter(r => r.status === 'warn' || r.status === 'fail')
            : rows;

        if (filtered.length === 0) {
            masterTestTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No rows to display.</td></tr>';
            return;
        }

        const html = filtered.map((r, idx) => {
            const detailHtml = masterTestRenderDetail(r.detail);
            const detailRow = detailHtml
                ? `<tr><td colspan="4" class="bg-dark">${detailHtml}</td></tr>`
                : '';
            return `
                <tr class="master-test-row-${r.status}">
                    <td>${masterTestStatusBadge(r.status)}</td>
                    <td><span class="badge badge-info">${masterTestEscape(r.category)}</span></td>
                    <td><strong>${masterTestEscape(r.name)}</strong></td>
                    <td>${masterTestEscape(r.message)}</td>
                </tr>
                ${detailRow}
            `;
        }).join('');
        masterTestTableBody.innerHTML = html;
    }

    function masterTestRender(report) {
        masterTestLastReport = report;

        // Summary
        document.getElementById('master-test-pass-count').textContent = report.summary.pass;
        document.getElementById('master-test-warn-count').textContent = report.summary.warn;
        document.getElementById('master-test-fail-count').textContent = report.summary.fail;
        document.getElementById('master-test-skip-count').textContent = report.summary.skip;
        document.getElementById('master-test-total-count').textContent = report.summary.total;
        document.getElementById('master-test-duration').textContent = report.duration_ms + ' ms';

        const overallBadge = document.getElementById('master-test-overall-badge');
        overallBadge.className = 'badge ';
        if (report.overall_status === 'pass') {
            overallBadge.className += 'badge-success';
            overallBadge.innerHTML = '<i class="fas fa-check"></i> ALL CLEAR';
        } else if (report.overall_status === 'warn') {
            overallBadge.className += 'badge-warning';
            overallBadge.innerHTML = '<i class="fas fa-exclamation-triangle"></i> WARNINGS';
        } else {
            overallBadge.className += 'badge-danger';
            overallBadge.innerHTML = '<i class="fas fa-times"></i> FAILURES';
        }

        masterTestRenderRows();
        masterTestResults.style.display = '';
    }

    if (masterTestBtn) {
        masterTestBtn.addEventListener('click', function () {
            masterTestBtn.disabled = true;
            masterTestSpinner.style.display = '';
            masterTestResults.style.display = 'none';

            fetch(masterTestUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                }
            })
            .then(r => r.json())
            .then(data => {
                masterTestBtn.disabled = false;
                masterTestSpinner.style.display = 'none';
                if (data && data.success && data.report) {
                    masterTestRender(data.report);
                } else {
                    alert('Master Test endpoint returned an unexpected response. Check the browser console / Laravel log.');
                    console.error('Master Test response:', data);
                }
            })
            .catch(err => {
                masterTestBtn.disabled = false;
                masterTestSpinner.style.display = 'none';
                alert('Master Test request failed: ' + err.message);
                console.error('Master Test fetch error:', err);
            });
        });
    }

    if (masterTestFilterBtn) {
        masterTestFilterBtn.addEventListener('click', function () {
            masterTestShowOnlyIssues = !masterTestShowOnlyIssues;
            masterTestFilterBtn.innerHTML = masterTestShowOnlyIssues
                ? '<i class="fas fa-list"></i> Show all'
                : '<i class="fas fa-filter"></i> Show only issues';
            masterTestRenderRows();
        });
    }
});

function testConnectivity() {
    console.log('Testing connectivity to diagnostic routes...');
    const url = '{{ route("mining-manager.diagnostic.ping") }}';
    // Convert to relative URL to respect current protocol
    const relativeUrl = new URL(url, window.location.origin).pathname;
    console.log('Ping URL:', relativeUrl);
    fetch(relativeUrl, {
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        console.log('Ping response:', response);
        return response.json();
    })
    .then(data => {
        console.log('Ping data:', data);
        alert('✓ Connection successful! Routes are working.\n\n' + JSON.stringify(data, null, 2));
    })
    .catch(error => {
        console.error('Ping error:', error);
        alert('✗ Connection failed!\n\nError: ' + error.message + '\n\nCheck browser console (F12) for details');
    });
}

function checkProviderRequirements() {
    const provider = document.getElementById('providerSelect').value;
    const warningBox = document.getElementById('providerWarning');
    const warningText = document.getElementById('providerWarningText');

    if (provider === 'janice') {
        warningText.innerHTML = 'Janice API requires an API key. Make sure you have configured <code>janice_api_key</code> in Settings or set <code>MINING_MANAGER_JANICE_API_KEY</code> in your .env file. You can get a free API key from <a href="https://janice.e-351.com/" target="_blank">janice.e-351.com</a>.';
        warningBox.style.display = 'block';
    } else if (provider === 'manager-core') {
        @if(\MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled())
            warningText.innerHTML = '<i class="fas fa-check-circle text-success"></i> Manager Core is installed. This test will read prices from Manager Core\'s cached market data. Make sure Manager Core has been configured and has run at least one price update cycle.';
        @else
            warningText.innerHTML = '<i class="fas fa-times-circle text-danger"></i> <strong>Manager Core is not installed.</strong> Install the <code>mattfalahe/manager-core</code> package to use this provider. EvePraisal integration is available through Manager Core\'s price provider settings.';
        @endif
        warningBox.style.display = 'block';
    } else {
        warningBox.style.display = 'none';
    }
}

function testProvider() {
    const provider = document.getElementById('providerSelect').value;
    const resultsDiv = document.getElementById('testResults');
    const btnText = document.getElementById('testBtnText');
    const spinner = document.getElementById('testSpinner');

    btnText.textContent = 'Testing...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.test-price-provider") }}';
    // Convert to relative URL to respect current protocol
    const relativeUrl = new URL(url, window.location.origin).pathname;
    console.log('Test provider URL:', relativeUrl);
    fetch(relativeUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ provider: provider })
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        btnText.textContent = 'Test Provider';
        spinner.style.display = 'none';

        if (data.success) {
            let html = `
                <div class="provider-test-result success">
                    <h5><i class="fas fa-check-circle text-success"></i> Test Successful</h5>
                    <p><strong>Provider:</strong> ${data.provider}</p>
                    <p><strong>Duration:</strong> ${data.duration_ms}ms</p>
                    <p><strong>Items Tested:</strong> ${data.successful_items} / ${data.total_items}</p>
                    <hr>
                    <h6>Price Results:</h6>
            `;

            data.results.forEach(item => {
                const statusClass = item.status === 'success' ? 'success' : 'failed';
                const statusIcon = item.status === 'success' ? 'fa-check-circle text-success' : 'fa-times-circle text-danger';
                html += `
                    <div class="price-item ${statusClass}">
                        <span><i class="fas ${statusIcon}"></i> ${item.type_name} (${item.type_id})</span>
                        <span><strong>${item.price} ISK</strong></span>
                    </div>
                `;
            });

            html += '</div>';
            resultsDiv.innerHTML = html;
        } else {
            let errorHtml = `
                <div class="provider-test-result error">
                    <h5><i class="fas fa-times-circle text-danger"></i> Test Failed</h5>
                    <p><strong>Provider:</strong> ${data.provider}</p>
                    <p><strong>Error:</strong> ${data.error}</p>
            `;

            // Add helpful hint for missing config
            if (data.missing_config) {
                errorHtml += `
                    <hr>
                    <p><strong>How to fix:</strong></p>
                    <ul>
                        <li>Get a free API key from <a href="https://janice.e-351.com/" target="_blank">janice.e-351.com</a></li>
                        <li>Go to <strong>Settings</strong> and add your Janice API key</li>
                        <li>Or add <code>MINING_MANAGER_JANICE_API_KEY=your_key_here</code> to your .env file</li>
                    </ul>
                `;
            }

            errorHtml += '</div>';
            resultsDiv.innerHTML = errorHtml;
        }
    })
    .catch(error => {
        btnText.textContent = 'Test Provider';
        spinner.style.display = 'none';
        console.error('Fetch error:', error);
        resultsDiv.innerHTML = `
            <div class="provider-test-result error">
                <h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5>
                <p><strong>Error:</strong> ${error.message}</p>
                <p><strong>URL:</strong> {{ route("mining-manager.diagnostic.test-price-provider") }}</p>
                <p class="mb-0"><small>Check browser console (F12) and Laravel logs for more details</small></p>
            </div>
        `;
    });
}

function loadProviderConfig() {
    const configDisplay = document.getElementById('configDisplay');
    const configContent = document.getElementById('configContent');

    const url = '{{ route("mining-manager.diagnostic.price-provider-config") }}';
    // Convert to relative URL to respect current protocol
    const relativeUrl = new URL(url, window.location.origin).pathname;
    console.log('Config URL:', relativeUrl);
    fetch(relativeUrl, {
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        console.log('Config response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        let html = `
            <p><strong>Current Provider:</strong> ${data.current_provider}</p>
        `;

        // Add warning if Janice is not configured but selected
        if (!data.janice_configured) {
            html += `
                <div class="danger-box" style="margin: 10px 0;">
                    <strong><i class="fas fa-exclamation-triangle"></i> Janice API Key Not Configured</strong>
                    <p class="mb-0">Get a free API key from <a href="https://janice.e-351.com/" target="_blank">janice.e-351.com</a> and configure it in Settings.</p>
                </div>
            `;
        }

        html += '<hr><h6>Settings:</h6><ul class="list-unstyled">';

        for (const [key, value] of Object.entries(data.settings)) {
            if (value !== null) {
                let displayValue = value;
                if (key === 'janice_api_key' && value === 'NOT CONFIGURED') {
                    displayValue = '<span class="text-danger">' + value + '</span>';
                }
                html += `<li><strong>${key}:</strong> ${displayValue}</li>`;
            }
        }

        html += '</ul>';
        configContent.innerHTML = html;
        configDisplay.style.display = 'block';
    })
    .catch(error => {
        console.error('Config fetch error:', error);
        configContent.innerHTML = `
            <p class="text-danger"><strong>Error loading configuration:</strong> ${error.message}</p>
            <p class="mb-0"><small>URL: {{ route("mining-manager.diagnostic.price-provider-config") }}</small></p>
            <p class="mb-0"><small>Check browser console (F12) and Laravel logs for more details</small></p>
        `;
        configDisplay.style.display = 'block';
    });
}

function testBatchPricing() {
    const provider = document.getElementById('batchProvider').value;
    const category = document.getElementById('batchCategory').value;
    const resultsDiv = document.getElementById('batchResults');
    const btnText = document.getElementById('batchBtnText');
    const spinner = document.getElementById('batchSpinner');

    btnText.textContent = 'Testing...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.test-batch-pricing") }}';
    // Convert to relative URL to respect current protocol
    const relativeUrl = new URL(url, window.location.origin).pathname;
    console.log('Batch pricing URL:', relativeUrl);
    fetch(relativeUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ provider: provider, category: category })
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Run Batch Test';
        spinner.style.display = 'none';

        if (data.success) {
            let html = `
                <div class="provider-test-result success" style="margin-top: 15px;">
                    <h5><i class="fas fa-check-circle text-success"></i> Batch Test Completed</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <p><strong>Provider:</strong> ${data.provider}</p>
                        </div>
                        <div class="col-md-3">
                            <p><strong>Category:</strong> ${data.category}</p>
                        </div>
                        <div class="col-md-3">
                            <p><strong>Duration:</strong> ${data.duration_ms}ms</p>
                        </div>
                        <div class="col-md-3">
                            <p><strong>Avg per item:</strong> ${data.avg_time_per_item}ms</p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Success Rate:</strong> ${data.successful_items} / ${data.total_items} (${Math.round(data.successful_items / data.total_items * 100)}%)</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Total Value:</strong> ${Number(data.total_value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ISK</p>
                        </div>
                    </div>
                    <hr>
                    <h6>Detailed Results (first 20):</h6>
                    <div style="max-height: 400px; overflow-y: auto;">
            `;

            data.results.slice(0, 20).forEach(item => {
                const statusClass = item.price > 0 ? 'success' : 'failed';
                const statusIcon = item.price > 0 ? 'fa-check-circle text-success' : 'fa-times-circle text-danger';
                html += `
                    <div class="price-item ${statusClass}">
                        <span><i class="fas ${statusIcon}"></i> ${item.type_name} (${item.type_id})</span>
                        <span><strong>${item.price_formatted} ISK</strong></span>
                    </div>
                `;
            });

            if (data.results.length > 20) {
                html += `<p class="text-muted mt-2">... and ${data.results.length - 20} more items</p>`;
            }

            html += '</div></div>';
            resultsDiv.innerHTML = html;
        } else {
            let errorHtml = `
                <div class="provider-test-result error" style="margin-top: 15px;">
                    <h5><i class="fas fa-times-circle text-danger"></i> Batch Test Failed</h5>
                    <p><strong>Error:</strong> ${data.error}</p>
            `;

            // Add helpful hint for missing config
            if (data.missing_config) {
                errorHtml += `
                    <hr>
                    <p><strong>How to fix:</strong></p>
                    <ul>
                        <li>Get a free API key from <a href="https://janice.e-351.com/" target="_blank">janice.e-351.com</a></li>
                        <li>Go to <strong>Settings</strong> and add your Janice API key</li>
                        <li>Or add <code>MINING_MANAGER_JANICE_API_KEY=your_key_here</code> to your .env file</li>
                    </ul>
                `;
            }

            errorHtml += '</div>';
            resultsDiv.innerHTML = errorHtml;
        }
    })
    .catch(error => {
        btnText.textContent = 'Run Batch Test';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `
            <div class="provider-test-result error" style="margin-top: 15px;">
                <h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5>
                <p>${error.message}</p>
            </div>
        `;
    });
}

function loadCacheHealth() {
    const resultsDiv = document.getElementById('cacheHealthResults');
    const btnText = document.getElementById('healthBtnText');
    const spinner = document.getElementById('healthSpinner');

    btnText.textContent = 'Checking...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.cache-health") }}';
    // Convert to relative URL to respect current protocol
    const relativeUrl = new URL(url, window.location.origin).pathname;
    console.log('Cache health URL:', relativeUrl);
    fetch(relativeUrl)
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Check Health';
        spinner.style.display = 'none';

        if (data.success) {
            const statusColors = {
                'healthy': 'success',
                'warning': 'warning',
                'critical': 'danger'
            };
            const statusIcons = {
                'healthy': 'fa-check-circle',
                'warning': 'fa-exclamation-triangle',
                'critical': 'fa-times-circle'
            };

            let html = `
                <div class="provider-test-result ${statusColors[data.health_status]}" style="margin-top: 15px;">
                    <h5><i class="fas ${statusIcons[data.health_status]} text-${statusColors[data.health_status]}"></i> Cache Status: ${data.health_status.toUpperCase()}</h5>

                    <div class="row">
                        <div class="col-md-3">
                            <p><strong>Total Cached:</strong> ${data.statistics.total_cached}</p>
                        </div>
                        <div class="col-md-3">
                            <p><strong>Fresh Items:</strong> ${data.statistics.fresh_items}</p>
                        </div>
                        <div class="col-md-3">
                            <p><strong>Stale Items:</strong> ${data.statistics.stale_items}</p>
                        </div>
                        <div class="col-md-3">
                            <p><strong>Zero Prices:</strong> ${data.statistics.zero_price_items}</p>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <p><strong>Cache Duration:</strong> ${data.statistics.cache_duration_minutes} minutes</p>
                        </div>
                        <div class="col-md-4">
                            <p><strong>Oldest Entry:</strong> ${data.statistics.oldest_cache_hours} hours ago</p>
                        </div>
                        <div class="col-md-4">
                            <p><strong>Newest Entry:</strong> ${data.statistics.newest_cache_minutes} minutes ago</p>
                        </div>
                    </div>
            `;

            if (data.issues && data.issues.length > 0) {
                html += '<hr><h6>Issues Found:</h6><ul>';
                data.issues.forEach(issue => {
                    html += `<li>${issue}</li>`;
                });
                html += '</ul>';
            }

            if (data.missing_essential && data.missing_essential.length > 0) {
                html += '<hr><h6>Missing Essential Ores:</h6><ul>';
                data.missing_essential.forEach(item => {
                    html += `<li>${item.name} (${item.type_id})</li>`;
                });
                html += '</ul>';
            }

            if (data.recommendations && data.recommendations.length > 0) {
                html += '<hr><h6>Recommendations:</h6>';
                data.recommendations.forEach(rec => {
                    const recColor = rec.severity === 'critical' ? 'danger' : rec.severity === 'warning' ? 'warning' : 'info';
                    html += `
                        <div class="alert alert-${recColor} mb-2" role="alert">
                            <strong>${rec.message}</strong><br>
                            <code>${rec.action}</code>
                        </div>
                    `;
                });
            }

            html += '</div>';
            resultsDiv.innerHTML = html;
        } else {
            resultsDiv.innerHTML = `
                <div class="provider-test-result error" style="margin-top: 15px;">
                    <h5><i class="fas fa-times-circle text-danger"></i> Failed to Check Cache Health</h5>
                    <p>${data.error}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        btnText.textContent = 'Check Health';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `
            <div class="provider-test-result error" style="margin-top: 15px;">
                <h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5>
                <p>${error.message}</p>
            </div>
        `;
    });
}

function warmCache() {
    const category = document.getElementById('warmCategory').value;
    const resultsDiv = document.getElementById('warmResults');
    const btnText = document.getElementById('warmBtnText');
    const spinner = document.getElementById('warmSpinner');

    btnText.textContent = 'Warming...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.warm-cache") }}';
    // Convert to relative URL to respect current protocol
    const relativeUrl = new URL(url, window.location.origin).pathname;
    console.log('Warm cache URL:', relativeUrl);
    fetch(relativeUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ category: category })
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Warm Cache';
        spinner.style.display = 'none';

        if (data.success) {
            const successRate = Math.round((data.stored / data.total_items) * 100);
            html = `
                <div class="provider-test-result success" style="margin-top: 15px;">
                    <h5><i class="fas fa-check-circle text-success"></i> Cache Warmed Successfully</h5>
                    <p><strong>Category:</strong> ${data.category}</p>
                    <p><strong>Provider:</strong> ${data.provider}</p>
                    <p><strong>Duration:</strong> ${data.duration_ms}ms</p>
                    <p><strong>Items Processed:</strong> ${data.total_items}</p>
                    <p><strong>Successfully Stored:</strong> ${data.stored} (${successRate}%)</p>
                    <p><strong>Failed:</strong> ${data.failed}</p>
                    <p class="mb-0">${data.message}</p>
                </div>
            `;
            resultsDiv.innerHTML = html;

            // Auto-refresh health status after warming
            setTimeout(() => {
                if (document.getElementById('cache-health').classList.contains('active')) {
                    loadCacheHealth();
                }
            }, 1000);
        } else {
            resultsDiv.innerHTML = `
                <div class="provider-test-result error" style="margin-top: 15px;">
                    <h5><i class="fas fa-times-circle text-danger"></i> Cache Warming Failed</h5>
                    <p>${data.error}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        btnText.textContent = 'Warm Cache';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `
            <div class="provider-test-result error" style="margin-top: 15px;">
                <h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5>
                <p>${error.message}</p>
            </div>
        `;
    });
}

// ============================================================================
// SETTINGS HEALTH CHECK
// ============================================================================

function loadSettingsHealth() {
    const resultsDiv = document.getElementById('settingsHealthResults');
    const btnText = document.getElementById('settingsHealthBtnText');
    const spinner = document.getElementById('settingsHealthSpinner');

    btnText.textContent = 'Checking...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.settings-health") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;

    fetch(relativeUrl, { headers: { 'Accept': 'application/json' } })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Run Health Check';
        spinner.style.display = 'none';

        if (data.success) {
            let html = `<div class="provider-test-result success">
                <h5><i class="fas fa-check-circle text-success"></i> Settings Health Report</h5>
                <div class="row mb-3">
                    <div class="col-md-3"><strong>DB Settings:</strong> ${data.summary.total_db_settings}</div>
                    <div class="col-md-3"><strong>Global:</strong> ${data.summary.global_settings}</div>
                    <div class="col-md-3"><strong>Corp Overrides:</strong> ${data.summary.corporation_overrides.length}</div>
                    <div class="col-md-3"><strong>Orphaned:</strong> <span class="${data.summary.orphaned_settings > 0 ? 'text-danger' : ''}">${data.summary.orphaned_settings}</span></div>
                </div>`;

            if (data.issues.length > 0) {
                html += '<div class="alert alert-warning">';
                data.issues.forEach(issue => { html += `<p class="mb-0"><i class="fas fa-exclamation-triangle"></i> ${issue}</p>`; });
                html += '</div>';
            }

            // Show each settings group
            for (const [group, settings] of Object.entries(data.settings)) {
                html += `<hr><h6><i class="fas fa-folder"></i> ${group}</h6><div style="max-height: 300px; overflow-y: auto;">`;
                settings.forEach(s => {
                    const sourceColor = s.source === 'database' ? 'text-success' : (s.source === 'config' ? 'text-info' : 'text-muted');
                    const sourceIcon = s.source === 'database' ? 'fa-database' : (s.source === 'config' ? 'fa-file-code' : 'fa-cog');
                    html += `<div class="price-item"><span><i class="fas ${sourceIcon} ${sourceColor}"></i> ${s.key}</span>
                        <span><strong>${s.value !== null && s.value !== '' ? s.value : '<em>empty</em>'}</strong> <small class="${sourceColor}">[${s.source}]</small></span></div>`;
                });
                html += '</div>';
            }

            // Corporation overrides
            if (data.summary.corporation_overrides.length > 0) {
                html += '<hr><h6><i class="fas fa-building"></i> Corporation Overrides</h6>';
                data.summary.corporation_overrides.forEach(corp => {
                    html += `<div class="price-item"><span>${corp.corporation_name} (${corp.corporation_id})</span><span><strong>${corp.setting_count} settings</strong></span></div>`;
                });
            }

            html += '</div>';
            resultsDiv.innerHTML = html;
        } else {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Failed</h5><p>${data.error}</p></div>`;
        }
    })
    .catch(error => {
        btnText.textContent = 'Run Health Check';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5><p>${error.message}</p></div>`;
    });
}

// ============================================================================
// TAX TRACE
// ============================================================================

function runTaxTrace() {
    const charId = document.getElementById('taxTraceCharId').value;
    const month = document.getElementById('taxTraceMonth').value;
    const resultsDiv = document.getElementById('taxTraceResults');
    const btnText = document.getElementById('taxTraceBtnText');
    const spinner = document.getElementById('taxTraceSpinner');

    if (!charId) {
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Error</h5><p>Please enter a Character ID</p></div>`;
        return;
    }

    btnText.textContent = 'Tracing...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.tax-diagnostic") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;

    fetch(relativeUrl + '?character_id=' + charId + '&month=' + month, {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Trace Tax Calculation';
        spinner.style.display = 'none';

        if (!data.success) {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Failed</h5><p>${data.error}</p></div>`;
            return;
        }

        const fmtISK = (v) => Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
        const fmtNum = (v) => Number(v || 0).toLocaleString();
        const oreIcon = (cat) => cat === 'moon_ore' ? 'fa-moon' : (cat === 'ice' ? 'fa-snowflake' : (cat === 'gas' ? 'fa-cloud' : 'fa-gem'));

        let html = '';

        // ================================================================
        // SECTION 1: Character & Account Info
        // ================================================================
        const acc = data.account || {};
        const bill = data.tax_bill;
        const statusColors = { paid: 'success', unpaid: 'warning', overdue: 'danger', partial: 'info', waived: 'secondary' };

        html += `<div class="provider-test-result ${data.stored_summaries.totals.days_with_data > 0 ? 'success' : 'warning'}">
            <h5><i class="fas fa-user"></i> Character & Account Info</h5>
            <div class="row mb-2">
                <div class="col-md-4">
                    <strong>Character:</strong> ${data.character.name} <small class="text-muted">(${data.character.id})</small>
                </div>
                <div class="col-md-4">
                    <strong>Corporation:</strong> ${data.character.corporation_name || 'Unknown'}
                </div>
                <div class="col-md-4">
                    <strong>Period:</strong> ${data.period.start} to ${data.period.end}
                </div>
            </div>`;

        // Account info
        if (acc.is_registered) {
            html += `<div class="row mb-2">
                <div class="col-md-4">
                    <strong>Main Character:</strong> ${acc.main_character_name || 'N/A'} <small class="text-muted">(${acc.main_character_id || '?'})</small>
                </div>
                <div class="col-md-8">
                    <strong>All Characters:</strong> `;
            if (acc.all_characters && acc.all_characters.length > 0) {
                html += acc.all_characters.map(c =>
                    `${c.name}${c.is_main ? ' <span class="badge badge-primary">MAIN</span>' : ''}`
                ).join(', ');
            } else {
                html += '<span class="text-muted">None found</span>';
            }
            html += `</div></div>`;
        } else {
            html += `<div class="row mb-2"><div class="col-md-12"><span class="text-warning"><i class="fas fa-exclamation-triangle"></i> Character not registered in SeAT (guest miner)</span></div></div>`;
        }

        // Tax bill
        if (bill) {
            const statusColor = statusColors[bill.status] || 'secondary';
            html += `<div class="row mb-2" style="background: rgba(0,0,0,0.1); padding: 8px; border-radius: 4px; margin-top: 5px;">
                <div class="col-md-3">
                    <strong>Tax Bill:</strong> <span class="badge badge-${statusColor}">${bill.status.toUpperCase()}</span>
                </div>
                <div class="col-md-3">
                    <strong>Owed:</strong> ${fmtISK(bill.amount_owed)} ISK
                </div>
                <div class="col-md-3">
                    <strong>Paid:</strong> ${fmtISK(bill.amount_paid)} ISK
                </div>
                <div class="col-md-3">
                    <strong>Due:</strong> ${bill.due_date || 'N/A'}
                    ${bill.tax_code ? ' | <strong>Code:</strong> ' + bill.tax_code : ''}
                </div>
            </div>`;
        } else {
            html += `<div class="row mb-2"><div class="col-md-12"><span class="text-muted"><i class="fas fa-info-circle"></i> No tax bill found for this period (taxes may not have been calculated yet)</span></div></div>`;
        }

        html += `</div>`;

        // ================================================================
        // SECTION 2: Mismatches / Warnings
        // ================================================================
        if (data.mismatches && data.mismatches.length > 0) {
            html += `<div class="provider-test-result error" style="margin-top: 10px;">
                <h5><i class="fas fa-exclamation-triangle text-warning"></i> Issues Detected (${data.mismatches.length})</h5>`;
            data.mismatches.forEach(m => {
                const sevColor = m.severity === 'high' ? 'danger' : (m.severity === 'medium' ? 'warning' : 'info');
                const sevIcon = m.severity === 'high' ? 'fa-times-circle' : 'fa-exclamation-circle';
                html += `<div style="padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <span class="badge badge-${sevColor}"><i class="fas ${sevIcon}"></i> ${m.severity.toUpperCase()}</span>
                    <span style="margin-left: 8px;">${m.message}</span>
                </div>`;
            });
            html += `</div>`;
        }

        // ================================================================
        // SECTION 3: Stored Daily Summaries
        // ================================================================
        const stored = data.stored_summaries;
        html += `<div class="provider-test-result success" style="margin-top: 10px;">
            <h5><i class="fas fa-database"></i> Stored Daily Summaries</h5>
            <div class="row mb-2">
                <div class="col-md-3"><strong>Days with data:</strong> ${stored.totals.days_with_data}</div>
                <div class="col-md-3"><strong>Total Value:</strong> ${fmtISK(stored.totals.total_value)} ISK</div>
                <div class="col-md-3"><strong>Total Tax:</strong> ${fmtISK(stored.totals.total_tax)} ISK</div>
                <div class="col-md-3"><strong>Effective Rate:</strong> ${stored.totals.effective_rate}%</div>
            </div>`;

        if (stored.days.length > 0) {
            html += `<div style="max-height: 600px; overflow-y: auto; margin-top: 10px;">`;

            stored.days.forEach((day, dayIdx) => {
                const hasWarnings = day.warnings && day.warnings.length > 0;
                const dayId = 'day_' + dayIdx;

                html += `<div style="border: 1px solid rgba(255,255,255,0.15); border-radius: 4px; margin-bottom: 8px; overflow: hidden;">
                    <div style="background: rgba(0,0,0,0.2); padding: 8px 12px; cursor: pointer; display: flex; justify-content: space-between; align-items: center;" onclick="document.getElementById('${dayId}').style.display = document.getElementById('${dayId}').style.display === 'none' ? 'block' : 'none'">
                        <span>
                            <i class="fas fa-calendar-day"></i> <strong>${day.date}</strong>
                            <span class="text-muted ml-2">${day.ore_count} ore types</span>
                            ${day.is_finalized ? '<span class="badge badge-success ml-2">FINALIZED</span>' : '<span class="badge badge-warning ml-2">PENDING</span>'}
                            ${hasWarnings ? '<span class="badge badge-danger ml-2"><i class="fas fa-exclamation-triangle"></i> ' + day.warnings.length + ' issues</span>' : ''}
                        </span>
                        <span>
                            <span class="text-muted">Value:</span> ${fmtISK(day.total_value)} ISK
                            <span class="text-muted ml-3">Tax:</span> <strong>${fmtISK(day.total_tax)} ISK</strong>
                            <i class="fas fa-chevron-down ml-2"></i>
                        </span>
                    </div>
                    <div id="${dayId}" style="display: none; padding: 8px 12px;">`;

                // Category breakdown
                html += `<div class="row mb-2" style="font-size: 0.85em;">
                    <div class="col-md-3"><i class="fas fa-moon text-warning"></i> Moon: ${fmtISK(day.moon_ore_value)} ISK</div>
                    <div class="col-md-3"><i class="fas fa-gem text-info"></i> Ore: ${fmtISK(day.regular_ore_value)} ISK</div>
                    <div class="col-md-3"><i class="fas fa-snowflake text-primary"></i> Ice: ${fmtISK(day.ice_value)} ISK</div>
                    <div class="col-md-3"><i class="fas fa-cloud text-success"></i> Gas: ${fmtISK(day.gas_value)} ISK</div>
                </div>`;

                // Ore details table
                if (day.ores && day.ores.length > 0) {
                    html += `<table class="table table-sm table-dark table-striped" style="font-size: 0.85em; margin-bottom: 0;">
                        <thead><tr>
                            <th>Ore</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Total Value</th>
                            <th class="text-center">Tax Rate</th>
                            <th class="text-center">Event Mod</th>
                            <th class="text-center">Eff. Rate</th>
                            <th class="text-right">Est. Tax</th>
                            <th class="text-center">Status</th>
                        </tr></thead><tbody>`;

                    day.ores.forEach(ore => {
                        const hasOreWarning = ore.warnings && ore.warnings.length > 0;
                        const rowClass = hasOreWarning ? 'style="background: rgba(255,0,0,0.1);"' : '';

                        html += `<tr ${rowClass}>
                            <td><i class="fas ${oreIcon(ore.category)}"></i> ${ore.ore_name || 'Type ' + ore.type_id}
                                <small class="text-muted">[${ore.category}${ore.moon_rarity ? '/' + ore.moon_rarity : ''}]</small>
                            </td>
                            <td class="text-right">${fmtNum(ore.quantity)}</td>
                            <td class="text-right">${ore.unit_price > 0 ? fmtISK(ore.unit_price) : '<span class="text-danger">0.00</span>'}</td>
                            <td class="text-right">${fmtISK(ore.total_value)}</td>
                            <td class="text-center">${ore.tax_rate || 0}%</td>
                            <td class="text-center">${ore.event_modifier ? ore.event_modifier + '%' : '-'}</td>
                            <td class="text-center">${ore.effective_rate || 0}%</td>
                            <td class="text-right"><strong>${fmtISK(ore.estimated_tax)}</strong></td>
                            <td class="text-center">
                                ${ore.is_taxable ? '<span class="badge badge-success">Taxable</span>' : '<span class="badge badge-secondary">Exempt</span>'}
                                ${hasOreWarning ? '<span class="badge badge-danger" title="' + ore.warnings.join('; ') + '"><i class="fas fa-exclamation-triangle"></i></span>' : ''}
                            </td>
                        </tr>`;
                    });

                    html += `</tbody></table>`;
                }

                html += `</div></div>`;
            });

            html += `</div>`;
        } else {
            html += `<p class="text-muted mt-3">No daily summaries found for this character in the selected month. Run calculate-taxes to generate them.</p>`;
        }

        html += `</div>`;

        // ================================================================
        // SECTION 4: Live Recalculation
        // ================================================================
        const live = data.live_recalculation;
        html += `<div class="provider-test-result" style="margin-top: 10px; border-left: 3px solid #17a2b8;">
            <h5><i class="fas fa-sync-alt"></i> Live Recalculation <small class="text-muted">(current prices & rates)</small></h5>
            <div class="row mb-2">
                <div class="col-md-3"><strong>Provider:</strong> ${live.settings_used.price_provider}</div>
                <div class="col-md-3"><strong>Method:</strong> ${live.settings_used.valuation_method}</div>
                <div class="col-md-3"><strong>Refining:</strong> ${live.settings_used.refining_efficiency}%</div>
                <div class="col-md-3"><strong>Ledger Entries:</strong> ${live.totals.total_entries}</div>
            </div>
            <div class="row mb-2">
                <div class="col-md-4"><strong>Total Value:</strong> ${fmtISK(live.totals.total_value)} ISK</div>
                <div class="col-md-4"><strong>Total Tax:</strong> ${fmtISK(live.totals.total_tax)} ISK</div>
                <div class="col-md-4"><strong>Effective Rate:</strong> ${live.totals.effective_rate}%</div>
            </div>`;

        // Comparison bar
        if (stored.totals.days_with_data > 0) {
            const diff = Math.abs(stored.totals.total_tax - live.totals.total_tax);
            const diffPct = stored.totals.total_tax > 0 ? ((diff / stored.totals.total_tax) * 100).toFixed(1) : 0;
            const diffColor = diff < 1 ? 'success' : (diff < 1000000 ? 'warning' : 'danger');
            html += `<div style="background: rgba(0,0,0,0.15); padding: 8px; border-radius: 4px; margin-bottom: 10px;">
                <strong>Stored vs Live Difference:</strong>
                <span class="badge badge-${diffColor}" style="font-size: 0.9em;">
                    ${diff < 1 ? 'MATCH' : fmtISK(diff) + ' ISK (' + diffPct + '%)'}
                </span>
                ${diff >= 1 ? ' <small class="text-muted">Prices or rates may have changed since summaries were generated</small>' : ''}
            </div>`;
        }

        // Collapsible live entries
        if (live.entries && live.entries.length > 0) {
            html += `<div style="margin-top: 5px;">
                <a href="#" onclick="event.preventDefault(); document.getElementById('liveEntriesTable').style.display = document.getElementById('liveEntriesTable').style.display === 'none' ? 'block' : 'none'">
                    <i class="fas fa-chevron-down"></i> Show ${live.entries.length} ledger entries
                </a>
                <div id="liveEntriesTable" style="display: none; max-height: 400px; overflow-y: auto; margin-top: 8px;">
                    <table class="table table-sm table-dark table-striped" style="font-size: 0.85em; margin-bottom: 0;">
                        <thead><tr>
                            <th>Date</th>
                            <th>Ore</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Value</th>
                            <th class="text-center">Rate</th>
                            <th class="text-right">Tax</th>
                        </tr></thead><tbody>`;

            live.entries.forEach(e => {
                const hasErr = e.pricing_error || e.unit_price == 0;
                html += `<tr ${hasErr ? 'style="background: rgba(255,0,0,0.1);"' : ''}>
                    <td>${e.date}</td>
                    <td><i class="fas ${oreIcon(e.category)}"></i> ${e.type_name} <small class="text-muted">[${e.category}${e.rarity ? '/' + e.rarity : ''}]</small>
                        ${e.pricing_error ? '<br><small class="text-danger"><i class="fas fa-times-circle"></i> ' + e.pricing_error + '</small>' : ''}
                    </td>
                    <td class="text-right">${fmtNum(e.quantity)}</td>
                    <td class="text-right">${e.unit_price > 0 ? fmtISK(e.unit_price) : '<span class="text-danger">0.00</span>'}</td>
                    <td class="text-right">${fmtISK(e.total_value)}</td>
                    <td class="text-center">${e.tax_rate}%</td>
                    <td class="text-right"><strong>${fmtISK(e.tax_amount)}</strong></td>
                </tr>`;
            });

            html += `</tbody></table></div></div>`;
        }

        html += `</div>`;

        resultsDiv.innerHTML = html;
    })
    .catch(error => {
        btnText.textContent = 'Trace Tax Calculation';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5><p>${error.message}</p></div>`;
    });
}

// ============================================================================
// DATA INTEGRITY SCAN
// ============================================================================

function runDataIntegrity() {
    const resultsDiv = document.getElementById('integrityResults');
    const btnText = document.getElementById('integrityBtnText');
    const spinner = document.getElementById('integritySpinner');

    btnText.textContent = 'Scanning...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.data-integrity") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;

    fetch(relativeUrl, { headers: { 'Accept': 'application/json' } })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Run Integrity Scan';
        spinner.style.display = 'none';

        if (data.success) {
            const statusColors = { 'healthy': 'success', 'warning': 'warning', 'error': 'danger' };
            const statusIcons = { 'healthy': 'fa-check-circle', 'warning': 'fa-exclamation-triangle', 'error': 'fa-times-circle' };

            let html = `<div class="provider-test-result ${statusColors[data.health_status]}">
                <h5><i class="fas ${statusIcons[data.health_status]} text-${statusColors[data.health_status]}"></i> Data Integrity: ${data.health_status.toUpperCase()}</h5>
                <p><strong>Scan Duration:</strong> ${data.duration_ms}ms</p>
                <div class="row mb-2">
                    <div class="col-md-3"><strong>Mining Entries:</strong> ${data.summary.total_mining_entries.toLocaleString()}</div>
                    <div class="col-md-3"><strong>Tax Records:</strong> ${data.summary.total_tax_records.toLocaleString()}</div>
                    <div class="col-md-3"><strong>Price Cache:</strong> ${data.summary.total_price_cache.toLocaleString()}</div>
                    <div class="col-md-3"><strong>Characters:</strong> ${data.summary.total_characters.toLocaleString()}</div>
                </div>`;

            if (data.issues.length === 0) {
                html += '<hr><p class="text-success"><i class="fas fa-check-circle"></i> No issues found! Your data looks clean.</p>';
            } else {
                html += `<hr><h6>Issues Found (${data.summary.total_issues} total):</h6>`;
                data.issues.forEach(issue => {
                    const sevColor = issue.severity === 'error' ? 'danger' : 'warning';
                    const sevIcon = issue.severity === 'error' ? 'fa-times-circle' : 'fa-exclamation-triangle';
                    html += `<div class="alert alert-${sevColor} mb-2">
                        <strong><i class="fas ${sevIcon}"></i> ${issue.category}</strong> (${issue.count} items)<br>
                        ${issue.message}`;
                    if (issue.details) {
                        html += '<ul class="mt-1 mb-0">';
                        issue.details.forEach(d => {
                            html += `<li>${d.type_name || ''} (${d.type_id || ''}) - ${d.entry_count || ''} entries</li>`;
                        });
                        html += '</ul>';
                    }
                    html += '</div>';
                });
            }

            html += '</div>';
            resultsDiv.innerHTML = html;
        } else {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Scan Failed</h5><p>${data.error}</p></div>`;
        }
    })
    .catch(error => {
        btnText.textContent = 'Run Integrity Scan';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5><p>${error.message}</p></div>`;
    });
}

// ============================================================================
// VALUATION TEST
// ============================================================================

function runValuationTest() {
    const typeId = document.getElementById('valuationTypeId').value;
    const quantity = document.getElementById('valuationQuantity').value || 1000;
    const resultsDiv = document.getElementById('valuationResults');
    const btnText = document.getElementById('valuationBtnText');
    const spinner = document.getElementById('valuationSpinner');

    if (!typeId) {
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Error</h5><p>Please enter a Type ID</p></div>`;
        return;
    }

    btnText.textContent = 'Testing...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.valuation-test") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;

    fetch(relativeUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ type_id: parseInt(typeId), quantity: parseInt(quantity) })
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                try {
                    const json = JSON.parse(text);
                    throw new Error(json.error || json.message || 'Server error ' + response.status);
                } catch (e) {
                    if (e.message.startsWith('Server error') || e.message.includes('error')) throw e;
                    throw new Error('Server error ' + response.status + ': ' + text.substring(0, 200));
                }
            });
        }
        return response.json();
    })
    .then(data => {
        btnText.textContent = 'Test Valuation';
        spinner.style.display = 'none';

        if (data.success) {
            let html = `<div class="provider-test-result success">
                <h5><i class="fas fa-search-dollar"></i> Valuation: ${data.type.type_name} x${data.type.quantity.toLocaleString()}</h5>
                <div class="row mb-3">
                    <div class="col-md-3"><strong>Category:</strong> ${data.type.category}</div>
                    <div class="col-md-3"><strong>Unit Price:</strong> ${Number(data.final_result.unit_price).toLocaleString('en-US', {minimumFractionDigits: 2})} ISK</div>
                    <div class="col-md-3"><strong>Total Value:</strong> ${Number(data.final_result.total_value).toLocaleString('en-US', {minimumFractionDigits: 2})} ISK</div>
                    <div class="col-md-3"><strong>Tax (${data.final_result.tax_rate}%):</strong> ${Number(data.final_result.tax_amount).toLocaleString('en-US', {minimumFractionDigits: 2})} ISK</div>
                </div>
                <hr><h6>Step-by-Step Trace:</h6>`;

            data.steps.forEach(step => {
                html += `<div class="alert alert-info mb-2"><strong>Step ${step.step}: ${step.action}</strong><br>`;
                for (const [k, v] of Object.entries(step.result)) {
                    const displayVal = (typeof v === 'boolean') ? (v ? 'Yes' : 'No') : (v === null ? 'null' : v);
                    html += `<small><strong>${k}:</strong> ${displayVal}</small><br>`;
                }
                html += '</div>';
            });

            html += '</div>';
            resultsDiv.innerHTML = html;
        } else {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Failed</h5><p>${data.error || data.message || 'Unknown error'}</p></div>`;
        }
    })
    .catch(error => {
        btnText.textContent = 'Test Valuation';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5><p>${error.message}</p></div>`;
    });
}

// ============================================================================
// TYPE ID VALIDATION
// ============================================================================

function validateTypeIds() {
    const category = document.getElementById('validateCategory').value;
    const resultsDiv = document.getElementById('validate-results');
    const btnText = document.getElementById('validate-btn-text');
    const spinner = document.getElementById('validate-spinner');

    btnText.textContent = 'Validating...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.validate-type-ids") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;

    fetch(relativeUrl + '?category=' + encodeURIComponent(category), {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                throw new Error(`HTTP ${response.status}: ${text}`);
            });
        }
        return response.json();
    })
    .then(data => {
        btnText.textContent = 'Validate Type IDs';
        spinner.style.display = 'none';

        if (data.success) {
            const successRate = ((data.verified / data.total_items) * 100).toFixed(1);
            const statusIcon = data.failed === 0 ? 'fa-check-circle text-success' : 'fa-exclamation-triangle text-warning';

            let html = `
                <div class="provider-test-result ${data.failed === 0 ? 'success' : 'warning'}">
                    <h5><i class="fas ${statusIcon}"></i> Validation ${data.failed === 0 ? 'Successful' : 'Complete with Issues'}</h5>
                    <p><strong>Category:</strong> ${data.category}</p>
                    <p><strong>Duration:</strong> ${data.duration_ms}ms</p>
                    <p><strong>Success Rate:</strong> ${successRate}% (${data.verified}/${data.total_items})</p>
            `;

            if (data.failed > 0) {
                html += `<p class="text-warning"><strong>Failed:</strong> ${data.failed} type IDs not found in database!</p>`;
            }

            html += `<hr><h6>Validation Results:</h6><div style="max-height: 400px; overflow-y: auto;">`;

            data.results.forEach(item => {
                const statusClass = item.status === 'success' ? 'success' : 'failed';
                const statusIcon = item.status === 'success' ? 'fa-check-circle text-success' : 'fa-times-circle text-danger';
                html += `
                    <div class="price-item ${statusClass}">
                        <span><i class="fas ${statusIcon}"></i> ${item.name} (${item.type_id})</span>
                        <span>${item.status === 'success' ? 'Valid' : 'MISSING'}</span>
                    </div>
                `;
            });

            html += '</div></div>';
            resultsDiv.innerHTML = html;
        } else {
            const errorMessage = typeof data.error === 'object' ? JSON.stringify(data.error) : data.error;
            resultsDiv.innerHTML = `
                <div class="provider-test-result error">
                    <h5><i class="fas fa-times-circle text-danger"></i> Validation Failed</h5>
                    <p><strong>Error:</strong> ${errorMessage}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        btnText.textContent = 'Validate Type IDs';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `
            <div class="provider-test-result error" style="margin-top: 15px;">
                <h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5>
                <p>${error.message}</p>
            </div>
        `;
    });
}

// ── System Status Tab ──────────────────────────────
var systemStatusLoaded = false;
function loadSystemStatus() {
    if (systemStatusLoaded) return;
    systemStatusLoaded = true;

    $('#system-status-loading').hide();
    $('#system-status-content').show();

    var ssUrl = new URL('{{ route("mining-manager.diagnostic.system-status") }}', window.location.origin).pathname;
    var headers = { 'Accept': 'application/json' };

    // Load each section independently — progressive loading
    var sections = [
        { key: 'daily_summaries', target: '#ss-daily-summaries', render: renderDailySummaries },
        { key: 'multi_corp', target: '#ss-multi-corp', render: renderMultiCorp },
        { key: 'price_cache', target: '#ss-price-cache', render: renderPriceCache },
        { key: 'scheduled_jobs', target: '#ss-jobs', render: renderJobs },
        { key: 'data_counts', target: '#ss-data-counts', render: renderDataCounts },
    ];

    sections.forEach(function(s) {
        $(s.target).html('<div class="text-center py-2"><div class="spinner-border spinner-border-sm text-info"></div> Loading...</div>');
        fetch(ssUrl + '?section=' + s.key, { headers: headers })
            .then(function(r) { return r.json(); })
            .then(function(data) { s.render(s.target, data); })
            .catch(function(err) { $(s.target).html('<div class="text-danger"><i class="fas fa-times-circle"></i> Failed: ' + err.message + '</div>'); });
    });
}

function renderDailySummaries(target, ds) {
    var badge = ds.status === 'healthy' ? 'success' : (ds.status === 'warning' ? 'warning' : 'danger');
    $(target).html(
        '<span class="badge badge-' + badge + ' mb-2">' + (ds.status || 'unknown').toUpperCase() + '</span>' +
        '<table class="table table-sm table-dark">' +
        '<tr><td>Total daily summaries</td><td><strong>' + (ds.total || 0).toLocaleString() + '</strong></td></tr>' +
        '<tr><td>Today\'s summaries</td><td>' + (ds.today || 0) + '</td></tr>' +
        '<tr><td>Yesterday\'s summaries</td><td>' + (ds.yesterday || 0) + '</td></tr>' +
        '<tr><td>Miners active today</td><td>' + (ds.miners_today || 0) + '</td></tr>' +
        '<tr><td>Missing summaries (today)</td><td>' + (ds.missing_today > 0 ? '<span class="text-warning">' + ds.missing_today + '</span>' : '0') + '</td></tr>' +
        '<tr><td>Last updated</td><td>' + (ds.last_updated_ago || 'never') + '</td></tr>' +
        '<tr><td>Finalized months</td><td>' + (ds.finalized_months || 0) + '</td></tr>' +
        '</table>'
    );
}

function renderMultiCorp(target, mc) {
    var mcBadge = mc.status === 'healthy' ? 'success' : 'warning';
    var html = '<span class="badge badge-' + mcBadge + ' mb-2">' + (mc.status || 'unknown').toUpperCase() + '</span>' +
        '<table class="table table-sm table-dark">' +
        '<tr><td>Configured corporations</td><td><strong>' + (mc.configured_corporations || 0) + '</strong></td></tr>' +
        '<tr><td>Moon owner corp ID</td><td>' + (mc.moon_owner_corporation_id || '<span class="text-warning">Not set</span>') + '</td></tr>' +
        '<tr><td>Manager Core</td><td>' + (mc.manager_core_installed ? '<span class="text-success">Installed</span>' : '<span class="text-muted">Not installed</span>') + '</td></tr>' +
        '</table>';
    if (mc.corporation_details && mc.corporation_details.length > 0) {
        var ck = '<i class="fas fa-check text-success"></i>';
        var no = '<i class="fas fa-times text-muted"></i>';
        html += '<h6 class="mt-3">Per-Corporation Tax Config</h6><table class="table table-sm table-dark" style="font-size:0.85em;">' +
            '<thead><tr><th>Corporation</th><th>Ore Rate</th><th>Moon Rates</th><th>Moon Mode</th><th>Ore</th><th>Ice</th><th>Gas</th><th>Abyssal</th><th>Triglavian</th></tr></thead><tbody>';
        mc.corporation_details.forEach(function(c) {
            var moonMode = c.all_moon_ore ? 'All Moon' : (c.only_corp_moon_ore ? 'Corp Only' : (c.no_moon_ore ? 'None' : 'Default'));
            html += '<tr><td>' + (c.corporation_name || c.corporation_id) + '</td>' +
                '<td>' + (c.ore_rate || 0) + '%</td>' +
                '<td>' + (c.has_moon_rates ? ck : no) + '</td>' +
                '<td><span class="badge badge-' + (c.no_moon_ore ? 'danger' : 'info') + '">' + moonMode + '</span></td>' +
                '<td>' + (c.ore_taxed ? ck : no) + '</td>' +
                '<td>' + (c.ice_taxed ? ck : no) + '</td>' +
                '<td>' + (c.gas_taxed ? ck : no) + '</td>' +
                '<td>' + (c.abyssal_taxed ? ck : no) + '</td>' +
                '<td>' + (c.triglavian_taxed ? ck : no) + '</td></tr>';
        });
        html += '</tbody></table>';
    }
    $(target).html(html);
}

function renderPriceCache(target, pc) {
    var pcBadge = pc.status === 'healthy' ? 'success' : (pc.status === 'critical' ? 'danger' : 'warning');
    var html = '<span class="badge badge-' + pcBadge + ' mb-2">' + (pc.status || 'unknown').toUpperCase() + '</span>';
    if (pc.provider) {
        html += ' <span class="badge badge-info mb-2"><i class="fas fa-server"></i> ' + pc.provider + '</span>';
    }
    html += '<table class="table table-sm table-dark">' +
        '<tr><td>Total cached prices</td><td><strong>' + (pc.total_cached || 0) + '</strong></td></tr>' +
        '<tr><td>Fresh (within ' + (pc.cache_duration_minutes || 240) + ' min)</td><td class="text-success">' + (pc.fresh || 0) + '</td></tr>' +
        '<tr><td>Stale</td><td class="' + (pc.stale > 0 ? 'text-warning' : '') + '">' + (pc.stale || 0) + '</td></tr>' +
        '</table>';
    $(target).html(html);
}

function renderJobs(target, data) {
    var jobs = data.jobs || [];
    var catColors = { data: 'info', tax: 'success', moon: 'warning', theft: 'danger', reports: 'secondary', other: 'dark' };
    var catLabels = { data: 'Data Pipeline', tax: 'Tax System', moon: 'Moon Mining', theft: 'Theft Detection', reports: 'Reports & Stats', other: 'Other' };

    var html = '<p class="text-muted mb-2">' + (data.total_scheduled || 0) + ' scheduled commands</p>' +
        '<table class="table table-sm table-dark table-striped" style="font-size:0.85em;">' +
        '<thead><tr><th>Command</th><th>Schedule</th><th>Next Run</th><th>Category</th></tr></thead><tbody>';

    jobs.forEach(function(j) {
        var cat = j.category || 'other';
        html += '<tr>' +
            '<td><code>' + j.command + '</code></td>' +
            '<td><code>' + j.expression + '</code></td>' +
            '<td>' + (j.next_run || 'N/A') + '</td>' +
            '<td><span class="badge badge-' + (catColors[cat] || 'dark') + '">' + (catLabels[cat] || cat) + '</span></td>' +
            '</tr>';
    });

    html += '</tbody></table>';
    $(target).html(html);
}

function renderDataCounts(target, dc) {
    $(target).html(
        '<table class="table table-sm table-dark">' +
        '<tr><td>Mining ledger entries</td><td><strong>' + (dc.mining_ledger || 0).toLocaleString() + '</strong></td></tr>' +
        '<tr><td>Tax records</td><td>' + (dc.mining_taxes || 0).toLocaleString() + '</td></tr>' +
        '<tr><td>Daily summaries</td><td>' + (dc.daily_summaries || 0).toLocaleString() + '</td></tr>' +
        '<tr><td>Monthly summaries</td><td>' + (dc.monthly_summaries || 0).toLocaleString() + '</td></tr>' +
        '<tr><td>Price cache entries</td><td>' + (dc.price_cache || 0).toLocaleString() + '</td></tr>' +
        '<tr><td>Moon extractions</td><td>' + (dc.moon_extractions || 0).toLocaleString() + '</td></tr>' +
        '<tr><td>Webhooks</td><td>' + (dc.webhooks || 0).toLocaleString() + '</td></tr>' +
        '</table>'
    );
}

// ============================================================================
// NOTIFICATION TESTING
// ============================================================================

function runNotificationTest() {
    const terminal = document.getElementById('ntTerminal');
    const btnText = document.getElementById('ntRunBtnText');
    const spinner = document.getElementById('ntRunSpinner');
    const summary = document.getElementById('ntSummary');

    // Collect channels
    const channels = [];
    if (document.getElementById('ntChannelEsi').checked) channels.push('esi');
    if (document.getElementById('ntChannelDiscord').checked) channels.push('discord');
    if (document.getElementById('ntChannelSlack').checked) channels.push('slack');

    if (channels.length === 0) {
        appendLogLine(getNow(), 'error', 'Please select at least one channel to test.');
        return;
    }

    // Collect character
    const charDropdownVisible = document.getElementById('ntCharDropdown').style.display !== 'none';
    let characterId = 0;
    let characterName = 'Test Character';

    if (charDropdownVisible) {
        const sel = document.getElementById('ntCharacterSelect');
        characterId = parseInt(sel.value) || 0;
        if (sel.selectedIndex > 0) {
            characterName = sel.options[sel.selectedIndex].text.replace(/\s*\(\d+\)$/, '');
        }
    } else {
        characterId = parseInt(document.getElementById('ntCharacterId').value) || 0;
        characterName = document.getElementById('ntCharacterName').value || 'Test Character';
    }

    // Collect webhook
    const whDropdownVisible = document.getElementById('ntWebhookDropdown').style.display !== 'none';
    let webhookId = null;
    let customWebhookUrl = null;

    if (whDropdownVisible) {
        webhookId = document.getElementById('ntWebhookSelect').value || null;
    } else {
        customWebhookUrl = document.getElementById('ntCustomWebhookUrl').value || null;
    }

    const customSlackUrl = document.getElementById('ntCustomSlackUrl').value || null;
    const notificationType = document.getElementById('ntNotificationType').value;

    // Collect sender info
    const senderMode = document.querySelector('input[name="ntSenderMode"]:checked')?.value || 'settings';
    const senderCharacterId = parseInt(document.getElementById('ntSenderCharacterId')?.value) || 0;

    // Collect test data
    const postData = {
        notification_type: notificationType,
        channels: channels,
        character_id: characterId,
        character_name: characterName,
        webhook_id: webhookId,
        custom_webhook_url: customWebhookUrl,
        custom_slack_url: customSlackUrl,
        sender_mode: senderMode,
        sender_character_id: senderCharacterId,
        test_ping: document.getElementById('ntTestPing')?.checked || false,
        test_amount: parseFloat(document.getElementById('ntAmount').value) || 5000000,
        test_due_date: document.getElementById('ntDueDate').value,
        test_days_remaining: parseInt(document.getElementById('ntDaysRemaining').value) || 7,
        test_days_overdue: parseInt(document.getElementById('ntDaysOverdue').value) || 3,
        test_event_name: document.getElementById('ntEventName').value || 'Test Mining Event',
        test_location: document.getElementById('ntLocation').value || 'Jita',
        test_structure_id: parseInt(document.getElementById('ntStructureId').value) || 1000000000001,
        test_structure_name: document.getElementById('ntStructureName').value || 'Athanor - Test Moon',
        test_moon_name: document.getElementById('ntMoonName').value || 'Perimeter I - Moon 1',
        // extraction_at_risk flavor — ignored by the server for other types
        test_alert_flavor: document.getElementById('ntExtractionFlavor')?.value || 'fuel_critical',
        // Theft fields
        test_severity: document.getElementById('ntSeverity')?.value || 'medium',
        test_ore_value: parseFloat(document.getElementById('ntOreValue')?.value) || 50000000,
        test_tax_owed: parseFloat(document.getElementById('ntTaxOwed')?.value) || 5000000,
        test_activity_count: parseInt(document.getElementById('ntActivityCount')?.value) || 3,
    };

    // Clear terminal and start
    terminal.innerHTML = '';
    summary.style.display = 'none';
    btnText.textContent = 'Running...';
    spinner.style.display = 'inline-block';

    appendLogLine(getNow(), 'info', 'Sending test request...');

    const url = '{{ route("mining-manager.diagnostic.test-notification") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;

    fetch(relativeUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
        },
        body: JSON.stringify(postData)
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Preview Test';
        spinner.style.display = 'none';

        // Clear and render all log lines
        terminal.innerHTML = '';

        if (data.logs && data.logs.length > 0) {
            data.logs.forEach(function(log) {
                appendLogLine(log.time, log.level, log.message);
            });
        }

        // Show summary
        if (data.summary) {
            document.getElementById('ntSumChannels').textContent = data.summary.channels_tested || 0;
            document.getElementById('ntSumSent').textContent = data.summary.sent || 0;
            document.getElementById('ntSumFailed').textContent = data.summary.failed || 0;
            document.getElementById('ntSumSkipped').textContent = data.summary.skipped || 0;
            summary.style.display = 'block';
        }
    })
    .catch(function(error) {
        btnText.textContent = 'Preview Test';
        spinner.style.display = 'none';
        appendLogLine(getNow(), 'error', 'Request failed: ' + error.message);
    });
}

// ============================================================================
// LIVE NOTIFICATION FIRE — routes through the full NotificationService pipeline
// ============================================================================

function runNotificationLiveFire() {
    const terminal = document.getElementById('ntTerminal');
    const btnText = document.getElementById('ntFireBtnText');
    const spinner = document.getElementById('ntFireSpinner');
    const summary = document.getElementById('ntSummary');
    const notificationType = document.getElementById('ntNotificationType').value;

    // Confirm before firing — this actually hits every subscribed webhook.
    const typeLabel = document.getElementById('ntNotificationType').options[document.getElementById('ntNotificationType').selectedIndex].text;
    if (!confirm('⚠️  Fire a LIVE ' + typeLabel + ' notification?\n\nThis will:\n  • Route through NotificationService\n  • Hit every subscribed, enabled webhook (not just the selected one)\n  • Apply corp scoping + per-type toggles\n  • Write to the audit log (mining_notification_log)\n\nContinue?')) {
        return;
    }

    // Resolve character selection — reuse the same fields as Preview Test.
    const charDropdownVisible = document.getElementById('ntCharDropdown').style.display !== 'none';
    let characterId = 0;
    let characterName = 'Test Character';
    if (charDropdownVisible) {
        const sel = document.getElementById('ntCharacterSelect');
        characterId = parseInt(sel.value) || 0;
        if (sel.selectedIndex > 0) {
            characterName = sel.options[sel.selectedIndex].text.replace(/\s*\(\d+\)$/, '');
        }
    } else {
        characterId = parseInt(document.getElementById('ntCharacterId').value) || 0;
        characterName = document.getElementById('ntCharacterName').value || 'Test Character';
    }

    const postData = {
        notification_type: notificationType,
        character_id: characterId,
        character_name: characterName,
        // Same test data fields as Preview Test — buildTestNotificationData
        // ingests these on the server side to shape $data for the wrapper.
        test_amount: parseFloat(document.getElementById('ntAmount').value) || 5000000,
        test_due_date: document.getElementById('ntDueDate').value,
        test_days_remaining: parseInt(document.getElementById('ntDaysRemaining').value) || 7,
        test_days_overdue: parseInt(document.getElementById('ntDaysOverdue').value) || 3,
        test_event_name: document.getElementById('ntEventName').value || 'Test Mining Event',
        test_location: document.getElementById('ntLocation').value || 'Jita',
        test_structure_id: parseInt(document.getElementById('ntStructureId').value) || 1000000000001,
        test_structure_name: document.getElementById('ntStructureName').value || 'Athanor - Test Moon',
        test_moon_name: document.getElementById('ntMoonName').value || 'Perimeter I - Moon 1',
        // extraction_at_risk flavor — ignored by the server for other types
        test_alert_flavor: document.getElementById('ntExtractionFlavor')?.value || 'fuel_critical',
        test_severity: document.getElementById('ntSeverity')?.value || 'medium',
        test_ore_value: parseFloat(document.getElementById('ntOreValue')?.value) || 50000000,
        test_tax_owed: parseFloat(document.getElementById('ntTaxOwed')?.value) || 5000000,
        test_activity_count: parseInt(document.getElementById('ntActivityCount')?.value) || 3,
    };

    terminal.innerHTML = '';
    summary.style.display = 'none';
    btnText.textContent = 'Firing...';
    spinner.style.display = 'inline-block';

    appendLogLine(getNow(), 'info', 'Firing live notification through NotificationService pipeline...');

    const url = '{{ route("mining-manager.diagnostic.fire-notification") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;

    fetch(relativeUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
        },
        body: JSON.stringify(postData)
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Fire Live Notification';
        spinner.style.display = 'none';

        terminal.innerHTML = '';
        if (data.logs && data.logs.length > 0) {
            data.logs.forEach(function(log) {
                appendLogLine(log.time, log.level, log.message);
            });
        } else if (data.success) {
            appendLogLine(getNow(), 'ok', 'Live fire completed with no log output.');
        } else {
            appendLogLine(getNow(), 'error', 'Live fire failed.');
        }
    })
    .catch(function(error) {
        btnText.textContent = 'Fire Live Notification';
        spinner.style.display = 'none';
        appendLogLine(getNow(), 'error', 'Request failed: ' + error.message);
    });
}

// ============================================================================
// FIRE ALL NOTIFICATIONS IN CHAIN — end-to-end QA smoke test
// Fires every notification type sequentially with a 1.5s delay between each
// so Discord's 45-req/min webhook rate limit stays comfortable.
// ============================================================================

function runFireAllNotifications() {
    const terminal = document.getElementById('ntTerminal');
    const btnText = document.getElementById('ntFireAllBtnText');
    const spinner = document.getElementById('ntFireAllSpinner');
    const summary = document.getElementById('ntSummary');

    // All 15 notification types grouped by surface.
    // Order: lightest first (tax/event) → moon/report → theft last
    // so the user sees quick wins before the longer theft chain.
    const allTypes = [
        { type: 'tax_reminder',     label: '⏰ Tax Reminder' },
        { type: 'tax_invoice',      label: '📧 Tax Invoice' },
        { type: 'tax_overdue',      label: '❌ Tax Overdue' },
        { type: 'tax_generated',    label: '📋 Mining Taxes Summary' },
        { type: 'tax_announcement', label: '📢 Invoices Announcement' },
        { type: 'event_created',    label: '📅 Event Created' },
        { type: 'event_started',    label: '🚀 Event Started' },
        { type: 'event_completed',  label: '🏁 Event Completed' },
        { type: 'moon_ready',          label: '🌙 Moon Chunk Ready' },
        { type: 'jackpot_detected',    label: '🎰 Jackpot Detected' },
        { type: 'moon_chunk_unstable', label: '⚠️ Moon Chunk Unstable' },
        { type: 'extraction_at_risk',  label: '🔥 Extraction at Risk' },
        { type: 'extraction_lost',     label: '☠️ Extraction Lost' },
        { type: 'report_generated',    label: '📊 Report Generated' },
        { type: 'theft_detected',   label: '⚠️ Theft Detected' },
        { type: 'critical_theft',   label: '🔴 Critical Theft' },
        { type: 'active_theft',     label: '🔥 Active Theft' },
        { type: 'incident_resolved',label: '✅ Incident Resolved' },
    ];

    if (!confirm('⚠️  Fire ALL ' + allTypes.length + ' notification types through the full pipeline?\n\n' +
        '  • One notification per type, chained 1.5s apart\n' +
        '  • Every subscribed, enabled webhook receives each one\n' +
        '  • Respects corp scoping + per-type toggles\n' +
        '  • Writes ' + allTypes.length + ' audit-log rows\n' +
        '  • Takes ~' + Math.ceil(allTypes.length * 1.5) + ' seconds to complete\n\n' +
        'Continue?')) {
        return;
    }

    // Resolve character selection (same as single-fire, re-used for the chain)
    const charDropdownVisible = document.getElementById('ntCharDropdown').style.display !== 'none';
    let characterId = 0;
    let characterName = 'Test Character';
    if (charDropdownVisible) {
        const sel = document.getElementById('ntCharacterSelect');
        characterId = parseInt(sel.value) || 0;
        if (sel.selectedIndex > 0) {
            characterName = sel.options[sel.selectedIndex].text.replace(/\s*\(\d+\)$/, '');
        }
    } else {
        characterId = parseInt(document.getElementById('ntCharacterId').value) || 0;
        characterName = document.getElementById('ntCharacterName').value || 'Test Character';
    }

    // Shared payload template — buildTestNotificationData on the server
    // reads these per-type, so one snapshot of the form fields serves all 15.
    const basePayload = {
        character_id: characterId,
        character_name: characterName,
        test_amount: parseFloat(document.getElementById('ntAmount').value) || 5000000,
        test_due_date: document.getElementById('ntDueDate').value,
        test_days_remaining: parseInt(document.getElementById('ntDaysRemaining').value) || 7,
        test_days_overdue: parseInt(document.getElementById('ntDaysOverdue').value) || 3,
        test_event_name: document.getElementById('ntEventName').value || 'Test Mining Event',
        test_location: document.getElementById('ntLocation').value || 'Jita',
        test_structure_id: parseInt(document.getElementById('ntStructureId').value) || 1000000000001,
        test_structure_name: document.getElementById('ntStructureName').value || 'Athanor - Test Moon',
        test_moon_name: document.getElementById('ntMoonName').value || 'Perimeter I - Moon 1',
        // extraction_at_risk flavor — ignored by the server for other types
        test_alert_flavor: document.getElementById('ntExtractionFlavor')?.value || 'fuel_critical',
        test_severity: document.getElementById('ntSeverity')?.value || 'medium',
        test_ore_value: parseFloat(document.getElementById('ntOreValue')?.value) || 50000000,
        test_tax_owed: parseFloat(document.getElementById('ntTaxOwed')?.value) || 5000000,
        test_activity_count: parseInt(document.getElementById('ntActivityCount')?.value) || 3,
    };

    terminal.innerHTML = '';
    summary.style.display = 'none';
    btnText.textContent = 'Firing...';
    spinner.style.display = 'inline-block';

    appendLogLine(getNow(), 'info', '=== Fire ALL Chain Started — ' + allTypes.length + ' notification types ===');

    const url = '{{ route("mining-manager.diagnostic.fire-notification") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;
    const delayMs = 1500;

    const results = { success: 0, failed: 0 };
    const startTime = Date.now();

    // Sequential chain — each type's request waits for the previous to settle
    // + a buffer delay before firing the next. Keeps Discord rate limits happy
    // and makes the log easier to read (one block per type, in order).
    function fireOne(index) {
        if (index >= allTypes.length) {
            // Chain finished
            const totalMs = Date.now() - startTime;
            appendLogLine(getNow(), 'info', '');
            appendLogLine(getNow(), 'info', '=== Fire ALL Chain Complete (' + totalMs + 'ms total) ===');
            appendLogLine(getNow(), results.failed === 0 ? 'ok' : 'warn',
                'Summary: ' + results.success + ' succeeded, ' + results.failed + ' failed out of ' + allTypes.length);
            btnText.textContent = 'Fire ALL (Chain)';
            spinner.style.display = 'none';
            return;
        }

        const entry = allTypes[index];
        const postData = Object.assign({}, basePayload, { notification_type: entry.type });

        appendLogLine(getNow(), 'info', '');
        appendLogLine(getNow(), 'info', '▶ [' + (index + 1) + '/' + allTypes.length + '] Firing ' + entry.label + ' (' + entry.type + ')');

        fetch(relativeUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify(postData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.logs && data.logs.length > 0) {
                // Only surface key ok/warn/error lines to keep the chain log
                // readable. Full per-type logs are still available via single-fire.
                data.logs.forEach(function(log) {
                    if (log.level === 'ok' || log.level === 'warn' || log.level === 'error') {
                        appendLogLine(log.time, log.level, '    ' + log.message);
                    }
                });
            }

            if (data.success) {
                results.success++;
                appendLogLine(getNow(), 'ok', '✓ ' + entry.label + ' dispatched');
            } else {
                results.failed++;
                appendLogLine(getNow(), 'error', '✗ ' + entry.label + ' failed');
            }

            // Schedule the next one
            setTimeout(function() { fireOne(index + 1); }, delayMs);
        })
        .catch(function(error) {
            results.failed++;
            appendLogLine(getNow(), 'error', '✗ ' + entry.label + ' request failed: ' + error.message);
            setTimeout(function() { fireOne(index + 1); }, delayMs);
        });
    }

    fireOne(0);
}

function appendLogLine(time, level, message) {
    const terminal = document.getElementById('ntTerminal');
    const line = document.createElement('div');

    const levelClass = 'log-' + level;
    const levelLabel = level.toUpperCase();

    line.className = 'log-line ' + levelClass;
    line.innerHTML = '<span class="log-time">[' + time + ']</span> <span class="log-level">[' + levelLabel + ']</span> ' + escapeHtml(message);

    terminal.appendChild(line);
    terminal.scrollTop = terminal.scrollHeight;
}

function clearNotificationLog() {
    const terminal = document.getElementById('ntTerminal');
    terminal.innerHTML = '<div class="log-line log-skip">[--:--:--.---] [INFO] Log cleared. Ready for next test.</div>';
    document.getElementById('ntSummary').style.display = 'none';
}

function getNow() {
    const d = new Date();
    return d.toTimeString().substring(0, 8) + '.' + String(d.getMilliseconds()).padStart(3, '0');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

function updateNotifTestFields() {
    const type = document.getElementById('ntNotificationType').value;

    document.getElementById('ntTaxFields').style.display = 'none';
    document.getElementById('ntEventFields').style.display = 'none';
    document.getElementById('ntMoonFields').style.display = 'none';
    document.getElementById('ntTheftFields').style.display = 'none';

    document.getElementById('ntDaysRemainingGroup').style.display = 'none';
    document.getElementById('ntDaysOverdueGroup').style.display = 'none';
    document.getElementById('ntActiveTheftFields').style.display = 'none';

    // Flavor selector is extraction_at_risk-only — reset on every change
    const flavorGroup = document.getElementById('ntExtractionFlavorGroup');
    if (flavorGroup) flavorGroup.style.display = 'none';

    if (type === 'tax_reminder' || type === 'tax_invoice' || type === 'tax_overdue' || type === 'tax_generated' || type === 'tax_announcement') {
        document.getElementById('ntTaxFields').style.display = 'block';
        if (type === 'tax_reminder') {
            document.getElementById('ntDaysRemainingGroup').style.display = 'block';
        } else if (type === 'tax_overdue') {
            document.getElementById('ntDaysOverdueGroup').style.display = 'block';
        }
    } else if (type === 'event_created' || type === 'event_started' || type === 'event_completed') {
        document.getElementById('ntEventFields').style.display = 'block';
    } else if (type === 'moon_ready' || type === 'jackpot_detected' || type === 'moon_chunk_unstable' || type === 'extraction_at_risk' || type === 'extraction_lost') {
        // All five moon-category types reuse Structure Name / Moon Name / ID.
        // Only extraction_at_risk exposes the flavor selector (4 flavors).
        document.getElementById('ntMoonFields').style.display = 'block';
        if (type === 'extraction_at_risk' && flavorGroup) {
            flavorGroup.style.display = 'block';
        }
    } else if (type === 'theft_detected' || type === 'critical_theft' || type === 'active_theft' || type === 'incident_resolved') {
        document.getElementById('ntTheftFields').style.display = 'block';
        if (type === 'active_theft') {
            document.getElementById('ntActiveTheftFields').style.display = 'block';
        }
    }
}

function toggleNtCharInput() {
    const dropdown = document.getElementById('ntCharDropdown');
    const manual = document.getElementById('ntCharManual');
    const toggleText = document.getElementById('ntCharToggleText');

    if (dropdown.style.display !== 'none') {
        dropdown.style.display = 'none';
        manual.style.display = 'block';
        toggleText.textContent = 'Select from List';
    } else {
        dropdown.style.display = 'block';
        manual.style.display = 'none';
        toggleText.textContent = 'Enter ID';
    }
}

function toggleNtWebhookInput() {
    const dropdown = document.getElementById('ntWebhookDropdown');
    const manual = document.getElementById('ntWebhookManual');
    const toggleText = document.getElementById('ntWebhookToggleText');

    if (dropdown.style.display !== 'none') {
        dropdown.style.display = 'none';
        manual.style.display = 'block';
        toggleText.textContent = 'Select from List';
    } else {
        dropdown.style.display = 'block';
        manual.style.display = 'none';
        toggleText.textContent = 'Custom URL';
    }
}

// Toggle sender section visibility when ESI channel is checked/unchecked
function updateNtSenderVisibility() {
    const esiChecked = document.getElementById('ntChannelEsi').checked;
    document.getElementById('ntSenderSection').style.display = esiChecked ? 'block' : 'none';
}

// Toggle sender sub-options (settings/character)
function updateNtSenderMode() {
    const mode = document.querySelector('input[name="ntSenderMode"]:checked')?.value || 'settings';
    document.getElementById('ntSenderSettingsInfo').style.display = mode === 'settings' ? 'block' : 'none';
    document.getElementById('ntSenderCharSelect').style.display = mode === 'character' ? 'block' : 'none';
}

// ============================================================================
// MOON EXTRACTION DIAGNOSTIC
// ============================================================================

function runMoonDiagnostic() {
    const extractionId = document.getElementById('moonDiagExtractionId').value;
    const structureId = document.getElementById('moonDiagStructureId').value;
    const resultsDiv = document.getElementById('moonDiagResults');
    const btnText = document.getElementById('moonDiagBtnText');
    const spinner = document.getElementById('moonDiagSpinner');

    if (!extractionId && !structureId) {
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Error</h5><p>Please enter an Extraction ID or Structure ID</p></div>`;
        return;
    }

    btnText.textContent = 'Scanning...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.moon-diagnostic") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;
    let params = [];
    if (extractionId) params.push('extraction_id=' + extractionId);
    if (structureId) params.push('structure_id=' + structureId);

    fetch(relativeUrl + '?' + params.join('&'), {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Run Moon Diagnostic';
        spinner.style.display = 'none';

        if (!data.success) {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Failed</h5><p>${data.error || 'Unknown error'}</p></div>`;
            return;
        }

        const fmtISK = (v) => Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
        const fmtNum = (v) => Number(v || 0).toLocaleString();
        let html = '';

        const extractions = data.extractions || [];
        if (extractions.length === 0) {
            html += `<div class="provider-test-result warning"><h5><i class="fas fa-info-circle text-warning"></i> No Extractions Found</h5><p>No moon extractions match the given criteria.</p></div>`;
        }

        extractions.forEach((ext, idx) => {
            // Status card - controller returns ext.status.database, ext.status.effective, ext.status.mismatch
            const extStatus = ext.status || {};
            const dbStatus = extStatus.database || 'unknown';
            const effectiveStatus = extStatus.effective || 'unknown';
            const statusMismatch = extStatus.mismatch || false;
            const statusClass = statusMismatch ? 'warning' : 'success';
            html += `<div class="provider-test-result ${statusClass}" style="margin-top: ${idx > 0 ? '15px' : '0'};">
                <h5><i class="fas fa-moon"></i> Extraction #${ext.extraction_id || idx + 1} - ${ext.structure_name || 'Unknown Structure'}</h5>
                <div class="row mb-2">
                    <div class="col-md-3"><strong>DB Status:</strong> <span class="badge badge-${dbStatus === 'completed' ? 'success' : (dbStatus === 'active' ? 'primary' : 'secondary')}">${dbStatus.toUpperCase()}</span></div>
                    <div class="col-md-3"><strong>Effective Status:</strong> <span class="badge badge-${effectiveStatus === 'completed' ? 'success' : (effectiveStatus === 'active' ? 'primary' : 'secondary')}">${effectiveStatus.toUpperCase()}</span></div>
                    <div class="col-md-3"><strong>Moon:</strong> ${ext.moon_name || 'Unknown'}</div>
                    <div class="col-md-3"><strong>Structure ID:</strong> ${ext.structure_id || 'N/A'}</div>
                </div>`;

            if (statusMismatch) {
                html += `<div class="alert alert-warning mb-2"><i class="fas fa-exclamation-triangle"></i> <strong>Status Mismatch:</strong> DB status (${dbStatus}) differs from effective status (${effectiveStatus}).</div>`;
            }

            // Timeline card - controller returns ext.timeline.*
            const tl = ext.timeline || {};
            html += `<hr><h6><i class="fas fa-clock"></i> Timeline</h6>
                <div class="row mb-2">
                    <div class="col-md-3"><strong>Arrival:</strong><br>${tl.chunk_arrival || 'N/A'}</div>
                    <div class="col-md-3"><strong>Fracture:</strong><br>${tl.fracture_time_calculated || tl.fractured_at || 'N/A'}</div>
                    <div class="col-md-3"><strong>Unstable Start:</strong><br>${tl.unstable_start || 'N/A'}</div>
                    <div class="col-md-3"><strong>Expiry:</strong><br>${tl.expiry_time || 'N/A'}</div>
                </div>`;

            // Fracture detection - controller returns ext.fracture_detection.had_fracture_data, .detected_now
            if (ext.fracture_detection !== undefined) {
                const fracResult = ext.fracture_detection;
                const fracDetected = fracResult.detected_now || false;
                const hadData = fracResult.had_fracture_data || false;
                const fracClass = hadData ? 'success' : (fracDetected ? 'primary' : 'warning');
                html += `<hr><h6><i class="fas fa-hammer"></i> Fracture Detection</h6>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Had Fracture Data:</strong> <span class="badge badge-${hadData ? 'success' : 'secondary'}">${hadData ? 'YES' : 'NO'}</span></div>
                        <div class="col-md-4"><strong>Detected Now:</strong> <span class="badge badge-${fracDetected ? 'success' : 'warning'}">${fracDetected ? 'YES' : 'NO'}</span></div>
                        <div class="col-md-4"><strong>Fractured By:</strong> ${tl.fractured_by || 'N/A'}${tl.auto_fractured ? ' (auto)' : ''}</div>
                    </div>`;
            }

            // Jackpot detection - controller returns ext.jackpot.*
            if (ext.jackpot !== undefined) {
                const jp = ext.jackpot;
                const jpClass = jp.is_jackpot ? 'success' : 'secondary';
                const jpOres = jp.jackpot_ores_in_ledger || [];
                html += `<hr><h6><i class="fas fa-gem"></i> Jackpot Detection</h6>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Jackpot:</strong> <span class="badge badge-${jpClass}">${jp.is_jackpot ? 'YES - JACKPOT!' : 'No'}</span></div>
                        <div class="col-md-4"><strong>Jackpot Ores in Ledger:</strong> ${jpOres.length}</div>
                        <div class="col-md-4"><strong>Detected At:</strong> ${jp.jackpot_detected_at || 'N/A'}</div>
                    </div>`;
                if (jpOres.length > 0) {
                    html += `<table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                        <thead><tr><th>Ore</th><th class="text-right">Quantity</th></tr></thead><tbody>`;
                    jpOres.forEach(ore => {
                        html += `<tr>
                            <td><i class="fas fa-gem text-warning"></i> ${ore.name || 'Type ' + ore.type_id}</td>
                            <td class="text-right">${fmtNum(ore.quantity)}</td>
                        </tr>`;
                    });
                    html += '</tbody></table>';
                }
            }

            // Composition - controller returns ext.composition as object: {has_data, has_notification_data, ore_count, estimated_value}
            if (ext.composition && ext.composition.has_data) {
                html += `<hr><h6><i class="fas fa-layer-group"></i> Composition</h6>
                    <div class="row mb-2">
                        <div class="col-md-3"><strong>Has Data:</strong> <span class="badge badge-success">YES</span></div>
                        <div class="col-md-3"><strong>Notification Data:</strong> <span class="badge badge-${ext.composition.has_notification_data ? 'success' : 'secondary'}">${ext.composition.has_notification_data ? 'YES' : 'NO'}</span></div>
                        <div class="col-md-3"><strong>Ore Count:</strong> ${ext.composition.ore_count || 0}</div>
                        <div class="col-md-3"><strong>Estimated Value:</strong> ${typeof ext.composition.estimated_value === 'string' ? ext.composition.estimated_value : fmtISK(ext.composition.estimated_value)} ISK</div>
                    </div>`;
            } else if (ext.composition) {
                html += `<hr><h6><i class="fas fa-layer-group"></i> Composition</h6>
                    <p class="text-muted">No composition data available.</p>`;
            }

            html += '</div>';
        });

        resultsDiv.innerHTML = html;
    })
    .catch(error => {
        btnText.textContent = 'Run Moon Diagnostic';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5><p>${error.message}</p></div>`;
    });
}

// ============================================================================
// TAX PIPELINE DIAGNOSTIC
// ============================================================================

function runTaxPipeline() {
    const month = document.getElementById('taxPipeMonth').value;
    const charId = document.getElementById('taxPipeCharId').value;
    const resultsDiv = document.getElementById('taxPipeResults');
    const btnText = document.getElementById('taxPipeBtnText');
    const spinner = document.getElementById('taxPipeSpinner');

    if (!month) {
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Error</h5><p>Please select a month</p></div>`;
        return;
    }

    btnText.textContent = 'Checking...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.tax-pipeline") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;
    let params = ['month=' + month];
    if (charId) params.push('character_id=' + charId);

    fetch(relativeUrl + '?' + params.join('&'), {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Run Pipeline Check';
        spinner.style.display = 'none';

        if (!data.success) {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Failed</h5><p>${data.error || 'Unknown error'}</p></div>`;
            return;
        }

        const fmtISK = (v) => Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
        const fmtNum = (v) => Number(v || 0).toLocaleString();
        let html = '';
        const pipe = data.pipeline || {};

        // Step 1: Daily Summaries - controller returns {status, total, finalized, unique_characters, all_finalized}
        const step1 = pipe.daily_summaries || {};
        const step1Total = step1.total || 0;
        const step1Class = step1Total > 0 ? 'success' : 'warning';
        html += `<div class="provider-test-result ${step1Class}" style="margin-top: 0;">
            <h5><i class="fas fa-database"></i> Step 1: Daily Summaries</h5>
            <div class="row mb-2">
                <div class="col-md-3"><strong>Total Summaries:</strong> ${fmtNum(step1Total)}</div>
                <div class="col-md-3"><strong>Finalized:</strong> ${fmtNum(step1.finalized || 0)} <span class="badge badge-${step1.all_finalized ? 'success' : 'warning'}">${step1Total > 0 ? Math.round((step1.finalized || 0) / step1Total * 100) : 0}%</span></div>
                <div class="col-md-3"><strong>Unique Characters:</strong> ${fmtNum(step1.unique_characters || 0)}</div>
                <div class="col-md-3"><strong>All Finalized:</strong> <span class="badge badge-${step1.all_finalized ? 'success' : 'warning'}">${step1.all_finalized ? 'YES' : 'NO'}</span></div>
            </div>
        </div>`;

        // Step 2: Tax Records - controller returns {status, total, by_status, total_owed, total_paid, collection_rate}
        const step2 = pipe.tax_records || {};
        const step2Total = step2.total || 0;
        const step2ByStatus = step2.by_status || {};
        const step2Class = step2Total > 0 ? 'success' : 'warning';
        html += `<div class="provider-test-result ${step2Class}" style="margin-top: 10px;">
            <h5><i class="fas fa-file-invoice-dollar"></i> Step 2: Tax Records</h5>
            <div class="row mb-2">
                <div class="col-md-3"><strong>Total Records:</strong> ${fmtNum(step2Total)}</div>
                <div class="col-md-3"><strong>Paid:</strong> <span class="text-success">${fmtNum(step2ByStatus.paid || 0)}</span></div>
                <div class="col-md-3"><strong>Unpaid:</strong> <span class="text-warning">${fmtNum(step2ByStatus.unpaid || step2ByStatus.pending || 0)}</span></div>
                <div class="col-md-3"><strong>Overdue:</strong> <span class="text-danger">${fmtNum(step2ByStatus.overdue || 0)}</span></div>
            </div>
            <div class="row mb-2">
                <div class="col-md-4"><strong>Total Owed:</strong> ${fmtISK(step2.total_owed)} ISK</div>
                <div class="col-md-4"><strong>Total Paid:</strong> ${fmtISK(step2.total_paid)} ISK</div>
                <div class="col-md-4"><strong>Collection Rate:</strong> <span class="badge badge-${(step2.collection_rate || 0) >= 80 ? 'success' : ((step2.collection_rate || 0) >= 50 ? 'warning' : 'danger')}">${step2.collection_rate || 0}%</span></div>
            </div>
        </div>`;

        // Step 3: Tax Codes - controller returns {status, total, by_status, taxes_without_codes}
        const step3 = pipe.tax_codes || {};
        const step3Total = step3.total || 0;
        const step3ByStatus = step3.by_status || {};
        html += `<div class="provider-test-result ${step3Total > 0 ? 'success' : 'warning'}" style="margin-top: 10px;">
            <h5><i class="fas fa-barcode"></i> Step 3: Tax Codes</h5>
            <div class="row mb-2">
                <div class="col-md-3"><strong>Total Codes:</strong> ${fmtNum(step3Total)}</div>
                <div class="col-md-3"><strong>Active:</strong> ${fmtNum(step3ByStatus.active || 0)}</div>
                <div class="col-md-3"><strong>Used:</strong> ${fmtNum(step3ByStatus.used || 0)}</div>
                <div class="col-md-3"><strong>Without Codes:</strong> <span class="${(step3.taxes_without_codes || 0) > 0 ? 'text-warning' : 'text-success'}">${fmtNum(step3.taxes_without_codes || 0)}</span></div>
            </div>
        </div>`;

        // Step 4: Pending Payments - controller returns {status, count} or {status, error}
        const step4 = pipe.pending_payments || {};
        html += `<div class="provider-test-result ${step4.status === 'error' ? 'error' : ((step4.count || 0) > 0 ? 'warning' : 'success')}" style="margin-top: 10px;">
            <h5><i class="fas fa-clock"></i> Step 4: Pending Payments</h5>
            <div class="row mb-2">
                <div class="col-md-6"><strong>Pending Count:</strong> ${step4.error ? '<span class="text-danger">' + step4.error + '</span>' : fmtNum(step4.count || 0)}</div>
                <div class="col-md-6"><strong>Status:</strong> <span class="badge badge-${step4.status === 'pass' ? 'success' : (step4.status === 'error' ? 'danger' : 'warning')}">${(step4.status || 'unknown').toUpperCase()}</span></div>
            </div>
        </div>`;

        // Step 5: Overdue - controller returns {status, count, overdue_records[{id, character_id, amount_owed, due_date}]}
        const step5 = pipe.overdue || {};
        html += `<div class="provider-test-result ${(step5.count || 0) > 0 ? 'error' : 'success'}" style="margin-top: 10px;">
            <h5><i class="fas fa-exclamation-triangle"></i> Step 5: Overdue</h5>
            <div class="row mb-2">
                <div class="col-md-6"><strong>Overdue Count:</strong> <span class="${(step5.count || 0) > 0 ? 'text-danger' : 'text-success'}">${fmtNum(step5.count || 0)}</span></div>
                <div class="col-md-6"><strong>Status:</strong> <span class="badge badge-${step5.status === 'pass' ? 'success' : 'warning'}">${(step5.status || 'unknown').toUpperCase()}</span></div>
            </div>`;
        const overdueRecords = step5.overdue_records || [];
        if (overdueRecords.length > 0) {
            html += `<table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                <thead><tr><th>ID</th><th>Character ID</th><th class="text-right">Amount Owed</th><th>Due Date</th></tr></thead><tbody>`;
            overdueRecords.forEach(item => {
                html += `<tr>
                    <td>${item.id || 'N/A'}</td>
                    <td>${item.character_id || 'N/A'}</td>
                    <td class="text-right">${fmtISK(item.amount_owed)} ISK</td>
                    <td>${item.due_date || 'N/A'}</td>
                </tr>`;
            });
            html += '</tbody></table>';
        }
        html += '</div>';

        // Step 6: Thresholds - controller returns {exemption_enabled, exemption_threshold, minimum_tax_amount, minimum_behavior}
        const step6 = pipe.thresholds || {};
        html += `<div class="provider-test-result success" style="margin-top: 10px;">
            <h5><i class="fas fa-sliders-h"></i> Step 6: Thresholds & Settings</h5>
            <div class="row mb-2">
                <div class="col-md-3"><strong>Exemption Enabled:</strong> <span class="badge badge-${step6.exemption_enabled ? 'success' : 'secondary'}">${step6.exemption_enabled ? 'YES' : 'NO'}</span></div>
                <div class="col-md-3"><strong>Exemption Threshold:</strong> ${fmtISK(step6.exemption_threshold)} ISK</div>
                <div class="col-md-3"><strong>Minimum Tax:</strong> ${fmtISK(step6.minimum_tax_amount)} ISK</div>
                <div class="col-md-3"><strong>Minimum Behavior:</strong> ${step6.minimum_behavior || 'N/A'}</div>
            </div>
        </div>`;

        // Optional: Character calculation - controller returns {character_id, calculated_tax, above_exemption, above_minimum} or {error}
        if (data.character_calculation) {
            const calc = data.character_calculation;
            if (calc.error) {
                html += `<div class="provider-test-result error" style="margin-top: 10px;">
                    <h5><i class="fas fa-user"></i> Character Calculation</h5>
                    <p class="text-danger">${calc.error}</p>
                </div>`;
            } else {
                html += `<div class="provider-test-result ${(calc.calculated_tax || 0) > 0 ? 'success' : 'warning'}" style="margin-top: 10px;">
                    <h5><i class="fas fa-user"></i> Character Calculation: ID ${calc.character_id || charId}</h5>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Calculated Tax:</strong> ${fmtISK(calc.calculated_tax)} ISK</div>
                        <div class="col-md-4"><strong>Above Exemption:</strong> <span class="badge badge-${calc.above_exemption ? 'success' : 'warning'}">${calc.above_exemption ? 'YES' : 'NO'}</span></div>
                        <div class="col-md-4"><strong>Above Minimum:</strong> <span class="badge badge-${calc.above_minimum ? 'success' : 'warning'}">${calc.above_minimum ? 'YES' : 'NO'}</span></div>
                    </div>
                </div>`;
            }
        }

        resultsDiv.innerHTML = html;
    })
    .catch(error => {
        btnText.textContent = 'Run Pipeline Check';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5><p>${error.message}</p></div>`;
    });
}

// ============================================================================
// THEFT DETECTION DIAGNOSTIC
// ============================================================================

function runTheftDiagnostic() {
    const charId = document.getElementById('theftDiagCharId').value;
    const resultsDiv = document.getElementById('theftDiagResults');
    const btnText = document.getElementById('theftDiagBtnText');
    const spinner = document.getElementById('theftDiagSpinner');

    btnText.textContent = 'Analyzing...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.theft-diagnostic") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;
    let params = [];
    if (charId) params.push('character_id=' + charId);

    fetch(relativeUrl + (params.length ? '?' + params.join('&') : ''), {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Run Theft Detection';
        spinner.style.display = 'none';

        if (!data.success) {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Failed</h5><p>${data.error || 'Unknown error'}</p></div>`;
            return;
        }

        const fmtISK = (v) => Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
        const fmtNum = (v) => Number(v || 0).toLocaleString();
        let html = '';

        // Statistics overview - controller returns data.statistics (from service), data.unresolved_count, data.unresolved_by_severity
        const stats = data.statistics || {};
        const unresolvedCount = data.unresolved_count || 0;
        const unresolvedBySev = data.unresolved_by_severity || {};
        const severityColors = { low: 'info', medium: 'warning', high: 'danger', critical: 'danger' };
        html += `<div class="provider-test-result ${unresolvedCount > 0 ? 'warning' : 'success'}">
            <h5><i class="fas fa-user-secret"></i> Theft Detection Overview</h5>
            <div class="row mb-2">
                <div class="col-md-4"><strong>Unresolved Incidents:</strong> <span class="text-danger">${fmtNum(unresolvedCount)}</span></div>
                <div class="col-md-8"><strong>By Severity:</strong>
                    <span class="badge badge-info ml-1">Low: ${fmtNum(unresolvedBySev.low || 0)}</span>
                    <span class="badge badge-warning ml-1">Medium: ${fmtNum(unresolvedBySev.medium || 0)}</span>
                    <span class="badge badge-danger ml-1">High: ${fmtNum(unresolvedBySev.high || 0)}</span>
                    <span class="badge badge-danger ml-1">Critical: ${fmtNum(unresolvedBySev.critical || 0)}</span>
                </div>
            </div>`;
        // Render any additional stats returned by the service
        if (Object.keys(stats).length > 0) {
            html += `<hr><h6>Service Statistics</h6><table class="table table-sm table-dark" style="font-size: 0.85em;"><tbody>`;
            Object.entries(stats).forEach(([key, val]) => {
                html += `<tr><td>${key.replace(/_/g, ' ')}</td><td><strong>${typeof val === 'number' ? fmtNum(val) : (val || 'N/A')}</strong></td></tr>`;
            });
            html += '</tbody></table>';
        }
        html += '</div>';

        // Paid characters list - controller returns [{character_id, character_name, status}]
        const paidChars = data.paid_characters || [];
        if (Array.isArray(paidChars) && paidChars.length > 0) {
            html += `<div class="provider-test-result success" style="margin-top: 10px;">
                <h5><i class="fas fa-check-circle"></i> Paid Characters (${paidChars.length})</h5>
                <table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                    <thead><tr><th>Character</th><th>Character ID</th><th>Status</th></tr></thead><tbody>`;
            paidChars.forEach(c => {
                html += `<tr>
                    <td>${c.character_name || 'Unknown'}</td>
                    <td>${c.character_id || 'N/A'}</td>
                    <td><span class="badge badge-success">${(c.status || 'N/A').toUpperCase()}</span></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        } else if (paidChars.error) {
            html += `<div class="provider-test-result error" style="margin-top: 10px;">
                <h5><i class="fas fa-exclamation-circle"></i> Paid Characters Check</h5>
                <p class="text-danger">${paidChars.error}</p>
            </div>`;
        }

        // Character analysis - controller returns {character_id, character_name, is_external_miner, moon_ore_mined_30d[], existing_incident}
        if (data.character_analysis) {
            const ca = data.character_analysis;
            const isExternal = ca.is_external_miner || false;
            const moonOres = ca.moon_ore_mined_30d || [];
            const existingIncident = ca.existing_incident;
            html += `<div class="provider-test-result ${isExternal ? 'error' : 'success'}" style="margin-top: 10px;">
                <h5><i class="fas fa-user-check"></i> Character Analysis: ${ca.character_name || 'ID: ' + charId}</h5>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>External Miner:</strong> <span class="badge badge-${isExternal ? 'danger' : 'success'}">${isExternal ? 'YES' : 'NO'}</span></div>
                    <div class="col-md-4"><strong>Moon Ores Mined (30d):</strong> ${moonOres.length} types</div>
                    <div class="col-md-4"><strong>Existing Incident:</strong> <span class="badge badge-${existingIncident ? 'warning' : 'success'}">${existingIncident ? 'YES' : 'NO'}</span></div>
                </div>`;
            if (moonOres.length > 0) {
                html += `<h6 class="mt-2">Moon Ore Mining (Last 30 Days)</h6>
                    <table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                    <thead><tr><th>Ore</th><th class="text-right">Quantity</th><th>Last Seen</th></tr></thead><tbody>`;
                moonOres.forEach(ore => {
                    html += `<tr>
                        <td><i class="fas fa-moon text-warning"></i> ${ore.name || 'Type ' + ore.type_id}</td>
                        <td class="text-right">${fmtNum(ore.quantity)}</td>
                        <td>${ore.last_seen || 'N/A'}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            }
            if (existingIncident) {
                html += `<h6 class="mt-2">Existing Incident</h6>
                    <div class="row mb-2">
                        <div class="col-md-3"><strong>ID:</strong> ${existingIncident.id || 'N/A'}</div>
                        <div class="col-md-3"><strong>Status:</strong> <span class="badge badge-${existingIncident.status === 'active' ? 'danger' : 'warning'}">${(existingIncident.status || 'N/A').toUpperCase()}</span></div>
                        <div class="col-md-3"><strong>Severity:</strong> <span class="badge badge-${severityColors[existingIncident.severity] || 'secondary'}">${(existingIncident.severity || 'N/A').toUpperCase()}</span></div>
                        <div class="col-md-3"><strong>Tax Owed:</strong> ${fmtISK(existingIncident.tax_owed)} ISK</div>
                    </div>`;
            }
            html += '</div>';
        }

        resultsDiv.innerHTML = html;
    })
    .catch(error => {
        btnText.textContent = 'Run Theft Detection';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5><p>${error.message}</p></div>`;
    });
}

// ============================================================================
// EVENT LIFECYCLE DIAGNOSTIC
// ============================================================================

function runEventDiagnostic() {
    const eventId = document.getElementById('eventDiagId').value;
    const resultsDiv = document.getElementById('eventDiagResults');
    const btnText = document.getElementById('eventDiagBtnText');
    const spinner = document.getElementById('eventDiagSpinner');

    btnText.textContent = 'Analyzing...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.event-diagnostic") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;
    let params = [];
    if (eventId) params.push('event_id=' + eventId);

    fetch(relativeUrl + (params.length ? '?' + params.join('&') : ''), {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Run Event Diagnostic';
        spinner.style.display = 'none';

        if (!data.success) {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Failed</h5><p>${data.error || 'Unknown error'}</p></div>`;
            return;
        }

        const fmtISK = (v) => Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
        const fmtNum = (v) => Number(v || 0).toLocaleString();
        let html = '';

        if (data.mode === 'single_event' && data.event) {
            // Single event details - controller returns {id, name, type, status, start_time, end_time, participant_count, total_mined, tax_modifier, is_active, is_future, duration}
            const evt = data.event;
            const statusColors = { active: 'success', upcoming: 'primary', completed: 'secondary', cancelled: 'danger' };

            // Event details card
            html += `<div class="provider-test-result success">
                <h5><i class="fas fa-calendar-alt"></i> Event: ${evt.name || 'Unnamed Event'}</h5>
                <div class="row mb-2">
                    <div class="col-md-3"><strong>Status:</strong> <span class="badge badge-${statusColors[evt.status] || 'secondary'}">${(evt.status || 'unknown').toUpperCase()}</span></div>
                    <div class="col-md-3"><strong>Type:</strong> ${evt.type || 'N/A'}</div>
                    <div class="col-md-3"><strong>Active:</strong> <span class="badge badge-${evt.is_active ? 'success' : 'secondary'}">${evt.is_active ? 'YES' : 'NO'}</span></div>
                    <div class="col-md-3"><strong>Future:</strong> <span class="badge badge-${evt.is_future ? 'primary' : 'secondary'}">${evt.is_future ? 'YES' : 'NO'}</span></div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-3"><strong>Start:</strong> ${evt.start_time || 'N/A'}</div>
                    <div class="col-md-3"><strong>End:</strong> ${evt.end_time || 'N/A'}</div>
                    <div class="col-md-3"><strong>Participants:</strong> ${fmtNum(evt.participant_count)}</div>
                    <div class="col-md-3"><strong>Tax Modifier:</strong> ${evt.tax_modifier != null ? evt.tax_modifier + '%' : 'N/A'}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Total Mined:</strong> ${fmtNum(evt.total_mined || 0)}</div>
                    <div class="col-md-4"><strong>Duration:</strong> ${evt.duration || 'N/A'}</div>
                </div>
            </div>`;

            // Progress metrics - from service (shape may vary, render generically if object)
            if (data.progress && typeof data.progress === 'object' && !data.progress.error) {
                const prog = data.progress;
                html += `<div class="provider-test-result success" style="margin-top: 10px;">
                    <h5><i class="fas fa-chart-line"></i> Progress</h5>
                    <table class="table table-sm table-dark" style="font-size: 0.85em;"><tbody>`;
                Object.entries(prog).forEach(([key, val]) => {
                    html += `<tr><td>${key.replace(/_/g, ' ')}</td><td><strong>${typeof val === 'number' ? (val > 1000 ? fmtISK(val) : fmtNum(val)) : (val || 'N/A')}</strong></td></tr>`;
                });
                html += '</tbody></table></div>';
            } else if (data.progress && data.progress.error) {
                html += `<div class="provider-test-result error" style="margin-top: 10px;">
                    <h5><i class="fas fa-chart-line"></i> Progress</h5><p class="text-danger">${data.progress.error}</p></div>`;
            }

            // Statistics - from service (shape may vary)
            if (data.statistics && typeof data.statistics === 'object' && !data.statistics.error) {
                const st = data.statistics;
                html += `<div class="provider-test-result success" style="margin-top: 10px;">
                    <h5><i class="fas fa-chart-bar"></i> Event Statistics</h5>
                    <table class="table table-sm table-dark" style="font-size: 0.85em;"><tbody>`;
                Object.entries(st).forEach(([key, val]) => {
                    html += `<tr><td>${key.replace(/_/g, ' ')}</td><td><strong>${typeof val === 'number' ? (val > 1000 ? fmtISK(val) : fmtNum(val)) : (val || 'N/A')}</strong></td></tr>`;
                });
                html += '</tbody></table></div>';
            } else if (data.statistics && data.statistics.error) {
                html += `<div class="provider-test-result error" style="margin-top: 10px;">
                    <h5><i class="fas fa-chart-bar"></i> Event Statistics</h5><p class="text-danger">${data.statistics.error}</p></div>`;
            }

            // Leaderboard - from service (shape may vary, render available fields)
            const leaderboard = Array.isArray(data.leaderboard) ? data.leaderboard : [];
            if (leaderboard.length > 0) {
                html += `<div class="provider-test-result success" style="margin-top: 10px;">
                    <h5><i class="fas fa-trophy"></i> Leaderboard (Top ${Math.min(leaderboard.length, 10)})</h5>
                    <table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                        <thead><tr><th>#</th><th>Character</th><th class="text-right">Quantity Mined</th></tr></thead><tbody>`;
                leaderboard.slice(0, 10).forEach((entry, idx) => {
                    const medal = idx === 0 ? '<span style="color: gold;">&#9733;</span>' : (idx === 1 ? '<span style="color: silver;">&#9733;</span>' : (idx === 2 ? '<span style="color: #cd7f32;">&#9733;</span>' : ''));
                    html += `<tr>
                        <td>${medal} ${idx + 1}</td>
                        <td>${entry.character_name || 'ID: ' + (entry.character_id || '?')}</td>
                        <td class="text-right">${fmtNum(entry.quantity_mined || entry.total_quantity || entry.volume || 0)}</td>
                    </tr>`;
                });
                html += '</tbody></table></div>';
            } else if (data.leaderboard && data.leaderboard.error) {
                html += `<div class="provider-test-result error" style="margin-top: 10px;">
                    <h5><i class="fas fa-trophy"></i> Leaderboard</h5><p class="text-danger">${data.leaderboard.error}</p></div>`;
            }

            // Inactive participants - controller returns [{character_id, character_name, quantity_mined, last_updated}]
            const inactiveList = Array.isArray(data.inactive_participants) ? data.inactive_participants : [];
            if (inactiveList.length > 0) {
                html += `<div class="provider-test-result warning" style="margin-top: 10px;">
                    <h5><i class="fas fa-user-clock"></i> Inactive Participants (${inactiveList.length})</h5>
                    <table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                        <thead><tr><th>Character</th><th>Last Updated</th><th class="text-right">Quantity Mined</th></tr></thead><tbody>`;
                inactiveList.forEach(p => {
                    html += `<tr>
                        <td>${p.character_name || 'ID: ' + p.character_id}</td>
                        <td>${p.last_updated || 'Never'}</td>
                        <td class="text-right">${fmtNum(p.quantity_mined || 0)}</td>
                    </tr>`;
                });
                html += '</tbody></table></div>';
            } else if (data.inactive_participants && data.inactive_participants.error) {
                html += `<div class="provider-test-result error" style="margin-top: 10px;">
                    <h5><i class="fas fa-user-clock"></i> Inactive Participants</h5><p class="text-danger">${data.inactive_participants.error}</p></div>`;
            }

        } else {
            // Overview mode - controller returns active_events, upcoming_events, recent_completed, total_active, total_upcoming
            // Active events - [{id, name, status, participant_count, total_mined}]
            const active = data.active_events || [];
            html += `<div class="provider-test-result ${active.length > 0 ? 'success' : 'warning'}">
                <h5><i class="fas fa-play-circle"></i> Active Events (${data.total_active || active.length})</h5>`;
            if (active.length > 0) {
                html += `<table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                    <thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Participants</th><th class="text-right">Total Mined</th></tr></thead><tbody>`;
                active.forEach(e => {
                    html += `<tr>
                        <td>${e.id}</td>
                        <td>${e.name || 'Unnamed'}</td>
                        <td><span class="badge badge-success">${(e.status || 'active').toUpperCase()}</span></td>
                        <td>${fmtNum(e.participant_count || 0)}</td>
                        <td class="text-right">${fmtNum(e.total_mined || 0)}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            } else {
                html += '<p class="text-muted">No active events.</p>';
            }
            html += '</div>';

            // Upcoming events - [{id, name, start_time}]
            const upcoming = data.upcoming_events || [];
            html += `<div class="provider-test-result ${upcoming.length > 0 ? 'success' : 'warning'}" style="margin-top: 10px;">
                <h5><i class="fas fa-clock"></i> Upcoming Events (${data.total_upcoming || upcoming.length})</h5>`;
            if (upcoming.length > 0) {
                html += `<table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                    <thead><tr><th>ID</th><th>Name</th><th>Start Time</th></tr></thead><tbody>`;
                upcoming.forEach(e => {
                    html += `<tr>
                        <td>${e.id}</td>
                        <td>${e.name || 'Unnamed'}</td>
                        <td>${e.start_time || 'N/A'}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            } else {
                html += '<p class="text-muted">No upcoming events.</p>';
            }
            html += '</div>';

            // Recent completed events - [{id, name, participant_count, total_mined, end_time}]
            const completed = data.recent_completed || [];
            html += `<div class="provider-test-result success" style="margin-top: 10px;">
                <h5><i class="fas fa-flag-checkered"></i> Recent Completed Events (${completed.length})</h5>`;
            if (completed.length > 0) {
                html += `<table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                    <thead><tr><th>ID</th><th>Name</th><th>Participants</th><th class="text-right">Total Mined</th><th>End Time</th></tr></thead><tbody>`;
                completed.forEach(e => {
                    html += `<tr>
                        <td>${e.id}</td>
                        <td>${e.name || 'Unnamed'}</td>
                        <td>${fmtNum(e.participant_count || 0)}</td>
                        <td class="text-right">${fmtNum(e.total_mined || 0)}</td>
                        <td>${e.end_time || 'N/A'}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            } else {
                html += '<p class="text-muted">No recently completed events.</p>';
            }
            html += '</div>';
        }

        resultsDiv.innerHTML = html;
    })
    .catch(error => {
        btnText.textContent = 'Run Event Diagnostic';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5><p>${error.message}</p></div>`;
    });
}

// ============================================================================
// ANALYTICS & REPORTS DIAGNOSTIC
// ============================================================================

function runAnalyticsDiagnostic() {
    const startDate = document.getElementById('analyticsDiagStart').value;
    const endDate = document.getElementById('analyticsDiagEnd').value;
    const resultsDiv = document.getElementById('analyticsDiagResults');
    const btnText = document.getElementById('analyticsDiagBtnText');
    const spinner = document.getElementById('analyticsDiagSpinner');

    if (!startDate || !endDate) {
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Error</h5><p>Please select both start and end dates</p></div>`;
        return;
    }

    btnText.textContent = 'Analyzing...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    const url = '{{ route("mining-manager.diagnostic.analytics-diagnostic") }}';
    const relativeUrl = new URL(url, window.location.origin).pathname;

    fetch(relativeUrl + '?start_date=' + startDate + '&end_date=' + endDate, {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Run Analytics Check';
        spinner.style.display = 'none';

        if (!data.success) {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Failed</h5><p>${data.error || 'Unknown error'}</p></div>`;
            return;
        }

        const fmtISK = (v) => Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
        const fmtNum = (v) => Number(v || 0).toLocaleString();
        let html = '';

        // All analytics data is nested under data.results.*
        const results = data.results || {};

        // Dashboard metrics - from results.dashboard_metrics (from service, shape may vary)
        const dash = results.dashboard_metrics || {};
        if (dash.error) {
            html += `<div class="provider-test-result error">
                <h5><i class="fas fa-tachometer-alt"></i> Dashboard Metrics</h5>
                <p class="text-danger">${dash.error}</p></div>`;
        } else {
            html += `<div class="provider-test-result success">
                <h5><i class="fas fa-tachometer-alt"></i> Dashboard Metrics</h5>
                <table class="table table-sm table-dark" style="font-size: 0.85em;"><tbody>`;
            Object.entries(dash).forEach(([key, val]) => {
                html += `<tr><td>${key.replace(/_/g, ' ')}</td><td><strong>${typeof val === 'number' ? (val > 1000 ? fmtISK(val) : fmtNum(val)) : (val || 'N/A')}</strong></td></tr>`;
            });
            html += '</tbody></table></div>';
        }

        // Tax metrics - from results.tax_metrics (from service, shape may vary)
        const tax = results.tax_metrics || {};
        if (tax.error) {
            html += `<div class="provider-test-result error" style="margin-top: 10px;">
                <h5><i class="fas fa-file-invoice-dollar"></i> Tax Metrics</h5>
                <p class="text-danger">${tax.error}</p></div>`;
        } else {
            html += `<div class="provider-test-result success" style="margin-top: 10px;">
                <h5><i class="fas fa-file-invoice-dollar"></i> Tax Metrics</h5>
                <table class="table table-sm table-dark" style="font-size: 0.85em;"><tbody>`;
            Object.entries(tax).forEach(([key, val]) => {
                html += `<tr><td>${key.replace(/_/g, ' ')}</td><td><strong>${typeof val === 'number' ? (val > 1000 ? fmtISK(val) : fmtNum(val)) : (val || 'N/A')}</strong></td></tr>`;
            });
            html += '</tbody></table></div>';
        }

        // Mining summary - results.mining_summary: {total_volume, total_value, unique_miners}
        const mining = results.mining_summary || {};
        if (mining.error) {
            html += `<div class="provider-test-result error" style="margin-top: 10px;">
                <h5><i class="fas fa-gem"></i> Mining Summary</h5>
                <p class="text-danger">${mining.error}</p></div>`;
        } else {
            html += `<div class="provider-test-result success" style="margin-top: 10px;">
                <h5><i class="fas fa-gem"></i> Mining Summary (${startDate} to ${endDate})</h5>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Total Volume:</strong> ${fmtNum(mining.total_volume || 0)} m3</div>
                    <div class="col-md-4"><strong>Total Value:</strong> ${fmtISK(mining.total_value)} ISK</div>
                    <div class="col-md-4"><strong>Unique Miners:</strong> ${fmtNum(mining.unique_miners || 0)}</div>
                </div>
            </div>`;
        }

        // Top miners - results.top_miners[]: {character_id, character_name, total_quantity, total_value}
        const topMiners = results.top_miners || [];
        if (topMiners.error) {
            html += `<div class="provider-test-result error" style="margin-top: 10px;">
                <h5><i class="fas fa-trophy"></i> Top Miners</h5>
                <p class="text-danger">${topMiners.error}</p></div>`;
        } else if (Array.isArray(topMiners) && topMiners.length > 0) {
            html += `<div class="provider-test-result success" style="margin-top: 10px;">
                <h5><i class="fas fa-trophy"></i> Top Miners</h5>
                <table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                    <thead><tr><th>#</th><th>Character</th><th class="text-right">Total Quantity</th><th class="text-right">Total Value</th></tr></thead><tbody>`;
            topMiners.forEach((miner, idx) => {
                const medal = idx === 0 ? '<span style="color: gold;">&#9733;</span>' : (idx === 1 ? '<span style="color: silver;">&#9733;</span>' : (idx === 2 ? '<span style="color: #cd7f32;">&#9733;</span>' : ''));
                html += `<tr>
                    <td>${medal} ${idx + 1}</td>
                    <td>${miner.character_name || 'ID: ' + miner.character_id}</td>
                    <td class="text-right">${fmtNum(miner.total_quantity || 0)}</td>
                    <td class="text-right">${fmtISK(miner.total_value)} ISK</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        }

        // Ore breakdown - results.ore_breakdown[]: {type_id, ore_name, total_quantity, total_value}
        const oreBreakdown = results.ore_breakdown || [];
        if (oreBreakdown.error) {
            html += `<div class="provider-test-result error" style="margin-top: 10px;">
                <h5><i class="fas fa-layer-group"></i> Ore Breakdown</h5>
                <p class="text-danger">${oreBreakdown.error}</p></div>`;
        } else if (Array.isArray(oreBreakdown) && oreBreakdown.length > 0) {
            html += `<div class="provider-test-result success" style="margin-top: 10px;">
                <h5><i class="fas fa-layer-group"></i> Ore Breakdown</h5>
                <table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                    <thead><tr><th>Ore</th><th class="text-right">Total Quantity</th><th class="text-right">Total Value</th></tr></thead><tbody>`;
            oreBreakdown.forEach(ore => {
                html += `<tr>
                    <td><i class="fas fa-gem"></i> ${ore.ore_name || 'Type ' + (ore.type_id || '?')}</td>
                    <td class="text-right">${fmtNum(ore.total_quantity || 0)}</td>
                    <td class="text-right">${fmtISK(ore.total_value)} ISK</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        }

        // Scheduled reports - results.scheduled_reports[]: {id, report_type, frequency, is_active, last_run, next_run}
        const scheduledReports = results.scheduled_reports || [];
        if (Array.isArray(scheduledReports) && scheduledReports.length > 0) {
            html += `<div class="provider-test-result success" style="margin-top: 10px;">
                <h5><i class="fas fa-clock"></i> Scheduled Reports (${scheduledReports.length})</h5>
                <table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                    <thead><tr><th>Report Type</th><th>Frequency</th><th>Last Run</th><th>Next Run</th><th>Status</th></tr></thead><tbody>`;
            scheduledReports.forEach(r => {
                html += `<tr>
                    <td>${r.report_type || 'Unknown'}</td>
                    <td>${r.frequency || 'N/A'}</td>
                    <td>${r.last_run || 'Never'}</td>
                    <td>${r.next_run || 'N/A'}</td>
                    <td><span class="badge badge-${r.is_active ? 'success' : 'secondary'}">${r.is_active ? 'Active' : 'Inactive'}</span></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        }

        // Monthly statistics - results.monthly_statistics[]: {year, month, is_closed, total_value, tax_owed, mining_days}
        const monthlyStats = results.monthly_statistics || [];
        if (Array.isArray(monthlyStats) && monthlyStats.length > 0) {
            html += `<div class="provider-test-result success" style="margin-top: 10px;">
                <h5><i class="fas fa-calendar"></i> Monthly Statistics</h5>
                <table class="table table-sm table-dark table-striped" style="font-size: 0.85em;">
                    <thead><tr><th>Period</th><th class="text-right">Total Value</th><th class="text-right">Tax Owed</th><th class="text-right">Mining Days</th><th>Closed</th></tr></thead><tbody>`;
            monthlyStats.forEach(m => {
                html += `<tr>
                    <td>${m.year || '?'}-${String(m.month || '?').padStart(2, '0')}</td>
                    <td class="text-right">${fmtISK(m.total_value)} ISK</td>
                    <td class="text-right">${fmtISK(m.tax_owed)} ISK</td>
                    <td class="text-right">${fmtNum(m.mining_days || 0)}</td>
                    <td><span class="badge badge-${m.is_closed ? 'success' : 'warning'}">${m.is_closed ? 'Closed' : 'Open'}</span></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        }

        resultsDiv.innerHTML = html;
    })
    .catch(error => {
        btnText.textContent = 'Run Analytics Check';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5><p>${error.message}</p></div>`;
    });
}

// Initialize fields visibility
$(document).ready(function() {
    updateNotifTestFields();
    updateNtSenderVisibility();

    // Bind ESI checkbox to show/hide sender section
    document.getElementById('ntChannelEsi').addEventListener('change', updateNtSenderVisibility);

    // Bind sender mode radios
    document.querySelectorAll('input[name="ntSenderMode"]').forEach(function(radio) {
        radio.addEventListener('change', updateNtSenderMode);
    });
});
</script>
@endpush

@endsection
