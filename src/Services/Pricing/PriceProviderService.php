<?php

namespace MiningManager\Services\Pricing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\Setting;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Services\Configuration\SettingsManagerService;
use Carbon\Carbon;
use Exception;

/**
 * Service for fetching ore prices from various providers
 *
 * Supported providers:
 * - SeAT Database (market_prices table) - Default, no ESI calls
 * - Janice - Janice API (requires API key)
 * - Fuzzwork - Fuzzwork market data
 * - Custom - Manually configured prices
 *
 * NO ESI CALLS - Uses SeAT's existing database tables
 */
class PriceProviderService
{
    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected SettingsManagerService $settingsService;
    /**
     * Price provider constants
     */
    const PROVIDER_SEAT = 'seat';
    const PROVIDER_JANICE = 'janice';
    const PROVIDER_FUZZWORK = 'fuzzwork';
    const PROVIDER_CUSTOM = 'custom';
    const PROVIDER_MANAGER_CORE = 'manager-core';

    /**
     * Default market hub for regional prices
     */
    const DEFAULT_REGION_ID = 10000002; // The Forge (Jita)

    /**
     * API endpoints
     */
    const JANICE_PRICER_URL = 'https://janice.e-351.com/api/rest/v2/pricer';
    const JANICE_APPRAISAL_URL = 'https://janice.e-351.com/api/rest/v2/appraisal';
    const FUZZWORK_MARKET_URL = 'https://market.fuzzwork.co.uk/aggregates/';

