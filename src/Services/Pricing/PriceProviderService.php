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

    /**
     * How many ids go in one Janice request, and how long to wait between
     * them. A whole refresh is a handful of requests at this size, which is
     * the point: the owner blocks keys for excessive traffic.
     */
    const JANICE_BATCH_SIZE = 100;
    const JANICE_BATCH_PAUSE_US = 2000000;
    const JANICE_RETRY_PAUSE_US = 1000000;
    const JANICE_MAX_SPLITS = 3;
    const JANICE_TIMEOUT_SECONDS = 30;

    /**
     * Where the provider's state is kept, and how many ids an answer has to
     * cover before an empty one counts as the provider being down rather than
     * a few ores nobody trades.
     */
    const PROVIDER_STATUS_KEY = 'pricing.provider_status';
    const PROVIDER_DOWN_MIN_IDS = 5;
    const FUZZWORK_BATCH_SIZE = 200;
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

            // An ask of any size that comes back with nothing at all is the
            // provider being down, not an ore without a market: a few ids can
            // legitimately have no price between them, a few hundred cannot.
            $answered = count(array_filter($prices, function ($price) {
                return $price > 0;
            }));

            if ($answered === 0 && count($typeIds) >= self::PROVIDER_DOWN_MIN_IDS) {
                $this->recordProviderOutcome($provider, false, 'The provider answered, but with no prices at all');
            } else {
                $this->recordProviderOutcome($provider, true);
            }

            // Fallback to Jita: if enabled and market is not Jita, retry zero-price items with Jita
            $prices = $this->applyJitaFallback($provider, $prices, $typeIds);

            return $prices;
        } catch (Exception $e) {
            Log::error('Failed to fetch prices', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            $this->recordProviderOutcome($provider, false, $e->getMessage());

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
     * Fetch prices from Janice.
     *
     * One request per batch of ids, not one per id: Janice publishes no rate
     * limit, but its owner blocks keys for excessive traffic, and a refresh
     * used to be one request per price several hundred times over. The pricer
     * endpoint takes a list and answers with the same prices.
     *
     * @param array $typeIds
     * @return array [type_id => price]
     */
    protected function getPricesFromJanice(array $typeIds): array
    {
        $pricingSettings = $this->settingsService->getPricingSettings();

        // Check settings first, then fall back to ENV/config
        $apiKey = $pricingSettings['janice_api_key'] ?? '';

        if (empty($apiKey)) {
            throw new Exception('Janice API key not configured. Set it in Settings UI or MINING_MANAGER_JANICE_API_KEY env variable.');
        }

        $market = ($pricingSettings['janice_market'] ?? 'jita') === 'jita' ? '2' : '1';
        $method = $pricingSettings['janice_price_method'] ?? 'buy';
        $batchSize = max(1, min(500, (int) $this->settingsService->getSetting('janice_batch_size', self::JANICE_BATCH_SIZE)));
        $pause = max(0, (int) $this->settingsService->getSetting('janice_rate_limit_delay', self::JANICE_BATCH_PAUSE_US));

        $wanted = array_values(array_unique(array_map('intval', $typeIds)));
        $prices = [];

        foreach (array_chunk($wanted, $batchSize) as $index => $batch) {
            if ($index > 0 && $pause > 0) {
                usleep($pause);
            }

            $prices += $this->fetchJaniceBatch($batch, $apiKey, $market, $method);
        }

        // Ids Janice does not answer for keep no price here. Nothing writes a
        // zero over a good price, so they simply keep whatever was cached.
        return $prices;
    }

    /**
     * One batch, with a staged retreat when the request itself fails.
     *
     * A refused key (401, 403, 429) stops the whole refresh: asking again in
     * smaller pieces is exactly the traffic that gets a key blocked. A server
     * error, a timeout or a rejected request is worth retrying in halves, and
     * a handful of leftovers one at a time, because those can be a single bad
     * id or a blip rather than a closed door.
     *
     * @return array [type_id => price]
     */
    protected function fetchJaniceBatch(array $typeIds, string $apiKey, string $market, string $method, int $depth = 0): array
    {
        if (empty($typeIds)) {
            return [];
        }

        try {
            return $this->janicePricerRequest($typeIds, $apiKey, $market, $method);
        } catch (JaniceRefusedException $e) {
            // Nothing to salvage: the key is the problem, not the batch.
            throw $e;
        } catch (Exception $e) {
            Log::warning('Mining Manager: Janice batch failed', [
                'ids' => count($typeIds),
                'depth' => $depth,
                'error' => $e->getMessage(),
            ]);

            if (count($typeIds) === 1 || $depth >= self::JANICE_MAX_SPLITS) {
                return [];
            }

            $half = (int) ceil(count($typeIds) / 2);
            $prices = [];
            foreach (array_chunk($typeIds, $half) as $piece) {
                usleep(self::JANICE_RETRY_PAUSE_US);
                $prices += $this->fetchJaniceBatch($piece, $apiKey, $market, $method, $depth + 1);
            }

            return $prices;
        }
    }

    /**
     * The pricer call itself: ids as text, one per line.
     *
     * @return array [type_id => price]
     * @throws JaniceRefusedException when Janice refuses the key
     * @throws Exception when the request fails in a way worth retrying
     */
    protected function janicePricerRequest(array $typeIds, string $apiKey, string $market, string $method): array
    {
        $response = Http::timeout(self::JANICE_TIMEOUT_SECONDS)
            ->withHeaders([
                'X-ApiKey' => $apiKey,
                'Content-Type' => 'text/plain',
                'accept' => 'application/json',
            ])
            ->withBody(implode("
", $typeIds), 'text/plain')
            ->post(self::JANICE_PRICER_URL . '?market=' . $market);

        if (in_array($response->status(), [401, 403, 429], true)) {
            throw new JaniceRefusedException(
                'Janice refused the request with HTTP ' . $response->status()
                . ($response->status() === 429 ? ' (too many requests)' : ' (check the API key)')
            );
        }

        if (!$response->successful()) {
            throw new Exception('Janice returned HTTP ' . $response->status());
        }

        $items = $response->json();
        if (!is_array($items)) {
            throw new Exception('Janice returned something other than a list of prices');
        }

        $prices = [];
        foreach ($items as $item) {
            $typeId = (int) ($item['itemType']['eid'] ?? 0);
            if ($typeId <= 0) {
                continue;
            }

            $price = $this->janicePrice($item['immediatePrices'] ?? [], $method);
            if ($price > 0) {
                $prices[$typeId] = $price;
            }
        }

        return $prices;
    }

    /**
     * The price Janice's answer gives for the configured method.
     *
     * Moon ore often trades on one side only, and a split of a side with no
     * orders is half of nothing, so split falls back to whichever side has a
     * price rather than reporting a moon as worthless.
     */
    protected function janicePrice(array $immediate, string $method): float
    {
        $buy = (float) ($immediate['buyPrice'] ?? 0);
        $sell = (float) ($immediate['sellPrice'] ?? 0);

        switch ($method) {
            case 'sell':
                return $sell;
            case 'split':
                if ($buy > 0 && $sell > 0) {
                    return (float) ($immediate['splitPrice'] ?? (($buy + $sell) / 2));
                }

                return $buy > 0 ? $buy : $sell;
            default:
                return $buy;
        }
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
        $regionId = $generalSettings['default_region_id'] ?? self::DEFAULT_REGION_ID;

        // Fuzzwork takes the ids in the query string, so a whole refresh in
        // one call is a URL thousands of characters long. Chunk it.
        if (count($typeIds) > self::FUZZWORK_BATCH_SIZE) {
            $prices = [];
            foreach (array_chunk(array_values($typeIds), self::FUZZWORK_BATCH_SIZE) as $batch) {
                $prices += $this->getPricesFromFuzzwork($batch);
            }

            return $prices;
        }

        $pricingSettings = $this->settingsService->getPricingSettings();
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
        // (pricing.getPrices). Reaching directly into
        // manager_core_market_prices via DB::table is fragile under MC schema
        // drift, bypasses MC's Cache::remember layer, and ignores the
        // documented capability surface.
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
            // 4th arg 'mining-manager' (MC Option B, 2026-05-29): tells MC
            // to consult MM's provider_override pref before reading cache.
            // When set (e.g. operator routed MM through Janice), MC does
            // a live upstream fetch via the override. When null/empty
            // (default), MC reads its cache populated by per-market routing.
            $rawResult = $bridge->call('ManagerCore', 'pricing.getPrices', $typeIds, $market, $bridgePriceType, 'mining-manager');
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

        // Only the prices that came back empty land here, so this is a small
        // list and one request covers it.
        return $this->fetchJaniceBatch(
            array_values(array_unique(array_map('intval', $typeIds))),
            $apiKey,
            $market === 'jita' ? '2' : '1',
            $pricingSettings['janice_price_method'] ?? 'buy'
        );
    }

    /**
     * Fetch Manager Core prices with a specific market override.
     *
     * Used by `applyJitaFallback` when the configured market returned 0 for
     * some type IDs and we want to retry against Jita before falling back
     * to local zeros.
     *
     * Goes through `pricing.getPrices` like the primary path rather than
     * `DB::table('manager_core_market_prices')`, with the same defensive
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
        // keeps the second-provider call bounded. The primary
        // `getPricesFromManagerCore` does proper buy+sell averaging.
        $bridgePriceType = $priceType === 'average' ? 'sell' : $priceType;

        try {
            $bridge = app(\ManagerCore\Services\PluginBridge::class);
            // 4th arg 'mining-manager' (MC Option B, 2026-05-29): consult
            // the provider_override on MM's pref row for the Jita-fallback
            // path too — if MM's primary read went through Janice, the
            // fallback should also go through Janice for consistency.
            $rawResult = $bridge->call('ManagerCore', 'pricing.getPrices', $typeIds, $market, $bridgePriceType, 'mining-manager');
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
        // (pricing.subscribeTypes). Calling PricingService directly via
        // service-locator (`app('ManagerCore\Services\PricingService')`)
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
        // the capability at all). Ignoring the return value here would log
        // "Subscribed N type IDs" even when nothing was persisted —
        // operators see success in the logs while MC's table stays empty.
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
            // added in MC commit dd50b94). A raw
            // DB::table('manager_core_type_subscriptions')->delete() would
            // bypass the documented capability surface and tie us to the MC
            // schema. Through the bridge, MC controls the deletion shape and
            // can add audit/observer logic later without breaking us.
            //
            // Passing market=null removes ALL of mining-manager's
            // subscriptions across every market — matches the previous
            // wholesale-delete behaviour. Returns the deleted row count
            // (capability returns int from PricingService::unregisterTypes).
            $bridge = app(\ManagerCore\Services\PluginBridge::class);
            $count = $bridge->call('ManagerCore', 'pricing.unsubscribeTypes', 'mining-manager', null);

            // Capability returns null when not registered (e.g. capability
            // surface changed in a future MC version, or MC's bridge boot
            // failed silently). Safe fallback: legacy direct-DB delete so
            // an unexpected null response never leaves orphan subscription
            // rows behind.
            if ($count === null) {
                Log::info('Mining Manager: pricing.unsubscribeTypes capability not registered; falling back to direct DB delete');
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
     * Type IDs whose cached price was written at or after the given moment
     *
     * @param int $regionId
     * @param Carbon $since
     * @return int[]
     */
    public function typeIdsCachedSince(int $regionId, Carbon $since): array
    {
        return MiningPriceCache::where('region_id', $regionId)
            ->where('cached_at', '>=', $since)
            ->pluck('type_id')
            ->map(fn ($typeId) => (int) $typeId)
            ->all();
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
        $sell = max(0.0, (float) ($priceData['sell'] ?? 0));
        $buy = max(0.0, (float) ($priceData['buy'] ?? 0));
        $average = max(0.0, (float) ($priceData['average'] ?? 0));

        // Nothing arrived. A zero used to be written here with a fresh
        // timestamp, which threw away a good price and made the miss look
        // like a current price of nothing. The row is left exactly as it is,
        // stale timestamp and all, so the next refresh still counts it as due.
        if ($sell <= 0 && $buy <= 0 && $average <= 0) {
            return false;
        }

        try {
            $values = ['cached_at' => Carbon::now()];

            // Each side only replaces what is cached when a real price for it
            // turned up: a provider with orders on one side of the market
            // must not wipe the other side.
            if ($sell > 0) {
                $values['sell_price'] = $sell;
            }
            if ($buy > 0) {
                $values['buy_price'] = $buy;
            }
            if ($average > 0) {
                $values['average_price'] = $average;
            }

            MiningPriceCache::updateOrCreate(
                [
                    'type_id' => $typeId,
                    'region_id' => $regionId,
                ],
                $values
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

    /**
     * What the price provider is doing: failing, and since when.
     *
     * @return array{failing: bool, provider: ?string, error: ?string, since: ?string, last_success: ?string}
     */
    public function providerStatus(): array
    {
        $status = $this->settingsService->getSetting(self::PROVIDER_STATUS_KEY, []);
        if (!is_array($status)) {
            $status = [];
        }

        return [
            'failing' => (bool) ($status['failing'] ?? false),
            'provider' => $status['provider'] ?? null,
            'error' => $status['error'] ?? null,
            'since' => $status['since'] ?? null,
            'last_success' => $status['last_success'] ?? null,
        ];
    }

    /**
     * Report how a fetch went from a path that does not go through
     * getPrices(), such as the Manager Core sync in the cache command.
     */
    public function noteProviderOutcome(bool $ok, ?string $error = null): void
    {
        $this->recordProviderOutcome($this->getConfiguredProvider(), $ok, $error);
    }

    /**
     * Remember how the last fetch went, and say so once when that changes.
     *
     * Only the change is worth an alert: a provider that is down stays down
     * for hours and one message per refresh would be noise nobody reads. An
     * ore without a price is not failure at all, so nothing here fires for it.
     */
    protected function recordProviderOutcome(string $provider, bool $ok, ?string $error = null): void
    {
        try {
            $status = $this->providerStatus();
            $now = Carbon::now()->format('Y-m-d H:i');
            $changed = $status['failing'] === $ok;

            $this->settingsService->updateSetting(self::PROVIDER_STATUS_KEY, [
                'failing' => !$ok,
                'provider' => $provider,
                'error' => $ok ? null : $error,
                'since' => $ok ? null : ($status['since'] ?? $now),
                'last_success' => $ok ? $now : $status['last_success'],
            ], 'json');

            if (!$changed) {
                return;
            }

            $this->announceProviderStatus([
                'provider' => $provider,
                'failing' => !$ok,
                'error' => $ok ? null : $error,
                'since' => $ok ? $status['since'] : $now,
                'last_success' => $ok ? $now : $status['last_success'],
            ]);
        } catch (Exception $e) {
            // Fetching prices must not fall over because a status note or an
            // alert did.
            Log::warning('Mining Manager: could not record the price provider status', ['error' => $e->getMessage()]);
        }
    }

    protected function announceProviderStatus(array $data): void
    {
        try {
            app(\MiningManager\Services\Notification\NotificationService::class)->sendPriceProviderStatus($data);
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: price provider alert could not be sent', ['error' => $e->getMessage()]);
        }
    }
}
