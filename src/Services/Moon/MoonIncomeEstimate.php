<?php

namespace MiningManager\Services\Moon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Ledger\LedgerSummaryService;

/**
 * What a moon would earn the corporation over one extraction period, worked
 * out two ways: a refinery whose chunk the members mine and pay tax on, or a
 * Metenox Moon Drill that keeps everything it drills. Both less fuel.
 *
 * The refinery side charges what an invoice would, through the same methods
 * the daily summaries use: the tax selector and the members' rates of the
 * Moon Owner Corporation. Only part of a chunk is ever mined, so the share
 * mined is the caller's to choose; the default is what this moon's last
 * chunks actually saw.
 */
class MoonIncomeEstimate
{
    /**
     * Archived chunks on the moon that set its usual mined share.
     */
    public const MOON_CHUNKS = 6;

    /**
     * Archived chunks across the corporation, for a moon with none of its own.
     */
    public const CORPORATION_CHUNKS = 20;

    /**
     * @var SettingsManagerService
     */
    protected $settings;

    /**
     * @var MoonValuation
     */
    protected $valuation;

    /**
     * @var LedgerSummaryService
     */
    protected $summaries;

    public function __construct(SettingsManagerService $settings, MoonValuation $valuation, LedgerSummaryService $summaries)
    {
        $this->settings = $settings;
        $this->valuation = $valuation;
        $this->summaries = $summaries;
    }

    /**
     * Both sides for a simulation, over the simulation's own days.
     *
     * @param array $simulation what MoonExtractionService::simulateExtraction() returned
     */
    public function forSimulation(array $simulation): array
    {
        $moonId = (int) ($simulation['moon_id'] ?? 0);
        $days = (int) ($simulation['extraction_days'] ?? 14);
        $hours = $days * 24;
        $hull = (int) ($simulation['rig']['hull'] ?? RefineryService::ATHANOR);

        // Read the way the daily summaries read it, not from whichever
        // corporation happens to be active.
        $moonOwner = $this->settings->getSettingForCorporation('general.moon_owner_corporation_id', null);
        $moonOwner = $moonOwner ? (int) $moonOwner : null;

        $previous = $this->settings->getActiveCorporation();
        if ($moonOwner) {
            $this->settings->setActiveCorporation($moonOwner);
        }
        try {
            // The same test OreValuationService makes when a ledger row is
            // valued: minerals, or else the ore.
            $basis = ($this->settings->getGeneralSettings()['ore_valuation_method'] ?? 'mineral_price') === 'mineral_price'
                ? 'refined'
                : 'ore';
            $refinery = $this->refineryTax($simulation['composition'] ?? [], $basis, $moonOwner);
        } finally {
            $this->settings->setActiveCorporation($previous);
        }

        $metenox = $this->valuation->metenox($this->moonOres($moonId), $days);
        $bayM3 = 0.0;
        foreach ($metenox['materials'] as $material) {
            $bayM3 += $material['quantity'] * MetenoxCargoService::MOON_MATERIAL_VOLUME_M3;
        }

        $refineryFuel = $this->fuel($hull, ['moon_drill'], $hours);
        $metenoxFuel = $this->fuel(MetenoxCargoService::METENOX_TYPE_ID, ['metenox_moon_drill'], $hours);

        return [
            'days' => $days,
            'tax_basis' => $basis,
            'moon_owner_set' => $moonOwner !== null,
            'mined_share' => $this->usualMinedShare($moonId, $moonOwner),
            'refinery' => $refinery + [
                'hull' => $hull,
                'fuel' => $this->fuelLine($refineryFuel),
            ],
            'metenox' => [
                'value' => $metenox['value'],
                'materials' => $metenox['materials'],
                'unpriced' => $metenox['unpriced'],
                'bay_m3' => $bayM3,
                'bay_capacity_m3' => MetenoxCargoService::MOON_MATERIAL_BAY_CAPACITY_M3,
                'bay_full_days' => $bayM3 > 0
                    ? MetenoxCargoService::MOON_MATERIAL_BAY_CAPACITY_M3 / ($bayM3 / $days)
                    : null,
                'fuel' => $this->fuelLine($metenoxFuel),
            ],
            'fuel' => $this->fuelStatus($refineryFuel, $metenoxFuel),
        ];
    }

    /**
     * Tax on the whole chunk, line by line, at the members' rates. The caller
     * scales it by the share mined.
     *
     * Chunk mining is only ever taxed from the Moon Owner Corporation's own
     * refineries, so with none set nothing here is taxed, which is what the
     * invoices would say too.
     *
     * @param array $composition the simulation's ore lines
     * @return array{value: float, tax: float, lines: array}
     */
    protected function refineryTax(array $composition, string $basis, ?int $moonOwner): array
    {
        $lines = [];
        $value = 0.0;
        $tax = 0.0;

        foreach ($composition as $line) {
            $typeId = (int) ($line['type_id'] ?? 0);
            $lineValue = (float) ($basis === 'refined' ? ($line['refined_value'] ?? 0) : ($line['value'] ?? 0));

            $rate = $moonOwner !== null && $this->summaries->shouldTaxType($typeId, $moonOwner, $moonOwner)
                ? $this->summaries->getTaxRateForType($typeId)
                : 0.0;
            $lineTax = $lineValue * $rate / 100;

            $value += $lineValue;
            $tax += $lineTax;
            $lines[] = [
                'type_id' => $typeId,
                'ore_name' => (string) ($line['ore_name'] ?? ''),
                'rarity' => $line['rarity'] ?? null,
                'value' => $lineValue,
                'rate' => $rate,
                'tax' => $lineTax,
            ];
        }

        return ['value' => $value, 'tax' => $tax, 'lines' => $lines];
    }

