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
                    <strong>Important:</strong> After saving Janice settings, run <code>php artisan mining-manager:cache-prices --type=all --force</code> to populate prices for all ores, minerals, moon materials, ice, and gas.
                </div>
            </div>

            {{-- Manager Core configuration — centralized in MC, not MM.

                 The market + price-type choice for Mining Manager lives in
                 MC's Pricing Preferences (one row per consumer plugin).
                 Showing a duplicate market dropdown here would lie: MC's
                 admin-overridden row always wins. Instead we surface a
                 read-only status readout of what MC currently has set for
                 MM + a deep-link button into MC's Pricing Preferences page.

                 The previous "Price Variant" dropdown (min/max/avg/median/
                 percentile) is gone — variant=min is the only one that
                 makes sense for tax + payout calculation (lowest sell =
                 actual market price for an instant buy). Hard-coded to
                 'min' in CachePriceDataCommand. --}}
            <div id="manager-core-config" style="display: none;" class="mt-4 p-3 bg-secondary rounded border border-info">
                <h6 class="text-info mb-3">
                    <i class="fas fa-cubes"></i> Manager Core Configuration
                </h6>

                @php
                    // Pull MM's current preference from MC via the bridge.
                    // Returns null if MC isn't loaded or hasn't been seeded
                    // with a row yet — first save will seed it.
                    $mcPref = null;
                    $mcPrefsUrl = null;
                    try {
                        if (class_exists(\ManagerCore\Services\PluginBridge::class)) {
                            $bridge = app(\ManagerCore\Services\PluginBridge::class);
                            $mcPref = $bridge->call('ManagerCore', 'pricing.getPreferenceForPlugin', 'mining-manager');
                            $mcPrefsUrl = $bridge->call('ManagerCore', 'pricing.preferencesUrl');
                        }
                    } catch (\Throwable $e) {
                        // Bridge call failed (older MC version, etc.) — fall
                        // through to the "not configured yet" panel
                    }
                @endphp

                @if($mcPref)
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-2">
                                <label class="text-muted small mb-1">
                                    <i class="fas fa-map-marker-alt"></i> Market
                                </label>
                                <div class="font-weight-bold">{{ $mcPref['market_name'] }}</div>
                                <div class="text-muted small">key: <code>{{ $mcPref['market'] }}</code></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-2">
                                <label class="text-muted small mb-1">
                                    <i class="fas fa-chart-line"></i> Price Type
                                </label>
                                <div class="font-weight-bold">
                                    @if($mcPref['price_type'] === 'sell')
                                        Sell orders (lowest sell)
                                    @elseif($mcPref['price_type'] === 'buy')
                                        Buy orders (highest buy)
                                    @else
                                        Average (mean of buy + sell)
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-2">
                                <label class="text-muted small mb-1">
                                    <i class="fas fa-database"></i> Provider routing
                                </label>
                                <div class="font-weight-bold">{{ $mcPref['provider_label'] }}</div>
                                <div class="text-muted small">
                                    @if(!empty($mcPref['provider_override']))
                                        {{-- Per-plugin provider override active —
                                             MC bypasses its cache and live-fetches
                                             via this provider on every MM read. --}}
                                        <span class="badge badge-info" title="MC routes MM's reads through this provider regardless of what the market is configured for">per-plugin override</span>
                                    @elseif(!empty($mcPref['market_provider']))
                                        <span class="badge badge-secondary" title="Following the market's configured provider">market default ({{ $mcPref['market_provider'] }})</span>
                                    @endif
                                    @if($mcPref['admin_overridden'])
                                        <span class="badge badge-warning">admin-overridden</span>
                                    @else
                                        <span class="badge badge-secondary">plugin default</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="alert alert-secondary mb-3">
                        <i class="fas fa-info-circle"></i>
                        Mining Manager hasn't registered a preference with Manager Core yet — click <strong>Save Pricing Settings</strong> below to seed it (defaults to Jita + Sell orders), then configure further in Manager Core.
                    </div>
                @endif

                <div class="text-center mt-2">
                    @if($mcPrefsUrl)
                        <a href="{{ $mcPrefsUrl }}" class="btn btn-info btn-lg">
                            <i class="fas fa-external-link-alt"></i> Configure pricing in Manager Core
                        </a>
                    @else
                        <button type="button" class="btn btn-secondary btn-lg" disabled>
                            <i class="fas fa-external-link-alt"></i> Manager Core preferences URL unavailable
                        </button>
                    @endif
                    <div class="text-muted small mt-2">
                        Open <strong>Manager Core → Pricing Preferences</strong> to change market or price type for Mining Manager. Saving in MC takes effect immediately.
                    </div>
                </div>

                <hr class="my-3">
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle"></i>
                    <strong>How Manager Core integration works:</strong> Mining Manager subscribes all mining-related type IDs (ores, minerals, moon materials, ice, gas) to MC at boot. MC's per-market provider routing decides where prices come from (Fuzzwork for hubs by default; Goonpraisal for the 7 pre-seeded nullsec citadels). The price refresh runs on MC's 4-hour cron — shared across every plugin that subscribes.
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

        // Hide Price Type when Janice OR Manager Core is selected.
        //  - Janice uses its own Price Method dropdown
        //  - Manager Core has its own price_type in MC's Pricing Preferences;
        //    surfacing a duplicate here would lie (MC's value always wins)
        if (provider === 'janice' || provider === 'manager-core') {
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
