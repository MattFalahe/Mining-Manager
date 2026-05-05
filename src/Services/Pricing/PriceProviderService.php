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
 * - Manager Core - Shared price cache (ESI/EvePraisal/SeAT)
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
     * Temporary provider override used during testProvider() calls.
     * When set, getConfiguredProvider() returns this instead of the DB setting.
     *
     * @var string|null
     */
    protected ?string $testProviderOverride = null;

    /**
     * Type IDs that were resolved via Jita fallback in the last getPrices() call.
     */
    protected array $lastJitaFallbackTypeIds = [];

    /**
     * Structured summary of the last Jita-fallback dispatch — null when the
     * most recent getPrices() call didn't trigger a fallback. Schema in
     * `getLastFallbackSummary()` docblock. Used by the diagnostic page to
     * surface fallback health without parsing logs.
     */
    protected ?array $lastFallbackSummary = null;

    /**
     * Price provider constants
     */
    const PROVIDER_SEAT = 'seat';
    const PROVIDER_JANICE = 'janice';
    const PROVIDER_FUZZWORK = 'fuzzwork';
    const PROVIDER_MANAGER_CORE = 'manager-core';

    /**
     * Threshold (in hours) beyond which a Manager Core price is considered
     * stale and worthy of a warning log.
     *
     * MC's `manager-core:update-prices` cron runs every 4 hours by default.
     * Any price older than 2× that interval (8 hours) almost certainly
     * indicates MC's cron is broken or paused — operators should check.
     *
     * Stale prices are still RETURNED (we don't fail the read just because
     * a price is old; the operator may want the stale value rather than
     * a zero that triggers fallback-to-jita). The warning is observability
     * only — surfaces in the log so an operator can spot and fix.
     */
    const MC_PRICE_STALENESS_HOURS = 8;

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
        $this->lastJitaFallbackTypeIds = [];

        $provider = $this->getConfiguredProvider();
        
        Log::info('Fetching prices', [
            'provider' => $provider,
            'type_count' => count($typeIds)
        ]);

        try {
            $prices = match ($provider) {
                self::PROVIDER_JANICE => $this->getPricesFromJanice($typeIds),
                self::PROVIDER_FUZZWORK => $this->getPricesFromFuzzwork($typeIds),
                self::PROVIDER_MANAGER_CORE => $this->getPricesFromManagerCore($typeIds),
                default => $this->getPricesFromSeAT($typeIds),
            };

            // Fallback to Jita: if enabled and market is not Jita, retry zero-price items with Jita
            $prices = $this->applyJitaFallback($provider, $prices, $typeIds);

            return $prices;
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

        $response = Http::timeout(10)->get(self::FUZZWORK_MARKET_URL, [
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

        // For "average" we need both sides (we average buy.<variant> and
        // sell.<variant> per type). MC's getPrice priceType='both' returns
        // both at once in a single shot, so we ask for it here and combine
        // in PHP rather than making 2× the calls.
        $bridgePriceType = $priceType === 'average' ? 'both' : $priceType;

        // Cross-plugin call via the documented PluginBridge contract
        // (pricing.getPrices). Pre-fix the implementation reached directly
        // into manager_core_market_prices via DB::table — fragile under MC
        // schema drift, bypassed MC's Cache::remember layer, and ignored the
        // documented capability surface entirely.
        //
        // The bridge call uses MC's getPrice / fetchPriceForType / formatPriceStats
        // pipeline, returning the per-type stats arrays:
        //   ['buy'  => ['min','max','avg','median','percentile','stddev','volume','order_count','strategy','updated_at'],
        //    'sell' => [ ... same shape ... ]]   (when $bridgePriceType = 'both')
        //   or just one of the buy/sell sub-arrays directly (when 'buy' or 'sell')
        //
        // Quirk to handle: getPrice has a single-element collapse —
        // `count($prices) === 1 ? reset($prices) : $prices`. So a call with
        // exactly one type id wrapped in an array returns the inner shape
        // (no typeId key). We canonicalise that below.
        try {
            $bridge = app(\ManagerCore\Services\PluginBridge::class);
            $rawResult = $bridge->call('ManagerCore', 'pricing.getPrices', $typeIds, $market, $bridgePriceType);
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: pricing.getPrices bridge call failed; returning zeros', [
                'error' => $e->getMessage(),
                'count' => count($typeIds),
                'market' => $market,
            ]);
            return array_fill_keys($typeIds, 0.0);
        }

        if ($rawResult === null) {
            // Capability not registered (MC version without pricing.getPrices)
            // OR an error inside the call returned null — either way, fail
            // safe with zeros so the fallback-to-jita layer can kick in.
            Log::warning('Mining Manager: pricing.getPrices returned null', [
                'count' => count($typeIds),
                'market' => $market,
            ]);
            return array_fill_keys($typeIds, 0.0);
        }

        // Canonicalise the single-element collapse: if we asked for N typeIds
        // but got back what looks like a stats array (no typeId int keys),
        // re-wrap.
        $resultByType = $this->normaliseBridgeGetPricesShape($rawResult, $typeIds);

        $prices = [];
        $stalenessThreshold = Carbon::now()->subHours(self::MC_PRICE_STALENESS_HOURS);
        $staleCount = 0;
        $staleSampleTypeIds = [];

        foreach ($typeIds as $typeId) {
            $entry = $resultByType[$typeId] ?? null;
            if ($entry === null) {
                $prices[$typeId] = 0;
                Log::warning('Price not found in Manager Core', ['type_id' => $typeId, 'market' => $market]);
                continue;
            }

            // For priceType=buy/sell, MC returns the inner stats shape directly.
            // For priceType=both, MC returns ['buy'=>stats, 'sell'=>stats].
            // Detect which shape we're holding.
            $hasBuySell = is_array($entry) && (array_key_exists('buy', $entry) || array_key_exists('sell', $entry));

            if ($priceType === 'average') {
                // Need both sides — must be 'both' shape.
                $sellStats = $hasBuySell ? ($entry['sell'] ?? null) : null;
                $buyStats  = $hasBuySell ? ($entry['buy']  ?? null) : null;

                $sellValue = $sellStats ? $this->extractVariant($sellStats, $variant) : 0;
                $buyValue  = $buyStats  ? $this->extractVariant($buyStats,  $variant) : 0;

                if ($sellStats && $buyStats) {
                    $prices[$typeId] = ($sellValue + $buyValue) / 2;
                } elseif ($sellStats) {
                    $prices[$typeId] = $sellValue;
                } elseif ($buyStats) {
                    $prices[$typeId] = $buyValue;
                } else {
                    $prices[$typeId] = 0;
                }

                // Staleness check — use the older of the two updated_at
                // timestamps. If either side is stale, the merge is too.
                $usedStats = $sellStats ?? $buyStats;
                if ($usedStats && $this->isStatsStale($usedStats, $stalenessThreshold)) {
                    $staleCount++;
                    if (count($staleSampleTypeIds) < 5) {
                        $staleSampleTypeIds[] = $typeId;
                    }
                }
            } else {
                // priceType is 'buy' or 'sell' — entry is the inner stats shape.
                $stats = $hasBuySell ? ($entry[$priceType] ?? null) : $entry;
                $prices[$typeId] = $stats ? $this->extractVariant($stats, $variant) : 0;

                if ($stats && $this->isStatsStale($stats, $stalenessThreshold)) {
                    $staleCount++;
                    if (count($staleSampleTypeIds) < 5) {
                        $staleSampleTypeIds[] = $typeId;
                    }
                }
            }
        }

        // Single warning per call rather than per-type spam. If a significant
        // fraction of returned prices are stale, MC's update-prices cron is
        // probably broken or paused — operators see this in the log and can
        // investigate. Stale prices are still RETURNED (the caller may
        // prefer a stale price over a fallback-to-jita zero), this is
        // observability only.
        if ($staleCount > 0) {
            Log::warning("Mining Manager: {$staleCount} of " . count($typeIds) . " prices from Manager Core are older than " . self::MC_PRICE_STALENESS_HOURS . "h", [
                'market' => $market,
                'price_type' => $priceType,
                'stale_sample_type_ids' => $staleSampleTypeIds,
                'hint' => 'Check that the manager-core:update-prices cron is running. Default schedule: every 4 hours.',
            ]);
        }

        return $prices;
    }

    /**
     * Determine whether a Manager Core formatPriceStats array represents
     * a price older than the staleness threshold.
     *
     * MC includes `updated_at` in every formatPriceStats output (Carbon
     * instance or ISO string depending on serialization path). Defensively
     * handle both shapes plus unparseable values (treat as not-stale to
     * avoid false-positive log spam from edge cases).
     *
     * @param array  $stats              MC formatPriceStats output
     * @param Carbon $stalenessThreshold Carbon time before which prices are stale
     * @return bool
     */
    protected function isStatsStale(array $stats, Carbon $stalenessThreshold): bool
    {
        $updatedAt = $stats['updated_at'] ?? null;
        if ($updatedAt === null) {
            return false;
        }

        try {
            $updatedAtCarbon = $updatedAt instanceof Carbon
                ? $updatedAt
                : Carbon::parse((string) $updatedAt);
        } catch (\Throwable $e) {
            return false;
        }

        return $updatedAtCarbon->lt($stalenessThreshold);
    }

    /**
     * Pull the configured variant (min/max/avg/median/percentile) value out of
     * an MC price-stats array. Single source of truth so both sides of the
     * 'average' merge path use the same selector.
     *
     * @param array  $stats  MC formatPriceStats output
     * @param string $variant
     * @return float
     */
    protected function extractVariant(array $stats, string $variant): float
    {
        return match ($variant) {
            'min' => (float) ($stats['min'] ?? 0),
            'max' => (float) ($stats['max'] ?? 0),
            'avg' => (float) ($stats['avg'] ?? 0),
            'median' => (float) ($stats['median'] ?? 0),
            'percentile' => (float) ($stats['percentile'] ?? 0),
            default => (float) ($stats['min'] ?? 0),
        };
    }

    /**
     * MC's getPrice has a single-element collapse: when called with exactly one
     * typeId (or a 1-element array) it returns the inner price shape rather
     * than a [typeId => shape] keyed array. This makes consumers handle two
     * different shapes for what should be a uniform contract.
     *
     * Insulate ourselves from that quirk: if the result shape isn't already
     * keyed by typeId, re-wrap it so downstream code sees the consistent
     * [typeId => shape] form.
     *
     * @param mixed $result   Raw return from pricing.getPrices
     * @param int[] $typeIds  The typeIds we asked for (in order)
     * @return array          [typeId => priceShape]
     */
    protected function normaliseBridgeGetPricesShape($result, array $typeIds): array
    {
        if (!is_array($result)) {
            return [];
        }

        // Already typeId-keyed if every key is numeric and a member of $typeIds.
        $typeIdSet = array_flip($typeIds);
        $allKeysAreTypeIds = !empty($result) && array_reduce(
            array_keys($result),
            fn($carry, $key) => $carry && isset($typeIdSet[$key]),
            true
        );

        if ($allKeysAreTypeIds) {
            return $result;
        }

        // Single-element collapse case: the only typeId we asked for IS the
        // entire result. Re-wrap.
        if (count($typeIds) === 1) {
            return [$typeIds[0] => $result];
        }

        // Unknown shape — log and return empty so callers fall back to zeros.
        Log::warning('Mining Manager: pricing.getPrices returned unexpected shape', [
            'sample_keys' => array_slice(array_keys($result), 0, 5),
            'requested_count' => count($typeIds),
        ]);
        return [];
    }

    /**
     * Apply Jita fallback for items that returned 0 price
     *
     * When fallback_to_jita is enabled and the configured market is not Jita,
     * re-fetch any zero-price items using Jita as the market.
     *
     * @param string $provider
     * @param array $prices
     * @param array $typeIds
     * @return array
     */
    protected function applyJitaFallback(string $provider, array $prices, array $typeIds): array
    {
        $this->lastJitaFallbackTypeIds = [];
        $this->lastFallbackSummary = null;

        $pricingSettings = $this->settingsService->getPricingSettings();
        $fallbackEnabled = $pricingSettings['fallback_to_jita'] ?? true;

        if (!$fallbackEnabled) {
            return $prices;
        }

        // Determine current market per provider
        $currentMarket = match ($provider) {
            self::PROVIDER_JANICE => $pricingSettings['janice_market'] ?? 'jita',
            self::PROVIDER_MANAGER_CORE => $pricingSettings['manager_core_market'] ?? 'jita',
            default => 'jita', // SeAT/Fuzzwork default to Jita region
        };

        // No fallback needed if already using Jita
        if ($currentMarket === 'jita') {
            return $prices;
        }

        // Find items that returned 0 from the primary provider
        $zeroTypeIds = array_keys(array_filter($prices, fn($price) => $price <= 0));
        $zeroCount = count($zeroTypeIds);

        if ($zeroCount === 0) {
            return $prices;
        }

        // STRUCTURED METRIC: fallback fire detected.
        //
        // One INFO log per dispatch with the full context an operator (or a
        // log-aggregation tool — Loki/ELK/Splunk) needs to spot patterns.
        // Sampling type_ids so the log line stays scannable but we keep
        // some signal for "which moon ores are missing prices?" debugging.
        $totalRequested = count($typeIds);
        $zeroFraction = $totalRequested > 0 ? round($zeroCount / $totalRequested, 3) : 0;

        Log::info('Mining Manager: Jita fallback dispatched', [
            'provider' => $provider,
            'configured_market' => $currentMarket,
            'requested_count' => $totalRequested,
            'zero_count' => $zeroCount,
            'zero_fraction' => $zeroFraction,
            'sample_zero_type_ids' => array_slice($zeroTypeIds, 0, 10),
        ]);

        $fallbackCount = 0;
        $fallbackError = null;

        try {
            $jitaPrices = match ($provider) {
                self::PROVIDER_JANICE => $this->getPricesFromJaniceWithMarket($zeroTypeIds, 'jita'),
                self::PROVIDER_MANAGER_CORE => $this->getPricesFromManagerCoreWithMarket($zeroTypeIds, 'jita'),
                self::PROVIDER_FUZZWORK => $this->getPricesFromFuzzworkWithRegion($zeroTypeIds, self::DEFAULT_REGION_ID),
                default => [],
            };

            foreach ($jitaPrices as $typeId => $price) {
                if ($price > 0 && ($prices[$typeId] ?? 0) <= 0) {
                    $prices[$typeId] = $price;
                    $this->lastJitaFallbackTypeIds[] = $typeId;
                    $fallbackCount++;
                }
            }
        } catch (Exception $e) {
            $fallbackError = $e->getMessage();
        }

        $unrecoveredCount = $zeroCount - $fallbackCount;
        $recoveryPct = $zeroCount > 0 ? round($fallbackCount / $zeroCount * 100, 1) : 0;

        // STRUCTURED METRIC: fallback completion summary.
        //
        // Tracked as instance state too (lastFallbackSummary) so a future
        // diagnostic page can read it without log scraping. Operators can
        // pivot from "I see N Jita fallback events per hour" in their log
        // tool to "what fraction was MC actually serving?" by computing
        // (1 - fallback_summary.zero_fraction) over a window.
        $this->lastFallbackSummary = [
            'provider' => $provider,
            'configured_market' => $currentMarket,
            'requested_count' => $totalRequested,
            'zero_count' => $zeroCount,
            'fallback_recovered_count' => $fallbackCount,
            'fallback_unrecovered_count' => $unrecoveredCount,
            'recovery_pct' => $recoveryPct,
            'fallback_error' => $fallbackError,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        // Log level reflects severity:
        //   - Error during the fallback request → warning (operator should investigate the second provider too)
        //   - Recovered <50% → warning (the primary provider is broken AND Jita can't fully cover)
        //   - Otherwise → info (typical operating mode for non-Jita primary)
        $logContext = $this->lastFallbackSummary;

        if ($fallbackError !== null) {
            Log::warning('Mining Manager: Jita fallback request failed', $logContext);
        } elseif ($zeroCount > 0 && $recoveryPct < 50) {
            Log::warning('Mining Manager: Jita fallback recovered <50% of missing prices', $logContext);
        } elseif ($fallbackCount > 0) {
            Log::info('Mining Manager: Jita fallback completed', $logContext);
        }

        return $prices;
    }

    /**
     * Read-only accessor for the last fallback dispatch summary.
     *
     * Returns null when the most recent `getPrices()` call did NOT trigger
     * a fallback (either fallback disabled, market already Jita, or no
     * zero prices). When non-null, contains the same structured context
     * emitted in the fallback completion log line — useful for a
     * diagnostic / admin page that wants to surface fallback health
     * without parsing logs.
     *
     * Schema:
     *   provider                     string
     *   configured_market            string
     *   requested_count              int
     *   zero_count                   int    (returned 0 from primary)
     *   fallback_recovered_count     int    (Jita filled in)
     *   fallback_unrecovered_count   int    (even Jita couldn't price)
     *   recovery_pct                 float  (0-100, percent of zeros that Jita recovered)
     *   fallback_error               ?string  (Jita request exception, if any)
     *   timestamp                    string ISO 8601
     *
     * @return array|null
     */
    public function getLastFallbackSummary(): ?array
    {
        return $this->lastFallbackSummary;
    }

    /**
     * Get type IDs that used Jita fallback in the last getPrices() call.
     *
     * @return array
     */
    public function getLastJitaFallbackTypeIds(): array
    {
        return $this->lastJitaFallbackTypeIds;
    }

    /**
     * Fetch Janice prices with a specific market override
     *
     * @param array $typeIds
     * @param string $market
     * @return array
     */
    protected function getPricesFromJaniceWithMarket(array $typeIds, string $market): array
    {
        $pricingSettings = $this->settingsService->getPricingSettings();
        $apiKey = $pricingSettings['janice_api_key'] ?? '';
        if (empty($apiKey)) {
            return [];
        }

        return $this->getPricesFromJanicePricer($typeIds, $apiKey, $market);
    }

    /**
     * Fetch Manager Core prices with a specific market override.
     *
     * Used by `applyJitaFallback` when the configured market returned 0 for
     * some type IDs and we want to retry against Jita before falling back
     * to local zeros.
     *
     * Pre-fix this called `DB::table('manager_core_market_prices')`
     * directly — the H7b PluginBridge migration missed this overload and
     * left it schema-coupled to MC's table layout. Now goes through
     * `pricing.getPrices` like the primary path, with the same defensive
     * shape-handling (single-element-collapse normalization, buy/sell vs
     * inner-stats variant detection, sane fallback to zeros on bridge
     * failure).
     *
     * @param array  $typeIds
     * @param string $market
     * @return array  [typeId => float]  prices keyed by type id, zeros for
     *                                   anything the bridge couldn't price
     */
    protected function getPricesFromManagerCoreWithMarket(array $typeIds, string $market): array
    {
        if (!self::isManagerCoreInstalled()) {
            return [];
        }

        if (empty($typeIds)) {
            return [];
        }

        $pricingSettings = $this->settingsService->getPricingSettings();
        $priceType = $pricingSettings['price_type'] ?? 'sell';
        $variant = $pricingSettings['manager_core_variant'] ?? 'min';

        // The Jita-fallback path doesn't try to be clever about 'average'
        // — if the user picked 'average', we just use sell-side here. This
        // matches the pre-fix behaviour and keeps the second-provider call
        // bounded. The primary `getPricesFromManagerCore` does proper
        // buy+sell averaging.
        $bridgePriceType = $priceType === 'average' ? 'sell' : $priceType;

        try {
            $bridge = app(\ManagerCore\Services\PluginBridge::class);
            $rawResult = $bridge->call('ManagerCore', 'pricing.getPrices', $typeIds, $market, $bridgePriceType);
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: pricing.getPrices bridge call failed in Jita-fallback path; returning zeros', [
                'error' => $e->getMessage(),
                'count' => count($typeIds),
                'market' => $market,
            ]);
            return array_fill_keys($typeIds, 0.0);
        }

        if ($rawResult === null) {
            // Capability not registered (older MC) or call returned null.
            // Fail safe with zeros so the caller doesn't accidentally treat
            // null as a price.
            Log::warning('Mining Manager: pricing.getPrices returned null in Jita-fallback path', [
                'count' => count($typeIds),
                'market' => $market,
            ]);
            return array_fill_keys($typeIds, 0.0);
        }

        $resultByType = $this->normaliseBridgeGetPricesShape($rawResult, $typeIds);

        $prices = [];
        foreach ($typeIds as $typeId) {
            $entry = $resultByType[$typeId] ?? null;
            if ($entry === null) {
                $prices[$typeId] = 0;
                continue;
            }

            // For priceType=buy/sell, MC returns the inner stats shape.
            // For priceType=both, MC returns ['buy'=>..., 'sell'=>...].
            $hasBuySell = is_array($entry) && (array_key_exists('buy', $entry) || array_key_exists('sell', $entry));
            $stats = $hasBuySell ? ($entry[$bridgePriceType] ?? null) : $entry;

            $prices[$typeId] = $stats ? $this->extractVariant($stats, $variant) : 0;
        }

        return $prices;
    }

    /**
     * Fetch Fuzzwork prices with a specific region override
     *
     * @param array $typeIds
     * @param int $regionId
     * @return array
     */
    protected function getPricesFromFuzzworkWithRegion(array $typeIds, int $regionId): array
    {
        $pricingSettings = $this->settingsService->getPricingSettings();
        $priceMethod = $pricingSettings['price_type'] ?? 'sell';
        $typeIdsString = implode(',', $typeIds);

        $response = Http::timeout(10)->get(self::FUZZWORK_MARKET_URL, [
            'region' => $regionId,
            'types' => $typeIdsString,
        ]);

        if (!$response->successful()) {
            return [];
        }

        $data = $response->json();
        $prices = [];

        foreach ($typeIds as $typeId) {
            if (isset($data[$typeId])) {
                $itemData = $data[$typeId];
                $prices[$typeId] = match ($priceMethod) {
                    'buy' => (float) ($itemData['buy']['max'] ?? 0),
                    'sell' => (float) ($itemData['sell']['min'] ?? 0),
                    default => ((float) ($itemData['buy']['max'] ?? 0) + (float) ($itemData['sell']['min'] ?? 0)) / 2,
                };
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
    public function subscribeToManagerCore(string $market = 'jita', bool $immediateRefresh = true): int
    {
        if (!self::isManagerCoreInstalled()) {
            throw new Exception('Manager Core is not installed.');
        }

        $typeIds = \MiningManager\Services\TypeIdRegistry::getTypeIdsByCategory('all');

        // Cross-plugin call via the documented PluginBridge contract
        // (pricing.subscribeTypes). Pre-fix this called PricingService directly
        // via service-locator (`app('ManagerCore\Services\PricingService')`) —
        // works today but bypasses the documented capability surface and ties
        // us to MC's concrete class name. The bridge call is forward-compat
        // friendly: MC can rename the underlying class, restructure the
        // service container, or add an audit middleware to capabilities, and
        // MM keeps working.
        //
        // $immediateRefresh forwards to MC's registerTypes 5th arg via the
        // (recently-extended) capability lambda. true = MC dispatches a
        // RefreshMarketPricesJob to populate prices via the queue; false =
        // MC persists the subscription and lets its 4-hourly cron pick up
        // new types. Boot path passes false to avoid dispatching a job on
        // every PHP request.
        $bridge = app(\ManagerCore\Services\PluginBridge::class);
        $bridgeResult = $bridge->call('ManagerCore', 'pricing.subscribeTypes', 'mining-manager', $typeIds, $market, 1, $immediateRefresh);

        // PluginBridge::call() returns null when the capability isn't
        // registered (older MC version pre-`8381cc1` that didn't plumb
        // immediateRefresh through, or a much older MC that didn't ship
        // the capability at all). Pre-fix we ignored the return value and
        // logged "Subscribed N type IDs" even when nothing was persisted —
        // operators saw success in logs while MC's table stayed empty.
        if ($bridgeResult === null) {
            Log::warning('Mining Manager: pricing.subscribeTypes capability returned null. MC may be on an older version. Falling back to direct service call.', [
                'market' => $market,
                'count' => count($typeIds),
            ]);

            // Fallback: legacy service-locator path. Older MC versions
            // expose PricingService::registerTypes directly (the bridge
            // capability is just a thin wrapper around it). This keeps
            // the subscription path working during MM-ahead-of-MC upgrade
            // windows.
            try {
                $pricingService = app('ManagerCore\\Services\\PricingService');
                $pricingService->registerTypes('mining-manager', $typeIds, $market, 1, $immediateRefresh);
            } catch (\Throwable $e) {
                Log::warning('Mining Manager: Legacy registerTypes fallback also failed: ' . $e->getMessage());
                return 0;
            }
        }

        Log::info('Mining Manager: Subscribed ' . count($typeIds) . " type IDs to Manager Core for market '{$market}' (immediate_refresh=" . ($immediateRefresh ? 'true' : 'false') . ')');

        return count($typeIds);
    }

    /**
     * Unsubscribe all Mining Manager type IDs from Manager Core
     *
     * Called when switching away from Manager Core as price provider
     * to clean up subscriptions so manager-core doesn't fetch prices
     * we no longer need.
     *
     * @return int Number of subscriptions removed
     */
    public function unsubscribeFromManagerCore(): int
    {
        if (!self::isManagerCoreInstalled()) {
            return 0;
        }

        try {
            // Cross-plugin call via PluginBridge (pricing.unsubscribeTypes,
            // added in MC commit dd50b94). Pre-fix this did a raw
            // DB::table('manager_core_type_subscriptions')->delete() which
            // bypassed the documented capability surface and tied us to the
            // MC schema. Through the bridge: MC controls the deletion shape
            // and can add audit/observer logic in future without breaking us.
            //
            // Passing market=null removes ALL of mining-manager's
            // subscriptions across every market — matches the previous
            // wholesale-delete behaviour. Returns the deleted row count
            // (capability returns int from PricingService::unregisterTypes).
            $bridge = app(\ManagerCore\Services\PluginBridge::class);
            $count = $bridge->call('ManagerCore', 'pricing.unsubscribeTypes', 'mining-manager', null);

            // Capability returns null when not registered (older MC version
            // without H7a). Safe fallback: legacy direct-DB delete so an
            // upgrade path that updates MM before MC still works.
            if ($count === null) {
                Log::info('Mining Manager: pricing.unsubscribeTypes capability not registered (older MC); falling back to direct DB delete');
                $count = DB::table('manager_core_type_subscriptions')
                    ->where('plugin_name', 'mining-manager')
                    ->delete();
            }

            Log::info("Mining Manager: Unsubscribed {$count} type IDs from Manager Core");

            return (int) $count;
        } catch (Exception $e) {
            Log::warning('Mining Manager: Failed to unsubscribe from Manager Core: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get the configured price provider
     *
     * @return string
     */
    protected function getConfiguredProvider(): string
    {
        if ($this->testProviderOverride !== null) {
            return $this->testProviderOverride;
        }

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
        $this->testProviderOverride = $provider;

        try {
            $testTypeId = 34; // Tritanium for testing

            $price = $this->getPrice($testTypeId);

            return $price !== null && $price > 0;
        } catch (Exception $e) {
            Log::error('Provider test failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            return false;
        } finally {
            $this->testProviderOverride = null;
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

        // Manager Core has no `config_fields` (its only requirement is that
        // the MC plugin itself is installed), so the `requires_config`
        // branch falls straight through to `return true` — even when MC
        // isn't installed. That's an early-return false-positive: any
        // pricing call that goes through this validator says "OK", then
        // `getPricesFromManagerCore` throws "Manager Core is not installed."
        // Callers see a confusing two-step failure (validator says config
        // is fine, then the read explodes).
        //
        // Special-case MC: its real precondition is the class existence
        // probe `isManagerCoreInstalled()`. If MC's PricingService class
        // isn't autoloadable, the provider is invalid regardless of what
        // the descriptor in `getAvailableProviders()` says.
        if ($provider === self::PROVIDER_MANAGER_CORE) {
            return self::isManagerCoreInstalled();
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
        if (in_array($provider, [self::PROVIDER_SEAT, self::PROVIDER_MANAGER_CORE])) {
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
