<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing database indexes for frequently queried columns.
 *
 * Audit finding: mining_events and moon_extractions have columns used
 * in WHERE/ORDER BY clauses that lack indexes, causing full table scans.
 */
return new class extends Migration
{
    public function up(): void
    {
        // mining_events: status, start_time, and created_by are used in scopes and queries
        if (Schema::hasTable('mining_events')) {
            Schema::table('mining_events', function (Blueprint $table) {
                if (!$this->hasIndex('mining_events', 'mining_events_status_index')) {
                    $table->index('status');
                }
                if (!$this->hasIndex('mining_events', 'mining_events_start_time_index')) {
                    $table->index('start_time');
                }
                if (Schema::hasColumn('mining_events', 'created_by') && !$this->hasIndex('mining_events', 'mining_events_created_by_index')) {
                    $table->index('created_by');
                }
            });
        }

        // moon_extractions: status, chunk_arrival_time, natural_decay_time used in timeline queries
        if (Schema::hasTable('moon_extractions')) {
            Schema::table('moon_extractions', function (Blueprint $table) {
                if (!$this->hasIndex('moon_extractions', 'moon_extractions_status_index')) {
                    $table->index('status');
                }
                if (!$this->hasIndex('moon_extractions', 'moon_extractions_chunk_arrival_time_index')) {
                    $table->index('chunk_arrival_time');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mining_events')) {
            Schema::table('mining_events', function (Blueprint $table) {
                $table->dropIndex(['status']);
                $table->dropIndex(['start_time']);
                if (Schema::hasColumn('mining_events', 'created_by')) {
                    $table->dropIndex(['created_by']);
                }
            });
        }

        if (Schema::hasTable('moon_extractions')) {
            Schema::table('moon_extractions', function (Blueprint $table) {
                $table->dropIndex(['status']);
                $table->dropIndex(['chunk_arrival_time']);
            });
        }
    }

    /**
     * Check if an index exists on a table.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $indexes = Schema::getIndexes($table);
            foreach ($indexes as $index) {
                if ($index['name'] === $indexName) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Schema::getIndexes may not be available on older Laravel versions
            // In that case, let the index creation attempt and catch the error
        }
        return false;
    }
};
