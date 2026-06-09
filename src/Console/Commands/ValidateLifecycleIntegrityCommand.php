<?php

namespace MiningManager\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MoonExtraction;

/**
 * Daily integrity check: walks moon_extractions, computes the expected
 * `status` value via the model's canonical lifecycle helpers, and warns
 * (or auto-corrects with --fix) when the persisted status disagrees.
 *
 * Why this exists
 * ---------------
 * The plugin has two pieces of code that decide a chunk's lifecycle
 * position:
 *
 *   1. `MoonExtractionService::determineStatus()` — runs at ESI import
 *      time, computes the status that gets persisted on the row.
 *
 *   2. `MoonExtraction::scopeExpiredByTime()` + the live helpers
 *      (`isReady()`, `isUnstable()`, `getExpiryTime()`) — used by the UI
 *      and downstream consumers at read time.
 *
 * The two should agree. But the original determineStatus() treated
 * `natural_decay_time` (the auto-fracture mark, ~3h after chunk arrival)
 * as the chunk's expiry, while the model helpers correctly use
 * `fractured_at + 50h`. Result: every chunk got stamped `status='expired'`
 * ~3h after arrival while still being mineable for 50 more hours; cron
 * Pass 2 skipped them and the moon_chunk_unstable warning never fired.
 *
 * The determineStatus() bug was fixed in commit 0997dd0. This command
 * exists as a permanent backstop:
 *
 *   - Catches the *next* divergence — any future logic change in either
 *     pathway that drifts apart will surface here within 24h.
 *   - Self-heals rows that got mis-stamped before the deploy (so installs
 *     upgrading from a broken version don't have to babysit a SQL recipe).
 *   - Provides an operator-runnable artisan tool when the diagnostic page
 *     says "active extractions = N" and that number looks wrong.
 *
 * The check is read-only by default. Pass `--fix` to flip mis-stamped
 * rows to the computed status (logged at INFO level per row changed).
 *
 * Scheduled daily via `ScheduleSeeder` (`0 3 * * *` — 03:00 UTC, off the
 * top of the hour to avoid colliding with hourly polls).
 */
class ValidateLifecycleIntegrityCommand extends Command
{
    protected $signature = 'mining-manager:validate-lifecycle-integrity
                            {--fix : Apply corrections to mis-stamped rows (default is read-only audit)}
                            {--quiet-ok : Suppress output when no divergences are found (use in cron mode to reduce log noise)}
                            {--days=14 : Only check rows updated within the last N days (default 14, performance-bounded)}';

    protected $description = 'Audit moon_extractions.status against the model-helper computed status; warn on divergence and optionally fix';

    /**
     * Statuses we DON'T validate:
     *
     *   - cancelled: operator-applied; cannot be derived from time
     *   - fractured: legacy/transient marker no longer in active use
     *
     * Everything else (extracting / ready / expired) is derivable from
     * the chunk_arrival_time + fractured_at + natural_decay_time fields.
     */
    private const NON_VALIDATABLE_STATUSES = ['cancelled', 'fractured'];

