<?php

namespace MiningManager\Services\Pricing;

use MiningManager\Services\ReprocessingRegistry;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Models\MiningPriceCache;
use Illuminate\Support\Facades\Log;

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
 */
class OreValuationService
{
    /**
     * Price provider service
     *
     * @var PriceProviderService
     */
    protected $priceProvider;

    /**
     * Constructor
     *
     * @param PriceProviderService $priceProvider
     */
    public function __construct(PriceProviderService $priceProvider)
    {
        $this->priceProvider = $priceProvider;
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
     * Get ore price from cache.
     *
     * @param int $typeId
     * @param int $regionId
     * @param string $priceType
     * @return float|null
     */
    protected function getOrePrice(int $typeId, int $regionId, string $priceType): ?float
    {
        $priceCache = MiningPriceCache::where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->latest('cached_at')
            ->first();

        if (!$priceCache) {
            Log::debug("OreValuationService: No price cache for type_id {$typeId}");
            return null;
        }

        return match ($priceType) {
            'buy' => $priceCache->buy_price,
            'average' => $priceCache->average_price,
            default => $priceCache->sell_price,
        };
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
