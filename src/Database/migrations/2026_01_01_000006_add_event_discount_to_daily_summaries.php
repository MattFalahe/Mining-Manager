<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add event_discount_total to mining_ledger_daily_summaries.
 *
 * This is the Phase 3 reporting column for event tax impact. It stores
 * the sum of ISK waived across all event-qualified mining on a given
 * (character, date). Previously this had to be computed by walking the
 * `ore_types` JSON blob and re-deriving (baseRate - effectiveRate) × value
 * per entry — expensive for any report over a multi-day range.
 *
 * Populated by LedgerSummaryService::generateDailySummary() as part of
 * Phase 2's per-row event attribution: each ore-type entry in the JSON
 * now carries event_qualified_value and event_discount_amount; this
 * column is the daily sum of those per-entry discounts.
 *
 * Zero (the default) means no event discount applied that day — either
 * no event was active for this character / no mining overlapped any
 * event window / every applied event had modifier 0.
 */
class AddEventDiscountToDailySummaries extends Migration
{
    public function up(): void
    {
        Schema::table('mining_ledger_daily_summaries', function (Blueprint $table) {
            $table->decimal('event_discount_total', 20, 2)->default(0)
                ->after('total_tax')
                ->comment('ISK waived due to active event tax modifiers this day (Phase 2 per-row attribution)');
        });
    }

    public function down(): void
    {
        Schema::table('mining_ledger_daily_summaries', function (Blueprint $table) {
            $table->dropColumn('event_discount_total');
        });
    }
}
