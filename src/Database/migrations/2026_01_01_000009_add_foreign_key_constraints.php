<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add foreign key constraints to child tables.
 *
 * Audit finding: event_participants.event_id, mining_tax_codes.mining_tax_id,
 * and tax_invoices.mining_tax_id reference parent tables but have no FK constraints,
 * allowing orphaned rows if a parent record is deleted.
 *
 * All FKs use CASCADE on delete — when the parent record is removed,
 * associated child records are automatically cleaned up.
 */
return new class extends Migration
{
    public function up(): void
    {
        // event_participants.event_id → mining_events.id
        if (Schema::hasTable('event_participants') && Schema::hasTable('mining_events')) {
            $this->addForeignKeySafely('event_participants', 'event_id', 'mining_events', 'id');
        }

        // mining_tax_codes.mining_tax_id → mining_taxes.id
        if (Schema::hasTable('mining_tax_codes') && Schema::hasTable('mining_taxes')) {
            $this->addForeignKeySafely('mining_tax_codes', 'mining_tax_id', 'mining_taxes', 'id');
        }

        // tax_invoices.mining_tax_id → mining_taxes.id
        if (Schema::hasTable('tax_invoices') && Schema::hasTable('mining_taxes')) {
            $this->addForeignKeySafely('tax_invoices', 'mining_tax_id', 'mining_taxes', 'id');
        }
    }

    public function down(): void
    {
        $this->dropForeignKeySafely('event_participants', 'event_id');
        $this->dropForeignKeySafely('mining_tax_codes', 'mining_tax_id');
        $this->dropForeignKeySafely('tax_invoices', 'mining_tax_id');
    }

    /**
     * Add a foreign key constraint, catching errors if it already exists
     * or if there are orphaned rows that prevent creation.
     */
    private function addForeignKeySafely(string $table, string $column, string $referencedTable, string $referencedColumn): void
    {
        try {
            // First, clean up any orphaned rows that would violate the constraint
            \DB::table($table)
                ->whereNotIn($column, function ($query) use ($referencedTable, $referencedColumn) {
                    $query->select($referencedColumn)->from($referencedTable);
                })
                ->delete();

            Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $referencedColumn) {
                $blueprint->foreign($column)
                    ->references($referencedColumn)
                    ->on($referencedTable)
                    ->onDelete('cascade');
            });
        } catch (\Exception $e) {
            // FK may already exist or table engine may not support it
            \Log::warning("Could not add FK on {$table}.{$column}: " . $e->getMessage());
        }
    }

    /**
     * Drop a foreign key constraint safely.
     */
    private function dropForeignKeySafely(string $table, string $column): void
    {
        try {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $blueprint) use ($table, $column) {
                    $blueprint->dropForeign([$column]);
                });
            }
        } catch (\Exception $e) {
            // FK may not exist
        }
    }
};
