<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use MiningManager\Services\Pricing\PriceProviderService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Models\MiningPriceCache;

class DiagnosticController extends Controller
{
    /**
     * Display diagnostic page
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // Get counts of existing test data
        $testDataCounts = [
            'corporations' => DB::table('corporation_infos')
                ->where('name', 'like', 'Test Corp%')
                ->count(),
            'characters' => DB::table('character_infos')
                ->where('name', 'like', 'Test Miner%')
                ->count(),
            'mining_ledger' => DB::table('mining_ledger')
                ->whereIn('character_id', function($query) {
                    $query->select('character_id')
                        ->from('character_infos')
                        ->where('name', 'like', 'Test Miner%');
                })
                ->count(),
            'mining_taxes' => DB::table('mining_taxes')
                ->whereIn('character_id', function($query) {
                    $query->select('character_id')
                        ->from('character_infos')
                        ->where('name', 'like', 'Test Miner%');
                })
                ->count(),
        ];

        return view('mining-manager::diagnostic.index', compact('testDataCounts'));
    }

    /**
     * Generate test corporations
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function generateTestCorporations(Request $request)
    {
        $count = $request->input('count', 3);

        try {
            DB::beginTransaction();

            $corporations = [];
            for ($i = 1; $i <= $count; $i++) {
                $corpId = 98000000 + $i;
                $ceoId = 90000000 + $i; // CEO character ID
                $creatorId = 90000000 + $i; // Creator character ID (same as CEO)
                $ticker = 'TST' . str_pad($i, 2, '0', STR_PAD_LEFT);

                DB::table('corporation_infos')->updateOrInsert(
                    ['corporation_id' => $corpId],
                    [
                        'name' => "Test Corp {$i}",
                        'ticker' => $ticker,
                        'ceo_id' => $ceoId,
                        'creator_id' => $creatorId,
                        'member_count' => rand(10, 100),
                        'tax_rate' => 0.1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $corporations[] = [
                    'id' => $corpId,
                    'name' => "Test Corp {$i}",
                    'ticker' => $ticker,
                ];
            }

            DB::commit();

            return redirect()->route('mining-manager.diagnostic.index')
                ->with('success', "Generated {$count} test corporations successfully!")
                ->with('generated_data', $corporations);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error generating test corporations', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error generating test corporations: ' . $e->getMessage());
        }
    }

    /**
     * Generate test characters
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function generateTestCharacters(Request $request)
    {
        $charactersPerCorp = $request->input('characters_per_corp', 5);

        try {
            DB::beginTransaction();

            // Get test corporations
            $corporations = DB::table('corporation_infos')
                ->where('name', 'like', 'Test Corp%')
                ->get();

            if ($corporations->isEmpty()) {
                DB::rollBack();
                return redirect()->back()->with('error', 'No test corporations found. Generate corporations first.');
            }

            $characters = [];
            $charIdCounter = 90000000;

            foreach ($corporations as $corp) {
                for ($i = 1; $i <= $charactersPerCorp; $i++) {
                    $charId = $charIdCounter++;
                    $charName = "Test Miner {$corp->ticker}-{$i}";

                    // Check if character_affiliations table exists for corporation linkage
                    DB::table('character_infos')->updateOrInsert(
                        ['character_id' => $charId],
                        [
                            'name' => $charName,
                            'gender' => rand(0, 1) ? 'male' : 'female', // Random gender
                            'race_id' => [1, 2, 4, 8][rand(0, 3)], // Random EVE race (Caldari, Minmatar, Amarr, Gallente)
                            'bloodline_id' => rand(1, 15), // Random bloodline
                            'security_status' => rand(-10, 10) / 10,
                            'birthday' => Carbon::now()->subYears(rand(1, 10)),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    // Insert into character_affiliations if it exists
                    try {
                        DB::table('character_affiliations')->updateOrInsert(
                            ['character_id' => $charId],
                            [
                                'character_id' => $charId,
                                'corporation_id' => $corp->corporation_id,
                                'faction_id' => null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    } catch (\Exception $e) {
                        // Table might not exist, continue
                    }

                    $characters[] = [
                        'id' => $charId,
                        'name' => $charName,
                        'corporation' => $corp->name,
                    ];
                }
            }

            DB::commit();

            return redirect()->route('mining-manager.diagnostic.index')
                ->with('success', "Generated " . count($characters) . " test characters successfully!")
                ->with('generated_data', $characters);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error generating test characters', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error generating test characters: ' . $e->getMessage());
        }
    }

    /**
     * Generate test mining data
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function generateTestMiningData(Request $request)
    {
        $daysOfData = $request->input('days', 30);
        $entriesPerDay = $request->input('entries_per_day', 10);

        try {
            DB::beginTransaction();

            // Get test characters
            $characters = DB::table('character_infos')
                ->where('name', 'like', 'Test Miner%')
                ->get();

            if ($characters->isEmpty()) {
                DB::rollBack();
                return redirect()->back()->with('error', 'No test characters found. Generate characters first.');
            }

            // Define ore types with their IDs and categories
            $oreTypes = [
                // Moon Ores (R64 - Exceptional)
                ['id' => 45506, 'name' => 'Xenotime', 'rarity' => 'r64', 'is_moon_ore' => true],
                ['id' => 46676, 'name' => 'Monazite', 'rarity' => 'r64', 'is_moon_ore' => true],

                // Moon Ores (R32 - Rare)
                ['id' => 45492, 'name' => 'Chromite', 'rarity' => 'r32', 'is_moon_ore' => true],
                ['id' => 46678, 'name' => 'Platinum', 'rarity' => 'r32', 'is_moon_ore' => true],

                // Moon Ores (R16 - Uncommon)
                ['id' => 45494, 'name' => 'Cobaltite', 'rarity' => 'r16', 'is_moon_ore' => true],
                ['id' => 46680, 'name' => 'Titanite', 'rarity' => 'r16', 'is_moon_ore' => true],

                // Moon Ores (R8 - Common)
                ['id' => 45490, 'name' => 'Zeolites', 'rarity' => 'r8', 'is_moon_ore' => true],
                ['id' => 46682, 'name' => 'Scheelite', 'rarity' => 'r8', 'is_moon_ore' => true],

                // Moon Ores (R4 - Ubiquitous)
                ['id' => 45488, 'name' => 'Bitumens', 'rarity' => 'r4', 'is_moon_ore' => true],
                ['id' => 46684, 'name' => 'Sylvite', 'rarity' => 'r4', 'is_moon_ore' => true],

                // Regular Ores
                ['id' => 1230, 'name' => 'Veldspar', 'rarity' => null, 'is_moon_ore' => false, 'is_ore' => true],
                ['id' => 1228, 'name' => 'Scordite', 'rarity' => null, 'is_moon_ore' => false, 'is_ore' => true],
                ['id' => 1224, 'name' => 'Pyroxeres', 'rarity' => null, 'is_moon_ore' => false, 'is_ore' => true],

                // Ice
                ['id' => 16262, 'name' => 'Clear Icicle', 'rarity' => null, 'is_moon_ore' => false, 'is_ice' => true],
                ['id' => 17975, 'name' => 'Blue Ice', 'rarity' => null, 'is_moon_ore' => false, 'is_ice' => true],

                // Gas
                ['id' => 25268, 'name' => 'Mykoserocin', 'rarity' => null, 'is_moon_ore' => false, 'is_gas' => true],
                ['id' => 25272, 'name' => 'Cytoserocin', 'rarity' => null, 'is_moon_ore' => false, 'is_gas' => true],
            ];

            // Solar system IDs (various null sec systems)
            $solarSystems = [30000142, 30001161, 30002187, 30003504, 30004608];

            $entriesCreated = 0;

            foreach ($characters as $character) {
                for ($day = 0; $day < $daysOfData; $day++) {
                    $date = Carbon::now()->subDays($day);

                    for ($entry = 0; $entry < $entriesPerDay; $entry++) {
                        $ore = $oreTypes[array_rand($oreTypes)];
                        $quantity = rand(1000, 50000);
                        $solarSystem = $solarSystems[array_rand($solarSystems)];

                        DB::table('mining_ledger')->insert([
                            'character_id' => $character->character_id,
                            'date' => $date->format('Y-m-d'),
                            'type_id' => $ore['id'],
                            'quantity' => $quantity,
                            'solar_system_id' => $solarSystem,
                            'processed_at' => $date,
                            'is_moon_ore' => $ore['is_moon_ore'] ?? false,
                            'created_at' => $date,
                            'updated_at' => $date,
                        ]);

                        $entriesCreated++;
                    }
                }
            }

            DB::commit();

            return redirect()->route('mining-manager.diagnostic.index')
                ->with('success', "Generated {$entriesCreated} mining ledger entries successfully!");
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error generating test mining data', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error generating test mining data: ' . $e->getMessage());
        }
    }

    /**
     * Clean up all test data
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cleanupTestData()
    {
        try {
            DB::beginTransaction();

            // Get test character IDs
            $testCharacterIds = DB::table('character_infos')
                ->where('name', 'like', 'Test Miner%')
                ->pluck('character_id');

            // Get test corporation IDs
            $testCorporationIds = DB::table('corporation_infos')
                ->where('name', 'like', 'Test Corp%')
                ->pluck('corporation_id');

            // Delete mining taxes for test characters
            $taxesDeleted = DB::table('mining_taxes')
                ->whereIn('character_id', $testCharacterIds)
                ->delete();

            // Delete mining ledger entries for test characters
            $ledgerDeleted = DB::table('mining_ledger')
                ->whereIn('character_id', $testCharacterIds)
                ->delete();

            // Delete test corporation settings (multi-corporation settings)
            $settingsDeleted = DB::table('mining_manager_settings')
                ->whereIn('corporation_id', $testCorporationIds)
                ->delete();

            // Delete character affiliations for test characters
            $affiliationsDeleted = 0;
            try {
                $affiliationsDeleted = DB::table('character_affiliations')
                    ->whereIn('character_id', $testCharacterIds)
                    ->delete();
            } catch (\Exception $e) {
                // Table might not exist
            }

            // Delete test characters
            $charactersDeleted = DB::table('character_infos')
                ->where('name', 'like', 'Test Miner%')
                ->delete();

            // Delete test corporations
            $corpsDeleted = DB::table('corporation_infos')
                ->where('name', 'like', 'Test Corp%')
                ->delete();

            DB::commit();

            $message = "Cleaned up test data: {$corpsDeleted} corporations, {$charactersDeleted} characters";
            if ($affiliationsDeleted > 0) {
                $message .= ", {$affiliationsDeleted} affiliations";
            }
            $message .= ", {$ledgerDeleted} ledger entries, {$taxesDeleted} tax records, {$settingsDeleted} settings";

            return redirect()->route('mining-manager.diagnostic.index')
                ->with('success', $message);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error cleaning up test data', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error cleaning up test data: ' . $e->getMessage());
        }
    }

    /**
     * Test price providers
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testPriceProvider(Request $request)
    {
        try {
            $provider = $request->input('provider', 'seat');
            $priceService = new PriceProviderService();

            // Check if Janice API key is configured when testing Janice
            if ($provider === 'janice') {
                $apiKey = \MiningManager\Models\Setting::getValue('janice_api_key')
                    ?: config('mining-manager.general.price_provider_api_key');

                if (empty($apiKey)) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Janice API key not configured. Please set it in Settings or add MINING_MANAGER_JANICE_API_KEY to your .env file.',
                        'provider' => $provider,
                        'missing_config' => true
                    ], 400);
                }
            }

            // Test with common ore types
            $testTypeIds = [
                34,     // Tritanium
                35,     // Pyerite
                36,     // Mexallon
                37,     // Isogen
                1230,   // Veldspar
                45506,  // Bistot (R64 moon ore)
            ];

            $startTime = microtime(true);

            // Temporarily set provider
            $originalProvider = \MiningManager\Models\Setting::getValue('price_provider');
            \MiningManager\Models\Setting::set('price_provider', $provider);

            $prices = $priceService->getPrices($testTypeIds);

            // Restore original provider
            \MiningManager\Models\Setting::set('price_provider', $originalProvider);

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2); // milliseconds

            // Get type names
            $typeNames = DB::table('invTypes')
                ->whereIn('typeID', $testTypeIds)
                ->pluck('typeName', 'typeID');

            $results = [];
            $successCount = 0;
            foreach ($testTypeIds as $typeId) {
                $price = $prices[$typeId] ?? 0;
                if ($price > 0) {
                    $successCount++;
                }
                $results[] = [
                    'type_id' => $typeId,
                    'type_name' => $typeNames[$typeId] ?? 'Unknown',
                    'price' => number_format($price, 2),
                    'status' => $price > 0 ? 'success' : 'failed'
                ];
            }

            return response()->json([
                'success' => true,
                'provider' => $provider,
                'duration_ms' => $duration,
                'total_items' => count($testTypeIds),
                'successful_items' => $successCount,
                'results' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Price provider test failed', [
                'provider' => $provider ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $provider ?? 'unknown'
            ], 500);
        }
    }

    /**
     * Get price provider configuration
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPriceProviderConfig()
    {
        try {
            $priceService = new PriceProviderService();
            $providers = $priceService->getAvailableProviders();

            $currentProvider = \MiningManager\Models\Setting::getValue('price_provider', 'seat');

            // Check if Janice API key is configured
            $janiceApiKey = \MiningManager\Models\Setting::getValue('janice_api_key')
                ?: config('mining-manager.general.price_provider_api_key');
            $janiceConfigured = !empty($janiceApiKey);

            $config = [
                'current_provider' => $currentProvider,
                'providers' => $providers,
                'janice_configured' => $janiceConfigured,
                'settings' => [
                    'janice_api_key' => $janiceConfigured ? '***configured***' : 'NOT CONFIGURED',
                    'janice_market' => \MiningManager\Models\Setting::getValue('janice_market', 'jita'),
                    'janice_price_method' => \MiningManager\Models\Setting::getValue('janice_price_method', 'buy'),
                    'janice_batch_size' => \MiningManager\Models\Setting::getValue('janice_batch_size', 50),
                    'janice_rate_limit_delay' => \MiningManager\Models\Setting::getValue('janice_rate_limit_delay', 50000) . ' microseconds',
                    'janice_max_retries' => \MiningManager\Models\Setting::getValue('janice_max_retries', 3),
                    'price_region_id' => \MiningManager\Models\Setting::getValue('price_region_id', 10000002),
                    'price_method' => \MiningManager\Models\Setting::getValue('price_method', 'sell'),
                ]
            ];

            return response()->json($config);

        } catch (\Exception $e) {
            Log::error('Failed to get price provider config', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Batch test multiple ore types
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testBatchPricing(Request $request)
    {
        try {
            $provider = $request->input('provider', 'seat');
            $oreCategory = $request->input('category', 'all'); // all, moon, ice, gas, ore
            $priceService = new PriceProviderService();

            // Check if Janice API key is configured when testing Janice
            if ($provider === 'janice') {
                $apiKey = \MiningManager\Models\Setting::getValue('janice_api_key')
                    ?: config('mining-manager.general.price_provider_api_key');

                if (empty($apiKey)) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Janice API key not configured. Please set it in Settings or add MINING_MANAGER_JANICE_API_KEY to your .env file.',
                        'provider' => $provider,
                        'missing_config' => true
                    ], 400);
                }
            }

            // Get type IDs based on category
            $typeIds = [];
            switch ($oreCategory) {
                case 'moon':
                    $typeIds = array_keys(TypeIdRegistry::getMoonOreRarityMap());
                    break;
                case 'ice':
                    $typeIds = TypeIdRegistry::getIceTypeIds();
                    break;
                case 'gas':
                    $typeIds = TypeIdRegistry::getGasTypeIds();
                    break;
                case 'ore':
                    $typeIds = TypeIdRegistry::getOreTypeIds();
                    break;
                case 'all':
                default:
                    $typeIds = array_merge(
                        array_slice(TypeIdRegistry::getOreTypeIds(), 0, 10),
                        array_slice(array_keys(TypeIdRegistry::getMoonOreRarityMap()), 0, 10),
                        array_slice(TypeIdRegistry::getIceTypeIds(), 0, 5)
                    );
                    break;
            }

            $startTime = microtime(true);

            // Temporarily set provider
            $originalProvider = \MiningManager\Models\Setting::getValue('price_provider');
            \MiningManager\Models\Setting::set('price_provider', $provider);

            $prices = $priceService->getPrices($typeIds);

            // Restore original provider
            \MiningManager\Models\Setting::set('price_provider', $originalProvider);

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            // Get type names
            $typeNames = DB::table('invTypes')
                ->whereIn('typeID', $typeIds)
                ->pluck('typeName', 'typeID');

            $successCount = 0;
            $totalValue = 0;
            $results = [];

            foreach ($typeIds as $typeId) {
                $price = $prices[$typeId] ?? 0;
                if ($price > 0) {
                    $successCount++;
                    $totalValue += $price;
                }
                $results[] = [
                    'type_id' => $typeId,
                    'type_name' => $typeNames[$typeId] ?? 'Unknown',
                    'price' => $price,
                    'price_formatted' => number_format($price, 2),
                ];
            }

            return response()->json([
                'success' => true,
                'provider' => $provider,
                'category' => $oreCategory,
                'duration_ms' => $duration,
                'total_items' => count($typeIds),
                'successful_items' => $successCount,
                'avg_time_per_item' => count($typeIds) > 0 ? round($duration / count($typeIds), 2) : 0,
                'total_value' => $totalValue,
                'results' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Batch price test failed', [
                'provider' => $provider ?? 'unknown',
                'category' => $oreCategory ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get price cache health statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCacheHealth()
    {
        try {
            $cacheStats = [];

            // Get cache duration setting
            $cacheDuration = \MiningManager\Models\Setting::getValue('price_cache_duration', 60); // minutes

            // Get total cached items
            $totalCached = MiningPriceCache::count();

            // Get fresh items (within cache duration)
            $freshCutoff = now()->subMinutes($cacheDuration);
            $freshItems = MiningPriceCache::where('cached_at', '>=', $freshCutoff)->count();

            // Get stale items
            $staleItems = $totalCached - $freshItems;

            // Get items with zero prices (potential issues)
            $zeroPriceItems = MiningPriceCache::where(function($query) {
                $query->where('sell_price', '<=', 0)
                      ->where('buy_price', '<=', 0)
                      ->where('average_price', '<=', 0);
            })->count();

            // Get oldest cache entry
            $oldestCache = MiningPriceCache::oldest('cached_at')->first();
            $oldestCacheAge = $oldestCache ? now()->diffInHours($oldestCache->cached_at) : 0;

            // Get newest cache entry
            $newestCache = MiningPriceCache::latest('cached_at')->first();
            $newestCacheAge = $newestCache ? now()->diffInMinutes($newestCache->cached_at) : 0;

            // Check essential ore types
            $essentialOres = [
                34 => 'Tritanium',
                35 => 'Pyerite',
                36 => 'Mexallon',
                37 => 'Isogen',
                1230 => 'Veldspar',
                45506 => 'Bistot',
            ];

            $missingEssential = [];
            foreach ($essentialOres as $typeId => $name) {
                $cached = MiningPriceCache::where('type_id', $typeId)->first();
                if (!$cached) {
                    $missingEssential[] = ['type_id' => $typeId, 'name' => $name];
                }
            }

            // Determine health status
            $healthStatus = 'healthy';
            $issues = [];

            if ($totalCached === 0) {
                $healthStatus = 'critical';
                $issues[] = 'No prices cached. Run: php artisan mining-manager:cache-prices';
            } elseif ($staleItems > ($totalCached * 0.5)) {
                $healthStatus = 'warning';
                $issues[] = 'More than 50% of cache is stale. Consider running cache refresh.';
            } elseif (!empty($missingEssential)) {
                $healthStatus = 'warning';
                $issues[] = 'Essential ore types missing from cache.';
            }

            if ($zeroPriceItems > 0) {
                $issues[] = "{$zeroPriceItems} items have zero prices.";
            }

            return response()->json([
                'success' => true,
                'health_status' => $healthStatus,
                'statistics' => [
                    'total_cached' => $totalCached,
                    'fresh_items' => $freshItems,
                    'stale_items' => $staleItems,
                    'zero_price_items' => $zeroPriceItems,
                    'cache_duration_minutes' => $cacheDuration,
                    'oldest_cache_hours' => $oldestCacheAge,
                    'newest_cache_minutes' => $newestCacheAge,
                ],
                'missing_essential' => $missingEssential,
                'issues' => $issues,
                'recommendations' => $this->getCacheRecommendations($totalCached, $staleItems, $missingEssential)
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get cache health', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get cache recommendations based on health
     *
     * @param int $totalCached
     * @param int $staleItems
     * @param array $missingEssential
     * @return array
     */
    protected function getCacheRecommendations(int $totalCached, int $staleItems, array $missingEssential): array
    {
        $recommendations = [];

        if ($totalCached === 0) {
            $recommendations[] = [
                'severity' => 'critical',
                'message' => 'Price cache is empty. Tax calculations will fail or return zero values.',
                'action' => 'Run: php artisan mining-manager:cache-prices --type=all'
            ];
        } elseif ($staleItems > ($totalCached * 0.7)) {
            $recommendations[] = [
                'severity' => 'warning',
                'message' => 'Most of your price cache is stale.',
                'action' => 'Run: php artisan mining-manager:cache-prices --force'
            ];
        }

        if (!empty($missingEssential)) {
            $recommendations[] = [
                'severity' => 'warning',
                'message' => 'Essential ore types are missing from cache.',
                'action' => 'Run: php artisan mining-manager:cache-prices --type=ore'
            ];
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'severity' => 'info',
                'message' => 'Cache health looks good!',
                'action' => 'Schedule regular cache refreshes with a cron job for best results.'
            ];
        }

