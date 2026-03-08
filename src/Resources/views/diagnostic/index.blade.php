@extends('web::layouts.grids.12')

@section('title', 'Mining Manager - Diagnostic Tools')
@section('page_header', 'Mining Manager - Diagnostic Tools')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
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
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper diagnostic-page">

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
                <a class="nav-link active" href="#test-data" data-toggle="tab">
                    <i class="fas fa-database"></i> Test Data Generation
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#webhook-testing" data-toggle="tab">
                    <i class="fas fa-satellite-dish"></i> Webhook Testing
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
        </ul>
    </div>
    <div class="card-body">
      <div class="tab-content">

        <!-- Test Data Generation Tab -->
        <div class="tab-pane active" id="test-data">
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

        <!-- Webhook Testing Tab -->
        <div class="tab-pane" id="webhook-testing">
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-satellite-dish"></i> Webhook Testing & Simulation
                            </h3>
                        </div>
                        <div class="card-body">
                            <p>Test your configured webhooks with simulated theft detection notifications. You can override Discord role IDs temporarily for testing purposes.</p>

                            <!-- Webhook Selection -->
                            <div class="form-group">
                                <label>Select Webhook to Test</label>
                                <select id="webhookSelect" class="form-control">
                                    <option value="">-- Select a Webhook --</option>
                                    @foreach(\MiningManager\Models\WebhookConfiguration::all() as $webhook)
                                        <option value="{{ $webhook->id }}"
                                                data-type="{{ $webhook->type }}"
                                                data-role-id="{{ $webhook->discord_role_id ?? '' }}">
                                            {{ $webhook->name }} ({{ ucfirst($webhook->type) }})
                                        </option>
                                    @endforeach
                                </select>
                                @if(\MiningManager\Models\WebhookConfiguration::count() === 0)
                                    <small class="form-text text-warning">
                                        <i class="fas fa-exclamation-triangle"></i> No webhooks configured.
                                        <a href="{{ route('mining-manager.settings.index') }}#webhooks">Go to Settings to add one</a>.
                                    </small>
                                @endif
                            </div>

                            <!-- Discord Role Override -->
                            <div id="discordRoleOverride" class="form-group" style="display: none;">
                                <label>Temporary Discord Role ID (Optional Override)</label>
                                <input type="text" id="tempRoleId" class="form-control" placeholder="123456789012345678">
                                <small class="form-text text-muted">
                                    Override the configured role ID for this test only. Leave empty to use configured role.
                                    <br>To get a role ID: Enable Developer Mode in Discord → Right-click role → Copy ID
                                </small>
                            </div>

                            <!-- Event Type Selection -->
                            <div class="form-group">
                                <label>Notification Type to Test</label>
                                <select id="eventTypeSelect" class="form-control">
                                    <option value="theft_detected">⚠️ Theft Detected (Regular)</option>
                                    <option value="critical_theft">🔴 Critical Theft (High Value)</option>
                                    <option value="active_theft">🔥 Active Theft in Progress</option>
                                    <option value="incident_resolved">✅ Incident Resolved</option>
                                </select>
                            </div>

                            <!-- Simulated Data Customization -->
                            <div class="card bg-secondary mb-3">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-edit"></i> Customize Test Data
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Character Name</label>
                                                <input type="text" id="testCharacterName" class="form-control" value="Test Miner" placeholder="Character Name">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Severity</label>
                                                <select id="testSeverity" class="form-control">
                                                    <option value="low">🟢 Low</option>
                                                    <option value="medium" selected>🟡 Medium</option>
                                                    <option value="high">🟠 High</option>
                                                    <option value="critical">🔴 Critical</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Ore Value (ISK)</label>
                                                <input type="number" id="testOreValue" class="form-control" value="50000000" min="0" step="1000000">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Tax Owed (ISK)</label>
                                                <input type="number" id="testTaxOwed" class="form-control" value="5000000" min="0" step="100000">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row" id="activeTheftFields" style="display: none;">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>New Mining Value (ISK)</label>
                                                <input type="number" id="testNewMiningValue" class="form-control" value="10000000" min="0" step="1000000">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Activity Count</label>
                                                <input type="number" id="testActivityCount" class="form-control" value="3" min="1">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Test Buttons -->
                            <button type="button" class="btn btn-mm-primary" onclick="sendTestNotification()">
                                <i class="fas fa-paper-plane"></i> <span id="sendBtnText">Send Test Notification</span>
                                <span id="sendSpinner" class="spinner-border spinner-border-sm ml-2" style="display: none;"></span>
                            </button>

                            <button type="button" class="btn btn-secondary ml-2" onclick="previewPayload()">
                                <i class="fas fa-eye"></i> Preview Payload
                            </button>

                            <!-- Test Results Container -->
                            <div id="webhook-test-results" class="mt-4"></div>
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
                                    <option value="custom">Custom Prices</option>
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
                            <p>Trace the full tax calculation chain for a specific character and month. Shows every decision: ore category, rarity, tax rate, price, and final tax amount.</p>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Character ID</label>
                                        <input type="number" id="taxTraceCharId" class="form-control" placeholder="e.g. 90000001">
                                        <small class="form-text text-muted">Enter the character_id to trace</small>
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
                                                <option value="tax_reminder">⏰ Tax Payment Reminder</option>
                                                <option value="tax_invoice">📧 Tax Invoice Created</option>
                                                <option value="tax_overdue">❌ Tax Payment Overdue</option>
                                            </optgroup>
                                            <optgroup label="Event Notifications">
                                                <option value="event_created">📅 Mining Event Created</option>
                                                <option value="event_started">🚀 Mining Event Started</option>
                                                <option value="event_completed">🏁 Mining Event Completed</option>
                                            </optgroup>
                                            <optgroup label="Moon Notifications">
                                                <option value="moon_ready">🌙 Moon Extraction Ready</option>
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
                                            <div class="custom-control custom-radio">
                                                <input type="radio" class="custom-control-input" id="ntSenderCorporation" name="ntSenderMode" value="corporation">
                                                <label class="custom-control-label" for="ntSenderCorporation">
                                                    <i class="fas fa-building"></i> Corporation
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
                                        <div id="ntSenderCorpSelect" style="display:none;">
                                            <select id="ntSenderCorporationId" class="form-control">
                                                <option value="">-- Select Corporation --</option>
                                                @foreach($ntCorporations as $corp)
                                                    <option value="{{ $corp->corporation_id }}">{{ $corp->name }} ({{ $corp->corporation_id }})</option>
                                                @endforeach
                                            </select>
                                            <small class="form-text text-muted">A character from this corp with mail scope will be auto-selected.</small>
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
                                                    <label class="small mb-1">Structure ID</label>
                                                    <input type="number" id="ntStructureId" class="form-control form-control-sm" value="1000000000001">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="mt-3 mb-3">
                                <button type="button" class="btn btn-mm-primary" onclick="runNotificationTest()">
                                    <i class="fas fa-play"></i> <span id="ntRunBtnText">Run Test</span>
                                    <span id="ntRunSpinner" class="spinner-border spinner-border-sm ml-2" style="display:none;"></span>
                                </button>
                                <button type="button" class="btn btn-secondary ml-2" onclick="clearNotificationLog()">
                                    <i class="fas fa-trash-alt"></i> Clear Log
                                </button>
                            </div>

                            <!-- Terminal Log View -->
                            <div class="notification-terminal" id="ntTerminal">
                                <div class="log-line log-skip">[--:--:--.---] [INFO] Ready. Select options and click "Run Test" to begin.</div>
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
    } else if (provider === 'custom') {
        warningText.innerHTML = 'Custom prices must be configured in Settings. Each ore type needs a manual price entry.';
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
// WEBHOOK TESTING FUNCTIONS
// ============================================================================

// Show/hide Discord role override based on webhook selection
document.getElementById('webhookSelect')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const webhookType = selectedOption.dataset.type;
    const roleOverrideDiv = document.getElementById('discordRoleOverride');
    const tempRoleInput = document.getElementById('tempRoleId');

    if (webhookType === 'discord') {
        roleOverrideDiv.style.display = 'block';
        // Pre-fill with current role ID if exists
        const currentRoleId = selectedOption.dataset.roleId;
        if (currentRoleId) {
            tempRoleInput.placeholder = `Current: ${currentRoleId}`;
        }
    } else {
        roleOverrideDiv.style.display = 'none';
        tempRoleInput.value = '';
    }
});

