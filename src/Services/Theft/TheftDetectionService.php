<?php

namespace MiningManager\Services\Theft;

use MiningManager\Models\TheftIncident;
use MiningManager\Models\MiningTax;
use MiningManager\Services\Character\CharacterInfoService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Theft Detection Service
 *
 * Detects moon ore theft by identifying characters who:
 * 1. Mined moon ore from corporation_industry_mining_observer_data
 * 2. Have unpaid/overdue taxes
 * 3. Grace period has passed
 * 4. Are NOT registered in SeAT OR are not members of moon owner corporation
 */
class TheftDetectionService
{
    protected $characterService;
    protected $settingsService;

    /**
     * Moon ore group IDs in EVE Online
     */
    const MOON_ORE_GROUPS = [1884, 1920, 1921, 1922, 1923];

    public function __construct(
        CharacterInfoService $characterService,
        SettingsManagerService $settingsService
    ) {
        $this->characterService = $characterService;
        $this->settingsService = $settingsService;
    }

    /**
     * Detect thefts within a date range
     *
     * @param Carbon|string $startDate
     * @param Carbon|string $endDate
     * @return array Statistics about detected incidents
     */
    public function detectThefts($startDate, $endDate): array
    {
        $startDate = Carbon::parse($startDate)->startOfDay();
        $endDate = Carbon::parse($endDate)->endOfDay();

        Log::info('TheftDetectionService: Starting theft detection', [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString()
        ]);

        // Get moon owner corporation ID from settings
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');

        if (!$moonOwnerCorpId) {
            Log::warning('TheftDetectionService: No moon owner corporation configured');
            return [
                'incidents_detected' => 0,
                'new_incidents' => 0,
                'updated_incidents' => 0,
                'error' => 'No moon owner corporation configured in settings'
            ];
        }

        // Get all moon ore type IDs
        $moonOreTypeIds = $this->getMoonOreTypeIds();

        if (empty($moonOreTypeIds)) {
            Log::warning('TheftDetectionService: No moon ore type IDs found');
            return [
                'incidents_detected' => 0,
                'new_incidents' => 0,
                'updated_incidents' => 0,
                'error' => 'No moon ore type IDs found in database'
            ];
        }

        Log::info('TheftDetectionService: Found moon ore type IDs', [
            'count' => count($moonOreTypeIds)
        ]);

        // Query corporation_industry_mining_observer_data for moon mining
        $miningData = DB::table('corporation_industry_mining_observer_data')
            ->whereBetween('last_updated', [$startDate, $endDate])
            ->whereIn('type_id', $moonOreTypeIds)
            ->select('character_id', 'type_id', 'quantity', 'last_updated')
            ->get();

        Log::info('TheftDetectionService: Found mining records', [
            'count' => $miningData->count()
        ]);

        // Group by character
        $characterMining = $miningData->groupBy('character_id');

        $newIncidents = 0;
        $updatedIncidents = 0;
        $totalIncidents = 0;

        foreach ($characterMining as $characterId => $records) {
            try {
                $result = $this->analyzeCharacterMining($characterId, $startDate, $endDate, $moonOwnerCorpId);

                if ($result['incident_created']) {
                    $newIncidents++;
                    $totalIncidents++;
                } elseif ($result['incident_updated']) {
                    $updatedIncidents++;
                    $totalIncidents++;
                }
            } catch (\Exception $e) {
                Log::error('TheftDetectionService: Failed to analyze character', [
                    'character_id' => $characterId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('TheftDetectionService: Completed theft detection', [
            'total_incidents' => $totalIncidents,
            'new_incidents' => $newIncidents,
            'updated_incidents' => $updatedIncidents
        ]);

        return [
            'incidents_detected' => $totalIncidents,
            'new_incidents' => $newIncidents,
            'updated_incidents' => $updatedIncidents
        ];
    }

    /**
     * Analyze mining activity for a specific character
     *
     * @param int $characterId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int $moonOwnerCorpId
     * @return array ['incident_created' => bool, 'incident_updated' => bool, 'incident' => TheftIncident|null]
     */
    public function analyzeCharacterMining(int $characterId, Carbon $startDate, Carbon $endDate, int $moonOwnerCorpId): array
    {
        // Get character information
        $characterInfo = $this->characterService->getCharacterInfo($characterId);

        // Check if character is external miner (not registered OR not corp member)
        $isExternal = $this->isExternalMiner($characterId, $moonOwnerCorpId, $characterInfo);

        // If character is a corp member, they're not a thief
        if (!$isExternal) {
            return ['incident_created' => false, 'incident_updated' => false, 'incident' => null];
        }

        // Check for unpaid/overdue taxes
        $unpaidTaxes = MiningTax::where('character_id', $characterId)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->where(function($query) use ($startDate, $endDate) {
                $query->whereBetween('month', [$startDate->copy()->startOfMonth(), $endDate->copy()->endOfMonth()])
                      ->orWhere('month', '<', $startDate->copy()->startOfMonth());
            })
            ->get();

        // Check if any taxes are overdue (grace period passed)
        $overdueTaxes = $unpaidTaxes->filter(function($tax) {
            return $tax->isOverdue();
        });

        if ($overdueTaxes->isEmpty()) {
            // No overdue taxes, not a theft incident
            return ['incident_created' => false, 'incident_updated' => false, 'incident' => null];
        }

        // Get mining data for this character
        $moonOreTypeIds = $this->getMoonOreTypeIds();

        $miningRecords = DB::table('corporation_industry_mining_observer_data')
            ->where('character_id', $characterId)
            ->whereBetween('last_updated', [$startDate, $endDate])
            ->whereIn('type_id', $moonOreTypeIds)
            ->get();

        if ($miningRecords->isEmpty()) {
            return ['incident_created' => false, 'incident_updated' => false, 'incident' => null];
        }

        // Calculate total ore value and quantity
        $totalQuantity = $miningRecords->sum('quantity');
        $oreValue = $this->calculateOreValue($miningRecords);

        // Calculate total tax owed
        $totalTaxOwed = $overdueTaxes->sum(function($tax) {
            return $tax->getRemainingBalance();
        });

        // Determine severity
        $severity = $this->calculateSeverity($totalTaxOwed, $oreValue);

        // Check if incident already exists for this character and period
        $existingIncident = TheftIncident::where('character_id', $characterId)
            ->where('mining_date_from', $startDate->toDateString())
            ->where('mining_date_to', $endDate->toDateString())
            ->first();

        if ($existingIncident) {
            // Update existing incident
            $updated = $this->updateIncident($existingIncident, [
                'ore_value' => $oreValue,
                'tax_owed' => $totalTaxOwed,
                'quantity_mined' => $totalQuantity,
                'severity' => $severity,
            ]);

            return ['incident_created' => false, 'incident_updated' => $updated, 'incident' => $existingIncident];
        }

        // Create new incident
        $incidentData = [
            'character_id' => $characterId,
            'character_name' => $characterInfo['name'],
            'corporation_id' => $characterInfo['corporation_id'],
            'mining_tax_id' => $overdueTaxes->first()->id,
            'incident_date' => Carbon::now(),
            'mining_date_from' => $startDate->toDateString(),
            'mining_date_to' => $endDate->toDateString(),
            'ore_value' => $oreValue,
            'tax_owed' => $totalTaxOwed,
            'quantity_mined' => $totalQuantity,
            'status' => 'detected',
            'severity' => $severity,
            'on_theft_list' => true,
        ];

        $incident = $this->createIncident($incidentData);

        Log::info('TheftDetectionService: Created theft incident', [
            'incident_id' => $incident->id,
            'character_id' => $characterId,
            'severity' => $severity,
            'tax_owed' => $totalTaxOwed
        ]);

        // B1b: announce on the cross-plugin event bus so Discord Pings,
        // HR Manager, and other subscribers can react to theft incidents.
        // Topics handles publisher attribution + idempotency_key composition
        // (theft_detected:{incident_id}). Standalone-safe via class_exists.
        if (class_exists(\ManagerCore\Topics::class)) {
            \ManagerCore\Topics::publish('mining.theft_detected', [
                'incident_id'    => $incident->id,
                'character_id'   => $characterId,
                'severity'       => $severity,
                'tax_owed'       => $totalTaxOwed,
                'ore_value'      => $oreValue,
                'quantity_mined' => $totalQuantity,
                'period_start'   => $startDate->toDateString(),
                'period_end'     => $endDate->toDateString(),
                'corporation_id' => null, // visibility scoping
                'role_id'        => null,
            ]);
        }

        return ['incident_created' => true, 'incident_updated' => false, 'incident' => $incident];
    }

    /**
     * Calculate severity based on tax owed and ore value
     *
     * @param float $taxOwed
     * @param float $oreValue
     * @return string (low, medium, high, critical)
     */
    public function calculateSeverity(float $taxOwed, float $oreValue): string
    {
        // Use the higher of tax owed or ore value for severity calculation
        $amount = max($taxOwed, $oreValue);

        if ($amount >= 500000000) { // 500M ISK or more
            return 'critical';
        } elseif ($amount >= 200000000) { // 200M ISK or more
            return 'high';
        } elseif ($amount >= 50000000) { // 50M ISK or more
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Create a new theft incident
     *
     * @param array $data
     * @return TheftIncident
     */
    public function createIncident(array $data): TheftIncident
    {
        return DB::transaction(function() use ($data) {
            return TheftIncident::create($data);
        });
    }

    /**
     * Update an existing theft incident
     *
     * @param TheftIncident $incident
     * @param array $data
     * @return bool
     */
    protected function updateIncident(TheftIncident $incident, array $data): bool
    {
        return DB::transaction(function() use ($incident, $data) {
            return $incident->update($data);
        });
    }

    /**
     * Get all unresolved theft incidents
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUnresolvedIncidents()
    {
        return TheftIncident::unresolved()
            ->with(['character', 'corporation', 'miningTax'])
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
            ->orderBy('incident_date', 'desc')
            ->get();
    }

    /**
     * Check if a character is an external miner (guest or unregistered)
     *
     * @param int $characterId
     * @param int $moonOwnerCorpId
     * @param array|null $characterInfo
     * @return bool
     */
    public function isExternalMiner(int $characterId, int $moonOwnerCorpId, ?array $characterInfo = null): bool
    {
        if (!$characterInfo) {
            $characterInfo = $this->characterService->getCharacterInfo($characterId);
        }

        // If character is not registered in SeAT, they're external
        if (!$characterInfo['is_registered']) {
            return true;
        }

        // If character is not a member of any configured corporation, they're external
        // Uses all configured corps (not just moon owner) so holding corp setups work
        $homeCorporationIds = $this->settingsService->getHomeCorporationIds();
        if (!empty($homeCorporationIds)) {
            return !in_array((int) $characterInfo['corporation_id'], $homeCorporationIds, true);
        }

        // Fallback: compare against moon owner corporation
        if ($characterInfo['corporation_id'] != $moonOwnerCorpId) {
            return true;
        }

        return false;
    }

    /**
     * Get all moon ore type IDs from the database
     *
     * @return array
     */
    protected function getMoonOreTypeIds(): array
    {
        try {
            // Query invTypes table for moon ore group IDs
            $typeIds = DB::table('invTypes')
                ->whereIn('groupID', self::MOON_ORE_GROUPS)
                ->pluck('typeID')
                ->toArray();

            return $typeIds;
        } catch (\Exception $e) {
            Log::error('TheftDetectionService: Failed to get moon ore type IDs', [
                'error' => $e->getMessage()
            ]);

            // Fallback to TypeIdRegistry if available
            try {
                $allMoonOres = TypeIdRegistry::getAllMoonOres();
                $compressedMoonOres = TypeIdRegistry::getAllCompressedMoonOres();
                return array_merge($allMoonOres, $compressedMoonOres);
            } catch (\Exception $e2) {
                Log::error('TheftDetectionService: TypeIdRegistry also failed', [
                    'error' => $e2->getMessage()
                ]);
                return [];
            }
        }
    }

    /**
     * Calculate the total value of mined ore
     *
     * @param \Illuminate\Support\Collection $miningRecords
     * @return float
     */
    protected function calculateOreValue($miningRecords): float
    {
        $totalValue = 0;

        try {
            // Get price data from database
            foreach ($miningRecords as $record) {
                $price = $this->getOrePrice($record->type_id);
                $value = $record->quantity * $price;
                $totalValue += $value;
            }
        } catch (\Exception $e) {
            Log::warning('TheftDetectionService: Failed to calculate ore value', [
                'error' => $e->getMessage()
            ]);
        }

        return $totalValue;
    }

    /**
     * Get the price for an ore type
     *
     * @param int $typeId
     * @return float
     */
    protected function getOrePrice(int $typeId): float
    {
        try {
            // Get from our price cache if available (uses cached_at, not updated_at)
            $priceType = $this->settingsService->getSetting('pricing.price_type', 'sell');

            $priceCache = DB::table('mining_price_cache')
                ->where('type_id', $typeId)
                ->where('cached_at', '>', Carbon::now()->subDays(1))
                ->first();

            if ($priceCache) {
                // Use correct column names: sell_price, buy_price, average_price
                return match ($priceType) {
                    'buy' => (float) ($priceCache->buy_price ?? 0),
                    'average' => (float) ($priceCache->average_price ?? 0),
                    default => (float) ($priceCache->sell_price ?? 0),
                };
            }

            // Fallback to SeAT's market_prices table (has adjusted_price, average_price)
            $marketData = DB::table('market_prices')
                ->where('type_id', $typeId)
                ->first();

            if ($marketData) {
                return (float) ($marketData->adjusted_price ?? $marketData->average_price ?? 0);
            }

            return 0;
        } catch (\Exception $e) {
            Log::warning('TheftDetectionService: Failed to get ore price', [
                'type_id' => $typeId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Detect active thefts (characters continuing to mine with unpaid taxes)
     *
     * @return array ['active_thefts' => Collection, 'count' => int, 'total_new_value' => float]
     */
    public function detectActiveThefts(): array
    {
        Log::info('TheftDetectionService: Starting active theft detection');

        // Get all unresolved theft incidents
        $unresolvedIncidents = TheftIncident::whereIn('status', ['detected', 'investigating'])
            ->get();

        if ($unresolvedIncidents->isEmpty()) {
            Log::info('TheftDetectionService: No unresolved incidents to check');
            return [
                'active_thefts' => collect([]),
                'count' => 0,
                'total_new_value' => 0
            ];
        }

        $moonOreTypeIds = $this->getMoonOreTypeIds();
        if (empty($moonOreTypeIds)) {
            Log::warning('TheftDetectionService: No moon ore type IDs found for active theft detection');
            return [
                'active_thefts' => collect([]),
                'count' => 0,
                'total_new_value' => 0
            ];
        }

        $activeThefts = collect([]);
        $totalNewValue = 0;

        foreach ($unresolvedIncidents as $incident) {
            try {
                // Query for new mining activity since last check (not since creation, to avoid double-counting)
                $checkFrom = $incident->last_activity_at ?? $incident->created_at;
                $newMiningRecords = DB::table('corporation_industry_mining_observer_data')
                    ->where('character_id', $incident->character_id)
                    ->where('last_updated', '>', $checkFrom)
                    ->whereIn('type_id', $moonOreTypeIds)
                    ->get();

                if ($newMiningRecords->isEmpty()) {
                    continue;
                }

                // Calculate value of new mining
                $newMiningValue = $this->calculateOreValue($newMiningRecords);

                if ($newMiningValue > 0) {
                    // Mark as active theft and update incident
                    $incident->markAsActiveTheft($newMiningValue);

                    $activeThefts->push([
                        'incident' => $incident,
                        'new_value' => $newMiningValue,
                        'new_quantity' => $newMiningRecords->sum('quantity')
                    ]);

                    $totalNewValue += $newMiningValue;

                    Log::info('TheftDetectionService: Active theft detected', [
                        'incident_id' => $incident->id,
                        'character_id' => $incident->character_id,
                        'character_name' => $incident->character_name,
                        'activity_count' => $incident->activity_count,
                        'new_value' => $newMiningValue,
                        'total_value' => $incident->ore_value
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('TheftDetectionService: Failed to check incident for active theft', [
                    'incident_id' => $incident->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('TheftDetectionService: Completed active theft detection', [
            'active_thefts_found' => $activeThefts->count(),
            'total_new_value' => $totalNewValue
        ]);

        return [
            'active_thefts' => $activeThefts,
            'count' => $activeThefts->count(),
            'total_new_value' => $totalNewValue
        ];
    }

    /**
     * Check if characters on theft list have paid their taxes
     * Remove them from active monitoring if taxes are paid
     *
     * @return \Illuminate\Support\Collection
     */
    public function checkForPaidTaxes()
    {
        $paidIncidents = collect();

        // Get all incidents currently on theft list
        $activeIncidents = TheftIncident::onTheftList()->get();

        foreach ($activeIncidents as $incident) {
            // Check if character has paid taxes for the relevant period
            $unpaidTaxes = MiningTax::where('character_id', $incident->character_id)
                ->where('month', '>=', $incident->mining_date_from)
                ->where('month', '<=', $incident->mining_date_to)
                ->whereIn('status', ['unpaid', 'overdue'])
                ->exists();

            if (!$unpaidTaxes) {
                // All taxes paid - remove from theft list
                $incident->removeFromList();
                $paidIncidents->push($incident);

                Log::info('Character removed from theft list (taxes paid)', [
                    'character_id' => $incident->character_id,
                    'character_name' => $incident->character_name,
                    'incident_id' => $incident->id,
                ]);
            }
        }

        return $paidIncidents;
    }

    /**
     * Monitor characters on theft list for continued mining
     * Only checks characters already flagged - much faster than full scan
     *
     * @param int $hours - Check last N hours
     * @return \Illuminate\Support\Collection - Active thefts detected
     */
    public function monitorActiveThefts(int $hours = 6)
    {
        $activeThefts = collect();
        $checkFrom = now()->subHours($hours);

        // Get characters on theft list
        $incidents = TheftIncident::onTheftList()->get();

        if ($incidents->isEmpty()) {
            return $activeThefts;
        }

        $characterIds = $incidents->pluck('character_id')->toArray();

        // Get moon ore type IDs
        $moonOreTypeIds = $this->getMoonOreTypeIds();

        if (empty($moonOreTypeIds)) {
            Log::warning('TheftDetectionService: No moon ore type IDs found for active theft monitoring');
            return $activeThefts;
        }

        // Query ONLY these characters in mining observer data
        // Use sell_price from mining_price_cache (cached_at for freshness check)
        $recentMining = DB::table('corporation_industry_mining_observer_data as mining')
            ->select(
                'mining.character_id',
                DB::raw('SUM(mining.quantity * COALESCE(prices.sell_price, 0)) as total_value'),
                DB::raw('SUM(mining.quantity) as total_quantity'),
                DB::raw('MAX(mining.last_updated) as last_activity')
            )
            ->leftJoin('invTypes as types', 'mining.type_id', '=', 'types.typeID')
            ->leftJoin('mining_price_cache as prices', function($join) {
                $join->on('mining.type_id', '=', 'prices.type_id')
                     ->where('prices.cached_at', '>', DB::raw('NOW() - INTERVAL 7 DAY'));
            })
            ->whereIn('mining.character_id', $characterIds)
            ->where('mining.last_updated', '>=', $checkFrom)
            ->whereIn('types.groupID', self::MOON_ORE_GROUPS)
            ->groupBy('mining.character_id')
            ->get();

        // Check each incident for new mining
        foreach ($incidents as $incident) {
            $mining = $recentMining->firstWhere('character_id', $incident->character_id);

            if ($mining && $mining->total_value > 0) {
                // Character is actively mining with unpaid taxes!
                $incident->markAsActiveTheft($mining->total_value);
                $activeThefts->push([
                    'incident' => $incident,
                    'new_value' => $mining->total_value,
                    'quantity' => $mining->total_quantity,
                    'last_activity' => $mining->last_activity,
                ]);

                Log::warning('ACTIVE THEFT IN PROGRESS', [
                    'character_id' => $incident->character_id,
                    'character_name' => $incident->character_name,
                    'new_value' => $mining->total_value,
                    'total_value' => $incident->ore_value,
                    'activity_count' => $incident->activity_count,
                ]);
            }
        }

        return $activeThefts;
    }

    /**
     * Detect tax delinquents — characters with 2+ overdue tax bills.
     * These are flagged on the theft list regardless of whether they are
     * corp members or external miners.
     *
     * @param int $minOverdue Minimum number of overdue bills to trigger (default 2)
     * @return array Statistics about delinquent detection
     */
    public function detectTaxDelinquents(int $minOverdue = 2): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        // Find characters with N+ overdue tax records
        $delinquents = MiningTax::where('status', 'overdue')
            ->select('character_id', DB::raw('COUNT(*) as overdue_count'), DB::raw('SUM(amount_owed) as total_owed'))
            ->groupBy('character_id')
            ->having('overdue_count', '>=', $minOverdue)
            ->get();

        foreach ($delinquents as $delinquent) {
            try {
                // Get character info
                $characterInfo = $this->characterService->getCharacterInfo($delinquent->character_id);
                $characterName = $characterInfo['name'] ?? 'Unknown';
                $corporationId = $characterInfo['corporation_id'] ?? null;

                // Get the overdue tax records for date range
                $overdueTaxes = MiningTax::where('character_id', $delinquent->character_id)
                    ->where('status', 'overdue')
                    ->orderBy('period_start')
                    ->get();

                $earliestPeriod = $overdueTaxes->min('period_start') ?? $overdueTaxes->min('month');
                $latestPeriod = $overdueTaxes->max('period_end') ?? $overdueTaxes->max('month');

                // Check if an unresolved delinquency incident already exists
                $existing = TheftIncident::where('character_id', $delinquent->character_id)
                    ->where('notes', 'LIKE', '%Tax delinquent%')
                    ->unresolved()
                    ->first();

                $severity = $this->calculateSeverity($delinquent->total_owed, 0);

                if ($existing) {
                    // Update existing incident with latest totals
                    $this->updateIncident($existing, [
                        'tax_owed' => $delinquent->total_owed,
                        'severity' => $severity,
                        'mining_date_to' => $latestPeriod,
                        'notes' => "Tax delinquent: {$delinquent->overdue_count} overdue bills totalling " .
                                   number_format($delinquent->total_owed, 0) . " ISK",
                    ]);
                    $updated++;
                } else {
                    // Check if there's a removed_paid incident we should reactivate
                    $removedIncident = TheftIncident::where('character_id', $delinquent->character_id)
                        ->where('notes', 'LIKE', '%Tax delinquent%')
                        ->where('status', 'removed_paid')
                        ->first();

                    if ($removedIncident) {
                        $removedIncident->addToList();
                        $this->updateIncident($removedIncident, [
                            'tax_owed' => $delinquent->total_owed,
                            'severity' => $severity,
                            'mining_date_to' => $latestPeriod,
                            'notes' => "Tax delinquent: {$delinquent->overdue_count} overdue bills totalling " .
                                       number_format($delinquent->total_owed, 0) . " ISK",
                        ]);
                        $updated++;
                    } else {
                        // Create new incident
                        $this->createIncident([
                            'character_id' => $delinquent->character_id,
                            'character_name' => $characterName,
                            'corporation_id' => $corporationId,
                            'mining_tax_id' => $overdueTaxes->first()->id,
                            'incident_date' => Carbon::now(),
                            'mining_date_from' => $earliestPeriod,
                            'mining_date_to' => $latestPeriod,
                            'ore_value' => 0,
                            'tax_owed' => $delinquent->total_owed,
                            'quantity_mined' => 0,
                            'status' => 'detected',
                            'severity' => $severity,
                            'on_theft_list' => true,
                            'notes' => "Tax delinquent: {$delinquent->overdue_count} overdue bills totalling " .
                                       number_format($delinquent->total_owed, 0) . " ISK",
                        ]);
                        $created++;
                    }
                }

                Log::info("TheftDetectionService: Tax delinquent flagged", [
                    'character_id' => $delinquent->character_id,
                    'character_name' => $characterName,
                    'overdue_count' => $delinquent->overdue_count,
                    'total_owed' => $delinquent->total_owed,
                ]);
            } catch (\Exception $e) {
                Log::error("TheftDetectionService: Failed to process delinquent {$delinquent->character_id}", [
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        return [
            'total_delinquents' => $delinquents->count(),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * Get theft statistics for dashboard
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $unresolvedIncidents = TheftIncident::unresolved()->get();

        return [
            'total_incidents' => TheftIncident::count(),
            'unresolved_incidents' => $unresolvedIncidents->count(),
            'critical_incidents' => $unresolvedIncidents->where('severity', 'critical')->count(),
            'total_value_at_risk' => $unresolvedIncidents->sum('tax_owed'),
            'active_thefts_count' => TheftIncident::activeThefts()->count(),
            'incidents_by_severity' => [
                'low' => $unresolvedIncidents->where('severity', 'low')->count(),
                'medium' => $unresolvedIncidents->where('severity', 'medium')->count(),
                'high' => $unresolvedIncidents->where('severity', 'high')->count(),
                'critical' => $unresolvedIncidents->where('severity', 'critical')->count(),
            ],
            'incidents_by_status' => [
                'detected' => TheftIncident::byStatus('detected')->count(),
                'investigating' => TheftIncident::byStatus('investigating')->count(),
                'resolved' => TheftIncident::byStatus('resolved')->count(),
                'false_alarm' => TheftIncident::byStatus('false_alarm')->count(),
            ],
        ];
    }
}
