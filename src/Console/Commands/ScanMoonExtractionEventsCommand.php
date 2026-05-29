<?php

namespace MiningManager\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Events\MoonExtractionEventPublisher;

/**
 * Scans active moon extractions and publishes lifecycle events to Manager
 * Core's EventBus exactly once per stage per extraction.
 *
 * Stages:
 *   - ready    : chunk has fractured, fleet-able window opens (48h)
 *   - unstable : final 2h capital-safety window before expiry
 *   - expired  : window closed, no more mining
 *
 * Per-stage dedup is enforced via the `moon_extraction_event_log` table.
 * Each stage column (e.g. `ready_published_at`) is NULL until the event
 * has been published, then stamped with NOW(). The scanner re-runs are
 * idempotent: an already-stamped stage is skipped.
 *
 * Standalone-safe: with Manager Core absent the publisher returns false
 * for every call, the log row never gets stamped, and the scanner reports
 * zero publishes. No errors, no log noise.
 *
 * Designed to run on a 5-minute cron. The latency floor between the
 * extraction transitioning state and the event firing is therefore ~5 min,
 * which is plenty for the consumer's purposes (FC formup planning, not
 * tick-accurate alarms).
 */
class ScanMoonExtractionEventsCommand extends Command
{
    protected $signature = 'mining-manager:scan-extraction-events
                            {--limit=200 : Cap how many extractions to evaluate per run}
                            {--dry-run : Show what would be published without firing or stamping latches}';

    protected $description = 'Publish mining.extraction_ready / unstable / expired events to Manager Core EventBus (idempotent per extraction per stage).';

