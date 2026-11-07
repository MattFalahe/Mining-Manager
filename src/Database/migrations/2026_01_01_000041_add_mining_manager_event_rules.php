<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an event counts, beyond its type, time, place and corporation.
 *
 *  mining_events.participation
 *    'everyone': everybody whose mining meets the event's rules gets its rate.
 *    'signed_up': only accounts that signed up, from when they signed up.
 *  mining_events.members_only
 *    For an event not tied to one corporation: only members of the configured
 *    corporations count, not everyone.
 *  mining_events.ore_categories
 *    Narrows the ore the event type covers (moon rarities, or what a special
 *    event counts). NULL counts all of it, as before.
 *  mining_events.results_sent_at
 *    When the final results went out, once the late refinery data was in.
 *
 *  event_mining_records.qualifies
 *    Whether the mining gets the event's rate. Mining by someone who has not
 *    signed up for a sign-up event is kept for the event page, but does not.
 *
 *  mining_manager_event_moons
 *    The refineries a moon event counts, when it is narrowed to some of them.
 *
 *  mining_manager_event_signups
 *    One row per account that signed up, and when.
 *
 * Existing events read exactly as before: open to everyone, all of their
 * type's ore, every record qualifying. Additive only; the sign-ups table is
 * created last, so the plugin treats its presence as "all of this is there".
 */
class AddMiningManagerEventRules extends Migration
{
    private const MOONS = 'mining_manager_event_moons';
    private const SIGNUPS = 'mining_manager_event_signups';

    public function up(): void
    {
        if (Schema::hasTable('mining_events')) {
            foreach ([
                'participation' => fn (Blueprint $table) => $table->string('participation', 20)->default('everyone'),
                'members_only' => fn (Blueprint $table) => $table->boolean('members_only')->default(false),
                'ore_categories' => fn (Blueprint $table) => $table->json('ore_categories')->nullable(),
                'results_sent_at' => fn (Blueprint $table) => $table->timestamp('results_sent_at')->nullable(),
            ] as $column => $add) {
                if (!Schema::hasColumn('mining_events', $column)) {
                    Schema::table('mining_events', $add);
                }
            }
        }

        if (Schema::hasTable('event_mining_records') && !Schema::hasColumn('event_mining_records', 'qualifies')) {
            Schema::table('event_mining_records', function (Blueprint $table) {
                $table->boolean('qualifies')->default(true);
            });
        }

        if (!Schema::hasTable(self::MOONS)) {
            Schema::create(self::MOONS, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('structure_id');
                $table->unsignedBigInteger('moon_id')->nullable();
                $table->timestamps();

                $table->unique(['event_id', 'structure_id'], 'mm_event_moons_unique');
                $table->index('structure_id', 'mm_event_moons_structure_idx');
                $table->foreign('event_id', 'mm_event_moons_event_fk')
                    ->references('id')->on('mining_events')->onDelete('cascade');
            });
        } elseif (!$this->hasIndex(self::MOONS, 'mm_event_moons_unique')) {
            Schema::table(self::MOONS, function (Blueprint $table) {
                $table->unique(['event_id', 'structure_id'], 'mm_event_moons_unique');
            });
        }

        if (!Schema::hasTable(self::SIGNUPS)) {
            Schema::create(self::SIGNUPS, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('character_id')->nullable();
                $table->timestamp('signed_up_at')->nullable();
                $table->timestamps();

                $table->unique(['event_id', 'user_id'], 'mm_event_signups_unique');
                $table->foreign('event_id', 'mm_event_signups_event_fk')
                    ->references('id')->on('mining_events')->onDelete('cascade');
            });
        } elseif (!$this->hasIndex(self::SIGNUPS, 'mm_event_signups_unique')) {
            Schema::table(self::SIGNUPS, function (Blueprint $table) {
                $table->unique(['event_id', 'user_id'], 'mm_event_signups_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::SIGNUPS);
        Schema::dropIfExists(self::MOONS);

        if (Schema::hasTable('event_mining_records') && Schema::hasColumn('event_mining_records', 'qualifies')) {
            Schema::table('event_mining_records', function (Blueprint $table) {
                $table->dropColumn('qualifies');
            });
        }

        if (Schema::hasTable('mining_events')) {
            foreach (['participation', 'members_only', 'ore_categories', 'results_sent_at'] as $column) {
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
