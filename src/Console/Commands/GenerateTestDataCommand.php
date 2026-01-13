<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GenerateTestDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:generate-test-data
                            {--corporations=3 : Number of test corporations to generate}
                            {--characters=5 : Number of characters per corporation}
                            {--days=30 : Number of days of mining data}
                            {--entries=10 : Number of mining entries per day per character}
                            {--cleanup : Remove all existing test data first}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate test data for mining manager (corporations, characters, and mining ledger entries)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Mining Manager Test Data Generator');
        $this->info('===================================');

        // Cleanup if requested
        if ($this->option('cleanup')) {
            $this->info("\nCleaning up existing test data...");
            $this->cleanupTestData();
        }

        $corporations = $this->option('corporations');
        $charactersPerCorp = $this->option('characters');
        $days = $this->option('days');
        $entriesPerDay = $this->option('entries');

        $this->info("\nGeneration Plan:");
        $this->line("  Corporations: {$corporations}");
        $this->line("  Characters per Corp: {$charactersPerCorp}");
        $this->line("  Days of Data: {$days}");
        $this->line("  Entries per Day: {$entriesPerDay}");
        $this->line("  Total Characters: " . ($corporations * $charactersPerCorp));
        $this->line("  Total Mining Entries: " . ($corporations * $charactersPerCorp * $days * $entriesPerDay));

        if (!$this->confirm("\nDo you want to proceed?", true)) {
            $this->warn('Operation cancelled.');
            return Command::FAILURE;
        }

        try {
            // Step 1: Generate Corporations
            $this->info("\nStep 1/3: Generating corporations...");
            $generatedCorps = $this->generateCorporations($corporations);
            $this->info("  ✓ Generated {$corporations} corporations");

            // Step 2: Generate Characters
            $this->info("\nStep 2/3: Generating characters...");
            $generatedChars = $this->generateCharacters($generatedCorps, $charactersPerCorp);
            $this->info("  ✓ Generated " . count($generatedChars) . " characters");

            // Step 3: Generate Mining Data
            $this->info("\nStep 3/3: Generating mining ledger data...");
            $entriesCreated = $this->generateMiningData($generatedChars, $days, $entriesPerDay);
            $this->info("  ✓ Generated {$entriesCreated} mining entries");

            $this->info("\n✓ Test data generation completed successfully!");
            $this->info("\nNext Steps:");
            $this->line("  1. Go to Mining Manager Settings");
            $this->line("  2. Select a test corporation (Test Corp 1, Test Corp 2, etc.)");
            $this->line("  3. Configure different tax rates for each corporation");
            $this->line("  4. Run tax calculations to test multi-corporation functionality");
            $this->line("\nCleanup: Run with --cleanup flag to remove all test data");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("\n✗ Error: " . $e->getMessage());
            Log::error('Test data generation failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }

    /**
     * Generate test corporations
     *
     * @param int $count
     * @return array
     */
    private function generateCorporations(int $count): array
    {
        DB::beginTransaction();

        $corporations = [];
        for ($i = 1; $i <= $count; $i++) {
            $corpId = 98000000 + $i;
            $ceoId = 90000000 + $i;
            $creatorId = 90000000 + $i;
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

            $corporations[] = (object)[
                'corporation_id' => $corpId,
                'name' => "Test Corp {$i}",
                'ticker' => $ticker,
            ];

            $this->line("    - Created: Test Corp {$i} [{$ticker}]");
        }

        DB::commit();
        return $corporations;
    }

    /**
     * Generate test characters
     *
     * @param array $corporations
     * @param int $charactersPerCorp
     * @return array
     */
    private function generateCharacters(array $corporations, int $charactersPerCorp): array
    {
        DB::beginTransaction();

        $characters = [];
        $charIdCounter = 90000000;

        foreach ($corporations as $corp) {
            for ($i = 1; $i <= $charactersPerCorp; $i++) {
                $charId = $charIdCounter++;
                $charName = "Test Miner {$corp->ticker}-{$i}";

                DB::table('character_infos')->updateOrInsert(
                    ['character_id' => $charId],
                    [
                        'name' => $charName,
                        'gender' => rand(0, 1) ? 'male' : 'female',
                        'race_id' => [1, 2, 4, 8][rand(0, 3)],
                        'bloodline_id' => rand(1, 15),
                        'security_status' => rand(-10, 10) / 10,
                        'birthday' => Carbon::now()->subYears(rand(1, 10)),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // Insert into character_affiliations
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
                    // Table might not exist
                }

                $characters[] = [
                    'character_id' => $charId,
                    'name' => $charName,
                    'corporation_id' => $corp->corporation_id,
                ];
            }
            $this->line("    - Created {$charactersPerCorp} miners for {$corp->name}");
        }

        DB::commit();
        return $characters;
    }

    /**
     * Generate test mining data
     *
     * @param array $characters
     * @param int $days
     * @param int $entriesPerDay
     * @return int
     */
    private function generateMiningData(array $characters, int $days, int $entriesPerDay): int
    {
        DB::beginTransaction();

        // Define ore types
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

        // Solar system IDs
        $solarSystems = [30000142, 30001161, 30002187, 30003504, 30004608];

        $entriesCreated = 0;
        $progressBar = $this->output->createProgressBar(count($characters) * $days);

        foreach ($characters as $character) {
            for ($day = 0; $day < $days; $day++) {
                $date = Carbon::now()->subDays($day);

                for ($entry = 0; $entry < $entriesPerDay; $entry++) {
                    $ore = $oreTypes[array_rand($oreTypes)];
                    $quantity = rand(1000, 50000);
                    $solarSystem = $solarSystems[array_rand($solarSystems)];

                    DB::table('mining_ledger')->insert([
                        'character_id' => $character['character_id'],
                        'date' => $date->format('Y-m-d'),
                        'type_id' => $ore['id'],
                        'quantity' => $quantity,
                        'solar_system_id' => $solarSystem,
                        'processed_at' => $date,
                        'is_moon_ore' => $ore['is_moon_ore'] ?? false,
                        'is_ore' => $ore['is_ore'] ?? false,
                        'is_ice' => $ore['is_ice'] ?? false,
                        'is_gas' => $ore['is_gas'] ?? false,
                        'created_at' => $date,
                        'updated_at' => $date,
                    ]);

                    $entriesCreated++;
                }
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine();

        DB::commit();
        return $entriesCreated;
    }

    /**
     * Clean up all test data
     *
     * @return void
     */
    private function cleanupTestData()
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

            // Delete mining taxes
            $taxesDeleted = DB::table('mining_taxes')
                ->whereIn('character_id', $testCharacterIds)
                ->delete();

            // Delete mining ledger
            $ledgerDeleted = DB::table('mining_ledger')
                ->whereIn('character_id', $testCharacterIds)
                ->delete();

            // Delete corporation settings
            $settingsDeleted = DB::table('mining_manager_settings')
                ->whereIn('corporation_id', $testCorporationIds)
                ->delete();

            // Delete character affiliations
            $affiliationsDeleted = 0;
            try {
                $affiliationsDeleted = DB::table('character_affiliations')
                    ->whereIn('character_id', $testCharacterIds)
                    ->delete();
            } catch (\Exception $e) {
                // Table might not exist
            }

            // Delete characters
            $charactersDeleted = DB::table('character_infos')
                ->where('name', 'like', 'Test Miner%')
                ->delete();

            // Delete corporations
            $corpsDeleted = DB::table('corporation_infos')
                ->where('name', 'like', 'Test Corp%')
                ->delete();

            DB::commit();

            $this->line("  ✓ Deleted {$corpsDeleted} corporations");
            $this->line("  ✓ Deleted {$charactersDeleted} characters");
            if ($affiliationsDeleted > 0) {
                $this->line("  ✓ Deleted {$affiliationsDeleted} affiliations");
            }
            $this->line("  ✓ Deleted {$ledgerDeleted} ledger entries");
            $this->line("  ✓ Deleted {$taxesDeleted} tax records");
            $this->line("  ✓ Deleted {$settingsDeleted} settings");

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
