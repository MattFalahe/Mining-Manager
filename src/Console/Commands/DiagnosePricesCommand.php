<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Pricing\PriceProviderService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Models\Setting;
use MiningManager\Services\Moon\MoonOreHelper;
use Illuminate\Support\Facades\DB;
use MiningManager\Services\TypeIdRegistry;
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
                            {--show-coverage : Show complete coverage statistics for every tracked type}';

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
     * @var array|null
     */
    protected $health = null;

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

        $health = $this->cacheHealth();

        $this->table(
            ['Metric', 'Count', 'Status'],
            [
                ['Total Items Cached', $health['total'], $this->getStatusIcon($health['total'] > 0)],
                ['Items With a Price', $health['priced'], $this->getStatusIcon($health['priced'] > 0)],
                ['Fresh (within ' . $health['cache_duration_minutes'] . ' min)', $health['fresh'], $this->getStatusIcon($health['fresh'] > 0)],
                ['Keeping an Older Price', $health['keeping_older'], 'ℹ️'],
                ['No Market Price', $health['no_market'], 'ℹ️'],
            ]
        );

        $this->line('  Last price written: ' . ($health['last_written']
            ? $health['last_written'] . ' (' . Carbon::parse($health['last_written'])->diffForHumans() . ')'
            : 'never'));
        $this->line('  A type that comes back empty keeps its last good price, and one with no market has none.');
        $this->line('  Neither is a fault.');
    }

    /**
     * The cache judged the same way the Diagnostics page judges it, worked
     * out once per run.
     */
    protected function cacheHealth(): array
    {
        return $this->health ??= $this->priceService->cacheHealth();
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
            'Minerals' => TypeIdRegistry::MINERALS,
            'R4 Materials' => TypeIdRegistry::MOON_MATERIALS_R4,
            'R8 Materials' => TypeIdRegistry::MOON_MATERIALS_R8,
            'R16 Materials' => TypeIdRegistry::MOON_MATERIALS_R16,
            'R32 Materials' => TypeIdRegistry::MOON_MATERIALS_R32,
            'R64 Materials' => TypeIdRegistry::MOON_MATERIALS_R64,
            'Ice Products' => TypeIdRegistry::ICE_PRODUCTS,
            'Compressed Ore (Sample)' => array_slice(TypeIdRegistry::COMPRESSED_REGULAR_ORES, 0, 4),
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
     * Check price provider health from what the refreshes recorded. Counting
     * rows without a price says nothing about the provider: those are types
     * with no market, and a failed lookup never writes a row at all.
     */
    protected function checkProviderHealth()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🏥 PRICE PROVIDER HEALTH');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $pricingSettings = $this->settingsService->getPricingSettings();
        $provider = $pricingSettings['price_provider'] ?? 'seat';
        $health = $this->cacheHealth();
        $status = $health['provider'];

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Active Provider', $provider, '📡'],
                [
                    'Refreshes',
                    $health['provider_failing'] ? 'Failing since ' . ($status['since'] ?? 'the last run') : 'Working',
                    $this->getStatusIcon(!$health['provider_failing']),
                ],
                ['Last Successful Fetch', $status['last_success'] ?? 'not recorded yet', $status['last_success'] ? '✅' : 'ℹ️'],
                [
                    'Last Price Written',
                    $health['last_written'] ?? 'never',
                    $health['last_written'] === null ? '❌' : ($health['refresh_overdue'] ? '⚠️' : '✅'),
                ],
            ]
        );

        foreach ($health['reasons'] as $reason) {
            $this->warn('  ⚠️  ' . $reason);
        }

        // Provider-specific diagnostics
        if ($provider === 'janice') {
            $hasKey = !empty($pricingSettings['janice_api_key'] ?? '');

            $this->line("\n  <fg=cyan>Janice Provider Status:</>");
            $this->line("  API Key: " . ($hasKey ? '✅ Configured' : '❌ Not configured'));

            if (!$hasKey) {
                $this->warn("  ⚠️  Janice API key is missing!");
                $this->line("  Configure it in Settings → Pricing");
            }
        } elseif ($provider === 'seat') {
            $this->line("\n  <fg=cyan>SeAT Provider Status:</>");
            $this->info("  ✅ Using SeAT's built-in market_prices table");
            $this->line("  No external API calls required");
        }

        if ($health['provider_failing'] && $provider !== 'seat') {
            $this->newLine();
            $this->warn("  💡 Cached prices stay as they are until refreshes work again.");
            $this->line("  Check the provider settings under Settings → Pricing, then run with --test-provider.");
        }
    }

    /**
     * Show complete coverage statistics for all item types
     */
    /**
     * Show complete coverage statistics for all items
     * Counts come from TypeIdRegistry rather than being restated here, so
     * the report cannot drift from the registry the way it used to.
     */
    protected function showCompleteCoverage()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 COMPLETE COVERAGE REPORT');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // Define all categories with type IDs
        $categories = [
            // RAW ORES (Ore Value Taxation)
            'Regular Ores' => [
                'type_ids' => TypeIdRegistry::REGULAR_ORES,
                'purpose' => 'Ore value taxation',
            ],
            'Compressed Ores' => [
                'type_ids' => TypeIdRegistry::COMPRESSED_REGULAR_ORES,
                'purpose' => 'Hauler ore taxation',
            ],
            'Moon Ores (All Variants)' => [
                'type_ids' => TypeIdRegistry::MOON_ORES,
                'purpose' => 'Moon ore taxation (all variants)',
            ],
            'Compressed Moon Ores (All Variants)' => [
                'type_ids' => TypeIdRegistry::COMPRESSED_MOON_ORES,
                'purpose' => 'Compressed moon ore taxation',
            ],
            // NOTE: Jackpot ores are already included in moon ore counts above
            // They are tracked for detection purposes but not counted separately
            'Ice (Raw + Compressed)' => [
                'type_ids' => TypeIdRegistry::getAllIce(),
                'purpose' => 'Ice value taxation',
            ],
            'Gas' => [
                'type_ids' => TypeIdRegistry::getAllGas(),
                'purpose' => 'Gas value taxation',
            ],
            
            // REFINED MATERIALS (Refined Value Taxation)
            'Minerals' => [
                'type_ids' => TypeIdRegistry::MINERALS,
                'purpose' => 'Refined ore value',
            ],
            'Moon Materials' => [
                'type_ids' => TypeIdRegistry::getAllMoonMaterials(),
                'purpose' => 'Refined moon value',
            ],
            'Ice Products' => [
                'type_ids' => TypeIdRegistry::ICE_PRODUCTS,
                'purpose' => '✨ Refined ice value',
            ],
        ];

        $results = [];
        $totalExpected = 0;
        $totalCached = 0;
        $totalWithPrices = 0;

        foreach ($categories as $category => $data) {
            $typeIds = $data['type_ids'];
            // Derive the denominator from the registry so the two can never drift.
            $expectedTotal = count(array_unique($typeIds));
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
        
        // Check jackpot detection readiness - jackpots are part of moon ores
        // Just check if moon ores have good coverage
        foreach (['Moon Ores (All Variants)', 'Compressed Moon Ores (All Variants)'] as $cat) {
            foreach ($results as $result) {
                if ($result[0] == $cat && str_replace('%', '', $result[2]) < 80) {
                    $jackpotDetectionReady = false;
                }
            }
        }
        
        $this->line("  Model 1 (Ore Value): " . ($oreValueReady ? '✅ Ready' : '❌ Incomplete'));
        $this->line("  Model 2 (Refined Value): " . ($refinedValueReady ? '✅ Ready' : '❌ Incomplete'));
        $this->line("  💎 Jackpot Detection: " . ($jackpotDetectionReady ? '✅ Ready' : '❌ Incomplete'));
        
        $this->newLine();
        // Counted off the registry, like the table above. These numbers used to
        // be typed in by hand and had drifted a long way from reality.
        $breakdown = [
            'Regular Ores'     => TypeIdRegistry::REGULAR_ORES,
            'Compressed Ores'  => TypeIdRegistry::COMPRESSED_REGULAR_ORES,
            'Moon Ores'        => TypeIdRegistry::MOON_ORES,
            'Compressed Moon'  => TypeIdRegistry::COMPRESSED_MOON_ORES,
            'Ice'              => TypeIdRegistry::getAllIce(),
            'Gas'              => TypeIdRegistry::getAllGas(),
            'Minerals'         => TypeIdRegistry::MINERALS,
            'Moon Materials'   => TypeIdRegistry::getAllMoonMaterials(),
            'Ice Products'     => TypeIdRegistry::ICE_PRODUCTS,
        ];

        $this->line("  <fg=yellow>📊 COVERAGE BREAKDOWN:</>");

        $everything = [];
        foreach ($breakdown as $label => $ids) {
            $this->line("  - {$label}: " . count(array_unique($ids)) . " items");
            $everything = array_merge($everything, $ids);
        }

        $this->line("  ───────────────────────");
        $this->line("  <fg=green>TOTAL: " . count(array_unique($everything)) . " UNIQUE ITEMS</>");
        $this->newLine();
        $this->line("  <fg=cyan>Note:</> Jackpot ores (40 items) are included in moon ore counts above");
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

        // Rows without a price, or keeping an older one, are not faults, so
        // they are not raised here. What is: the provider failing, or the
        // refresh no longer writing anything.
        $health = $this->cacheHealth();
        if ($health['provider_failing']) {
            $recommendations[] = [
                '⚠️',
                'Price refreshes are failing',
                'Check the provider settings under Settings → Pricing',
            ];
        }
        if ($health['refresh_overdue']) {
            $recommendations[] = [
                '⚠️',
                'No price written for ' . intdiv((int) $health['minutes_since_write'], 60) . ' hours',
                'php artisan mining-manager:cache-prices, then check its schedule is running',
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
        $this->line('  <fg=cyan>Tip:</> Run with --show-coverage to see full coverage');
    }

    /**
     * Get status icon
     */
    protected function getStatusIcon(bool $isGood): string
    {
        return $isGood ? '✅' : '❌';
    }
}
