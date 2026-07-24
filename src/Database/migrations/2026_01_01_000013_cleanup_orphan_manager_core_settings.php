<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Clean up orphan top-level `manager_core_market` and `manager_core_variant`
 * rows in `mining_manager_settings`.
 *
 * `SettingsController::updatePricing()` used to write the user's MC market
 * and variant selections to BOTH the top-level keys (e.g. `manager_core_market`)
 * AND the prefixed keys (e.g. `pricing.manager_core_market`). The reader
 * (`SettingsManagerService::getPricingSettings()`) only looks at the prefixed
 * forms, so the top-level rows were never read by anything — orphan data.
 *
 * The M11 commit (controller change) removed the duplicate write going
 * forward. This migration cleans up the orphans on existing installs that
 * accumulated those rows from prior saves.
 *
 * Safe to run repeatedly: `delete()` is idempotent. Returns 0 affected on
 * a clean install.
 *
 * Forward-only — backward compat with the released v1.0.2 migration set.
 *
 */
class CleanupOrphanManagerCoreSettings extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mining_manager_settings')) {
            return;
        }

        $deleted = DB::table('mining_manager_settings')
            ->whereIn('key', ['manager_core_market', 'manager_core_variant'])
            ->delete();

        if ($deleted > 0) {
            Log::info("[Mining Manager] Cleaned up {$deleted} orphan manager_core_market/variant settings rows (data preserved at pricing.manager_core_* keys)");
        }
    }

    public function down(): void
    {
        // Cleanup is one-way. The prefixed (`pricing.manager_core_market`)
        // rows are the canonical source of truth and remain untouched, so
        // there's nothing meaningful to restore.
    }
}
