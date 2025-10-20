<?php

namespace MiningManager\Services\Moon;

use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MiningPriceCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MoonValueCalculationService
{
    /**
     * Calculate the estimated ISK value of a moon extraction.
     *
     * @param MoonExtraction $extraction
     * @return float|null
     */
    public function calculateExtractionValue(MoonExtraction $extraction): ?float
    {
        if (!$extraction->ore_composition) {
            Log::debug("Mining Manager: No ore composition data for extraction {$extraction->id}");
            return null;
        }

        $cacheKey = "mining-manager:moon-value:{$extraction->id}";
        $cacheDuration = config('mining-manager.pricing.cache_duration', 60);

        return Cache::remember($cacheKey, now()->addMinutes($cacheDuration), function () use ($extraction) {
            return $this->calculateValue($extraction->ore_composition);
        });
    }

    /**
     * Calculate value from ore composition array.
     *
     * @param array $oreComposition
     * @return float
     */
    private function calculateValue(array $oreComposition): float
    {
        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');
        $useRefinedValue = config('mining-manager.pricing.use_refined_value', false);

        $totalValue = 0;

        foreach ($oreComposition as $typeId => $quantity) {
            $price = $this->getOrePrice($typeId, $regionId, $priceType);

            if ($price === null) {
                Log::warning("Mining Manager: No price data for moon ore type_id {$typeId}");
                continue;
            }

            if ($useRefinedValue) {
                $price = $this->calculateRefinedValue($typeId, $price);
            }

            $totalValue += $quantity * $price;
        }

        return round($totalValue, 2);
    }

    /**
     * Get ore price from cache.
     *
     * @param int $typeId
     * @param int $regionId
     * @param string $priceType
     * @return float|null
     */
    private function getOrePrice(int $typeId, int $regionId, string $priceType): ?float
    {
        $priceCache = MiningPriceCache::where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->latest('cached_at')
            ->first();

        if (!$priceCache) {
            return null;
        }

        // Check if cache is fresh
        $cacheDuration = config('mining-manager.pricing.cache_duration', 60);
        if ($priceCache->cached_at->addMinutes($cacheDuration)->isPast()) {
            Log::debug("Mining Manager: Price cache expired for type_id {$typeId}");
            return null;
        }

        return match ($priceType) {
            'buy' => $priceCache->buy_price,
            'average' => $priceCache->average_price,
            default => $priceCache->sell_price,
        };
    }

    /**
     * Calculate refined mineral value from ore.
     *
     * @param int $typeId
     * @param float $basePrice
     * @return float
     */
    private function calculateRefinedValue(int $typeId, float $basePrice): float
    {
        $refiningEfficiency = config('mining-manager.pricing.refining_efficiency', 87.5) / 100;

        // Get mineral yields for this ore type
        $mineralYields = $this->getMineralYields($typeId);

        if (empty($mineralYields)) {
            // Fallback to base price with efficiency modifier
            return $basePrice * $refiningEfficiency;
        }

        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');

        $refinedValue = 0;

        foreach ($mineralYields as $mineralTypeId => $yieldAmount) {
            $mineralPrice = $this->getOrePrice($mineralTypeId, $regionId, $priceType);
            
            if ($mineralPrice) {
                $refinedValue += ($yieldAmount * $refiningEfficiency) * $mineralPrice;
            }
        }

        return $refinedValue;
    }

    /**
     * Get mineral yields for an ore type.
     *
     * @param int $typeId
     * @return array
     */
    private function getMineralYields(int $typeId): array
    {
        // This is a simplified version
        // In reality, you'd query the EVE SDE database for actual mineral yields
        // For moon ores, this would be the R-type ores and their mineral compositions

        // Example moon ore mineral yields (simplified)
        $moonOreYields = [
            // Bitumens (R4)
            45506 => [ // Bitumens
                34 => 1, // Tritanium (small amount for all R4s)
                // Add actual mineral yields here
            ],
            // Add more moon ore types and their yields
        ];

        return $moonOreYields[$typeId] ?? [];
    }

    /**
     * Compare extraction values over time.
     *
     * @param int $structureId
     * @param int $numExtractions
     * @return array
     */
    public function compareExtractionValues(int $structureId, int $numExtractions = 10): array
    {
        $extractions = MoonExtraction::where('structure_id', $structureId)
            ->whereNotNull('ore_composition')
            ->whereNotNull('estimated_value')
            ->orderBy('extraction_start_time', 'desc')
            ->limit($numExtractions)
            ->get();

        if ($extractions->isEmpty()) {
            return [
                'average_value' => 0,
                'min_value' => 0,
                'max_value' => 0,
                'value_variance' => 0,
                'extractions' => [],
            ];
        }

        $values = $extractions->pluck('estimated_value');
        $average = $values->avg();
        $min = $values->min();
        $max = $values->max();

        // Calculate variance
        $variance = $values->map(function ($value) use ($average) {
            return pow($value - $average, 2);
        })->avg();

        return [
            'average_value' => round($average, 2),
            'min_value' => $min,
            'max_value' => $max,
            'value_variance' => round($variance, 2),
            'standard_deviation' => round(sqrt($variance), 2),
            'coefficient_of_variation' => $average > 0 ? round((sqrt($variance) / $average) * 100, 2) : 0,
            'extractions' => $extractions->map(fn($e) => [
                'extraction_start_time' => $e->extraction_start_time,
                'value' => $e->estimated_value,
            ])->toArray(),
        ];
    }

    /**
     * Get ore composition breakdown with values.
     *
     * @param MoonExtraction $extraction
     * @return array
     */
    public function getOreCompositionBreakdown(MoonExtraction $extraction): array
    {
        if (!$extraction->ore_composition) {
            return [];
        }

        $regionId = config('mining-manager.pricing.default_region_id', 10000002);
        $priceType = config('mining-manager.pricing.price_type', 'sell');

        $breakdown = [];
        $totalValue = 0;

        foreach ($extraction->ore_composition as $typeId => $quantity) {
            $price = $this->getOrePrice($typeId, $regionId, $priceType);
            $value = $price ? $quantity * $price : 0;
            $totalValue += $value;

            // Get ore name from universe types
            $oreName = $this->getOreName($typeId);

            $breakdown[] = [
                'type_id' => $typeId,
                'ore_name' => $oreName,
                'quantity' => $quantity,
                'unit_price' => $price,
                'total_value' => round($value, 2),
            ];
        }

        // Sort by value descending
        usort($breakdown, fn($a, $b) => $b['total_value'] <=> $a['total_value']);

        // Add percentage of total
        foreach ($breakdown as &$item) {
            $item['percentage_of_total'] = $totalValue > 0 
                ? round(($item['total_value'] / $totalValue) * 100, 2) 
                : 0;
        }

        return [
            'total_value' => round($totalValue, 2),
            'ore_types' => $breakdown,
        ];
    }

    /**
     * Get ore name from type ID.
     *
     * @param int $typeId
     * @return string
     */
    private function getOreName(int $typeId): string
    {
        // Query the universe_types table
        $type = \DB::table('universe_types')
            ->where('type_id', $typeId)
            ->first();

        return $type ? $type->typeName : "Type {$typeId}";
    }

    /**
     * Calculate value per cubic meter.
     *
     * @param MoonExtraction $extraction
     * @return float|null
     */
    public function calculateValuePerM3(MoonExtraction $extraction): ?float
    {
        $totalValue = $this->calculateExtractionValue($extraction);
        
        if ($totalValue === null || !$extraction->ore_composition) {
            return null;
        }

        $totalVolume = 0;

        foreach ($extraction->ore_composition as $typeId => $quantity) {
            $volume = $this->getOreVolume($typeId);
            $totalVolume += $quantity * $volume;
        }

        return $totalVolume > 0 ? round($totalValue / $totalVolume, 2) : null;
    }

    /**
     * Get ore volume per unit.
     *
     * @param int $typeId
     * @return float
     */
    private function getOreVolume(int $typeId): float
    {
        // Query the universe_types table for volume
        $type = \DB::table('universe_types')
            ->where('type_id', $typeId)
            ->first();

        return $type ? (float) $type->volume : 0;
    }

    /**
     * Estimate mining time for extraction.
     *
     * @param MoonExtraction $extraction
     * @param int $numMiners
     * @param float $miningRatePerHour Average m3 per hour per miner
     * @return array
     */
    public function estimateMiningTime(MoonExtraction $extraction, int $numMiners = 10, float $miningRatePerHour = 50000): array
    {
        if (!$extraction->ore_composition) {
            return [
                'total_volume' => 0,
                'estimated_hours' => 0,
                'estimated_hours_per_miner' => 0,
            ];
        }

        $totalVolume = 0;

        foreach ($extraction->ore_composition as $typeId => $quantity) {
            $volume = $this->getOreVolume($typeId);
            $totalVolume += $quantity * $volume;
        }

        $totalMiningRate = $miningRatePerHour * $numMiners;
        $estimatedHours = $totalMiningRate > 0 ? $totalVolume / $totalMiningRate : 0;
        $estimatedHoursPerMiner = $miningRatePerHour > 0 ? $totalVolume / $miningRatePerHour : 0;

        return [
            'total_volume' => round($totalVolume, 2),
            'estimated_hours' => round($estimatedHours, 2),
            'estimated_hours_per_miner' => round($estimatedHoursPerMiner, 2),
            'num_miners' => $numMiners,
            'mining_rate_per_hour' => $miningRatePerHour,
        ];
    }

    /**
     * Get high-value moon extractions.
     *
     * @param int $limit
     * @param int $days
     * @return \Illuminate\Support\Collection
     */
    public function getHighValueExtractions(int $limit = 10, int $days = 30)
    {
        $startDate = now()->subDays($days);

        return MoonExtraction::with(['structure', 'moon'])
            ->whereNotNull('estimated_value')
            ->where('extraction_start_time', '>=', $startDate)
            ->orderByDesc('estimated_value')
            ->limit($limit)
            ->get()
            ->map(function ($extraction) {
                return [
                    'id' => $extraction->id,
                    'structure_name' => $extraction->structure->name ?? 'Unknown',
                    'moon_name' => $extraction->moon->name ?? 'Unknown',
                    'estimated_value' => $extraction->estimated_value,
                    'status' => $extraction->status,
                    'chunk_arrival_time' => $extraction->chunk_arrival_time,
                    'value_per_m3' => $this->calculateValuePerM3($extraction),
                ];
            });
    }

    /**
     * Calculate total moon mining income for corporation.
     *
     * @param int $corporationId
     * @param int $days
     * @return array
     */
    public function calculateMoonIncome(int $corporationId, int $days = 30): array
    {
        $startDate = now()->subDays($days);

        $extractions = MoonExtraction::where('corporation_id', $corporationId)
            ->whereNotNull('estimated_value')
            ->whereIn('status', ['fractured', 'expired'])
            ->where('natural_decay_time', '>=', $startDate)
            ->get();

        $totalValue = $extractions->sum('estimated_value');
        $averagePerExtraction = $extractions->count() > 0 
            ? $totalValue / $extractions->count() 
            : 0;

        // Estimate daily income
        $dailyIncome = $days > 0 ? $totalValue / $days : 0;

        return [
            'total_value' => round($totalValue, 2),
            'num_extractions' => $extractions->count(),
            'average_per_extraction' => round($averagePerExtraction, 2),
            'daily_income' => round($dailyIncome, 2),
            'monthly_income_projection' => round($dailyIncome * 30, 2),
            'period_days' => $days,
        ];
    }

    /**
     * Compare moon profitability.
     *
     * @param int $corporationId
     * @return array
     */
    public function compareMoonProfitability(int $corporationId): array
    {
        $extractions = MoonExtraction::where('corporation_id', $corporationId)
            ->whereNotNull('estimated_value')
            ->whereNotNull('moon_id')
            ->with('moon')
            ->get();

        $moonStats = $extractions->groupBy('moon_id')
            ->map(function ($moonExtractions, $moonId) {
                $totalValue = $moonExtractions->sum('estimated_value');
                $count = $moonExtractions->count();
                $averageValue = $count > 0 ? $totalValue / $count : 0;

                return [
                    'moon_id' => $moonId,
                    'moon_name' => $moonExtractions->first()->moon->name ?? "Moon {$moonId}",
                    'total_extractions' => $count,
                    'total_value' => round($totalValue, 2),
                    'average_value' => round($averageValue, 2),
                ];
            })
            ->sortByDesc('average_value')
            ->values();

        return [
            'moon_statistics' => $moonStats->toArray(),
            'most_profitable' => $moonStats->first(),
            'least_profitable' => $moonStats->last(),
        ];
    }

    /**
     * Clear value calculation cache.
     *
     * @param int|null $extractionId
     * @return void
     */
    public function clearValueCache(?int $extractionId = null): void
    {
        if ($extractionId) {
            Cache::forget("mining-manager:moon-value:{$extractionId}");
        } else {
            // Clear all moon value caches
            // This is implementation-specific based on your cache driver
            Cache::tags(['mining-manager', 'moon-values'])->flush();
        }
    }
}
