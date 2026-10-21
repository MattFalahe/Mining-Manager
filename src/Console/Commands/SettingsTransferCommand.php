<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MiningManager\Services\Configuration\SettingsRegistry;
use MiningManager\Services\Configuration\SettingsTransfer;

class SettingsTransferCommand extends Command
{
    protected $signature = 'mining-manager:settings-transfer
                            {--dry-run : Show what the transfer would write from the settings as they are now, and write nothing}';

    protected $description = 'Show how settings were carried into tax profiles, or what carrying them would do';

    public function handle(SettingsTransfer $transfer): int
    {
        // The migration runs the transfer. Here it is only ever shown, so
        // there is no way to run it twice or out of order.
        if ($report = $transfer->storedReport()) {
            $this->info("Settings were carried into tax profiles on {$report['transferred_at']}.");
            $this->line('');
            $this->render($report);

            return self::SUCCESS;
        }

        if (!$this->option('dry-run')) {
            $this->error('Settings have not been carried into tax profiles yet. The plugin\'s migrations do that; add --dry-run to see what it would write.');

            return self::FAILURE;
        }

        $this->info('Dry run: what the transfer would write from the settings as they are now. Nothing will be written.');
        $this->line('');
        $this->render($transfer->report($transfer->plan()));
        $this->line('');
        $this->info('Dry run. Nothing was written.');

        return self::SUCCESS;
    }

    private function render(array $report): void
    {
        $ids = array_values(array_unique(array_merge(
            $report['configured'],
            array_filter([$report['moon_owner']]),
            array_column($report['superseded'], 'corporation_id')
        )));
        $corporations = DB::table('corporation_infos')
            ->whereIn('corporation_id', $ids)
            ->get(['corporation_id', 'name', 'ticker'])
            ->keyBy('corporation_id');
        $label = function (int $id) use ($corporations) {
            $corporation = $corporations[$id] ?? null;

            return $corporation ? "[{$corporation->ticker}] {$corporation->name}" : "Corporation {$id}";
        };
        $shown = fn (?string $value) => $value === null ? '(not set)' : ($value === '' ? '(empty)' : $value);

        $this->line('Moon Owner: ' . ($report['moon_owner'] ? $label($report['moon_owner']) : 'not set'));
        if ($report['configured']) {
            $this->line('Configured corporations: ' . implode(', ', array_map($label, $report['configured'])));
            $this->line('Everyone else mines under the Global profile.');
        } else {
            $this->line('No corporations are configured, so the Global profile taxes everyone.');
        }
        $this->line('');

        $order = array_merge(['Global profile'], array_map(fn (int $id) => $label($id) . ' profile', $report['configured']), ['Install-wide', 'The plugin\'s own records']);
        $groups = array_fill_keys($order, []);
        foreach ($report['writes'] as $write) {
            $scope = SettingsRegistry::scope($write['key']);
            $group = match (true) {
                $scope === SettingsRegistry::PROFILE && $write['corporation_id'] === null => 'Global profile',
                $scope === SettingsRegistry::PROFILE => $label($write['corporation_id']) . ' profile',
                $scope === SettingsRegistry::STATE => 'The plugin\'s own records',
                default => 'Install-wide',
            };
            $groups[$group][] = [$write['key'], $shown($write['from']), $shown($write['to']), $write['reason']];
        }

        foreach (array_filter($groups) as $group => $rows) {
            $this->line($group);
            $this->table(['Setting', 'Was', 'Becomes', 'Why'], $rows);
            $this->line('');
        }

        $this->line(sprintf('Written: %d. Already where they belong: %d.', $report['written'], $report['kept']));
        if (!empty($report['writes_cut'])) {
            $this->line('The stored report keeps the first ' . count($report['writes']) . ' writes.');
        }
        $this->line('');

        if ($report['superseded']) {
            $this->line('Copies the plugin stops reading. They stay in the table, unused.');
            $this->table(['Setting', 'Corporation', 'Its copy', 'Value in effect'], array_map(fn (array $copy) => [
                $copy['key'],
                $label($copy['corporation_id']),
                $shown($copy['value']),
                $copy['in_effect'] === null ? '(default)' : $shown($copy['in_effect']),
            ], $report['superseded']));
            if (!empty($report['superseded_cut'])) {
                $this->line('The stored report keeps the first ' . count($report['superseded']) . '.');
            }
            $this->line('');
        }

        if ($report['effects']) {
            $this->line('What changes from the day it runs:');
            foreach ($report['effects'] as $effect) {
                $this->line(' - ' . $effect);
            }
        } else {
            $this->line('Nothing changes in effect: everyone is taxed as before.');
        }
    }
}
