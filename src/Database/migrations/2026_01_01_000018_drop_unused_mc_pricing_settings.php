<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Drop now-unused `pricing.manager_core_market` and `pricing.manager_core_variant`
 * rows from `mining_manager_settings`.
 *
 * Background: those two settings used to be user-facing dropdowns on the
 * Pricing settings tab (Market dropdown + Price Variant dropdown inside the
 * "Manager Core Configuration" panel). Both were removed in commit 583ea48
 * on dev-5.0 when the panel was rewritten as a read-only status readout +
 * deep-link to MC's Pricing Preferences — single source of truth for which
 * market MM should read from is now MC's `manager_core_pricing_preferences`
 * table.
 *
 * SettingsController::updatePricing kept writing 'jita' / 'min' as bootstrap
 * defaults on every save for backwards-compat with code paths that still
 * read these settings. After the read-path fix in commit d61e9e9
 * (CachePriceDataCommand reads the market from MC's getPreferenceForPlugin
 * capability now), there are no remaining readers — the rows are pure
 * dead weight.
 *
 * This migration:
 *   - Deletes the two now-unused prefixed keys
 *   - Companion controller cleanup (in this same commit) drops the writes
 *
 * Safe to run repeatedly: `delete()` is idempotent. Returns 0 affected on
 * a clean install where the rows were never written.
 *
 * Why number 000018: fixed-date prefix per plugin, sequential numbering.
 * 000017 was the latest migration in the chain; 000018 is the next.
 *
 * Forward-only — empty down() since the rows are unrecoverable and weren't
 * referenced by any operator-visible feature.
 *
 * @see commit 583ea48 dev-5.0 — Pricing tab rewrite that removed the inputs
 * @see commit d61e9e9 dev-5.0 — Read-path fix that removed the last reader
 */
class DropUnusedMcPricingSettings extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mining_manager_settings')) {
            return;
        }

        $deleted = DB::table('mining_manager_settings')
            ->whereIn('key', [
                'pricing.manager_core_market',
                'pricing.manager_core_variant',
            ])
            ->delete();

        if ($deleted > 0) {
            Log::info("[Mining Manager] Dropped {$deleted} unused pricing.manager_core_market/variant settings rows. Source of truth for market lives in MC's manager_core_pricing_preferences table.");
        }
    }

    public function down(): void
    {
        // Forward-only. The rows weren't used by anything, so there's
        // nothing meaningful to restore.
    }
}
