<?php

namespace MattFalahe\Seat\MiningManager\Services\Pricing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\Setting;
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
     * Price provider constants
     */
    const PROVIDER_SEAT = 'seat';
    const PROVIDER_JANICE = 'janice';
    const PROVIDER_FUZZWORK = 'fuzzwork';
    const PROVIDER_CUSTOM = 'custom';

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
        $priceMethod = Setting::get('price_method', 'average');
        
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
        $apiKey = Setting::get('janice_api_key');
        
        if (empty($apiKey)) {
            throw new Exception('Janice API key not configured');
        }

        $prices = [];
        $market = Setting::get('janice_market', 'jita'); // jita or amarr

        // Janice API is more efficient when we call individually for pricing
        // Use the pricer endpoint for single items
        foreach ($typeIds as $typeId) {
            try {
                $url = sprintf('%s/%d?market=%s', 
                    self::JANICE_PRICER_URL, 
                    $typeId, 
                    $market === 'jita' ? '2' : '1' // 2=Jita, 1=Amarr
                );

                $response = Http::withHeaders([
                    'X-ApiKey' => $apiKey,
                    'accept' => 'application/json'
                ])->get($url);

                if (!$response->successful()) {
                    Log::warning('Janice API error for type', [
                        'type_id' => $typeId,
                        'status' => $response->status()
                    ]);
                    $prices[$typeId] = 0;
                    continue;
                }

                $data = $response->json();
                
                // Get price based on configured method
                $priceMethod = Setting::get('janice_price_method', 'buy');
                
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

                // Small delay to avoid rate limiting
                usleep(50000); // 50ms delay

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
        $regionId = Setting::get('price_region_id', self::DEFAULT_REGION_ID);
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
        $priceMethod = Setting::get('price_method', 'sell');

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
        $customPrices = Setting::get('custom_prices', []);
        $prices = [];

        foreach ($typeIds as $typeId) {
            $prices[$typeId] = (float) ($customPrices[$typeId] ?? 0);
        }

        return $prices;
    }

    /**
     * Get the configured price provider
     *
     * @return string
     */
    protected function getConfiguredProvider(): string
    {
        return Setting::get('price_provider', self::PROVIDER_SEAT);
    }

    /**
     * Test connection to a price provider
     *
     * @param string $provider
     * @return bool
     */
    public function testProvider(string $provider): bool
    {
        try {
            $testTypeId = 34; // Tritanium for testing
            
            $originalProvider = $this->getConfiguredProvider();
            Setting::set('price_provider', $provider);
            
            $price = $this->getPrice($testTypeId);
            
            Setting::set('price_provider', $originalProvider);
            
            return $price !== null && $price > 0;
        } catch (Exception $e) {
            Log::error('Provider test failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            return false;
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
            if (empty(Setting::get($field))) {
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
        if (in_array($provider, [self::PROVIDER_SEAT, self::PROVIDER_CUSTOM])) {
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
}
