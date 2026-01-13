<?php

namespace MiningManager\Services\Pricing;

use MiningManager\Services\ReprocessingRegistry;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Models\MiningPriceCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Ore Valuation Service
 *
 * Calculates ore values using either raw ore prices or refined mineral prices
 * based on the configured valuation method.
 *
 * Handles special cases:
 * - Gas cannot be refined (always uses raw ore price)
 * - Respects ore_valuation_method setting from config
 * - Applies refining efficiency from config
 *
 * Price Fallback Strategy:
 * 1. Check MiningPriceCache (fastest, pre-populated via cache command)
 * 2. If cache miss, use MarketDataService (respects configured price provider)
 * 3. If provider fails, fallback to SeAT's market_prices table
 */
class OreValuationService
{
    /**
     * Market data service (uses configured price provider)
     *
     * @var MarketDataService
     */
    protected $marketDataService;

    /**
     * Constructor
     *
     * @param MarketDataService $marketDataService
     */
    public function __construct(MarketDataService $marketDataService)
    {
        $this->marketDataService = $marketDataService;
    }

    /**
     * Calculate ore value based on configured valuation method.
     *
     * @param int $typeId
     * @param int $quantity
     * @param array $options Optional settings override
     * @return array ['ore_value' => float, 'mineral_value' => float, 'total_value' => float, 'unit_price' => float]
     */
    public function calculateOreValue(int $typeId, int $quantity, array $options = []): array
    {
        // Get settings from config or options
        $valuationMethod = $options['ore_valuation_method']
            ?? config('mining-manager.general.ore_valuation_method', 'mineral_price');
        $refiningRate = $options['ore_refining_rate']
            ?? config('mining-manager.general.ore_refining_rate', 90.0);
        $regionId = $options['default_region_id']
            ?? config('mining-manager.general.default_region_id', 10000002);
        $priceType = $options['price_type']
            ?? config('mining-manager.pricing.price_type', 'sell');
        $priceModifier = $options['price_modifier']
            ?? config('mining-manager.general.price_modifier', 0.0);

        // Always calculate raw ore value
        $orePrice = $this->getOrePrice($typeId, $regionId, $priceType);
        $rawOreValue = $quantity * ($orePrice ?? 0);

        // Apply price modifier to raw ore value
        $modifiedOreValue = $rawOreValue * (1 + ($priceModifier / 100));

        // Initialize result
        $result = [
            'ore_value' => $modifiedOreValue,
            'mineral_value' => 0,
            'total_value' => $modifiedOreValue,
            'unit_price' => $orePrice ?? 0,
            'valuation_method' => $valuationMethod,
        ];

        // Check if this is gas (cannot be refined)
        if ($this->isGas($typeId)) {
            Log::debug("OreValuationService: Type {$typeId} is gas, using raw ore price only");
            return $result;
        }

        // If valuation method is mineral_price, calculate refined value
        if ($valuationMethod === 'mineral_price') {
            $refinedValue = $this->calculateRefinedMineralValue(
                $typeId,
                $quantity,
                $refiningRate,
                $regionId,
                $priceType
            );

            // Apply price modifier to refined value
            $modifiedRefinedValue = $refinedValue * (1 + ($priceModifier / 100));

            $result['mineral_value'] = $modifiedRefinedValue;
            $result['total_value'] = $modifiedRefinedValue;
        }

        return $result;
    }

    /**
     * Calculate refined mineral value for ore.
     *
     * @param int $typeId
     * @param int $quantity
     * @param float $refiningRate Refining rate as percentage (0-100)
     * @param int $regionId
     * @param string $priceType
     * @return float
     */
    protected function calculateRefinedMineralValue(
        int $typeId,
        int $quantity,
        float $refiningRate,
        int $regionId,
        string $priceType
    ): float {
        // Get mineral composition from ReprocessingRegistry
        $minerals = ReprocessingRegistry::getMinerals($typeId);

        if ($minerals === null || empty($minerals)) {
            Log::debug("OreValuationService: No refining data for type_id {$typeId}, using raw ore price");

            // Fallback to raw ore price
            $orePrice = $this->getOrePrice($typeId, $regionId, $priceType);
            return $quantity * ($orePrice ?? 0);
        }

        // Get all unique mineral type IDs
        $mineralTypeIds = array_keys($minerals);

        // Fetch prices for all minerals at once
        $mineralPrices = [];
        foreach ($mineralTypeIds as $mineralTypeId) {
            $price = $this->getOrePrice($mineralTypeId, $regionId, $priceType);
            $mineralPrices[$mineralTypeId] = $price ?? 0;
        }

        // Convert refining rate from percentage to decimal
        $refiningEfficiency = $refiningRate / 100;

        // Use ReprocessingRegistry to calculate refined value
        $refinedValue = ReprocessingRegistry::calculateRefinedValue(
            $typeId,
            $quantity,
            $refiningEfficiency,
            $mineralPrices
        );

        return $refinedValue;
    }

