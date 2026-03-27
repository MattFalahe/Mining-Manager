<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Pricing\PriceProviderService;
use MiningManager\Services\Pricing\MarketDataService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Models\MiningPriceCache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CachePriceDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:cache-prices
                            {--type=all : Type to cache (ore|compressed-ore|moon|materials|minerals|ice|ice-products|gas|compressed|all)}
                            {--region=10000002 : Region ID (default: The Forge)}
                            {--force : Force refresh even if cache is fresh}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cache market price data for mining materials';

    /**
     * Price provider service
     *
     * @var PriceProviderService
     */
    protected $priceService;

    /**
     * Market data service
     *
     * @var MarketDataService
     */
    protected $marketService;

    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Create a new command instance.
     *
     * @param PriceProviderService $priceService
     * @param MarketDataService $marketService
     * @param SettingsManagerService $settingsService
     */
    public function __construct(PriceProviderService $priceService, MarketDataService $marketService, SettingsManagerService $settingsService)
    {
        parent::__construct();
        $this->priceService = $priceService;
        $this->marketService = $marketService;
        $this->settingsService = $settingsService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting price cache update...');

        $type = $this->option('type');
        $regionId = $this->option('region');
        $force = $this->option('force');

        $this->info("Caching prices for: {$type}");
        $this->info("Region ID: {$regionId}");

        // Get type IDs to cache
        $typeIds = $this->getTypeIdsForCategory($type);

        if (empty($typeIds)) {
            $this->error("No type IDs found for category: {$type}");
            return Command::FAILURE;
        }

        $this->info("Found " . count($typeIds) . " items to cache");

        // Check if Manager Core is the active provider — use fast DB sync path
        $pricingSettings = $this->settingsService->getPricingSettings();
        $provider = $pricingSettings['price_provider'] ?? 'seat';

        if ($provider === 'manager-core' && PriceProviderService::isManagerCoreInstalled()) {
            $this->syncFromManagerCore($typeIds, $regionId);
        } else {
            $this->fetchFromProvider($typeIds, $regionId, $force);
        }

        // Clean up old cache entries
        $this->cleanupOldCache();

        return Command::SUCCESS;
    }

    /**
     * Sync prices from Manager Core's market_prices table into mining_price_cache
     *
     * Fast DB-to-DB copy — no API calls, no rate limiting needed.
     *
     * @param array $typeIds
     * @param int $regionId
     * @return void
     */
    private function syncFromManagerCore(array $typeIds, int $regionId): void
    {
        $pricingSettings = $this->settingsService->getPricingSettings();
        $market = $pricingSettings['manager_core_market'] ?? 'jita';
        $variant = $pricingSettings['manager_core_variant'] ?? 'min';

        $this->info("Syncing from Manager Core (market: {$market}, variant: {$variant})...");

        // Fetch all matching prices from Manager Core in one query
        $mcPrices = DB::table('manager_core_market_prices')
            ->whereIn('type_id', $typeIds)
            ->where('market', $market)
            ->get()
            ->groupBy('type_id');

        $synced = 0;
        $missing = 0;

        $bar = $this->output->createProgressBar(count($typeIds));
        $bar->start();

        foreach ($typeIds as $typeId) {
            $typePrices = $mcPrices->get($typeId);

            if (!$typePrices || $typePrices->isEmpty()) {
                $missing++;
                $bar->advance();
                continue;
            }

            $sellRow = $typePrices->firstWhere('price_type', 'sell');
            $buyRow = $typePrices->firstWhere('price_type', 'buy');

            // Extract the configured variant price
            $variantField = "price_{$variant}";

            $sellPrice = $sellRow ? (float) ($sellRow->$variantField ?? $sellRow->price_min ?? 0) : 0;
            $buyPrice = $buyRow ? (float) ($buyRow->$variantField ?? $buyRow->price_min ?? 0) : 0;
            $avgPrice = ($sellPrice + $buyPrice) / 2;

            if ($sellPrice > 0 || $buyPrice > 0) {
                $this->priceService->cachePriceData($typeId, $regionId, [
                    'sell' => $sellPrice,
                    'buy' => $buyPrice,
                    'average' => $avgPrice > 0 ? $avgPrice : max($sellPrice, $buyPrice),
                ]);
                $synced++;
            } else {
                $missing++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Manager Core sync complete!");
        $this->info("Synced: {$synced} items");
        if ($missing > 0) {
            $this->warn("Missing in Manager Core: {$missing} items");
        }
    }

    /**
     * Fetch prices from the configured provider (SeAT, Janice, Fuzzwork)
     *
     * @param array $typeIds
     * @param int $regionId
     * @param bool $force
     * @return void
     */
    private function fetchFromProvider(array $typeIds, int $regionId, bool $force): void
    {
        $cached = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar(count($typeIds));
        $bar->start();

        foreach ($typeIds as $typeId) {
            try {
                // Check if cache is still fresh (unless forced)
                if (!$force && $this->priceService->isCacheFresh($typeId, $regionId)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // getCachedPrice will fetch from provider and cache automatically
                $price = $this->marketService->getCachedPrice($typeId, $force);

                if ($price !== null) {
                    $priceData = [
                        'sell' => $price,
                        'buy' => $price,
                        'average' => $price,
                    ];

                    $this->priceService->cachePriceData($typeId, $regionId, $priceData);
                    $cached++;
                } else {
                    $this->newLine();
                    $this->warn("  No price data available for type ID: {$typeId}");
                    $errors++;
                }

                $bar->advance();

                // Rate limiting - sleep briefly between requests
                usleep(100000); // 100ms delay

            } catch (\Exception $e) {
                $this->newLine();
                $this->error("  Error caching type ID {$typeId}: {$e->getMessage()}");
                $errors++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Price cache update complete!");
        $this->info("Cached: {$cached} items");
        if ($skipped > 0) {
            $this->info("Skipped: {$skipped} (cache still fresh)");
        }
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }
    }

    /**
     * Get type IDs for a category
     * Now uses TypeIdRegistry as single source of truth
     *
     * @param string $category
     * @return array
     */
    private function getTypeIdsForCategory(string $category): array
    {
        return TypeIdRegistry::getTypeIdsByCategory($category);
    }

    /**
     * Clean up old cache entries
     * Removes entries older than 7 days
     *
     * @return void
     */
    private function cleanupOldCache()
    {
        $this->info('Cleaning up old cache entries...');
        
        try {
            $cutoffDate = Carbon::now()->subDays(7);
            
            $deleted = MiningPriceCache::where('updated_at', '<', $cutoffDate)->delete();
            
            if ($deleted > 0) {
                $this->info("Removed {$deleted} old cache entries (older than 7 days)");
            } else {
                $this->info("No old cache entries found");
            }
        } catch (\Exception $e) {
            $this->warn("Could not clean up cache: {$e->getMessage()}");
        }
    }
}
