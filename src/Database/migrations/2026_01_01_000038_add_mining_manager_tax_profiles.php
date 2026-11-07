<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MiningManager\Services\Configuration\SettingsTransfer;

/**
 * Move every install's settings into tax profiles, so nobody has to set the
 * plugin up again after the update.
 *
 * SettingsTransfer decides where each value goes, and explains why. This
 * migration keeps a copy of every settings row first, in a table of its own:
 * the settings table's value column is TEXT, too small to hold a snapshot of
 * itself safely. The copy and the transfer run in one transaction, so an
 * install ends up with both or neither.
 *
 * The copy is taken once. A second run never replaces it, because by then
 * the settings table no longer holds what it held before the transfer.
 *
 * down() puts every settings row back exactly as the copy has it, ids
 * included, and drops the copy. Anything saved after the update is lost with
 * it, which is what going back to before the transfer means.
 */
class AddMiningManagerTaxProfiles extends Migration
{
    private const ARCHIVE = 'mining_manager_settings_archive';
    private const REASON = 'before_tax_profiles';

    public function up(): void
    {
        if (!Schema::hasTable('mining_manager_settings')) {
            return;
        }

        if (!Schema::hasTable(self::ARCHIVE)) {
            Schema::create(self::ARCHIVE, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('setting_id');
                $table->string('key');
                $table->text('value')->nullable();
                $table->string('type', 20)->default('string');
                $table->unsignedBigInteger('corporation_id')->nullable();
                $table->text('description')->nullable();
                $table->timestamp('setting_created_at')->nullable();
                $table->timestamp('setting_updated_at')->nullable();
                $table->string('reason', 64);
                $table->timestamp('archived_at')->nullable();

                $table->index(['reason', 'archived_at'], 'mm_settings_archive_reason_idx');
            });
        }

        $transfer = new SettingsTransfer();
        if ($transfer->done()) {
            return;
        }

        DB::transaction(function () use ($transfer) {
            if (!DB::table(self::ARCHIVE)->where('reason', self::REASON)->exists()) {
                $now = now();

                DB::table('mining_manager_settings')->orderBy('id')->chunk(500, function ($rows) use ($now) {
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
                });
            }

            $transfer->apply();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::ARCHIVE)) {
            return;
        }

        if (Schema::hasTable('mining_manager_settings')) {
            DB::transaction(function () {
                DB::table('mining_manager_settings')->delete();

                DB::table(self::ARCHIVE)->where('reason', self::REASON)->orderBy('setting_id')->chunk(500, function ($rows) {
                    DB::table('mining_manager_settings')->insert($rows->map(fn ($row) => [
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
            });
        }

        Schema::dropIfExists(self::ARCHIVE);
    }
}
