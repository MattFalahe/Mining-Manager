<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use MiningManager\Models\MiningLedger;

/**
 * Backfill moon_extraction_history from EVE character notifications.
 *
 * When a corporation installs this plugin partway through their life,
 * the plugin starts tracking extractions from that point forward. But
 * EVE has been delivering MoonminingExtractionStarted / Finished /
 * LaserFired / AutomaticFracture / Cancelled notifications to
 * character mailboxes for much longer. SeAT syncs these notifications
 * into `character_notifications`.
 *
 * This command scans those notifications, dedupes to one record per
 * extraction (same extraction is notified to many recipients — all
 * have identical payload), and rebuilds moon_extraction_history rows
 * so the moon detail pages show full historical context.
 *
 * Key decisions:
 *  - Writes ONLY to moon_extraction_history (archived records).
 *    Current/future extractions come from the normal ESI import flow.
 *  - Dedupes by (structure_id, readyTime) — that pair uniquely
 *    identifies a specific extraction cycle.
 *  - Skips if a matching row already exists in moon_extractions OR
 *    moon_extraction_history (unless --force).
 *  - Final state is resolved from a matching LaserFired / AutomaticFracture
 *    / Cancelled notification within the extraction's lifecycle window.
 *    If none found, assumed auto-fractured at autoTime.
 *  - actual_mined_value computed from mining_ledger where data exists.
 *  - Historical ISK prices unknown — estimated_value fields left NULL.
 */
class BackfillExtractionHistoryCommand extends Command
{
    protected $signature = 'mining-manager:backfill-extraction-history
                            {--structure= : Only backfill for this specific structure ID}
                            {--days=365 : Look back this many days for notifications}
                            {--limit=1000 : Maximum extractions to create per run}
                            {--dry-run : Preview what would be created without writing}
                            {--force : Recreate history rows even if they already exist (destructive — use with care)}';

    protected $description = 'Reconstruct moon_extraction_history from EVE character notifications for past extractions';

    /**
     * Number of seconds between Unix epoch (1970) and Windows FILETIME epoch (1601).
     * Used to convert EVE's game-time ticks to PHP Unix timestamps.
     */
    private const FILETIME_EPOCH_OFFSET_SECONDS = 11644473600;

