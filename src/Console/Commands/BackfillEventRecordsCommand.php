<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningEvent;
use MiningManager\Services\Events\EventMiningAggregator;

/**
 * Backfill event_mining_records for existing events.
 *
 * Run once after the 2026_01_01_000005_create_event_mining_records migration
 * deploys to populate the new table from historical data. Subsequent
 * aggregation runs automatically via the update-events cron and the
 * MiningEvent saved() hook.
 *
 * Usage
 * =====
 *   mining-manager:backfill-event-records            # aggregate all non-cancelled events
 *   mining-manager:backfill-event-records --event=42 # aggregate only event #42
 *   mining-manager:backfill-event-records --fresh    # full-refresh: delete existing records first
 *   mining-manager:backfill-event-records --status=active
 *
 * Idempotent: without --fresh, re-running the command simply re-upserts
 * the same rows (no duplicates). With --fresh it deletes and rebuilds
 * each event's records from scratch.
 */
class BackfillEventRecordsCommand extends Command
{
    protected $signature = 'mining-manager:backfill-event-records
                            {--event= : Only backfill this specific event ID}
                            {--status= : Only backfill events with this status (active, completed, planned)}
                            {--fresh : Delete existing event_mining_records before rebuilding (use on scope corrections)}';

    protected $description = 'Populate event_mining_records for existing events (one-off backfill after migration)';

    public function handle(EventMiningAggregator $aggregator): int
    {
        $query = MiningEvent::query();

        if ($eventId = $this->option('event')) {
            $query->where('id', $eventId);
        } elseif ($status = $this->option('status')) {
            $query->where('status', $status);
        } else {
            // Default: every non-cancelled event. Planned events usually
            // yield zero records (event hasn't started) but we still touch
            // them so the table is consistent.
            $query->whereIn('status', ['planned', 'active', 'completed']);
        }

        $events = $query->orderBy('id')->get();

        if ($events->isEmpty()) {
            $this->warn('No events matched — nothing to backfill.');
            return Command::SUCCESS;
        }

        $this->info("Backfilling event_mining_records for {$events->count()} events"
            . ($this->option('fresh') ? ' (fresh rebuild — existing records will be deleted first)' : '')
            . '...');

        $fresh = (bool) $this->option('fresh');
        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];
        $processed = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($events->count());
        $bar->start();

        foreach ($events as $event) {
            try {
                $result = $aggregator->aggregate($event, $fresh);
                $totals['created'] += $result['created'];
                $totals['updated'] += $result['updated'];
                $totals['skipped'] += $result['skipped'];
                $totals['deleted'] += $result['deleted'];
                $processed++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Event {$event->id} ({$event->name}) failed: {$e->getMessage()}");
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Events processed', $processed],
                ['Errors', $errors],
                ['Records created', $totals['created']],
                ['Records updated', $totals['updated']],
                ['Rows skipped by filter', $totals['skipped']],
                ['Prior records deleted (--fresh)', $totals['deleted']],
            ]
        );

        if ($errors > 0) {
            $this->warn("Completed with {$errors} error(s). Check the log for details.");
            return Command::FAILURE;
        }

        $this->info('Backfill complete.');
        return Command::SUCCESS;
    }
}
