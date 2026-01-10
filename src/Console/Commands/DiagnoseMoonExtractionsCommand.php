<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use MiningManager\Models\MoonExtraction;

class DiagnoseMoonExtractionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:diagnose-extractions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose moon extraction data issues';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('=== Mining Manager - Moon Extraction Diagnostics ===');
        $this->newLine();

        // Check 1: Mining Manager moon_extractions table
        $this->checkMiningManagerTable();
        $this->newLine();

        // Check 2: SeAT extraction tables
        $this->checkSeatExtractionTables();
        $this->newLine();

        // Check 3: Refinery structures
        $this->checkRefineries();
        $this->newLine();

        // Check 4: SeAT mining observer tables
        $this->checkMiningObservers();
        $this->newLine();

        $this->info('=== Diagnostics Complete ===');
        
        return Command::SUCCESS;
    }

    /**
     * Check Mining Manager's moon_extractions table
     */
    private function checkMiningManagerTable()
    {
        $this->line('Checking Mining Manager moon_extractions table:');
        
        if (!Schema::hasTable('moon_extractions')) {
            $this->error('  ✗ moon_extractions table DOES NOT exist');
            $this->warn('    Run: php artisan migrate');
            return;
        }

        $this->info('  ✓ moon_extractions table exists');
        
        $count = MoonExtraction::count();
        $this->line("  Records: {$count}");

        if ($count > 0) {
            $this->line('  Sample data:');
            $samples = MoonExtraction::limit(3)->get();
            foreach ($samples as $sample) {
                $this->line("    - ID: {$sample->id}, Structure: {$sample->structure_id}, Status: {$sample->status}, Moon: {$sample->moon_id}");
            }
            
            // Check statuses
            $statuses = MoonExtraction::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get();
            
            $this->line('  Status breakdown:');
            foreach ($statuses as $status) {
                $this->line("    - {$status->status}: {$status->count}");
            }
        } else {
            $this->warn('  ⚠ No extraction records found - table is empty!');
        }
    }

    /**
     * Check for SeAT's extraction tables
     */
    private function checkSeatExtractionTables()
    {
        $this->line('Checking for SeAT extraction tables:');
        
        $possibleTables = [
            'corporation_industry_mining_extractions',
            'corporation_mining_extractions', 
            'mining_extractions',
            'industry_mining_extractions',
        ];

        $found = false;
        foreach ($possibleTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                $count = DB::table($tableName)->count();
                $this->info("  ✓ {$tableName} exists ({$count} records)");
                $found = true;

                if ($count > 0) {
                    $columns = Schema::getColumnListing($tableName);
                    $this->line('    Columns: ' . implode(', ', array_slice($columns, 0, 10)) . '...');
                    
                    $sample = DB::table($tableName)->limit(1)->first();
                    if ($sample) {
                        $this->line('    Sample record:');
                        $this->line('      ' . json_encode($sample, JSON_PRETTY_PRINT));
                    }
                }
            }
        }

        if (!$found) {
            $this->warn('  ⚠ No SeAT extraction tables found');
            $this->line('    SeAT may not have synced extraction data yet');
            $this->line('    OR extraction data might be in a different table structure');
        }
    }

    /**
     * Check refinery structures
     */
    private function checkRefineries()
    {
        $this->line('Checking for refinery structures:');
        
        if (!Schema::hasTable('corporation_structures')) {
            $this->error('  ✗ corporation_structures table does not exist');
            return;
        }

        $refineries = CorporationStructure::whereIn('type_id', [35835, 35836])->get();
        
        $this->line("  Found {$refineries->count()} refineries (Athanor: 35835, Tatara: 35836)");

        if ($refineries->count() > 0) {
            foreach ($refineries as $refinery) {
                $name = $refinery->name ?? 'Unknown';
                $this->line("    - {$name}");
                $this->line("      Structure ID: {$refinery->structure_id}");
                $this->line("      Corporation ID: {$refinery->corporation_id}");
                $this->line("      Type ID: {$refinery->type_id}");
                
                // Check if we have extraction data for this structure
                $extractionCount = MoonExtraction::where('structure_id', $refinery->structure_id)->count();
                if ($extractionCount > 0) {
                    $this->info("      ✓ Has {$extractionCount} extraction records");
                } else {
                    $this->warn("      ⚠ No extraction records found");
                }
            }
        } else {
            $this->warn('  ⚠ No refineries found in corporation_structures');
            $this->line('    Make sure:');
            $this->line('    1. You have Athanor or Tatara structures');
            $this->line('    2. SeAT has synced corporation structure data');
        }
    }

    /**
     * Check mining observer tables
     */
    private function checkMiningObservers()
    {
        $this->line('Checking SeAT mining observer tables:');
        
        $observerTables = [
            'corporation_industry_mining_observers',
            'corporation_industry_mining_observer_data',
        ];

        foreach ($observerTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                $count = DB::table($tableName)->count();
                $this->info("  ✓ {$tableName} exists ({$count} records)");
                
                if ($count > 0 && $count < 20) {
                    $samples = DB::table($tableName)->limit(3)->get();
                    $this->line('    Sample data:');
                    foreach ($samples as $sample) {
                        $this->line('      ' . json_encode($sample));
                    }
                }
            } else {
                $this->warn("  ✗ {$tableName} does not exist");
            }
        }
    }
}
