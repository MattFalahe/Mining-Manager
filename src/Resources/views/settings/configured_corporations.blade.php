@extends('web::layouts.grids.12')

@section('title', 'Mining Manager - Configured Corporations')
@section('page_header', 'Mining Manager - Configured Corporations')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/mining-manager-dashboard.css') }}">
<style>
    .corp-card {
        background: linear-gradient(135deg, #1a1d2e 0%, #2d3748 100%);
        border: 1px solid rgba(102, 126, 234, 0.3);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        transition: all 0.3s;
    }

    .corp-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        border-color: rgba(102, 126, 234, 0.6);
    }

    .corp-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px solid rgba(102, 126, 234, 0.2);
    }

    .corp-name {
        font-size: 1.5em;
        font-weight: bold;
        color: #667eea;
    }

    .corp-ticker {
        display: inline-block;
        background: rgba(102, 126, 234, 0.2);
        padding: 5px 10px;
        border-radius: 5px;
        font-weight: bold;
        color: #667eea;
        margin-left: 10px;
    }

    .tax-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-top: 15px;
    }

    .tax-item {
        background: rgba(102, 126, 234, 0.1);
        border: 1px solid rgba(102, 126, 234, 0.3);
        border-radius: 8px;
        padding: 15px;
        text-align: center;
    }

    .tax-label {
        color: #a0aec0;
        font-size: 0.85em;
        margin-bottom: 5px;
    }

    .tax-value {
        font-size: 1.5em;
        font-weight: bold;
        color: #667eea;
    }

    .settings-badge {
        background: rgba(102, 126, 234, 0.2);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.9em;
        color: #667eea;
    }

    .selector-status {
        margin-top: 15px;
        padding: 10px;
        background: rgba(102, 126, 234, 0.05);
        border-radius: 5px;
    }

    .selector-status .status-item {
        display: inline-block;
        margin-right: 15px;
        padding: 5px 10px;
        border-radius: 5px;
    }

    .selector-status .enabled {
        background: rgba(40, 167, 69, 0.2);
        color: #28a745;
    }

    .selector-status .disabled {
        background: rgba(220, 53, 69, 0.2);
        color: #dc3545;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: rgba(102, 126, 234, 0.05);
        border-radius: 10px;
        border: 2px dashed rgba(102, 126, 234, 0.3);
    }

    .empty-state i {
        font-size: 4em;
        color: rgba(102, 126, 234, 0.3);
        margin-bottom: 20px;
    }

    .btn-edit {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        color: white;
        padding: 8px 16px;
        border-radius: 5px;
        transition: all 0.3s;
    }

    .btn-edit:hover {
        transform: translateY(-2px);
        box-shadow: 0 3px 10px rgba(102, 126, 234, 0.4);
        color: white;
    }
</style>
@endpush

