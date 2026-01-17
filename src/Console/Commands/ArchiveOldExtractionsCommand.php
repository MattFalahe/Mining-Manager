<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use MiningManager\Models\MiningLedger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveOldExtractionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:archive-extractions
                            {--days=7 : Archive extractions older than this many days}
                            {--keep-months=12 : Keep history for this many months}
                            {--dry-run : Show what would be archived without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive old moon extractions to history table and calculate actual mined values';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $daysOld = $this->option('days');
        $keepMonths = $this->option('keep-months');
        $dryRun = $this->option('dry-run');

        $this->info("Mining Manager: Archiving moon extractions older than {$daysOld} days...");

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No changes will be made");
        }

        // Find extractions to archive
        $cutoffDate = Carbon::now()->subDays($daysOld);
        $extractionsToArchive = MoonExtraction::where('natural_decay_time', '<', $cutoffDate)
            ->whereIn('status', ['expired', 'fractured'])
            ->get();

        if ($extractionsToArchive->isEmpty()) {
            $this->info("No extractions found to archive");
            return 0;
        }

        $this->info("Found {$extractionsToArchive->count()} extractions to archive");

        $archived = 0;
        $failed = 0;

        foreach ($extractionsToArchive as $extraction) {
            try {
                // Calculate actual mined value from mining_ledger
                $actualMinedData = $this->calculateActualMinedValue($extraction);

                if (!$dryRun) {
                    // Create history record
                    MoonExtractionHistory::create([
                        'moon_extraction_id' => $extraction->id,
                        'structure_id' => $extraction->structure_id,
                        'corporation_id' => $extraction->corporation_id,
                        'moon_id' => $extraction->moon_id,
                        'extraction_start_time' => $extraction->extraction_start_time,
                        'chunk_arrival_time' => $extraction->chunk_arrival_time,
                        'natural_decay_time' => $extraction->natural_decay_time,
                        'archived_at' => Carbon::now(),
                        'final_status' => $extraction->status,
                        'estimated_value_at_start' => $extraction->estimated_value_at_start,
                        'estimated_value_at_arrival' => $extraction->estimated_value_pre_arrival,
                        'final_estimated_value' => $extraction->estimated_value,
                        'ore_composition' => $extraction->ore_composition,
                        'actual_mined_value' => $actualMinedData['total_value'],
                        'total_miners' => $actualMinedData['total_miners'],
                        'completion_percentage' => $actualMinedData['completion_percentage'],
                        'is_jackpot' => $extraction->is_jackpot,
                        'jackpot_detected_at' => $extraction->jackpot_detected_at,
                    ]);

                    // Delete the original extraction
                    $extraction->delete();
                }

                $this->line("✓ Archived extraction {$extraction->id} (Moon: {$extraction->moon_id}, Value: " . number_format($extraction->estimated_value) . " ISK)");
                $archived++;

            } catch (\Exception $e) {
                $this->error("✗ Failed to archive extraction {$extraction->id}: " . $e->getMessage());
                Log::error("Mining Manager: Failed to archive extraction {$extraction->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $failed++;
            }
        }

        // Clean up old history records beyond retention period
        if (!$dryRun) {
            $oldHistoryCutoff = Carbon::now()->subMonths($keepMonths);
            $deletedHistory = MoonExtractionHistory::where('archived_at', '<', $oldHistoryCutoff)->delete();

            if ($deletedHistory > 0) {
                $this->info("Cleaned up {$deletedHistory} history records older than {$keepMonths} months");
            }
        }

        $this->info("\nArchival complete:");
        $this->info("  Archived: {$archived}");
        if ($failed > 0) {
            $this->error("  Failed: {$failed}");
        }

        return 0;
    }

    /**
     * Calculate actual mined value from mining_ledger data.
     *
     * @param MoonExtraction $extraction
     * @return array
     */
    private function calculateActualMinedValue(MoonExtraction $extraction): array
    {
        try {
            // Get mining data for this extraction's timeframe
            // Look for mining that happened between chunk arrival and natural decay
            $miningData = MiningLedger::where('solar_system_id', function($query) use ($extraction) {
                    $query->select('solar_system_id')
                        ->from('universe_structures')
                        ->where('structure_id', $extraction->structure_id)
                        ->limit(1);
                })
                ->where('date', '>=', $extraction->chunk_arrival_time->format('Y-m-d'))
                ->where('date', '<=', $extraction->natural_decay_time->format('Y-m-d'))
                ->where('is_moon_ore', true)
                ->get();

            if ($miningData->isEmpty()) {
                return [
                    'total_value' => 0,
                    'total_miners' => 0,
                    'completion_percentage' => 0,
                ];
            }

            $totalValue = $miningData->sum('total_value');
            $totalMiners = $miningData->pluck('character_id')->unique()->count();

            // Calculate completion percentage (actual mined vs estimated)
            $completionPercentage = 0;
            if ($extraction->estimated_value > 0) {
                $completionPercentage = min(100, ($totalValue / $extraction->estimated_value) * 100);
            }

            return [
                'total_value' => $totalValue,
                'total_miners' => $totalMiners,
                'completion_percentage' => round($completionPercentage, 2),
            ];

        } catch (\Exception $e) {
            Log::warning("Mining Manager: Could not calculate actual mined value for extraction {$extraction->id}: " . $e->getMessage());
            return [
                'total_value' => 0,
                'total_miners' => 0,
                'completion_percentage' => 0,
            ];
        }
    }
}