    public function handle(): int
    {
        // Cluster-safe single-instance lock — 10 minute TTL covers the scan
        // even on a large install. The 5-minute cron will retry on next
        // tick if the lock is held by a stuck run.
        $lock = Cache::lock('mining-manager:scan-extraction-events', 600);
        if (! $lock->get()) {
            $this->warn('Another instance is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
            // Skip entirely when Manager Core isn't installed — the publisher
            // would no-op anyway, but skipping early saves a DB scan and
            // makes the dry-run output honest about the situation.
            if (! class_exists(\ManagerCore\Topics::class)) {
                $this->info('Manager Core is not installed; nothing to publish. Skipping.');
                return Command::SUCCESS;
            }

            $isDry  = (bool) $this->option('dry-run');
            $limit  = max(1, (int) $this->option('limit'));

            // Consider every extraction whose state could possibly need a
            // publish: anything that has a fracture time set (so it can be
            // ready/unstable/expired) or a chunk_arrival_time that has
            // already passed. The narrowest cut still safely covers all
            // three stages.
            $extractions = MoonExtraction::query()
                ->whereNotNull('chunk_arrival_time')
                ->where(function ($q) {
                    $q->whereNotNull('fractured_at')
                      ->orWhere('chunk_arrival_time', '<=', Carbon::now());
                })
                ->orderBy('chunk_arrival_time')
                ->limit($limit)
                ->get();

            if ($extractions->isEmpty()) {
                $this->info('No candidate extractions found.');
                return Command::SUCCESS;
            }

            // Bulk-load display names so payloads don't trigger N+1.
            MoonExtraction::loadDisplayNames($extractions);

            // Pre-fetch every existing log row in one query.
            $logRows = DB::table('moon_extraction_event_log')
                ->whereIn('extraction_id', $extractions->pluck('id')->all())
                ->get()
                ->keyBy('extraction_id');

            $published = ['ready' => 0, 'unstable' => 0, 'expired' => 0];
            $skipped   = 0;

            foreach ($extractions as $extraction) {
                $stage = $extraction->getEffectiveStatus();

                // Map MM's effective status onto our event stages.
                // Skip 'extracting' (chunk hasn't arrived) — nothing to
                // publish yet. Skip unknowns.
                $eventStage = match ($stage) {
                    'ready'    => 'ready',
                    'unstable' => 'unstable',
                    'expired'  => 'expired',
                    default    => null,
                };

                if ($eventStage === null) {
                    $skipped++;
                    continue;
                }

                $logRow = $logRows->get($extraction->id);
                $latchColumn = $eventStage . '_published_at';

                // Already stamped? Nothing to do for this stage.
                if ($logRow && ! empty($logRow->{$latchColumn})) {
                    // Still need to consider whether a LATER stage now
                    // applies (e.g. row latched ready 30h ago, now
                    // unstable). Don't continue — fall through to also
                    // check / publish unstable + expired.
                } else {
                    if ($isDry) {
                        $this->line(sprintf(
                            '  [dry] would publish mining.extraction_%s for #%d (%s)',
                            $eventStage,
                            $extraction->id,
                            $extraction->moon_name ?? '?'
                        ));
                        $published[$eventStage]++;
                    } else {
                        $ok = self::publishForStage($eventStage, $extraction);

                        if ($ok) {
                            $this->upsertLatch($extraction->id, $latchColumn);
                            $published[$eventStage]++;
                            $this->line(sprintf(
                                '  ✓ published mining.extraction_%s for #%d (%s)',
                                $eventStage,
                                $extraction->id,
                                $extraction->moon_name ?? '?'
                            ));
                        } else {
                            $this->warn(sprintf(
                                '  publish failed for mining.extraction_%s on #%d',
                                $eventStage,
                                $extraction->id
                            ));
                        }
                    }
                }

                // Catch up earlier stages if the scanner missed them
                // (e.g. cron was paused, MC was offline). Without this,
                // an extraction that we first observe in 'unstable' state
                // would never get its 'ready' event published, and the
                // consumer's calendar would miss the window-opens-at
                // signal entirely.
                $this->catchUpEarlierStages($eventStage, $extraction, $logRow, $isDry, $published);
            }

            $total = array_sum($published);
            $this->info(sprintf(
                'Done. Published: %d ready, %d unstable, %d expired (%d total). Skipped: %d.',
                $published['ready'],
                $published['unstable'],
                $published['expired'],
                $total,
                $skipped
            ));

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            Log::error('[MiningManager] scan-extraction-events crashed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Crashed: ' . $e->getMessage());
            return Command::FAILURE;

        } finally {
            $lock->release();
        }
    }

    /**
     * Call the publisher for a given stage. Tiny wrapper so the call sites
     * above stay readable.
     */
    protected static function publishForStage(string $stage, MoonExtraction $extraction): bool
    {
        return match ($stage) {
            'ready'    => MoonExtractionEventPublisher::publishReady($extraction),
            'unstable' => MoonExtractionEventPublisher::publishUnstable($extraction),
            'expired'  => MoonExtractionEventPublisher::publishExpired($extraction),
            default    => false,
        };
    }

    /**
     * If an extraction is currently in stage X but earlier stages Y / Z
     * were never published (because the scanner wasn't running, or MC was
     * offline, or this is a brand-new install with already-active moons),
     * publish those earlier stages too so the consumer's lifecycle picture
     * is complete. Idempotency latches still prevent any double publishes
     * even when this back-fills.
     */
    protected function catchUpEarlierStages(
        string $currentStage,
        MoonExtraction $extraction,
        $logRow,
        bool $isDry,
        array &$published
    ): void {
        $order = ['ready', 'unstable', 'expired'];
        $currentIdx = array_search($currentStage, $order, true);
        if ($currentIdx === false || $currentIdx === 0) {
            return;
        }

        for ($i = 0; $i < $currentIdx; $i++) {
            $stage = $order[$i];
            $col   = $stage . '_published_at';

            if ($logRow && ! empty($logRow->{$col})) {
                continue;
            }

            if ($isDry) {
                $this->line(sprintf(
                    '  [dry] would back-fill mining.extraction_%s for #%d',
                    $stage,
                    $extraction->id
                ));
                $published[$stage]++;
                continue;
            }

            $ok = self::publishForStage($stage, $extraction);
            if ($ok) {
                $this->upsertLatch($extraction->id, $col);
                $published[$stage]++;
            }
        }
    }

    /**
     * Insert-or-update the per-extraction log row, stamping the given
     * stage column with NOW(). Carefully avoids clobbering `created_at`
     * on the update path — Laravel's updateOrInsert sets the second-arg
     * fields on UPDATE too, so we split insert / update explicitly.
     */
    protected function upsertLatch(int $extractionId, string $column): void
    {
        $now = Carbon::now();

        $exists = DB::table('moon_extraction_event_log')
            ->where('extraction_id', $extractionId)
            ->exists();

        if ($exists) {
            DB::table('moon_extraction_event_log')
                ->where('extraction_id', $extractionId)
                ->update([
                    $column      => $now,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('moon_extraction_event_log')->insert([
                'extraction_id' => $extractionId,
                $column         => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }
}