    /**
     * How much of a chunk usually gets mined, as a fraction. This moon's last
     * archived chunks first, then the corporation's, and all of it when there
     * is no history to go on. Cancelled chunks never had anything to mine and
     * are left out; a chunk nobody mined counts, since that is exactly what a
     * refinery risks.
     *
     * @return array{share: float, source: string, chunks: int}
     */
    public function usualMinedShare(int $moonId, ?int $corporationId): array
    {
        try {
            if (Schema::hasTable('moon_extraction_history')) {
                $moon = DB::table('moon_extraction_history')
                    ->where('moon_id', $moonId)
                    ->where('final_status', '<>', 'cancelled')
                    ->orderByDesc('chunk_arrival_time')
                    ->limit(self::MOON_CHUNKS)
                    ->pluck('completion_percentage');
                if ($moon->isNotEmpty()) {
                    return $this->averageShare($moon->all(), 'moon');
                }

                $query = DB::table('moon_extraction_history')
                    ->where('final_status', '<>', 'cancelled');
                if ($corporationId) {
                    $query->where('corporation_id', $corporationId);
                }
                $corporation = $query->orderByDesc('chunk_arrival_time')
                    ->limit(self::CORPORATION_CHUNKS)
                    ->pluck('completion_percentage');
                if ($corporation->isNotEmpty()) {
                    return $this->averageShare($corporation->all(), 'corporation');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not read the extraction history for the income estimate', [
                'moon_id' => $moonId,
                'error' => $e->getMessage(),
            ]);
        }

        return ['share' => 1.0, 'source' => 'none', 'chunks' => 0];
    }

    /**
     * @param array<int, float|string|null> $percentages
     */
    protected function averageShare(array $percentages, string $source): array
    {
        $values = array_map(fn ($p) => max(0.0, min(100.0, (float) $p)), $percentages);

        return [
            'share' => array_sum($values) / count($values) / 100,
            'source' => $source,
            'chunks' => count($values),
        ];
    }

    /**
     * @return array<int, float> ore type => share of the moon
     */
    protected function moonOres(int $moonId): array
    {
        return DB::table('universe_moon_contents')
            ->where('moon_id', $moonId)
            ->pluck('rate', 'type_id')
            ->map(fn ($rate) => (float) $rate)
            ->all();
    }

    /**
     * Fuel for a structure over the period, priced by Structure Manager.
     * Null when Manager Core, or a Structure Manager new enough to answer,
     * is not installed. Fuel rates and prices are Structure Manager's to
     * keep, so nothing here guesses at them.
     *
     * @param string[] $modules Structure Manager's service module names
     */
    protected function fuel(int $structureTypeId, array $modules, int $hours): ?array
    {
        if (!class_exists('ManagerCore\\Services\\PluginBridge')) {
            return null;
        }

        try {
            $bridge = app(\ManagerCore\Services\PluginBridge::class);
            if (!$bridge->hasCapability('structure-manager', 'fuel.estimate')) {
                return null;
            }

            $result = $bridge->call('structure-manager', 'fuel.estimate', $structureTypeId, $modules, $hours);

            return is_array($result) ? $result : null;
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: Structure Manager could not estimate fuel', [
                'structure_type_id' => $structureTypeId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array{blocks: int, magmatic_gas: int, block_name: ?string, isk: ?float}|null
     */
    protected function fuelLine(?array $fuel): ?array
    {
        if ($fuel === null) {
            return null;
        }

        return [
            'blocks' => (int) ($fuel['blocks'] ?? 0),
            'magmatic_gas' => (int) ($fuel['magmatic_gas'] ?? 0),
            'block_name' => $fuel['fuel_block']['name'] ?? null,
            'isk' => isset($fuel['isk']) ? (float) $fuel['isk'] : null,
        ];
    }

    /**
     * Whether the fuel is in ISK, and if not, why.
     *
     * @return array{status: string, missing: string[], market: ?string, price_type: ?string}
     */
    protected function fuelStatus(?array $refinery, ?array $metenox): array
    {
        $status = [
            'status' => 'priced',
            'missing' => [],
            'market' => $refinery['pricing']['market'] ?? null,
            'price_type' => $refinery['pricing']['price_type'] ?? null,
        ];

        if ($refinery === null || $metenox === null) {
            $status['status'] = 'unavailable';
        } elseif (empty($refinery['pricing']['enabled'])) {
            $status['status'] = 'disabled';
        } elseif (!isset($refinery['isk'], $metenox['isk'])) {
            $status['status'] = 'unpriced';
            $status['missing'] = array_values(array_unique(array_merge(
                (array) ($refinery['missing_prices'] ?? []),
                (array) ($metenox['missing_prices'] ?? [])
            )));
        }

        return $status;
    }
}