// Show/hide active theft fields based on event type
document.getElementById('eventTypeSelect')?.addEventListener('change', function() {
    const activeTheftFields = document.getElementById('activeTheftFields');
    if (this.value === 'active_theft') {
        activeTheftFields.style.display = 'flex';
    } else {
        activeTheftFields.style.display = 'none';
    }
});

function sendTestNotification() {
    const webhookId = document.getElementById('webhookSelect').value;
    const eventType = document.getElementById('eventTypeSelect').value;
    const resultsDiv = document.getElementById('webhook-test-results');
    const btnText = document.getElementById('sendBtnText');
    const spinner = document.getElementById('sendSpinner');

    if (!webhookId) {
        resultsDiv.innerHTML = `
            <div class="provider-test-result error">
                <h5><i class="fas fa-times-circle text-danger"></i> Error</h5>
                <p>Please select a webhook to test</p>
            </div>
        `;
        return;
    }

    // Collect test data
    const testData = {
        event_type: eventType,
        character_name: document.getElementById('testCharacterName').value,
        severity: document.getElementById('testSeverity').value,
        ore_value: parseFloat(document.getElementById('testOreValue').value),
        tax_owed: parseFloat(document.getElementById('testTaxOwed').value),
        temp_role_id: document.getElementById('tempRoleId')?.value || null,
    };

    // Add active theft specific data
    if (eventType === 'active_theft') {
        testData.new_mining_value = parseFloat(document.getElementById('testNewMiningValue').value);
        testData.activity_count = parseInt(document.getElementById('testActivityCount').value);
    }

    btnText.textContent = 'Sending...';
    spinner.style.display = 'inline-block';
    resultsDiv.innerHTML = '';

    fetch(`/mining-manager/diagnostic/test-webhook/${webhookId}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
        },
        body: JSON.stringify(testData)
    })
    .then(response => response.json())
    .then(data => {
        btnText.textContent = 'Send Test Notification';
        spinner.style.display = 'none';

        if (data.success) {
            let resultHtml = `
                <div class="provider-test-result success">
                    <h5><i class="fas fa-check-circle text-success"></i> Test Notification Sent Successfully!</h5>
                    <p><strong>Webhook:</strong> ${data.webhook_name}</p>
                    <p><strong>Type:</strong> ${data.webhook_type}</p>
                    <p><strong>Event:</strong> ${data.event_type}</p>
                    <p><strong>Delivery Time:</strong> ${data.duration_ms}ms</p>
            `;

            if (data.role_mention) {
                resultHtml += `<p><strong>Role Mentioned:</strong> ${data.role_mention}</p>`;
            }

            if (data.temp_role_used) {
                resultHtml += `<p class="text-warning"><i class="fas fa-info-circle"></i> Temporary role ID was used for this test</p>`;
            }

            resultHtml += `<p class="mb-0 text-success">Check your Discord/Slack channel for the notification!</p></div>`;
            resultsDiv.innerHTML = resultHtml;
        } else {
            resultsDiv.innerHTML = `
                <div class="provider-test-result error">
                    <h5><i class="fas fa-times-circle text-danger"></i> Test Failed</h5>
                    <p><strong>Error:</strong> ${data.error || data.message}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        btnText.textContent = 'Send Test Notification';
        spinner.style.display = 'none';
        resultsDiv.innerHTML = `
            <div class="provider-test-result error">
                <h5><i class="fas fa-times-circle text-danger"></i> Request Failed</h5>
                <p>${error.message}</p>
            </div>
        `;
    });
}

