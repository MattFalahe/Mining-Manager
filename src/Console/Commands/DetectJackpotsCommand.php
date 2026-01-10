<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Moon\MoonOreHelper;
use MiningManager\Services\Notification\WebhookService;

class DetectJackpotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:detect-jackpots
                            {--all : Check all extractions, not just recent ones}
                            {--days=30 : Number of days to check (default: 30)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect jackpot moon extractions based on mining ledger data';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lock = Cache::lock('mining-manager:detect-jackpots', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return self::SUCCESS;
        }

        try {
        $this->info('Starting jackpot detection...');

        $checkAll = $this->option('all');
        $days = $this->option('days');

        // Get extractions to check
        $query = MoonExtraction::query();
        
        if (!$checkAll) {
            $query->where('chunk_arrival_time', '>=', now()->subDays($days));
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No extractions found to check.');
            return Command::SUCCESS;
        }

        $this->info("Checking {$total} extractions...");

        $detected = 0;
        $alreadyMarked = 0;
        $verified = 0;
        $unverified = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunk(500, function ($extractions) use (&$detected, &$alreadyMarked, &$verified, &$unverified, $bar) {
            foreach ($extractions as $extraction) {
                // Already marked as jackpot — check if manual report needs verification
                if ($extraction->is_jackpot) {
                    if ($extraction->jackpot_reported_by && $extraction->jackpot_verified === null) {
                        // Manual report awaiting verification
                        $hasJackpot = $this->checkForJackpotOres($extraction);

                        if ($hasJackpot) {
                            $extraction->jackpot_verified = true;
                            $extraction->jackpot_verified_at = now();
                            $extraction->save();
                            $verified++;

                            $this->newLine();
                            $this->info("  ✅ VERIFIED: {$extraction->moon_name} (reported by character {$extraction->jackpot_reported_by})");
                        } elseif ($extraction->natural_decay_time && $extraction->natural_decay_time->isPast()) {
                            // Extraction has expired and no jackpot ores found — mark as unverified
                            $extraction->jackpot_verified = false;
                            $extraction->jackpot_verified_at = now();
                            $extraction->save();
                            $unverified++;

                            $this->newLine();
                            $this->warn("  ❌ UNVERIFIED: {$extraction->moon_name} — no jackpot ores found in mining data");
                        }
                        // else: extraction not expired yet, data may still arrive — skip for now
                    }

                    $alreadyMarked++;
                    $bar->advance();
                    continue;
                }

                // Check mining ledger for jackpot ores
                $hasJackpot = $this->checkForJackpotOres($extraction);

                if ($hasJackpot) {
                    $extraction->is_jackpot = true;
                    $extraction->jackpot_detected_at = now();
                    $extraction->jackpot_verified = true;
                    $extraction->jackpot_verified_at = now();
                    $extraction->save();
                    $detected++;

                    $this->newLine();
                    $this->info("  ⭐ JACKPOT DETECTED: {$extraction->moon_name}");

                    // Send webhook notification
                    try {
                        $structure = DB::table('universe_structures')
                            ->where('structure_id', $extraction->structure_id)
                            ->first();

                        $systemName = $structure
                            ? (DB::table('solar_systems')->where('system_id', $structure->solar_system_id)->value('name') ?? 'Unknown')
                            : 'Unknown';

                        $webhookService = app(WebhookService::class);
                        $webhookService->sendMoonNotification('jackpot_detected', [
                            'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                            'structure_name' => $structure->name ?? "Structure {$extraction->structure_id}",
                            'system_name' => $systemName,
                            'detected_by' => 'Jackpot Detection Scan',
                            'jackpot_ores' => [],
                            'jackpot_percentage' => 100,
                            'extraction_id' => $extraction->id,
                        ], $extraction->corporation_id);
                    } catch (\Exception $e) {
                        $this->warn("  ⚠️ Failed to send notification: {$e->getMessage()}");
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Jackpot detection complete!');
        $this->table(
            ['Status', 'Count'],
            [
                ['New jackpots detected', $detected],
                ['Manual reports verified', $verified],
                ['Manual reports unverified', $unverified],
                ['Already marked as jackpot', $alreadyMarked - $verified - $unverified],
                ['No jackpot', $total - $detected - $alreadyMarked],
            ]
        );

        if ($detected > 0) {
            $this->info("💎 Found {$detected} new jackpot extractions!");
        }
        if ($verified > 0) {
            $this->info("✅ Verified {$verified} manually reported jackpots!");
        }
        if ($unverified > 0) {
            $this->warn("❌ {$unverified} manual reports could not be verified (no jackpot ores found).");
        }

        return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * Check if extraction has jackpot ores in mining ledger
     *
     * @param MoonExtraction $extraction
     * @return bool
     */
    private function checkForJackpotOres(MoonExtraction $extraction): bool
    {
        // Get the solar system for this structure
        $structure = \DB::table('universe_structures')
            ->where('structure_id', $extraction->structure_id)
            ->first();

        if (!$structure) {
            return false;
        }

        // Check mining ledger for jackpot ore type IDs
        $hasJackpot = MiningLedger::where('solar_system_id', $structure->solar_system_id)
            ->whereBetween('date', [
                $extraction->chunk_arrival_time->toDateString(),
                $extraction->natural_decay_time->toDateString()
            ])
            ->whereIn('type_id', MoonOreHelper::getAllJackpotTypeIds())
            ->exists();

        return $hasJackpot;
    }
}
