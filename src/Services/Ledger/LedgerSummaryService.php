<?php

namespace MiningManager\Services\Ledger;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningLedgerMonthlySummary;
use MiningManager\Models\MiningLedgerDailySummary;
use Seat\Eveapi\Models\Sde\SolarSystem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerSummaryService
{
    /**
     * Generate monthly summary for a specific character and month
     *
     * @param int $characterId
     * @param string $month YYYY-MM format
     * @return MiningLedgerMonthlySummary
     */
    public function generateMonthlySummary(int $characterId, string $month): MiningLedgerMonthlySummary
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        // Aggregate data from mining_ledger
        $summary = MiningLedger::where('character_id', $characterId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->selectRaw('
                character_id,
                corporation_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(tax_amount) as total_tax,
                SUM(CASE WHEN ore_type = "moon_ore" THEN total_value ELSE 0 END) as moon_ore_value,
                SUM(CASE WHEN ore_type = "ore" THEN total_value ELSE 0 END) as regular_ore_value,
                SUM(CASE WHEN ore_type = "ice" THEN total_value ELSE 0 END) as ice_value,
                SUM(CASE WHEN ore_type = "gas" THEN total_value ELSE 0 END) as gas_value
            ')
            ->groupBy('character_id', 'corporation_id')
            ->first();

        if (!$summary) {
            // No mining data for this character/month
            return MiningLedgerMonthlySummary::create([
                'character_id' => $characterId,
                'month' => $monthDate,
                'corporation_id' => null,
                'total_quantity' => 0,
                'total_value' => 0,
                'total_tax' => 0,
                'moon_ore_value' => 0,
                'regular_ore_value' => 0,
                'ice_value' => 0,
                'gas_value' => 0,
                'ore_breakdown' => [],
                'is_finalized' => true,
                'finalized_at' => now(),
            ]);
        }

        // Get ore breakdown
        $oreBreakdown = MiningLedger::where('character_id', $characterId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->selectRaw('ore_type, type_id, SUM(quantity) as total_quantity, SUM(total_value) as total_value')
            ->groupBy('ore_type', 'type_id')
            ->get()
            ->toArray();

        // Create or update monthly summary
        return MiningLedgerMonthlySummary::updateOrCreate(
            [
                'character_id' => $characterId,
                'month' => $monthDate,
            ],
            [
                'corporation_id' => $summary->corporation_id,
                'total_quantity' => $summary->total_quantity,
                'total_value' => $summary->total_value,
                'total_tax' => $summary->total_tax,
                'moon_ore_value' => $summary->moon_ore_value,
                'regular_ore_value' => $summary->regular_ore_value,
                'ice_value' => $summary->ice_value,
                'gas_value' => $summary->gas_value,
                'ore_breakdown' => $oreBreakdown,
                'is_finalized' => true,
                'finalized_at' => now(),
            ]
        );
    }

    /**
     * Generate daily summary for a specific character and date
     *
     * @param int $characterId
     * @param string $date YYYY-MM-DD format
     * @return MiningLedgerDailySummary
     */
    public function generateDailySummary(int $characterId, string $date): MiningLedgerDailySummary
    {
        $dateCarbon = Carbon::parse($date);

        // Aggregate data from mining_ledger
        $summary = MiningLedger::where('character_id', $characterId)
            ->whereDate('date', $dateCarbon)
            ->selectRaw('
                character_id,
                corporation_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(tax_amount) as total_tax,
                SUM(CASE WHEN ore_type = "moon_ore" THEN total_value ELSE 0 END) as moon_ore_value,
                SUM(CASE WHEN ore_type = "ore" THEN total_value ELSE 0 END) as regular_ore_value,
                SUM(CASE WHEN ore_type = "ice" THEN total_value ELSE 0 END) as ice_value,
                SUM(CASE WHEN ore_type = "gas" THEN total_value ELSE 0 END) as gas_value
            ')
            ->groupBy('character_id', 'corporation_id')
            ->first();

        if (!$summary) {
            // No mining data for this character/date
            return MiningLedgerDailySummary::create([
                'character_id' => $characterId,
                'date' => $dateCarbon,
                'corporation_id' => null,
                'total_quantity' => 0,
                'total_value' => 0,
                'total_tax' => 0,
                'moon_ore_value' => 0,
                'regular_ore_value' => 0,
                'ice_value' => 0,
                'gas_value' => 0,
                'ore_types' => [],
                'is_finalized' => true,
            ]);
        }

        // Get unique ore types for the day
        $oreTypes = MiningLedger::where('character_id', $characterId)
            ->whereDate('date', $dateCarbon)
            ->distinct()
            ->pluck('ore_type')
            ->toArray();

        // Create or update daily summary
        return MiningLedgerDailySummary::updateOrCreate(
            [
                'character_id' => $characterId,
                'date' => $dateCarbon,
            ],
            [
                'corporation_id' => $summary->corporation_id,
                'total_quantity' => $summary->total_quantity,
                'total_value' => $summary->total_value,
                'total_tax' => $summary->total_tax,
                'moon_ore_value' => $summary->moon_ore_value,
                'regular_ore_value' => $summary->regular_ore_value,
                'ice_value' => $summary->ice_value,
                'gas_value' => $summary->gas_value,
                'ore_types' => $oreTypes,
                'is_finalized' => true,
            ]
        );
    }

    /**
     * Finalize all summaries for a given month
     * Run this on the 2nd of each month for the previous month
     *
     * @param string $month YYYY-MM format
     * @return array Statistics about finalization
     */
    public function finalizeMonth(string $month): array
    {
        $monthDate = Carbon::parse($month)->startOfMonth();
        $stats = [
            'month' => $month,
            'monthly_summaries' => 0,
            'daily_summaries' => 0,
            'characters_processed' => 0,
            'errors' => [],
        ];

        try {
            // Get all unique characters who mined in this month
            $characters = MiningLedger::whereYear('date', $monthDate->year)
                ->whereMonth('date', $monthDate->month)
                ->distinct()
                ->pluck('character_id');

            $stats['characters_processed'] = $characters->count();

            foreach ($characters as $characterId) {
                try {
                    // Generate monthly summary
                    $this->generateMonthlySummary($characterId, $month);
                    $stats['monthly_summaries']++;

                    // Generate daily summaries for each day in the month
                    $start = $monthDate->copy()->startOfMonth();
                    $end = $monthDate->copy()->endOfMonth();

                    for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                        $this->generateDailySummary($characterId, $date->format('Y-m-d'));
                        $stats['daily_summaries']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors'][] = "Character {$characterId}: " . $e->getMessage();
                    Log::error("Failed to finalize summaries for character {$characterId}", [
                        'month' => $month,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info("Finalized summaries for month {$month}", $stats);
        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
            Log::error("Failed to finalize month {$month}", [
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Get monthly summaries with fallback to live calculation
     *
     * @param string $month YYYY-MM format
     * @param int|null $corporationId
     * @return \Illuminate\Support\Collection
     */
    public function getMonthlySummaries(string $month, ?int $corporationId = null)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();
        $isCurrentMonth = $monthDate->isSameMonth(now());

        // If it's the current month, always calculate live
        if ($isCurrentMonth) {
            return $this->calculateLiveMonthlySummaries($month, $corporationId);
        }

        // For past months, try to get finalized summaries first
        $summaries = MiningLedgerMonthlySummary::forMonth($monthDate)
            ->forCorporation($corporationId)
            ->finalized()
            ->with('character')
            ->get();

        // If no finalized summaries exist, calculate live
        if ($summaries->isEmpty()) {
            return $this->calculateLiveMonthlySummaries($month, $corporationId);
        }

        return $summaries;
    }

    /**
     * Calculate live monthly summaries from raw ledger data
     *
     * @param string $month YYYY-MM format
     * @param int|null $corporationId
     * @return \Illuminate\Support\Collection
     */
    protected function calculateLiveMonthlySummaries(string $month, ?int $corporationId = null)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        $query = MiningLedger::whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month);

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        return $query->selectRaw('
                character_id,
                corporation_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(tax_amount) as total_tax,
                SUM(CASE WHEN ore_type = "moon_ore" THEN total_value ELSE 0 END) as moon_ore_value,
                SUM(CASE WHEN ore_type = "ore" THEN total_value ELSE 0 END) as regular_ore_value,
                SUM(CASE WHEN ore_type = "ice" THEN total_value ELSE 0 END) as ice_value,
                SUM(CASE WHEN ore_type = "gas" THEN total_value ELSE 0 END) as gas_value
            ')
            ->groupBy('character_id', 'corporation_id')
            ->with('character')
            ->get();
    }

    /**
     * Get daily summaries for a character in a specific month
     *
     * @param int $characterId
     * @param string $month YYYY-MM format
     * @return \Illuminate\Support\Collection
     */
    public function getDailySummaries(int $characterId, string $month)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();
        $isCurrentMonth = $monthDate->isSameMonth(now());

        // If it's the current month, always calculate live
        if ($isCurrentMonth) {
            return $this->calculateLiveDailySummaries($characterId, $month);
        }

        // For past months, try to get finalized summaries first
        $summaries = MiningLedgerDailySummary::forCharacter($characterId)
            ->forMonth($monthDate)
            ->finalized()
            ->orderBy('date')
            ->get();

        // If no finalized summaries exist, calculate live
        if ($summaries->isEmpty()) {
            return $this->calculateLiveDailySummaries($characterId, $month);
        }

        return $summaries;
    }

    /**
     * Calculate live daily summaries from raw ledger data
     *
     * @param int $characterId
     * @param string $month YYYY-MM format
     * @return \Illuminate\Support\Collection
     */
    protected function calculateLiveDailySummaries(int $characterId, string $month)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        return MiningLedger::where('character_id', $characterId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->selectRaw('
                DATE(date) as date,
                character_id,
                corporation_id,
                SUM(quantity) as total_quantity,
                SUM(total_value) as total_value,
                SUM(tax_amount) as total_tax,
                SUM(CASE WHEN ore_type = "moon_ore" THEN total_value ELSE 0 END) as moon_ore_value,
                SUM(CASE WHEN ore_type = "ore" THEN total_value ELSE 0 END) as regular_ore_value,
                SUM(CASE WHEN ore_type = "ice" THEN total_value ELSE 0 END) as ice_value,
                SUM(CASE WHEN ore_type = "gas" THEN total_value ELSE 0 END) as gas_value
            ')
            ->groupBy(DB::raw('DATE(date)'), 'character_id', 'corporation_id')
            ->orderBy('date')
            ->get();
    }

    /**
     * Get enhanced monthly summaries with ore types and systems
     *
     * @param string $month YYYY-MM format
     * @param int|null $corporationId
     * @return \Illuminate\Support\Collection
     */
    public function getEnhancedMonthlySummaries(string $month, ?int $corporationId = null)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        // Get base summaries
        $summaries = $this->getMonthlySummaries($month, $corporationId);

        // Enhance each summary with ore types and systems
        $summaries = $summaries->map(function ($summary) use ($monthDate) {
            // Get ore types for this character
            $oreTypes = MiningLedger::where('character_id', $summary->character_id)
                ->whereYear('date', $monthDate->year)
                ->whereMonth('date', $monthDate->month)
                ->select('type_id', 'ore_type')
                ->distinct()
                ->get()
                ->pluck('type_id')
                ->toArray();

            // Get system information with names
            $systemData = MiningLedger::where('character_id', $summary->character_id)
                ->whereYear('date', $monthDate->year)
                ->whereMonth('date', $monthDate->month)
                ->select('solar_system_id', DB::raw('SUM(total_value) as system_value'))
                ->groupBy('solar_system_id')
                ->orderByDesc('system_value')
                ->get();

            // Manually load system names
            $systems = $systemData->map(function($item) {
                $item->solarSystem = SolarSystem::find($item->solar_system_id);
                return $item;
            });

            $summary->ore_type_ids = $oreTypes;
            $summary->systems = $systems;
            $summary->primary_system = $systems->first();
            $summary->system_count = $systems->count();

            return $summary;
        });

        return $summaries;
    }

    /**
     * Get character mining details in a specific system
     *
     * @param int $characterId
     * @param string $month YYYY-MM format
     * @param int $systemId
     * @return \Illuminate\Support\Collection
     */
    public function getCharacterSystemDetails(int $characterId, string $month, int $systemId)
    {
        $monthDate = Carbon::parse($month)->startOfMonth();

        return MiningLedger::with(['type', 'solarSystem'])
            ->where('character_id', $characterId)
            ->where('solar_system_id', $systemId)
            ->whereYear('date', $monthDate->year)
            ->whereMonth('date', $monthDate->month)
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * Group character summaries by main character
     * Uses SeAT v5 structure (refresh_tokens table for character-user mapping)
     *
     * @param \Illuminate\Support\Collection $summaries
     * @return \Illuminate\Support\Collection
     */
    public function groupByMainCharacter($summaries)
    {
        // Get all character IDs
        $characterIds = $summaries->pluck('character_id')->unique()->toArray();

        // Get user IDs for these characters from refresh_tokens table (SeAT v5.x standard)
        $characterUserMapping = DB::table('refresh_tokens')
            ->whereIn('character_id', $characterIds)
            ->select('character_id', 'user_id')
            ->get()
            ->pluck('user_id', 'character_id');

        // Get main character IDs for these users
        $userIds = $characterUserMapping->values()->unique()->toArray();
        $mainCharacterMapping = DB::table('users')
            ->whereIn('id', $userIds)
            ->pluck('main_character_id', 'id');

        // Build character-to-main-character mapping
        $characterToMain = [];
        foreach ($characterUserMapping as $charId => $userId) {
            $mainCharId = $mainCharacterMapping->get($userId, $charId);
            $characterToMain[$charId] = $mainCharId;
        }

        // Group characters by their main character
        $groupedByMain = [];
        foreach ($summaries as $summary) {
            $charId = $summary->character_id;
            $mainCharId = $characterToMain[$charId] ?? $charId;

            if (!isset($groupedByMain[$mainCharId])) {
                $groupedByMain[$mainCharId] = collect();
            }
            $groupedByMain[$mainCharId]->push($summary);
        }

        // Build final grouped summaries
        $grouped = collect();

        foreach ($groupedByMain as $mainCharId => $userSummaries) {
            // Find the main character's summary (or use first if main not in list)
            $mainSummary = $userSummaries->where('character_id', $mainCharId)->first()
                        ?? $userSummaries->first();

            if ($mainSummary) {
                // Get alt characters (all except the main)
                $mainSummary->alt_characters = $userSummaries->where('character_id', '!=', $mainSummary->character_id)->values();
                $mainSummary->alt_count = $mainSummary->alt_characters->count();

                // Aggregate totals from all characters (main + alts)
                $mainSummary->total_value = $userSummaries->sum('total_value');
                $mainSummary->total_tax = $userSummaries->sum('total_tax');
                $mainSummary->total_quantity = $userSummaries->sum('total_quantity');
                $mainSummary->moon_ore_value = $userSummaries->sum('moon_ore_value');
                $mainSummary->regular_ore_value = $userSummaries->sum('regular_ore_value');
                $mainSummary->ice_value = $userSummaries->sum('ice_value');
                $mainSummary->gas_value = $userSummaries->sum('gas_value');

                // Merge ore types from all characters
                if (isset($mainSummary->ore_type_ids)) {
                    $allOreTypes = $userSummaries->pluck('ore_type_ids')->flatten()->unique()->values();
                    $mainSummary->ore_type_ids = $allOreTypes->toArray();
                }

                // Merge and re-aggregate systems from all characters
                if (isset($mainSummary->systems)) {
                    $allSystems = $userSummaries->pluck('systems')->flatten();
                    // Merge and re-aggregate by system
                    $systemsById = $allSystems->groupBy('solar_system_id')->map(function($group) {
                        $first = $group->first();
                        $first->system_value = $group->sum('system_value');
                        return $first;
                    })->sortByDesc('system_value')->values();

                    $mainSummary->systems = $systemsById;
                    $mainSummary->primary_system = $systemsById->first();
                    $mainSummary->system_count = $systemsById->count();
                }

                $grouped->push($mainSummary);
            }
        }

        return $grouped->sortByDesc('total_value')->values();
    }
}
