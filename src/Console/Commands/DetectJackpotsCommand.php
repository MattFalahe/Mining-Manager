<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MiningLedger;
use MiningManager\Services\Moon\MoonOreHelper;

class DetectJackpotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:detect-jackpots
                            {--all : Check all extractions, not just recent ones}
                            {--days=30 : Number of days to check (default: 30)}
                            {--rerun-failed : Reset jackpot_verified=false rows back to null so they re-verify with the current logic. Use this after fixing verification bugs.}';

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

        // --rerun-failed: reset incorrectly-marked rows so they re-verify with
        // current logic. Use this after fixing verification bugs (e.g. wrong
        // date window, wrong expiry trigger). Only resets rows where the user
        // actually reported a jackpot (jackpot_reported_by is set) — auto-
        // marked false rows from genuine non-jackpots are left alone.
        if ($this->option('rerun-failed')) {
            $reset = MoonExtraction::where('is_jackpot', true)
                ->where('jackpot_verified', false)
                ->whereNotNull('jackpot_reported_by')
                ->update([
                    'jackpot_verified' => null,
                    'jackpot_verified_at' => null,
                ]);
            $this->info("Reset {$reset} previously-failed verifications back to null. They will re-verify in this run.");
        }

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
                        } elseif ($extraction->isExpired()) {
                            // The mining window is fully closed (fractured_at + 50h has passed,
                            // or the legacy fallback equivalent — see MoonExtraction::isExpired).
                            // Earlier versions used natural_decay_time->isPast() here, but that
                            // is the AUTO-FRACTURE mark (~3h after chunk_arrival) — verification
                            // would fire before miners had even started, producing false negatives
                            // for any chunk where actual mining happened after the fracture.
                            $extraction->jackpot_verified = false;
                            $extraction->jackpot_verified_at = now();
                            $extraction->save();
                            $unverified++;

                            $this->newLine();
                            $this->warn("  ❌ UNVERIFIED: {$extraction->moon_name} — no jackpot ores found in mining data");
                        }
                        // else: mining window still open, data may still arrive — skip for now
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

                    // B1c: announce on the cross-plugin event bus. Idempotency
                    // key (jackpot_detected:{extraction_id}) guarantees re-runs
                    // of this command on the same extraction won't re-publish.
                    // Standalone-safe via class_exists guard.
                    if (class_exists(\ManagerCore\Topics::class)) {
                        \ManagerCore\Topics::publish('mining.jackpot_detected', [
                            'extraction_id'  => $extraction->id,
                            'moon_id'        => $extraction->moon_id ?? null,
                            'moon_name'      => $extraction->moon_name ?? null,
                            'structure_id'   => $extraction->structure_id ?? null,
                            'corporation_id' => $extraction->corporation_id ?? null, // visibility scoping
                            'role_id'        => null,
                            'detected_at'    => $extraction->jackpot_detected_at->toIso8601String(),
                        ]);
                    }

                    // Send webhook notification
                    try {
                        $structure = DB::table('universe_structures')
                            ->where('structure_id', $extraction->structure_id)
                            ->first();

                        $systemName = $structure
                            ? (DB::table('solar_systems')->where('system_id', $structure->solar_system_id)->value('name') ?? 'Unknown')
                            : 'Unknown';

                        $baseUrl = rtrim(config('app.url', ''), '/');

                        // Apply ~2.0x jackpot multiplier — see ProcessMiningLedgerCommand
                        // for the rationale. estimated_value is the pre-jackpot ESI base.
                        $jackpotValue = (int) round(
                            $extraction->calculateValueWithJackpotBonus((float) ($extraction->estimated_value ?? 0))
                        );

                        $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
                        $notificationService->sendJackpotDetected([
                            'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                            'structure_name' => $structure->name ?? "Structure {$extraction->structure_id}",
                            'system_name' => $systemName,
                            'detected_by' => 'Jackpot Detection Scan',
                            'estimated_value' => $jackpotValue,
                            'ore_summary' => $extraction->buildOreSummary(),
                            'extraction_id' => $extraction->id,
                            'extraction_url' => $baseUrl . '/mining-manager/moon/' . $extraction->id,
                        ]);
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
     * Check if extraction has jackpot ores in mining ledger.
     *
     * The mining_ledger table stores per-day aggregated mining records from
     * the corporation industry mining observer endpoint, with one row per
     * (character, observer/structure, date, type_id). We filter:
     *
     *   - observer_id = this extraction's structure_id
     *     (precise — solar_system_id was previously used here but cross-
     *      contaminates between multiple Athanors in the same system)
     *   - date BETWEEN chunk_arrival and getExpiryTime
     *     (the FULL mining window. Previously used natural_decay_time
     *      which is the auto-fracture mark, ~3h after chunk_arrival —
     *      the window collapsed to a single day and missed almost all
     *      actual mining activity.)
     *   - type_id IN (jackpot ore type IDs from TypeIdRegistry)
     *
     * @param MoonExtraction $extraction
     * @return bool
     */
    private function checkForJackpotOres(MoonExtraction $extraction): bool
    {
        if (!$extraction->structure_id || !$extraction->chunk_arrival_time) {
            return false;
        }

        // End of mining window = fractured_at + 50h (plugin's lifecycle).
        // Falls back to chunk_arrival + 53h if fractured_at isn't yet set
        // (matches MoonExtraction::getExpiryTime() worst-case fallback).
        $end = $extraction->getExpiryTime()
            ?? $extraction->chunk_arrival_time->copy()->addHours(53);

        return MiningLedger::query()
            ->where('observer_id', $extraction->structure_id)
            ->whereBetween('date', [
                $extraction->chunk_arrival_time->toDateString(),
                $end->toDateString(),
            ])
            ->whereIn('type_id', MoonOreHelper::getAllJackpotTypeIds())
            ->exists();
    }
}