@section('full')
<div class="mining-manager-wrapper configured-corporations-page">

    <div class="mb-3">
        <a href="{{ route('mining-manager.settings.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Settings
        </a>
    </div>

    @if($corporations->isEmpty())
        <div class="empty-state">
            <i class="fas fa-building"></i>
            <h4>No Configured Corporations</h4>
            <p class="text-muted">
                No corporations have custom tax settings configured yet.
                <br>
                Go to <a href="{{ route('mining-manager.settings.index') }}">Settings</a> and select a corporation to configure tax rates.
            </p>
        </div>
    @else
        <div class="row">
            <div class="col-12 mb-3">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>{{ $corporations->count() }}</strong> corporation(s) have custom tax settings configured.
                    Click "Edit Settings" to modify the tax configuration for any corporation.
                </div>
            </div>
        </div>

        @foreach($corporations as $corp)
        <div class="corp-card">
            <div class="corp-header">
                <div>
                    <span class="corp-name">{{ $corp['name'] }}</span>
                    <span class="corp-ticker">[{{ $corp['ticker'] }}]</span>
                </div>
                <div>
                    <span class="settings-badge">
                        <i class="fas fa-cog"></i> {{ $corp['settings_count'] }} settings
                    </span>
                    <a href="{{ route('mining-manager.settings.index') }}?corporation_id={{ $corp['corporation_id'] }}"
                       class="btn btn-edit ml-2">
                        <i class="fas fa-edit"></i> Edit Settings
                    </a>
                </div>
            </div>

            <div class="corp-info mb-3">
                <small class="text-muted">
                    <i class="fas fa-users"></i> Members: {{ number_format($corp['member_count']) }}
                    <span class="ml-3"><i class="fas fa-hashtag"></i> Corp ID: {{ $corp['corporation_id'] }}</span>
                </small>
            </div>

            <h5><i class="fas fa-percent"></i> Tax Rates</h5>
            <div class="tax-grid">
                {{-- Moon Ore Tax Rates by Rarity --}}
                <div class="tax-item">
                    <div class="tax-label">Moon Ore (R64)</div>
                    <div class="tax-value">{{ number_format($corp['moon_ore_r64_tax'], 1) }}%</div>
                </div>
                <div class="tax-item">
                    <div class="tax-label">Moon Ore (R32)</div>
                    <div class="tax-value">{{ number_format($corp['moon_ore_r32_tax'], 1) }}%</div>
                </div>
                <div class="tax-item">
                    <div class="tax-label">Moon Ore (R16)</div>
                    <div class="tax-value">{{ number_format($corp['moon_ore_r16_tax'], 1) }}%</div>
                </div>
                <div class="tax-item">
                    <div class="tax-label">Moon Ore (R8)</div>
                    <div class="tax-value">{{ number_format($corp['moon_ore_r8_tax'], 1) }}%</div>
                </div>
                <div class="tax-item">
                    <div class="tax-label">Moon Ore (R4)</div>
                    <div class="tax-value">{{ number_format($corp['moon_ore_r4_tax'], 1) }}%</div>
                </div>
                {{-- Regular Ore Type Tax Rates --}}
                <div class="tax-item">
                    <div class="tax-label">Regular Ore</div>
                    <div class="tax-value">{{ number_format($corp['ore_tax'], 1) }}%</div>
                </div>
                <div class="tax-item">
                    <div class="tax-label">Ice</div>
                    <div class="tax-value">{{ number_format($corp['ice_tax'], 1) }}%</div>
                </div>
                <div class="tax-item">
                    <div class="tax-label">Gas</div>
                    <div class="tax-value">{{ number_format($corp['gas_tax'], 1) }}%</div>
                </div>
                <div class="tax-item">
                    <div class="tax-label">Abyssal Ore</div>
                    <div class="tax-value">{{ number_format($corp['abyssal_ore_tax'], 1) }}%</div>
                </div>
                <div class="tax-item">
                    <div class="tax-label">Triglavian Ore</div>
                    <div class="tax-value">{{ number_format($corp['triglavian_ore_tax'], 1) }}%</div>
                </div>
            </div>

            <div class="selector-status">
                <strong><i class="fas fa-filter"></i> Active Tax Selectors:</strong>
                <div class="mt-2">
                    {{-- Moon Ore Selector --}}
                    @if($corp['all_moon_ore'])
                        <span class="status-item enabled">
                            <i class="fas fa-check"></i> All Moon Ore
                        </span>
                    @elseif($corp['only_corp_moon_ore'])
                        <span class="status-item enabled">
                            <i class="fas fa-check"></i> Corp Moon Ore Only
                        </span>
                    @else
                        <span class="status-item disabled">
                            <i class="fas fa-times"></i> Moon Ore
                        </span>
                    @endif

                    {{-- Regular Ore Types --}}
                    <span class="status-item {{ $corp['tax_regular_ore'] ? 'enabled' : 'disabled' }}">
                        <i class="fas fa-{{ $corp['tax_regular_ore'] ? 'check' : 'times' }}"></i> Regular Ore
                    </span>

                    <span class="status-item {{ $corp['tax_ice'] ? 'enabled' : 'disabled' }}">
                        <i class="fas fa-{{ $corp['tax_ice'] ? 'check' : 'times' }}"></i> Ice
                    </span>

                    <span class="status-item {{ $corp['tax_gas'] ? 'enabled' : 'disabled' }}">
                        <i class="fas fa-{{ $corp['tax_gas'] ? 'check' : 'times' }}"></i> Gas
                    </span>

                    <span class="status-item {{ $corp['tax_abyssal_ore'] ? 'enabled' : 'disabled' }}">
                        <i class="fas fa-{{ $corp['tax_abyssal_ore'] ? 'check' : 'times' }}"></i> Abyssal Ore
                    </span>

                    <span class="status-item {{ $corp['tax_triglavian_ore'] ? 'enabled' : 'disabled' }}">
                        <i class="fas fa-{{ $corp['tax_triglavian_ore'] ? 'check' : 'times' }}"></i> Triglavian Ore
                    </span>
                </div>
            </div>
        </div>
        @endforeach
    @endif

</div>
@endsection
