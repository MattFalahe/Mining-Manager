<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMissingIndexesAndForeignKeys extends Migration
{
    /**
     * Add missing indexes for commonly queried columns and
     * a foreign key on mining_reports.schedule_id.
     */
    public function up(): void
    {
        // Add composite index on mining_ledger (observer_id, date) for observer-based joins
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->index(['observer_id', 'date'], 'idx_mining_ledger_observer_date');
        });

        // Add index on theft_incidents.resolved_by for resolved-by lookups
        Schema::table('theft_incidents', function (Blueprint $table) {
            $table->index('resolved_by', 'idx_theft_incidents_resolved_by');
        });

        // Add foreign key on mining_reports.schedule_id with SET NULL on delete
        if (Schema::hasColumn('mining_reports', 'schedule_id')) {
            Schema::table('mining_reports', function (Blueprint $table) {
                $table->index('schedule_id', 'idx_mining_reports_schedule_id');
                $table->foreign('schedule_id')
                    ->references('id')
                    ->on('report_schedules')
                    ->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('mining_reports', 'schedule_id')) {
            Schema::table('mining_reports', function (Blueprint $table) {
                $table->dropForeign(['schedule_id']);
                $table->dropIndex('idx_mining_reports_schedule_id');
            });
        }

        Schema::table('theft_incidents', function (Blueprint $table) {
            $table->dropIndex('idx_theft_incidents_resolved_by');
        });

        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropIndex('idx_mining_ledger_observer_date');
        });
    }
}
