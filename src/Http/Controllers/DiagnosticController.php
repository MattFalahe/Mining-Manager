<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use MiningManager\Services\Pricing\PriceProviderService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Pricing\OreValuationService;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Models\Setting;

class DiagnosticController extends Controller
{
    /**
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * @var PriceProviderService
     */
    protected $priceService;

    /**
     * @var OreValuationService
     */
    protected $valuationService;

    /**
     * Constructor with dependency injection
     */
    public function __construct(
        SettingsManagerService $settingsService,
        PriceProviderService $priceService,
        OreValuationService $valuationService
    ) {
        $this->settingsService = $settingsService;
        $this->priceService = $priceService;
        $this->valuationService = $valuationService;
    }

    /**
     * Test endpoint to verify routes are working
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ping()
    {
        Log::info('DiagnosticController: ping endpoint called');
        return response()->json([
            'success' => true,
            'message' => 'Diagnostic routes are working!',
            'timestamp' => now()->toDateTimeString()
        ]);
    }

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
     * FIXED: Uses injected services, adds finally block to restore provider
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testPriceProvider(Request $request)
    {
        Log::info('DiagnosticController: testPriceProvider called', [
            'provider' => $request->input('provider', 'seat'),
            'request_method' => $request->method(),
            'ip' => $request->ip()
        ]);

        $provider = $request->input('provider', 'seat');
        $originalProvider = null;

        try {
            // Check if Janice API key is configured when testing Janice
            if ($provider === 'janice') {
                $pricingSettings = $this->settingsService->getPricingSettings();
                $apiKey = $pricingSettings['janice_api_key'] ?? '';

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

            // Temporarily set provider via settings service (ensures cache invalidation)
            $pricingSettings = $this->settingsService->getPricingSettings();
            $originalProvider = $pricingSettings['price_provider'] ?? 'seat';
            $this->settingsService->updateSetting('price_provider', $provider, 'string');

            $prices = $this->priceService->getPrices($testTypeIds);

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
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $provider
            ], 500);
        } finally {
            // Always restore original provider
            if ($originalProvider !== null) {
                $this->settingsService->updateSetting('price_provider', $originalProvider, 'string');
            }
        }
    }

    /**
     * Get price provider configuration
     * FIXED: Uses injected services instead of new PriceProviderService() and Setting::getValue()
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPriceProviderConfig()
    {
        Log::info('DiagnosticController: getPriceProviderConfig called');

        try {
            $providers = $this->priceService->getAvailableProviders();

            $pricingSettings = $this->settingsService->getPricingSettings();
            $generalSettings = $this->settingsService->getGeneralSettings();
            $currentProvider = $pricingSettings['price_provider'] ?? 'seat';

            // Check if Janice API key is configured
            $janiceApiKey = $pricingSettings['janice_api_key'] ?? '';
            $janiceConfigured = !empty($janiceApiKey);

            $config = [
                'current_provider' => $currentProvider,
                'providers' => $providers,
                'janice_configured' => $janiceConfigured,
                'settings' => [
                    'janice_api_key' => $janiceConfigured ? '***configured***' : 'NOT CONFIGURED',
                    'janice_market' => $pricingSettings['janice_market'] ?? 'jita',
                    'janice_price_method' => $pricingSettings['janice_price_method'] ?? 'buy',
                    'price_type' => $pricingSettings['price_type'] ?? 'sell',
                    'cache_duration' => ($pricingSettings['cache_duration'] ?? 60) . ' minutes',
                    'auto_refresh' => ($pricingSettings['auto_refresh'] ?? true) ? 'Enabled' : 'Disabled',
                    'use_refined_value' => ($pricingSettings['use_refined_value'] ?? false) ? 'Yes' : 'No',
                    'refining_efficiency' => ($pricingSettings['refining_efficiency'] ?? 87.5) . '%',
                    'default_region_id' => $generalSettings['default_region_id'] ?? 10000002,
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
     * FIXED: Uses injected services, adds finally block to restore provider
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testBatchPricing(Request $request)
    {
        $provider = $request->input('provider', 'seat');
        $oreCategory = $request->input('category', 'all');
        $originalProvider = null;

        try {
            // Check if Janice API key is configured when testing Janice
            if ($provider === 'janice') {
                $pricingSettings = $this->settingsService->getPricingSettings();
                $apiKey = $pricingSettings['janice_api_key'] ?? '';

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
                    $typeIds = TypeIdRegistry::getAllMoonOres();
                    break;
                case 'moon-materials':
                    $typeIds = TypeIdRegistry::getAllMoonMaterials();
                    break;
                case 'ice':
                    $typeIds = TypeIdRegistry::getAllIce();
                    break;
                case 'gas':
                    $typeIds = TypeIdRegistry::getAllGas();
                    break;
                case 'ore':
                    $typeIds = TypeIdRegistry::getAllRegularOres();
                    break;
                case 'essential':
                    // Essential ores + minerals for quick test
                    $typeIds = [
                        34, 35, 36, 37, 38, 39, 40,  // Minerals
                        1230, 1228, 1224,  // Veldspar, Scordite, Pyroxeres
                    ];
                    break;
                case 'all':
                default:
                    $typeIds = array_merge(
                        array_slice(TypeIdRegistry::getAllRegularOres(), 0, 10),
                        array_slice(TypeIdRegistry::getAllMoonOres(), 0, 10),
                        array_slice(TypeIdRegistry::getAllIce(), 0, 5)
                    );
                    break;
            }

            $startTime = microtime(true);

            // Temporarily set provider via settings service (ensures cache invalidation)
            $pricingSettings = $this->settingsService->getPricingSettings();
            $originalProvider = $pricingSettings['price_provider'] ?? 'seat';
            $this->settingsService->updateSetting('price_provider', $provider, 'string');

            $prices = $this->priceService->getPrices($typeIds);

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
                'provider' => $provider,
                'category' => $oreCategory,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        } finally {
            // Always restore original provider
            if ($originalProvider !== null) {
                $this->settingsService->updateSetting('price_provider', $originalProvider, 'string');
            }
        }
    }

    /**
     * Get price cache health statistics
     * FIXED: Uses settingsService for cache duration instead of wrong Setting key
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCacheHealth()
    {
        try {
            // Get cache duration from settings service (correct key path)
            $pricingSettings = $this->settingsService->getPricingSettings();
            $cacheDuration = (int) ($pricingSettings['cache_duration'] ?? 60);

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
     * FIXED: Uses injected services for provider and region ID
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
                    $typeIds = TypeIdRegistry::getAllRegularOres();
                    break;
                case 'moon':
                    $typeIds = TypeIdRegistry::getAllMoonOres();
                    break;
                case 'ice':
                    $typeIds = TypeIdRegistry::getAllIce();
                    break;
                case 'gas':
                    $typeIds = TypeIdRegistry::getAllGas();
                    break;
                case 'all':
                    $typeIds = array_merge(
                        TypeIdRegistry::getAllRegularOres(),
                        TypeIdRegistry::getAllMoonOres(),
                        TypeIdRegistry::getAllIce(),
                        TypeIdRegistry::getAllGas(),
                        [34, 35, 36, 37, 38, 39, 40] // Common minerals
                    );
                    break;
            }

            $startTime = microtime(true);

            // Get configured provider and region from settings service
            $pricingSettings = $this->settingsService->getPricingSettings();
            $generalSettings = $this->settingsService->getGeneralSettings();
            $provider = $pricingSettings['price_provider'] ?? 'seat';
            $regionId = (int) ($generalSettings['default_region_id'] ?? 10000002);

            // Fetch prices
            $prices = $this->priceService->getPrices($typeIds);

            // Store in cache using correct price_type column
            $stored = 0;
            $failed = 0;
            $priceType = $pricingSettings['price_type'] ?? 'sell';

            foreach ($prices as $typeId => $price) {
                if ($price > 0) {
                    try {
                        $cacheData = [
                            'cached_at' => now(),
                        ];

                        // Store price in the correct column based on price_type setting
                        if ($priceType === 'buy') {
                            $cacheData['buy_price'] = $price;
                            $cacheData['sell_price'] = $price;
                            $cacheData['average_price'] = $price;
                        } elseif ($priceType === 'average') {
                            $cacheData['average_price'] = $price;
                            $cacheData['sell_price'] = $price;
                            $cacheData['buy_price'] = $price;
                        } else {
                            // Default: sell
                            $cacheData['sell_price'] = $price;
                            $cacheData['buy_price'] = $price;
                            $cacheData['average_price'] = $price;
                        }

                        MiningPriceCache::updateOrCreate(
                            ['type_id' => $typeId, 'region_id' => $regionId],
                            $cacheData
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

    /**
     * Validate TypeID Registry against database
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateTypeIds(Request $request)
    {
        $category = $request->input('category', 'materials'); // materials, moon, ore, ice, gas, all

        try {
            $startTime = microtime(true);

            // Get type IDs for category
            $typeIds = TypeIdRegistry::getTypeIdsByCategory($category);

            if (empty($typeIds)) {
                return response()->json([
                    'success' => false,
                    'error' => "Unknown category: {$category}"
                ], 400);
            }

            $results = [];
            $verified = 0;
            $failed = 0;

            // Batch query for better performance
            $types = DB::table('invTypes')
                ->whereIn('typeID', $typeIds)
                ->select('typeID', 'typeName', 'groupID')
                ->get()
                ->keyBy('typeID');

            foreach ($typeIds as $typeId) {
                if (isset($types[$typeId])) {
                    $type = $types[$typeId];
                    $results[] = [
                        'type_id' => $typeId,
                        'name' => $type->typeName,
                        'group_id' => $type->groupID,
                        'status' => 'success'
                    ];
                    $verified++;
                } else {
                    $results[] = [
                        'type_id' => $typeId,
                        'name' => 'NOT FOUND',
                        'group_id' => null,
                        'status' => 'failed',
                        'error' => 'Type ID not found in invTypes table'
                    ];
                    $failed++;
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $totalCount = count($typeIds);

            return response()->json([
                'success' => true,
                'category' => $category,
                'duration_ms' => $duration,
                'total_items' => $totalCount,
                'verified' => $verified,
                'failed' => $failed,
                'results' => $results,
                'message' => "Validated {$verified}/{$totalCount} type IDs in {$duration}ms"
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to validate type IDs', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test webhook with simulated theft notification
     *
     * @param Request $request
     * @param int $webhookId
     * @return \Illuminate\Http\JsonResponse
     */
    public function testWebhook(Request $request, $webhookId)
    {
        try {
            $startTime = microtime(true);

            // Get webhook
            $webhook = \MiningManager\Models\WebhookConfiguration::findOrFail($webhookId);

            // Get test data from request
            $eventType = $request->input('event_type', 'theft_detected');
            $characterName = $request->input('character_name', 'Test Miner');
            $severity = $request->input('severity', 'medium');
            $oreValue = $request->input('ore_value', 50000000);
            $taxOwed = $request->input('tax_owed', 5000000);
            $tempRoleId = $request->input('temp_role_id');

            // Temporarily override Discord role ID if provided
            $originalRoleId = null;
            $tempRoleUsed = false;
            if ($tempRoleId && $webhook->type === 'discord') {
                $originalRoleId = $webhook->discord_role_id;
                $webhook->discord_role_id = $tempRoleId;
                $tempRoleUsed = true;
            }

            // Create simulated theft incident
            $testIncident = new \MiningManager\Models\TheftIncident([
                'character_id' => 123456789,
                'character_name' => $characterName,
                'severity' => $severity,
                'ore_value' => $oreValue,
                'tax_owed' => $taxOwed,
                'status' => 'open',
                'detected_at' => now(),
            ]);

            // Add additional data based on event type
            $additionalData = [
                'test_mode' => true,
                'incident_url' => route('mining-manager.diagnostic.index'),
            ];

            if ($eventType === 'active_theft') {
                $additionalData['new_mining_value'] = $request->input('new_mining_value', 10000000);
                $additionalData['last_activity'] = now()->format('Y-m-d H:i:s');
                $testIncident->activity_count = $request->input('activity_count', 3);
                $testIncident->last_activity_at = now();
            } elseif ($eventType === 'incident_resolved') {
                $additionalData['resolved_by'] = 'Diagnostic Test';
                $testIncident->status = 'resolved';
                $testIncident->resolved_by = 'Test Admin';
            }

            // Send notification
            $webhookService = app(\MiningManager\Services\Notification\WebhookService::class);
            $result = $webhookService->sendTheftNotification($testIncident, $eventType, $additionalData);

            // Restore original role ID if it was temporarily changed
            if ($originalRoleId !== null) {
                $webhook->discord_role_id = $originalRoleId;
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Check if notification was successful
            $success = false;
            $error = null;
            foreach ($result as $webhookResult) {
                if ($webhookResult['success']) {
                    $success = true;
                    break;
                } else {
                    $error = $webhookResult['error'] ?? 'Unknown error';
                }
            }

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test notification sent successfully!',
                    'webhook_name' => $webhook->name,
                    'webhook_type' => $webhook->type,
                    'event_type' => $eventType,
                    'duration_ms' => $duration,
                    'temp_role_used' => $tempRoleUsed,
                    'role_mention' => $tempRoleUsed ? $tempRoleId : $webhook->discord_role_id,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $error,
                    'message' => 'Failed to send test notification',
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Failed to test webhook', [
                'webhook_id' => $webhookId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Exception occurred while testing webhook'
            ], 500);
        }
    }

    // ========================================================================
    // NEW DIAGNOSTIC TOOLS
    // ========================================================================

    /**
     * Settings Health Check
     * Shows all plugin settings with source (DB / config / default), flags mismatches
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function settingsHealth()
    {
        try {
            $results = [];

            // Define all setting groups to check
            $settingGroups = [
                'General' => $this->settingsService->getGeneralSettings(),
                'Tax Rates' => $this->settingsService->getTaxRates(),
                'Pricing' => $this->settingsService->getPricingSettings(),
                'Contract' => $this->settingsService->getContractSettings(),
                'Payment' => $this->settingsService->getPaymentSettings(),
            ];

            foreach ($settingGroups as $groupName => $settings) {
                $groupResults = [];
                foreach ($settings as $key => $value) {
                    // Check source: DB vs config vs hardcoded
                    $source = 'default';
                    $dbValue = null;

                    if (is_array($value)) {
                        // Nested settings (e.g., tax_rates.moon_ore)
                        foreach ($value as $subKey => $subValue) {
                            $fullKey = $key . '.' . $subKey;
                            $dbRecord = Setting::where('key', $fullKey)->first()
                                ?? Setting::where('key', strtolower($groupName) . '.' . $fullKey)->first();

                            $groupResults[] = [
                                'key' => $fullKey,
                                'value' => $subValue,
                                'source' => $dbRecord ? 'database' : (config('mining-manager.' . $fullKey) !== null ? 'config' : 'default'),
                                'type' => gettype($subValue),
                            ];
                        }
                    } else {
                        $dbRecord = Setting::where('key', $key)->first()
                            ?? Setting::where('key', strtolower($groupName) . '.' . $key)->first();

                        // Mask sensitive values
                        $displayValue = $value;
                        if (in_array($key, ['janice_api_key']) && !empty($value)) {
                            $displayValue = '***' . substr($value, -4);
                        }

                        $groupResults[] = [
                            'key' => $key,
                            'value' => $displayValue,
                            'source' => $dbRecord ? 'database' : (config('mining-manager.' . $key) !== null ? 'config' : 'default'),
                            'type' => gettype($value),
                        ];
                    }
                }
                $results[$groupName] = $groupResults;
            }

            // Check for corporation-specific overrides
            $corpOverrides = Setting::whereNotNull('corporation_id')
                ->select('corporation_id', DB::raw('COUNT(*) as setting_count'))
                ->groupBy('corporation_id')
                ->get()
                ->map(function ($row) {
                    $corpName = DB::table('corporation_infos')
                        ->where('corporation_id', $row->corporation_id)
                        ->value('name');
                    return [
                        'corporation_id' => $row->corporation_id,
                        'corporation_name' => $corpName ?? 'Unknown',
                        'setting_count' => $row->setting_count,
                    ];
                });

            // Check for orphaned settings (settings for corporations that no longer exist)
            $orphanedSettings = Setting::whereNotNull('corporation_id')
                ->whereNotIn('corporation_id', function ($query) {
                    $query->select('corporation_id')->from('corporation_infos');
                })
                ->count();

            // Total DB settings count
            $totalDbSettings = Setting::count();
            $globalSettings = Setting::whereNull('corporation_id')->count();

            return response()->json([
                'success' => true,
                'settings' => $results,
                'summary' => [
                    'total_db_settings' => $totalDbSettings,
                    'global_settings' => $globalSettings,
                    'corporation_overrides' => $corpOverrides,
                    'orphaned_settings' => $orphanedSettings,
                ],
                'issues' => $orphanedSettings > 0
                    ? ["{$orphanedSettings} orphaned setting(s) found for non-existent corporations"]
                    : [],
            ]);

        } catch (\Exception $e) {
            Log::error('Settings health check failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tax Calculation Trace
     * Dry-run tax calculation showing the full decision chain for a character + month
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function taxDiagnostic(Request $request)
    {
        try {
            $characterId = (int) $request->input('character_id');
            $month = $request->input('month', Carbon::now()->subMonth()->format('Y-m'));

            if (!$characterId) {
                return response()->json([
                    'success' => false,
                    'error' => 'character_id is required'
                ], 400);
            }

            // Parse month
            $monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            // Get character info
            $character = DB::table('character_infos')
                ->where('character_id', $characterId)
                ->first();

            if (!$character) {
                return response()->json([
                    'success' => false,
                    'error' => "Character {$characterId} not found"
                ], 404);
            }

            // Get character's corporation
            $affiliation = DB::table('character_affiliations')
                ->where('character_id', $characterId)
                ->first();

            $corporationId = $affiliation->corporation_id ?? null;
            $corporationName = null;
            if ($corporationId) {
                $corporationName = DB::table('corporation_infos')
                    ->where('corporation_id', $corporationId)
                    ->value('name');
            }

            // Get mining entries for this character in this month
            $entries = DB::table('mining_ledger')
                ->where('character_id', $characterId)
                ->whereBetween('date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
                ->orderBy('date')
                ->get();

            // Load settings
            $taxRates = $this->settingsService->getTaxRates();
            $pricingSettings = $this->settingsService->getPricingSettings();
            $generalSettings = $this->settingsService->getGeneralSettings();

            // Check exemptions
            $exemptions = $this->settingsService->getSetting('tax_rates.exemptions', []);
            $isExempt = false;
            if (is_array($exemptions)) {
                $isExempt = in_array($characterId, $exemptions)
                    || ($corporationId && in_array($corporationId, $exemptions));
            }

            // Process each mining entry
            $traceResults = [];
            $totalTax = 0;
            $totalValue = 0;

            foreach ($entries as $entry) {
                $typeId = $entry->type_id;
                $quantity = $entry->quantity;

                // Identify ore
                $isMoonOre = TypeIdRegistry::isMoonOre($typeId);
                $isIce = TypeIdRegistry::isIce($typeId);
                $isGas = TypeIdRegistry::isGas($typeId);
                $isRegularOre = TypeIdRegistry::isRegularOre($typeId);
                $rarity = $isMoonOre ? TypeIdRegistry::getMoonOreRarity($typeId) : null;

                // Determine category
                $category = 'unknown';
                if ($isMoonOre) $category = 'moon_ore';
                elseif ($isIce) $category = 'ice';
                elseif ($isGas) $category = 'gas';
                elseif ($isRegularOre) $category = 'ore';

                // Determine tax rate
                $taxRate = 0;
                if ($isMoonOre && $rarity) {
                    $taxRate = $taxRates['moon_ore'][$rarity] ?? 0;
                } elseif ($isIce) {
                    $taxRate = $taxRates['ice'] ?? 0;
                } elseif ($isGas) {
                    $taxRate = $taxRates['gas'] ?? 0;
                } elseif ($isRegularOre) {
                    $taxRate = $taxRates['ore'] ?? 0;
                }

                // Get valuation
                $oreValue = 0;
                try {
                    $unitPrice = $this->priceService->getPrice($typeId);
                    $oreValue = ($unitPrice ?? 0) * $quantity;
                } catch (\Exception $e) {
                    // Price fetch failed, keep at 0
                }

                // Calculate tax
                $taxAmount = $isExempt ? 0 : round($oreValue * ($taxRate / 100), 2);
                $totalTax += $taxAmount;
                $totalValue += $oreValue;

                // Get type name
                $typeName = DB::table('invTypes')
                    ->where('typeID', $typeId)
                    ->value('typeName') ?? "Type {$typeId}";

                $traceResults[] = [
                    'date' => $entry->date,
                    'type_id' => $typeId,
                    'type_name' => $typeName,
                    'quantity' => $quantity,
                    'category' => $category,
                    'rarity' => $rarity,
                    'tax_rate' => $taxRate,
                    'unit_price' => round($oreValue / max($quantity, 1), 2),
                    'total_value' => round($oreValue, 2),
                    'tax_amount' => $taxAmount,
                    'is_exempt' => $isExempt,
                ];
            }

            return response()->json([
                'success' => true,
                'character' => [
                    'id' => $characterId,
                    'name' => $character->name,
                    'corporation_id' => $corporationId,
                    'corporation_name' => $corporationName,
                ],
                'period' => [
                    'month' => $month,
                    'start' => $monthStart->format('Y-m-d'),
                    'end' => $monthEnd->format('Y-m-d'),
                ],
                'settings_used' => [
                    'price_provider' => $pricingSettings['price_provider'] ?? 'seat',
                    'valuation_method' => $generalSettings['ore_valuation_method'] ?? 'mineral_price',
                    'refining_efficiency' => $pricingSettings['refining_efficiency'] ?? 87.5,
                    'is_exempt' => $isExempt,
                ],
                'tax_rates_applied' => $taxRates,
                'summary' => [
                    'total_entries' => count($entries),
                    'total_value' => round($totalValue, 2),
                    'total_tax' => round($totalTax, 2),
                    'effective_rate' => $totalValue > 0 ? round(($totalTax / $totalValue) * 100, 2) : 0,
                ],
                'entries' => $traceResults,
            ]);

        } catch (\Exception $e) {
            Log::error('Tax diagnostic failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Data Integrity Scan
     * Scans for data quality problems across mining data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function dataIntegrity()
    {
        try {
            $issues = [];
            $startTime = microtime(true);

            // 1. Mining entries with unknown type_ids
            $allKnownTypeIds = array_merge(
                TypeIdRegistry::getAllRegularOres(),
                TypeIdRegistry::getAllMoonOres(),
                TypeIdRegistry::getAllIce(),
                TypeIdRegistry::getAllGas(),
                TypeIdRegistry::MINERALS,
                TypeIdRegistry::getAllMoonMaterials(),
                TypeIdRegistry::ICE_PRODUCTS
            );

            $unknownTypeEntries = DB::table('mining_ledger')
                ->whereNotIn('type_id', $allKnownTypeIds)
                ->select('type_id', DB::raw('COUNT(*) as count'))
                ->groupBy('type_id')
                ->limit(20)
                ->get();

            if ($unknownTypeEntries->isNotEmpty()) {
                $typeNames = DB::table('invTypes')
                    ->whereIn('typeID', $unknownTypeEntries->pluck('type_id'))
                    ->pluck('typeName', 'typeID');

                $issues[] = [
                    'category' => 'Unknown Type IDs',
                    'severity' => 'warning',
                    'count' => $unknownTypeEntries->sum('count'),
                    'message' => 'Mining entries with type IDs not in TypeIdRegistry',
                    'details' => $unknownTypeEntries->map(function ($row) use ($typeNames) {
                        return [
                            'type_id' => $row->type_id,
                            'type_name' => $typeNames[$row->type_id] ?? 'Unknown',
                            'entry_count' => $row->count,
                        ];
                    }),
                ];
            }

            // 2. Mining entries with zero quantities
            $zeroQuantity = DB::table('mining_ledger')
                ->where('quantity', '<=', 0)
                ->count();

            if ($zeroQuantity > 0) {
                $issues[] = [
                    'category' => 'Zero Quantities',
                    'severity' => 'error',
                    'count' => $zeroQuantity,
                    'message' => 'Mining entries with zero or negative quantity',
                ];
            }

            // 3. Mining entries with missing character records
            $orphanedEntries = DB::table('mining_ledger')
                ->whereNotIn('character_id', function ($query) {
                    $query->select('character_id')->from('character_infos');
                })
                ->count();

            if ($orphanedEntries > 0) {
                $issues[] = [
                    'category' => 'Orphaned Entries',
                    'severity' => 'warning',
                    'count' => $orphanedEntries,
                    'message' => 'Mining entries for characters not in character_infos',
                ];
            }

            // 4. Price cache entries with all-zero prices
            $zeroPrices = MiningPriceCache::where('sell_price', '<=', 0)
                ->where('buy_price', '<=', 0)
                ->where('average_price', '<=', 0)
                ->count();

            if ($zeroPrices > 0) {
                $issues[] = [
                    'category' => 'Zero Price Cache',
                    'severity' => 'warning',
                    'count' => $zeroPrices,
                    'message' => 'Price cache entries with all prices at zero',
                ];
            }

            // 5. Tax records with negative amounts
            $negativeTaxes = DB::table('mining_taxes')
                ->where('amount_owed', '<', 0)
                ->count();

            if ($negativeTaxes > 0) {
                $issues[] = [
                    'category' => 'Negative Taxes',
                    'severity' => 'error',
                    'count' => $negativeTaxes,
                    'message' => 'Tax records with negative amount_owed',
                ];
            }

            // 5b. Mining ledger entries with negative tax_amount
            $negativeLedgerTax = DB::table('mining_ledger')
                ->where('tax_amount', '<', 0)
                ->count();

            if ($negativeLedgerTax > 0) {
                $issues[] = [
                    'category' => 'Negative Ledger Tax',
                    'severity' => 'error',
                    'count' => $negativeLedgerTax,
                    'message' => 'Mining ledger entries with negative tax_amount',
                ];
            }

            // 6. Tax records for non-existent characters
            $orphanedTaxes = DB::table('mining_taxes')
                ->whereNotIn('character_id', function ($query) {
                    $query->select('character_id')->from('character_infos');
                })
                ->count();

            if ($orphanedTaxes > 0) {
                $issues[] = [
                    'category' => 'Orphaned Taxes',
                    'severity' => 'warning',
                    'count' => $orphanedTaxes,
                    'message' => 'Tax records for characters not in character_infos',
                ];
            }

            // 7. Duplicate mining entries (same character, date, type_id, solar_system)
            $duplicates = DB::table('mining_ledger')
                ->select('character_id', 'date', 'type_id', 'solar_system_id', DB::raw('COUNT(*) as dupe_count'))
                ->groupBy('character_id', 'date', 'type_id', 'solar_system_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($duplicates > 0) {
                $issues[] = [
                    'category' => 'Duplicate Entries',
                    'severity' => 'warning',
                    'count' => $duplicates,
                    'message' => 'Potential duplicate mining ledger entries (same character+date+type+system)',
                ];
            }

            // 8. Settings table health
            $corruptSettings = Setting::whereNull('key')
                ->orWhere('key', '')
                ->count();

            if ($corruptSettings > 0) {
                $issues[] = [
                    'category' => 'Corrupt Settings',
                    'severity' => 'error',
                    'count' => $corruptSettings,
                    'message' => 'Settings entries with empty or null keys',
                ];
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Summary
            $totalIssues = array_sum(array_column($issues, 'count'));
            $errorCount = count(array_filter($issues, fn($i) => $i['severity'] === 'error'));
            $warningCount = count(array_filter($issues, fn($i) => $i['severity'] === 'warning'));

            return response()->json([
                'success' => true,
                'duration_ms' => $duration,
                'health_status' => $errorCount > 0 ? 'error' : ($warningCount > 0 ? 'warning' : 'healthy'),
                'summary' => [
                    'total_issues' => $totalIssues,
                    'error_categories' => $errorCount,
                    'warning_categories' => $warningCount,
                    'total_mining_entries' => DB::table('mining_ledger')->count(),
                    'total_tax_records' => DB::table('mining_taxes')->count(),
                    'total_price_cache' => MiningPriceCache::count(),
                    'total_characters' => DB::table('character_infos')->count(),
                ],
                'issues' => $issues,
            ]);

        } catch (\Exception $e) {
            Log::error('Data integrity scan failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Valuation Test
     * Step-by-step trace of how a specific ore type_id + quantity is valued
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function valuationTest(Request $request)
    {
        try {
            $typeId = (int) $request->input('type_id');
            $quantity = (int) $request->input('quantity', 1000);

            if (!$typeId) {
                return response()->json([
                    'success' => false,
                    'error' => 'type_id is required'
                ], 400);
            }

            // Get type info
            $typeInfo = DB::table('invTypes')
                ->where('typeID', $typeId)
                ->select('typeID', 'typeName', 'groupID', 'volume')
                ->first();

            if (!$typeInfo) {
                return response()->json([
                    'success' => false,
                    'error' => "Type ID {$typeId} not found in invTypes"
                ], 404);
            }

            // Identify category
            $isMoonOre = TypeIdRegistry::isMoonOre($typeId);
            $isIce = TypeIdRegistry::isIce($typeId);
            $isGas = TypeIdRegistry::isGas($typeId);
            $isRegularOre = TypeIdRegistry::isRegularOre($typeId);
            $rarity = $isMoonOre ? TypeIdRegistry::getMoonOreRarity($typeId) : null;
            $isJackpot = $isMoonOre ? in_array($typeId, TypeIdRegistry::getAllJackpotOres()) : false;

            $category = 'unknown';
            if ($isMoonOre) $category = 'moon_ore';
            elseif ($isIce) $category = 'ice';
            elseif ($isGas) $category = 'gas';
            elseif ($isRegularOre) $category = 'ore';

            // Load settings
            $pricingSettings = $this->settingsService->getPricingSettings();
            $generalSettings = $this->settingsService->getGeneralSettings();
            $taxRates = $this->settingsService->getTaxRates();

            $steps = [];

            // Step 1: Ore identification
            $steps[] = [
                'step' => 1,
                'action' => 'Ore Identification',
                'result' => [
                    'type_id' => $typeId,
                    'type_name' => $typeInfo->typeName,
                    'category' => $category,
                    'rarity' => $rarity,
                    'is_jackpot' => $isJackpot,
                    'volume_per_unit' => $typeInfo->volume,
                ],
            ];

            // Step 2: Settings loaded
            $steps[] = [
                'step' => 2,
                'action' => 'Settings Loaded',
                'result' => [
                    'price_provider' => $pricingSettings['price_provider'] ?? 'seat',
                    'price_type' => $pricingSettings['price_type'] ?? 'sell',
                    'use_refined_value' => $pricingSettings['use_refined_value'] ?? false,
                    'refining_efficiency' => $pricingSettings['refining_efficiency'] ?? 87.5,
                    'valuation_method' => $generalSettings['ore_valuation_method'] ?? 'mineral_price',
                ],
            ];

            // Step 3: Price fetch
            $unitPrice = 0;
            $priceSource = 'none';
            try {
                $unitPrice = $this->priceService->getPrice($typeId);
                $priceSource = ($unitPrice && $unitPrice > 0) ? 'price_provider' : 'none';

                // Check if from cache
                $cacheEntry = MiningPriceCache::where('type_id', $typeId)->first();
                if ($cacheEntry) {
                    $priceSource = 'cache (age: ' . $cacheEntry->cached_at->diffForHumans() . ')';
                }
            } catch (\Exception $e) {
                $priceSource = 'error: ' . $e->getMessage();
            }

            $steps[] = [
                'step' => 3,
                'action' => 'Price Fetch',
                'result' => [
                    'unit_price' => round($unitPrice ?? 0, 2),
                    'price_source' => $priceSource,
                    'cache_hit' => MiningPriceCache::where('type_id', $typeId)->exists(),
                ],
            ];

            // Step 4: Value calculation
            $totalValue = ($unitPrice ?? 0) * $quantity;

            $steps[] = [
                'step' => 4,
                'action' => 'Value Calculation',
                'result' => [
                    'formula' => "unit_price ({$unitPrice}) x quantity ({$quantity})",
                    'total_value' => round($totalValue, 2),
                    'total_volume' => round($typeInfo->volume * $quantity, 2),
                ],
            ];

            // Step 5: Tax rate determination
            $taxRate = 0;
            $taxRateSource = '';
            if ($isMoonOre && $rarity) {
                $taxRate = $taxRates['moon_ore'][$rarity] ?? 0;
                $taxRateSource = "moon_ore.{$rarity}";
            } elseif ($isIce) {
                $taxRate = $taxRates['ice'] ?? 0;
                $taxRateSource = 'ice';
            } elseif ($isGas) {
                $taxRate = $taxRates['gas'] ?? 0;
                $taxRateSource = 'gas';
            } elseif ($isRegularOre) {
                $taxRate = $taxRates['ore'] ?? 0;
                $taxRateSource = 'ore';
            }

            $taxAmount = round($totalValue * ($taxRate / 100), 2);

            $steps[] = [
                'step' => 5,
                'action' => 'Tax Calculation',
                'result' => [
                    'tax_rate' => $taxRate,
                    'tax_rate_source' => $taxRateSource,
                    'tax_amount' => $taxAmount,
                    'formula' => "total_value ({$totalValue}) x tax_rate ({$taxRate}%)",
                ],
            ];

            // Step 6: Price modifier (if applicable)
            $priceModifier = (float) ($generalSettings['price_modifier'] ?? 0);
            $modifiedValue = $totalValue;
            if ($priceModifier != 0) {
                $modifiedValue = $totalValue * (1 + ($priceModifier / 100));
            }

            $steps[] = [
                'step' => 6,
                'action' => 'Price Modifier',
                'result' => [
                    'modifier' => $priceModifier . '%',
                    'original_value' => round($totalValue, 2),
                    'modified_value' => round($modifiedValue, 2),
                    'applied' => $priceModifier != 0,
                ],
            ];

            return response()->json([
                'success' => true,
                'type' => [
                    'type_id' => $typeId,
                    'type_name' => $typeInfo->typeName,
                    'quantity' => $quantity,
                    'category' => $category,
                ],
                'final_result' => [
                    'unit_price' => round($unitPrice ?? 0, 2),
                    'total_value' => round($totalValue, 2),
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                ],
                'steps' => $steps,
            ]);

        } catch (\Exception $e) {
            Log::error('Valuation test failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'debug' => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                ],
            ], 500);
        }
    }
}
