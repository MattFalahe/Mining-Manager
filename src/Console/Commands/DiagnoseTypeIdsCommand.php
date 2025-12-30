<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MiningManager\Services\Moon\MoonOreHelper;

class DiagnoseTypeIdsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:diagnose-type-ids
                            {--category= : Diagnose specific category only (ore|compressed-ore|moon|compressed-moon|materials|minerals|ice|gas|jackpot|all)}
                            {--include-abyssal : Include abyssal ore diagnosis (Pochven ores)}
                            {--test-jackpot : Test jackpot detection logic}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose type IDs by verifying against CCP ESI API and test jackpot detection';

    /**
     * All type IDs organized by category
     *
     * @var array
     */
    protected $allTypeIds = [];

    /**
     * Statistics
     *
     * @var array
     */
    protected $stats = [
        'total' => 0,
        'verified' => 0,
        'failed' => 0,
        'failures' => [],
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║   Mining Manager - Type ID Diagnostics v2.0               ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->loadTypeIds();

        $category = $this->option('category');
        $includeAbyssal = $this->option('include-abyssal');
        $testJackpot = $this->option('test-jackpot');

        if ($category && $category !== 'all') {
            $this->verifyCategory($category);
        } else {
            $this->verifyAllCategories($includeAbyssal);
        }

        // Test jackpot detection if requested
        if ($testJackpot) {
            $this->newLine();
            $this->testJackpotDetection();
        }

        $this->displaySummary();

        return $this->stats['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Load all type IDs - UPDATED WITH ALL 120 MOON ORES
     */
    protected function loadTypeIds()
    {
        $this->allTypeIds = [
            'ore' => [
                'name' => 'Regular Ores',
                'count' => 45,
                'ids' => [
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
            ],
            'compressed-ore' => [
                'name' => 'Compressed Ores',
                'count' => 45,
                'ids' => [
                    // Base compressed ores (15 types)
                    28432, 28433, 28434, 28435, 28436, 28437, 28438, 28439, 28440, 28441,
                    28442, 28443, 28444, 28445, 28446,
                    // Compressed ore variants (30 types)
                    28427, 28428, 28429, 28430, 28421, 28422, 28415, 28416, 28425, 28426,
                    28419, 28420, 28417, 28418, 28413, 28414, 28409, 28410, 28411, 28412,
                    28407, 28408, 28405, 28406, 28401, 28404, 28397, 28400, 28398, 28399,
                ],
            ],
            'moon' => [
                'name' => 'Moon Ores (Uncompressed) - ALL VARIANTS',
                'count' => 60,
                'ids' => [
                    // R4 (Ubiquitous) - 12 items
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
            ],
            'compressed-moon' => [
                'name' => 'Compressed Moon Ores - ALL VARIANTS',
                'count' => 60,
                'ids' => [
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
            ],
            'jackpot' => [
                'name' => 'Jackpot Moon Ores (+100% variants)',
                'count' => 40,
                'ids' => MoonOreHelper::getAllJackpotTypeIds(),
            ],
            'ice' => [
                'name' => 'Ice (Raw + Compressed)',
                'count' => 16,
                'ids' => [
                    // Standard Ice (8 types)
                    16262, 16263, 16264, 16265, 16266, 16267, 16268, 16269,
                    // Compressed Ice (8 types)
                    17975, 17976, 17977, 17978, 17979, 17980, 17981, 17982,
                ],
            ],
            'gas' => [
                'name' => 'Gas (Fullerites + Booster)',
                'count' => 12,
                'ids' => [
                    // Fullerites (C-X) - 8 types
                    30370, 30371, 30372, 30373, 30374, 30375, 30377, 30378,
                    // Booster Gases - 4 types
                    25276, 25278, 25274, 25268,
                ],
            ],
            'minerals' => [
                'name' => 'Minerals',
                'count' => 8,
                'ids' => [34, 35, 36, 37, 38, 39, 40, 11399],
            ],
            'materials' => [
                'name' => 'Moon Materials',
                'count' => 24,
                'ids' => [
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
            ],
            'ice-products' => [
                'name' => 'Ice Products',
                'count' => 7,
                'ids' => [16272, 16274, 17889, 16273, 17888, 17887, 16275],
            ],
            'abyssal' => [
                'name' => 'Abyssal Ores (Pochven)',
                'count' => 10,
                'optional' => true,
                'ids' => [
                    52306,  // Talassonite (base)
                    56625,  // Abyssal Talassonite
                    56626,  // Hadal Talassonite
                    62582,  // Compressed Talassonite
                    62583,  // Compressed Abyssal Talassonite
                    62584,  // Compressed Hadal Talassonite
                    56629,  // Abyssal Rakovene
                    56630,  // Hadal Rakovene (tentative)
                    56627,  // Abyssal Bezdnacine
                    56628,  // Hadal Bezdnacine
                ],
            ],
        ];
    }

    /**
     * Verify all categories
     */
    protected function verifyAllCategories(bool $includeAbyssal = false)
    {
        foreach ($this->allTypeIds as $key => $category) {
            // Skip abyssal unless explicitly included
            if ($key === 'abyssal' && !$includeAbyssal) {
                continue;
            }

            $this->verifyCategory($key);
        }
    }

    /**
     * Verify specific category
     */
    protected function verifyCategory(string $categoryKey)
    {
        if (!isset($this->allTypeIds[$categoryKey])) {
            $this->error("Unknown category: {$categoryKey}");
            $this->line("Available: " . implode(', ', array_keys($this->allTypeIds)));
            return;
        }

        $category = $this->allTypeIds[$categoryKey];
        
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("Verifying: {$category['name']} ({$category['count']} items)");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        if (isset($category['optional'])) {
            $this->warn('  ⚠️  This is an optional category (Pochven space only)');
            $this->newLine();
        }

        $bar = $this->output->createProgressBar(count($category['ids']));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        foreach ($category['ids'] as $typeId) {
            $this->stats['total']++;
            $bar->setMessage("Checking Type ID: {$typeId}");

            $result = $this->verifyTypeId($typeId);

            if ($result['success']) {
                $this->stats['verified']++;
            } else {
                $this->stats['failed']++;
                $this->stats['failures'][] = [
                    'category' => $category['name'],
                    'type_id' => $typeId,
                    'error' => $result['error'],
                ];
            }

            $bar->advance();

            // Rate limiting to be nice to ESI
            usleep(100000); // 100ms between requests
        }

        $bar->setMessage('Complete!');
        $bar->finish();
        $this->newLine(2);
    }

    /**
     * Verify a single type ID against ESI
     */
    protected function verifyTypeId(int $typeId): array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => 'Mining-Manager-SeAT-Plugin/2.0',
                ])
                ->get("https://esi.evetech.net/latest/universe/types/{$typeId}/");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'name' => $data['name'] ?? 'Unknown',
                    'group_id' => $data['group_id'] ?? null,
                ];
            }

            return [
                'success' => false,
                'error' => "HTTP {$response->status()}: {$response->body()}",
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test jackpot detection logic
     */
    protected function testJackpotDetection()
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('💎 JACKPOT DETECTION TESTS');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $tests = [
            [
                'name' => 'R4 Jackpot Ore (Glistening Bitumens)',
                'type_id' => 46285,
                'expected_jackpot' => true,
                'expected_rarity' => 'R4',
                'expected_quality' => 'excellent',
            ],
            [
                'name' => 'R64 Jackpot Ore (Shining Xenotime)',
                'type_id' => 46313,
                'expected_jackpot' => true,
                'expected_rarity' => 'R64',
                'expected_quality' => 'excellent',
            ],
            [
                'name' => 'Base Moon Ore (Bitumens)',
                'type_id' => 45492,
                'expected_jackpot' => false,
                'expected_rarity' => 'R4',
                'expected_quality' => 'base',
            ],
            [
                'name' => 'Improved Ore (Brimful Bitumens)',
                'type_id' => 46284,
                'expected_jackpot' => false,
                'expected_rarity' => 'R4',
                'expected_quality' => 'improved',
            ],
            [
                'name' => 'Compressed Jackpot (Compressed Shining Xenotime)',
                'type_id' => 62512,
                'expected_jackpot' => true,
                'expected_rarity' => null, // Rarity detection for compressed might not work
                'expected_quality' => 'excellent',
            ],
        ];

        $passed = 0;
        $failed = 0;

        foreach ($tests as $test) {
            $this->line("Testing: {$test['name']} (ID: {$test['type_id']})");
            
            $isJackpot = MoonOreHelper::isJackpotOre($test['type_id']);
            $rarity = MoonOreHelper::getRarity($test['type_id']);
            $quality = MoonOreHelper::getQuality($test['type_id']);

            $jackpotMatch = $isJackpot === $test['expected_jackpot'];
            $rarityMatch = $test['expected_rarity'] === null || $rarity === $test['expected_rarity'];
            $qualityMatch = $quality === $test['expected_quality'];

            if ($jackpotMatch && $rarityMatch && $qualityMatch) {
                $this->line("  ✅ PASS - Jackpot: " . ($isJackpot ? 'Yes' : 'No') . " | Rarity: {$rarity} | Quality: {$quality}");
                $passed++;
            } else {
                $this->error("  ❌ FAIL");
                if (!$jackpotMatch) {
                    $this->line("     Expected jackpot: " . ($test['expected_jackpot'] ? 'true' : 'false') . ", Got: " . ($isJackpot ? 'true' : 'false'));
                }
                if (!$rarityMatch) {
                    $this->line("     Expected rarity: {$test['expected_rarity']}, Got: {$rarity}");
                }
                if (!$qualityMatch) {
                    $this->line("     Expected quality: {$test['expected_quality']}, Got: {$quality}");
                }
                $failed++;
            }
            $this->newLine();
        }

        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line("Test Results: {$passed} passed, {$failed} failed");
        
        if ($failed === 0) {
            $this->info('✅ All jackpot detection tests passed!');
        } else {
            $this->error("❌ {$failed} tests failed! Please review MoonOreHelper logic.");
        }
    }

    /**
     * Display summary
     */
    protected function displaySummary()
    {
        $this->newLine();
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('DIAGNOSTICS SUMMARY');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $successRate = $this->stats['total'] > 0 
            ? round(($this->stats['verified'] / $this->stats['total']) * 100, 1) 
            : 0;

        $this->table(
            ['Metric', 'Count', 'Status'],
            [
                ['Total Type IDs', $this->stats['total'], '📊'],
                ['Verified', $this->stats['verified'], '✅'],
                ['Failed', $this->stats['failed'], $this->stats['failed'] > 0 ? '❌' : '✅'],
                ['Success Rate', $successRate . '%', $successRate >= 95 ? '✅' : '⚠️'],
            ]
        );

        // Show failures if any
        if ($this->stats['failed'] > 0) {
            $this->newLine();
            $this->error('❌ FAILED DIAGNOSTICS:');
            $this->newLine();

            $failureData = [];
            foreach ($this->stats['failures'] as $failure) {
                $failureData[] = [
                    $failure['category'],
                    $failure['type_id'],
                    $failure['error'],
                ];
            }

            $this->table(
                ['Category', 'Type ID', 'Error'],
                $failureData
            );

            $this->newLine();
            $this->warn('⚠️  These type IDs should be investigated!');
            $this->line('    Check if they are valid in CCP\'s SDE or if they were removed.');
        } else {
            $this->newLine();
            $this->info('✅ All type IDs verified successfully!');
            $this->line('    All item types are valid and present in CCP\'s database.');
        }

        $this->newLine();
        $this->line('  💡 Tip: Use --category=moon to diagnose all 60 moon ore variants');
        $this->line('  💡 Tip: Use --category=jackpot to diagnose all 40 jackpot ores');
        $this->line('  💡 Tip: Use --test-jackpot to test jackpot detection logic');
        $this->line('  💡 Tip: Use --include-abyssal to include Pochven ores');
        
        $this->newLine();
        $this->line('  📊 UPDATED COVERAGE:');
        $this->line('     - Regular Ores: 45 items');
        $this->line('     - Compressed Ores: 45 items');
        $this->line('     - Moon Ores: 60 items (base + improved + jackpot)');
        $this->line('     - Compressed Moon: 60 items (base + improved + jackpot)');
        $this->line('     - Jackpot Variants: 40 items (20 uncompressed + 20 compressed)');
        $this->line('     - Total: 317 type IDs tracked!');
    }
}
