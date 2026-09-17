<?php

namespace MiningManager\Services\Moon;

use Illuminate\Support\Facades\DB;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\ReprocessingRegistry;

/**
 * Values moon ore from the price cache alone.
 *
 * The simulator used to ask the price provider for every ore each time
 * someone pressed Simulate, which with Janice meant a live request per ore,
 * and valuing every scanned moon that way would be thousands of requests.
 * The scheduled price refresh already keeps mining_price_cache current, so
 * everything here reads that and nothing else.
 */
class MoonValuation
{
    /**
     * @var SettingsManagerService
     */
    protected $settings;

    /**
     * @var array<int, bool> ore types already loaded
     */
    protected $prepared = [];

    /**
     * @var array<int, string>
     */
    protected $names = [];

    /**
     * @var array<int, float> m³ per unit
     */
    protected $unitVolumes = [];

    /**
     * @var array<int, float> ISK per unit of ore
     */
    protected $orePrices = [];

    /**
     * @var array<int, float> ISK per unit of ore once reprocessed
     */
    protected $refinedPerUnit = [];

    public function __construct(SettingsManagerService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Load names, volumes, prices and reprocessing yields for these ore types.
     * Types loaded before are skipped, so calling it again is cheap.
     *
     * @param int[] $oreTypeIds
     */
    public function prepare(array $oreTypeIds): void
    {
        $typeIds = array_values(array_diff(
            array_unique(array_map('intval', $oreTypeIds)),
            array_keys($this->prepared)
        ));

        if (empty($typeIds)) {
            return;
        }

        $portionSizes = [];
        foreach ($this->loadTypes($typeIds) as $type) {
            $typeId = (int) $type->typeID;
            $this->names[$typeId] = (string) $type->typeName;
            // The simulator has always assumed 16 m³ when the SDE gives no volume.
            $this->unitVolumes[$typeId] = (float) ($type->volume ?: 16);
            $portionSizes[$typeId] = (int) ($type->portionSize ?: 100);
        }

        $minerals = $this->loadMinerals($typeIds);

        $priceIds = $typeIds;
        foreach ($minerals as $yields) {
            $priceIds = array_merge($priceIds, array_keys($yields));
        }
        $prices = $this->loadPrices(array_values(array_unique(array_map('intval', $priceIds))));
        $efficiency = $this->refiningEfficiency();

        foreach ($typeIds as $typeId) {
            $this->prepared[$typeId] = true;

            if (isset($prices[$typeId])) {
                $this->orePrices[$typeId] = $prices[$typeId];
            }

            // The arithmetic of ReprocessingRegistry::calculateRefinedValue,
            // done once per ore type instead of once per moon.
            $perPortion = 0.0;
            foreach ($minerals[$typeId] ?? [] as $mineralId => $yield) {
                $perPortion += $yield * $efficiency * ($prices[(int) $mineralId] ?? 0.0);
            }
            $this->refinedPerUnit[$typeId] = $perPortion / ($portionSizes[$typeId] ?? 100);
        }
    }

    /**
     * Value one extraction.
     *
     * @param array<int, float> $ores ore type => share of the moon, 0.46 for 46%
     * @param int $days extraction length
     * @return array{share: float, rate: int, volume: int, raw: float, refined: float, ores: array, unpriced: string[]}
     */
    public function value(array $ores, int $days): array
    {
        $this->prepare(array_keys($ores));

        $share = (float) array_sum($ores);
        $volume = MoonChunkModel::volume($share, $days);

        $lines = [];
        $raw = 0.0;
        $refined = 0.0;
        $unpriced = [];

        foreach ($ores as $typeId => $oreShare) {
            $typeId = (int) $typeId;

            // An ore the SDE does not know has no volume to measure it by, and
            // the simulator has always left those out.
            if (!isset($this->unitVolumes[$typeId])) {
                continue;
            }

            $oreVolume = $volume * $oreShare;
            $quantity = floor($oreVolume / $this->unitVolumes[$typeId]);

            if (!isset($this->orePrices[$typeId])) {
                $unpriced[] = $this->names[$typeId];
            }

            $lineValue = $quantity * ($this->orePrices[$typeId] ?? 0.0);
            $lineRefined = $quantity * ($this->refinedPerUnit[$typeId] ?? 0.0);

            $raw += $lineValue;
            $refined += $lineRefined;

            $lines[] = [
                'type_id' => $typeId,
                'ore_name' => $this->names[$typeId],
                'share' => (float) $oreShare,
                'volume' => $oreVolume,
                'quantity' => $quantity,
                'unit_price' => $this->orePrices[$typeId] ?? 0.0,
                'value' => $lineValue,
                'refined_value' => $lineRefined,
            ];
        }

        return [
            'share' => $share,
            'rate' => MoonChunkModel::ratePerHour($share),
            'volume' => $volume,
            'raw' => $raw,
            'refined' => $refined,
            'ores' => $lines,
            'unpriced' => $unpriced,
        ];
    }

    /**
     * Reprocessing yield as a fraction, from the Pricing tab's refining efficiency.
     */
    public function refiningEfficiency(): float
    {
        $value = (float) ($this->settings->getPricingSettings()['refining_efficiency'] ?? 87.5);

        if ($value > 1) {
            $value = $value / 100;
        }

        return max(0.0, min(1.0, $value));
    }

    /**
     * When the price cache was last written, or null when it is empty.
     */
    public function pricesUpdatedAt(): ?string
    {
        $latest = DB::table('mining_price_cache')->max('cached_at');

        return $latest === null ? null : (string) $latest;
    }

    /**
     * Changes whenever anything behind a value changes: the prices, the
     * settings that choose and reprocess them, or the extraction model.
     */
    public function fingerprint(): string
    {
        $pricing = $this->settings->getPricingSettings();

        return md5(json_encode([
            $this->pricesUpdatedAt(),
            $pricing['price_type'] ?? 'sell',
            $this->refiningEfficiency(),
            (int) ($this->settings->getGeneralSettings()['default_region_id'] ?? 10000002),
            MoonChunkModel::ratePerHour(1.0),
            MoonChunkModel::ratePerHour(0.5),
        ]));
    }

    /**
     * @param int[] $typeIds
     * @return iterable<object> rows with typeID, typeName, volume, portionSize
     */
    protected function loadTypes(array $typeIds): iterable
    {
        return DB::table('invTypes')
            ->whereIn('typeID', $typeIds)
            ->get(['typeID', 'typeName', 'volume', 'portionSize']);
    }

    /**
     * @param int[] $typeIds
     * @return array<int, array<int, int>> ore type => [mineral type => quantity per portion]
     */
    protected function loadMinerals(array $typeIds): array
    {
        return ReprocessingRegistry::getBatchMinerals($typeIds);
    }

    /**
     * Cached price per type from the configured price column. A row for the
     * configured region wins; failing that, the latest row from any region, so
     * a region setting that differs from the one the refresh writes still
     * finds a price.
     *
     * @param int[] $typeIds
     * @return array<int, float>
     */
    protected function loadPrices(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $column = match ($this->settings->getPricingSettings()['price_type'] ?? 'sell') {
            'buy' => 'buy_price',
            'average' => 'average_price',
            default => 'sell_price',
        };
        $regionId = (int) ($this->settings->getGeneralSettings()['default_region_id'] ?? 10000002);

        $rows = DB::table('mining_price_cache')
            ->whereIn('type_id', $typeIds)
            ->where($column, '>', 0)
            ->orderBy('cached_at')
            ->get(['type_id', 'region_id', $column . ' as price']);

        $inRegion = [];
        $anyRegion = [];
        foreach ($rows as $row) {
            $typeId = (int) $row->type_id;
            if ((int) $row->region_id === $regionId) {
                $inRegion[$typeId] = (float) $row->price;
            }
            $anyRegion[$typeId] = (float) $row->price;
        }

        return $inRegion + $anyRegion;
    }
}