    /**
     * Get ore price with fallback strategy.
     *
     * Priority:
     * 1. MiningPriceCache (fastest, pre-populated)
     * 2. MarketDataService (uses configured price provider)
     * 3. SeAT market_prices table (last resort)
     *
     * @param int $typeId
     * @param int $regionId
     * @param string $priceType
     * @return float|null
     */
    protected function getOrePrice(int $typeId, int $regionId, string $priceType): ?float
    {
        // Try cache first (fastest)
        $priceCache = MiningPriceCache::where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->latest('cached_at')
            ->first();

        if ($priceCache) {
            $price = match ($priceType) {
                'buy' => $priceCache->buy_price,
                'average' => $priceCache->average_price,
                default => $priceCache->sell_price,
            };

            if ($price > 0) {
                return $price;
            }
        }

        // Cache miss or zero price - fetch from configured price provider
        Log::info("OreValuationService: Cache miss for type_id {$typeId}, fetching from price provider");

        try {
            $price = $this->marketDataService->getCachedPrice($typeId);

            if ($price && $price > 0) {
                // Store in cache for next time
                $this->storePriceInCache($typeId, $regionId, $price);
                return $price;
            }
        } catch (\Exception $e) {
            Log::warning("OreValuationService: MarketDataService failed for type_id {$typeId}: {$e->getMessage()}");
        }

        // Final fallback: query SeAT's market_prices table directly
        Log::info("OreValuationService: Using SeAT market_prices fallback for type_id {$typeId}");

        try {
            $marketPrice = DB::table('market_prices')
                ->where('type_id', $typeId)
                ->first();

            if ($marketPrice) {
                $price = match ($priceType) {
                    'buy' => null, // market_prices doesn't have buy price
                    'average' => $marketPrice->average_price ?? 0,
                    default => $marketPrice->adjusted_price ?? $marketPrice->average_price ?? 0,
                };

                if ($price > 0) {
                    // Store in cache
                    $this->storePriceInCache($typeId, $regionId, $price);
                    return $price;
                }
            }
        } catch (\Exception $e) {
            Log::error("OreValuationService: All price sources failed for type_id {$typeId}: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Store price in cache for future use.
     *
     * @param int $typeId
     * @param int $regionId
     * @param float $price
     * @return void
     */
    protected function storePriceInCache(int $typeId, int $regionId, float $price): void
    {
        try {
            MiningPriceCache::updateOrCreate(
                [
                    'type_id' => $typeId,
                    'region_id' => $regionId,
                ],
                [
                    'sell_price' => $price,
                    'buy_price' => $price,
                    'average_price' => $price,
                    'cached_at' => now(),
                ]
            );

            Log::debug("OreValuationService: Stored price {$price} for type_id {$typeId} in cache");
        } catch (\Exception $e) {
            Log::warning("OreValuationService: Failed to store price in cache: {$e->getMessage()}");
        }
    }

    /**
     * Check if a type ID is gas (cannot be refined).
     *
     * @param int $typeId
     * @return bool
     */
    protected function isGas(int $typeId): bool
    {
        return TypeIdRegistry::isGas($typeId);
    }

    /**
     * Calculate batch ore values for multiple ledger entries.
     *
     * @param array $entries Array of objects with type_id and quantity properties
     * @param array $options Optional settings override
     * @return array Array of results with same keys as input
     */
    public function calculateBatchOreValues(array $entries, array $options = []): array
    {
        $results = [];

        foreach ($entries as $key => $entry) {
            $results[$key] = $this->calculateOreValue(
                $entry->type_id,
                $entry->quantity,
                $options
            );
        }

        return $results;
    }

    /**
     * Get valuation info for display purposes.
     *
     * @param int $typeId
     * @param int $quantity
     * @param array $options
     * @return array Detailed valuation breakdown
     */
    public function getValuationInfo(int $typeId, int $quantity, array $options = []): array
    {
        $values = $this->calculateOreValue($typeId, $quantity, $options);

        $isGas = $this->isGas($typeId);
        $hasReprocessingData = ReprocessingRegistry::hasReprocessingData($typeId);

        return [
            'type_id' => $typeId,
            'quantity' => $quantity,
            'ore_value' => $values['ore_value'],
            'mineral_value' => $values['mineral_value'],
            'total_value' => $values['total_value'],
            'unit_price' => $values['unit_price'],
            'valuation_method' => $values['valuation_method'],
            'is_gas' => $isGas,
            'can_be_refined' => !$isGas && $hasReprocessingData,
            'using_fallback' => $values['valuation_method'] === 'mineral_price' && (!$hasReprocessingData || $isGas),
        ];
    }
}
