<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Services\Ledger\PersonalMiningReconciler;

class ReconcilePersonalMiningCommand extends Command
{
    protected $signature = 'mining-manager:reconcile-personal-mining
                            {--days= : How many days back to look (default 4)}
                            {--dry-run : Report what would go and write nothing}';

    protected $description = 'Remove personal mining rows a corporation observer has already accounted for';

    public function handle(PersonalMiningReconciler $reconciler): int
    {
        $days = (int) ($this->option('days') ?: PersonalMiningReconciler::DEFAULT_DAYS);
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? "Looking at the last {$days} day(s). Nothing will be written."
            : "Reconciling the last {$days} day(s).");
        $this->line('');

        $result = $reconciler->reconcile($days, $dryRun);

        if ($result['moon_owner'] === null) {
            $this->error('No Moon Owner Corporation is set, so there is nothing to match our own refineries against.');

            return self::FAILURE;
        }

        if (!empty($result['rows'])) {
            $this->table(
                ['Ledger id', 'Character', 'Date', 'Type', 'Quantity', 'ISK'],
                array_map(fn ($row) => [
                    $row['id'],
                    $row['character_id'],
                    $row['date'],
                    $row['type_id'],
                    number_format($row['quantity']),
                    number_format($row['value'], 0),
                ], $result['rows'])
            );

            if ($result['removed'] > count($result['rows'])) {
                $this->line('  ... and ' . ($result['removed'] - count($result['rows'])) . ' more.');
            }

            $this->line('');
        }

        $this->table(
            ['', 'Count'],
            [
                ['Personal rows with an observer row beside them', number_format($result['examined'])],
                [$dryRun ? 'Would be removed' : 'Removed', number_format($result['removed'])],
                ['Left alone, an issued invoice covers the day', number_format($result['billed_skipped'])],
                ['Duplicated quantity', number_format($result['quantity'])],
                ['Duplicated value (ISK)', number_format($result['value'], 0)],
                ['Daily summaries rebuilt', number_format($result['summaries'])],
            ]
        );

        if ($result['removed'] === 0) {
            $this->info('Nothing was double counted in that window.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('');
            $this->warn('Dry run. Run it again without --dry-run to remove them.');
        }

        return self::SUCCESS;
    }
}