    public function handle(): int
    {
        $structureId = $this->option('structure');
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info("Mining Manager: Backfilling extraction history from character_notifications...");
        $this->info("  Scan window: last {$days} days");
        if ($structureId) {
            $this->info("  Structure filter: {$structureId}");
        }
        if ($dryRun) {
            $this->warn("  DRY RUN — no database writes will be made");
        }
        if ($force) {
            $this->warn("  FORCE — existing history rows for scanned extractions will be deleted and recreated");
        }

        // Resolve the Moon Owner Corporation — only backfill structures owned
        // by this corp. Other directors' private moons on the same SeAT install
        // appear in character_notifications but must NOT be imported.
        $settings = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $moonOwnerCorpId = $settings->getTaxProgramCorporationId();

        if ($moonOwnerCorpId === null) {
            $this->error("  Moon Owner Corporation is not configured. Set it in Settings > General before running backfill.");
            return Command::FAILURE;
        }

        // Pre-load the set of structure IDs owned by moon owner corp. Used
        // to filter notifications during dedup — we skip any structure that
        // doesn't belong to moon owner corp.
        $ownedStructureIds = DB::table('corporation_structures')
            ->where('corporation_id', $moonOwnerCorpId)
            ->pluck('structure_id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $this->info("  Moon Owner Corp: {$moonOwnerCorpId} (" . count($ownedStructureIds) . " structure(s) owned)");

        if (empty($ownedStructureIds)) {
            $this->warn("  No structures found for moon owner corp. Nothing to backfill.");
            return Command::SUCCESS;
        }

        // If user passed --structure, enforce it also belongs to moon owner corp.
        if ($structureId && !in_array((int) $structureId, $ownedStructureIds, true)) {
            $this->error("  Structure {$structureId} does not belong to Moon Owner Corporation. Aborting.");
            return Command::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        // Load all MoonminingExtractionStarted notifications in window.
        // SeAT stores one row per recipient character, so we'll dedup
        // in memory below using (structure_id, readyTime) as the key.
        $query = DB::table('character_notifications')
            ->where('type', 'MoonminingExtractionStarted')
            ->where('timestamp', '>=', $cutoff);

        if ($structureId) {
            $query->where('text', 'LIKE', '%structureID: ' . $structureId . '%');
        }

        $rawNotifications = $query
            ->orderBy('timestamp', 'asc')
            ->get();

        $this->info("  Scanned {$rawNotifications->count()} raw notification rows (pre-dedup)");

        // Dedup by (structure_id, readyTime) — each pair = one unique extraction.
        // Progress bar for the dedup pass too, since with large datasets
        // (thousands of notifications) YAML parsing can take a few seconds.
        $this->info("  Parsing and deduplicating notifications...");
        $dedupBar = $this->output->createProgressBar($rawNotifications->count());
        $dedupBar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
        $dedupBar->setMessage('parsing YAML');
        $dedupBar->start();

        $uniqueExtractions = [];
        $skippedNotOwned = 0;
        foreach ($rawNotifications as $notification) {
            $dedupBar->advance();

            $parsed = $this->parseYamlText($notification->text);
            if ($parsed === null) continue;

            $structureIdParsed = $parsed['structureID'] ?? null;
            $readyTimeRaw = $parsed['readyTime'] ?? null;
            if (!$structureIdParsed || !$readyTimeRaw) continue;

            // Filter: only process structures owned by Moon Owner Corporation.
            // Other corps' private moons on shared SeAT installs produce
            // notifications in this table but must NOT be imported.
            if (!in_array((int) $structureIdParsed, $ownedStructureIds, true)) {
                $skippedNotOwned++;
                continue;
            }

            $key = "{$structureIdParsed}_{$readyTimeRaw}";
            if (isset($uniqueExtractions[$key])) continue; // already have this one

            $uniqueExtractions[$key] = [
                'notification' => $notification,
                'parsed' => $parsed,
            ];
        }

        $dedupBar->setMessage('done');
        $dedupBar->finish();
        $this->newLine();

        if ($skippedNotOwned > 0) {
            $this->info("  Skipped {$skippedNotOwned} notification(s) for structures NOT owned by Moon Owner Corporation");
        }

        $this->info("  Deduplicated to " . count($uniqueExtractions) . " unique extractions");

        if (empty($uniqueExtractions)) {
            $this->warn("  No extractions found in window. Nothing to backfill.");
            return Command::SUCCESS;
        }

        $created = 0;
        $skipped = 0;
        $skippedNotCompleted = 0;
        $failed = 0;
        $processed = 0;

        // Progress bar for the main processing pass — the slow step.
        // Each iteration does multiple DB queries (existence check, fracture
        // notification search, mining ledger calculation, insert).
        $totalToProcess = min(count($uniqueExtractions), $limit);
        $this->info("  Processing {$totalToProcess} extractions...");
        $bar = $this->output->createProgressBar($totalToProcess);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage('starting');
        $bar->start();

        foreach ($uniqueExtractions as $key => $data) {
            if ($processed >= $limit) {
                $bar->setMessage("reached --limit={$limit}");
                break;
            }

            $notification = $data['notification'];
            $parsed = $data['parsed'];

            try {
                $structureIdVal = (int) $parsed['structureID'];
                $moonId = (int) ($parsed['moonID'] ?? 0);
                $readyTime = $this->eveTimeToCarbon((int) $parsed['readyTime']);
                $autoTime = $this->eveTimeToCarbon((int) $parsed['autoTime']);
                $startTime = Carbon::parse($notification->timestamp);

                // Skip extractions that aren't fully completed yet.
                //
                // MoonminingExtractionStarted notifications are sent when the
                // director fires the laser — readyTime is the FUTURE moment
                // the chunk will arrive (typically 28-30 days later). For such
                // extractions:
                //   - The chunk hasn't arrived yet (readyTime is in the future), OR
                //   - The extraction is within its mining window (< 7 days old),
                //     meaning it lives in moon_extractions and will be moved to
                //     history by the archive-extractions cron after the 7-day
                //     cooldown.
                //
                // Either way, backfill creating a row here produces a bogus
                // "expired" history entry for an extraction that's either
                // currently active or hasn't happened yet.
                $sevenDaysAgo = Carbon::now()->subDays(7);
                if ($readyTime->isAfter($sevenDaysAgo)) {
                    $skippedNotCompleted++;
                    $processed++;
                    $bar->setMessage("struct {$structureIdVal} — not yet completed, skipped");
                    $bar->advance();
                    continue;
                }

                // Existence check uses a ±5 minute window rather than strict
                // equality. ESI-sourced timestamps (in moon_extractions) may
                // differ from the filetime-converted readyTime by a few
                // seconds. Strict equality would miss the match and create a
                // duplicate row.
                $existsInMain = MoonExtraction::where('structure_id', $structureIdVal)
                    ->whereBetween('chunk_arrival_time', [
                        $readyTime->copy()->subMinutes(5),
                        $readyTime->copy()->addMinutes(5),
                    ])
                    ->exists();

                $existsInHistory = MoonExtractionHistory::where('structure_id', $structureIdVal)
                    ->whereBetween('chunk_arrival_time', [
                        $readyTime->copy()->subMinutes(5),
                        $readyTime->copy()->addMinutes(5),
                    ])
                    ->exists();

                if (($existsInMain || $existsInHistory) && !$force) {
                    $skipped++;
                    $processed++;
                    $bar->setMessage("struct {$structureIdVal} — already exists, skipped");
                    $bar->advance();
                    continue;
                }

                // Resolve final state from matching fracture/cancel notifications
                // within this extraction's lifecycle window (started → autoTime + buffer)
                $windowEnd = $autoTime->copy()->addHours(6); // generous buffer for late-arriving notifs
                $finalState = $this->resolveFinalState($structureIdVal, $startTime, $windowEnd);

                // Parse ore composition — oreVolumeByType is {typeID: volumeM3}
                $oreComposition = $this->buildOreComposition($parsed['oreVolumeByType'] ?? []);

                // Calculate actual mined value from ledger — but ONLY for extractions
                // that actually completed. Cancelled extractions never had a chunk
                // fractured, so by definition zero ore was mined from THIS extraction.
                // If mining activity exists in the ledger at that structure during the
                // cancelled extraction's window, it belongs to a DIFFERENT extraction
                // (typically a re-schedule immediately after the cancellation).
                if ($finalState['status'] === 'cancelled') {
                    $minedData = [
                        'total_value' => 0,
                        'total_miners' => 0,
                        'completion_percentage' => 0,
                    ];
                } else {
                    $minedData = $this->calculateActualMined($structureIdVal, $readyTime, $autoTime);
                }

                // Resolve corporation_id from the structure
                $corporationId = $this->resolveCorporationId($structureIdVal);

                $row = [
                    'moon_extraction_id' => null, // synthesized, no live row to link
                    'structure_id' => $structureIdVal,
                    'corporation_id' => $corporationId,
                    'moon_id' => $moonId ?: null,
                    'extraction_start_time' => $startTime,
                    'chunk_arrival_time' => $readyTime,
                    'natural_decay_time' => $autoTime,
                    'archived_at' => Carbon::now(),
                    'final_status' => $finalState['status'],
                    'estimated_value_at_start' => null, // historical prices unknown
                    'estimated_value_at_arrival' => null,
                    'final_estimated_value' => null,
                    'ore_composition' => $oreComposition ?: null, // model casts array → json automatically
                    'actual_mined_value' => $minedData['total_value'],
                    'total_miners' => $minedData['total_miners'],
                    'completion_percentage' => $minedData['completion_percentage'],
                    'is_jackpot' => false,
                    'jackpot_detected_at' => null,
                    'auto_fractured' => $finalState['auto_fractured'],
                    'fractured_at' => $finalState['fractured_at'],
                    'fractured_by' => $finalState['fractured_by'],
                ];

                if ($dryRun) {
                    // Write dry-run output on a new line so it doesn't mangle the progress bar.
                    // Progress bar re-renders on next advance().
                    $bar->clear();
                    $this->line(sprintf(
                        "  [DRY] struct=%d moon=%d ready=%s final=%s miners=%d mined=%.2f ISK",
                        $structureIdVal, $moonId, $readyTime->format('Y-m-d H:i'),
                        $finalState['status'], $minedData['total_miners'], $minedData['total_value']
                    ));
                    $bar->display();
                } else {
                    DB::transaction(function () use ($row, $force, $structureIdVal, $readyTime) {
                        if ($force) {
                            MoonExtractionHistory::where('structure_id', $structureIdVal)
                                ->where('chunk_arrival_time', $readyTime)
                                ->delete();
                        }
                        MoonExtractionHistory::create($row);
                    });
                }

                $created++;
                $processed++;
                $bar->setMessage(sprintf(
                    "struct %d moon %d — %s",
                    $structureIdVal,
                    $moonId,
                    $finalState['status']
                ));
                $bar->advance();
            } catch (\Exception $e) {
                $failed++;
                $processed++;
                $bar->clear();
                $this->error("  Failed to process extraction {$key}: {$e->getMessage()}");
                $bar->display();
                $bar->advance();
                Log::error("BackfillExtractionHistory: failed processing extraction", [
                    'key' => $key,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $bar->setMessage('done');
        $bar->finish();
        $this->newLine(2);

        $this->info("Summary:");
        $this->info("  " . ($dryRun ? 'Would create' : 'Created') . ": {$created}");
        $this->info("  Skipped (already exists): {$skipped}");
        if ($skippedNotCompleted > 0) {
            $this->info("  Skipped (chunk not yet arrived or within 7-day cooldown): {$skippedNotCompleted}");
        }
        if ($failed > 0) {
            $this->warn("  Failed: {$failed}");
        }

        return Command::SUCCESS;
    }

    /**
     * Parse the YAML-like notification text. SeAT stores it as
     * indented key:value pairs similar to YAML. symfony/yaml handles
     * the format robustly.
     */
    private function parseYamlText(string $text): ?array
    {
        try {
            $parsed = Yaml::parse($text);
            return is_array($parsed) ? $parsed : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Convert EVE game time (Windows FILETIME — 100-ns intervals since 1601)
     * into a Carbon instance at the corresponding UTC moment.
     */
    private function eveTimeToCarbon(int $eveTicks): Carbon
    {
        $unixSeconds = intdiv($eveTicks, 10_000_000) - self::FILETIME_EPOCH_OFFSET_SECONDS;
        return Carbon::createFromTimestamp($unixSeconds);
    }

    /**
     * Determine the final state of a historical extraction by scanning
     * for matching fracture/cancel notifications within its window.
     */
    private function resolveFinalState(int $structureId, Carbon $startTime, Carbon $windowEnd): array
    {
        // Check cancellation first (most definitive)
        $cancelled = DB::table('character_notifications')
            ->where('type', 'MoonminingExtractionCancelled')
            ->where('text', 'LIKE', '%structureID: ' . $structureId . '%')
            ->whereBetween('timestamp', [$startTime, $windowEnd])
            ->orderBy('timestamp', 'asc')
            ->first();

        if ($cancelled) {
            $cancelledBy = null;
            if (preg_match('/cancelledBy:\s*\[.*?,\s*"([^"]+)"\]/', $cancelled->text, $m)) {
                $cancelledBy = $m[1];
            }
            return [
                'status' => 'cancelled',
                'auto_fractured' => false,
                'fractured_at' => Carbon::parse($cancelled->timestamp),
                'fractured_by' => $cancelledBy,
            ];
        }

        // Manual laser fire
        $laser = DB::table('character_notifications')
            ->where('type', 'MoonminingLaserFired')
            ->where('text', 'LIKE', '%structureID: ' . $structureId . '%')
            ->whereBetween('timestamp', [$startTime, $windowEnd])
            ->orderBy('timestamp', 'asc')
            ->first();

        if ($laser) {
            $firedBy = null;
            if (preg_match('/firedBy:\s*\[.*?,\s*"([^"]+)"\]/', $laser->text, $m)) {
                $firedBy = $m[1];
            } elseif (preg_match('/fired by (.+?) and/', $laser->text, $m)) {
                $firedBy = $m[1];
            }
            return [
                'status' => 'expired',
                'auto_fractured' => false,
                'fractured_at' => Carbon::parse($laser->timestamp),
                'fractured_by' => $firedBy,
            ];
        }

        // Auto-fracture
        $auto = DB::table('character_notifications')
            ->where('type', 'MoonminingAutomaticFracture')
            ->where('text', 'LIKE', '%structureID: ' . $structureId . '%')
            ->whereBetween('timestamp', [$startTime, $windowEnd])
            ->orderBy('timestamp', 'asc')
            ->first();

        if ($auto) {
            return [
                'status' => 'expired',
                'auto_fractured' => true,
                'fractured_at' => Carbon::parse($auto->timestamp),
                'fractured_by' => null,
            ];
        }

        // No fracture/cancel found — assume auto-fractured based on the chunk lifecycle.
        // Chunk arrives at readyTime, auto-fractures at autoTime if nobody fires.
        // We don't have autoTime here; caller passes windowEnd ≈ autoTime + 6h.
        return [
            'status' => 'expired',
            'auto_fractured' => true,
            'fractured_at' => null, // unknown
            'fractured_by' => null,
        ];
    }

    /**
     * Convert the notification's oreVolumeByType map into the
     * ore_composition JSON shape the plugin uses elsewhere.
     *
     * Input shape: [typeID => volumeM3, ...]
     * Output shape: [oreName => ['percentage' => X, 'volume_m3' => Y], ...]
     */
    private function buildOreComposition(array $oreVolumeByType): array
    {
        if (empty($oreVolumeByType)) {
            return [];
        }

        $totalVolume = array_sum($oreVolumeByType);
        if ($totalVolume <= 0) {
            return [];
        }

        $composition = [];
        foreach ($oreVolumeByType as $typeId => $volume) {
            $oreName = $this->resolveOreName((int) $typeId);
            $composition[$oreName] = [
                'type_id' => (int) $typeId,
                'percentage' => round(($volume / $totalVolume) * 100, 2),
                'volume_m3' => (float) $volume,
            ];
        }

        return $composition;
    }

    /**
     * Look up an ore name from invTypes by typeID. Returns a fallback
     * label if the type isn't in SDE.
     */
    private function resolveOreName(int $typeId): string
    {
        $name = DB::table('invTypes')->where('typeID', $typeId)->value('typeName');
        return $name ?: "Type {$typeId}";
    }

    /**
     * Resolve corporation_id from corporation_structures or fall back
     * to the configured Moon Owner Corporation.
     */
    private function resolveCorporationId(int $structureId): ?int
    {
        $corpId = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->value('corporation_id');

        if ($corpId) {
            return (int) $corpId;
        }

        // Fall back to the configured tax program corp
        try {
            $settings = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
            return $settings->getTaxProgramCorporationId();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Compute actual mined value from mining_ledger for the given
     * structure during the extraction's mining window.
     *
     * Timing note: in EVE, chunk_arrival_time is when the chunk is
     * ready to fracture. natural_decay_time in the plugin's data is
     * the AUTO-FRACTURE time (3 hours after chunk arrival). AFTER
     * fracture (manual or auto), the ore exists as minable belt roids
     * for approximately 48 hours before despawning. So the real
     * mining window is roughly:
     *
     *   readyTime  →  autoTime + 48h  ≈  readyTime + 51h
     *
     * We use a 72-hour window from readyTime to be conservative and
     * catch stragglers who mine just before despawn. The mining_ledger
     * `date` column is date-only (no time), so we compare against
     * date strings covering the full calendar days of the window.
     */
    private function calculateActualMined(int $structureId, Carbon $readyTime, Carbon $decayTime): array
    {
        $windowEnd = $readyTime->copy()->addHours(72);

        $entries = MiningLedger::where('observer_id', $structureId)
            ->where('date', '>=', $readyTime->toDateString())
            ->where('date', '<=', $windowEnd->toDateString())
            ->get();

        if ($entries->isEmpty()) {
            return [
                'total_value' => 0,
                'total_miners' => 0,
                'completion_percentage' => 0,
            ];
        }

        return [
            'total_value' => (float) $entries->sum('total_value'),
            'total_miners' => $entries->pluck('character_id')->unique()->count(),
            'completion_percentage' => 0, // no baseline estimated value available for backfilled rows
        ];
    }
}
