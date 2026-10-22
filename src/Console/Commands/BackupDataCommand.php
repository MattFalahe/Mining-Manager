<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BackupDataCommand extends Command
{
    protected $signature = 'mining-manager:backup-data
                            {--path= : Custom backup directory path}
                            {--tables= : Comma-separated list of specific tables to backup}
                            {--no-ledger : Skip the mining_ledger table (largest table, can be regenerated)}';

    protected $description = 'Backup all Mining Manager plugin data to JSON files for migration, disaster recovery, or server transfer';

    /**
     * Every table the plugin's migrations create, related ones together.
     * Restore turns foreign key checks off while it runs, so the order is for
     * whoever reads the backup, not for the constraints. A table added by a
     * new migration belongs here too, or backups quietly leave it out.
     */
    private const PLUGIN_TABLES = [
        'mining_manager_settings',
        'mining_manager_settings_archive',
        'webhook_configurations',
        'mining_events',
        'event_participants',
        'event_mining_records',
        'moon_extractions',
        'moon_extraction_history',
        'moon_extraction_event_log',
        'moon_extraction_plans',
        'moon_extraction_plan_audits',
        'mining_manager_moon_rotations',
        'mining_manager_moon_rotation_slots',
        'mining_manager_refinery_alerts',
        'mining_manager_moon_scan_alerts',
        'mining_manager_moon_claims',
        'mining_manager_moon_watchlist',
        'metenox_cargo_alert_state',
        'mining_ledger',
        'mining_ledger_daily_summaries',
        'mining_ledger_monthly_summaries',
        'mining_taxes',
        'mining_tax_codes',
        'tax_invoices',
        'mining_manager_payment_credits',
        'mining_manager_payment_allocations',
        'mining_manager_payment_refunds',
        'mining_reports',
        'report_schedules',
        'mining_notification_log',
        'monthly_statistics',
        'mining_price_cache',
        'mining_manager_character_affiliations',
        'theft_incidents',
        'mining_manager_dismissed_transactions',
        'mining_manager_processed_transactions',
    ];

    public function handle()
    {
        $this->info('');
        $this->info('======================================');
        $this->info('  Mining Manager Data Backup');
        $this->info('======================================');
        $this->info('');

        // Determine backup path
        $timestamp = Carbon::now()->format('Y-m-d_His');
        $basePath = $this->option('path') ?: storage_path('mining-manager-backup');
        $backupPath = $basePath . '/' . $timestamp;

        // Determine tables to backup
        $tables = $this->getTableList();

        // Verify all tables exist
        $this->info('Verifying tables...');
        $missing = [];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if (!empty($missing)) {
            $this->warn('Tables not found (skipping): ' . implode(', ', $missing));
            $tables = array_diff($tables, $missing);
        }

        if (empty($tables)) {
            $this->error('No tables to backup.');
            return Command::FAILURE;
        }

        // Create backup directory
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        $this->info("Backup path: {$backupPath}");
        $this->info("Tables to backup: " . count($tables));
        $this->info('');

        $manifest = [
            'plugin' => 'mining-manager',
            'version' => $this->pluginVersion(),
            'created_at' => Carbon::now()->toIso8601String(),
            'seat_version' => config('seat.version', 'unknown'),
            'tables' => [],
        ];

        $totalRows = 0;
        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        foreach ($tables as $table) {
            try {
                $count = DB::table($table)->count();

                if ($count === 0) {
                    $manifest['tables'][$table] = ['rows' => 0, 'file' => null, 'status' => 'empty'];
                    $bar->advance();
                    continue;
                }

                // Stream large tables in chunks to avoid memory issues
                $filePath = $backupPath . '/' . $table . '.json';
                $handle = fopen($filePath, 'w');
                fwrite($handle, "[\n");

                $first = true;
                $write = function ($rows) use ($handle, &$first) {
                    foreach ($rows as $row) {
                        if (!$first) {
                            fwrite($handle, ",\n");
                        }
                        fwrite($handle, json_encode((array) $row, JSON_UNESCAPED_UNICODE));
                        $first = false;
                    }
                };

                // chunk() pages by a column, and the few tables keyed on
                // something other than id are small enough to read in one go.
                if (Schema::hasColumn($table, 'id')) {
                    DB::table($table)->orderBy('id')->chunk(1000, $write);
                } else {
                    $write(DB::table($table)->get());
                }

                fwrite($handle, "\n]");
                fclose($handle);

                $fileSize = filesize($filePath);
                $manifest['tables'][$table] = [
                    'rows' => $count,
                    'file' => $table . '.json',
                    'size_bytes' => $fileSize,
                    'status' => 'ok',
                ];

                $totalRows += $count;

            } catch (\Exception $e) {
                $manifest['tables'][$table] = ['rows' => 0, 'file' => null, 'status' => 'error', 'error' => $e->getMessage()];
                $this->error("  Error backing up {$table}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->info('');
        $this->info('');

        $manifest['total_rows'] = $totalRows;

        // Write manifest
        file_put_contents(
            $backupPath . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // Summary
        $this->info('Backup complete!');
        $this->info('');
        $this->table(['Table', 'Rows', 'Status'], collect($manifest['tables'])->map(function ($info, $table) {
            return [$table, $info['rows'], $info['status']];
        })->toArray());
        $this->info('');
        $this->info("Total rows: {$totalRows}");
        $this->info("Backup path: {$backupPath}");
        $this->info("Manifest: {$backupPath}/manifest.json");

        // Create a portable tar.gz archive for easy extraction
        $archiveName = 'mining-manager-backup-' . $timestamp . '.tar.gz';
        $archivePath = $basePath . '/' . $archiveName;
        try {
            $phar = new \PharData($archivePath);
            $phar->buildFromDirectory($backupPath);
            $this->info('');
            $this->info("Archive: {$archivePath}");
            $this->info('');
            $this->info('To copy backup to Docker host:');
            $this->info("  docker cp seat-docker-front-1:{$archivePath} /opt/seat-docker/");
        } catch (\Exception $e) {
            $this->warn("Could not create archive: {$e->getMessage()}");
            $this->info('');
            $this->info('To copy backup to Docker host:');
            $this->info("  docker cp seat-docker-front-1:{$backupPath} /opt/seat-docker/mining-manager-backup/");
        }

        Log::info("Mining Manager: Data backup completed. {$totalRows} rows across " . count($tables) . " tables.", [
            'path' => $backupPath,
        ]);

        return Command::SUCCESS;
    }

    /**
     * The installed version as Composer resolved it: a tag such as 2.0.5, or
     * the branch on an install that tracks one. Only a label in the manifest.
     */
    private function pluginVersion(): string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class)
                && \Composer\InstalledVersions::isInstalled('mattfalahe/mining-manager')) {
                return (string) \Composer\InstalledVersions::getPrettyVersion('mattfalahe/mining-manager');
            }
        } catch (\Throwable $e) {
            // Fall through to unknown; nothing reads this back.
        }

        return 'unknown';
    }

    private function getTableList(): array
    {
        if ($this->option('tables')) {
            return array_map('trim', explode(',', $this->option('tables')));
        }

        $tables = self::PLUGIN_TABLES;

        if ($this->option('no-ledger')) {
            $tables = array_filter($tables, fn($t) => $t !== 'mining_ledger');
        }

        return array_values($tables);
    }
}