function previewPayload() {
    const webhookId = document.getElementById('webhookSelect').value;
    const eventType = document.getElementById('eventTypeSelect').value;
    const resultsDiv = document.getElementById('webhook-test-results');

    if (!webhookId) {
        resultsDiv.innerHTML = `
            <div class="provider-test-result error">
                <h5><i class="fas fa-times-circle text-danger"></i> Error</h5>
                <p>Please select a webhook to preview</p>
            </div>
        `;
        return;
    }

    // Build preview of what will be sent
    const testData = {
        event_type: eventType,
        character_name: document.getElementById('testCharacterName').value,
        severity: document.getElementById('testSeverity').value,
        ore_value: parseFloat(document.getElementById('testOreValue').value),
        tax_owed: parseFloat(document.getElementById('testTaxOwed').value),
    };

    if (eventType === 'active_theft') {
        testData.new_mining_value = parseFloat(document.getElementById('testNewMiningValue').value);
        testData.activity_count = parseInt(document.getElementById('testActivityCount').value);
    }

    resultsDiv.innerHTML = `
        <div class="provider-test-result success">
            <h5><i class="fas fa-eye text-info"></i> Payload Preview</h5>
            <p><strong>Notification Data:</strong></p>
            <pre style="background: #1a252f; padding: 15px; border-radius: 5px; max-height: 400px; overflow: auto;">${JSON.stringify(testData, null, 2)}</pre>
            <p class="mb-0 text-muted">This is the data that will be sent to your webhook. The actual Discord/Slack embed will be formatted based on this data.</p>
        </div>
    `;
}

