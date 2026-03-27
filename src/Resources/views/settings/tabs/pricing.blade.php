<form method="POST" action="{{ route('mining-manager.settings.update-pricing') }}">
    @csrf
    
    <h4>
        <i class="fas fa-tags"></i>
        Pricing Settings
    </h4>
    <hr>

    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <strong>Info:</strong> Settings configured here will override ENV variables. Leave Janice API key blank to use <code>MINING_MANAGER_JANICE_API_KEY</code> from ENV.
    </div>

    {{-- Price Source Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-database"></i>
                Price Provider Configuration
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="price_provider">
                    <i class="fas fa-server"></i>
                    Price Provider
                </label>
                <select class="form-control @error('price_provider') is-invalid @enderror" 
                        id="price_provider" 
                        name="price_provider">
                    <option value="seat" {{ (isset($settings['pricing']['price_provider']) && $settings['pricing']['price_provider'] == 'seat') || !isset($settings['pricing']['price_provider']) ? 'selected' : '' }}>
                        SeAT Database (Default - Uses SeAT's market_prices table)
                    </option>
                    <option value="fuzzwork" {{ (isset($settings['pricing']['price_provider']) && $settings['pricing']['price_provider'] == 'fuzzwork') ? 'selected' : '' }}>
                        Fuzzwork Market (Community aggregator - No API key needed)
                    </option>
                    <option value="janice" {{ (isset($settings['pricing']['price_provider']) && $settings['pricing']['price_provider'] == 'janice') ? 'selected' : '' }}>
                        Janice (Appraisal service - Requires API key)
                    </option>
                    @if(\MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled())
                    <option value="manager-core" {{ (isset($settings['pricing']['price_provider']) && $settings['pricing']['price_provider'] == 'manager-core') ? 'selected' : '' }}>
                        Manager Core (Shared price cache - ESI/EvePraisal/SeAT)
                    </option>
                    @endif
                </select>
                @error('price_provider')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    <strong>SeAT Database:</strong> Uses SeAT's existing market_prices table (refreshed by SeAT's jobs)<br>
                    <strong>Fuzzwork:</strong> External market data API - no configuration needed<br>
                    <strong>Janice:</strong> Accurate appraisal service - requires free API key
                    @if(\MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled())
                    <br><strong>Manager Core:</strong> Uses Manager Core's shared price cache with full statistics, history, and trending
                    @endif
                </small>
            </div>

            {{-- Janice API Configuration --}}
            <div id="janice-config" style="display: none;" class="mt-4 p-3 bg-secondary rounded border border-warning">
                <h6 class="text-warning mb-3">
                    <i class="fas fa-key"></i> Janice API Configuration
                </h6>
                
                <div class="form-group">
                    <label for="janice_api_key">
                        <i class="fas fa-key"></i>
                        Janice API Key <span class="text-danger">*</span>
                    </label>
                    <input type="text" 
                           class="form-control @error('janice_api_key') is-invalid @enderror" 
                           id="janice_api_key" 
                           name="janice_api_key" 
                           value="{{ old('janice_api_key', $settings['pricing']['janice_api_key'] ?? '') }}"
                           placeholder="Leave blank to use MINING_MANAGER_JANICE_API_KEY from ENV">
                    @error('janice_api_key')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <small class="form-text text-muted">
                        Get your free API key from <a href="https://janice.e-351.com/settings" target="_blank" class="text-info"><i class="fas fa-external-link-alt"></i> Janice Settings</a>
                        <br><strong>ENV Fallback:</strong> If left blank, will use <code>MINING_MANAGER_JANICE_API_KEY</code> environment variable
                    </small>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="janice_market">
                                <i class="fas fa-map-marker-alt"></i>
                                Market Hub
                            </label>
                            <select class="form-control @error('janice_market') is-invalid @enderror" 
                                    id="janice_market" 
                                    name="janice_market">
                                <option value="jita" {{ (isset($settings['pricing']['janice_market']) && $settings['pricing']['janice_market'] == 'jita') || !isset($settings['pricing']['janice_market']) ? 'selected' : '' }}>
                                    Jita (The Forge)
                                </option>
                                <option value="amarr" {{ (isset($settings['pricing']['janice_market']) && $settings['pricing']['janice_market'] == 'amarr') ? 'selected' : '' }}>
                                    Amarr
                                </option>
                            </select>
                            @error('janice_market')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">
                                Which trade hub to use for pricing
                            </small>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="janice_price_method">
                                <i class="fas fa-calculator"></i>
                                Price Method
                            </label>
                            <select class="form-control @error('janice_price_method') is-invalid @enderror" 
                                    id="janice_price_method" 
                                    name="janice_price_method">
                                <option value="buy" {{ (isset($settings['pricing']['janice_price_method']) && $settings['pricing']['janice_price_method'] == 'buy') || !isset($settings['pricing']['janice_price_method']) ? 'selected' : '' }}>
                                    Buy Orders (Instant sell to market)
                                </option>
                                <option value="sell" {{ (isset($settings['pricing']['janice_price_method']) && $settings['pricing']['janice_price_method'] == 'sell') ? 'selected' : '' }}>
                                    Sell Orders (Instant buy from market)
                                </option>
                                <option value="split" {{ (isset($settings['pricing']['janice_price_method']) && $settings['pricing']['janice_price_method'] == 'split') ? 'selected' : '' }}>
                                    Split Price (Average for bulk)
                                </option>
                            </select>
                            @error('janice_price_method')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">
                                How to calculate item values
                            </small>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Important:</strong> After saving Janice settings, run <code>php artisan mining-manager:cache-prices --type=moon --force</code> to populate prices.
                </div>
            </div>

            {{-- Manager Core Configuration --}}
            <div id="manager-core-config" style="display: none;" class="mt-4 p-3 bg-secondary rounded border border-info">
                <h6 class="text-info mb-3">
                    <i class="fas fa-cubes"></i> Manager Core Configuration
                </h6>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="manager_core_market">
                                <i class="fas fa-map-marker-alt"></i>
                                Market
                            </label>
                            <select class="form-control @error('manager_core_market') is-invalid @enderror"
                                    id="manager_core_market"
                                    name="manager_core_market">
                                <option value="jita" {{ (isset($settings['pricing']['manager_core_market']) && $settings['pricing']['manager_core_market'] == 'jita') || !isset($settings['pricing']['manager_core_market']) ? 'selected' : '' }}>
                                    Jita
                                </option>
                                <option value="amarr" {{ (isset($settings['pricing']['manager_core_market']) && $settings['pricing']['manager_core_market'] == 'amarr') ? 'selected' : '' }}>
                                    Amarr
                                </option>
                                <option value="dodixie" {{ (isset($settings['pricing']['manager_core_market']) && $settings['pricing']['manager_core_market'] == 'dodixie') ? 'selected' : '' }}>
                                    Dodixie
                                </option>
                                <option value="hek" {{ (isset($settings['pricing']['manager_core_market']) && $settings['pricing']['manager_core_market'] == 'hek') ? 'selected' : '' }}>
                                    Hek
                                </option>
                                <option value="rens" {{ (isset($settings['pricing']['manager_core_market']) && $settings['pricing']['manager_core_market'] == 'rens') ? 'selected' : '' }}>
                                    Rens
                                </option>
                            </select>
                            @error('manager_core_market')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">
                                Which market hub to read prices from (must be enabled in Manager Core)
                            </small>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="manager_core_variant">
                                <i class="fas fa-calculator"></i>
                                Price Variant
                            </label>
                            <select class="form-control @error('manager_core_variant') is-invalid @enderror"
                                    id="manager_core_variant"
                                    name="manager_core_variant">
                                <option value="min" {{ (isset($settings['pricing']['manager_core_variant']) && $settings['pricing']['manager_core_variant'] == 'min') || !isset($settings['pricing']['manager_core_variant']) ? 'selected' : '' }}>
                                    Minimum
                                </option>
                                <option value="max" {{ (isset($settings['pricing']['manager_core_variant']) && $settings['pricing']['manager_core_variant'] == 'max') ? 'selected' : '' }}>
                                    Maximum
                                </option>
                                <option value="avg" {{ (isset($settings['pricing']['manager_core_variant']) && $settings['pricing']['manager_core_variant'] == 'avg') ? 'selected' : '' }}>
                                    Average (Weighted)
                                </option>
                                <option value="median" {{ (isset($settings['pricing']['manager_core_variant']) && $settings['pricing']['manager_core_variant'] == 'median') ? 'selected' : '' }}>
                                    Median
                                </option>
                                <option value="percentile" {{ (isset($settings['pricing']['manager_core_variant']) && $settings['pricing']['manager_core_variant'] == 'percentile') ? 'selected' : '' }}>
                                    Percentile (5th)
                                </option>
                            </select>
                            @error('manager_core_variant')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">
                                Which price statistic to use for tax and value calculations
                            </small>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle"></i>
                    <strong>Auto-Subscribe:</strong> When you save with Manager Core selected, all mining-related type IDs (ores, minerals, moon materials, ice, gas) will be automatically subscribed to Manager Core's price tracking.
                    Prices are updated on Manager Core's schedule and shared across all plugins.
                </div>
            </div>

            <div class="form-group mt-3" id="price-type-group">
                <label for="price_type">
                    <i class="fas fa-chart-line"></i>
                    Price Type
                </label>
                <select class="form-control @error('price_type') is-invalid @enderror"
                        id="price_type"
                        name="price_type">
                    <option value="sell" {{ (isset($settings['pricing']['price_type']) && $settings['pricing']['price_type'] == 'sell') || !isset($settings['pricing']['price_type']) ? 'selected' : '' }}>
                        Sell Orders (Lowest sell price)
                    </option>
                    <option value="buy" {{ (isset($settings['pricing']['price_type']) && $settings['pricing']['price_type'] == 'buy') ? 'selected' : '' }}>
                        Buy Orders (Highest buy price)
                    </option>
                    <option value="average" {{ (isset($settings['pricing']['price_type']) && $settings['pricing']['price_type'] == 'average') ? 'selected' : '' }}>
                        Average (Mean of buy and sell)
                    </option>
                </select>
                @error('price_type')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    Which market price to use for calculations (used by SeAT, Fuzzwork, and Manager Core)
                </small>
            </div>
        </div>
    </div>

    {{-- Price Cache Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-hdd"></i>
                Price Cache Settings
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="cache_duration">
                    <i class="fas fa-clock"></i>
                    Cache Duration (in minutes)
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('cache_duration') is-invalid @enderror" 
                           id="cache_duration" 
                           name="cache_duration" 
                           value="{{ old('cache_duration', $settings['pricing']['cache_duration'] ?? 240) }}"
                           min="30"
                           max="1440">
                    <div class="input-group-append">
                        <span class="input-group-text">minutes</span>
                    </div>
                    @error('cache_duration')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    How long to cache prices before considering them stale. Range: 30-1440 minutes (30 min - 24 hours). <strong>Default: 240 minutes (4 hours)</strong>.
                    If you change this, also update the <code>cache-prices</code> scheduled job frequency to match.
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="fallback_to_jita" 
                       name="fallback_to_jita" 
                       value="1"
                       {{ old('fallback_to_jita', $settings['pricing']['fallback_to_jita'] ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="fallback_to_jita">
                    <i class="fas fa-shield-alt"></i>
                    Fallback to Jita prices if unavailable
                </label>
                <small class="form-text text-muted">
                    When a non-Jita market returns 0 for an item, automatically retry with Jita prices using the same provider
                </small>
            </div>
        </div>
    </div>

    {{-- Manual Price Cache Management --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-terminal"></i>
                Manual Price Cache Management
            </h5>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Use these commands to manually refresh the price cache:
            </p>
            
            <div class="bg-darker p-3 rounded mb-2">
                <code class="text-success">php artisan mining-manager:cache-prices --type=moon --force</code>
                <br><small class="text-muted">Refresh moon ore prices only (fastest, ~24 items)</small>
            </div>
            
            <div class="bg-darker p-3 rounded mb-2">
                <code class="text-success">php artisan mining-manager:cache-prices --type=all --force</code>
                <br><small class="text-muted">Refresh all mining-related prices (ore, moon, minerals, ice, gas)</small>
            </div>

            <div class="alert alert-info mb-0 mt-3">
                <i class="fas fa-lightbulb"></i>
                <strong>Tip:</strong> Add this to your crontab to automatically refresh prices:
                <br><code>0 * * * * cd /path/to/seat && php artisan mining-manager:cache-prices --type=moon</code>
                <br><small class="text-muted">(Refreshes moon ore prices every hour)</small>
            </div>
        </div>
    </div>

    {{-- Refining Configuration --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-industry"></i>
                Refining Configuration
            </h5>
        </div>
        <div class="card-body">
            <div class="info-banner mb-3">
                <i class="fas fa-info-circle"></i>
                <strong>Info:</strong>
                When enabled, moon extraction values and mining taxes are calculated based on refined mineral prices instead of raw ore prices. This gives you a more accurate value based on what you actually get when reprocessing the ore.
            </div>

            <div class="form-group">
                <div class="custom-control custom-switch">
                    <input type="checkbox" 
                           class="custom-control-input" 
                           id="use_refined_value"
                           name="use_refined_value"
                           value="1"
                           {{ old('use_refined_value', $settings['pricing']['use_refined_value'] ?? false) ? 'checked' : '' }}>
                    <label class="custom-control-label" for="use_refined_value">
                        <strong>Use Refined Mineral Value</strong>
                    </label>
                </div>
                <small class="form-text text-muted">
                    Calculate moon and tax values based on refined mineral prices instead of raw ore prices
                </small>
            </div>

            <div class="form-group">
                <label for="refining_efficiency">
                    <i class="fas fa-percentage"></i>
                    Refining Efficiency
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('refining_efficiency') is-invalid @enderror" 
                           id="refining_efficiency" 
                           name="refining_efficiency"
                           value="{{ old('refining_efficiency', $settings['pricing']['refining_efficiency'] ?? 87.5) }}"
                           min="0"
                           max="100"
                           step="0.1">
                    <div class="input-group-append">
                        <span class="input-group-text">%</span>
                    </div>
                    @error('refining_efficiency')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    Your station's reprocessing efficiency. With Reprocessing V and Reprocessing Efficiency V, this is typically 87.5%. Tatara with rigs can reach ~89.5%.
                </small>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-calculator"></i>
                <strong>Example Calculation:</strong>
                <ul class="mb-0">
                    <li><strong>At 87.5% efficiency:</strong> 100 units of ore yields 87.5 units of each mineral</li>
                    <li><strong>Bitumens example:</strong> Each 100 units yields 9600 Pyerite × 0.875 = 8400 Pyerite</li>
                    <li><strong>Moon materials:</strong> Each 100 units yields 104 moon materials × 0.875 = 91 units</li>
                </ul>
            </div>

            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Note:</strong> Enabling refined value calculation requires price data for both ores AND minerals. Make sure your price cache includes mineral prices (Pyerite, Mexallon) and moon material prices, or moon values may be inaccurate.
            </div>
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="row mt-4">
        <div class="col-md-6">
            <button type="submit" class="btn btn-success btn-block btn-lg">
                <i class="fas fa-save"></i>
                Save Pricing Settings
            </button>
        </div>
        <div class="col-md-6">
            <a href="{{ route('mining-manager.settings.index') }}" 
               class="btn btn-secondary btn-block btn-lg">
                <i class="fas fa-undo"></i>
                Cancel
            </a>
        </div>
    </div>

</form>

@push('javascript')
<script>
$(document).ready(function() {
    // Show/hide provider-specific config based on selection
    function toggleProviderConfig() {
        const provider = $('#price_provider').val();

        // Janice config
        if (provider === 'janice') {
            $('#janice-config').slideDown(300);
        } else {
            $('#janice-config').slideUp(300);
        }

        // Manager Core config
        if (provider === 'manager-core') {
            $('#manager-core-config').slideDown(300);
        } else {
            $('#manager-core-config').slideUp(300);
        }

        // Hide Price Type when Janice is selected (Janice uses its own Price Method)
        if (provider === 'janice') {
            $('#price-type-group').slideUp(300);
        } else {
            $('#price-type-group').slideDown(300);
        }
    }

    // Initialize on page load
    toggleProviderConfig();

    // Update when provider changes
    $('#price_provider').on('change', toggleProviderConfig);
    
    // Show warning if Janice selected but no API key
    $('#price_provider').on('change', function() {
        if ($(this).val() === 'janice') {
            const apiKey = $('#janice_api_key').val().trim();
            const envKey = '{{ config("mining-manager.general.price_provider_api_key") }}';
            
            if (!apiKey && !envKey) {
                setTimeout(function() {
                    if (typeof toastr !== 'undefined') {
                        toastr.warning('Remember to configure your Janice API key!', 'Configuration Required');
                    }
                }, 500);
            }
        }
    });
});
</script>
@endpush
