<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class RestoreDataCommand extends Command
{
    protected $signature = 'mining-manager:restore-data
                            {path? : Path to backup directory (default: latest from /opt/seat-docker/mining-manager-backup/)}
                            {--tables= : Comma-separated list of specific tables to restore}
                            {--force : Skip confirmation prompts}
                            {--no-truncate : Append data instead of replacing (use with caution)}';

    protected $description = 'Restore Mining Manager plugin data from a JSON backup';

    public function handle()
    {
        $backupPath = $this->argument('path');

        // Auto-detect latest backup if no path provided
        if (!$backupPath) {
            $basePath = storage_path('mining-manager-backup');
            if (!is_dir($basePath)) {
                $this->error("No backup directory found at {$basePath}");
                $this->error("Run mining-manager:backup-data first, or provide a path.");
                return Command::FAILURE;
            }

            $dirs = array_filter(glob($basePath . '/*'), 'is_dir');
            if (empty($dirs)) {
                $this->error("No backups found in {$basePath}");
                return Command::FAILURE;
            }

            sort($dirs);
            $backupPath = end($dirs); // Latest by timestamp folder name
            $this->info("Auto-selected latest backup: {$backupPath}");
        }

        // Handle .tar.gz archive — extract to temp directory first
        if (is_file($backupPath) && preg_match('/\.tar\.gz$/', $backupPath)) {
            $this->info("Extracting archive: {$backupPath}");
            $extractPath = storage_path('mining-manager-backup/_restore_temp_' . time());
            mkdir($extractPath, 0755, true);

            try {
                $phar = new \PharData($backupPath);
                $phar->extractTo($extractPath);
                $backupPath = $extractPath;
                $this->info("Extracted to: {$extractPath}");
            } catch (\Exception $e) {
                $this->error("Failed to extract archive: {$e->getMessage()}");
                return Command::FAILURE;
            }
        }

        $this->info('');
        $this->info('======================================');
        $this->info('  Mining Manager Data Restore');
        $this->info('======================================');
        $this->info('');

        // Verify backup exists
        $manifestPath = $backupPath . '/manifest.json';
        if (!file_exists($manifestPath)) {
            $this->error("Manifest not found at: {$manifestPath}");
            $this->error("Make sure the path points to a backup directory or .tar.gz archive created by mining-manager:backup-data");
            return Command::FAILURE;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest) {
            $this->error("Failed to parse manifest.json");
            return Command::FAILURE;
        }

        $this->info("Backup info:");
        $this->info("  Plugin: {$manifest['plugin']}");
        $this->info("  Version: {$manifest['version']}");
        $this->info("  Created: {$manifest['created_at']}");
        $this->info("  Total rows: {$manifest['total_rows']}");
        $this->info('');

        // Determine tables to restore
        $tables = $this->getTableList($manifest);

        if (empty($tables)) {
            $this->warn('No tables to restore.');
            return Command::SUCCESS;
        }

        // Show what will be restored
        $this->table(['Table', 'Rows in Backup', 'File'], collect($tables)->map(function ($table) use ($manifest) {
            $info = $manifest['tables'][$table] ?? [];
            return [$table, $info['rows'] ?? 0, $info['file'] ?? 'N/A'];
        })->toArray());

        // Confirm
        if (!$this->option('force')) {
            $truncateMsg = $this->option('no-truncate') ? 'Data will be APPENDED to existing tables.' : 'Existing data in these tables will be REPLACED.';
            $this->warn($truncateMsg);

            if (!$this->confirm('Proceed with restore?')) {
                $this->info('Restore cancelled.');
                return Command::SUCCESS;
            }
        }

        // Verify all target tables exist
        $missing = [];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if (!empty($missing)) {
            $this->error('Target tables do not exist: ' . implode(', ', $missing));
            $this->error('Run php artisan migrate first to create the tables.');
            return Command::FAILURE;
        }

        // Disable foreign key checks during restore
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $restoredRows = 0;
        $errors = [];

        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        foreach ($tables as $table) {
            $info = $manifest['tables'][$table] ?? [];

            if (($info['status'] ?? '') !== 'ok' || empty($info['file'])) {
                $bar->advance();
                continue;
            }

            $filePath = $backupPath . '/' . $info['file'];
            if (!file_exists($filePath)) {
                $errors[] = "{$table}: backup file not found ({$info['file']})";
                $bar->advance();
                continue;
            }

            try {
                // Truncate existing data unless --no-truncate
                if (!$this->option('no-truncate')) {
                    DB::table($table)->truncate();
                }

                // Stream the JSON file and insert in batches
                $jsonContent = file_get_contents($filePath);
                $rows = json_decode($jsonContent, true);

                if (!is_array($rows)) {
                    $errors[] = "{$table}: invalid JSON data";
                    $bar->advance();
                    continue;
                }

                // Insert in chunks of 500 to avoid query size limits
                $chunks = array_chunk($rows, 500);
                foreach ($chunks as $chunk) {
                    DB::table($table)->insert($chunk);
                }

                $restoredRows += count($rows);

                // Verify row count
                $actualCount = DB::table($table)->count();
                $expectedCount = $this->option('no-truncate') ? null : $info['rows'];

                if ($expectedCount !== null && $actualCount !== $expectedCount) {
                    $errors[] = "{$table}: row count mismatch (expected {$expectedCount}, got {$actualCount})";
                }

            } catch (\Exception $e) {
                $errors[] = "{$table}: {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->info('');
        $this->info('');

        if (!empty($errors)) {
            $this->warn('Restore completed with errors:');
            foreach ($errors as $error) {
                $this->error("  - {$error}");
            }
        } else {
            $this->info('Restore completed successfully!');
        }

        $this->info("Total rows restored: {$restoredRows}");

        // Post-restore verification
        $this->info('');
        $this->info('Verifying restored data...');
        $verifyResults = [];
        foreach ($tables as $table) {
            $info = $manifest['tables'][$table] ?? [];
            if (($info['status'] ?? '') !== 'ok') continue;

            $actualCount = DB::table($table)->count();
            $backupCount = $info['rows'] ?? 0;
            $match = $this->option('no-truncate') ? ($actualCount >= $backupCount) : ($actualCount === $backupCount);

            $verifyResults[] = [
                $table,
                $backupCount,
                $actualCount,
                $match ? 'OK' : 'MISMATCH',
            ];
        }

        $this->table(['Table', 'Backup Rows', 'Current Rows', 'Status'], $verifyResults);

        Log::info("Mining Manager: Data restore completed. {$restoredRows} rows restored.", [
            'path' => $backupPath,
            'errors' => $errors,
        ]);

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }

    private function getTableList(array $manifest): array
    {
        if ($this->option('tables')) {
            $requested = array_map('trim', explode(',', $this->option('tables')));
            return array_intersect($requested, array_keys($manifest['tables']));
        }

        // Return all tables that have data in the backup
        return collect($manifest['tables'])
            ->filter(fn($info) => ($info['status'] ?? '') === 'ok' && ($info['rows'] ?? 0) > 0)
            ->keys()
            ->toArray();
    }
}
