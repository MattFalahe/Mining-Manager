<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddPeriodFieldsToMiningTaxes extends Migration
{
    public function up()
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->string('period_type', 20)->default('monthly')->after('month');
            $table->date('period_start')->nullable()->after('period_type');
            $table->date('period_end')->nullable()->after('period_start');
        });

        // Backfill existing records: monthly period = full calendar month
        DB::table('mining_taxes')->whereNull('period_start')->update([
            'period_type' => 'monthly',
            'period_start' => DB::raw('`month`'),
            'period_end' => DB::raw('LAST_DAY(`month`)'),
        ]);

        Schema::table('mining_taxes', function (Blueprint $table) {
            // Drop old unique constraint and index
            $table->dropUnique('mining_taxes_char_month_unique');
            $table->dropIndex('idx_mining_taxes_char_status_month');

            // New unique constraint: one tax per character per period start
            $table->unique(['character_id', 'period_start'], 'mining_taxes_char_period_unique');

            // New performance index
            $table->index(
                ['character_id', 'status', 'period_start'],
                'idx_mining_taxes_char_status_period'
            );

            // Index for period queries
            $table->index(['period_type', 'period_start', 'period_end'], 'idx_mining_taxes_period');
        });
    }

    public function down()
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->dropUnique('mining_taxes_char_period_unique');
            $table->dropIndex('idx_mining_taxes_char_status_period');
            $table->dropIndex('idx_mining_taxes_period');

            // Restore original constraints
            $table->unique(['character_id', 'month'], 'mining_taxes_char_month_unique');
            $table->index(
                ['character_id', 'status', 'month'],
                'idx_mining_taxes_char_status_month'
            );

            $table->dropColumn(['period_type', 'period_start', 'period_end']);
        });
    }
}
