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
<div class="nav-tabs-custom">
    <ul class="nav nav-tabs">
        <li class="active">
            <a href="#test-data" data-toggle="tab">
                <i class="fas fa-database"></i> Test Data Generation
            </a>
        </li>
        <li>
            <a href="#price-provider" data-toggle="tab">
                <i class="fas fa-dollar-sign"></i> Price Provider Testing
            </a>
        </li>
        <li>
            <a href="#cache-health" data-toggle="tab" onclick="loadCacheHealth()">
                <i class="fas fa-heartbeat"></i> Price Cache Health
            </a>
        </li>
        <li>
            <a href="#system-validation" data-toggle="tab">
                <i class="fas fa-check-circle"></i> System Validation
            </a>
        </li>
    </ul>
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
                                    <input type="number" name="count_per_corp" class="form-control" value="5" min="1" max="20">
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

    </div>{{-- /.tab-content --}}
</div>{{-- /.nav-tabs-custom --}}

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

    fetch(relativeUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ category: category })
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
</script>
@endpush

@endsection
