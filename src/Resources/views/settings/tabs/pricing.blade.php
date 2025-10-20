<form method="POST" action="{{ route('mining-manager.settings.update') }}">
    @csrf
    @method('PUT')
    
    <h4>
        <i class="fas fa-tags"></i>
        {{ trans('mining-manager::settings.pricing_settings') }}
    </h4>
    <hr>

    <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        <strong>{{ trans('mining-manager::settings.info') }}:</strong>
        {{ trans('mining-manager::settings.pricing_info') }}
    </div>

    {{-- Price Source Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-database"></i>
                {{ trans('mining-manager::settings.price_source_settings') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="price_provider">
                    <i class="fas fa-server"></i>
                    {{ trans('mining-manager::settings.price_provider') }}
                </label>
                <select class="form-control @error('price_provider') is-invalid @enderror" 
                        id="price_provider" 
                        name="price_provider">
                    <option value="fuzzwork" {{ ($settings->price_provider ?? 'fuzzwork') == 'fuzzwork' ? 'selected' : '' }}>
                        Fuzzwork Market
                    </option>
                    <option value="evepraisal" {{ ($settings->price_provider ?? '') == 'evepraisal' ? 'selected' : '' }}>
                        Evepraisal
                    </option>
                    <option value="evemarketer" {{ ($settings->price_provider ?? '') == 'evemarketer' ? 'selected' : '' }}>
                        Eve Marketer
                    </option>
                    <option value="janice" {{ ($settings->price_provider ?? '') == 'janice' ? 'selected' : '' }}>
                        Janice
                    </option>
                </select>
                @error('price_provider')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.price_provider_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="price_type">
                    <i class="fas fa-chart-line"></i>
                    {{ trans('mining-manager::settings.price_type') }}
                </label>
                <select class="form-control @error('price_type') is-invalid @enderror" 
                        id="price_type" 
                        name="price_type">
                    <option value="sell" {{ ($settings->price_type ?? 'sell') == 'sell' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.sell_orders') }}
                    </option>
                    <option value="buy" {{ ($settings->price_type ?? '') == 'buy' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.buy_orders') }}
                    </option>
                    <option value="average" {{ ($settings->price_type ?? '') == 'average' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.average') }}
                    </option>
                </select>
                @error('price_type')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.price_type_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="price_percentile">
                    <i class="fas fa-percentage"></i>
                    {{ trans('mining-manager::settings.price_percentile') }}
                </label>
                <select class="form-control @error('price_percentile') is-invalid @enderror" 
                        id="price_percentile" 
                        name="price_percentile">
                    <option value="min" {{ ($settings->price_percentile ?? 'median') == 'min' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.minimum') }}
                    </option>
                    <option value="max" {{ ($settings->price_percentile ?? '') == 'max' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.maximum') }}
                    </option>
                    <option value="median" {{ ($settings->price_percentile ?? 'median') == 'median' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.median') }}
                    </option>
                    <option value="average" {{ ($settings->price_percentile ?? '') == 'average' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.average') }}
                    </option>
                    <option value="percentile_5" {{ ($settings->price_percentile ?? '') == 'percentile_5' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.percentile_5') }}
                    </option>
                    <option value="percentile_95" {{ ($settings->price_percentile ?? '') == 'percentile_95' ? 'selected' : '' }}>
                        {{ trans('mining-manager::settings.percentile_95') }}
                    </option>
                </select>
                @error('price_percentile')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.price_percentile_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="market_hub">
                    <i class="fas fa-globe"></i>
                    {{ trans('mining-manager::settings.market_hub') }}
                </label>
                <select class="form-control @error('market_hub') is-invalid @enderror" 
                        id="market_hub" 
                        name="market_hub">
                    <option value="60003760" {{ ($settings->market_hub ?? '60003760') == '60003760' ? 'selected' : '' }}>
                        Jita IV - Moon 4 - Caldari Navy Assembly Plant
                    </option>
                    <option value="60008494" {{ ($settings->market_hub ?? '') == '60008494' ? 'selected' : '' }}>
                        Amarr VIII (Oris) - Emperor Family Academy
                    </option>
                    <option value="60004588" {{ ($settings->market_hub ?? '') == '60004588' ? 'selected' : '' }}>
                        Dodixie IX - Moon 20 - Federation Navy Assembly Plant
                    </option>
                    <option value="60011866" {{ ($settings->market_hub ?? '') == '60011866' ? 'selected' : '' }}>
                        Rens VI - Moon 8 - Brutor Tribe Treasury
                    </option>
                    <option value="60005686" {{ ($settings->market_hub ?? '') == '60005686' ? 'selected' : '' }}>
                        Hek VIII - Moon 12 - Boundless Creation Factory
                    </option>
                </select>
                @error('market_hub')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.market_hub_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Price Cache Settings --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-hdd"></i>
                {{ trans('mining-manager::settings.price_cache_settings') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="price_cache_duration">
                    <i class="fas fa-clock"></i>
                    {{ trans('mining-manager::settings.price_cache_duration') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('price_cache_duration') is-invalid @enderror" 
                           id="price_cache_duration" 
                           name="price_cache_duration" 
                           value="{{ old('price_cache_duration', $settings->price_cache_duration ?? 60) }}"
                           min="15" 
                           max="1440">
                    <div class="input-group-append">
                        <span class="input-group-text">{{ trans('mining-manager::settings.minutes') }}</span>
                    </div>
                    @error('price_cache_duration')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.price_cache_duration_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="auto_update_prices" 
                       name="auto_update_prices" 
                       value="1"
                       {{ old('auto_update_prices', $settings->auto_update_prices ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="auto_update_prices">
                    <i class="fas fa-sync-alt"></i>
                    {{ trans('mining-manager::settings.auto_update_prices') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.auto_update_prices_help') }}
                </small>
            </div>

            <div class="alert alert-info mt-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('mining-manager::settings.last_price_update') }}:</strong>
                        @if(isset($lastPriceUpdate))
                            {{ $lastPriceUpdate->diffForHumans() }}
                        @else
                            {{ trans('mining-manager::settings.never') }}
                        @endif
                    </div>
                    <button type="button" 
                            class="btn btn-info btn-sm" 
                            id="updatePricesBtn">
                        <i class="fas fa-sync"></i>
                        {{ trans('mining-manager::settings.update_now') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Pricing Adjustments --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-calculator"></i>
                {{ trans('mining-manager::settings.pricing_adjustments') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="warning-banner">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>{{ trans('mining-manager::settings.warning') }}:</strong>
                {{ trans('mining-manager::settings.pricing_adjustment_warning') }}
            </div>

            <div class="form-group">
                <label for="price_adjustment_percentage">
                    <i class="fas fa-percent"></i>
                    {{ trans('mining-manager::settings.price_adjustment') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('price_adjustment_percentage') is-invalid @enderror" 
                           id="price_adjustment_percentage" 
                           name="price_adjustment_percentage" 
                           value="{{ old('price_adjustment_percentage', $settings->price_adjustment_percentage ?? 0) }}"
                           min="-50" 
                           max="50" 
                           step="0.1">
                    <div class="input-group-append">
                        <span class="input-group-text">%</span>
                    </div>
                    @error('price_adjustment_percentage')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.price_adjustment_help') }}
                </small>
            </div>

            <div class="form-group">
                <label for="minimum_ore_value">
                    <i class="fas fa-coins"></i>
                    {{ trans('mining-manager::settings.minimum_ore_value') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('minimum_ore_value') is-invalid @enderror" 
                           id="minimum_ore_value" 
                           name="minimum_ore_value" 
                           value="{{ old('minimum_ore_value', $settings->minimum_ore_value ?? 0) }}"
                           min="0" 
                           step="1">
                    <div class="input-group-append">
                        <span class="input-group-text">ISK</span>
                    </div>
                    @error('minimum_ore_value')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.minimum_ore_value_help') }}
                </small>
            </div>

            <div class="custom-control custom-switch">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="apply_refining_efficiency" 
                       name="apply_refining_efficiency" 
                       value="1"
                       {{ old('apply_refining_efficiency', $settings->apply_refining_efficiency ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="apply_refining_efficiency">
                    <i class="fas fa-industry"></i>
                    {{ trans('mining-manager::settings.apply_refining_efficiency') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.apply_refining_efficiency_help') }}
                </small>
            </div>

            <div class="form-group mt-3">
                <label for="refining_efficiency">
                    <i class="fas fa-percent"></i>
                    {{ trans('mining-manager::settings.refining_efficiency') }}
                </label>
                <div class="input-group">
                    <input type="number" 
                           class="form-control @error('refining_efficiency') is-invalid @enderror" 
                           id="refining_efficiency" 
                           name="refining_efficiency" 
                           value="{{ old('refining_efficiency', $settings->refining_efficiency ?? 72) }}"
                           min="50" 
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
                    {{ trans('mining-manager::settings.refining_efficiency_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Compressed Ore Pricing --}}
    <div class="card bg-dark mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-compress"></i>
                {{ trans('mining-manager::settings.compressed_ore_pricing') }}
            </h5>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label>
                    <i class="fas fa-balance-scale"></i>
                    {{ trans('mining-manager::settings.compressed_ore_pricing_method') }}
                </label>
                <div class="custom-control custom-radio">
                    <input type="radio" 
                           id="pricing_refined" 
                           name="compressed_ore_pricing_method" 
                           value="refined" 
                           class="custom-control-input"
                           {{ old('compressed_ore_pricing_method', $settings->compressed_ore_pricing_method ?? 'refined') == 'refined' ? 'checked' : '' }}>
                    <label class="custom-control-label" for="pricing_refined">
                        <strong>{{ trans('mining-manager::settings.refined_value') }}</strong>
                        <br>
                        <small class="text-muted">
                            {{ trans('mining-manager::settings.refined_value_desc') }}
                        </small>
                    </label>
                </div>
                <div class="custom-control custom-radio mt-2">
                    <input type="radio" 
                           id="pricing_market" 
                           name="compressed_ore_pricing_method" 
                           value="market" 
                           class="custom-control-input"
                           {{ old('compressed_ore_pricing_method', $settings->compressed_ore_pricing_method ?? '') == 'market' ? 'checked' : '' }}>
                    <label class="custom-control-label" for="pricing_market">
                        <strong>{{ trans('mining-manager::settings.market_value') }}</strong>
                        <br>
                        <small class="text-muted">
                            {{ trans('mining-manager::settings.market_value_desc') }}
                        </small>
                    </label>
                </div>
            </div>

            <div class="custom-control custom-switch mt-3">
                <input type="checkbox" 
                       class="custom-control-input" 
                       id="auto_detect_compressed" 
                       name="auto_detect_compressed" 
                       value="1"
                       {{ old('auto_detect_compressed', $settings->auto_detect_compressed ?? true) ? 'checked' : '' }}>
                <label class="custom-control-label" for="auto_detect_compressed">
                    <i class="fas fa-magic"></i>
                    {{ trans('mining-manager::settings.auto_detect_compressed') }}
                </label>
                <small class="form-text text-muted">
                    {{ trans('mining-manager::settings.auto_detect_compressed_help') }}
                </small>
            </div>
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="action-buttons">
        <div class="row">
            <div class="col-md-6">
                <button type="submit" class="btn btn-success btn-block">
                    <i class="fas fa-save"></i>
                    {{ trans('mining-manager::settings.save_changes') }}
                </button>
            </div>
            <div class="col-md-6">
                <a href="{{ route('mining-manager.settings.index') }}" 
                   class="btn btn-secondary btn-block">
                    <i class="fas fa-undo"></i>
                    {{ trans('mining-manager::settings.reset_form') }}
                </a>
            </div>
        </div>
    </div>

</form>

@push('javascript')
<script>
$(document).ready(function() {
    // Update prices
    $('#updatePricesBtn').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i> {{ trans("mining-manager::settings.updating") }}');
        
        $.ajax({
            url: '{{ route("mining-manager.settings.update-prices") }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                toastr.success('{{ trans("mining-manager::settings.prices_updated") }}');
                setTimeout(() => location.reload(), 1000);
            },
            error: function(xhr) {
                toastr.error(xhr.responseJSON?.message || '{{ trans("mining-manager::settings.error_updating_prices") }}');
                btn.prop('disabled', false)
                    .html('<i class="fas fa-sync"></i> {{ trans("mining-manager::settings.update_now") }}');
            }
        });
    });
});
</script>
@endpush