        return $recommendations;
    }

    /**
     * Warm up price cache with common ore types
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function warmCache(Request $request)
    {
        try {
            $category = $request->input('category', 'essential'); // essential, ore, moon, ice, gas, all

            $typeIds = [];
            switch ($category) {
                case 'essential':
                    $typeIds = [34, 35, 36, 37, 38, 39, 40, 1230, 1228, 1229, 45506]; // Common minerals + ores
                    break;
                case 'ore':
                    $typeIds = TypeIdRegistry::getOreTypeIds();
                    break;
                case 'moon':
                    $typeIds = array_keys(TypeIdRegistry::getMoonOreRarityMap());
                    break;
                case 'ice':
                    $typeIds = TypeIdRegistry::getIceTypeIds();
                    break;
                case 'gas':
                    $typeIds = TypeIdRegistry::getGasTypeIds();
                    break;
                case 'all':
                    $typeIds = array_merge(
                        TypeIdRegistry::getOreTypeIds(),
                        array_keys(TypeIdRegistry::getMoonOreRarityMap()),
                        TypeIdRegistry::getIceTypeIds(),
                        TypeIdRegistry::getGasTypeIds(),
                        [34, 35, 36, 37, 38, 39, 40] // Common minerals
                    );
                    break;
            }

            $priceService = new PriceProviderService();
            $startTime = microtime(true);

            // Get configured provider
            $provider = \MiningManager\Models\Setting::getValue('price_provider', 'seat');

            // Fetch prices
            $prices = $priceService->getPrices($typeIds);

            // Store in cache
            $stored = 0;
            $failed = 0;
            $regionId = \MiningManager\Models\Setting::getValue('price_region_id', 10000002);

            foreach ($prices as $typeId => $price) {
                if ($price > 0) {
                    try {
                        MiningPriceCache::updateOrCreate(
                            ['type_id' => $typeId, 'region_id' => $regionId],
                            [
                                'sell_price' => $price,
                                'buy_price' => $price,
                                'average_price' => $price,
                                'cached_at' => now(),
                            ]
                        );
                        $stored++;
                    } catch (\Exception $e) {
                        $failed++;
                    }
                } else {
                    $failed++;
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            return response()->json([
                'success' => true,
                'category' => $category,
                'provider' => $provider,
                'duration_ms' => $duration,
                'total_items' => count($typeIds),
                'stored' => $stored,
                'failed' => $failed,
                'message' => "Cached {$stored} prices in {$duration}ms using {$provider} provider"
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to warm cache', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
