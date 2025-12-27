<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiagnoseAffiliationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:diagnose-affiliation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose CharacterInfo affiliation relationship issue';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║   CharacterInfo Affiliation Diagnostic Tool           ║');
        $this->info('╚════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Test 1: Check if CharacterInfo class exists
        $this->info('═══ TEST 1: CharacterInfo Class ═══');
        if (class_exists(CharacterInfo::class)) {
            $this->line('  ✅ CharacterInfo class exists');
        } else {
            $this->error('  ❌ CharacterInfo class NOT FOUND');
            return Command::FAILURE;
        }
        $this->newLine();

        // Test 2: Check character_infos table structure
        $this->info('═══ TEST 2: character_infos Table Structure ═══');
        try {
            $columns = DB::select("DESCRIBE character_infos");
            $this->line('  ✅ Table exists with columns:');
            $columnNames = [];
            foreach ($columns as $column) {
                $columnNames[] = $column->Field;
                $this->line("     • {$column->Field} ({$column->Type})");
            }
            
            // Check if corporation_id exists in character_infos
            if (in_array('corporation_id', $columnNames)) {
                $this->warn('  ⚠️  WARNING: corporation_id exists in character_infos table!');
                $this->warn('     This might be from an old version. Should be in character_affiliations.');
            } else {
                $this->line('  ✅ No corporation_id in character_infos (correct)');
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Error: ' . $e->getMessage());
        }
        $this->newLine();

        // Test 3: Check character_affiliations table
        $this->info('═══ TEST 3: character_affiliations Table ═══');
        try {
            if (Schema::hasTable('character_affiliations')) {
                $columns = DB::select("DESCRIBE character_affiliations");
                $count = DB::table('character_affiliations')->count();
                
                $this->line("  ✅ Table exists with {$count} rows");
                $this->line('  Columns:');
                foreach ($columns as $column) {
                    $this->line("     • {$column->Field} ({$column->Type})");
                }
            } else {
                $this->error('  ❌ character_affiliations table DOES NOT EXIST!');
                $this->error('     This is the problem! SeAT needs this table for affiliations.');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Error: ' . $e->getMessage());
        }
        $this->newLine();

        // Test 4: Get a sample character
        $this->info('═══ TEST 4: Sample Character Data ═══');
        try {
            $character = CharacterInfo::first();
            if ($character) {
                $this->line('  ✅ Character found:');
                $this->line("     • ID: {$character->character_id}");
                $this->line("     • Name: {$character->name}");
            } else {
                $this->warn('  ⚠️  No characters in database');
                return Command::SUCCESS;
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Error: ' . $e->getMessage());
        }
        $this->newLine();

        // Test 5: Check if affiliation relationship exists
        $this->info('═══ TEST 5: Affiliation Relationship ═══');
        try {
            $character = CharacterInfo::first();
            if ($character) {
                if (method_exists($character, 'affiliation')) {
                    $this->line('  ✅ affiliation() method exists on CharacterInfo model');
                    
                    try {
                        $affiliation = $character->affiliation;
                        if ($affiliation) {
                            $this->line('  ✅ Affiliation loaded successfully:');
                            $this->line("     • Character ID: {$affiliation->character_id}");
                            $this->line("     • Corporation ID: {$affiliation->corporation_id}");
                            if (isset($affiliation->alliance_id)) {
                                $this->line("     • Alliance ID: {$affiliation->alliance_id}");
                            }
                        } else {
                            $this->warn('  ⚠️  Character has no affiliation data');
                        }
                    } catch (\Exception $e) {
                        $this->error('  ❌ Error loading affiliation: ' . $e->getMessage());
                        $this->error('     Stack trace:');
                        foreach (explode("\n", $e->getTraceAsString()) as $line) {
                            if ($line) {
                                $this->error('     ' . $line);
                            }
                        }
                    }
                } else {
                    $this->error('  ❌ affiliation() method DOES NOT EXIST on CharacterInfo!');
                    $this->error('     This is the problem! SeAT CharacterInfo model is missing the relationship.');
                    $this->newLine();
                    $this->warn('  Checking available methods on CharacterInfo:');
                    $reflection = new \ReflectionClass($character);
                    $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
                    foreach ($methods as $method) {
                        if (strpos($method->name, 'corporation') !== false || 
                            strpos($method->name, 'affiliation') !== false) {
                            $this->line("     • {$method->name}()");
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Error: ' . $e->getMessage());
        }
        $this->newLine();

        // Test 6: Test the actual query from TaxController
        $this->info('═══ TEST 6: Test TaxController Query ═══');
        try {
            $this->line('  Testing the actual problematic query...');
            
            // Get a sample character ID
            $character = CharacterInfo::first();
            if (!$character) {
                $this->warn('  ⚠️  No characters to test with');
                return Command::SUCCESS;
            }
            
            $characterIds = [$character->character_id];
            $this->line("  Using character ID: {$character->character_id}");
            
            // Try the query that fails in TaxController
            $this->line('  Query: CharacterInfo::whereIn(\'character_id\', [...])->with(\'affiliation\')->get()');
            $characters = CharacterInfo::whereIn('character_id', $characterIds)
                ->with('affiliation')
                ->get();
            
            $this->line("  ✅ Query succeeded! Retrieved {$characters->count()} character(s)");
            
            // Try to pluck corporation_id
            $this->line('  Testing: ->pluck(\'affiliation.corporation_id\')');
            $corporationIds = $characters->pluck('affiliation.corporation_id')->filter();
            $this->line('  ✅ Pluck succeeded! Corporation IDs: ' . $corporationIds->toJson());
            
        } catch (\Exception $e) {
            $this->error('  ❌ Query failed: ' . $e->getMessage());
            $this->error('     This is the error from TaxController!');
            $this->newLine();
            $this->line('  Stack trace excerpt:');
            $trace = explode("\n", $e->getTraceAsString());
            foreach (array_slice($trace, 0, 5) as $line) {
                if ($line) {
                    $this->error('     ' . $line);
                }
            }
        }
        $this->newLine();

        // Test 7: Check SeAT version and CharacterInfo model location
        $this->info('═══ TEST 7: SeAT Version & Model Info ═══');
        try {
            $reflection = new \ReflectionClass(CharacterInfo::class);
            $this->line('  CharacterInfo model location:');
            $this->line('     ' . $reflection->getFileName());
            
            // Try to read the model file to see if affiliation relationship exists
            $modelFile = file_get_contents($reflection->getFileName());
            if (strpos($modelFile, 'function affiliation') !== false) {
                $this->line('  ✅ Found affiliation() method in model file');
            } else {
                $this->error('  ❌ No affiliation() method found in model file');
                $this->error('     Your SeAT version might be outdated or missing this relationship.');
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Error: ' . $e->getMessage());
        }
        $this->newLine();

        // Test 8: Database query test
        $this->info('═══ TEST 8: Direct Database Query Test ═══');
        try {
            $this->line('  Testing direct query for corporation IDs...');
            
            // Try direct database query
            $result = DB::table('character_affiliations')
                ->select('corporation_id')
                ->distinct()
                ->limit(5)
                ->get();
            
            $this->line("  ✅ Direct query succeeded! Found {$result->count()} corporation IDs");
            foreach ($result as $row) {
                $this->line("     • Corporation ID: {$row->corporation_id}");
            }
            
        } catch (\Exception $e) {
            $this->error('  ❌ Direct query failed: ' . $e->getMessage());
        }
        $this->newLine();

        // Summary
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║                    DIAGNOSIS SUMMARY                   ║');
        $this->info('╚════════════════════════════════════════════════════════╝');
        $this->newLine();
        
        $this->warn('If you see errors above, the problem is likely:');
        $this->warn('1. Missing affiliation() relationship in CharacterInfo model');
        $this->warn('2. Outdated SeAT version that doesn\'t have character_affiliations');
        $this->warn('3. Database migration not run for character_affiliations table');
        $this->newLine();
        
        $this->info('Next steps:');
        $this->line('• Check your SeAT version: Should be 5.x or higher');
        $this->line('• Update SeAT if needed: composer update');
        $this->line('• Run SeAT migrations: php artisan migrate');
        $this->line('• Clear caches: php artisan config:clear && php artisan cache:clear');
        
        return Command::SUCCESS;
    }
}
