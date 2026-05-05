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
     * Run the Master Test — a one-click chain of read-only smoke checks
     * exercising every major area of the plugin (schema, settings, cross-
     * plugin integration, pricing, notifications, lifecycle, tax, security
     * hardening). Returns a structured JSON report.
     *
     * Idempotent — never mutates production data. Heavier per-area
     * diagnostics remain on their own dedicated tabs for deep-dive use.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function runMasterTest(\MiningManager\Services\Diagnostic\MasterTestRunner $runner)
    {
        return response()->json([
            'success' => true,
            'report' => $runner->runAll(),
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

            // (Removed: temp_role_id override.)
            //
            // The diagnostic used to read a `temp_role_id` form field and
            // mutate `$webhook->discord_role_id` in-memory before dispatch,
            // intending to let the operator test "what happens with role X
            // pinged" without persisting the change. The mutation never
            // reached the actual dispatch though — sendViaWebhooks calls
            // `WebhookConfiguration::enabled()->forEvent(...)->get()` which
            // does a fresh DB read and ignores the in-memory model. So the
            // feature lied: it reported "role X pinged" while the saved
            // role got pinged instead. Removed entirely. Operators who
            // want to test a different role should edit the webhook,
            // save, test, then revert — same number of clicks, no lie.

            // Create simulated theft incident
            // Use the webhook's corporation_id (or moon owner corp) so the query matches this webhook
            $testCorpId = $webhook->corporation_id
                ?? (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0);

            $testIncident = new \MiningManager\Models\TheftIncident([
                'character_id' => 123456789,
                'character_name' => $characterName,
                'corporation_id' => $testCorpId ?: null,
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

            // Send notification via NotificationService (Phase D — was
            // WebhookService::sendTheftNotification before consolidation).
            $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
            $nsResults = match ($eventType) {
                'critical_theft' => $notificationService->sendCriticalTheft($testIncident, $additionalData),
                'active_theft' => $notificationService->sendActiveTheft($testIncident, $additionalData),
                'incident_resolved' => $notificationService->sendIncidentResolved($testIncident, $additionalData),
                default => $notificationService->sendTheftDetected($testIncident, $additionalData),
            };

            // Unwrap to match the old per-webhook-id shape used by the
            // success/failure counting below. send() returns a channel-keyed
            // array; we want the Discord slot for diagnostic reporting.
            $result = [];
            foreach ($nsResults['discord']['sent'] ?? [] as $wid) {
                $result[$wid] = ['success' => true];
            }
            foreach ($nsResults['discord']['failed'] ?? [] as $fail) {
                $result[$fail['webhook_id'] ?? 'unknown'] = ['success' => false, 'error' => $fail['error'] ?? 'Unknown error'];
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
                    'role_mention' => $webhook->discord_role_id,
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
                        // Check multiple possible DB key paths for this setting
                        // Payment settings read from tax_rates.* and payment.* namespaces
                        $dbRecord = Setting::where('key', $key)->first()
                            ?? Setting::where('key', strtolower($groupName) . '.' . $key)->first()
                            ?? Setting::where('key', 'tax_rates.tax_' . $key)->first()
                            ?? Setting::where('key', 'payment.' . $key)->first()
                            ?? Setting::where('key', 'general.' . $key)->first();

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
            // Include all alts in the group so accumulated tax traces show the full picture
            // ================================================================
            $allCharIds = $accountInfo['all_character_ids'] ?? [$characterId];

            $dailySummaries = MiningLedgerDailySummary::whereIn('character_id', $allCharIds)
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
            // Include all alts in the group for accumulated tax comparison
            // ================================================================
            $entries = DB::table('mining_ledger')
                ->whereIn('character_id', $allCharIds)
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
     * Moon extraction diagnostic - test fracture detection, status transitions, values
     */
    public function moonDiagnostic(Request $request)
    {
        try {
            $extractionId = (int) $request->input('extraction_id');
            $structureId = (int) $request->input('structure_id');

            if (!$extractionId && !$structureId) {
                return response()->json(['success' => false, 'error' => 'extraction_id or structure_id is required'], 400);
            }

            $extractionService = app(\MiningManager\Services\Moon\MoonExtractionService::class);
            $valueService = app(\MiningManager\Services\Moon\MoonValueCalculationService::class);

            // Find extraction(s)
            if ($extractionId) {
                $extractions = \MiningManager\Models\MoonExtraction::where('id', $extractionId)->get();
            } else {
                $extractions = \MiningManager\Models\MoonExtraction::where('structure_id', $structureId)
                    ->orderBy('extraction_start_time', 'desc')
                    ->limit(5)
                    ->get();
            }

            if ($extractions->isEmpty()) {
                return response()->json(['success' => false, 'error' => 'No extractions found'], 404);
            }

            $results = [];
            foreach ($extractions as $extraction) {
                // Status analysis
                $dbStatus = $extraction->status;
                $effectiveStatus = $extraction->getEffectiveStatus();
                $statusMismatch = $dbStatus !== $effectiveStatus;

                // Timeline
                $fractureTime = $extraction->getFractureTime();
                $unstableStart = $extraction->getUnstableStartTime();
                $expiryTime = $extraction->getExpiryTime();

                // Fracture detection test
                $fractureDetected = false;
                if (!$extraction->fractured_at && $extraction->chunk_arrival_time && $extraction->chunk_arrival_time->isPast()) {
                    $fractureDetected = $extractionService->detectFractureForExtraction($extraction);
                    if ($fractureDetected) {
                        $extraction->refresh();
                        $fractureTime = $extraction->getFractureTime();
                    }
                }

                // Jackpot check
                $jackpotOresFound = [];
                if ($extraction->chunk_arrival_time && $extraction->chunk_arrival_time->isPast()) {
                    $jackpotTypeIds = \MiningManager\Services\TypeIdRegistry::getAllJackpotOres();
                    $structureInfo = DB::table('universe_structures')
                        ->where('structure_id', $extraction->structure_id)
                        ->first();

                    if ($structureInfo) {
                        $jackpotMining = DB::table('mining_ledger')
                            ->where('solar_system_id', $structureInfo->solar_system_id)
                            ->whereIn('type_id', $jackpotTypeIds)
                            ->where('date', '>=', $extraction->chunk_arrival_time->toDateString())
                            ->where('date', '<=', ($expiryTime ?? now())->toDateString())
                            ->select('type_id', DB::raw('SUM(quantity) as total_qty'))
                            ->groupBy('type_id')
                            ->get();

                        foreach ($jackpotMining as $jm) {
                            $typeName = DB::table('invTypes')->where('typeID', $jm->type_id)->value('typeName') ?? "Type {$jm->type_id}";
                            $jackpotOresFound[] = ['type_id' => $jm->type_id, 'name' => $typeName, 'quantity' => $jm->total_qty];
                        }
                    }
                }

                // Value calculation
                $estimatedValue = null;
                if ($extraction->ore_composition) {
                    try {
                        $estimatedValue = $valueService->calculateExtractionValue($extraction);
                    } catch (\Exception $e) {
                        $estimatedValue = 'Error: ' . $e->getMessage();
                    }
                }

                $results[] = [
                    'extraction_id' => $extraction->id,
                    'structure_id' => $extraction->structure_id,
                    'structure_name' => $extraction->structure_name ?? 'Unknown',
                    'moon_name' => $extraction->moon_name ?? 'Unknown',
                    'status' => [
                        'database' => $dbStatus,
                        'effective' => $effectiveStatus,
                        'mismatch' => $statusMismatch,
                    ],
                    'timeline' => [
                        'extraction_start' => $extraction->extraction_start_time?->toIso8601String(),
                        'chunk_arrival' => $extraction->chunk_arrival_time?->toIso8601String(),
                        'fractured_at' => $extraction->fractured_at?->toIso8601String(),
                        'fractured_by' => $extraction->fractured_by,
                        'auto_fractured' => $extraction->auto_fractured,
                        'fracture_time_calculated' => $fractureTime?->toIso8601String(),
                        'unstable_start' => $unstableStart?->toIso8601String(),
                        'expiry_time' => $expiryTime?->toIso8601String(),
                    ],
                    'fracture_detection' => [
                        'had_fracture_data' => $extraction->fractured_at !== null,
                        'detected_now' => $fractureDetected,
                    ],
                    'jackpot' => [
                        'is_jackpot' => $extraction->is_jackpot,
                        'jackpot_detected_at' => $extraction->jackpot_detected_at?->toIso8601String(),
                        'jackpot_ores_in_ledger' => $jackpotOresFound,
                    ],
                    'composition' => [
                        'has_data' => !empty($extraction->ore_composition),
                        'has_notification_data' => (bool) $extraction->has_notification_data,
                        'ore_count' => is_array($extraction->ore_composition) ? count($extraction->ore_composition) : 0,
                        'estimated_value' => $estimatedValue,
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'extraction_count' => count($results),
                'extractions' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Moon diagnostic failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Tax pipeline diagnostic - test summaries, calculation, codes, payment matching
     */
    public function taxPipelineDiagnostic(Request $request)
    {
        try {
            $month = $request->input('month', \Carbon\Carbon::now()->subMonth()->format('Y-m'));
            $characterId = (int) $request->input('character_id');

            $monthStart = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $taxService = app(\MiningManager\Services\Tax\TaxCalculationService::class);
            $walletService = app(\MiningManager\Services\Tax\WalletTransferService::class);

            $pipeline = [];

            // Step 1: Daily summaries
            $summaryCount = \MiningManager\Models\MiningLedgerDailySummary::whereBetween('date', [$monthStart, $monthEnd])->count();
            $finalizedCount = \MiningManager\Models\MiningLedgerDailySummary::whereBetween('date', [$monthStart, $monthEnd])->where('is_finalized', true)->count();
            $uniqueChars = \MiningManager\Models\MiningLedgerDailySummary::whereBetween('date', [$monthStart, $monthEnd])->distinct('character_id')->count('character_id');

            $pipeline['daily_summaries'] = [
                'status' => $summaryCount > 0 ? 'pass' : 'warning',
                'total' => $summaryCount,
                'finalized' => $finalizedCount,
                'unique_characters' => $uniqueChars,
                'all_finalized' => $summaryCount > 0 && $summaryCount === $finalizedCount,
            ];

            // Step 2: Tax records
            $taxRecords = \MiningManager\Models\MiningTax::where('month', $monthStart->format('Y-m-01'))
                ->orWhere(function ($q) use ($monthStart, $monthEnd) {
                    $q->where('period_start', '>=', $monthStart)->where('period_start', '<=', $monthEnd);
                })
                ->get();

            $byStatus = $taxRecords->groupBy('status')->map->count();
            $totalOwed = $taxRecords->sum('amount_owed');
            $totalPaid = $taxRecords->sum('amount_paid');

            $pipeline['tax_records'] = [
                'status' => $taxRecords->isNotEmpty() ? 'pass' : 'warning',
                'total' => $taxRecords->count(),
                'by_status' => $byStatus->toArray(),
                'total_owed' => round($totalOwed, 2),
                'total_paid' => round($totalPaid, 2),
                'collection_rate' => $totalOwed > 0 ? round(($totalPaid / $totalOwed) * 100, 1) : 0,
            ];

            // Step 3: Tax codes
            $taxIds = $taxRecords->pluck('id')->toArray();
            $codes = \MiningManager\Models\TaxCode::whereIn('mining_tax_id', $taxIds)->get();
            $codesByStatus = $codes->groupBy('status')->map->count();

            $pipeline['tax_codes'] = [
                'status' => $codes->isNotEmpty() || $taxRecords->isEmpty() ? 'pass' : 'warning',
                'total' => $codes->count(),
                'by_status' => $codesByStatus->toArray(),
                'taxes_without_codes' => $taxRecords->count() - $codes->pluck('mining_tax_id')->unique()->count(),
            ];

            // Step 4: Pending payments
            try {
                $pending = $walletService->getPendingPayments(30);
                $pipeline['pending_payments'] = [
                    'status' => 'pass',
                    'count' => $pending->count(),
                ];
            } catch (\Exception $e) {
                $pipeline['pending_payments'] = ['status' => 'error', 'error' => $e->getMessage()];
            }

            // Step 5: Overdue check
            $overdueCount = $taxRecords->filter(fn($t) => $t->isOverdue())->count();
            $pipeline['overdue'] = [
                'status' => $overdueCount === 0 ? 'pass' : 'warning',
                'count' => $overdueCount,
                'overdue_records' => $taxRecords->filter(fn($t) => $t->isOverdue())->map(fn($t) => [
                    'id' => $t->id, 'character_id' => $t->character_id, 'amount_owed' => $t->amount_owed, 'due_date' => $t->due_date?->toDateString(),
                ])->values()->take(10)->toArray(),
            ];

            // Step 6: Exemption/minimum check
            $exemptions = $this->settingsService->getExemptions();
            $paymentSettings = $this->settingsService->getPaymentSettings();
            $minimumBehavior = $this->settingsService->getSetting('payment.minimum_tax_behavior', 'exempt');

            $pipeline['thresholds'] = [
                'exemption_enabled' => $exemptions['enabled'] ?? false,
                'exemption_threshold' => $exemptions['threshold'] ?? 0,
                'minimum_tax_amount' => $paymentSettings['minimum_tax_amount'] ?? 1000000,
                'minimum_behavior' => $minimumBehavior,
            ];

            // Optional: Character-specific calculation
            $characterCalc = null;
            if ($characterId) {
                try {
                    $charTax = $taxService->calculateCharacterTax($characterId, $monthStart, $monthEnd, true);
                    $characterCalc = [
                        'character_id' => $characterId,
                        'calculated_tax' => round($charTax, 2),
                        'above_exemption' => $charTax >= ($exemptions['threshold'] ?? 0),
                        'above_minimum' => $charTax >= ($paymentSettings['minimum_tax_amount'] ?? 1000000),
                    ];
                } catch (\Exception $e) {
                    $characterCalc = ['error' => $e->getMessage()];
                }
            }

            return response()->json([
                'success' => true,
                'period' => ['month' => $month, 'start' => $monthStart->toDateString(), 'end' => $monthEnd->toDateString()],
                'pipeline' => $pipeline,
                'character_calculation' => $characterCalc,
            ]);

        } catch (\Exception $e) {
            Log::error('Tax pipeline diagnostic failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Theft detection diagnostic - test detection logic and statistics
     */
    public function theftDiagnostic(Request $request)
    {
        try {
            $characterId = (int) $request->input('character_id');
            $theftService = app(\MiningManager\Services\Theft\TheftDetectionService::class);

            // Overall statistics
            $stats = $theftService->getStatistics();

            // Unresolved incidents summary
            $unresolved = $theftService->getUnresolvedIncidents();

            // Characters who have paid
            $paidCharacters = [];
            try {
                $paid = $theftService->checkForPaidTaxes();
                $paidCharacters = $paid->map(fn($i) => [
                    'character_id' => $i->character_id, 'character_name' => $i->character_name, 'status' => $i->status,
                ])->take(10)->toArray();
            } catch (\Exception $e) {
                $paidCharacters = ['error' => $e->getMessage()];
            }

            // Character-specific analysis
            $characterAnalysis = null;
            if ($characterId) {
                $moonOwnerCorpId = (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0);

                $isExternal = $theftService->isExternalMiner($characterId, $moonOwnerCorpId);

                // Get their mining on moon structures
                $moonMining = DB::table('corporation_industry_mining_observer_data as m')
                    ->join('corporation_industry_mining_observers as o', 'm.observer_id', '=', 'o.observer_id')
                    ->where('m.character_id', $characterId)
                    ->where('m.last_updated', '>=', now()->subDays(30))
                    ->select('m.type_id', DB::raw('SUM(m.quantity) as total_qty'), DB::raw('MAX(m.last_updated) as last_seen'))
                    ->groupBy('m.type_id')
                    ->get();

                $totalValue = 0;
                $moonOreEntries = [];
                foreach ($moonMining as $entry) {
                    $typeName = DB::table('invTypes')->where('typeID', $entry->type_id)->value('typeName') ?? "Type {$entry->type_id}";
                    $isMoonOre = \MiningManager\Services\TypeIdRegistry::isMoonOre($entry->type_id);
                    if ($isMoonOre) {
                        $moonOreEntries[] = ['type_id' => $entry->type_id, 'name' => $typeName, 'quantity' => $entry->total_qty, 'last_seen' => $entry->last_seen];
                    }
                }

                // Check existing incidents
                $existingIncident = \MiningManager\Models\TheftIncident::where('character_id', $characterId)
                    ->whereIn('status', ['detected', 'investigating', 'active'])
                    ->first();

                $characterAnalysis = [
                    'character_id' => $characterId,
                    'character_name' => DB::table('character_infos')->where('character_id', $characterId)->value('name') ?? "Unknown ({$characterId})",
                    'is_external_miner' => $isExternal,
                    'moon_ore_mined_30d' => $moonOreEntries,
                    'existing_incident' => $existingIncident ? [
                        'id' => $existingIncident->id, 'status' => $existingIncident->status, 'severity' => $existingIncident->severity,
                        'ore_value' => $existingIncident->ore_value, 'tax_owed' => $existingIncident->tax_owed,
                    ] : null,
                ];
            }

            return response()->json([
                'success' => true,
                'statistics' => $stats,
                'unresolved_count' => $unresolved->count(),
                'unresolved_by_severity' => $unresolved->groupBy('severity')->map->count()->toArray(),
                'paid_characters' => $paidCharacters,
                'character_analysis' => $characterAnalysis,
            ]);

        } catch (\Exception $e) {
            Log::error('Theft diagnostic failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Event lifecycle diagnostic - test tracking, progress, leaderboards
     */
    public function eventDiagnostic(Request $request)
    {
        try {
            $eventId = (int) $request->input('event_id');
            $eventService = app(\MiningManager\Services\Events\EventManagementService::class);
            $trackingService = app(\MiningManager\Services\Events\EventTrackingService::class);

            if ($eventId) {
                // Specific event analysis
                $event = \MiningManager\Models\MiningEvent::with('participants.character')->findOrFail($eventId);

                $progress = [];
                $leaderboard = [];
                $inactive = [];
                $stats = [];

                try { $progress = $trackingService->getEventProgress($eventId); } catch (\Exception $e) { $progress = ['error' => $e->getMessage()]; }
                try { $stats = $eventService->getEventStatistics($eventId); } catch (\Exception $e) { $stats = ['error' => $e->getMessage()]; }
                try { $leaderboard = $trackingService->getDetailedLeaderboard($eventId); } catch (\Exception $e) { $leaderboard = ['error' => $e->getMessage()]; }
                try {
                    $inactiveParticipants = $trackingService->detectInactiveParticipants($eventId, 2);
                    $inactive = $inactiveParticipants->map(fn($p) => [
                        'character_id' => $p->character_id, 'character_name' => $p->character->name ?? 'Unknown',
                        'quantity_mined' => $p->quantity_mined, 'last_updated' => $p->last_updated?->toIso8601String(),
                    ])->toArray();
                } catch (\Exception $e) { $inactive = ['error' => $e->getMessage()]; }

                return response()->json([
                    'success' => true,
                    'mode' => 'single_event',
                    'event' => [
                        'id' => $event->id, 'name' => $event->name, 'type' => $event->type, 'status' => $event->status,
                        'start_time' => $event->start_time?->toIso8601String(), 'end_time' => $event->end_time?->toIso8601String(),
                        'participant_count' => $event->participant_count, 'total_mined' => $event->total_mined,
                        'tax_modifier' => $event->tax_modifier, 'is_active' => $event->isActive(), 'is_future' => $event->isFuture(),
                        'duration' => $event->getDuration(),
                    ],
                    'progress' => $progress,
                    'statistics' => $stats,
                    'leaderboard' => is_array($leaderboard) ? array_slice($leaderboard, 0, 10) : $leaderboard,
                    'inactive_participants' => $inactive,
                ]);
            } else {
                // Overview
                $active = $eventService->getActiveEvents();
                $upcoming = $eventService->getUpcomingEvents(7);

                $recentCompleted = \MiningManager\Models\MiningEvent::where('status', 'completed')
                    ->orderBy('end_time', 'desc')
                    ->limit(5)
                    ->get();

                return response()->json([
                    'success' => true,
                    'mode' => 'overview',
                    'active_events' => $active->map(fn($e) => [
                        'id' => $e->id, 'name' => $e->name, 'status' => $e->status,
                        'participant_count' => $e->participant_count, 'total_mined' => $e->total_mined,
                    ])->toArray(),
                    'upcoming_events' => $upcoming->map(fn($e) => [
                        'id' => $e->id, 'name' => $e->name, 'start_time' => $e->start_time?->toIso8601String(),
                    ])->toArray(),
                    'recent_completed' => $recentCompleted->map(fn($e) => [
                        'id' => $e->id, 'name' => $e->name, 'participant_count' => $e->participant_count,
                        'total_mined' => $e->total_mined, 'end_time' => $e->end_time?->toIso8601String(),
                    ])->toArray(),
                    'total_active' => $active->count(),
                    'total_upcoming' => $upcoming->count(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Event diagnostic failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Analytics & reports diagnostic - test metrics, analytics, report generation
     */
    public function analyticsDiagnostic(Request $request)
    {
        try {
            $startDate = \Carbon\Carbon::parse($request->input('start_date', now()->subDays(30)->toDateString()));
            $endDate = \Carbon\Carbon::parse($request->input('end_date', now()->toDateString()));

            $metricsService = app(\MiningManager\Services\Analytics\DashboardMetricsService::class);
            $analyticsService = app(\MiningManager\Services\Analytics\MiningAnalyticsService::class);

            $results = [];

            // Dashboard metrics
            try {
                $results['dashboard_metrics'] = $metricsService->getSummaryMetrics();
            } catch (\Exception $e) {
                $results['dashboard_metrics'] = ['error' => $e->getMessage()];
            }

            // Tax metrics
            try {
                $results['tax_metrics'] = $metricsService->getTaxMetrics();
            } catch (\Exception $e) {
                $results['tax_metrics'] = ['error' => $e->getMessage()];
            }

            // Mining analytics
            try {
                $results['mining_summary'] = [
                    'total_volume' => $analyticsService->getTotalVolume($startDate, $endDate),
                    'total_value' => round($analyticsService->getTotalValue($startDate, $endDate), 2),
                    'unique_miners' => $analyticsService->getUniqueMinerCount($startDate, $endDate),
                ];
            } catch (\Exception $e) {
                $results['mining_summary'] = ['error' => $e->getMessage()];
            }

            // Top miners
            try {
                $topMiners = $analyticsService->getTopMiners($startDate, $endDate, 5);
                $results['top_miners'] = $topMiners->map(fn($m) => [
                    'character_id' => $m->character_id,
                    'character_name' => $m->character_name ?? DB::table('character_infos')->where('character_id', $m->character_id)->value('name') ?? "Unknown",
                    'total_quantity' => $m->total_quantity ?? $m->quantity ?? 0,
                    'total_value' => round($m->total_value ?? $m->value ?? 0, 2),
                ])->toArray();
            } catch (\Exception $e) {
                $results['top_miners'] = ['error' => $e->getMessage()];
            }

            // Ore breakdown
            try {
                $oreBreakdown = $analyticsService->getOreBreakdown($startDate, $endDate);
                $results['ore_breakdown'] = $oreBreakdown->take(10)->map(fn($o) => [
                    'type_id' => $o->type_id ?? null,
                    'ore_name' => $o->ore_name ?? $o->type_name ?? 'Unknown',
                    'total_quantity' => $o->total_quantity ?? 0,
                    'total_value' => round($o->total_value ?? 0, 2),
                ])->toArray();
            } catch (\Exception $e) {
                $results['ore_breakdown'] = ['error' => $e->getMessage()];
            }

            // Scheduled reports
            $scheduledReports = \MiningManager\Models\ReportSchedule::orderBy('next_run')->limit(5)->get();
            $results['scheduled_reports'] = $scheduledReports->map(fn($r) => [
                'id' => $r->id, 'report_type' => $r->report_type, 'frequency' => $r->frequency,
                'is_active' => $r->is_active, 'last_run' => $r->last_run?->toIso8601String(), 'next_run' => $r->next_run?->toIso8601String(),
            ])->toArray();

            // Monthly statistics
            $monthlyStats = \MiningManager\Models\MonthlyStatistic::orderBy('year', 'desc')->orderBy('month', 'desc')
                ->limit(3)
                ->get();
            $results['monthly_statistics'] = $monthlyStats->map(fn($s) => [
                'year' => $s->year, 'month' => $s->month, 'is_closed' => $s->is_closed,
                'total_value' => round($s->total_value ?? 0, 2), 'tax_owed' => round($s->tax_owed ?? 0, 2),
                'mining_days' => $s->mining_days,
            ])->toArray();

            return response()->json([
                'success' => true,
                'period' => ['start' => $startDate->toDateString(), 'end' => $endDate->toDateString()],
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Analytics diagnostic failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
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
    public function systemStatus(Request $request)
    {
        $section = $request->input('section', 'all');

        // Load individual sections — JS calls each independently for progressive loading
        try {
            $result = match ($section) {
                'daily_summaries' => $this->systemStatusDailySummaries(),
                'multi_corp' => $this->systemStatusMultiCorp(),
                'price_cache' => $this->systemStatusPriceCache(),
                'scheduled_jobs' => $this->systemStatusScheduledJobs(),
                'data_counts' => $this->systemStatusDataCounts(),
                default => [
                    'sections' => ['daily_summaries', 'multi_corp', 'price_cache', 'scheduled_jobs', 'data_counts'],
                ],
            };

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    private function systemStatusDailySummaries(): array
    {
        $stats = [];
        $stats['total'] = DB::table('mining_ledger_daily_summaries')->count();
        $stats['today'] = DB::table('mining_ledger_daily_summaries')
            ->whereDate('date', Carbon::today())->count();
        $stats['yesterday'] = DB::table('mining_ledger_daily_summaries')
            ->whereDate('date', Carbon::yesterday())->count();

        $minersToday = DB::table('mining_ledger')
            ->whereDate('date', Carbon::today())
            ->whereNotNull('processed_at')
            ->distinct()->pluck('character_id')->toArray();
        $summariesToday = DB::table('mining_ledger_daily_summaries')
            ->whereDate('date', Carbon::today())
            ->distinct()->pluck('character_id')->toArray();
        $stats['missing_today'] = count(array_diff($minersToday, $summariesToday));
        $stats['miners_today'] = count($minersToday);

        $latest = DB::table('mining_ledger_daily_summaries')
            ->orderByDesc('updated_at')->value('updated_at');
        $stats['last_updated'] = $latest;
        $stats['last_updated_ago'] = $latest ? Carbon::parse($latest)->diffForHumans() : 'never';
        $stats['finalized_months'] = DB::table('mining_ledger_monthly_summaries')
            ->where('is_finalized', true)->count();
        $stats['status'] = $stats['missing_today'] === 0 ? 'healthy' : 'warning';

        return $stats;
    }

    private function systemStatusMultiCorp(): array
    {
        $corpStats = [];
        $allCorps = $this->settingsService->getAllCorporations();
        $corpStats['configured_corporations'] = $allCorps->count();

        $moonOwner = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $corpStats['moon_owner_corporation_id'] = $moonOwner;

        $corpDetails = [];
        foreach ($allCorps as $corp) {
            $corpId = $corp->corporation_id;
            $this->settingsService->setActiveCorporation((int) $corpId);
            $taxRates = $this->settingsService->getTaxRatesForCorporation($corpId);
            $taxSelector = $this->settingsService->getTaxSelector();

            $corpDetails[] = [
                'corporation_id' => $corpId,
                'corporation_name' => $corp->name ?? 'Unknown',
                'has_ore_rate' => isset($taxRates['ore']) && $taxRates['ore'] > 0,
                'has_moon_rates' => isset($taxRates['moon_ore']) && !empty($taxRates['moon_ore']),
                'all_moon_ore' => $taxSelector['all_moon_ore'] ?? false,
                'only_corp_moon_ore' => $taxSelector['only_corp_moon_ore'] ?? false,
                'no_moon_ore' => $taxSelector['no_moon_ore'] ?? false,
                'ore_taxed' => $taxSelector['ore'] ?? true,
                'ice_taxed' => $taxSelector['ice'] ?? true,
                'gas_taxed' => $taxSelector['gas'] ?? false,
                'abyssal_taxed' => $taxSelector['abyssal_ore'] ?? false,
                'triglavian_taxed' => $taxSelector['triglavian_ore'] ?? false,
                'ore_rate' => $taxRates['ore'] ?? 0,
            ];
        }
        $this->settingsService->setActiveCorporation(null);

        $corpStats['corporation_details'] = $corpDetails;
        $corpStats['status'] = $allCorps->count() > 0 || $moonOwner ? 'healthy' : 'warning';
        $corpStats['manager_core_installed'] = \MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled();

        return $corpStats;
    }

    private function systemStatusPriceCache(): array
    {
        $pricingSettings = $this->settingsService->getPricingSettings();
        $cacheDuration = (int) ($pricingSettings['cache_duration'] ?? 240);

        $totalCached = MiningPriceCache::count();
        $freshCount = MiningPriceCache::where('cached_at', '>=', now()->subMinutes($cacheDuration))->count();
        $staleCount = $totalCached - $freshCount;

        $result = [
            'total_cached' => $totalCached,
            'fresh' => $freshCount,
            'stale' => $staleCount,
            'cache_duration_minutes' => $cacheDuration,
            'status' => $staleCount === 0 && $totalCached > 0 ? 'healthy'
                : ($totalCached === 0 ? 'critical' : 'warning'),
            'provider' => $pricingSettings['price_provider'] ?? 'seat',
        ];

        if (($pricingSettings['price_provider'] ?? 'seat') === 'manager-core'
            && \MiningManager\Services\Pricing\PriceProviderService::isManagerCoreInstalled()) {
            try {
                $mcMarket = $pricingSettings['manager_core_market'] ?? 'jita';
                $result['manager_core'] = [
                    'market' => $mcMarket,
                    'total_prices' => DB::table('manager_core_market_prices')->where('market', $mcMarket)->count(),
                    'last_updated_ago' => Carbon::parse(
                        DB::table('manager_core_market_prices')->where('market', $mcMarket)->max('updated_at')
                    )->diffForHumans(),
                ];
            } catch (\Exception $e) {
                $result['manager_core'] = ['error' => $e->getMessage()];
            }
        }

        return $result;
    }

    private function systemStatusScheduledJobs(): array
    {
        // Read actual schedule configuration from SeAT's schedules table
        $schedules = DB::table('schedules')
            ->where('command', 'like', 'mining-manager:%')
            ->get();

        $jobs = [];
        foreach ($schedules as $schedule) {
            // Parse cron expression to human-readable and calculate next run
            $nextRun = null;
            try {
                $cron = new \Cron\CronExpression($schedule->expression);
                $nextRun = $cron->getNextRunDate()->format('Y-m-d H:i');
            } catch (\Exception $e) {
                $nextRun = 'parse error';
            }

            // Categorize the command
            $category = 'other';
            $cmd = $schedule->command;
            if (str_contains($cmd, 'process-ledger') || str_contains($cmd, 'import-character') || str_contains($cmd, 'update-ledger') || str_contains($cmd, 'update-daily') || str_contains($cmd, 'cache-prices')) {
                $category = 'data';
            } elseif (str_contains($cmd, 'calculate-taxes') || str_contains($cmd, 'generate-invoices') || str_contains($cmd, 'verify-payments') || str_contains($cmd, 'send-reminders') || str_contains($cmd, 'generate-tax') || str_contains($cmd, 'finalize')) {
                $category = 'tax';
            } elseif (str_contains($cmd, 'extract') || str_contains($cmd, 'jackpot') || str_contains($cmd, 'archive')) {
                $category = 'moon';
            } elseif (str_contains($cmd, 'theft') || str_contains($cmd, 'detect-theft')) {
                $category = 'theft';
            } elseif (str_contains($cmd, 'report') || str_contains($cmd, 'stats')) {
                $category = 'reports';
            }

            $jobs[] = [
                'command' => $schedule->command,
                'expression' => $schedule->expression,
                'next_run' => $nextRun,
                'allow_overlap' => (bool) $schedule->allow_overlap,
                'category' => $category,
            ];
        }

        // Sort by next run time
        usort($jobs, function ($a, $b) {
            return ($a['next_run'] ?? '9999') <=> ($b['next_run'] ?? '9999');
        });

        return [
            'total_scheduled' => count($jobs),
            'jobs' => $jobs,
        ];
    }

    private function systemStatusDataCounts(): array
    {
        return [
            'mining_ledger' => DB::table('mining_ledger')->count(),
            'mining_taxes' => DB::table('mining_taxes')->count(),
            'daily_summaries' => DB::table('mining_ledger_daily_summaries')->count(),
            'monthly_summaries' => DB::table('mining_ledger_monthly_summaries')->count(),
            'price_cache' => MiningPriceCache::count(),
            'moon_extractions' => DB::table('moon_extractions')->count(),
            'webhooks' => DB::table('webhook_configurations')->count(),
        ];
    }

    // ========================================================================
    // NOTIFICATION TESTING
    // ========================================================================

    /**
     * Fire a REAL notification through the full NotificationService pipeline.
     *
     * Unlike testNotification() which does a single-webhook preview POST,
     * this routes through the actual convenience wrappers (sendTaxReminder,
     * sendMoonArrival, sendTheftDetected, etc.) so everything gets exercised:
     * per-type enable/disable toggles, corp-scoped webhook filtering, all
     * subscribed webhooks (not just one), retry logic on transient failures,
     * and the mining_notification_log audit trail.
     *
     * Useful for end-to-end verification without having to wait for a real
     * tax calculation, moon extraction, theft detection, or report generation
     * to happen organically.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function fireLiveNotification(Request $request)
    {
        $logs = [];
        $startTime = microtime(true);
        $this->addLog($logs, 'info', '=== Live Notification Fire Started ===');

        $type = $request->input('notification_type', 'tax_reminder');
        $characterId = (int) $request->input('character_id', 0);
        $characterName = $request->input('character_name', 'Test Character');

        if ($characterId === 0) {
            $characterId = 123456789;
            $this->addLog($logs, 'warn', 'No character_id provided — using fake ID 123456789. Tax-individual notifications may not ping a real user.');
        } else {
            $charInfo = DB::table('character_infos')->where('character_id', $characterId)->first();
            if ($charInfo) {
                $characterName = $charInfo->name;
                $this->addLog($logs, 'ok', "Target character: {$characterName} ({$characterId})");
            } else {
                $this->addLog($logs, 'warn', "Character ID {$characterId} not found in character_infos — falling back to provided name: {$characterName}");
            }
        }

        $this->addLog($logs, 'info', "Notification type: {$type}");
        $this->addLog($logs, 'warn', '⚠️  This will fire through the FULL pipeline — every subscribed, enabled webhook will receive the notification. Scope filters apply.');

        try {
            $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
            $testData = $this->buildTestNotificationData($request, $type, $characterId, $characterName);

            $results = $this->dispatchLiveByType($notificationService, $type, $characterId, $characterName, $testData, $request);

            $this->addLog($logs, 'ok', 'Wrapper returned: ' . json_encode($this->summariseLiveResults($results)));

            $this->summariseLiveChannels($logs, $results);
        } catch (\Exception $e) {
            $this->addLog($logs, 'error', 'Live fire failed: ' . $e->getMessage());
            $this->addLog($logs, 'error', 'Stack: ' . $e->getTraceAsString());
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->addLog($logs, 'info', "=== Live Fire Complete ({$duration}ms) ===");

        return response()->json([
            'success' => true,
            'logs' => $logs,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Route a live notification fire to the right NotificationService wrapper
     * for its type. Builds the fake domain model where the wrapper needs one
     * (MiningEvent / TheftIncident / MiningReport).
     */
    protected function dispatchLiveByType(
        \MiningManager\Services\Notification\NotificationService $ns,
        string $type,
        int $characterId,
        string $characterName,
        array $data,
        Request $request
    ): array {
        return match ($type) {
            'tax_reminder' => $ns->sendTaxReminder(
                $characterId,
                (float) ($data['amount'] ?? 5000000),
                \Carbon\Carbon::parse($data['due_date'] ?? now()->addDays(7)),
                (int) ($data['days_remaining'] ?? 7)
            ),
            'tax_overdue' => $ns->sendTaxOverdue(
                $characterId,
                (float) ($data['amount'] ?? 5000000),
                \Carbon\Carbon::parse($data['due_date'] ?? now()->subDays(3)),
                (int) ($data['days_overdue'] ?? 3)
            ),
            'tax_generated' => $ns->sendTaxGenerated(
                $data['period_label'] ?? now()->subMonth()->format('F Y'),
                (int) ($data['tax_count'] ?? 15),
                (float) ($data['total_amount'] ?? 75000000),
                $data['period_type'] ?? 'monthly',
                $data['due_date'] ?? null
            ),
            'tax_announcement' => $ns->sendTaxAnnouncement(
                $data['period_label'] ?? now()->subMonth()->format('F Y'),
                $data['period_type'] ?? 'monthly',
                $data['due_date'] ?? null
            ),
            'tax_invoice' => $this->fireLiveTaxInvoice($ns, $characterId, $data),
            'event_created' => $ns->sendEventCreated($this->buildFakeMiningEvent($data)),
            'event_started' => $ns->sendEventStarted($this->buildFakeMiningEvent($data), []),
            'event_completed' => $ns->sendEventCompleted($this->buildFakeMiningEvent($data), []),
            'moon_ready' => $ns->sendMoonArrival($data),
            'jackpot_detected' => $ns->sendJackpotDetected($data),
            'moon_chunk_unstable' => $ns->sendMoonChunkUnstable($data),
            'extraction_at_risk' => $ns->sendExtractionAtRisk(array_merge($data, [
                'alert_flavor' => $data['alert_flavor'] ?? 'fuel_critical',
                'moon_name' => $data['moon_name'] ?? 'Test Moon IV - Moon 3',
                'structure_name' => $data['structure_name'] ?? 'Diagnostic Athanor',
                'system_name' => $data['system_name'] ?? 'J-Space Test',
                'days_remaining' => $data['days_remaining'] ?? 2.4,
                'hours_remaining' => $data['hours_remaining'] ?? 57.6,
                'fuel_expires' => $data['fuel_expires'] ?? now()->addDays(2)->addHours(14)->format('Y-m-d H:i') . ' UTC',
                'estimated_value' => (int) ($data['estimated_value'] ?? 1200000000),
                'structure_corporation_id' => (int) ($data['structure_corporation_id'] ?? 98000001),
            ])),
            'extraction_lost' => $ns->sendExtractionLost(array_merge($data, [
                'moon_name' => $data['moon_name'] ?? 'Test Moon IV - Moon 3',
                'structure_name' => $data['structure_name'] ?? 'Diagnostic Athanor',
                'system_name' => $data['system_name'] ?? 'J-Space Test',
                'destroyed_at' => $data['destroyed_at'] ?? now()->subMinutes(30)->format('Y-m-d H:i') . ' UTC',
                'detection_source' => $data['detection_source'] ?? 'notification',
                'final_timer_result' => $data['final_timer_result'] ?? 'Defended and lost',
                'chunk_value' => (int) ($data['chunk_value'] ?? 1200000000),
                'killmail_url' => $data['killmail_url'] ?? 'https://zkillboard.com/',
                'structure_corporation_id' => (int) ($data['structure_corporation_id'] ?? 98000001),
            ])),
            'theft_detected' => $ns->sendTheftDetected($this->buildFakeTheftIncident($data), [
                'incident_url' => $data['incident_url'] ?? route('mining-manager.theft.index'),
            ]),
            'critical_theft' => $ns->sendCriticalTheft($this->buildFakeTheftIncident(array_merge($data, ['severity' => 'critical'])), [
                'incident_url' => $data['incident_url'] ?? route('mining-manager.theft.index'),
            ]),
            'active_theft' => $ns->sendActiveTheft($this->buildFakeTheftIncident($data), [
                'incident_url' => $data['incident_url'] ?? route('mining-manager.theft.index'),
                'new_mining_value' => (float) ($data['new_mining_value'] ?? 10000000),
                'last_activity' => $data['last_activity'] ?? now()->format('Y-m-d H:i:s'),
            ]),
            'incident_resolved' => $ns->sendIncidentResolved($this->buildFakeTheftIncident(array_merge($data, ['status' => 'resolved'])), [
                'incident_url' => $data['incident_url'] ?? route('mining-manager.theft.index'),
                'resolved_by' => $data['resolved_by'] ?? 'Diagnostic Test',
            ]),
            'report_generated' => $ns->sendReportGenerated($this->buildFakeMiningReport($data), $data),
            default => ['error' => "Unknown notification type: {$type}"],
        };
    }

    /**
     * Tax invoice requires a TaxInvoice model — build a fake unsaved one
     * and call sendTaxInvoice.
     */
    protected function fireLiveTaxInvoice(
        \MiningManager\Services\Notification\NotificationService $ns,
        int $characterId,
        array $data
    ): array {
        // TaxInvoice uses `expires_at` as its deadline (no `due_date` column on
        // the model — that lives on mining_taxes). `due_date` would be silently
        // dropped by the mass-assignment filter since it's not in $fillable.
        $invoice = new \MiningManager\Models\TaxInvoice([
            'character_id' => $characterId,
            'amount' => (float) ($data['amount'] ?? 5000000),
            'expires_at' => \Carbon\Carbon::parse($data['due_date'] ?? now()->addDays(7)),
        ]);
        $invoice->id = 99999;
        return $ns->sendTaxInvoice($invoice);
    }

    /**
     * Build an unsaved MiningEvent model from test data for event_* dispatches.
     */
    protected function buildFakeMiningEvent(array $data): \MiningManager\Models\MiningEvent
    {
        $event = new \MiningManager\Models\MiningEvent([
            'name' => $data['event_name'] ?? 'Diagnostic Test Event',
            'type' => $data['event_type_key'] ?? 'mining_op',
            'tax_modifier' => 1.0,
            'location_scope' => 'any',
            'corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
            'status' => 'active',
            'total_mined' => (float) ($data['total_mined'] ?? 150000000),
            'participant_count' => (int) ($data['participants'] ?? 12),
            'start_time' => \Carbon\Carbon::parse($data['start_date'] ?? now()),
            'end_time' => \Carbon\Carbon::parse($data['end_date'] ?? now()->addHours(4)),
        ]);
        $event->id = 99999;
        return $event;
    }

    /**
     * Build an unsaved TheftIncident for theft_* dispatches.
     */
    protected function buildFakeTheftIncident(array $data): \MiningManager\Models\TheftIncident
    {
        $incident = new \MiningManager\Models\TheftIncident([
            'character_id' => (int) ($data['character_id'] ?? 123456789),
            'character_name' => $data['character_name'] ?? 'Test Miner',
            'corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
            'severity' => $data['severity'] ?? 'medium',
            'ore_value' => (float) ($data['ore_value'] ?? 50000000),
            'tax_owed' => (float) ($data['tax_owed'] ?? 5000000),
            'status' => $data['status'] ?? 'detected',
            'incident_date' => now(),
            'activity_count' => (int) ($data['activity_count'] ?? 1),
        ]);
        $incident->id = 99999;
        return $incident;
    }

    /**
     * Build an unsaved MiningReport for report_generated dispatches.
     */
    protected function buildFakeMiningReport(array $data): \MiningManager\Models\MiningReport
    {
        $period = $data['period'] ?? [];
        $start = isset($period['start']) ? \Carbon\Carbon::parse($period['start']) : now()->subMonth()->startOfMonth();
        $end = isset($period['end']) ? \Carbon\Carbon::parse($period['end']) : now()->subMonth()->endOfMonth();

        $report = new \MiningManager\Models\MiningReport([
            'report_type' => $data['report_type'] ?? 'monthly',
            'start_date' => $start,
            'end_date' => $end,
            'format' => $data['format'] ?? 'json',
            'generated_at' => now(),
            'generated_by' => $data['generated_by'] ?? 'Diagnostic Test',
        ]);
        $report->id = 99999;
        return $report;
    }

    /**
     * Compact the full channel-keyed result map into a logger-friendly shape.
     */
    protected function summariseLiveResults(array $results): array
    {
        if (isset($results['error'])) {
            return ['error' => $results['error']];
        }
        if (isset($results['skipped'])) {
            return ['skipped' => true, 'reason' => $results['reason'] ?? 'unknown'];
        }

        $summary = [];
        foreach ($results as $channel => $channelResult) {
            if (!is_array($channelResult)) {
                $summary[$channel] = $channelResult;
                continue;
            }
            if (isset($channelResult['skipped']) && $channelResult['skipped']) {
                // Common cause: no webhook has notify_{type}=true yet.
                // Surface the reason explicitly so the user knows this isn't
                // a bug — they just need to subscribe a webhook.
                $reason = $channelResult['reason'] ?? 'no subscribed webhooks';
                $summary[$channel] = "skipped ({$reason})";
                continue;
            }
            if (isset($channelResult['sent']) && is_array($channelResult['sent'])) {
                $summary[$channel] = [
                    'sent' => count($channelResult['sent']),
                    'failed' => count($channelResult['failed'] ?? []),
                ];
            } elseif (isset($channelResult['success'])) {
                $summary[$channel] = $channelResult['success'] ? 'ok' : 'failed';
            } elseif (isset($channelResult['error'])) {
                $summary[$channel] = 'error: ' . $channelResult['error'];
            } else {
                $summary[$channel] = 'unknown';
            }
        }
        return $summary;
    }

    /**
     * Emit per-channel + per-webhook log lines from a live-dispatch result map.
     */
    protected function summariseLiveChannels(array &$logs, array $results): void
    {
        if (isset($results['error'])) {
            $this->addLog($logs, 'error', 'Dispatcher error: ' . $results['error']);
            return;
        }

        if (isset($results['skipped'])) {
            $reason = $results['reason'] ?? 'unknown';
            $this->addLog($logs, 'warn', "Dispatcher SKIPPED the notification. Reason: {$reason}");
            return;
        }

        foreach ($results as $channel => $channelResult) {
            if (!is_array($channelResult)) continue;

            $label = strtoupper($channel);

            if (isset($channelResult['error'])) {
                $this->addLog($logs, 'warn', "{$label}: {$channelResult['error']}");
                continue;
            }
            if (isset($channelResult['skipped']) && $channelResult['skipped']) {
                $this->addLog($logs, 'skip', "{$label}: skipped");
                continue;
            }

            $sent = $channelResult['sent'] ?? [];
            $failed = $channelResult['failed'] ?? [];

            if (is_array($sent) && is_array($failed)) {
                $this->addLog($logs, count($sent) > 0 ? 'ok' : 'warn',
                    "{$label}: " . count($sent) . " sent, " . count($failed) . " failed"
                );
                foreach ($sent as $webhookId) {
                    $this->addLog($logs, 'info', "  → webhook #{$webhookId}: delivered");
                }
                foreach ($failed as $fail) {
                    $wid = $fail['webhook_id'] ?? 'unknown';
                    $err = $fail['error'] ?? 'unknown error';
                    $this->addLog($logs, 'warn', "  → webhook #{$wid}: {$err}");
                }
            } elseif (isset($channelResult['success'])) {
                $ok = $channelResult['success'];
                $this->addLog($logs, $ok ? 'ok' : 'warn',
                    "{$label}: " . ($ok ? 'sent successfully' : ('failed — ' . ($channelResult['error'] ?? 'unknown')))
                );
            }
        }
    }

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
            'moon_chunk_unstable' => 'Moon Chunk Unstable (capital safety)',
            'extraction_at_risk' => 'Extraction at Risk (fuel / attack / reinforced)',
            'extraction_lost' => 'Extraction Lost (structure destroyed)',
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
            // formatMessageForESI is public — preview can call directly.
            $message = $notificationService->formatMessageForESI($typeConst, $data);

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

        // Format Discord embed — all surfaces (theft/moon/report/tax/event)
        // now live in NotificationService as of Phases B-D. We still reflect
        // into the protected formatter to build the preview without actually
        // sending to any webhook.
        $isMoonType = in_array($type, ['moon_ready', 'jackpot_detected', 'moon_chunk_unstable', 'extraction_at_risk', 'extraction_lost']);
        $isTheftType = in_array($type, ['theft_detected', 'critical_theft', 'active_theft', 'incident_resolved']);
        $isReportType = $type === 'report_generated';
        $message = null;

        if ($isTheftType) {
            // Theft notifications live in NotificationService as of Phase D
            // of the notification consolidation.
            try {
                $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
                $testIncident = new \MiningManager\Models\TheftIncident([
                    'character_id' => $data['character_id'] ?? 123456789,
                    'character_name' => $data['character_name'] ?? 'Test Miner',
                    'severity' => $data['severity'] ?? 'medium',
                    'ore_value' => $data['ore_value'] ?? 50000000,
                    'tax_owed' => $data['tax_owed'] ?? 5000000,
                    'status' => $type === 'incident_resolved' ? 'resolved' : 'open',
                    'incident_date' => now(),
                ]);
                if ($type === 'active_theft') {
                    $testIncident->activity_count = $data['activity_count'] ?? 3;
                    $testIncident->last_activity_at = now();
                }
                $additionalData = array_intersect_key($data, array_flip([
                    'incident_url', 'test_mode', 'new_mining_value', 'last_activity', 'resolved_by',
                ]));

                // buildTheftData + formatMessageForDiscord + getDiscordRoleMention
                // are all public on NotificationService — call directly.
                $previewData = $notificationService->buildTheftData($testIncident, $additionalData);

                $typeConst = match ($type) {
                    'critical_theft' => \MiningManager\Services\Notification\NotificationService::TYPE_CRITICAL_THEFT,
                    'active_theft' => \MiningManager\Services\Notification\NotificationService::TYPE_ACTIVE_THEFT,
                    'incident_resolved' => \MiningManager\Services\Notification\NotificationService::TYPE_INCIDENT_RESOLVED,
                    default => \MiningManager\Services\Notification\NotificationService::TYPE_THEFT_DETECTED,
                };

                $message = $notificationService->formatMessageForDiscord($typeConst, $previewData);
                $embed = $message['embeds'][0] ?? [];

                // Add role mention via the trait helper (also on NotificationService).
                if ($webhook) {
                    $roleMention = $notificationService->getDiscordRoleMention($type, $webhook);
                    if ($roleMention) {
                        $message['content'] = $roleMention;
                    }
                }

                $this->addLog($logs, 'ok', 'Discord theft embed formatted via NotificationService: ' . ($embed['title'] ?? 'N/A'));
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format theft Discord embed: ' . $e->getMessage());
            }
        } elseif ($isReportType) {
            // Report notifications live in NotificationService as of Phase B
            // of the notification consolidation. Build the same $data shape
            // the consolidated formatter expects, then run it through the
            // formatter for the preview. (formatMessageForDiscord is public.)
            try {
                $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);

                // Minimal $data compatible with the TYPE_REPORT_GENERATED
                // branch of formatFieldsForDiscord / formatMessageForDiscord.
                $previewData = array_merge([
                    'report_id' => $data['report_id'] ?? 99999,
                    'report_type' => $data['report_type'] ?? 'monthly',
                    'period_str' => $data['period_str']
                        ?? ((isset($data['period']['start'], $data['period']['end']))
                            ? ($data['period']['start'] . ' to ' . $data['period']['end'])
                            : 'N/A'),
                    'period' => $data['period'] ?? [],
                    'unique_miners' => $data['unique_miners'] ?? ($data['summary']['unique_miners'] ?? 0),
                    'total_value' => $data['total_value'] ?? ($data['summary']['total_value'] ?? 0),
                    'is_current_month' => $data['is_current_month'] ?? ($data['taxes']['is_current_month'] ?? false),
                    'estimated_tax' => $data['estimated_tax'] ?? ($data['taxes']['estimated_tax'] ?? 0),
                    'total_paid' => $data['total_paid'] ?? ($data['taxes']['total_paid'] ?? 0),
                    'unpaid' => $data['unpaid'] ?? ($data['taxes']['unpaid'] ?? 0),
                    'collection_rate' => $data['collection_rate'] ?? ($data['taxes']['collection_rate'] ?? null),
                    'description' => $data['description'] ?? 'Preview: sample report notification.',
                    'footer_extra' => $data['footer_extra'] ?? '',
                ], $data);

                $message = $notificationService->formatMessageForDiscord(\MiningManager\Services\Notification\NotificationService::TYPE_REPORT_GENERATED, $previewData);
                $embed = $message['embeds'][0] ?? [];
                $this->addLog($logs, 'ok', 'Discord report embed formatted via NotificationService: ' . ($embed['title'] ?? 'N/A'));
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format report Discord embed: ' . $e->getMessage());
            }
        } elseif ($isMoonType) {
            // Moon notifications live in NotificationService as of Phase C
            // of the notification consolidation. formatMessageForDiscord
            // is public — call directly.
            try {
                $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);

                $nsType = match ($type) {
                    'jackpot_detected' => \MiningManager\Services\Notification\NotificationService::TYPE_JACKPOT_DETECTED,
                    'moon_chunk_unstable' => \MiningManager\Services\Notification\NotificationService::TYPE_MOON_CHUNK_UNSTABLE,
                    'extraction_at_risk' => \MiningManager\Services\Notification\NotificationService::TYPE_EXTRACTION_AT_RISK,
                    'extraction_lost' => \MiningManager\Services\Notification\NotificationService::TYPE_EXTRACTION_LOST,
                    default => \MiningManager\Services\Notification\NotificationService::TYPE_MOON_READY,
                };

                // Provide a sensible description for the preview when the
                // caller didn't supply one.
                $previewData = $data;
                // Seed reasonable defaults for extraction_at_risk preview so
                // the Discord embed title resolver can pick the right flavor.
                if ($type === 'extraction_at_risk' && !isset($previewData['alert_flavor'])) {
                    $previewData['alert_flavor'] = 'fuel_critical';
                }
                if (!isset($previewData['description'])) {
                    $previewData['description'] = match ($nsType) {
                        \MiningManager\Services\Notification\NotificationService::TYPE_JACKPOT_DETECTED
                            => isset($previewData['reported_by'])
                                ? 'A jackpot moon extraction has been reported by a fleet member. Will be verified automatically when mining data arrives.'
                                : 'A jackpot moon extraction has been confirmed! Miners found +100% variant ores in the belt.',
                        \MiningManager\Services\Notification\NotificationService::TYPE_MOON_CHUNK_UNSTABLE
                            => '⚠️ This chunk will enter **unstable state** soon. Capital ship pilots (Rorquals, Orcas) should dock up or warp to safety — unstable chunks are known hotspots for hostile activity.',
                        \MiningManager\Services\Notification\NotificationService::TYPE_EXTRACTION_AT_RISK
                            => '🔥 **Preview** — refinery running an active extraction is under threat. Flavor can be fuel_critical / shield_reinforced / armor_reinforced / hull_reinforced.',
                        \MiningManager\Services\Notification\NotificationService::TYPE_EXTRACTION_LOST
                            => '☠️ **Preview** — refinery destroyed mid-extraction. Chunk lost. Tax reconciliation and miner comms recommended.',
                        default => 'A moon chunk is ready for mining!',
                    };
                }

                $message = $notificationService->formatMessageForDiscord($nsType, $previewData);
                $embed = $message['embeds'][0] ?? [];
                $this->addLog($logs, 'ok', 'Discord moon embed formatted via NotificationService: ' . ($embed['title'] ?? 'N/A'));
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
                $message = $notificationService->formatMessageForDiscord($typeConst, $data);
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

        // Format message — all surfaces now live in NotificationService as of
        // Phases B-D. The branches below stay type-specialised because some
        // surfaces (moon, report) build inline field layouts in this preview
        // rather than reflecting into protected formatters.
        $isMoonType = in_array($type, ['moon_ready', 'jackpot_detected', 'moon_chunk_unstable', 'extraction_at_risk', 'extraction_lost']);
        $isTheftType = in_array($type, ['theft_detected', 'critical_theft', 'active_theft', 'incident_resolved']);
        $isReportType = $type === 'report_generated';
        $message = null;

        if ($isTheftType) {
            // Theft Slack formatting now goes through NotificationService
            // (Phase D). The rich Block Kit layout used by the old WebhookService
            // has been replaced with the simpler attachments-style output that
            // matches the other surfaces.
            try {
                $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
                $testIncident = new \MiningManager\Models\TheftIncident([
                    'character_id' => $data['character_id'] ?? 123456789,
                    'character_name' => $data['character_name'] ?? 'Test Miner',
                    'severity' => $data['severity'] ?? 'medium',
                    'ore_value' => $data['ore_value'] ?? 50000000,
                    'tax_owed' => $data['tax_owed'] ?? 5000000,
                    'status' => $type === 'incident_resolved' ? 'resolved' : 'open',
                    'incident_date' => now(),
                ]);
                if ($type === 'active_theft') {
                    $testIncident->activity_count = $data['activity_count'] ?? 3;
                    $testIncident->last_activity_at = now();
                }
                $additionalData = array_intersect_key($data, array_flip([
                    'incident_url', 'test_mode', 'new_mining_value', 'last_activity', 'resolved_by',
                ]));

                $previewData = $notificationService->buildTheftData($testIncident, $additionalData);

                $typeConst = match ($type) {
                    'critical_theft' => \MiningManager\Services\Notification\NotificationService::TYPE_CRITICAL_THEFT,
                    'active_theft' => \MiningManager\Services\Notification\NotificationService::TYPE_ACTIVE_THEFT,
                    'incident_resolved' => \MiningManager\Services\Notification\NotificationService::TYPE_INCIDENT_RESOLVED,
                    default => \MiningManager\Services\Notification\NotificationService::TYPE_THEFT_DETECTED,
                };

                $message = $notificationService->formatMessageForSlack($typeConst, $previewData);
                $title = $notificationService->getEventTitle($type);
                $this->addLog($logs, 'ok', "Slack theft message formatted via NotificationService: {$title}");
            } catch (\Exception $e) {
                $this->addLog($logs, 'warn', 'Could not format theft Slack message: ' . $e->getMessage());
            }
        } elseif ($isReportType) {
            // Report notifications live in NotificationService (Phase B).
            try {
                $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
                $title = $notificationService->getEventTitle('report_generated');

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
            // Build Slack moon payload matching production format (previously in
            // WebhookService, now ported into NotificationService::formatFieldsForSlack
            // TYPE_MOON_READY / TYPE_JACKPOT_DETECTED branches — Phase C of the
            // notification consolidation). Kept inline here to avoid reflection.
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

            $title = app(\MiningManager\Services\Notification\NotificationService::class)->getEventTitle($moonEventType);

            $message = [
                'text' => $title,
                'blocks' => [
                    ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $title]],
                    ['type' => 'section', 'fields' => $fields],
                ],
            ];
            $this->addLog($logs, 'ok', "Slack moon message formatted via NotificationService: {$title}");
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
                $message = $notificationService->formatMessageForSlack($typeConst, $data);
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
                'estimated_value' => 2500000000,
                'ore_summary' => "Glistening Sylvite: 45.0% (12,000 m³)\nShimmering Sperrylite: 30.0% (8,000 m³)\nGlistening Coesite: 25.0% (6,500 m³)",
                'extraction_id' => 1,
                'extraction_url' => rtrim(config('app.url', ''), '/') . '/mining-manager/moon/1',
                'corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
            ],
            'moon_chunk_unstable' => [
                'moon_name' => $request->input('test_moon_name', 'Perimeter I - Moon 1'),
                'structure_name' => $request->input('test_structure_name', 'Athanor - Test Moon'),
                'natural_decay_time' => now()->addHours(2)->format('Y-m-d H:i') . ' UTC',
                'time_until_unstable' => '1h 57m',
                'estimated_value' => 250000000,
                'extraction_id' => 1,
                'extraction_url' => rtrim(config('app.url', ''), '/') . '/mining-manager/moon/1',
                'corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
            ],
            'extraction_at_risk' => [
                // Default flavor for the Fire Live / Fire ALL preview. Operators
                // can override via test_* inputs if they want to preview a
                // specific threat flavor (shield_reinforced, armor_reinforced,
                // hull_reinforced).
                'alert_flavor' => $request->input('test_alert_flavor', 'fuel_critical'),
                'moon_name' => $request->input('test_moon_name', 'Perimeter I - Moon 1'),
                'structure_name' => $request->input('test_structure_name', 'Athanor - Test Moon'),
                'system_name' => $request->input('test_system_name', 'Perimeter'),
                'days_remaining' => (float) $request->input('test_days_remaining', 2.4),
                'hours_remaining' => (float) $request->input('test_hours_remaining', 57.6),
                'fuel_expires' => now()->addDays(2)->addHours(14)->format('Y-m-d H:i') . ' UTC',
                'timer_ends_at' => now()->addHours(24)->format('Y-m-d H:i') . ' UTC',
                'estimated_value' => (int) $request->input('test_estimated_value', 1200000000),
                'extraction_url' => rtrim(config('app.url', ''), '/') . '/mining-manager/moon/1',
                'structure_corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
                'corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
            ],
            'extraction_lost' => [
                'moon_name' => $request->input('test_moon_name', 'Perimeter I - Moon 1'),
                'structure_name' => $request->input('test_structure_name', 'Athanor - Test Moon'),
                'system_name' => $request->input('test_system_name', 'Perimeter'),
                'destroyed_at' => now()->subMinutes(30)->format('Y-m-d H:i') . ' UTC',
                'detection_source' => $request->input('test_detection_source', 'notification'),
                'final_timer_result' => $request->input('test_final_timer_result', 'Defended and lost'),
                'chunk_value' => (int) $request->input('test_chunk_value', 1200000000),
                'killmail_url' => 'https://zkillboard.com/',
                'extraction_url' => rtrim(config('app.url', ''), '/') . '/mining-manager/moon/1',
                'structure_corporation_id' => (int) $this->settingsService->getSetting('general.moon_owner_corporation_id', 0),
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
