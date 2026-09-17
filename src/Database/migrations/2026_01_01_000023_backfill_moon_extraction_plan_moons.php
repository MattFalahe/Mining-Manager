<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill in the moons the planner never managed to record.
 *
 * The planner resolved a refinery's moon by reading a moon_id column off
 * corporation_structures. SeAT has no such column there. Saving a planned pull
 * put that read in the SELECT list and threw a 1054, so it failed outright;
 * auto-fill reached for the same attribute on an already-loaded model, where
 * Eloquent hands back null for something it never selected rather than
 * complaining, so those plans were quietly stored with no moon at all.
 *
 * Nothing was lost: a refinery is anchored on exactly one moon and cannot move,
 * so the moon can be recovered from any extraction ever observed for that
 * structure. This walks the same three sources the resolver now uses and fills
 * the gaps, in plans and in the audit trail.
 *
 * Data-only and idempotent. Rows that already carry a moon are left alone, and
 * a refinery nothing has ever seen an extraction for stays null, which is a
 * legitimate state the column allows.
 */
class BackfillMoonExtractionPlanMoons extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('moon_extraction_plans')) {
            return;
        }

        $structureIds = DB::table('moon_extraction_plans')
            ->whereNull('moon_id')
            ->distinct()
            ->pluck('structure_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->all();

        if (empty($structureIds)) {
            return;
        }

        $moons = $this->resolveMoons($structureIds);

        foreach ($moons as $structureId => $moonId) {
            DB::table('moon_extraction_plans')
                ->where('structure_id', $structureId)
                ->whereNull('moon_id')
                ->update(['moon_id' => $moonId]);

            if (Schema::hasTable('moon_extraction_plan_audits')) {
                DB::table('moon_extraction_plan_audits')
                    ->where('structure_id', $structureId)
                    ->whereNull('moon_id')
                    ->update(['moon_id' => $moonId]);
            }
        }
    }

    /**
     * Nothing to reverse. Removing a moon that was correct would be a
     * regression, not a rollback.
     */
    public function down(): void
    {
        //
    }

    /**
     * structure_id => moon_id, from whichever source knows first.
     *
     * Ordered oldest arrival first so the last row written per structure is the
     * most recently observed one.
     *
     * @param  array<int,int>  $structureIds
     * @return array<int,int>
     */
    private function resolveMoons(array $structureIds): array
    {
        $sources = [
            ['table' => 'moon_extractions', 'order' => 'chunk_arrival_time'],
            ['table' => 'moon_extraction_history', 'order' => 'chunk_arrival_time'],
            ['table' => 'corporation_industry_mining_extractions', 'order' => 'chunk_arrival_time'],
        ];

        $resolved = [];
        $outstanding = $structureIds;

        foreach ($sources as $source) {
            if (empty($outstanding) || !Schema::hasTable($source['table'])) {
                continue;
            }

            $rows = DB::table($source['table'])
                ->whereIn('structure_id', $outstanding)
                ->whereNotNull('moon_id')
                ->orderBy($source['order'])
                ->pluck('moon_id', 'structure_id');

            foreach ($rows as $structureId => $moonId) {
                $resolved[(int) $structureId] = (int) $moonId;
            }

            $outstanding = array_values(array_filter(
                $outstanding,
                fn ($id) => !isset($resolved[$id])
            ));
        }

        return $resolved;
    }
}
