@extends('web::layouts.grids.12')

@section('title', 'Mining Manager - Settings')

@push('head')
<link rel="stylesheet" href="{{ asset('web/assets/mining-manager/css/mining-manager.css') }}">
<style>
    .nav-tabs-custom > .nav-tabs > li.active {
        border-top-color: #007bff;
    }
    .tax-rate-input {
        width: 80px;
    }
    .setting-description {
        color: #6c757d;
        font-size: 0.875rem;
        margin-top: 0.25rem;
    }
</style>
@endpush

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-cog"></i> Mining Manager Settings
                </h3>
            </div>
            <form action="{{ route('mining.settings.update') }}" method="POST" id="settings-form">
                @csrf
                <div class="card-body">
                    <!-- Tabs -->
                    <div class="nav-tabs-custom">
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-toggle="tab" href="#general" role="tab">
                                    <i class="fas fa-cog"></i> General
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-toggle="tab" href="#taxes" role="tab">
                                    <i class="fas fa-calculator"></i> Tax Rates
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-toggle="tab" href="#contracts" role="tab">
                                    <i class="fas fa-file-contract"></i> Contracts
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-toggle="tab" href="#pricing" role="tab">
                                    <i class="fas fa-tag"></i> Pricing
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-toggle="tab" href="#features" role="tab">
                                    <i class="fas fa-toggle-on"></i> Features
                                </a>
                            </li>
                        </ul>
                        
                        <div class="tab-content pt-3">
                            <!-- General Tab -->
                            <div class="tab-pane fade show active" id="general" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="corporation_id">
                                                <i class="fas fa-building"></i> Corporation
                                            </label>
                                            <select class="form-control select2" name="corporation_id" id="corporation_id" required>
                                                <option value="">Select Corporation...</option>
                                                @foreach($corporations as $corp)
                                                <option value="{{ $corp->corporation_id }}" 
                                                    {{ $settings->corporation_id == $corp->corporation_id ? 'selected' : '' }}>
                                                    {{ $corp->name }}
                                                </option>
                                                @endforeach
                                            </select>
                                            <p class="setting-description">
                                                Select your corporation for mining tracking
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="tax_calculation_method">
                                                <i class="fas fa-users"></i> Tax Calculation Method
                                            </label>
                                            <select class="form-control" name="tax_calculation_method" id="tax_calculation_method">
                                                <option value="individual" {{ $settings->tax_calculation_method == 'individual' ? 'selected' : '' }}>
                                                    Individual (Per Character)
                                                </option>
                                                <option value="main_character" {{ $settings->tax_calculation_method == 'main_character' ? 'selected' : '' }}>
                                                    Main Character (Combined Alts)
                                                </option>
                                                <option value="corporation" {{ $settings->tax_calculation_method == 'corporation' ? 'selected' : '' }}>
                                                    Corporation Total
                                                </option>
                                            </select>
                                            <p class="setting-description">
                                                How to group characters for tax calculation
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="refining_efficiency">
                                                <i class="fas fa-percentage"></i> Refining Efficiency
                                            </label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" name="refining_efficiency" 
                                                       id="refining_efficiency" value="{{ $settings->refining_efficiency }}"
                                                       min="0" max="100" step="0.1" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </div>
                                            <p class="setting-description">
                                                Maximum refining rate (90.6% with perfect skills and rigged Tatara)
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Tax Rates Tab -->
                            <div class="tab-pane fade" id="taxes" role="tabpanel">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> 
                                    Configure tax rates for different ore types. Enable/disable specific categories and set whether to tax only corporation-owned moons.
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <tr>
                                                <th width="30">
                                                    <input type="checkbox" id="select-all-taxes">
                                                </th>
                                                <th>Ore Type</th>
                                                <th width="120">Tax Rate (%)</th>
                                                <th width="150">Corp Moons Only</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @php
                                            $oreCategories = [
                                                'standard' => ['name' => 'Standard Ore', 'icon' => 'fa-gem', 'color' => 'info'],
                                                'ice' => ['name' => 'Ice', 'icon' => 'fa-icicles', 'color' => 'primary'],
                                                'gas' => ['name' => 'Gas', 'icon' => 'fa-cloud', 'color' => 'success'],
                                                'moon_r4' => ['name' => 'R4 Moon Ore', 'icon' => 'fa-moon', 'color' => 'secondary'],
                                                'moon_r8' => ['name' => 'R8 Moon Ore', 'icon' => 'fa-moon', 'color' => 'info'],
                                                'moon_r16' => ['name' => 'R16 Moon Ore', 'icon' => 'fa-moon', 'color' => 'success'],
                                                'moon_r32' => ['name' => 'R32 Moon Ore', 'icon' => 'fa-moon', 'color' => 'warning'],
                                                'moon_r64' => ['name' => 'R64 Moon Ore', 'icon' => 'fa-moon', 'color' => 'danger'],
                                                'abyssal' => ['name' => 'Abyssal Ore', 'icon' => 'fa-vortex', 'color' => 'dark'],
                                            ];
                                            @endphp
                                            
                                            @foreach($oreCategories as $key => $category)
                                            <tr>
                                                <td class="text-center">
                                                    <input type="checkbox" name="tax_enabled[{{ $key }}]" 
                                                           class="tax-checkbox" value="1"
                                                           {{ $settings->taxes[$key]['enabled'] ?? false ? 'checked' : '' }}>
                                                </td>
                                                <td>
                                                    <i class="fas {{ $category['icon'] }} text-{{ $category['color'] }}"></i>
                                                    <strong>{{ $category['name'] }}</strong>
                                                </td>
                                                <td>
                                                    <input type="number" name="tax_rates[{{ $key }}]" 
                                                           class="form-control tax-rate-input"
                                                           value="{{ $settings->taxes[$key]['rate'] ?? 10 }}"
                                                           min="0" max="100" step="0.1">
                                                </td>
                                                <td class="text-center">
                                                    @if(str_starts_with($key, 'moon_'))
                                                    <input type="checkbox" name="corp_moons_only[{{ $key }}]" 
                                                           value="1"
                                                           {{ $settings->taxes[$key]['corp_moons_only'] ?? false ? 'checked' : '' }}>
                                                    @else
                                                    <span class="text-muted">N/A</span>
                                                    @endif
                                                </td>
                                                <td class="text-muted">
                                                    @if($key == 'standard')
                                                        All standard asteroid belt ores
                                                    @elseif($key == 'ice')
                                                        Ice belt materials
                                                    @elseif($key == 'gas')
                                                        Gas cloud harvesting
                                                    @elseif(str_starts_with($key, 'moon_'))
                                                        Moon mining goo ({{ substr($key, 5) }})
                                                    @elseif($key == 'abyssal')
                                                        Abyssal deadspace ores
                                                    @endif
                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h5><i class="fas fa-calculator"></i> Quick Tax Calculator</h5>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <label>If mined value is:</label>
                                                        <input type="number" id="calc-value" class="form-control" value="1000000000">
                                                    </div>
                                                    <div class="col-6">
                                                        <label>Tax would be:</label>
                                                        <input type="text" id="calc-result" class="form-control" readonly>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contracts Tab -->
                            <div class="tab-pane fade" id="contracts" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="contract_issuer_id">
                                                <i class="fas fa-user"></i> Contract Issuer Character
                                            </label>
                                            <select class="form-control select2" name="contract_issuer_id" id="contract_issuer_id" required>
                                                <option value="">Select Character...</option>
                                                @foreach($settings->availableIssuers as $character)
                                                <option value="{{ $character->character_id }}"
                                                    {{ $settings->contract_issuer_id == $character->character_id ? 'selected' : '' }}>
                                                    {{ $character->name }}
                                                </option>
                                                @endforeach
                                            </select>
                                            <p class="setting-description">
                                                Character who will create the tax contracts
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="contract_tag">
                                                <i class="fas fa-tag"></i> Contract Tag
                                            </label>
                                            <input type="text" class="form-control" name="contract_tag" 
                                                   id="contract_tag" value="{{ $settings->contract_tag }}"
                                                   placeholder="Mining Tax">
                                            <p class="setting-description">
                                                Tag to identify mining tax contracts
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="minimum_contract_value">
                                                <i class="fas fa-coins"></i> Minimum Contract Value
                                            </label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" name="minimum_contract_value" 
                                                       id="minimum_contract_value" value="{{ $settings->minimum_contract_value }}"
                                                       min="0" step="1000000" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">ISK</span>
                                                </div>
                                            </div>
                                            <p class="setting-description">
                                                Don't create contracts below this value
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="contract_expiry_days">
                                                <i class="fas fa-clock"></i> Contract Expiry Days
                                            </label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" name="contract_expiry_days" 
                                                       id="contract_expiry_days" value="{{ $settings->contract_expiry_days }}"
                                                       min="1" max="30" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">days</span>
                                                </div>
                                            </div>
                                            <p class="setting-description">
                                                Days before contract expires
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <div class="custom-control custom-switch">
                                                <input type="checkbox" class="custom-control-input" 
                                                       name="auto_generate_invoices" id="auto_generate_invoices"
                                                       {{ $settings->auto_generate_invoices ? 'checked' : '' }}>
                                                <label class="custom-control-label" for="auto_generate_invoices">
                                                    Auto-generate monthly tax invoices
                                                </label>
                                            </div>
                                            <p class="setting-description">
                                                Automatically create tax invoices on the 1st of each month
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Pricing Tab -->
                            <div class="tab-pane fade" id="pricing" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="price_source">
                                                <i class="fas fa-chart-line"></i> Price Source
                                            </label>
                                            <select class="form-control" name="price_source" id="price_source">
                                                @foreach($priceProviders as $key => $name)
                                                <option value="{{ $key }}" {{ $settings->price_source == $key ? 'selected' : '' }}>
                                                    {{ $name }}
                                                </option>
                                                @endforeach
                                            </select>
                                            <p class="setting-description">
                                                Source for ore and mineral prices
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="price_provider_key">
                                                <i class="fas fa-key"></i> API Key (if required)
                                            </label>
                                            <input type="text" class="form-control" name="price_provider_key" 
                                                   id="price_provider_key" value="{{ $settings->price_provider_key }}"
                                                   placeholder="Enter API key for external price provider">
                                            <p class="setting-description">
                                                Required for EVE Janice and some other providers
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="price_modifier">
                                                <i class="fas fa-percentage"></i> Price Modifier
                                            </label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" name="price_modifier" 
                                                       id="price_modifier" value="{{ $settings->price_modifier }}"
                                                       min="0" max="200" step="1" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </div>
                                            <p class="setting-description">
                                                Adjust base prices (100% = no change, 90% = 10% discount)
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="valuation_method">
                                                <i class="fas fa-balance-scale"></i> Valuation Method
                                            </label>
                                            <select class="form-control" name="valuation_method" id="valuation_method">
                                                <option value="raw" {{ $settings->valuation_method == 'raw' ? 'selected' : '' }}>
                                                    Raw Ore Price
                                                </option>
                                                <option value="refined" {{ $settings->valuation_method == 'refined' ? 'selected' : '' }}>
                                                    Refined Mineral Price
                                                </option>
                                                <option value="highest" {{ $settings->valuation_method == 'highest' ? 'selected' : '' }}>
                                                    Highest of Both
                                                </option>
                                            </select>
                                            <p class="setting-description">
                                                How to calculate the value of mined ore
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Features Tab -->
                            <div class="tab-pane fade" id="features" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h5>Core Features</h5>
                                        <div class="form-group">
                                            <div class="custom-control custom-switch mb-2">
                                                <input type="checkbox" class="custom-control-input" 
                                                       name="enable_notifications" id="enable_notifications"
                                                       {{ $settings->enable_notifications ? 'checked' : '' }}>
                                                <label class="custom-control-label" for="enable_notifications">
                                                    <i class="fas fa-bell"></i> Enable Notifications
                                                </label>
                                            </div>
                                            
                                            <div class="custom-control custom-switch mb-2">
                                                <input type="checkbox" class="custom-control-input" 
                                                       name="enable_events" id="enable_events"
                                                       {{ $settings->enable_events ? 'checked' : '' }}>
                                                <label class="custom-control-label" for="enable_events">
                                                    <i class="fas fa-calendar"></i> Enable Mining Events
                                                </label>
                                            </div>
                                            
                                            <div class="custom-control custom-switch mb-2">
                                                <input type="checkbox" class="custom-control-input" 
                                                       name="enable_moon_tracking" id="enable_moon_tracking"
                                                       {{ $settings->enable_moon_tracking ? 'checked' : '' }}>
                                                <label class="custom-control-label" for="enable_moon_tracking">
                                                    <i class="fas fa-moon"></i> Enable Moon Tracking
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h5>Advanced Features</h5>
                                        <div class="form-group">
                                            <div class="custom-control custom-switch mb-2">
                                                <input type="checkbox" class="custom-control-input" 
                                                       name="enable_analytics" id="enable_analytics"
                                                       {{ $settings->enable_analytics ? 'checked' : '' }}>
                                                <label class="custom-control-label" for="enable_analytics">
                                                    <i class="fas fa-chart-line"></i> Advanced Analytics
                                                </label>
                                            </div>
                                            
                                            <div class="custom-control custom-switch mb-2">
                                                <input type="checkbox" class="custom-control-input" 
                                                       name="enable_api" id="enable_api"
                                                       {{ $settings->enable_api ? 'checked' : '' }}>
                                                <label class="custom-control-label" for="enable_api">
                                                    <i class="fas fa-plug"></i> API Access
                                                </label>
                                            </div>
                                            
                                            <div class="custom-control custom-switch mb-2">
                                                <input type="checkbox" class="custom-control-input" 
                                                       name="enable_reports" id="enable_reports"
                                                       {{ $settings->enable_reports ? 'checked' : '' }}>
                                                <label class="custom-control-label" for="enable_reports">
                                                    <i class="fas fa-file-pdf"></i> Generate Reports
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    <button type="button" class="btn btn-secondary" id="reset-defaults">
                        <i class="fas fa-undo"></i> Reset to Defaults
                    </button>
                    <div class="float-right">
                        <small class="text-muted">
                            Last updated: {{ $settings->updated_at ? $settings->updated_at->diffForHumans() : 'Never' }}
                        </small>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap4',
        width: '100%'
    });
    
    // Select all taxes checkbox
    $('#select-all-taxes').on('change', function() {
        $('.tax-checkbox').prop('checked', $(this).is(':checked'));
    });
    
    // Tax calculator
    function calculateTax() {
        const value = parseFloat($('#calc-value').val()) || 0;
        let totalTax = 0;
        
        $('.tax-checkbox:checked').each(function() {
            const row = $(this).closest('tr');
            const rate = parseFloat(row.find('.tax-rate-input').val()) || 0;
            totalTax += value * (rate / 100);
        });
        
        $('#calc-result').val(totalTax.toLocaleString() + ' ISK');
    }
    
    $('#calc-value, .tax-rate-input, .tax-checkbox').on('change input', calculateTax);
    calculateTax();
    
    // Show/hide API key field based on price source
    $('#price_source').on('change', function() {
        const needsKey = ['janice', 'custom'].includes($(this).val());
        $('#price_provider_key').closest('.form-group').toggle(needsKey);
    }).trigger('change');
    
    // Reset to defaults
    $('#reset-defaults').on('click', function() {
        if (confirm('Are you sure you want to reset all settings to defaults? This cannot be undone.')) {
            $.post('{{ route("mining.settings.reset") }}', {
                _token: '{{ csrf_token() }}'
            }).done(function() {
                location.reload();
            });
        }
    });
    
    // Form validation
    $('#settings-form').on('submit', function(e) {
        let valid = true;
        
        // Check if corporation is selected
        if (!$('#corporation_id').val()) {
            alert('Please select a corporation');
            valid = false;
        }
        
        // Check if at least one tax is enabled
        if ($('.tax-checkbox:checked').length === 0) {
            alert('Please enable at least one tax category');
            valid = false;
        }
        
        if (!valid) {
            e.preventDefault();
        }
    });
});
</script>
@endpush