    /**
     * Constructor
     *
     * @param SettingsManagerService $settingsService
     */
    public function __construct(SettingsManagerService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Get prices for specified type IDs
     *
     * @param array $typeIds Array of EVE Online type IDs
     * @return array Associative array [type_id => price]
     */
    public function getPrices(array $typeIds): array
    {
        $provider = $this->getConfiguredProvider();
        
        Log::info('Fetching prices', [
            'provider' => $provider,
            'type_count' => count($typeIds)
        ]);

        try {
            switch ($provider) {
                case self::PROVIDER_JANICE:
                    return $this->getPricesFromJanice($typeIds);
                
                case self::PROVIDER_FUZZWORK:
                    return $this->getPricesFromFuzzwork($typeIds);
                
                case self::PROVIDER_CUSTOM:
                    return $this->getCustomPrices($typeIds);

                case self::PROVIDER_MANAGER_CORE:
                    return $this->getPricesFromManagerCore($typeIds);

                case self::PROVIDER_SEAT:
                default:
                    return $this->getPricesFromSeAT($typeIds);
            }
        } catch (Exception $e) {
            Log::error('Failed to fetch prices', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to SeAT database if configured provider fails
            if ($provider !== self::PROVIDER_SEAT) {
                Log::info('Falling back to SeAT database provider');
                return $this->getPricesFromSeAT($typeIds);
            }
            
            throw $e;
        }
    }

    /**
     * Get a single price for a type ID
     *
     * @param int $typeId
     * @return float|null
     */
    public function getPrice(int $typeId): ?float
    {
        $prices = $this->getPrices([$typeId]);
        return $prices[$typeId] ?? null;
    }

    /**
     * Fetch prices from SeAT database (market_prices table)
     * NO ESI CALLS - Uses existing SeAT data
     *
     * @param array $typeIds
     * @return array
     */
    protected function getPricesFromSeAT(array $typeIds): array
    {
        $pricingSettings = $this->settingsService->getPricingSettings();
        $priceMethod = $pricingSettings['price_type'] ?? 'average';
        
        $prices = [];

        // Query SeAT's market_prices table
        $marketPrices = DB::table('market_prices')
            ->whereIn('type_id', $typeIds)
            ->get();

        foreach ($marketPrices as $item) {
            // Choose price based on method
            $price = match($priceMethod) {
                'adjusted' => $item->adjusted_price ?? $item->average_price ?? 0,
                'average' => $item->average_price ?? 0,
                default => $item->average_price ?? 0
            };

            $prices[$item->type_id] = (float) $price;
        }

        // Fill missing prices with 0
        foreach ($typeIds as $typeId) {
            if (!isset($prices[$typeId])) {
                $prices[$typeId] = 0;
                Log::warning('Price not found in database', ['type_id' => $typeId]);
            }
        }

        return $prices;
    }

    /**
     * Fetch prices from Janice API
     *
     * @param array $typeIds
     * @return array
     */
    protected function getPricesFromJanice(array $typeIds): array
    {
        $pricingSettings = $this->settingsService->getPricingSettings();

        // Check settings first, then fall back to ENV/config
        $apiKey = $pricingSettings['janice_api_key'] ?? '';

        if (empty($apiKey)) {
            throw new Exception('Janice API key not configured. Set it in Settings UI or MINING_MANAGER_JANICE_API_KEY env variable.');
        }

        $prices = [];
        $market = $pricingSettings['janice_market'] ?? 'jita';
        $batchSize = $this->settingsService->getSetting('janice_batch_size', 50);
        $rateLimitDelay = $this->settingsService->getSetting('janice_rate_limit_delay', 50000); // microseconds
        $maxRetries = $this->settingsService->getSetting('janice_max_retries', 3);

        // For large batches, use appraisal endpoint (more efficient)
        if (count($typeIds) > $batchSize) {
            return $this->getPricesFromJaniceAppraisal($typeIds, $apiKey, $market);
        }

        // Use the pricer endpoint for smaller batches
        foreach ($typeIds as $typeId) {
            $attempts = 0;
            $success = false;

            while ($attempts < $maxRetries && !$success) {
                try {
                    $url = sprintf('%s/%d?market=%s',
                        self::JANICE_PRICER_URL,
                        $typeId,
                        $market === 'jita' ? '2' : '1' // 2=Jita, 1=Amarr
                    );

                    $response = Http::timeout(10)
                        ->retry(2, 100)
                        ->withHeaders([
                            'X-ApiKey' => $apiKey,
                            'accept' => 'application/json'
                        ])->get($url);

                    if (!$response->successful()) {
                        Log::warning('Janice API error for type', [
                            'type_id' => $typeId,
                            'status' => $response->status(),
                            'attempt' => $attempts + 1
                        ]);
                        $attempts++;

                        if ($attempts < $maxRetries) {
                            usleep(1000000); // 1 second delay before retry
                            continue;
                        }

                        $prices[$typeId] = 0;
                        break;
                    }

                    $data = $response->json();

                    // Get price based on configured method
                    $priceMethod = $pricingSettings['janice_price_method'] ?? 'buy';

                    if (isset($data['immediatePrices'])) {
                        $prices[$typeId] = match($priceMethod) {
                            'sell' => (float) ($data['immediatePrices']['sellPrice'] ?? 0),
                            'buy' => (float) ($data['immediatePrices']['buyPrice'] ?? 0),
                            'split' => (float) ($data['effectivePrices']['splitPrice'] ?? 0),
                            default => (float) ($data['immediatePrices']['buyPrice'] ?? 0)
                        };
                    } else {
                        $prices[$typeId] = 0;
                    }

                    $success = true;

                    // Configurable rate limiting
                    if ($rateLimitDelay > 0) {
                        usleep($rateLimitDelay);
                    }

                } catch (Exception $e) {
                    $attempts++;
                    Log::error('Failed to fetch Janice price', [
                        'type_id' => $typeId,
                        'error' => $e->getMessage(),
                        'attempt' => $attempts
                    ]);

                    if ($attempts >= $maxRetries) {
                        $prices[$typeId] = 0;
                    } else {
                        usleep(1000000); // 1 second delay before retry
                    }
                }
            }
        }

        return $prices;
    }

    /**
     * Fetch prices from Janice for large batches.
     * Uses the individual pricer endpoint since the appraisal endpoint
     * only returns totals, not per-item prices.
     *
     * @param array $typeIds
     * @param string $apiKey
     * @param string $market
     * @return array
     */
    protected function getPricesFromJaniceAppraisal(array $typeIds, string $apiKey, string $market): array
    {
        // The appraisal endpoint only returns totals, not per-item prices.
        // Use the individual pricer endpoint directly for per-item pricing.
        return $this->getPricesFromJanicePricer($typeIds, $apiKey, $market);
    }

    /**
     * Fetch prices using individual pricer endpoint
     *
     * @param array $typeIds
     * @param string $apiKey
     * @param string $market
     * @return array
     */
    protected function getPricesFromJanicePricer(array $typeIds, string $apiKey, string $market): array
    {
        $prices = [];
        $pricingSettings = $this->settingsService->getPricingSettings();
        $rateLimitDelay = $this->settingsService->getSetting('janice_rate_limit_delay', 50000);

        foreach ($typeIds as $typeId) {
            try {
                $url = sprintf('%s/%d?market=%s',
                    self::JANICE_PRICER_URL,
                    $typeId,
                    $market === 'jita' ? '2' : '1'
                );

                $response = Http::timeout(10)
                    ->withHeaders([
                        'X-ApiKey' => $apiKey,
                        'accept' => 'application/json'
                    ])->get($url);

                if ($response->successful()) {
                    $data = $response->json();
                    $priceMethod = $pricingSettings['janice_price_method'] ?? 'buy';

                    if (isset($data['immediatePrices'])) {
                        $prices[$typeId] = match($priceMethod) {
                            'sell' => (float) ($data['immediatePrices']['sellPrice'] ?? 0),
                            'buy' => (float) ($data['immediatePrices']['buyPrice'] ?? 0),
                            'split' => (float) ($data['effectivePrices']['splitPrice'] ?? 0),
                            default => (float) ($data['immediatePrices']['buyPrice'] ?? 0)
                        };
                    } else {
                        $prices[$typeId] = 0;
                    }
                } else {
                    $prices[$typeId] = 0;
                }

                if ($rateLimitDelay > 0) {
                    usleep($rateLimitDelay);
                }

            } catch (Exception $e) {
                Log::error('Failed to fetch Janice price', [
                    'type_id' => $typeId,
                    'error' => $e->getMessage()
                ]);
                $prices[$typeId] = 0;
            }
        }

        return $prices;
    }

    /**
     * Fetch prices from Fuzzwork (no ESI, external API)
     *
     * @param array $typeIds
     * @return array
     */
    protected function getPricesFromFuzzwork(array $typeIds): array
    {
        $generalSettings = $this->settingsService->getGeneralSettings();
        $pricingSettings = $this->settingsService->getPricingSettings();
        $regionId = $generalSettings['default_region_id'] ?? self::DEFAULT_REGION_ID;
        $typeIdsString = implode(',', $typeIds);

        $response = Http::get(self::FUZZWORK_MARKET_URL, [
            'region' => $regionId,
            'types' => $typeIdsString
        ]);

        if (!$response->successful()) {
            throw new Exception('Failed to fetch Fuzzwork prices: ' . $response->status());
        }

        $data = $response->json();
        $prices = [];
        $priceMethod = $pricingSettings['price_type'] ?? 'sell';

        foreach ($typeIds as $typeId) {
            if (isset($data[$typeId])) {
                $itemData = $data[$typeId];
                
                switch ($priceMethod) {
                    case 'buy':
                        $prices[$typeId] = (float) ($itemData['buy']['max'] ?? 0);
                        break;
                    case 'sell':
                        $prices[$typeId] = (float) ($itemData['sell']['min'] ?? 0);
                        break;
                    case 'average':
                    default:
                        $buy = (float) ($itemData['buy']['max'] ?? 0);
                        $sell = (float) ($itemData['sell']['min'] ?? 0);
                        $prices[$typeId] = ($buy + $sell) / 2;
                        break;
                }
            } else {
                $prices[$typeId] = 0;
            }
        }

        return $prices;
    }

    /**
     * Get custom configured prices
     *
     * @param array $typeIds
     * @return array
     */
    protected function getCustomPrices(array $typeIds): array
    {
        $customPrices = $this->settingsService->getSetting('custom_prices', []);
        $prices = [];

        foreach ($typeIds as $typeId) {
            $prices[$typeId] = (float) ($customPrices[$typeId] ?? 0);
        }

        return $prices;
    }

    /**
     * Fetch prices from Manager Core's market_prices table
     *
     * Uses Manager Core's cached price data which can be sourced from
     * ESI, EvePraisal, or SeAT's price provider system.
     *
     * @param array $typeIds
     * @return array
     */
    protected function getPricesFromManagerCore(array $typeIds): array
    {
        if (!self::isManagerCoreInstalled()) {
            throw new Exception('Manager Core is not installed. Install mattfalahe/manager-core to use this provider.');
        }

        $pricingSettings = $this->settingsService->getPricingSettings();
        $priceType = $pricingSettings['price_type'] ?? 'sell';
        $market = $pricingSettings['manager_core_market'] ?? 'jita';
        $variant = $pricingSettings['manager_core_variant'] ?? 'min';

        $prices = [];

        // Query Manager Core's market prices table
        $marketPrices = DB::table('manager_core_market_prices')
            ->whereIn('type_id', $typeIds)
            ->where('market', $market)
            ->where('price_type', $priceType === 'average' ? 'sell' : $priceType)
            ->get();

        foreach ($marketPrices as $item) {
            // Select the price based on configured variant
            $price = match ($variant) {
                'min' => (float) ($item->price_min ?? 0),
                'max' => (float) ($item->price_max ?? 0),
                'avg' => (float) ($item->price_avg ?? 0),
                'median' => (float) ($item->price_median ?? 0),
                'percentile' => (float) ($item->price_percentile ?? 0),
                default => (float) ($item->price_min ?? 0),
            };

            // For 'average' price type, average buy and sell
            if ($priceType === 'average') {
                $buyPrice = DB::table('manager_core_market_prices')
                    ->where('type_id', $item->type_id)
                    ->where('market', $market)
                    ->where('price_type', 'buy')
                    ->first();

                if ($buyPrice) {
                    $buyValue = match ($variant) {
                        'min' => (float) ($buyPrice->price_min ?? 0),
                        'max' => (float) ($buyPrice->price_max ?? 0),
                        'avg' => (float) ($buyPrice->price_avg ?? 0),
                        'median' => (float) ($buyPrice->price_median ?? 0),
                        'percentile' => (float) ($buyPrice->price_percentile ?? 0),
                        default => (float) ($buyPrice->price_min ?? 0),
                    };
                    $price = ($price + $buyValue) / 2;
                }
            }

            $prices[$item->type_id] = $price;
        }

        // Fill missing prices with 0
        foreach ($typeIds as $typeId) {
            if (!isset($prices[$typeId])) {
                $prices[$typeId] = 0;
                Log::warning('Price not found in Manager Core', ['type_id' => $typeId, 'market' => $market]);
            }
        }

        return $prices;
    }

    /**
     * Check if Manager Core package is installed
     *
     * @return bool
     */
    public static function isManagerCoreInstalled(): bool
    {
        return class_exists('ManagerCore\Services\PricingService');
    }

    /**
     * Subscribe all Mining Manager type IDs to Manager Core
     *
     * Registers all ore, mineral, moon material, ice, and gas type IDs
     * with Manager Core's subscription system so it fetches prices for them.
     *
     * @param string $market Market to subscribe to (default: jita)
     * @return int Number of type IDs subscribed
     */
    public function subscribeToManagerCore(string $market = 'jita'): int
    {
        if (!self::isManagerCoreInstalled()) {
            throw new Exception('Manager Core is not installed.');
        }

        $typeIds = \MiningManager\Services\TypeIdRegistry::getTypeIdsByCategory('all');

        $pricingService = app('ManagerCore\Services\PricingService');
        $pricingService->registerTypes('mining-manager', $typeIds, $market);

        Log::info('Mining Manager: Subscribed ' . count($typeIds) . " type IDs to Manager Core for market '{$market}'");

        return count($typeIds);
    }

    /**
     * Get the configured price provider
     *
     * @return string
     */
    protected function getConfiguredProvider(): string
    {
        $pricingSettings = $this->settingsService->getPricingSettings();
        return $pricingSettings['price_provider'] ?? self::PROVIDER_SEAT;
    }

    /**
     * Test connection to a price provider
     *
     * @param string $provider
     * @return bool
     */
    public function testProvider(string $provider): bool
    {
        $originalProvider = $this->getConfiguredProvider();

        try {
            $testTypeId = 34; // Tritanium for testing

            $this->settingsService->updateSetting('price_provider', $provider, 'string');

            $price = $this->getPrice($testTypeId);

            return $price !== null && $price > 0;
        } catch (Exception $e) {
            Log::error('Provider test failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            return false;
        } finally {
            // Always restore the original provider, even if an exception occurred
            $this->settingsService->updateSetting('price_provider', $originalProvider, 'string');
        }
    }

    /**
     * Get available price providers
     *
     * @return array
     */
    public function getAvailableProviders(): array
    {
        return [
            self::PROVIDER_SEAT => [
                'name' => 'SeAT Database',
                'description' => 'Use prices from SeAT market_prices table (refreshed by SeAT)',
                'requires_config' => false
            ],
            self::PROVIDER_JANICE => [
                'name' => 'Janice',
                'description' => 'Janice appraisal service (requires API key)',
                'requires_config' => true,
                'config_fields' => ['janice_api_key', 'janice_market', 'janice_price_method']
            ],
            self::PROVIDER_FUZZWORK => [
                'name' => 'Fuzzwork',
                'description' => 'Community market aggregator',
                'requires_config' => false
            ],
            self::PROVIDER_CUSTOM => [
                'name' => 'Custom',
                'description' => 'Manually configured prices',
                'requires_config' => true,
                'config_fields' => ['custom_prices']
            ],
            self::PROVIDER_MANAGER_CORE => [
                'name' => 'Manager Core',
                'description' => 'Use Manager Core\'s cached market prices (ESI, EvePraisal, or SeAT)',
                'requires_config' => false,
                'available' => self::isManagerCoreInstalled(),
            ]
        ];
    }

    /**
     * Validate provider configuration
     *
     * @param string $provider
     * @return bool
     */
    public function validateProviderConfig(string $provider): bool
    {
        $providers = $this->getAvailableProviders();
        
        if (!isset($providers[$provider])) {
            return false;
        }

        $providerConfig = $providers[$provider];
        
        if (!$providerConfig['requires_config']) {
            return true;
        }

        // Check if required config fields are set
        foreach ($providerConfig['config_fields'] as $field) {
            if (empty($this->settingsService->getSetting($field))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Batch price fetching with smart batching
     *
     * @param array $typeIds
     * @param int $batchSize
     * @return array
     */
    public function getBatchPrices(array $typeIds, int $batchSize = 100): array
    {
        $provider = $this->getConfiguredProvider();

        // For database and custom providers, no need to batch
        if (in_array($provider, [self::PROVIDER_SEAT, self::PROVIDER_CUSTOM, self::PROVIDER_MANAGER_CORE])) {
            return $this->getPrices($typeIds);
        }

        // For API providers, batch the requests
        $batches = array_chunk($typeIds, $batchSize);
        $allPrices = [];

        foreach ($batches as $batch) {
            $batchPrices = $this->getPrices($batch);
            $allPrices = array_merge($allPrices, $batchPrices);
            
            // Small delay between batches for API providers
            if (count($batches) > 1) {
                usleep(100000); // 100ms
            }
        }

        return $allPrices;
    }

    /**
     * Check if a type ID has price data available
     *
     * @param int $typeId
     * @return bool
     */
    public function hasPriceData(int $typeId): bool
    {
        $price = $this->getPrice($typeId);
        return $price !== null && $price > 0;
    }

    /**
     * Get price source info (where the price came from)
     *
     * @param int $typeId
     * @return array
     */
    public function getPriceInfo(int $typeId): array
    {
        $provider = $this->getConfiguredProvider();
        $price = $this->getPrice($typeId);

        return [
            'type_id' => $typeId,
            'price' => $price,
            'provider' => $provider,
            'provider_name' => $this->getAvailableProviders()[$provider]['name'] ?? 'Unknown',
            'has_price' => $price > 0,
            'cached' => false, // Will be set by MarketDataService
            'fetched_at' => now()->toDateTimeString()
        ];
    }

    /**
     * Check if price cache is still fresh for a type ID
     *
     * @param int $typeId
     * @param int $regionId
     * @return bool
     */
    public function isCacheFresh(int $typeId, int $regionId): bool
    {
        $cacheEntry = MiningPriceCache::where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->first();

        if (!$cacheEntry) {
            return false;
        }

        // Check if cache is fresh based on configuration
        $pricingSettings = $this->settingsService->getPricingSettings();
        $cacheDuration = (int) ($pricingSettings['cache_duration'] ?? 240); // minutes
        $cacheAge = $cacheEntry->cached_at->diffInMinutes(Carbon::now());

        return $cacheAge < $cacheDuration;
    }

    /**
     * Cache price data for a type ID
     *
     * @param int $typeId
     * @param int $regionId
     * @param array $priceData
     * @return bool
     */
    public function cachePriceData(int $typeId, int $regionId, array $priceData): bool
    {
        try {
            MiningPriceCache::updateOrCreate(
                [
                    'type_id' => $typeId,
                    'region_id' => $regionId,
                ],
                [
                    'sell_price' => $priceData['sell'] ?? 0,
                    'buy_price' => $priceData['buy'] ?? 0,
                    'average_price' => $priceData['average'] ?? 0,
                    'cached_at' => Carbon::now(),
                ]
            );

            return true;
        } catch (Exception $e) {
            Log::error('Failed to cache price data', [
                'type_id' => $typeId,
                'region_id' => $regionId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Delete old cache entries
     *
     * @param Carbon $cutoffDate
     * @return int Number of deleted entries
     */
    public function deleteOldCache(Carbon $cutoffDate): int
    {
        try {
            return MiningPriceCache::where('cached_at', '<', $cutoffDate)->delete();
        } catch (Exception $e) {
            Log::error('Failed to delete old cache entries', [
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }
}
