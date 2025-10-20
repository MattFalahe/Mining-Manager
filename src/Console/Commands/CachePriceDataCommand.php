<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Pricing\PriceProviderService;
use MiningManager\Services\Pricing\MarketDataService;
use Carbon\Carbon;

class CachePriceDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:cache-prices
                            {--type=all : Type to cache (ore|minerals|ice|gas|all)}
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
     * Create a new command instance.
     *
     * @param PriceProviderService $priceService
     * @param MarketDataService $marketService
     */
    public function __construct(PriceProviderService $priceService, MarketDataService $marketService)
    {
        parent::__construct();
        $this->priceService = $priceService;
        $this->marketService = $marketService;
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

                // Fetch and cache price data
                $priceData = $this->marketService->fetchMarketPrice($typeId, $regionId);

                if ($priceData) {
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

        // Clean up old cache entries
        $this->cleanupOldCache();

        return Command::SUCCESS;
    }

    /**
     * Get type IDs for a category
     *
     * @param string $category
     * @return array
     */
    private function getTypeIdsForCategory(string $category): array
    {
        // Common ore type IDs
        $oreTypes = [
            // Veldspar family
            1230, 17470, 17471,
            // Scordite family
            1228, 17463, 17464,
            // Pyroxeres family
            1224, 17459, 17460,
            // Plagioclase family
            18, 17455, 17456,
            // Omber family
            1227, 17867, 17868,
            // Kernite family
            20, 17452, 17453,
            // Jaspet family
            1226, 17448, 17449,
            // Hemorphite family
            1231, 17444, 17445,
            // Hedbergite family
            21, 17440, 17441,
            // Gneiss family
            1229, 17865, 17866,
            // Dark Ochre family
            1232, 17436, 17437,
            // Crokite family
            1225, 17432, 17433,
            // Bistot family
            1223, 17428, 17429,
            // Arkonor family
            22, 17425, 17426,
            // Mercoxit family
            11396, 17869, 17870,
        ];

        $mineralTypes = [
            34, 35, 36, 37, 38, 39, 40, // Tritanium through Megacyte
            11399, // Morphite
        ];

        $iceTypes = [
            16262, 16263, 16264, 16265, 16266, 16267, 16268, 16269, // Various ice types
            17975, 17976, 17977, 17978, // Enriched ice
        ];

        $gasTypes = [
            25268, 25269, 25270, 25271, 25272, 25273, 25274, 25275, // Fullerites
            25276, 25277, 25278, 25279, 25280, // Booster gases
        ];

        switch ($category) {
            case 'ore':
                return $oreTypes;
            case 'minerals':
                return $mineralTypes;
            case 'ice':
                return $iceTypes;
            case 'gas':
                return $gasTypes;
            case 'all':
                return array_merge($oreTypes, $mineralTypes, $iceTypes, $gasTypes);
            default:
                return [];
        }
    }

    /**
     * Clean up old cache entries
     *
     * @return void
     */
    private function cleanupOldCache(): void
    {
        $this->info("Cleaning up old cache entries...");
        
        $cutoffDate = Carbon::now()->subDays(7);
        $deleted = $this->priceService->deleteOldCache($cutoffDate);

        if ($deleted > 0) {
            $this->info("Deleted {$deleted} old cache entries");
        }
    }
}
