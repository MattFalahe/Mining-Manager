<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DiagnoseTypeIdsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:diagnose-type-ids
                            {--category= : Diagnose specific category only (ore|compressed-ore|moon|materials|minerals|ice|gas|all)}
                            {--include-abyssal : Include abyssal ore diagnosis (Pochven ores)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose type IDs by verifying against CCP ESI API';

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
        $this->info('║   Mining Manager - Type ID Diagnostics                    ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->loadTypeIds();

        $category = $this->option('category');
        $includeAbyssal = $this->option('include-abyssal');

        if ($category && $category !== 'all') {
            $this->verifyCategory($category);
        } else {
            $this->verifyAllCategories($includeAbyssal);
        }

        $this->displaySummary();

        return $this->stats['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Load all type IDs
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
                'name' => 'Moon Ores',
                'count' => 20,
                'ids' => [
                    // R4 Ores (Ubiquitous)
                    45506, 45489, 45493, 45497,
                    // R8 Ores (Common)
                    45494, 45495, 46682, 46683,
                    // R16 Ores (Uncommon)
                    45492, 46679, 46687, 46688,
                    // R32 Ores (Rare)
                    46677, 45490, 46680, 46681,
                    // R64 Ores (Exceptional)
                    45491, 46676, 46678, 46689,
                ],
            ],
            'compressed-moon' => [
                'name' => 'Compressed Moon Ores',
                'count' => 20,
                'ids' => [
                    // Compressed R4 Ores
                    46675, 46676, 46677, 46678,
                    // Compressed R8 Ores
                    46679, 46680, 46681, 46682,
                    // Compressed R16 Ores
                    46683, 46684, 46685, 46686,
                    // Compressed R32 Ores
                    46687, 46688, 46689, 46690,
                    // Compressed R64 Ores
                    46691, 46692, 46693, 46694,
                ],
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
        $this->line('  💡 Tip: Use --category=gas to diagnose specific categories');
        $this->line('  💡 Tip: Use --include-abyssal to include Pochven ores');
    }
}
