<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Pricing\PriceProviderService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Models\Setting;
use MiningManager\Services\Moon\MoonOreHelper;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DiagnosePricesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:diagnose-prices
                            {--detailed : Show detailed breakdown of each category}
                            {--test-provider : Test current price provider}
                            {--show-missing : Show which specific items are missing prices}
                            {--show-sources : Show where prices are coming from (cache vs fallback)}
                            {--show-coverage : Show complete coverage statistics for all 357 items}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose price caching system and identify issues';

    /**
     * Price provider service
     *
     * @var PriceProviderService
     */
    protected $priceService;

    /**
     * Settings service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Create a new command instance.
     */
    public function __construct(PriceProviderService $priceService, SettingsManagerService $settingsService)
    {
        parent::__construct();
        $this->priceService = $priceService;
        $this->settingsService = $settingsService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Mining Manager - Price Caching Diagnostic Report        ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // 1. Check configuration
        $this->checkConfiguration();
        $this->newLine();

        // 2. Check price cache statistics
        $this->checkCacheStatistics();
        $this->newLine();

        // 3. Check SeAT's market_prices table
        $this->checkMarketPrices();
        $this->newLine();

        // 3.5. Check provider health
        $this->checkProviderHealth();
        $this->newLine();

        // 4. Test price provider if requested
        if ($this->option('test-provider')) {
            $this->testPriceProvider();
            $this->newLine();
        }

        // 5. Check for missing critical prices
        $this->checkCriticalPrices();
        $this->newLine();

        // 6. Show detailed breakdown if requested
        if ($this->option('detailed')) {
            $this->showDetailedBreakdown();
            $this->newLine();
        }

        // 7. Show missing items if requested
        if ($this->option('show-missing')) {
            $this->showMissingPrices();
            $this->newLine();
        }

        // 8. Show price sources if requested
        if ($this->option('show-sources')) {
            $this->showPriceSources();
            $this->newLine();
        }

        // 9. Show complete coverage if requested
        if ($this->option('show-coverage')) {
            $this->showCompleteCoverage();
            $this->newLine();
        }

        // 10. Provide recommendations
        $this->provideRecommendations();

        return Command::SUCCESS;
    }

    /**
     * Check configuration settings
     */
    protected function checkConfiguration()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📋 CONFIGURATION');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $pricingSettings = $this->settingsService->getPricingSettings();
        $provider = $pricingSettings['price_provider'] ?? 'not set';

        $this->table(
            ['Setting', 'Value', 'Status'],
            [
                [
                    'Price Provider',
                    $provider,
                    $this->getStatusIcon($provider !== 'not set')
                ],
                [
                    'Price Type',
                    $pricingSettings['price_type'] ?? 'not set',
                    $this->getStatusIcon(isset($pricingSettings['price_type']))
                ],
                [
                    'Cache Duration',
                    ($pricingSettings['cache_duration'] ?? 'not set') . ' minutes',
                    $this->getStatusIcon(isset($pricingSettings['cache_duration']))
                ],
                [
                    'Auto Refresh',
                    $pricingSettings['auto_refresh'] ? 'Enabled' : 'Disabled',
                    $this->getStatusIcon($pricingSettings['auto_refresh'] ?? false)
                ],
                [
                    'Use Refined Value',
                    $pricingSettings['use_refined_value'] ? 'Yes' : 'No',
                    '📊'
                ],
                [
                    'Refining Efficiency',
                    ($pricingSettings['refining_efficiency'] ?? 'not set') . '%',
                    '⚙️'
                ],
            ]
        );

        // Provider-specific settings
        if ($provider === 'janice') {
            $hasApiKey = !empty($pricingSettings['janice_api_key']);
            $this->line('  Janice API Key: ' . ($hasApiKey ? '✅ Configured' : '❌ Not configured'));
            $this->line('  Janice Market: ' . ($pricingSettings['janice_market'] ?? 'not set'));
            $this->line('  Janice Method: ' . ($pricingSettings['janice_price_method'] ?? 'not set'));
        }
    }

    /**
     * Check cache statistics
     */
    protected function checkCacheStatistics()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('💾 PRICE CACHE STATISTICS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $totalCached = MiningPriceCache::count();
        $withPrices = MiningPriceCache::where('sell_price', '>', 0)->count();
        $withoutPrices = $totalCached - $withPrices;

        // Get cache freshness
        $cacheDuration = config('mining-manager.pricing.cache_duration', 60);
        $cutoff = Carbon::now()->subMinutes($cacheDuration);
        $fresh = MiningPriceCache::where('cached_at', '>', $cutoff)->count();
        $stale = $totalCached - $fresh;

        // Get oldest and newest
        $oldest = MiningPriceCache::orderBy('cached_at', 'asc')->first();
        $newest = MiningPriceCache::orderBy('cached_at', 'desc')->first();

        $this->table(
            ['Metric', 'Count', 'Status'],
            [
                ['Total Items Cached', $totalCached, $this->getStatusIcon($totalCached > 0)],
                ['Items With Prices', $withPrices, $this->getStatusIcon($withPrices > 0)],
                ['Items Without Prices', $withoutPrices, $this->getStatusIcon($withoutPrices === 0)],
                ['Fresh Cache Entries', $fresh, $this->getStatusIcon($fresh > 0)],
                ['Stale Cache Entries', $stale, $stale > 0 ? '⚠️' : '✅'],
            ]
        );

        if ($oldest) {
            $this->line('  Oldest Entry: ' . $oldest->cached_at->diffForHumans());
        }
        if ($newest) {
            $this->line('  Newest Entry: ' . $newest->cached_at->diffForHumans());
        }
    }

    /**
     * Check SeAT's market_prices table
     */
    protected function checkMarketPrices()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🏪 SEAT MARKET PRICES (Fallback)');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        try {
            $totalMarketPrices = DB::table('market_prices')->count();
            $latestUpdate = DB::table('market_prices')->max('updated_at');

            $this->table(
                ['Metric', 'Value', 'Status'],
                [
                    ['Total Market Prices', number_format($totalMarketPrices), $this->getStatusIcon($totalMarketPrices > 0)],
                    ['Last Updated', $latestUpdate ? Carbon::parse($latestUpdate)->diffForHumans() : 'Never', $latestUpdate ? '✅' : '❌'],
                ]
            );

            if ($totalMarketPrices === 0) {
                $this->warn('  ⚠️  SeAT market_prices table is empty!');
                $this->line('  Run: php artisan esi:update:prices');
            } elseif ($latestUpdate && Carbon::parse($latestUpdate)->diffInDays() > 1) {
                $this->warn('  ⚠️  Market prices are outdated (> 1 day old)');
                $this->line('  Run: php artisan esi:update:prices');
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Could not access market_prices table: ' . $e->getMessage());
        }
    }

    /**
     * Test price provider
     */
    protected function testPriceProvider()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔬 PRICE PROVIDER TEST');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $testTypeIds = [
            34 => 'Tritanium',
            35 => 'Pyerite',
            36 => 'Mexallon',
            16633 => 'Hydrocarbons (R4)',
            45506 => 'Bitumens (Moon Ore)',
        ];

        $this->line('  Testing price provider with sample items...');
        $this->newLine();

        $results = [];
        foreach ($testTypeIds as $typeId => $name) {
            try {
                $price = $this->priceService->getPrice($typeId);
                $results[] = [
                    $name,
                    $typeId,
                    $price ? number_format($price, 2) . ' ISK' : 'No price',
                    $price > 0 ? '✅' : '❌',
                ];
            } catch (\Exception $e) {
                $results[] = [
                    $name,
                    $typeId,
                    'Error: ' . $e->getMessage(),
                    '❌',
                ];
            }
        }

        $this->table(['Item', 'Type ID', 'Price', 'Status'], $results);
    }

    /**
     * Check critical prices for refined value calculations
     */
    protected function checkCriticalPrices()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('⚠️  CRITICAL PRICES CHECK');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $criticalTypes = [
            'Minerals' => [34, 35, 36, 37, 38, 39, 40, 11399],
            'R4 Materials' => [16633, 16635, 16636, 16638],
            'R8 Materials' => [16634, 16637, 16639, 16655],
            'R16 Materials' => [16640, 16641, 16644, 16647],
            'R32 Materials' => [16642, 16643, 16646, 16648],
            'R64 Materials' => [16649, 16650, 16651, 16652],
            'Ice Products' => [16272, 16274, 17889, 16273, 17888, 17887, 16275], // CORRECTED!
            'Compressed Ore (Sample)' => [28432, 28433, 28434, 28435], // NEW!
        ];

        $categoryResults = [];
        foreach ($criticalTypes as $category => $typeIds) {
            // Check in cache
            $cachedCount = MiningPriceCache::whereIn('type_id', $typeIds)
                ->where('sell_price', '>', 0)
                ->count();

            // Check in market_prices
            $marketCount = DB::table('market_prices')
                ->whereIn('type_id', $typeIds)
                ->where('average_price', '>', 0)
                ->count();

            $total = count($typeIds);
            $cachePercent = round(($cachedCount / $total) * 100);
            $marketPercent = round(($marketCount / $total) * 100);

            $status = '✅';
            if ($cachedCount === 0 && $marketCount === 0) {
                $status = '❌ CRITICAL';
            } elseif ($cachedCount === 0) {
                $status = '⚠️  Fallback Only';
            } elseif ($cachedCount < $total) {
                $status = '⚠️  Partial';
            }

            $categoryResults[] = [
                $category,
                "{$cachedCount}/{$total} ({$cachePercent}%)",
                "{$marketCount}/{$total} ({$marketPercent}%)",
                $status,
            ];
        }

        $this->table(
            ['Category', 'In Cache', 'In Market Prices', 'Status'],
            $categoryResults
        );
    }

    /**
     * Show detailed breakdown
     */
    protected function showDetailedBreakdown()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 DETAILED BREAKDOWN');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Sample from each category in cache
        $this->line('  <fg=cyan>Sample Cached Prices:</>');
        $this->newLine();

        $samples = MiningPriceCache::where('sell_price', '>', 0)
            ->orderBy('type_id')
            ->limit(10)
            ->get();

        if ($samples->isEmpty()) {
            $this->warn('  No cached prices found!');
        } else {
            $sampleData = [];
            foreach ($samples as $sample) {
                $sampleData[] = [
                    $sample->type_id,
                    number_format($sample->sell_price, 2),
                    number_format($sample->buy_price, 2),
                    number_format($sample->average_price, 2),
                    $sample->cached_at->diffForHumans(),
                ];
            }

            $this->table(
                ['Type ID', 'Sell', 'Buy', 'Average', 'Cached'],
                $sampleData
            );
        }
    }

    /**
     * Show missing prices
     */
    protected function showMissingPrices()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('❌ MISSING PRICES');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $criticalTypeIds = array_merge(
            [34, 35, 36, 37, 38, 39, 40, 11399], // Minerals
            [16633, 16635, 16636, 16638], // R4
            [16634, 16637, 16639, 16655], // R8
            [16640, 16641, 16644, 16647], // R16
            [16642, 16643, 16646, 16648], // R32
            [16649, 16650, 16651, 16652]  // R64
        );

        $cached = MiningPriceCache::whereIn('type_id', $criticalTypeIds)
            ->pluck('type_id')
            ->toArray();

        $missing = array_diff($criticalTypeIds, $cached);

        if (empty($missing)) {
            $this->info('  ✅ All critical prices are cached!');
        } else {
            $this->warn('  ⚠️  Missing ' . count($missing) . ' critical type IDs:');
            $this->line('  ' . implode(', ', $missing));
            $this->newLine();
            $this->line('  Run: php artisan mining-manager:cache-prices --type=all --force');
        }
    }

    /**
     * Show where prices are coming from (cache vs fallback)
     */
    protected function showPriceSources()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔍 PRICE SOURCES (Cache vs Fallback)');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Sample critical items to check
        $criticalItems = [
            // Minerals
            ['id' => 34, 'name' => 'Tritanium', 'category' => 'Mineral'],
            ['id' => 35, 'name' => 'Pyerite', 'category' => 'Mineral'],
            ['id' => 36, 'name' => 'Mexallon', 'category' => 'Mineral'],
            
            // R4 Materials
            ['id' => 16633, 'name' => 'Hydrocarbons', 'category' => 'R4'],
            ['id' => 16635, 'name' => 'Evaporite Deposits', 'category' => 'R4'],
            
            // R8 Materials
            ['id' => 16634, 'name' => 'Titanium', 'category' => 'R8'],
            ['id' => 16637, 'name' => 'Tungsten', 'category' => 'R8'],
            
            // R16 Materials
            ['id' => 16640, 'name' => 'Cadmium', 'category' => 'R16'],
            ['id' => 16641, 'name' => 'Chromium', 'category' => 'R16'],
            
            // R32 Materials
            ['id' => 16642, 'name' => 'Caesium', 'category' => 'R32'],
            ['id' => 16643, 'name' => 'Technetium', 'category' => 'R32'],
            
            // R64 Materials
            ['id' => 16649, 'name' => 'Neodymium', 'category' => 'R64'],
            ['id' => 16650, 'name' => 'Dysprosium', 'category' => 'R64'],
            
            // Sample Moon Ores
            ['id' => 45506, 'name' => 'Bitumens', 'category' => 'Moon Ore (R4)'],
            ['id' => 45491, 'name' => 'Xenotime', 'category' => 'Moon Ore (R64)'],
            
            // Ice Products (CORRECTED!)
            ['id' => 16272, 'name' => 'Heavy Water', 'category' => 'Ice Product'],
            ['id' => 16273, 'name' => 'Liquid Ozone', 'category' => 'Ice Product'],
            ['id' => 16275, 'name' => 'Strontium Clathrates', 'category' => 'Ice Product'],
            
            // Compressed Ores (NEW!)
            ['id' => 28432, 'name' => 'Compressed Veldspar', 'category' => 'Compressed Ore'],
            ['id' => 28445, 'name' => 'Compressed Arkonor', 'category' => 'Compressed Ore'],
            
            // Ice Raw
            ['id' => 16264, 'name' => 'Blue Ice', 'category' => 'Ice'],
            ['id' => 17977, 'name' => 'Compressed Blue Ice', 'category' => 'Compressed Ice'],
            
            // Gas (CORRECTED!)
            ['id' => 30370, 'name' => 'Fullerite-C50', 'category' => 'Gas'],
            ['id' => 25276, 'name' => 'Malachite Cytoserocin', 'category' => 'Booster Gas'],
        ];

        $results = [];
        $cacheCount = 0;
        $fallbackCount = 0;
        $noPriceCount = 0;

        foreach ($criticalItems as $item) {
            $typeId = $item['id'];
            
            // Check cache
            $cached = MiningPriceCache::where('type_id', $typeId)
                ->where('sell_price', '>', 0)
                ->latest('cached_at')
                ->first();
            
            // Check market_prices
            $market = DB::table('market_prices')
                ->where('type_id', $typeId)
                ->first();
            
            $source = '❌ None';
            $price = 'N/A';
            $age = 'N/A';
            
            if ($cached && $cached->sell_price > 0) {
                $source = '✅ Cache';
                $price = number_format($cached->sell_price, 2);
                $age = $cached->cached_at->diffForHumans();
                $cacheCount++;
            } elseif ($market && $market->average_price > 0) {
                $source = '⚠️  Fallback';
                $price = number_format($market->average_price, 2);
                $age = 'Market data';
                $fallbackCount++;
            } else {
                $noPriceCount++;
            }
            
            $results[] = [
                $item['name'],
                $item['category'],
                $typeId,
                $price . ' ISK',
                $source,
                $age,
            ];
        }

        $this->table(
            ['Item', 'Category', 'Type ID', 'Price', 'Source', 'Age'],
            $results
        );

        // Summary
        $total = count($criticalItems);
        $this->newLine();
        $this->line("  <fg=cyan>Summary:</>");
        $this->line("  ✅ From Cache: {$cacheCount}/{$total} (" . round(($cacheCount/$total)*100) . "%)");
        $this->line("  ⚠️  From Fallback: {$fallbackCount}/{$total} (" . round(($fallbackCount/$total)*100) . "%)");
        
        if ($noPriceCount > 0) {
            $this->line("  ❌ No Price: {$noPriceCount}/{$total} (" . round(($noPriceCount/$total)*100) . "%)");
        }
        
        $this->newLine();
        
        if ($fallbackCount > ($total * 0.5)) {
            $this->warn("  ⚠️  WARNING: More than 50% of prices are using fallback!");
            $this->line("  This means your price provider (Janice/Fuzzwork) may be failing.");
            $this->line("  Run: php artisan mining-manager:cache-prices --type=all --force");
        } elseif ($fallbackCount > 0) {
            $this->line("  <fg=yellow>ℹ  Some prices using fallback - this is normal for rarely-traded items.</>");
        } else {
            $this->info("  ✅ All prices from cache - price provider working perfectly!");
        }
    }

    /**
     * Check price provider health by analyzing cache vs fallback ratio
     */
    protected function checkProviderHealth()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🏥 PRICE PROVIDER HEALTH');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $provider = Setting::getValue('price_provider', 'seat');
        
        // Count items in cache with valid prices
        $cacheWithPrices = MiningPriceCache::where('sell_price', '>', 0)->count();
        $cacheWithoutPrices = MiningPriceCache::where('sell_price', '=', 0)->count();
        $totalCache = MiningPriceCache::count();
        
        // Calculate success rate
        $successRate = $totalCache > 0 ? round(($cacheWithPrices / $totalCache) * 100, 1) : 0;
        
        // Determine health status
        $status = '✅ Healthy';
        $color = 'green';
        
        if ($successRate < 50) {
            $status = '❌ Critical';
            $color = 'red';
        } elseif ($successRate < 80) {
            $status = '⚠️  Degraded';
            $color = 'yellow';
        }
        
        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Active Provider', $provider, '📡'],
                ['Cache Success Rate', $successRate . '%', $status],
                ['Items Cached Successfully', $cacheWithPrices, $this->getStatusIcon($cacheWithPrices > 0)],
                ['Items Failed to Cache', $cacheWithoutPrices, $cacheWithoutPrices > 0 ? '⚠️' : '✅'],
            ]
        );
        
        // Provider-specific diagnostics
        if ($provider === 'janice') {
            $apiKey = Setting::getValue('janice_api_key');
            $hasKey = !empty($apiKey);
            
            $this->line("\n  <fg=cyan>Janice Provider Status:</>");
            $this->line("  API Key: " . ($hasKey ? '✅ Configured' : '❌ Not configured'));
            
            if (!$hasKey) {
                $this->warn("  ⚠️  Janice API key is missing!");
                $this->line("  Configure it in Settings → Pricing");
            } elseif ($cacheWithoutPrices > 10) {
                $this->warn("  ⚠️  {$cacheWithoutPrices} items failed to get prices from Janice");
                $this->line("  Possible reasons:");
                $this->line("    - API key invalid or expired");
                $this->line("    - Rate limiting");
                $this->line("    - Network issues");
                $this->line("  Fallback to market_prices is active");
            }
        } elseif ($provider === 'fuzzwork') {
            $this->line("\n  <fg=cyan>Fuzzwork Provider Status:</>");
            
            if ($cacheWithoutPrices > 10) {
                $this->warn("  ⚠️  {$cacheWithoutPrices} items failed to get prices from Fuzzwork");
                $this->line("  Possible reasons:");
                $this->line("    - Fuzzwork API temporarily down");
                $this->line("    - Network issues");
                $this->line("  Fallback to market_prices is active");
            } else {
                $this->info("  ✅ Fuzzwork provider working normally");
            }
        } elseif ($provider === 'seat') {
            $this->line("\n  <fg=cyan>SeAT Provider Status:</>");
            $this->info("  ✅ Using SeAT's built-in market_prices table");
            $this->line("  No external API calls required");
        }
        
        // Recommendations based on health
        if ($successRate < 80 && $provider !== 'seat') {
            $this->newLine();
            $this->warn("  💡 Recommendation: Consider switching to 'seat' provider temporarily");
            $this->line("  Or run: php artisan mining-manager:cache-prices --type=all --force");
        }
    }

    /**
     * Show complete coverage statistics for all item types
     */
    /**
     * Show complete coverage statistics for all items
     * UPDATED: Now tracks all 357 items (was 197)
     */
    protected function showCompleteCoverage()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 COMPLETE COVERAGE REPORT (ALL 357 ITEMS)');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // Define all categories with type IDs
        $categories = [
            // RAW ORES (Ore Value Taxation)
            'Regular Ores' => [
                'type_ids' => [
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
                ],
                'total' => 45,
                'purpose' => 'Ore value taxation',
            ],
            'Compressed Ores' => [
                'type_ids' => [
                    // Base compressed ores (15 types)
                    28432, 28433, 28434, 28435, 28436, 28437, 28438, 28439, 28440, 28441,
                    28442, 28443, 28444, 28445, 28446,
                    // Compressed ore variants (30 types)
                    28427, 28428, 28429, 28430, 28421, 28422, 28415, 28416, 28425, 28426,
                    28419, 28420, 28417, 28418, 28413, 28414, 28409, 28410, 28411, 28412,
                    28407, 28408, 28405, 28406, 28401, 28404, 28397, 28400, 28398, 28399,
                ],
                'total' => 45,
                'purpose' => 'Hauler ore taxation',
            ],
            'Moon Ores (All Variants)' => [
                'type_ids' => [
                    // R4 (Ubiquitous) - 12 items (base + improved + jackpot)
                    45492, 46284, 46285,  // Bitumens family
                    45493, 46286, 46287,  // Coesite family
                    45491, 46282, 46283,  // Sylvite family
                    45490, 46280, 46281,  // Zeolites family
                    
                    // R8 (Common) - 12 items
                    45494, 46288, 46289,  // Cobaltite family
                    45495, 46290, 46291,  // Euxenite family
                    45497, 46294, 46295,  // Scheelite family
                    45496, 46292, 46293,  // Titanite family
                    
                    // R16 (Uncommon) - 12 items
                    45501, 46302, 46303,  // Chromite family
                    45498, 46296, 46297,  // Otavite family
                    45499, 46298, 46299,  // Sperrylite family
                    45500, 46300, 46301,  // Vanadinite family
                    
                    // R32 (Rare) - 12 items
                    45502, 46304, 46305,  // Carnotite family
                    45506, 46310, 46311,  // Cinnabar family
                    45504, 46308, 46309,  // Pollucite family
                    45503, 46306, 46307,  // Zircon family
                    
                    // R64 (Exceptional) - 12 items
                    45510, 46312, 46313,  // Xenotime family
                    45511, 46314, 46315,  // Monazite family
                    45512, 46316, 46317,  // Loparite family
                    45513, 46318, 46319,  // Ytterbite family
                ],
                'total' => 60,
                'purpose' => 'Moon ore taxation (all variants)',
            ],
            'Compressed Moon Ores (All Variants)' => [
                'type_ids' => [
                    // R4 (Ubiquitous) Compressed - 12 items
                    62454, 62455, 62456,  // Compressed Bitumens family
                    62457, 62458, 62459,  // Compressed Coesite family
                    62460, 62461, 62466,  // Compressed Sylvite family
                    62463, 62464, 62467,  // Compressed Zeolites family
                    
                    // R8 (Common) Compressed - 12 items
                    62474, 62475, 62476,  // Compressed Cobaltite family
                    62471, 62472, 62473,  // Compressed Euxenite family
                    62468, 62469, 62470,  // Compressed Scheelite family
                    62477, 62478, 62479,  // Compressed Titanite family
                    
                    // R16 (Uncommon) Compressed - 12 items
                    62480, 62481, 62482,  // Compressed Chromite family
                    62483, 62484, 62485,  // Compressed Otavite family
                    62486, 62487, 62488,  // Compressed Sperrylite family
                    62489, 62490, 62491,  // Compressed Vanadinite family
                    
                    // R32 (Rare) Compressed - 12 items
                    62492, 62493, 62494,  // Compressed Carnotite family
                    62495, 62496, 62497,  // Compressed Cinnabar family
                    62498, 62499, 62500,  // Compressed Pollucite family
                    62501, 62502, 62503,  // Compressed Zircon family
                    
                    // R64 (Exceptional) Compressed - 12 items
                    62510, 62511, 62512,  // Compressed Xenotime family
                    62507, 62508, 62509,  // Compressed Monazite family
                    62504, 62505, 62506,  // Compressed Loparite family
                    62513, 62514, 62515,  // Compressed Ytterbite family
                ],
                'total' => 60,
                'purpose' => 'Compressed moon ore taxation',
            ],
            '💎 Jackpot Moon Ores (+100%)' => [
                'type_ids' => MoonOreHelper::getAllJackpotTypeIds(),
                'total' => 40,
                'purpose' => '⭐ Jackpot extraction detection',
            ],
            'Ice (Raw + Compressed)' => [
                'type_ids' => [
                    // Standard Ice (8 types)
                    16262, 16263, 16264, 16265, 16266, 16267, 16268, 16269,
                    // Compressed Ice (8 types)
                    17975, 17976, 17977, 17978, 17979, 17980, 17981, 17982,
                ],
                'total' => 16,
                'purpose' => 'Ice value taxation',
            ],
            'Gas' => [
                'type_ids' => [
                    // Fullerites (C-X) - 8 types
                    30370, 30371, 30372, 30373, 30374, 30375, 30377, 30378,
                    // Booster Gases - 4 types
                    25276, 25278, 25274, 25268,
                ],
                'total' => 12,
                'purpose' => 'Gas value taxation',
            ],
            
            // REFINED MATERIALS (Refined Value Taxation)
            'Minerals' => [
                'type_ids' => [34, 35, 36, 37, 38, 39, 40, 11399],
                'total' => 8,
                'purpose' => 'Refined ore value',
            ],
            'Moon Materials' => [
                'type_ids' => [
                    // R4 Materials
                    16633, 16635, 16636, 16638,
                    // R8 Materials
                    16634, 16637, 16639, 16655,
                    // R16 Materials
                    16640, 16641, 16644, 16647,
                    // R32 Materials
                    16642, 16643, 16646, 16648,
                    // R64 Materials
                    16649, 16650, 16651, 16652,
                ],
                'total' => 24,
                'purpose' => 'Refined moon value',
            ],
            'Ice Products' => [
                'type_ids' => [16272, 16274, 17889, 16273, 17888, 17887, 16275],
                'total' => 7,
                'purpose' => '✨ Refined ice value',
            ],
        ];

        $results = [];
        $totalExpected = 0;
        $totalCached = 0;
        $totalWithPrices = 0;

        foreach ($categories as $category => $data) {
            $typeIds = $data['type_ids'];
            $expectedTotal = $data['total'];
            $totalExpected += $expectedTotal;
            
            // Count cached items
            $cached = MiningPriceCache::whereIn('type_id', $typeIds)->count();
            $totalCached += $cached;
            
            // Count items with prices
            $withPrices = MiningPriceCache::whereIn('type_id', $typeIds)
                ->where('sell_price', '>', 0)
                ->count();
            $totalWithPrices += $withPrices;
            
            // Calculate percentage
            $percentage = $expectedTotal > 0 ? round(($withPrices / $expectedTotal) * 100) : 0;
            
            // Determine status
            if ($percentage == 100) {
                $status = '✅';
            } elseif ($percentage >= 80) {
                $status = '⚠️';
            } else {
                $status = '❌';
            }
            
            $results[] = [
                $category,
                "{$withPrices}/{$expectedTotal}",
                "{$percentage}%",
                $status,
                $data['purpose'],
            ];
        }

        $this->table(
            ['Category', 'Cached', '%', 'Status', 'Purpose'],
            $results
        );

        // Grand total
        $this->newLine();
        $overallPercentage = $totalExpected > 0 ? round(($totalWithPrices / $totalExpected) * 100, 1) : 0;
        
        $this->line("  <fg=cyan>GRAND TOTAL:</>");
        $this->line("  Expected Items: {$totalExpected}");
        $this->line("  Cached Items: {$totalCached}");
        $this->line("  Items With Prices: {$totalWithPrices}");
        $this->line("  Coverage: {$overallPercentage}% " . ($overallPercentage >= 90 ? '✅' : ($overallPercentage >= 75 ? '⚠️' : '❌')));
        
        $this->newLine();
        
        if ($overallPercentage < 90) {
            $missing = $totalExpected - $totalWithPrices;
            $this->warn("  ⚠️  Missing {$missing} items from complete coverage!");
            $this->line("  Run: php artisan mining-manager:cache-prices --type=all --force");
        } else {
            $this->info("  ✅ Excellent coverage! All taxation models supported!");
        }
        
        // Show what's enabled
        $this->newLine();
        $this->line("  <fg=cyan>Taxation Models Supported:</>");
        
        $oreValueReady = true;
        $refinedValueReady = true;
        $jackpotDetectionReady = true;
        
        // Check ore value taxation readiness
        foreach (['Regular Ores', 'Moon Ores (All Variants)', 'Ice (Raw + Compressed)', 'Gas'] as $cat) {
            foreach ($results as $result) {
                if ($result[0] == $cat && str_replace('%', '', $result[2]) < 80) {
                    $oreValueReady = false;
                }
            }
        }
        
        // Check refined value taxation readiness
        foreach (['Minerals', 'Moon Materials', 'Ice Products'] as $cat) {
            foreach ($results as $result) {
                if ($result[0] == $cat && str_replace('%', '', $result[2]) < 80) {
                    $refinedValueReady = false;
                }
            }
        }
        
        // Check jackpot detection readiness
        foreach ($results as $result) {
            if ($result[0] == '💎 Jackpot Moon Ores (+100%)' && str_replace('%', '', $result[2]) < 80) {
                $jackpotDetectionReady = false;
            }
        }
        
        $this->line("  Model 1 (Ore Value): " . ($oreValueReady ? '✅ Ready' : '❌ Incomplete'));
        $this->line("  Model 2 (Refined Value): " . ($refinedValueReady ? '✅ Ready' : '❌ Incomplete'));
        $this->line("  💎 Jackpot Detection: " . ($jackpotDetectionReady ? '✅ Ready' : '❌ Incomplete'));
        
        $this->newLine();
        $this->line("  <fg=yellow>📊 COVERAGE BREAKDOWN:</>");
        $this->line("  - Regular Ores: 45 items (base + variants)");
        $this->line("  - Compressed Ores: 45 items");
        $this->line("  - Moon Ores: 60 items (base + improved + jackpot)");
        $this->line("  - Compressed Moon: 60 items (base + improved + jackpot)");
        $this->line("  - Jackpot Variants: 40 items (tracked separately)");
        $this->line("  - Ice: 16 items");
        $this->line("  - Gas: 12 items");
        $this->line("  - Minerals: 8 items");
        $this->line("  - Moon Materials: 24 items");
        $this->line("  - Ice Products: 7 items");
        $this->line("  ───────────────────────");
        $this->line("  <fg=green>TOTAL: 357 ITEMS TRACKED!</>");
    }

    /**
     * Provide recommendations
     */
    protected function provideRecommendations()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('💡 RECOMMENDATIONS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $recommendations = [];

        // Check total cache
        $totalCached = MiningPriceCache::count();
        if ($totalCached === 0) {
            $recommendations[] = [
                '❌',
                'No prices cached',
                'php artisan mining-manager:cache-prices --type=all --force',
            ];
        }

        // Check for prices without values
        $emptyPrices = MiningPriceCache::where('sell_price', 0)->count();
        if ($emptyPrices > 0) {
            $recommendations[] = [
                '⚠️',
                "{$emptyPrices} cached items have 0 price",
                'Check price provider configuration or use --force to refresh',
            ];
        }

        // Check cache freshness
        $cacheDuration = config('mining-manager.pricing.cache_duration', 60);
        $cutoff = Carbon::now()->subMinutes($cacheDuration);
        $stale = MiningPriceCache::where('cached_at', '<', $cutoff)->count();
        if ($stale > 10) {
            $recommendations[] = [
                '⚠️',
                "{$stale} cached prices are stale",
                'php artisan mining-manager:cache-prices --type=all',
            ];
        }

        // Check critical minerals
        $minerals = MiningPriceCache::whereIn('type_id', [35, 36])
            ->where('sell_price', '>', 0)
            ->count();
        if ($minerals < 2) {
            $recommendations[] = [
                '❌',
                'Missing mineral prices (Pyerite, Mexallon)',
                'php artisan mining-manager:cache-prices --type=minerals --force',
            ];
        }

        // Check moon materials
        $moonMats = MiningPriceCache::whereIn('type_id', [16633, 16635, 16636, 16638])
            ->where('sell_price', '>', 0)
            ->count();
        if ($moonMats < 4) {
            $recommendations[] = [
                '❌',
                'Missing R4 moon material prices',
                'php artisan mining-manager:cache-prices --type=materials --force',
            ];
        }

        // Check SeAT market prices
        $marketPrices = DB::table('market_prices')->count();
        if ($marketPrices === 0) {
            $recommendations[] = [
                '❌',
                'SeAT market_prices table is empty',
                'php artisan esi:update:prices',
            ];
        }

        if (empty($recommendations)) {
            $this->info('  ✅ Everything looks good! No issues found.');
        } else {
            $this->table(
                ['Status', 'Issue', 'Solution'],
                $recommendations
            );
        }

        $this->newLine();
        $this->line('  <fg=cyan>Tip:</> Run with --detailed for more information');
        $this->line('  <fg=cyan>Tip:</> Run with --test-provider to test price fetching');
        $this->line('  <fg=cyan>Tip:</> Run with --show-missing to see missing type IDs');
        $this->line('  <fg=cyan>Tip:</> Run with --show-sources to see cache vs fallback usage');
        $this->line('  <fg=cyan>Tip:</> Run with --show-coverage to see all 357 items coverage');
    }

    /**
     * Get status icon
     */
    protected function getStatusIcon(bool $isGood): string
    {
        return $isGood ? '✅' : '❌';
    }
}
