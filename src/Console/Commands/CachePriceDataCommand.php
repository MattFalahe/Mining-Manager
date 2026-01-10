<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Pricing\PriceProviderService;
use MiningManager\Services\Pricing\MarketDataService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Models\MiningPriceCache;
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

                // FIXED: Use correct keys that PriceProviderService expects
                // getCachedPrice will fetch from provider and cache automatically
                $price = $this->marketService->getCachedPrice($typeId, $force);

                if ($price !== null) {
                    // Create price data structure with CORRECT keys
                    $priceData = [
                        'sell' => $price,     // Correct key (not 'sell_price')
                        'buy' => $price,      // Correct key (not 'buy_price')
                        'average' => $price,  // Correct key (not 'average_price')
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

        // Clean up old cache entries
        $this->cleanupOldCache();

        return Command::SUCCESS;
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