// ============================================================================
// TYPE ID VALIDATION
// ============================================================================

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

        if (data.success) {
            let html = `<div class="provider-test-result ${data.summary.total_entries > 0 ? 'success' : 'warning'}">
                <h5><i class="fas fa-calculator"></i> Tax Trace: ${data.character.name}</h5>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Corporation:</strong> ${data.character.corporation_name || 'Unknown'}</div>
                    <div class="col-md-4"><strong>Period:</strong> ${data.period.start} to ${data.period.end}</div>
                    <div class="col-md-4"><strong>Exempt:</strong> ${data.settings_used.is_exempt ? '<span class="text-warning">YES</span>' : 'No'}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-3"><strong>Provider:</strong> ${data.settings_used.price_provider}</div>
                    <div class="col-md-3"><strong>Method:</strong> ${data.settings_used.valuation_method}</div>
                    <div class="col-md-3"><strong>Refining:</strong> ${data.settings_used.refining_efficiency}%</div>
                    <div class="col-md-3"><strong>Entries:</strong> ${data.summary.total_entries}</div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-4"><h6>Total Value: <strong>${Number(data.summary.total_value).toLocaleString('en-US', {minimumFractionDigits: 2})} ISK</strong></h6></div>
                    <div class="col-md-4"><h6>Total Tax: <strong>${Number(data.summary.total_tax).toLocaleString('en-US', {minimumFractionDigits: 2})} ISK</strong></h6></div>
                    <div class="col-md-4"><h6>Effective Rate: <strong>${data.summary.effective_rate}%</strong></h6></div>
                </div>`;

            if (data.entries.length > 0) {
                html += `<hr><h6>Entry Details (${data.entries.length} entries):</h6><div style="max-height: 400px; overflow-y: auto;">`;
                data.entries.forEach(e => {
                    const icon = e.category === 'moon_ore' ? 'fa-moon' : (e.category === 'ice' ? 'fa-snowflake' : (e.category === 'gas' ? 'fa-cloud' : 'fa-gem'));
                    html += `<div class="price-item">
                        <span><i class="fas ${icon}"></i> ${e.type_name} x${e.quantity.toLocaleString()} <small class="text-muted">[${e.category}${e.rarity ? '/' + e.rarity : ''}] @ ${e.tax_rate}%</small></span>
                        <span><strong>${Number(e.tax_amount).toLocaleString('en-US', {minimumFractionDigits: 2})} ISK</strong></span>
                    </div>`;
                });
                html += '</div>';
            } else {
                html += '<p class="text-muted mt-3">No mining entries found for this character in the selected month.</p>';
            }

            html += '</div>';
            resultsDiv.innerHTML = html;
        } else {
            resultsDiv.innerHTML = `<div class="provider-test-result error"><h5><i class="fas fa-times-circle text-danger"></i> Failed</h5><p>${data.error}</p></div>`;
        }
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

    $('#system-status-loading').html('<div class="text-center py-5"><div class="spinner-border text-info"></div><p class="text-muted mt-2">Loading system status...</p></div>');

    var ssUrl = new URL('{{ route("mining-manager.diagnostic.system-status") }}', window.location.origin).pathname;
    fetch(ssUrl)
        .then(r => r.json())
        .then(data => {
            // Daily Summaries
            var ds = data.daily_summaries || {};
            var badge = ds.status === 'healthy' ? 'success' : (ds.status === 'warning' ? 'warning' : 'danger');
            $('#ss-daily-summaries').html(
                '<span class="badge badge-' + badge + ' mb-2">' + (ds.status || 'unknown').toUpperCase() + '</span>' +
                '<table class="table table-sm table-dark">' +
                '<tr><td>Total daily summaries</td><td><strong>' + (ds.total || 0) + '</strong></td></tr>' +
                '<tr><td>Today\'s summaries</td><td>' + (ds.today || 0) + '</td></tr>' +
                '<tr><td>Yesterday\'s summaries</td><td>' + (ds.yesterday || 0) + '</td></tr>' +
                '<tr><td>Miners active today</td><td>' + (ds.miners_today || 0) + '</td></tr>' +
                '<tr><td>Missing summaries (today)</td><td>' + (ds.missing_today > 0 ? '<span class="text-warning">' + ds.missing_today + '</span>' : '0') + '</td></tr>' +
                '<tr><td>Last updated</td><td>' + (ds.last_updated_ago || 'never') + '</td></tr>' +
                '<tr><td>Finalized months</td><td>' + (ds.finalized_months || 0) + '</td></tr>' +
                '</table>'
            );

            // Multi-Corp
            var mc = data.multi_corp || {};
            var mcBadge = mc.status === 'healthy' ? 'success' : 'warning';
            var corpHtml = '<span class="badge badge-' + mcBadge + ' mb-2">' + (mc.status || 'unknown').toUpperCase() + '</span>' +
                '<table class="table table-sm table-dark">' +
                '<tr><td>Configured corporations</td><td><strong>' + (mc.configured_corporations || 0) + '</strong></td></tr>' +
                '<tr><td>Moon owner corp ID</td><td>' + (mc.moon_owner_corporation_id || '<span class="text-warning">Not set</span>') + '</td></tr>' +
                '</table>';
            if (mc.corporation_details && mc.corporation_details.length > 0) {
                corpHtml += '<h6 class="mt-3">Per-Corporation Tax Config</h6><table class="table table-sm table-dark">' +
                    '<thead><tr><th>Corp ID</th><th>Ore Rate</th><th>Moon Rates</th><th>Ore Taxed</th><th>Ice Taxed</th><th>Gas Taxed</th></tr></thead><tbody>';
                mc.corporation_details.forEach(function(c) {
                    corpHtml += '<tr><td>' + c.corporation_id + '</td>' +
                        '<td>' + (c.ore_rate || 0) + '%</td>' +
                        '<td>' + (c.has_moon_rates ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-danger"></i>') + '</td>' +
                        '<td>' + (c.ore_taxed ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>') + '</td>' +
                        '<td>' + (c.ice_taxed ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>') + '</td>' +
                        '<td>' + (c.gas_taxed ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>') + '</td></tr>';
                });
                corpHtml += '</tbody></table>';
            }
            $('#ss-multi-corp').html(corpHtml);

            // Price Cache
            var pc = data.price_cache || {};
            var pcBadge = pc.status === 'healthy' ? 'success' : (pc.status === 'critical' ? 'danger' : 'warning');
            $('#ss-price-cache').html(
                '<span class="badge badge-' + pcBadge + ' mb-2">' + (pc.status || 'unknown').toUpperCase() + '</span>' +
                '<table class="table table-sm table-dark">' +
                '<tr><td>Total cached prices</td><td><strong>' + (pc.total_cached || 0) + '</strong></td></tr>' +
                '<tr><td>Fresh (within ' + (pc.cache_duration_minutes || 60) + ' min)</td><td class="text-success">' + (pc.fresh || 0) + '</td></tr>' +
                '<tr><td>Stale</td><td class="' + (pc.stale > 0 ? 'text-warning' : '') + '">' + (pc.stale || 0) + '</td></tr>' +
                '</table>'
            );

            // Scheduled Jobs
            var jobs = data.scheduled_jobs || {};
            var jobHtml = '<table class="table table-sm table-dark"><thead><tr><th>Job</th><th>Last Activity</th><th>Status</th></tr></thead><tbody>';
            ['process-ledger', 'update-daily-summaries', 'calculate-taxes', 'cache-prices'].forEach(function(name) {
                var j = jobs[name] || {};
                var jBadge = j.status === 'healthy' ? 'success' : (j.status === 'error' ? 'danger' : 'warning');
                jobHtml += '<tr><td>' + name + '</td><td>' + (j.ago || 'never') + '</td>' +
                    '<td><span class="badge badge-' + jBadge + '">' + (j.status || 'unknown') + '</span></td></tr>';
            });
            var failed = jobs.failed_jobs_7d || 0;
            jobHtml += '<tr><td>Failed jobs (7 days)</td><td colspan="2">' +
                (failed > 0 ? '<span class="text-danger">' + failed + '</span>' : '<span class="text-success">0</span>') +
                '</td></tr>';
            jobHtml += '</tbody></table>';
            $('#ss-jobs').html(jobHtml);

            // Data Counts
            var dc = data.data_counts || {};
            $('#ss-data-counts').html(
                '<table class="table table-sm table-dark">' +
                '<tr><td>Mining ledger entries</td><td><strong>' + (dc.mining_ledger || 0).toLocaleString() + '</strong></td></tr>' +
                '<tr><td>Tax records</td><td>' + (dc.mining_taxes || 0).toLocaleString() + '</td></tr>' +
                '<tr><td>Daily summaries</td><td>' + (dc.daily_summaries || 0).toLocaleString() + '</td></tr>' +
                '<tr><td>Monthly summaries</td><td>' + (dc.monthly_summaries || 0).toLocaleString() + '</td></tr>' +
                '<tr><td>Price cache entries</td><td>' + (dc.price_cache || 0).toLocaleString() + '</td></tr>' +
                '</table>'
            );

            $('#system-status-loading').hide();
            $('#system-status-content').show();
        })
        .catch(function(err) {
            $('#system-status-loading').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Failed to load system status: ' + err.message + '</div>');
        });
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
    const senderCorporationId = parseInt(document.getElementById('ntSenderCorporationId')?.value) || 0;

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
        sender_corporation_id: senderCorporationId,
        test_ping: document.getElementById('ntTestPing')?.checked || false,
        test_amount: parseFloat(document.getElementById('ntAmount').value) || 5000000,
        test_due_date: document.getElementById('ntDueDate').value,
        test_days_remaining: parseInt(document.getElementById('ntDaysRemaining').value) || 7,
        test_days_overdue: parseInt(document.getElementById('ntDaysOverdue').value) || 3,
        test_event_name: document.getElementById('ntEventName').value || 'Test Mining Event',
        test_location: document.getElementById('ntLocation').value || 'Jita',
        test_structure_id: parseInt(document.getElementById('ntStructureId').value) || 1000000000001,
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
        btnText.textContent = 'Run Test';
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
        btnText.textContent = 'Run Test';
        spinner.style.display = 'none';
        appendLogLine(getNow(), 'error', 'Request failed: ' + error.message);
    });
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
    document.getElementById('ntDaysRemainingGroup').style.display = 'none';
    document.getElementById('ntDaysOverdueGroup').style.display = 'none';

    if (type === 'tax_reminder' || type === 'tax_invoice' || type === 'tax_overdue') {
        document.getElementById('ntTaxFields').style.display = 'block';
        if (type === 'tax_reminder') {
            document.getElementById('ntDaysRemainingGroup').style.display = 'block';
        } else if (type === 'tax_overdue') {
            document.getElementById('ntDaysOverdueGroup').style.display = 'block';
        }
    } else if (type === 'event_created' || type === 'event_started' || type === 'event_completed') {
        document.getElementById('ntEventFields').style.display = 'block';
    } else if (type === 'moon_ready') {
        document.getElementById('ntMoonFields').style.display = 'block';
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

// Toggle sender sub-options (settings/character/corporation)
function updateNtSenderMode() {
    const mode = document.querySelector('input[name="ntSenderMode"]:checked')?.value || 'settings';
    document.getElementById('ntSenderSettingsInfo').style.display = mode === 'settings' ? 'block' : 'none';
    document.getElementById('ntSenderCharSelect').style.display = mode === 'character' ? 'block' : 'none';
    document.getElementById('ntSenderCorpSelect').style.display = mode === 'corporation' ? 'block' : 'none';
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
