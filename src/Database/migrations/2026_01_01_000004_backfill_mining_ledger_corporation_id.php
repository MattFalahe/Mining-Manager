<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill NULL corporation_id in mining_ledger from observer data.
 *
 * The mining_ledger.corporation_id represents the structure owner corporation.
 * Earlier versions of the process-ledger command did not populate this field.
 * This migration resolves it from the SeAT observer table via observer_id.
 */
class BackfillMiningLedgerCorporationId extends Migration
{
    public function up(): void
    {
        // Only run if both tables exist
        if (!Schema::hasTable('mining_ledger') || !Schema::hasTable('corporation_industry_mining_observers')) {
            Log::info('Backfill migration skipped: required tables do not exist');
            return;
        }

        $updated = DB::statement('
            UPDATE mining_ledger ml
            INNER JOIN corporation_industry_mining_observers o
                ON ml.observer_id = o.observer_id
            SET ml.corporation_id = o.corporation_id
            WHERE ml.corporation_id IS NULL
              AND ml.observer_id IS NOT NULL
        ');

        $remaining = DB::table('mining_ledger')
            ->whereNull('corporation_id')
            ->whereNotNull('observer_id')
            ->count();

        Log::info('Backfill mining_ledger corporation_id complete', [
            'remaining_null' => $remaining,
        ]);
    }

    public function down(): void
    {
        // Not reversible — we don't know which rows were originally NULL vs backfilled
    }
}
