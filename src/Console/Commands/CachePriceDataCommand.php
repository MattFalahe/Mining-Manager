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

        // Compressed regular ores (for haulers/traders)
        $compressedOreTypes = [
            // Base compressed ores (15 types)
            28432, // Compressed Veldspar
            28433, // Compressed Scordite
            28434, // Compressed Pyroxeres
            28435, // Compressed Plagioclase
            28436, // Compressed Omber
            28437, // Compressed Kernite
            28438, // Compressed Jaspet
            28439, // Compressed Hemorphite
            28440, // Compressed Hedbergite
            28441, // Compressed Gneiss
            28442, // Compressed Dark Ochre
            28443, // Compressed Crokite
            28444, // Compressed Bistot
            28445, // Compressed Arkonor
            28446, // Compressed Mercoxit
            
            // Compressed ore variants (30 types)
            28427, // Compressed Dense Veldspar
            28428, // Compressed Concentrated Veldspar
            28429, // Compressed Condensed Scordite
            28430, // Compressed Massive Scordite
            28421, // Compressed Solid Pyroxeres
            28422, // Compressed Viscous Pyroxeres
            28415, // Compressed Azure Plagioclase
            28416, // Compressed Rich Plagioclase
            28425, // Compressed Silvery Omber
            28426, // Compressed Golden Omber
            28419, // Compressed Luminous Kernite
            28420, // Compressed Fiery Kernite
            28417, // Compressed Pure Jaspet
            28418, // Compressed Pristine Jaspet
            28413, // Compressed Vivid Hemorphite
            28414, // Compressed Radiant Hemorphite
            28409, // Compressed Vitric Hedbergite
            28410, // Compressed Glazed Hedbergite
            28411, // Compressed Iridescent Gneiss
            28412, // Compressed Prismatic Gneiss
            28407, // Compressed Onyx Ochre
            28408, // Compressed Obsidian Ochre
            28405, // Compressed Sharp Crokite
            28406, // Compressed Crystalline Crokite
            28401, // Compressed Triclinic Bistot
            28404, // Compressed Monoclinic Bistot
            28397, // Compressed Crimson Arkonor
            28400, // Compressed Prime Arkonor
            28398, // Compressed Magma Mercoxit
            28399, // Compressed Vitreous Mercoxit
        ];

        // ============================================
        // MOON ORE TYPE IDs - COMPLETE (60 items)
        // VERIFIED AGAINST DATABASE - ALL CORRECT
        // ============================================
        $moonOreTypes = [
            // ========== R4 (Ubiquitous) - 12 items ==========
            
            // Bitumens family (Hydrocarbons)
            45492,  // Bitumens (base)
            46284,  // Brimful Bitumens (+15%)
            46285,  // Glistening Bitumens (+100% jackpot)
            
            // Coesite family (Silicates)
            45493,  // Coesite (base)
            46286,  // Brimful Coesite (+15%)
            46287,  // Glistening Coesite (+100% jackpot)
            
            // Sylvite family (Evaporite Deposits)
            45491,  // Sylvite (base)
            46282,  // Brimful Sylvite (+15%)
            46283,  // Glistening Sylvite (+100% jackpot)
            
            // Zeolites family (Atmospheric Gases)
            45490,  // Zeolites (base)
            46280,  // Brimful Zeolites (+15%)
            46281,  // Glistening Zeolites (+100% jackpot)
            
            // ========== R8 (Common) - 12 items ==========
            
            // Cobaltite family (Cobalt)
            45494,  // Cobaltite (base)
            46288,  // Copious Cobaltite (+15%)
            46289,  // Twinkling Cobaltite (+100% jackpot)
            
            // Euxenite family (Scandium)
            45495,  // Euxenite (base)
            46290,  // Copious Euxenite (+15%)
            46291,  // Twinkling Euxenite (+100% jackpot)
            
            // Scheelite family (Tungsten)
            45497,  // Scheelite (base)
            46294,  // Copious Scheelite (+15%)
            46295,  // Twinkling Scheelite (+100% jackpot)
            
            // Titanite family (Titanium)
            45496,  // Titanite (base)
            46292,  // Copious Titanite (+15%)
            46293,  // Twinkling Titanite (+100% jackpot)
            
            // ========== R16 (Uncommon) - 12 items ==========
            
            // Chromite family (Chromium)
            45501,  // Chromite (base)
            46302,  // Lavish Chromite (+15%)
            46303,  // Shimmering Chromite (+100% jackpot)
            
            // Otavite family (Cadmium)
            45498,  // Otavite (base)
            46296,  // Lavish Otavite (+15%)
            46297,  // Shimmering Otavite (+100% jackpot)
            
            // Sperrylite family (Platinum)
            45499,  // Sperrylite (base)
            46298,  // Lavish Sperrylite (+15%)
            46299,  // Shimmering Sperrylite (+100% jackpot)
            
            // Vanadinite family (Vanadium)
            45500,  // Vanadinite (base)
            46300,  // Lavish Vanadinite (+15%)
            46301,  // Shimmering Vanadinite (+100% jackpot)
            
            // ========== R32 (Rare) - 12 items ==========
            
            // Carnotite family (Technetium)
            45502,  // Carnotite (base)
            46304,  // Replete Carnotite (+15%)
            46305,  // Glowing Carnotite (+100% jackpot)
            
            // Cinnabar family (Mercury)
            45506,  // Cinnabar (base)
            46310,  // Replete Cinnabar (+15%)
            46311,  // Glowing Cinnabar (+100% jackpot)
            
            // Pollucite family (Caesium)
            45504,  // Pollucite (base)
            46308,  // Replete Pollucite (+15%)
            46309,  // Glowing Pollucite (+100% jackpot)
            
            // Zircon family (Hafnium)
            45503,  // Zircon (base)
            46306,  // Replete Zircon (+15%)
            46307,  // Glowing Zircon (+100% jackpot)
            
            // ========== R64 (Exceptional) - 12 items ==========
            
            // Xenotime family (Dysprosium)
            45510,  // Xenotime (base)
            46312,  // Bountiful Xenotime (+15%)
            46313,  // Shining Xenotime (+100% jackpot)
            
            // Monazite family (Neodymium)
            45511,  // Monazite (base)
            46314,  // Bountiful Monazite (+15%)
            46315,  // Shining Monazite (+100% jackpot)
            
            // Loparite family (Promethium)
            45512,  // Loparite (base)
            46316,  // Bountiful Loparite (+15%)
            46317,  // Shining Loparite (+100% jackpot)
            
            // Ytterbite family (Thulium)
            45513,  // Ytterbite (base)
            46318,  // Bountiful Ytterbite (+15%)
            46319,  // Shining Ytterbite (+100% jackpot)
        ];

        // Moon materials (R4, R8, R16, R32, R64)
        // These are the refined products from moon ores
        $moonMaterialTypes = [
            // R4 Materials
            16633, // Hydrocarbons
            16635, // Evaporite Deposits
            16636, // Silicates
            16638, // Atmospheric Gases
            
            // R8 Materials
            16634, // Titanium
            16637, // Tungsten
            16639, // Cobalt
            16655, // Scandium
            
            // R16 Materials
            16640, // Cadmium
            16641, // Chromium
            16644, // Vanadium
            16647, // Platinum
            
            // R32 Materials
            16642, // Caesium
            16643, // Technetium
            16646, // Mercury
            16648, // Hafnium
            
            // R64 Materials
            16649, // Neodymium
            16650, // Dysprosium
            16651, // Thulium
            16652, // Promethium
        ];

        // =====================================================
        // COMPRESSED MOON ORES - COMPLETE (60 items)
        // VERIFIED AGAINST DATABASE - ALL CORRECT
        // =====================================================
        $compressedMoonOreTypes = [
            // ========== R4 (Ubiquitous) Compressed - 12 items ==========
            
            // Compressed Bitumens family
            62454,  // Compressed Bitumens
            62455,  // Compressed Brimful Bitumens
            62456,  // Compressed Glistening Bitumens
            
            // Compressed Coesite family
            62457,  // Compressed Coesite
            62458,  // Compressed Brimful Coesite
            62459,  // Compressed Glistening Coesite
            
            // Compressed Sylvite family
            62460,  // Compressed Sylvite
            62461,  // Compressed Brimful Sylvite
            62466,  // Compressed Glistening Sylvite
            
            // Compressed Zeolites family
            62463,  // Compressed Zeolites
            62464,  // Compressed Brimful Zeolites
            62467,  // Compressed Glistening Zeolites
            
            // ========== R8 (Common) Compressed - 12 items ==========
            
            // Compressed Cobaltite family
            62474,  // Compressed Cobaltite
            62475,  // Compressed Copious Cobaltite
            62476,  // Compressed Twinkling Cobaltite
            
            // Compressed Euxenite family
            62471,  // Compressed Euxenite
            62472,  // Compressed Copious Euxenite
            62473,  // Compressed Twinkling Euxenite
            
            // Compressed Scheelite family
            62468,  // Compressed Scheelite
            62469,  // Compressed Copious Scheelite
            62470,  // Compressed Twinkling Scheelite
            
            // Compressed Titanite family
            62477,  // Compressed Titanite
            62478,  // Compressed Copious Titanite
            62479,  // Compressed Twinkling Titanite
            
            // ========== R16 (Uncommon) Compressed - 12 items ==========
            
            // Compressed Chromite family
            62480,  // Compressed Chromite
            62481,  // Compressed Lavish Chromite
            62482,  // Compressed Shimmering Chromite
            
            // Compressed Otavite family
            62483,  // Compressed Otavite
            62484,  // Compressed Lavish Otavite
            62485,  // Compressed Shimmering Otavite
            
            // Compressed Sperrylite family
            62486,  // Compressed Sperrylite
            62487,  // Compressed Lavish Sperrylite
            62488,  // Compressed Shimmering Sperrylite
            
            // Compressed Vanadinite family
            62489,  // Compressed Vanadinite
            62490,  // Compressed Lavish Vanadinite
            62491,  // Compressed Shimmering Vanadinite
            
            // ========== R32 (Rare) Compressed - 12 items ==========
            
            // Compressed Carnotite family
            62492,  // Compressed Carnotite
            62493,  // Compressed Replete Carnotite
            62494,  // Compressed Glowing Carnotite
            
            // Compressed Cinnabar family
            62495,  // Compressed Cinnabar
            62496,  // Compressed Replete Cinnabar
            62497,  // Compressed Glowing Cinnabar
            
            // Compressed Pollucite family
            62498,  // Compressed Pollucite
            62499,  // Compressed Replete Pollucite
            62500,  // Compressed Glowing Pollucite
            
            // Compressed Zircon family
            62501,  // Compressed Zircon
            62502,  // Compressed Replete Zircon
            62503,  // Compressed Glowing Zircon
            
            // ========== R64 (Exceptional) Compressed - 12 items ==========
            
            // Compressed Xenotime family
            62510,  // Compressed Xenotime
            62511,  // Compressed Bountiful Xenotime
            62512,  // Compressed Shining Xenotime
            
            // Compressed Monazite family
            62507,  // Compressed Monazite
            62508,  // Compressed Bountiful Monazite
            62509,  // Compressed Shining Monazite
            
            // Compressed Loparite family
            62504,  // Compressed Loparite
            62505,  // Compressed Bountiful Loparite
            62506,  // Compressed Shining Loparite
            
            // Compressed Ytterbite family
            62513,  // Compressed Ytterbite
            62514,  // Compressed Bountiful Ytterbite
            62515,  // Compressed Shining Ytterbite
        ];

        $mineralTypes = [
            34, 35, 36, 37, 38, 39, 40, // Tritanium through Megacyte
            11399, // Morphite
        ];

        // Ice types - Standard + ALL Compressed variants
        $iceTypes = [
            // Standard Ice (8 types)
            16262, // Clear Icicle
            16263, // Glacial Mass
            16264, // Blue Ice
            16265, // White Glaze
            16266, // Glare Crust
            16267, // Dark Glitter
            16268, // Gelidus
            16269, // Krystallos
            
            // Compressed Ice (8 types)
            17975, // Compressed Clear Icicle
            17976, // Compressed Glacial Mass
            17977, // Compressed Blue Ice
            17978, // Compressed White Glaze
            17979, // Compressed Glare Crust
            17980, // Compressed Dark Glitter
            17981, // Compressed Gelidus
            17982, // Compressed Krystallos
        ];

        // Gas types - Fullerites + Booster Gases
        $gasTypes = [
            // Fullerites (C-X) - 8 types
            30370, // Fullerite-C50
            30371, // Fullerite-C60
            30372, // Fullerite-C70
            30373, // Fullerite-C72
            30374, // Fullerite-C84
            30375, // Fullerite-C28
            30377, // Fullerite-C320
            30378, // Fullerite-C540
            
            // Booster Gases - 4 types
            25276, // Malachite Cytoserocin
            25278, // Vermillion Cytoserocin
            25274, // Viridian Cytoserocin
            25268, // Amber Cytoserocin
        ];

        // Ice products (refined from ice)
        $iceProductTypes = [
            16272, // Heavy Water
            16274, // Helium Isotopes
            17889, // Hydrogen Isotopes
            16273, // Liquid Ozone
            17888, // Nitrogen Isotopes
            17887, // Oxygen Isotopes
            16275, // Strontium Clathrates
        ];

        // Return appropriate type IDs based on category
        switch ($category) {
            case 'ore':
                return $oreTypes;
            case 'compressed-ore':
                return $compressedOreTypes;
            case 'moon':
                return $moonOreTypes;
            case 'materials':
                return $moonMaterialTypes;
            case 'minerals':
                return $mineralTypes;
            case 'ice':
                return $iceTypes;
            case 'ice-products':
                return $iceProductTypes;
            case 'gas':
                return $gasTypes;
            case 'compressed':
                return array_merge($compressedOreTypes, $compressedMoonOreTypes);
            case 'all':
                return array_merge(
                    $oreTypes,
                    $compressedOreTypes,
                    $moonOreTypes,
                    $compressedMoonOreTypes,
                    $moonMaterialTypes,
                    $mineralTypes,
                    $iceTypes,
                    $iceProductTypes,
                    $gasTypes
                );
            default:
                return [];
        }
    }

    /**
     * Clean up old cache entries
     *
     * @return void
     */
    private function cleanupOldCache()
    {
        $this->info('Cleaning up old cache entries...');
        
        $deleted = $this->priceService->cleanupOldCache(7); // Remove entries older than 7 days
        
        if ($deleted > 0) {
            $this->info("Removed {$deleted} old cache entries");
        }
    }
}
