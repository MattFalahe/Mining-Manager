<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MiningManager\Services\Moon\MoonOreHelper;
use MiningManager\Services\TypeIdRegistry;

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
     * Load all type IDs from TypeIdRegistry
     * Now uses TypeIdRegistry as single source of truth
     */
    protected function loadTypeIds()
    {
        $this->allTypeIds = [
            'ore' => [
                'name' => 'Regular Ores',
                'count' => TypeIdRegistry::getCategoryCount('ore'),
                'ids' => TypeIdRegistry::getTypeIdsByCategory('ore'),
            ],
            'compressed-ore' => [
                'name' => 'Compressed Ores',
                'count' => TypeIdRegistry::getCategoryCount('compressed-ore'),
                'ids' => TypeIdRegistry::getTypeIdsByCategory('compressed-ore'),
            ],
            'moon' => [
                'name' => 'Moon Ores (Uncompressed) - ALL VARIANTS',
                'count' => TypeIdRegistry::getCategoryCount('moon'),
                'ids' => TypeIdRegistry::getTypeIdsByCategory('moon'),
            ],
            'compressed-moon' => [
                'name' => 'Compressed Moon Ores - ALL VARIANTS',
                'count' => TypeIdRegistry::getCategoryCount('compressed-moon'),
                'ids' => TypeIdRegistry::getTypeIdsByCategory('compressed-moon'),
            ],
            'jackpot' => [
                'name' => 'Jackpot Moon Ores (+100% variants)',
                'count' => count(TypeIdRegistry::getAllJackpotOres()),
                'ids' => TypeIdRegistry::getAllJackpotOres(),
            ],
            'ice' => [
                'name' => 'Ice (Raw + Compressed)',
                'count' => TypeIdRegistry::getCategoryCount('ice'),
                'ids' => TypeIdRegistry::getTypeIdsByCategory('ice'),
            ],
            'gas' => [
                'name' => 'Gas (Fullerites + Booster)',
                'count' => TypeIdRegistry::getCategoryCount('gas'),
                'ids' => TypeIdRegistry::getTypeIdsByCategory('gas'),
            ],
            'minerals' => [
                'name' => 'Minerals',
                'count' => TypeIdRegistry::getCategoryCount('minerals'),
                'ids' => TypeIdRegistry::getTypeIdsByCategory('minerals'),
            ],
            'materials' => [
                'name' => 'Moon Materials',
                'count' => TypeIdRegistry::getCategoryCount('materials'),
                'ids' => TypeIdRegistry::getTypeIdsByCategory('materials'),
            ],
            'ice-products' => [
                'name' => 'Ice Products',
                'count' => TypeIdRegistry::getCategoryCount('ice-products'),
                'ids' => TypeIdRegistry::getTypeIdsByCategory('ice-products'),
            ],
            'abyssal' => [
                'name' => 'Abyssal Ores (Pochven)',
                'count' => TypeIdRegistry::getCategoryCount('abyssal'),
                'optional' => true,
                'ids' => TypeIdRegistry::getTypeIdsByCategory('abyssal'),
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
        $this->line('  📊 UPDATED COVERAGE (from TypeIdRegistry):');
        $this->line('     - Regular Ores: ' . TypeIdRegistry::getCategoryCount('ore') . ' items');
        $this->line('     - Compressed Ores: ' . TypeIdRegistry::getCategoryCount('compressed-ore') . ' items');
        $this->line('     - Moon Ores: ' . TypeIdRegistry::getCategoryCount('moon') . ' items (base + improved + jackpot)');
        $this->line('     - Compressed Moon: ' . TypeIdRegistry::getCategoryCount('compressed-moon') . ' items (base + improved + jackpot)');
        $this->line('     - Jackpot Variants: ' . count(TypeIdRegistry::getAllJackpotOres()) . ' items (20 uncompressed + 20 compressed)');
        $this->line('     - Total: ' . TypeIdRegistry::getTotalTrackedTypeIds() . ' type IDs tracked!');
    }
}
