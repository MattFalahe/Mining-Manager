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
use MiningManager\Models\MiningLedgerDailySummary;
use MiningManager\Models\Setting;
use MiningManager\Models\MiningTax;
use MiningManager\Models\WebhookConfiguration;
use MiningManager\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;

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

        // For Notification Testing tab
        $webhooks = WebhookConfiguration::select('id', 'name', 'type', 'is_enabled', 'webhook_url')->get();

        $seatCharacters = DB::table('character_infos')
            ->join('refresh_tokens', 'character_infos.character_id', '=', 'refresh_tokens.character_id')
            ->select(
                'character_infos.character_id',
                'character_infos.name',
                DB::raw("CASE WHEN refresh_tokens.scopes LIKE '%esi-mail.send_mail.v1%' THEN 1 ELSE 0 END as has_mail_scope")
            )
            ->orderBy('character_infos.name')
            ->get();

        // Get notification settings for default values
        $notificationSettings = $this->settingsService->getNotificationSettings();

        return view('mining-manager::diagnostic.index', compact('testDataCounts', 'webhooks', 'seatCharacters', 'notificationSettings'));
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

                        DB::table('mining_ledger')->updateOrInsert(
                            [
                                'character_id' => $character->character_id,
                                'date' => $date->format('Y-m-d'),
                                'type_id' => $ore['id'],
                                'observer_id' => null,
                            ],
                            [
                                'quantity' => $quantity,
                                'solar_system_id' => $solarSystem,
                                'processed_at' => $date,
                                'is_moon_ore' => $ore['is_moon_ore'] ?? false,
                                'created_at' => $date,
                                'updated_at' => $date,
                            ]
                        );

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

            // Check if Manager Core is installed when testing it
            if ($provider === 'manager-core') {
                if (!\MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled()) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Manager Core package is not installed. Install mattfalahe/manager-core to use this provider.',
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
                    'cache_duration' => ($pricingSettings['cache_duration'] ?? 240) . ' minutes',
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

            // Check if Manager Core is installed when testing it
            if ($provider === 'manager-core') {
                if (!\MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled()) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Manager Core package is not installed. Install mattfalahe/manager-core to use this provider.',
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
            $cacheDuration = (int) ($pricingSettings['cache_duration'] ?? 240);

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
     * Tax Trace — comprehensive diagnostic showing stored daily summaries,
     * live recalculation, account/bill info, and mismatch detection.
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

            // ================================================================
            // SECTION 1: Character & Account Info
            // ================================================================
            $character = DB::table('character_infos')
                ->where('character_id', $characterId)
                ->first();

            if (!$character) {
                $hasMiningData = DB::table('mining_ledger')
                    ->where('character_id', $characterId)
                    ->exists();

                if (!$hasMiningData) {
                    return response()->json([
                        'success' => false,
                        'error' => "Character {$characterId} not found in SeAT and has no mining data"
                    ], 404);
                }
            }

            $characterName = $character->name ?? "Character {$characterId} (not in SeAT)";

            // Corporation
            $affiliation = DB::table('character_affiliations')
                ->where('character_id', $characterId)
                ->first();
            $corporationId = $affiliation->corporation_id ?? null;
            $corporationName = $corporationId
                ? DB::table('corporation_infos')->where('corporation_id', $corporationId)->value('name')
                : null;

            // Main account mapping: character -> refresh_tokens -> users -> main_character_id
            $accountInfo = $this->resolveAccountInfo($characterId);

            // Tax bill for this month (uses main character ID since taxes are grouped by main)
            $billCharacterId = $accountInfo['main_character_id'] ?? $characterId;
            $taxBill = MiningTax::where('character_id', $billCharacterId)
                ->whereYear('month', $monthStart->year)
                ->whereMonth('month', $monthStart->month)
                ->first();

            $taxBillData = null;
            if ($taxBill) {
                $taxCode = $taxBill->taxCodes()->latest()->first();
                $taxBillData = [
                    'id' => $taxBill->id,
                    'character_id' => $taxBill->character_id,
                    'amount_owed' => (float) $taxBill->amount_owed,
                    'amount_paid' => (float) $taxBill->amount_paid,
                    'status' => $taxBill->status,
                    'due_date' => $taxBill->due_date ? $taxBill->due_date->format('Y-m-d') : null,
                    'paid_at' => $taxBill->paid_at ? $taxBill->paid_at->format('Y-m-d H:i') : null,
                    'tax_code' => $taxCode ? $taxCode->code : null,
                    'period_type' => $taxBill->period_type ?? 'monthly',
                ];
            }

            // ================================================================
            // SECTION 2: Daily Summaries (Stored Data)
            // ================================================================
            $dailySummaries = MiningLedgerDailySummary::where('character_id', $characterId)
                ->whereBetween('date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
                ->orderBy('date')
                ->get();

            $storedDays = [];
            $storedTotalValue = 0;
            $storedTotalTax = 0;
            $storedTotalQuantity = 0;
            $storedWarnings = [];

            foreach ($dailySummaries as $summary) {
                $oreEntries = $summary->ore_types ?? [];
                $dayWarnings = [];

                // Check for issues in ore entries
                foreach ($oreEntries as &$ore) {
                    $warnings = [];
                    if (($ore['unit_price'] ?? 0) == 0 && ($ore['quantity'] ?? 0) > 0) {
                        $warnings[] = 'Zero price — pricing may have failed';
                    }
                    if (($ore['is_taxable'] ?? false) && ($ore['effective_rate'] ?? 0) == 0) {
                        $warnings[] = 'Taxable ore with 0% effective rate';
                    }
                    if (($ore['total_value'] ?? 0) > 0 && ($ore['estimated_tax'] ?? 0) == 0 && ($ore['is_taxable'] ?? true)) {
                        $warnings[] = 'Has value but zero tax — check tax rate config';
                    }
                    $ore['warnings'] = $warnings;
                    if (!empty($warnings)) {
                        $dayWarnings = array_merge($dayWarnings, $warnings);
                    }
                }
                unset($ore);

                $dayValue = (float) $summary->total_value;
                $dayTax = (float) $summary->total_tax;

                $storedTotalValue += $dayValue;
                $storedTotalTax += $dayTax;
                $storedTotalQuantity += (float) $summary->total_quantity;

                $storedDays[] = [
                    'date' => $summary->date->format('Y-m-d'),
                    'total_quantity' => (float) $summary->total_quantity,
                    'total_value' => $dayValue,
                    'total_tax' => $dayTax,
                    'moon_ore_value' => (float) $summary->moon_ore_value,
                    'regular_ore_value' => (float) $summary->regular_ore_value,
                    'ice_value' => (float) $summary->ice_value,
                    'gas_value' => (float) $summary->gas_value,
                    'is_finalized' => $summary->is_finalized,
                    'ore_count' => count($oreEntries),
                    'ores' => $oreEntries,
                    'warnings' => $dayWarnings,
                ];

                if (!empty($dayWarnings)) {
                    $storedWarnings[] = [
                        'date' => $summary->date->format('Y-m-d'),
                        'issues' => $dayWarnings,
                    ];
                }
            }

            // ================================================================
            // SECTION 3: Live Recalculation (current prices/rates)
            // ================================================================
            $entries = DB::table('mining_ledger')
                ->where('character_id', $characterId)
                ->whereBetween('date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
                ->orderBy('date')
                ->get();

            $taxRates = $this->settingsService->getTaxRates();
            $pricingSettings = $this->settingsService->getPricingSettings();
            $generalSettings = $this->settingsService->getGeneralSettings();

            $liveResults = [];
            $liveTotalTax = 0;
            $liveTotalValue = 0;

            foreach ($entries as $entry) {
                $typeId = $entry->type_id;
                $quantity = $entry->quantity;

                $isMoonOre = TypeIdRegistry::isMoonOre($typeId);
                $isIce = TypeIdRegistry::isIce($typeId);
                $isGas = TypeIdRegistry::isGas($typeId);
                $isRegularOre = TypeIdRegistry::isRegularOre($typeId);
                $rarity = $isMoonOre ? TypeIdRegistry::getMoonOreRarity($typeId) : null;

                $category = 'unknown';
                if ($isMoonOre) $category = 'moon_ore';
                elseif ($isIce) $category = 'ice';
                elseif ($isGas) $category = 'gas';
                elseif ($isRegularOre) $category = 'ore';

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

                $oreValue = 0;
                $unitPrice = 0;
                $pricingError = null;
                try {
                    $unitPrice = $this->priceService->getPrice($typeId) ?? 0;
                    $oreValue = $unitPrice * $quantity;
                } catch (\Exception $e) {
                    $pricingError = $e->getMessage();
                }

                $taxAmount = round($oreValue * ($taxRate / 100), 2);
                $liveTotalTax += $taxAmount;
                $liveTotalValue += $oreValue;

                $typeName = DB::table('invTypes')
                    ->where('typeID', $typeId)
                    ->value('typeName') ?? "Type {$typeId}";

                $liveResults[] = [
                    'date' => $entry->date,
                    'type_id' => $typeId,
                    'type_name' => $typeName,
                    'quantity' => $quantity,
                    'category' => $category,
                    'rarity' => $rarity,
                    'tax_rate' => $taxRate,
                    'unit_price' => round($unitPrice, 2),
                    'total_value' => round($oreValue, 2),
                    'tax_amount' => $taxAmount,
                    'pricing_error' => $pricingError,
                ];
            }

            // ================================================================
            // SECTION 4: Mismatch Detection
            // ================================================================
            $mismatches = [];

            // Compare stored summary total vs live recalculation
            $storedVsLiveDiff = abs($storedTotalTax - $liveTotalTax);
            if ($storedVsLiveDiff > 1) {
                $mismatches[] = [
                    'type' => 'stored_vs_live',
                    'severity' => $storedVsLiveDiff > 1000000 ? 'high' : 'medium',
                    'message' => sprintf(
                        'Daily summary total tax (%.2f ISK) differs from live recalculation (%.2f ISK) by %.2f ISK — prices or rates may have changed since summaries were generated',
                        $storedTotalTax, $liveTotalTax, $storedVsLiveDiff
                    ),
                ];
            }

            // Compare stored total vs tax bill amount
            if ($taxBill) {
                // For the bill comparison, we need to check ALL characters under this account
                $allCharIds = $accountInfo['all_character_ids'] ?? [$characterId];
                $accountStoredTax = 0;

                if (count($allCharIds) > 1) {
                    // Sum daily summaries across all alts
                    $accountStoredTax = (float) MiningLedgerDailySummary::whereIn('character_id', $allCharIds)
                        ->whereBetween('date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
                        ->sum('total_tax');
                } else {
                    $accountStoredTax = $storedTotalTax;
                }

                $billDiff = abs($accountStoredTax - (float) $taxBill->amount_owed);
                if ($billDiff > 1) {
                    $mismatches[] = [
                        'type' => 'summary_vs_bill',
                        'severity' => $billDiff > 1000000 ? 'high' : 'medium',
                        'message' => sprintf(
                            'Account daily summaries total (%.2f ISK across %d characters) differs from tax bill amount (%.2f ISK) by %.2f ISK',
                            $accountStoredTax, count($allCharIds), (float) $taxBill->amount_owed, $billDiff
                        ),
                    ];
                }
            }

            // Check for days with mining ledger data but no daily summary
            $ledgerDates = $entries->pluck('date')->unique()->values()->toArray();
            $summaryDates = $dailySummaries->map(fn($s) => $s->date->format('Y-m-d'))->toArray();
            $missingSummaryDates = array_diff($ledgerDates, $summaryDates);
            if (!empty($missingSummaryDates)) {
                $mismatches[] = [
                    'type' => 'missing_summaries',
                    'severity' => 'high',
                    'message' => sprintf(
                        'Mining ledger has data for %d dates with no daily summary: %s — run calculate-taxes to regenerate',
                        count($missingSummaryDates),
                        implode(', ', array_slice($missingSummaryDates, 0, 5)) . (count($missingSummaryDates) > 5 ? '...' : '')
                    ),
                ];
            }

            // Check for zero-priced ores
            $zeroPricedCount = 0;
            foreach ($storedDays as $day) {
                foreach ($day['ores'] as $ore) {
                    if (($ore['unit_price'] ?? 0) == 0 && ($ore['quantity'] ?? 0) > 0) {
                        $zeroPricedCount++;
                    }
                }
            }
            if ($zeroPricedCount > 0) {
                $mismatches[] = [
                    'type' => 'zero_prices',
                    'severity' => 'medium',
                    'message' => sprintf(
                        '%d ore entries in daily summaries have zero unit price — pricing provider may have failed for these items',
                        $zeroPricedCount
                    ),
                ];
            }

            return response()->json([
                'success' => true,

                // Section 1: Character & Account
                'character' => [
                    'id' => $characterId,
                    'name' => $characterName,
                    'corporation_id' => $corporationId,
                    'corporation_name' => $corporationName ?? 'Unknown (guest miner)',
                ],
                'account' => $accountInfo,
                'tax_bill' => $taxBillData,

                // Section 2: Stored Daily Summaries
                'period' => [
                    'month' => $month,
                    'start' => $monthStart->format('Y-m-d'),
                    'end' => $monthEnd->format('Y-m-d'),
                ],
                'stored_summaries' => [
                    'days' => $storedDays,
                    'totals' => [
                        'total_quantity' => round($storedTotalQuantity, 2),
                        'total_value' => round($storedTotalValue, 2),
                        'total_tax' => round($storedTotalTax, 2),
                        'effective_rate' => $storedTotalValue > 0 ? round(($storedTotalTax / $storedTotalValue) * 100, 2) : 0,
                        'days_with_data' => count($storedDays),
                    ],
                    'warnings' => $storedWarnings,
                ],

                // Section 3: Live Recalculation
                'live_recalculation' => [
                    'settings_used' => [
                        'price_provider' => $pricingSettings['price_provider'] ?? 'seat',
                        'valuation_method' => $generalSettings['ore_valuation_method'] ?? 'mineral_price',
                        'refining_efficiency' => $pricingSettings['refining_efficiency'] ?? 87.5,
                    ],
                    'tax_rates' => $taxRates,
                    'totals' => [
                        'total_entries' => count($entries),
                        'total_value' => round($liveTotalValue, 2),
                        'total_tax' => round($liveTotalTax, 2),
                        'effective_rate' => $liveTotalValue > 0 ? round(($liveTotalTax / $liveTotalValue) * 100, 2) : 0,
                    ],
                    'entries' => $liveResults,
                ],

                // Section 4: Mismatches
                'mismatches' => $mismatches,
            ]);

        } catch (\Exception $e) {
            Log::error('Tax diagnostic failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolve account info: main character, all alts under the same SeAT user account.
     *
     * @param int $characterId
     * @return array
     */
    private function resolveAccountInfo(int $characterId): array
    {
        // Find user_id from refresh_tokens
        $userId = DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->value('user_id');

        if (!$userId) {
            return [
                'main_character_id' => null,
                'main_character_name' => null,
                'all_character_ids' => [$characterId],
                'all_characters' => [],
                'is_registered' => false,
            ];
        }

        // Get main character from users table
        $mainCharacterId = DB::table('users')
            ->where('id', $userId)
            ->value('main_character_id');

        $mainCharacterName = $mainCharacterId
            ? DB::table('character_infos')->where('character_id', $mainCharacterId)->value('name')
            : null;

        // Get all characters under this user account
        $allCharacterIds = DB::table('refresh_tokens')
            ->where('user_id', $userId)
            ->pluck('character_id')
            ->toArray();

        $allCharacters = DB::table('character_infos')
            ->whereIn('character_id', $allCharacterIds)
            ->get(['character_id', 'name'])
            ->map(fn($c) => [
                'character_id' => $c->character_id,
                'name' => $c->name,
                'is_main' => $c->character_id == $mainCharacterId,
            ])
            ->toArray();

        return [
            'main_character_id' => $mainCharacterId,
            'main_character_name' => $mainCharacterName,
            'all_character_ids' => $allCharacterIds,
            'all_characters' => $allCharacters,
            'is_registered' => true,
        ];
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

    /**
     * System Status diagnostic — daily summaries, multi-corp, scheduled jobs.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function systemStatus()
    {
        $results = [];

        // ── 1. Daily Summary Status ──────────────────────────────────
        try {
            $summaryStats = [];
            $summaryStats['total'] = DB::table('mining_ledger_daily_summaries')->count();
            $summaryStats['today'] = DB::table('mining_ledger_daily_summaries')
                ->whereDate('date', Carbon::today())->count();
            $summaryStats['yesterday'] = DB::table('mining_ledger_daily_summaries')
                ->whereDate('date', Carbon::yesterday())->count();

            // Characters with mining data today but no daily summary
            $minersToday = DB::table('mining_ledger')
                ->whereDate('date', Carbon::today())
                ->whereNotNull('processed_at')
                ->distinct()->pluck('character_id')->toArray();
            $summariesToday = DB::table('mining_ledger_daily_summaries')
                ->whereDate('date', Carbon::today())
                ->distinct()->pluck('character_id')->toArray();
            $summaryStats['missing_today'] = count(array_diff($minersToday, $summariesToday));
            $summaryStats['miners_today'] = count($minersToday);

            // Latest summary timestamp
            $latest = DB::table('mining_ledger_daily_summaries')
                ->orderByDesc('updated_at')->value('updated_at');
            $summaryStats['last_updated'] = $latest;
            $summaryStats['last_updated_ago'] = $latest ? Carbon::parse($latest)->diffForHumans() : 'never';

            // Finalized months count
            $summaryStats['finalized_months'] = DB::table('mining_ledger_monthly_summaries')
                ->where('is_finalized', true)->count();

            $summaryStats['status'] = $summaryStats['missing_today'] === 0 ? 'healthy' : 'warning';
            $results['daily_summaries'] = $summaryStats;
        } catch (\Exception $e) {
            $results['daily_summaries'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // ── 2. Multi-Corporation Settings ────────────────────────────
        try {
            $corpStats = [];
            $allCorps = $this->settingsService->getAllCorporations();
            $corpStats['configured_corporations'] = $allCorps->count();

            $moonOwner = $this->settingsService->getSetting('general.moon_owner_corporation_id');
            $corpStats['moon_owner_corporation_id'] = $moonOwner;

            // Check each configured corp has tax rates
            $corpDetails = [];
            foreach ($allCorps as $corp) {
                $corpId = $corp->corporation_id;
                $this->settingsService->setActiveCorporation((int) $corpId);
                $taxRates = $this->settingsService->getTaxRatesForCorporation($corpId);
                $taxSelector = $this->settingsService->getTaxSelector();

                $corpDetails[] = [
                    'corporation_id' => $corpId,
                    'has_ore_rate' => isset($taxRates['ore']) && $taxRates['ore'] > 0,
                    'has_moon_rates' => isset($taxRates['moon_ore']) && !empty($taxRates['moon_ore']),
                    'ore_taxed' => $taxSelector['ore'] ?? true,
                    'ice_taxed' => $taxSelector['ice'] ?? true,
                    'gas_taxed' => $taxSelector['gas'] ?? false,
                    'ore_rate' => $taxRates['ore'] ?? 0,
                ];
            }
            // Reset to global context
            $this->settingsService->setActiveCorporation(null);

            $corpStats['corporation_details'] = $corpDetails;
            $corpStats['status'] = $allCorps->count() > 0 || $moonOwner ? 'healthy' : 'warning';
            $results['multi_corp'] = $corpStats;
        } catch (\Exception $e) {
            $results['multi_corp'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // ── 3. Price Cache Freshness ─────────────────────────────────
        try {
            $pricingSettings = $this->settingsService->getPricingSettings();
            $cacheDuration = (int) ($pricingSettings['cache_duration'] ?? 240);

            $totalCached = MiningPriceCache::count();
            $freshCount = MiningPriceCache::where('cached_at', '>=', now()->subMinutes($cacheDuration))->count();
            $staleCount = $totalCached - $freshCount;

            $results['price_cache'] = [
                'total_cached' => $totalCached,
                'fresh' => $freshCount,
                'stale' => $staleCount,
                'cache_duration_minutes' => $cacheDuration,
                'status' => $staleCount === 0 && $totalCached > 0 ? 'healthy'
                    : ($totalCached === 0 ? 'critical' : 'warning'),
                'provider' => $pricingSettings['price_provider'] ?? 'seat',
            ];

            // Add Manager Core external cache info when it's the active provider
            if (($pricingSettings['price_provider'] ?? 'seat') === 'manager-core'
                && \MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled()) {
                try {
                    $mcMarket = $pricingSettings['manager_core_market'] ?? 'jita';
                    $mcTotal = DB::table('manager_core_market_prices')->where('market', $mcMarket)->count();
                    $mcLastUpdate = DB::table('manager_core_market_prices')
                        ->where('market', $mcMarket)
                        ->max('updated_at');

                    $results['price_cache']['manager_core'] = [
                        'market' => $mcMarket,
                        'total_prices' => $mcTotal,
                        'last_updated' => $mcLastUpdate,
                        'last_updated_ago' => $mcLastUpdate ? \Carbon\Carbon::parse($mcLastUpdate)->diffForHumans() : 'never',
                    ];
                } catch (\Exception $mcEx) {
                    $results['price_cache']['manager_core'] = ['error' => $mcEx->getMessage()];
                }
            }
        } catch (\Exception $e) {
            $results['price_cache'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // ── 4. Scheduled Jobs Last-Run ───────────────────────────────
        try {
            $jobChecks = [
                'process-ledger' => ['table' => 'mining_ledger', 'column' => 'processed_at'],
                'update-daily-summaries' => ['table' => 'mining_ledger_daily_summaries', 'column' => 'updated_at'],
                'calculate-taxes' => ['table' => 'mining_taxes', 'column' => 'updated_at'],
                'cache-prices' => ['table' => 'mining_price_cache', 'column' => 'cached_at'],
            ];

            $jobStatus = [];
            foreach ($jobChecks as $jobName => $check) {
                try {
                    if (Schema::hasTable($check['table'])) {
                        $lastRun = DB::table($check['table'])->max($check['column']);
                        $jobStatus[$jobName] = [
                            'last_activity' => $lastRun,
                            'ago' => $lastRun ? Carbon::parse($lastRun)->diffForHumans() : 'never',
                            'status' => $lastRun && Carbon::parse($lastRun)->isAfter(now()->subHours(25))
                                ? 'healthy' : 'warning',
                        ];
                    } else {
                        $jobStatus[$jobName] = ['status' => 'error', 'error' => "Table {$check['table']} missing"];
                    }
                } catch (\Exception $e) {
                    $jobStatus[$jobName] = ['status' => 'error', 'error' => $e->getMessage()];
                }
            }

            // Check failed_jobs table for any mining-manager failures
            $failedJobs = 0;
            if (Schema::hasTable('failed_jobs')) {
                $failedJobs = DB::table('failed_jobs')
                    ->where('payload', 'like', '%mining-manager%')
                    ->where('failed_at', '>=', now()->subDays(7))
                    ->count();
            }
            $jobStatus['failed_jobs_7d'] = $failedJobs;

            $results['scheduled_jobs'] = $jobStatus;
        } catch (\Exception $e) {
            $results['scheduled_jobs'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // ── 5. Data Counts Overview ──────────────────────────────────
        try {
            $results['data_counts'] = [
                'mining_ledger' => DB::table('mining_ledger')->count(),
                'mining_taxes' => DB::table('mining_taxes')->count(),
                'daily_summaries' => DB::table('mining_ledger_daily_summaries')->count(),
                'monthly_summaries' => DB::table('mining_ledger_monthly_summaries')->count(),
                'price_cache' => MiningPriceCache::count(),
            ];
        } catch (\Exception $e) {
            $results['data_counts'] = ['error' => $e->getMessage()];
        }

        return response()->json($results);
    }

    // ========================================================================
    // NOTIFICATION TESTING
    // ========================================================================

    /**
     * Test notification pipeline with detailed step-by-step logging
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testNotification(Request $request)
    {
        $logs = [];
        $summary = ['channels_tested' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $startTime = microtime(true);

        $this->addLog($logs, 'info', '=== Notification Test Started ===');

        // 1. Parse inputs
        $notificationType = $request->input('notification_type', 'tax_reminder');
        $channels = $request->input('channels', []);
        $characterId = (int) $request->input('character_id', 0);
        $characterName = $request->input('character_name', 'Test Character');
        $webhookId = $request->input('webhook_id');
        $customWebhookUrl = $request->input('custom_webhook_url');
        $customSlackUrl = $request->input('custom_slack_url');

        // Sender settings for EVE Mail
        $senderMode = $request->input('sender_mode', 'settings'); // settings or character
        $senderCharacterId = (int) $request->input('sender_character_id', 0);

        // Discord ping test override
        $testPing = (bool) $request->input('test_ping', false);

        if (empty($channels)) {
            $this->addLog($logs, 'error', 'No channels selected. Please select at least one channel.');
            return response()->json([
                'success' => false,
                'logs' => $logs,
                'summary' => $summary,
            ]);
        }

        $typeLabels = [
            'tax_generated' => 'Mining Taxes Summary (General)',
            'tax_announcement' => 'New Invoices Announcement (General)',
            'tax_reminder' => 'Tax Payment Reminder (Individual)',
            'tax_invoice' => 'Tax Invoice Created (Individual)',
            'tax_overdue' => 'Tax Payment Overdue (Individual)',
            'event_created' => 'Mining Event Created',
            'event_started' => 'Mining Event Started',
            'event_completed' => 'Mining Event Completed',
            'moon_ready' => 'Moon Extraction Ready',
            'jackpot_detected' => 'Jackpot Detected',
            'theft_detected' => 'Theft Detected',
            'critical_theft' => 'Critical Theft',
            'active_theft' => 'Active Theft in Progress',
            'incident_resolved' => 'Incident Resolved',
            'report_generated' => 'Report Generated',
        ];

        $this->addLog($logs, 'info', 'Notification Type: ' . ($typeLabels[$notificationType] ?? $notificationType));
        $this->addLog($logs, 'info', 'Channels: ' . implode(', ', array_map('strtoupper', $channels)));

        // 2. Resolve character
        if ($characterId > 0) {
            $charInfo = DB::table('character_infos')->where('character_id', $characterId)->first();
            if ($charInfo) {
                $characterName = $charInfo->name;
                $this->addLog($logs, 'ok', "Target character: {$characterName} ({$characterId})");
            } else {
                $this->addLog($logs, 'warn', "Character ID {$characterId} not found in database, using name: {$characterName}");
            }
        } else {
            $characterId = 123456789;
            $this->addLog($logs, 'info', "Using test character: {$characterName} ({$characterId})");
        }

        // 3. Build test data based on type
        $testData = $this->buildTestNotificationData($request, $notificationType, $characterId, $characterName);
        $this->addLog($logs, 'info', 'Test data prepared: ' . json_encode(array_intersect_key($testData, array_flip(['formatted_amount', 'due_date', 'event_name', 'structure_id']))));

        // 4. Get notification settings
        $notificationSettings = $this->settingsService->getNotificationSettings();
        $this->addLog($logs, 'info', 'Loaded notification settings from database');

        // 5. Process each channel
        foreach ($channels as $channel) {
            $this->addLog($logs, 'info', '');
            $this->addLog($logs, 'info', "--- Testing " . strtoupper($channel) . " Channel ---");
            $summary['channels_tested']++;

            try {
                switch ($channel) {
                    case 'esi':
                        $this->testEsiChannel($logs, $summary, $notificationType, $testData, $characterId, $notificationSettings, $senderMode, $senderCharacterId);
                        break;

                    case 'discord':
                        $this->testDiscordChannel($logs, $summary, $notificationType, $testData, $characterId, $characterName, $webhookId, $customWebhookUrl, $notificationSettings, $testPing);
                        break;

                    case 'slack':
                        $this->testSlackChannel($logs, $summary, $notificationType, $testData, $customSlackUrl, $notificationSettings);
                        break;

                    default:
                        $this->addLog($logs, 'error', "Unknown channel: {$channel}");
                        $summary['failed']++;
                }
            } catch (\Exception $e) {
                $this->addLog($logs, 'error', "Exception in {$channel} channel: " . $e->getMessage());
                $summary['failed']++;
            }
        }

        // 6. Summary
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->addLog($logs, 'info', '');
        $this->addLog($logs, 'info', "=== Test Complete ({$duration}ms) ===");
        $this->addLog($logs, 'info', "Channels: {$summary['channels_tested']} | Sent: {$summary['sent']} | Failed: {$summary['failed']} | Skipped: {$summary['skipped']}");

        return response()->json([
            'success' => $summary['failed'] === 0,
            'logs' => $logs,
            'summary' => $summary,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Test EVE Mail (ESI) channel — dry run only
     */
    protected function testEsiChannel(array &$logs, array &$summary, string $type, array $data, int $characterId, array $settings, string $senderMode = 'settings', int $senderCharacterId = 0): void
    {
        // Check if EVE mail is enabled
        if (!($settings['evemail_enabled'] ?? false)) {
            $this->addLog($logs, 'warn', 'EVE Mail is disabled in settings (testing anyway)');
        } else {
            $this->addLog($logs, 'ok', 'EVE Mail is enabled');
        }

        // Check type filter
        $typeKey = str_replace('TYPE_', '', $type);
        $evemailTypes = $settings['evemail_types'] ?? [];
        if (!($evemailTypes[$typeKey] ?? true)) {
            $this->addLog($logs, 'warn', "Notification type '{$type}' is disabled for EVE Mail (testing anyway)");
        } else {
            $this->addLog($logs, 'ok', "Notification type '{$type}' is enabled for EVE Mail");
        }

        // Resolve sender based on mode
        $senderId = null;

        switch ($senderMode) {
            case 'character':
                // Use the character specified in the test form
                if ($senderCharacterId > 0) {
                    $senderId = $senderCharacterId;
                    $this->addLog($logs, 'info', "Sender mode: Character (from test form)");
                } else {
                    $this->addLog($logs, 'error', 'Sender mode is "Character" but no character selected.');
                    $summary['failed']++;
                    return;
                }
                break;

            default: // 'settings' - use configured sender from notification settings
                $this->addLog($logs, 'info', 'Sender mode: From Settings');

                $senderOverride = $settings['evemail_sender_character_override'] ?? null;
                $senderDropdown = $settings['evemail_sender_character_id'] ?? null;
                $senderId = $senderOverride ?: $senderDropdown;

                if (!$senderId) {
                    $this->addLog($logs, 'error', 'No sender character configured. Set one in Settings > Notifications.');
                    $summary['failed']++;
                    return;
                }

                $this->addLog($logs, 'info', 'Using sender from settings' . ($senderOverride ? ' [manual override]' : ' [dropdown]'));
                break;
        }

        $senderInfo = DB::table('character_infos')->where('character_id', $senderId)->first();
        $senderName = $senderInfo ? $senderInfo->name : "Unknown (ID: {$senderId})";
        $this->addLog($logs, 'info', "Sender character: {$senderName} ({$senderId})");

        // Check token
        $token = DB::table('refresh_tokens')
            ->where('character_id', $senderId)
            ->first();

        if (!$token) {
            $this->addLog($logs, 'error', "No refresh token found for sender character {$senderId}");
            $summary['failed']++;
            return;
        }

        $hasMailScope = $token->scopes && str_contains($token->scopes, 'esi-mail.send_mail.v1');
        if (!$hasMailScope) {
            $this->addLog($logs, 'error', "Sender character {$senderName} does not have esi-mail.send_mail.v1 scope");
            $summary['failed']++;
            return;
        }
        $this->addLog($logs, 'ok', 'Sender has valid token with mail scope');

        // Format message
        $notificationService = app(NotificationService::class);
        $typeConst = match ($type) {
            'tax_generated' => NotificationService::TYPE_TAX_GENERATED,
            'tax_announcement' => NotificationService::TYPE_TAX_ANNOUNCEMENT,
            'tax_reminder' => NotificationService::TYPE_TAX_REMINDER,
            'tax_invoice' => NotificationService::TYPE_TAX_INVOICE,
            'tax_overdue' => NotificationService::TYPE_TAX_OVERDUE,
            'event_created' => NotificationService::TYPE_EVENT_CREATED,
            'event_started' => NotificationService::TYPE_EVENT_STARTED,
            'event_completed' => NotificationService::TYPE_EVENT_COMPLETED,
            'moon_ready' => NotificationService::TYPE_MOON_READY,
            default => NotificationService::TYPE_CUSTOM,
        };

        try {
            // Use reflection to call the protected formatMessageForESI
            $reflection = new \ReflectionMethod($notificationService, 'formatMessageForESI');
            $reflection->setAccessible(true);
            $message = $reflection->invoke($notificationService, $typeConst, $data);

            $this->addLog($logs, 'info', "Subject: {$message['subject']}");
            $this->addLog($logs, 'info', 'Body length: ' . strlen($message['body']) . ' characters');
            $this->addLog($logs, 'info', "Would send FROM {$senderName} TO character {$characterId}");
        } catch (\Exception $e) {
            $this->addLog($logs, 'warn', 'Could not format ESI message: ' . $e->getMessage());
        }

        $this->addLog($logs, 'ok', 'EVE Mail test completed (DRY RUN — no ESI call made)');
        $this->addLog($logs, 'info', 'To avoid ESI rate limits, mail was not actually sent. Pipeline validated successfully.');
        $summary['sent']++;
    }

    /**
     * Test Discord channel — sends real webhook
     */
    protected function testDiscordChannel(array &$logs, array &$summary, string $type, array $data, int $characterId, string $characterName, $webhookId, $customWebhookUrl, array $settings, bool $testPing = false): void
    {
        // Resolve webhook URL
        $webhookUrl = null;
        $webhookName = 'Custom URL';

        if ($customWebhookUrl) {
            $webhookUrl = $customWebhookUrl;
            $this->addLog($logs, 'info', 'Using custom webhook URL');
        } elseif ($webhookId) {
            $webhook = WebhookConfiguration::find($webhookId);
            if (!$webhook) {
                $this->addLog($logs, 'error', "Webhook ID {$webhookId} not found");
                $summary['failed']++;
                return;
            }
            $webhookUrl = $webhook->webhook_url;
            $webhookName = $webhook->name;
            $this->addLog($logs, 'ok', "Using webhook: {$webhookName} (ID: {$webhookId})");
        } else {
            // Try to find any enabled Discord webhook
            $webhook = WebhookConfiguration::enabled()->where('type', 'discord')->first();
            if ($webhook) {
                $webhookUrl = $webhook->webhook_url;
                $webhookName = $webhook->name;
                $this->addLog($logs, 'info', "Auto-selected webhook: {$webhookName}");
            } else {
                $this->addLog($logs, 'error', 'No Discord webhook available. Select one or provide a custom URL.');
                $summary['failed']++;
                return;
            }
        }

        // Format Discord embed — use WebhookService for moon/theft/report types (matches production), NotificationService for others
        $isMoonType = in_array($type, ['moon_ready', 'jackpot_detected']);
        $isTheftType = in_array($type, ['theft_detected', 'critical_theft', 'active_theft', 'incident_resolved']);
        $isReportType = $type === 'report_generated';
        $message = null;

        if ($isTheftType) {
            // Theft notifications use WebhookService with TheftIncident model in production
            try {
                $webhookService = app(\MiningManager\Services\Notification\WebhookService::class);
                $testIncident = new \MiningManager\Models\TheftIncident([
                    'character_id' => $data['character_id'] ?? 123456789,
                    'character_name' => $data['character_name'] ?? 'Test Miner',
                    'severity' => $data['severity'] ?? 'medium',
                    'ore_value' => $data['ore_value'] ?? 50000000,
                    'tax_owed' => $data['tax_owed'] ?? 5000000,
                    'status' => $type === 'incident_resolved' ? 'resolved' : 'open',
                    'detected_at' => now(),
                ]);
                if ($type === 'active_theft') {
                    $testIncident->activity_count = $data['activity_count'] ?? 3;
                    $testIncident->last_activity_at = now();
                }
                $additionalData = array_intersect_key($data, array_flip([
                    'incident_url', 'test_mode', 'new_mining_value', 'last_activity', 'resolved_by',
                ]));
                $reflection = new \ReflectionMethod($webhookService, 'buildDiscordEmbed');
                $reflection->setAccessible(true);
                $embed = $reflection->invoke($webhookService, $testIncident, $type, $additionalData);
                $message = ['embeds' => [$embed]];

                // Add role mention
                $roleReflection = new \ReflectionMethod($webhookService, 'getDiscordRoleMention');
                $roleReflection->setAccessible(true);
                if ($webhook) {
                    $roleMention = $roleReflection->invoke($webhookService, $type, $webhook);
                    if ($roleMention) {
                        $message['content'] = $roleMention;
                    }
                }

                $this->addLog($logs, 'ok', 'Discord theft embed formatted via WebhookService: ' . ($embed['title'] ?? 'N/A'));
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format theft Discord embed: ' . $e->getMessage());
            }
        } elseif ($isReportType) {
            // Report notifications use WebhookService with MiningReport model in production
            try {
                $webhookService = app(\MiningManager\Services\Notification\WebhookService::class);
                $reflection = new \ReflectionMethod($webhookService, 'buildReportDiscordEmbed');
                $reflection->setAccessible(true);
                $testReport = new \MiningManager\Models\MiningReport();
                $testReport->report_type = $data['report_type'] ?? 'monthly';
                $testReport->id = 99999;
                $embed = $reflection->invoke($webhookService, $testReport, $data);
                $message = ['embeds' => [$embed]];
                $this->addLog($logs, 'ok', 'Discord report embed formatted via WebhookService: ' . ($embed['title'] ?? 'N/A'));
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format report Discord embed: ' . $e->getMessage());
            }
        } elseif ($isMoonType) {
            // Moon notifications use WebhookService in production
            try {
                $webhookService = app(\MiningManager\Services\Notification\WebhookService::class);
                $reflection = new \ReflectionMethod($webhookService, 'buildMoonDiscordEmbed');
                $reflection->setAccessible(true);
                $moonEventType = $type === 'jackpot_detected' ? 'jackpot_detected' : 'moon_arrival';
                $embed = $reflection->invoke($webhookService, $moonEventType, $data);
                $message = ['embeds' => [$embed]];
                $this->addLog($logs, 'ok', 'Discord moon embed formatted via WebhookService: ' . ($embed['title'] ?? 'N/A'));
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format moon Discord embed: ' . $e->getMessage());
            }
        }

        if (!$message) {
            // Tax/event notifications use NotificationService
            $notificationService = app(NotificationService::class);
            $typeConst = match ($type) {
                'tax_generated' => NotificationService::TYPE_TAX_GENERATED,
                'tax_announcement' => NotificationService::TYPE_TAX_ANNOUNCEMENT,
                'tax_reminder' => NotificationService::TYPE_TAX_REMINDER,
                'tax_invoice' => NotificationService::TYPE_TAX_INVOICE,
                'tax_overdue' => NotificationService::TYPE_TAX_OVERDUE,
                'event_created' => NotificationService::TYPE_EVENT_CREATED,
                'event_started' => NotificationService::TYPE_EVENT_STARTED,
                'event_completed' => NotificationService::TYPE_EVENT_COMPLETED,
                'moon_ready' => NotificationService::TYPE_MOON_READY,
                default => NotificationService::TYPE_CUSTOM,
            };

            try {
                $reflection = new \ReflectionMethod($notificationService, 'formatMessageForDiscord');
                $reflection->setAccessible(true);
                $message = $reflection->invoke($notificationService, $typeConst, $data);
                $this->addLog($logs, 'ok', 'Discord embed formatted: ' . ($message['embeds'][0]['title'] ?? 'N/A'));
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format Discord embed: ' . $e->getMessage());
                $message = [
                    'embeds' => [[
                        'title' => 'Test Notification',
                        'description' => 'Test notification from Mining Manager diagnostic tools.',
                        'color' => 3447003,
                        'timestamp' => now()->toIso8601String(),
                        'footer' => ['text' => 'Mining Manager - Test'],
                    ]]
                ];
            }
        }

        // Check Discord pinging (tax types only — moon notifications don't ping individuals)
        $isTaxType = in_array($type, ['tax_reminder', 'tax_invoice', 'tax_overdue']);
        $pingingEnabled = $settings['discord_pinging_enabled'] ?? false;
        $doPing = ($testPing || $pingingEnabled) && $isTaxType;

        if ($doPing) {
            if ($testPing && !$pingingEnabled) {
                $this->addLog($logs, 'info', 'Discord pinging is disabled in settings, but testing anyway (test override)');
            } else {
                $this->addLog($logs, 'info', 'Discord pinging is enabled, checking seat-connector...');
            }

            if (Schema::hasTable('seat_connector_users')) {
                try {
                    // Try to resolve via seat-connector
                    $discordId = DB::table('seat_connector_users')
                        ->where('connector_type', 'discord')
                        ->where('character_id', $characterId)
                        ->value('connector_id');

                    if (!$discordId) {
                        // Try via user_id lookup
                        $userId = DB::table('refresh_tokens')
                            ->where('character_id', $characterId)
                            ->value('user_id');

                        if ($userId) {
                            $discordId = DB::table('seat_connector_users')
                                ->where('connector_type', 'discord')
                                ->where('user_id', $userId)
                                ->value('connector_id');
                        }
                    }

                    if ($discordId) {
                        $this->addLog($logs, 'ok', "Resolved Discord user ID: {$discordId}");
                        $showAmount = $settings['discord_ping_show_amount'] ?? true;
                        $mention = "<@{$discordId}>";

                        // Use production-matching action messages
                        $action = match ($type) {
                            'tax_reminder' => 'You have a tax payment coming due.',
                            'tax_invoice' => 'A new tax invoice has been created for you.',
                            'tax_overdue' => 'Your tax payment is overdue!',
                            default => 'You have a pending tax notification.',
                        };

                        if ($showAmount && isset($data['formatted_amount']) && ($data['show_amount'] ?? true)) {
                            $message['content'] = "{$mention} — {$action} Amount: {$data['formatted_amount']}";
                        } else {
                            $message['content'] = "{$mention} — {$action}";
                        }
                        $this->addLog($logs, 'ok', 'Ping content added to message');
                    } else {
                        $this->addLog($logs, 'warn', "Could not resolve Discord user ID for character {$characterId} — no seat-connector mapping found");
                    }
                } catch (\Exception $e) {
                    $this->addLog($logs, 'warn', 'Pinging resolution failed: ' . $e->getMessage());
                }
            } else {
                $this->addLog($logs, 'skip', 'seat-connector not installed (seat_connector_users table not found), pinging skipped');
            }
        } elseif ($isTaxType) {
            $this->addLog($logs, 'skip', 'Discord pinging is disabled (enable in settings or check "Test Discord Ping")');
        }

        // Send to Discord
        $this->addLog($logs, 'info', 'Sending to Discord webhook...');
        try {
            $response = Http::timeout(10)->post($webhookUrl, $message);

            if ($response->successful() || $response->status() === 204) {
                $this->addLog($logs, 'ok', "Discord webhook responded: HTTP {$response->status()} — Notification delivered!");
                $summary['sent']++;
            } else {
                $this->addLog($logs, 'error', "Discord webhook returned HTTP {$response->status()}: {$response->body()}");
                $summary['failed']++;
            }
        } catch (\Exception $e) {
            $this->addLog($logs, 'error', 'Discord request failed: ' . $e->getMessage());
            $summary['failed']++;
        }
    }

    /**
     * Test Slack channel — sends real webhook
     */
    protected function testSlackChannel(array &$logs, array &$summary, string $type, array $data, $customSlackUrl, array $settings): void
    {
        // Resolve webhook URL
        $webhookUrl = $customSlackUrl ?: ($settings['slack_webhook_url'] ?? '');

        if (!$webhookUrl) {
            $this->addLog($logs, 'error', 'No Slack webhook URL configured. Set one in Settings > Notifications or provide a custom URL.');
            $summary['failed']++;
            return;
        }

        if ($customSlackUrl) {
            $this->addLog($logs, 'info', 'Using custom Slack webhook URL');
        } else {
            $this->addLog($logs, 'ok', 'Using configured Slack webhook URL');
        }

        // Check if Slack is enabled (skip check if using custom URL)
        if (!$customSlackUrl && !($settings['slack_enabled'] ?? false)) {
            $this->addLog($logs, 'warn', 'Slack is disabled in settings, but testing anyway with configured URL');
        }

        // Check type filter
        $slackTypes = $settings['slack_types'] ?? [];
        $typeKey = str_replace('TYPE_', '', $type);
        if (!($slackTypes[$typeKey] ?? true)) {
            $this->addLog($logs, 'warn', "Notification type '{$type}' is disabled for Slack, but sending test anyway");
        }

        // Format message — use WebhookService for moon/theft/report types (matches production), NotificationService for others
        $isMoonType = in_array($type, ['moon_ready', 'jackpot_detected']);
        $isTheftType = in_array($type, ['theft_detected', 'critical_theft', 'active_theft', 'incident_resolved']);
        $isReportType = $type === 'report_generated';
        $message = null;

        if ($isTheftType) {
            // Theft notifications use WebhookService with TheftIncident model in production
            try {
                $webhookService = app(\MiningManager\Services\Notification\WebhookService::class);
                $testIncident = new \MiningManager\Models\TheftIncident([
                    'character_id' => $data['character_id'] ?? 123456789,
                    'character_name' => $data['character_name'] ?? 'Test Miner',
                    'severity' => $data['severity'] ?? 'medium',
                    'ore_value' => $data['ore_value'] ?? 50000000,
                    'tax_owed' => $data['tax_owed'] ?? 5000000,
                    'status' => $type === 'incident_resolved' ? 'resolved' : 'open',
                    'detected_at' => now(),
                ]);
                if ($type === 'active_theft') {
                    $testIncident->activity_count = $data['activity_count'] ?? 3;
                    $testIncident->last_activity_at = now();
                }
                $additionalData = array_intersect_key($data, array_flip([
                    'incident_url', 'test_mode', 'new_mining_value', 'last_activity', 'resolved_by',
                ]));
                $reflection = new \ReflectionMethod($webhookService, 'buildSlackBlocks');
                $reflection->setAccessible(true);
                $blocks = $reflection->invoke($webhookService, $testIncident, $type, $additionalData);

                $titleReflection = new \ReflectionMethod($webhookService, 'getTitleForEventType');
                $titleReflection->setAccessible(true);
                $title = $titleReflection->invoke($webhookService, $type);

                $message = ['text' => $title, 'blocks' => $blocks];
                $this->addLog($logs, 'ok', "Slack theft message formatted via WebhookService: {$title}");
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format theft Slack message: ' . $e->getMessage());
            }
        } elseif ($isReportType) {
            // Report notifications use WebhookService in production
            try {
                $webhookService = app(\MiningManager\Services\Notification\WebhookService::class);
                $titleReflection = new \ReflectionMethod($webhookService, 'getTitleForEventType');
                $titleReflection->setAccessible(true);
                $title = $titleReflection->invoke($webhookService, 'report_generated');

                $summary_data = $data['summary'] ?? [];
                $taxes = $data['taxes'] ?? [];
                $period = $data['period'] ?? [];
                $periodStr = isset($period['start'], $period['end']) ? "{$period['start']} to {$period['end']}" : 'N/A';

                $fields = [
                    ['type' => 'mrkdwn', 'text' => "*Report Type:*\n" . ucfirst($data['report_type'] ?? 'monthly')],
                    ['type' => 'mrkdwn', 'text' => "*Period:*\n{$periodStr}"],
                    ['type' => 'mrkdwn', 'text' => "*Total Miners:*\n" . number_format($summary_data['unique_miners'] ?? 0)],
                    ['type' => 'mrkdwn', 'text' => "*Total Value Mined:*\n" . number_format($summary_data['total_value'] ?? 0, 0) . ' ISK'],
                ];

                $message = [
                    'text' => $title,
                    'blocks' => [
                        ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $title]],
                        ['type' => 'section', 'fields' => $fields],
                    ],
                ];
                $this->addLog($logs, 'ok', "Slack report message formatted: {$title}");
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format report Slack message: ' . $e->getMessage());
            }
        } elseif ($isMoonType) {
            // Build Slack moon payload matching production format from WebhookService::sendMoonToSlack
            $moonEventType = $type === 'jackpot_detected' ? 'jackpot_detected' : 'moon_arrival';
            $fields = [];
            if (isset($data['moon_name'])) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Moon:*\n{$data['moon_name']}"];
            }
            if (isset($data['structure_name'])) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*Structure:*\n{$data['structure_name']}"];
            }

            if ($moonEventType === 'jackpot_detected') {
                if (isset($data['detected_by'])) {
                    $fields[] = ['type' => 'mrkdwn', 'text' => "*Detected By:*\n{$data['detected_by']}"];
                }
                if (isset($data['jackpot_percentage'])) {
                    $fields[] = ['type' => 'mrkdwn', 'text' => "*Jackpot:*\n{$data['jackpot_percentage']}% +100% ores"];
                }
            } else {
                if (isset($data['chunk_arrival_time'])) {
                    $fields[] = ['type' => 'mrkdwn', 'text' => "*Arrived:*\n{$data['chunk_arrival_time']}"];
                }
                if (isset($data['auto_fracture_time'])) {
                    $fields[] = ['type' => 'mrkdwn', 'text' => "*Est. Auto Fracture:*\n{$data['auto_fracture_time']}"];
                }
                if (isset($data['estimated_value'])) {
                    $fields[] = ['type' => 'mrkdwn', 'text' => "*Value:*\n" . number_format($data['estimated_value'], 0) . ' ISK'];
                }
                if (isset($data['extraction_url'])) {
                    $fields[] = ['type' => 'mrkdwn', 'text' => "*Details:*\n<{$data['extraction_url']}|View Extraction>"];
                }
            }

            $webhookService = app(\MiningManager\Services\Notification\WebhookService::class);
            $titleReflection = new \ReflectionMethod($webhookService, 'getTitleForEventType');
            $titleReflection->setAccessible(true);
            $title = $titleReflection->invoke($webhookService, $moonEventType);

            $message = [
                'text' => $title,
                'blocks' => [
                    ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $title]],
                    ['type' => 'section', 'fields' => $fields],
                ],
            ];
            $this->addLog($logs, 'ok', "Slack moon message formatted (production format): {$title}");
        }

        if (!$message) {
            $notificationService = app(NotificationService::class);
            $typeConst = match ($type) {
                'tax_generated' => NotificationService::TYPE_TAX_GENERATED,
                'tax_announcement' => NotificationService::TYPE_TAX_ANNOUNCEMENT,
                'tax_reminder' => NotificationService::TYPE_TAX_REMINDER,
                'tax_invoice' => NotificationService::TYPE_TAX_INVOICE,
                'tax_overdue' => NotificationService::TYPE_TAX_OVERDUE,
                'event_created' => NotificationService::TYPE_EVENT_CREATED,
                'event_started' => NotificationService::TYPE_EVENT_STARTED,
                'event_completed' => NotificationService::TYPE_EVENT_COMPLETED,
                'moon_ready' => NotificationService::TYPE_MOON_READY,
                default => NotificationService::TYPE_CUSTOM,
            };

            try {
                $reflection = new \ReflectionMethod($notificationService, 'formatMessageForSlack');
                $reflection->setAccessible(true);
                $message = $reflection->invoke($notificationService, $typeConst, $data);
                $this->addLog($logs, 'ok', 'Slack message formatted');
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format Slack message: ' . $e->getMessage());
                $message = ['text' => 'Test notification from Mining Manager diagnostic tools.'];
            }
        }

        // Send to Slack
        $this->addLog($logs, 'info', 'Sending to Slack webhook...');
        try {
            $response = Http::timeout(10)->post($webhookUrl, $message);

            if ($response->successful()) {
                $this->addLog($logs, 'ok', "Slack webhook responded: HTTP {$response->status()} — Notification delivered!");
                $summary['sent']++;
            } else {
                $this->addLog($logs, 'error', "Slack webhook returned HTTP {$response->status()}: {$response->body()}");
                $summary['failed']++;
            }
        } catch (\Exception $e) {
            $this->addLog($logs, 'error', 'Slack request failed: ' . $e->getMessage());
            $summary['failed']++;
        }
    }

    /**
     * Build test notification data based on type
     */
    protected function buildTestNotificationData(Request $request, string $type, int $characterId, string $characterName): array
    {
        $amount = $request->input('test_amount', 5000000);
        $dueDate = $request->input('test_due_date', now()->addDays(7)->format('Y-m-d'));
        $daysRemaining = $request->input('test_days_remaining', 7);
        $daysOverdue = $request->input('test_days_overdue', 3);

        // Get show_amount setting and URLs to match production notifications
        $showAmount = (bool) $this->settingsService->getSetting('notifications.discord_ping_show_amount', true);
        $baseUrl = rtrim(config('app.url', ''), '/');
        $myTaxesUrl = $baseUrl . '/mining-manager/tax/my-taxes';
        $helpUrl = $baseUrl . '/mining-manager/help#how-to-pay';

        $base = [
            'character_id' => $characterId,
            'character_name' => $characterName,
            'amount' => $amount,
            'formatted_amount' => number_format($amount, 2) . ' ISK',
            'due_date' => $dueDate,
            'show_amount' => $showAmount,
            'my_taxes_url' => $myTaxesUrl,
            'help_url' => $helpUrl,
        ];

        $corpName = $this->settingsService->getSetting('general.moon_owner_corporation_id')
            ? (\Seat\Eveapi\Models\Corporation\CorporationInfo::where('corporation_id', $this->settingsService->getSetting('general.moon_owner_corporation_id'))->value('name') ?? 'Test Corporation')
            : 'Test Corporation';

        return match ($type) {
            'tax_generated' => [
                'period_label' => now()->subMonth()->format('F Y'),
                'period_type' => 'monthly',
                'tax_count' => 15,
                'total_amount' => $amount * 15,
                'formatted_amount' => number_format($amount * 15, 2) . ' ISK',
                'due_date' => $dueDate,
                'corp_name' => $corpName,
                'wallet_division' => $this->settingsService->getWalletDivisionName(),
                'tax_code_prefix' => $this->settingsService->getSetting('tax_rates.tax_code_prefix', 'TAX-'),
                'my_taxes_url' => $myTaxesUrl,
                'help_url' => $helpUrl,
                'collect_url' => $baseUrl . '/mining-manager/help#how-to-collect',
                'wallet_url' => $baseUrl . '/mining-manager/tax/wallet',
            ],
            'tax_announcement' => [
                'period_label' => now()->subMonth()->format('F Y'),
                'period_type' => 'monthly',
                'due_date' => $dueDate,
                'corp_name' => $corpName,
                'my_taxes_url' => $myTaxesUrl,
                'help_url' => $helpUrl,
            ],
            'tax_reminder' => array_merge($base, [
                'days_remaining' => $daysRemaining,
            ]),
            'tax_invoice' => array_merge($base, [
                'invoice_id' => 99999,
            ]),
            'tax_overdue' => array_merge($base, [
                'days_overdue' => $daysOverdue,
            ]),
            'event_created', 'event_started' => [
                'event_id' => 99999,
                'event_name' => $request->input('test_event_name', 'Test Mining Event'),
                'event_type' => $request->input('test_event_type', 'Mining Op'),
                'event_type_key' => 'mining_op',
                'start_date' => now()->format('Y-m-d H:i'),
                'end_date' => now()->addHours(4)->format('Y-m-d H:i'),
                'tax_modifier' => 1.0,
                'tax_modifier_label' => 'Normal (100%)',
                'location' => $request->input('test_location', 'Jita'),
            ],
            'event_completed' => [
                'event_id' => 99999,
                'event_name' => $request->input('test_event_name', 'Test Mining Event'),
                'event_type' => $request->input('test_event_type', 'Mining Op'),
                'total_mined' => $request->input('test_total_mined', 150000000),
                'participants' => $request->input('test_participants', 12),
                'tax_modifier' => 1.0,
                'tax_modifier_label' => 'Normal (100%)',
                'location' => $request->input('test_location', 'Jita'),
            ],
            'moon_ready' => [
                'structure_id' => $request->input('test_structure_id', 1000000000001),
                'structure_name' => $request->input('test_structure_name', 'Athanor - Test Moon'),
                'moon_name' => $request->input('test_moon_name', 'Perimeter I - Moon 1'),
                'ready_time' => now()->addHours(2)->format('Y-m-d H:i'),
                'chunk_arrival_time' => now()->addHours(2)->format('Y-m-d H:i'),
                'auto_fracture_time' => now()->addHours(5)->format('Y-m-d H:i'),
                'time_until_ready' => '2 hours from now',
                'estimated_value' => 250000000,
                'ore_summary' => "Monazite — 15,000 m³ (45%)\nXenotime — 8,500 m³ (25%)\nCoesite — 5,200 m³ (15%)\nBitumite — 4,800 m³ (15%)",
                'extraction_id' => 1,
                'extraction_url' => $baseUrl . '/mining-manager/moon/1',
                'corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
            ],
            'jackpot_detected' => [
                'moon_name' => $request->input('test_moon_name', 'Perimeter I - Moon 1'),
                'system_name' => 'Perimeter',
                'structure_name' => $request->input('test_structure_name', 'Athanor - Test Moon'),
                'detected_by' => 'Mining Ledger Analysis',
                'jackpot_percentage' => 100,
                'jackpot_ores' => [
                    ['name' => 'Brilliant Gneiss', 'quantity' => 12500],
                    ['name' => 'Lustrous Hedbergite', 'quantity' => 8200],
                ],
                'extraction_id' => 1,
                'corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
            ],
            'theft_detected', 'critical_theft', 'active_theft', 'incident_resolved' => $this->buildTheftTestData($request, $type, $characterId, $characterName),
            'report_generated' => [
                'report_type' => 'monthly',
                'period' => [
                    'start' => now()->subMonth()->startOfMonth()->format('Y-m-d'),
                    'end' => now()->subMonth()->endOfMonth()->format('Y-m-d'),
                ],
                'summary' => [
                    'unique_miners' => 42,
                    'total_value' => 15000000000,
                    'total_tax' => 1500000000,
                ],
                'taxes' => [
                    'total_invoices' => 42,
                    'total_paid' => 35,
                    'total_outstanding' => 7,
                ],
                'corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
            ],
            default => $base,
        };
    }

    /**
     * Build test data for theft notification types
     */
    protected function buildTheftTestData(Request $request, string $type, int $characterId, string $characterName): array
    {
        $baseUrl = rtrim(config('app.url', ''), '/');
        $severity = $request->input('test_severity', 'medium');
        $oreValue = (float) $request->input('test_ore_value', 50000000);
        $taxOwed = (float) $request->input('test_tax_owed', 5000000);

        $data = [
            'character_id' => $characterId,
            'character_name' => $characterName,
            'severity' => $severity,
            'ore_value' => $oreValue,
            'formatted_ore_value' => number_format($oreValue, 0) . ' ISK',
            'tax_owed' => $taxOwed,
            'formatted_tax_owed' => number_format($taxOwed, 0) . ' ISK',
            'moon_name' => $request->input('test_moon_name', 'Perimeter I - Moon 1'),
            'structure_name' => $request->input('test_structure_name', 'Athanor - Test Moon'),
            'detected_at' => now()->format('Y-m-d H:i:s'),
            'incident_url' => $baseUrl . '/mining-manager/diagnostic',
            'test_mode' => true,
            'corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
        ];

        if ($type === 'active_theft') {
            $data['new_mining_value'] = (float) $request->input('test_new_mining_value', 10000000);
            $data['formatted_new_mining_value'] = number_format($data['new_mining_value'], 0) . ' ISK';
            $data['activity_count'] = (int) $request->input('test_activity_count', 3);
            $data['last_activity'] = now()->format('Y-m-d H:i:s');
        } elseif ($type === 'incident_resolved') {
            $data['resolved_by'] = 'Diagnostic Test';
            $data['status'] = 'resolved';
        }

        return $data;
    }

    /**
     * Add a timestamped log entry
     */
    protected function addLog(array &$logs, string $level, string $message): void
    {
        $logs[] = [
            'time' => now()->format('H:i:s.v'),
            'level' => $level,
            'message' => $message,
        ];
    }
}
