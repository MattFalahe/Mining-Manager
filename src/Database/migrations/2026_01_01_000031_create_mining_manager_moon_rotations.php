<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moon rotations: a repeating pattern of pulls, laid over the planner.
 *
 * A corp does not think in "the next pull for this refinery". It thinks in
 * weeks: these three moons on Monday, Wednesday and Friday, those four the
 * week after, then round again. The planner could only hold one pull at a
 * time per refinery in any practical sense, so building a month of that meant
 * one dialog and one page reload per pull.
 *
 * A rotation is the pattern (how many weeks long, which refinery on which
 * weekday at which time). Applying it writes ordinary plan rows, tagged with
 * the rotation they came from, because everything else on the page already
 * reads plan rows: coverage badges, reconciliation against real extractions,
 * the off-plan alert and the notifications. A virtual overlay would have to be
 * taught to all of them.
 *
 * Applying asks how many cycles to write rather than repeating for ever. A
 * pattern that runs out is visible in the calendar; one that generates itself
 * into next year quietly fills the table with intent nobody chose.
 *
 * Additive: two new tables and three nullable columns. Plans made before this
 * carry no rotation, which is exactly what they are.
 */
class CreateMiningManagerMoonRotations extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mining_manager_moon_rotations')) {
            Schema::create('mining_manager_moon_rotations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('corporation_id');
                $table->string('name', 100);

                // How many weeks the pattern covers before it repeats.
                $table->unsignedTinyInteger('weeks')->default(1);

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index('corporation_id', 'idx_mmrot_corp');
            });
        }

        if (!Schema::hasTable('mining_manager_moon_rotation_slots')) {
            Schema::create('mining_manager_moon_rotation_slots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rotation_id');

                // Week 1..weeks, and the ISO weekday (1 = Monday) inside it.
                $table->unsignedTinyInteger('week_number')->default(1);
                $table->unsignedTinyInteger('day_of_week')->default(1);

                // EVE time, which is what the in-game scheduler takes. The
                // planner has shown EVE time with a local confirmation since
                // it was built, and a rotation is no different.
                $table->time('time_of_day')->default('12:00:00');

                $table->unsignedBigInteger('structure_id');
                $table->unsignedBigInteger('moon_id')->nullable();
                $table->timestamps();

                $table->index('rotation_id', 'idx_mmrots_rotation');
                $table->index('structure_id', 'idx_mmrots_structure');
            });
        }

        if (Schema::hasTable('moon_extraction_plans')) {
            Schema::table('moon_extraction_plans', function (Blueprint $table) {
                if (!Schema::hasColumn('moon_extraction_plans', 'rotation_id')) {
                    $table->unsignedBigInteger('rotation_id')->nullable()->after('source');
                }
                if (!Schema::hasColumn('moon_extraction_plans', 'rotation_slot_id')) {
                    $table->unsignedBigInteger('rotation_slot_id')->nullable()->after('rotation_id');
                }
                // Which repeat of the pattern this pull belongs to, so "this
                // and every later one" can be answered without date maths.
                if (!Schema::hasColumn('moon_extraction_plans', 'rotation_cycle')) {
                    $table->unsignedSmallInteger('rotation_cycle')->nullable()->after('rotation_slot_id');
                }
            });

            if (Schema::hasColumn('moon_extraction_plans', 'rotation_id') && !$this->hasIndex('moon_extraction_plans', 'idx_mep_rotation')) {
                Schema::table('moon_extraction_plans', function (Blueprint $table) {
                    $table->index(['rotation_id', 'planned_arrival_time'], 'idx_mep_rotation');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('moon_extraction_plans')) {
            if ($this->hasIndex('moon_extraction_plans', 'idx_mep_rotation')) {
                Schema::table('moon_extraction_plans', function (Blueprint $table) {
                    $table->dropIndex('idx_mep_rotation');
                });
            }

            foreach (['rotation_cycle', 'rotation_slot_id', 'rotation_id'] as $column) {
                if (Schema::hasColumn('moon_extraction_plans', $column)) {
                    Schema::table('moon_extraction_plans', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        Schema::dropIfExists('mining_manager_moon_rotation_slots');
        Schema::dropIfExists('mining_manager_moon_rotations');
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
