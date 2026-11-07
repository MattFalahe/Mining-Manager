<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MiningManager\Services\Configuration\SettingsRegistry;
use MiningManager\Services\Configuration\SettingsTransfer;

/**
 * Clear the settings rows nothing reads once settings are in tax profiles.
 *
 * That is every retired setting, and every copy of an install-wide setting or
 * of one of the plugin's own records saved under a corporation: in tax
 * profiles those are read from their one global row only. Tax profile rows
 * always stay, those of corporations no longer configured included, and so
 * does any key the plugin does not know.
 *
 * It runs only once 000038 has moved the settings, because the move still
 * reads the retired ones. Each row is copied into the archive table before it
 * goes, in the same transaction, and down() puts the rows back.
 */
class RemoveMiningManagerRetiredSettings extends Migration
{
    private const ARCHIVE = 'mining_manager_settings_archive';
    private const REASON = 'retired_settings';

    public function up(): void
    {
        if (!Schema::hasTable('mining_manager_settings') || !Schema::hasTable(self::ARCHIVE)) {
            return;
        }

        if (!(new SettingsTransfer())->done()) {
            return;
        }

        $retired = SettingsRegistry::keys(SettingsRegistry::LEGACY);
        $globalOnly = array_merge(
            SettingsRegistry::keys(SettingsRegistry::INSTALL),
            SettingsRegistry::keys(SettingsRegistry::STATE)
        );

        DB::transaction(function () use ($retired, $globalOnly) {
            $ids = [];
            $now = now();

            DB::table('mining_manager_settings')
                ->where(function ($query) use ($retired, $globalOnly) {
                    $query->whereIn('key', $retired)
                        ->orWhere(function ($copies) use ($globalOnly) {
                            $copies->whereIn('key', $globalOnly)->whereNotNull('corporation_id');
                        });
                })
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$ids, $now) {
                    DB::table(self::ARCHIVE)->insert($rows->map(fn ($row) => [
                        'setting_id' => $row->id,
                        'key' => $row->key,
                        'value' => $row->value,
                        'type' => $row->type,
                        'corporation_id' => $row->corporation_id,
                        'description' => $row->description,
                        'setting_created_at' => $row->created_at,
                        'setting_updated_at' => $row->updated_at,
                        'reason' => self::REASON,
                        'archived_at' => $now,
                    ])->all());

                    foreach ($rows as $row) {
                        $ids[] = (int) $row->id;
                    }
                });

            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('mining_manager_settings')->whereIn('id', $chunk)->delete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mining_manager_settings') || !Schema::hasTable(self::ARCHIVE)) {
            return;
        }

        DB::transaction(function () {
            // A row saved since under the same key and corporation is newer
            // than the copy, so it stays and the copy is dropped.
            DB::table(self::ARCHIVE)->where('reason', self::REASON)->orderBy('setting_id')->chunk(500, function ($rows) {
                DB::table('mining_manager_settings')->insertOrIgnore($rows->map(fn ($row) => [
                    'id' => $row->setting_id,
                    'key' => $row->key,
                    'value' => $row->value,
                    'type' => $row->type,
                    'corporation_id' => $row->corporation_id,
                    'description' => $row->description,
                    'created_at' => $row->setting_created_at,
                    'updated_at' => $row->setting_updated_at,
                ])->all());
            });

            DB::table(self::ARCHIVE)->where('reason', self::REASON)->delete();
        });
    }
}
