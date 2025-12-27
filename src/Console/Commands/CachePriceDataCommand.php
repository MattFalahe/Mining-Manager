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

        // Compressed regular ores (for haulers/traders) - CRITICAL
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

        // Moon ore type IDs (R4, R8, R16, R32, R64) - CORRECTED
        $moonOreTypes = [
            // R4 Ores (Ubiquitous)
            45506, // Bitumens
            45489, // Coesite
            45493, // Sylvite
            45497, // Zeolites
            
            // R8 Ores (Common)
            45494, // Cobaltite
            45495, // Euxenite
            46682, // Scheelite
            46683, // Titanite
            
            // R16 Ores (Uncommon)
            45492, // Chromite
            46679, // Otavite
            46687, // Sperrylite
            46688, // Vanadinite
            
            // R32 Ores (Rare)
            46677, // Carnotite
            45490, // Cinnabar
            46680, // Pollucite
            46681, // Zircon
            
            // R64 Ores (Exceptional)
            45491, // Xenotime
            46676, // Monazite
            46678, // Loparite
            46689, // Ytterbite
        ];

        // Moon materials (R4, R8, R16, R32, R64) - ADDED
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

        // Compressed moon ores (for traders/haulers)
        $compressedMoonOreTypes = [
            // Compressed R4 Ores
            46675, // Compressed Bitumens
            46676, // Compressed Coesite
            46677, // Compressed Sylvite
            46678, // Compressed Zeolites
            
            // Compressed R8 Ores
            46679, // Compressed Cobaltite
            46680, // Compressed Euxenite
            46681, // Compressed Scheelite
            46682, // Compressed Titanite
            
            // Compressed R16 Ores
            46683, // Compressed Chromite
            46684, // Compressed Otavite
            46685, // Compressed Sperrylite
            46686, // Compressed Vanadinite
            
            // Compressed R32 Ores
            46687, // Compressed Carnotite
            46688, // Compressed Cinnabar
            46689, // Compressed Pollucite
            46690, // Compressed Zircon
            
            // Compressed R64 Ores
            46691, // Compressed Xenotime
            46692, // Compressed Monazite
            46693, // Compressed Loparite
            46694, // Compressed Ytterbite
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
            
            // Compressed Ice (8 types) - COMPLETE
            17975, // Compressed Clear Icicle
            17976, // Compressed Glacial Mass
            17977, // Compressed Blue Ice
            17978, // Compressed White Glaze
            17979, // Compressed Glare Crust
            17980, // Compressed Dark Glitter
            17981, // Compressed Gelidus
            17982, // Compressed Krystallos
        ];

        // Gas types - Fullerites + Booster Gases (CORRECTED!)
        $gasTypes = [
            // Fullerites (C-X) - 8 types - FIXED TYPE IDs
            30370, // Fullerite-C50
            30371, // Fullerite-C60
            30372, // Fullerite-C70
            30373, // Fullerite-C72
            30374, // Fullerite-C84
            30375, // Fullerite-C28
            30377, // Fullerite-C320
            30378, // Fullerite-C540
            
            // Booster Gases - 4 types (uncompressed)
            25276, // Malachite Cytoserocin
            25278, // Vermillion Cytoserocin
            25274, // Viridian Cytoserocin
            25268, // Amber Cytoserocin
        ];

        // Ice products (refined from ice) - CRITICAL FOR REFINED ICE VALUE! - CORRECTED IDs
        $iceProductTypes = [
            16272, // Heavy Water ✅
            16274, // Helium Isotopes (was 16273)
            17889, // Hydrogen Isotopes (NEW!)
            16273, // Liquid Ozone (was 16275)
            17888, // Nitrogen Isotopes (was 16276)
            17887, // Oxygen Isotopes (was 16277)
            16275, // Strontium Clathrates (was 16278 which is Ice Harvester I!)
        ];

        switch ($category) {
            case 'ore':
                return $oreTypes;
            case 'compressed-ore':
                return $compressedOreTypes;
            case 'moon':
            case 'moon-ore':
                return $moonOreTypes;
            case 'materials':
            case 'moon-materials':
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
            case 'compressed-moon':
                return $compressedMoonOreTypes;
            case 'all':
                return array_merge(
                    $oreTypes,              // Regular ores (45)
                    $compressedOreTypes,    // Compressed regular ores (45)
                    $moonOreTypes,          // Moon ores (20)
                    $compressedMoonOreTypes,// Compressed moon ores (20)
                    $moonMaterialTypes,     // Moon materials refined (24)
                    $mineralTypes,          // Minerals refined (8)
                    $iceTypes,              // Ice raw (16)
                    $iceProductTypes,       // Ice products refined (7)
                    $gasTypes               // Gas (16)
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
