<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what to an event, and when an event may still change.
 *
 *  mining_events.applies_at
 *    An event set up for days that have passed is held until then, so it can
 *    still be changed or deleted, and applies when the hold ends.
 *  mining_events.locked_at
 *    When the event's figures became final: a past event when it applied,
 *    any other when its final results went out. A locked event can no longer
 *    be edited.
 *
 *  webhook_configurations.notify_event_review
 *    The webhook takes Event review: events waiting for a director's
 *    approval, past events held before they apply, and what is decided about
 *    them. Off, like every other switch, until somebody ticks it.
 *
 *  mining_manager_event_history
 *    One row per thing done to an event: created, edited, approved, declined,
 *    cancelled, brought back, applied, deleted, by whom, with the changes and
 *    the reason given. No foreign key, so a deleted event stays on record.
 *
 * Additive only; existing events read as before. The history table is
 * created last, so the plugin treats its presence as "all of this is there".
 */
class AddMiningManagerEventGovernance extends Migration
{
    private const HISTORY = 'mining_manager_event_history';

    public function up(): void
    {
        if (Schema::hasTable('mining_events')) {
            foreach ([
                'applies_at' => fn (Blueprint $table) => $table->timestamp('applies_at')->nullable(),
                'locked_at' => fn (Blueprint $table) => $table->timestamp('locked_at')->nullable(),
            ] as $column => $add) {
                if (!Schema::hasColumn('mining_events', $column)) {
                    Schema::table('mining_events', $add);
                }
            }
        }

        if (Schema::hasTable('webhook_configurations') && !Schema::hasColumn('webhook_configurations', 'notify_event_review')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->boolean('notify_event_review')->default(false);
            });
        }

        if (!Schema::hasTable(self::HISTORY)) {
            Schema::create(self::HISTORY, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action', 30);
                $table->json('changes')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['event_id', 'created_at'], 'mm_event_history_event_idx');
            });
        } elseif (!$this->hasIndex(self::HISTORY, 'mm_event_history_event_idx')) {
            Schema::table(self::HISTORY, function (Blueprint $table) {
                $table->index(['event_id', 'created_at'], 'mm_event_history_event_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::HISTORY);

        if (Schema::hasTable('webhook_configurations') && Schema::hasColumn('webhook_configurations', 'notify_event_review')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->dropColumn('notify_event_review');
            });
        }

        if (Schema::hasTable('mining_events')) {
            foreach (['applies_at', 'locked_at'] as $column) {
                if (Schema::hasColumn('mining_events', $column)) {
                    Schema::table('mining_events', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }

    /**
     * Laravel has no portable "does this index exist", and an operator may be
     * part-migrated, so ask the schema itself.
     */
    protected function hasIndex(string $table, string $index): bool
    {
        try {
            return DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $index)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