    public function handle(): int
    {
        $fix      = (bool) $this->option('fix');
        $quietOk  = (bool) $this->option('quiet-ok');
        $days     = max(1, (int) $this->option('days'));

        $startedAt = microtime(true);
        $now = Carbon::now();
        $cutoff = $now->copy()->subDays($days);

        // Scope to rows seen recently. We're chasing data-quality
        // divergence, not migrating ancient history — old archived rows
        // are out of scope, ArchiveOldExtractionsCommand handles them.
        $candidates = MoonExtraction::whereNotIn('status', self::NON_VALIDATABLE_STATUSES)
            ->where('updated_at', '>=', $cutoff)
            ->orderBy('id')
            ->get();

        $checked       = 0;
        $divergent     = [];
        $missingTiming = 0; // rows we can't evaluate (e.g. both chunk_arrival_time and natural_decay_time null)

        foreach ($candidates as $extraction) {
            $checked++;

            $expected = $this->computeExpectedStatus($extraction, $now);

            if ($expected === null) {
                // Not enough timing data to derive a status. Likely an
                // ESI poll that ran before the structure's full lifecycle
                // data was available. Skip with a count for telemetry.
                $missingTiming++;
                continue;
            }

            if ($extraction->status !== $expected) {
                $divergent[] = [
                    'id'            => $extraction->id,
                    'moon_id'       => $extraction->moon_id,
                    'structure_id'  => $extraction->structure_id,
                    'persisted'     => $extraction->status,
                    'expected'      => $expected,
                    'fractured_at'  => $extraction->fractured_at?->toIso8601String(),
                    'chunk_arrival' => $extraction->chunk_arrival_time?->toIso8601String(),
                    'natural_decay' => $extraction->natural_decay_time?->toIso8601String(),
                ];
            }
        }

        $divergentCount = count($divergent);
        $durationMs = round((microtime(true) - $startedAt) * 1000, 1);

        // Clean-bill-of-health path: no divergences. Respect --quiet-ok
        // for cron-mode silence so the daily run doesn't spam logs/email
        // when everything is fine.
        if ($divergentCount === 0) {
            $msg = "Lifecycle integrity OK — {$checked} row(s) checked, 0 divergent, {$missingTiming} skipped (missing timing data), {$durationMs}ms";
            if (!$quietOk) {
                $this->info($msg);
                Log::info('Mining Manager: lifecycle integrity check passed', [
                    'checked'        => $checked,
                    'missing_timing' => $missingTiming,
                    'duration_ms'    => $durationMs,
                ]);
            }
            return self::SUCCESS;
        }

        // Divergences found. ALWAYS report — even with --quiet-ok.
        $this->warn("Lifecycle integrity check found {$divergentCount} divergent row(s) out of {$checked} checked ({$missingTiming} skipped, {$durationMs}ms):");
        $this->newLine();
        foreach ($divergent as $row) {
            $arrow = $fix ? '→ FIXED' : '(audit only — pass --fix to correct)';
            $this->line(sprintf(
                '  #%d  moon=%d structure=%d  persisted=%s  expected=%s  fractured_at=%s  %s',
                $row['id'],
                $row['moon_id'],
                $row['structure_id'],
                $row['persisted'],
                $row['expected'],
                $row['fractured_at'] ?? 'null',
                $arrow
            ));
        }

        Log::warning('Mining Manager: lifecycle integrity divergences detected', [
            'divergent_count' => $divergentCount,
            'checked'         => $checked,
            'fix_applied'     => $fix,
            'sample'          => array_slice($divergent, 0, 10), // first 10 for the log line; full list went to stdout
        ]);

        if ($fix) {
            $fixed = 0;
            foreach ($divergent as $row) {
                MoonExtraction::where('id', $row['id'])->update(['status' => $row['expected']]);
                Log::info('Mining Manager: lifecycle integrity auto-corrected status', [
                    'extraction_id' => $row['id'],
                    'from'          => $row['persisted'],
                    'to'            => $row['expected'],
                ]);
                $fixed++;
            }
            $this->newLine();
            $this->info("Applied {$fixed} corrections.");
        } else {
            $this->newLine();
            $this->comment('Re-run with --fix to apply corrections, or investigate the rows above first.');
        }

        // Exit non-zero so the daily cron's exit code reflects "needs
        // attention" — operators wiring monitoring on schedule history
        // will see this. The fix path resolves it: rows are corrected,
        // next-day run reports clean.
        return self::FAILURE;
    }

    /**
     * Compute what the row's status SHOULD be based on the timing fields,
     * mirroring the math in `MoonExtractionService::determineStatus()`
     * (post-fix) and `MoonExtraction::scopeExpiredByTime()`.
     *
     * Returns null when there isn't enough timing data to decide (caller
     * counts these as "skipped" rather than divergent).
     *
     *   - extracting: now < chunk_arrival_time
     *   - ready:      chunk_arrival_time <= now < fractureTime + 50h
     *   - expired:    now >= fractureTime + 50h
     *
     * Where fractureTime is fractured_at (if populated) or
     * natural_decay_time (as a conservative auto-fracture estimate when
     * fracture detection hasn't yet populated the explicit timestamp).
     */
    private function computeExpectedStatus(MoonExtraction $extraction, Carbon $now): ?string
    {
        if (!$extraction->chunk_arrival_time) {
            return null;
        }

        if ($now->lt($extraction->chunk_arrival_time)) {
            return 'extracting';
        }

        $fractureTime = $extraction->fractured_at
            ?? $extraction->natural_decay_time;

        if (!$fractureTime) {
            // Past chunk_arrival but no fracture info either — undecidable.
            // ESI sometimes lags on natural_decay_time for very fresh rows.
            return null;
        }

        $fractureTime = $fractureTime instanceof Carbon
            ? $fractureTime
            : Carbon::parse($fractureTime);

        $expiry = $fractureTime->copy()->addHours(50);

        return $now->lt($expiry) ? 'ready' : 'expired';
    }
}
