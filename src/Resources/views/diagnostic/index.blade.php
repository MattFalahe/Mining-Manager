@extends('web::layouts.grids.12')

@section('title', 'Mining Manager - Diagnostic Tools')
@section('page_header', 'Mining Manager - Diagnostic Tools')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .diagnostic-card {
        background: linear-gradient(135deg, #1a1d2e 0%, #2d3748 100%);
        border: 1px solid rgba(102, 126, 234, 0.3);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .diagnostic-card h4 {
        color: #667eea;
        margin-bottom: 15px;
    }

    .stat-box {
        background: rgba(102, 126, 234, 0.1);
        border: 1px solid rgba(102, 126, 234, 0.3);
        border-radius: 8px;
        padding: 15px;
        text-align: center;
        margin-bottom: 10px;
    }

    .stat-box .stat-number {
        font-size: 2em;
        font-weight: bold;
        color: #667eea;
    }

    .stat-box .stat-label {
        color: #a0aec0;
        font-size: 0.9em;
        margin-top: 5px;
    }

    .warning-box {
        background: rgba(255, 193, 7, 0.1);
        border: 1px solid rgba(255, 193, 7, 0.3);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }

    .danger-box {
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid rgba(220, 53, 69, 0.3);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }

    .btn-generate {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        color: white;
        padding: 10px 20px;
        border-radius: 5px;
        transition: all 0.3s;
    }

    .btn-generate:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
    }

    .btn-danger-custom {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        border: none;
        color: white;
        padding: 10px 20px;
        border-radius: 5px;
        transition: all 0.3s;
    }

    .btn-danger-custom:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(220, 53, 69, 0.4);
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper diagnostic-page">

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

    {{-- Warning Banner --}}
    <div class="warning-box">
        <h5><i class="fas fa-exclamation-triangle"></i> Test Environment Only</h5>
        <p class="mb-0">
            These diagnostic tools generate fake data for testing purposes.
            <strong>Use only in development/testing environments!</strong>
            All test data is prefixed with "Test" for easy identification.
        </p>
    </div>

    {{-- Current Test Data Stats --}}
    <div class="diagnostic-card">
        <h4><i class="fas fa-chart-bar"></i> Current Test Data</h4>
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
                    <div class="stat-number">{{ number_format($testDataCounts['mining_ledger']) }}</div>
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
    </div>

    {{-- Step 1: Generate Corporations --}}
    <div class="diagnostic-card">
        <h4><i class="fas fa-building"></i> Step 1: Generate Test Corporations</h4>
        <p class="text-muted">
            Create test corporations with IDs starting from 98000001. These will be used to test multi-corporation settings.
        </p>
        <form method="POST" action="{{ route('mining-manager.diagnostic.generate-corporations') }}" class="form-inline">
            @csrf
            <div class="form-group mr-3">
                <label for="corp_count" class="mr-2">Number of Corporations:</label>
                <input type="number" class="form-control" id="corp_count" name="count" value="3" min="1" max="10">
            </div>
            <button type="submit" class="btn btn-generate">
                <i class="fas fa-plus-circle"></i> Generate Corporations
            </button>
        </form>
    </div>

    {{-- Step 2: Generate Characters --}}
    <div class="diagnostic-card">
        <h4><i class="fas fa-users"></i> Step 2: Generate Test Characters (Miners)</h4>
        <p class="text-muted">
            Create test miner characters for each corporation. Character IDs start from 90000000.
        </p>
        <form method="POST" action="{{ route('mining-manager.diagnostic.generate-characters') }}" class="form-inline">
            @csrf
            <div class="form-group mr-3">
                <label for="chars_per_corp" class="mr-2">Characters per Corporation:</label>
                <input type="number" class="form-control" id="chars_per_corp" name="characters_per_corp" value="5" min="1" max="20">
            </div>
            <button type="submit" class="btn btn-generate">
                <i class="fas fa-user-plus"></i> Generate Characters
            </button>
        </form>
    </div>

    {{-- Step 3: Generate Mining Data --}}
    <div class="diagnostic-card">
        <h4><i class="fas fa-gem"></i> Step 3: Generate Mining Ledger Data</h4>
        <p class="text-muted">
            Generate fake mining data for all test characters. Includes various ore types (moon ores R64-R4, regular ores, ice, gas).
        </p>
        <form method="POST" action="{{ route('mining-manager.diagnostic.generate-mining-data') }}" class="form-inline">
            @csrf
            <div class="form-group mr-3">
                <label for="days" class="mr-2">Days of Data:</label>
                <input type="number" class="form-control" id="days" name="days" value="30" min="1" max="365">
            </div>
            <div class="form-group mr-3">
                <label for="entries_per_day" class="mr-2">Entries per Day per Character:</label>
                <input type="number" class="form-control" id="entries_per_day" name="entries_per_day" value="10" min="1" max="50">
            </div>
            <button type="submit" class="btn btn-generate">
                <i class="fas fa-database"></i> Generate Mining Data
            </button>
        </form>
    </div>

    {{-- Information Box --}}
    <div class="diagnostic-card">
        <h4><i class="fas fa-info-circle"></i> Test Data Details</h4>
        <ul>
            <li><strong>Test Corporations:</strong> Named "Test Corp 1", "Test Corp 2", etc. with tickers TST01, TST02, etc.</li>
            <li><strong>Test Characters:</strong> Named "Test Miner TST01-1", "Test Miner TST01-2", etc.</li>
            <li><strong>Ore Types Included:</strong>
                <ul>
                    <li>Moon Ores: R64 (Xenotime, Monazite), R32 (Chromite, Platinum), R16 (Cobaltite, Titanite), R8 (Zeolites, Scheelite), R4 (Bitumens, Sylvite)</li>
                    <li>Regular Ores: Veldspar, Scordite, Pyroxeres</li>
                    <li>Ice: Clear Icicle, Blue Ice</li>
                    <li>Gas: Mykoserocin, Cytoserocin</li>
                </ul>
            </li>
            <li><strong>Mining Quantities:</strong> Random amounts between 1,000 and 50,000 units per entry</li>
            <li><strong>Solar Systems:</strong> Various null-sec systems for realistic testing</li>
        </ul>
    </div>

    {{-- Usage Instructions --}}
    <div class="diagnostic-card">
        <h4><i class="fas fa-clipboard-list"></i> How to Test Multi-Corporation Tax Settings</h4>
        <ol>
            <li>Generate 3-5 test corporations using Step 1</li>
            <li>Generate 5-10 characters per corporation using Step 2</li>
            <li>Generate 30 days of mining data using Step 3</li>
            <li>Go to <a href="{{ route('mining-manager.settings.index') }}">Settings</a> and select a test corporation</li>
            <li>Configure different tax rates for each corporation (e.g., Corp 1: 15% R64, Corp 2: 20% R64, Corp 3: 10% R64)</li>
            <li>Run tax calculations to see how different corporations have different tax amounts</li>
            <li>Test the "Switch Corporation" functionality to verify settings are isolated</li>
            <li>View all configured corporations at <a href="{{ route('mining-manager.settings.configured-corporations') }}">Configured Corporations</a> page</li>
        </ol>
    </div>

    {{-- Cleanup Section --}}
    <div class="danger-box">
        <h5><i class="fas fa-trash-alt"></i> Cleanup Test Data</h5>
        <p>
            Remove all test data from the database. This will delete:
        </p>
        <ul>
            <li>All test corporations (Test Corp%)</li>
            <li>All test characters (Test Miner%)</li>
            <li>All mining ledger entries for test characters</li>
            <li>All tax records for test characters</li>
        </ul>
        <form method="POST" action="{{ route('mining-manager.diagnostic.cleanup') }}" onsubmit="return confirm('Are you sure you want to delete ALL test data? This cannot be undone!');">
            @csrf
            <button type="submit" class="btn btn-danger-custom">
                <i class="fas fa-exclamation-triangle"></i> Delete All Test Data
            </button>
        </form>
    </div>

</div>
@endsection
