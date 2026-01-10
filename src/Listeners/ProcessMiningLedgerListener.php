<?php

namespace MiningManager\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Seat\Eveapi\Events\CharacterMiningUpdated;
use Seat\Eveapi\Models\Industry\CharacterMining;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\EventParticipant;
use MiningManager\Services\TypeIdRegistry;
use MiningManager\Services\Moon\MoonOreHelper;
use MiningManager\Services\Notification\WebhookService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMiningLedgerListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param CharacterMiningUpdated $event
     * @return void
     */
    public function handle(CharacterMiningUpdated $event)
    {
        // Check if mining ledger feature is enabled
        if (!config('mining-manager.features.mining_ledger', true)) {
            return;
        }

        $characterId = $event->character_id;

        Log::debug("Mining Manager: Processing mining ledger for character {$characterId}");

        // Acquire a per-character lock to prevent race conditions between
        // the listener (personal ESI) and command (observer data) processing simultaneously
        $lock = Cache::lock("mining-ledger-processing-{$characterId}", 120); // 2-minute timeout

        if (!$lock->get()) {
            Log::debug("Mining Manager: Character {$characterId} ledger is already being processed, skipping");
            return;
        }

        try {
            // Get mining ledger entries for this character
            // Process entries from the last 30 days
            $cutoffDate = Carbon::now()->subDays(30);

            $ledgerEntries = CharacterMining::where('character_id', $characterId)
                ->where('date', '>=', $cutoffDate)
                ->get();

            if ($ledgerEntries->isEmpty()) {
                Log::debug("Mining Manager: No mining ledger entries found for character {$characterId}");
                return;
            }

            $processed = 0;
            $updated = 0;

            foreach ($ledgerEntries as $entry) {
                // Check if already processed - matches DB unique constraint
                // (character_id, date, type_id, solar_system_id, observer_id)
                // Personal ESI mining has observer_id = NULL
                $existing = MiningLedger::where('character_id', $entry->character_id)
                    ->where('date', $entry->date)
                    ->where('type_id', $entry->type_id)
                    ->where('solar_system_id', $entry->solar_system_id)
                    ->whereNull('observer_id')
                    ->first();

                if ($existing) {
                    // Update if quantity changed - recalculate values with new quantity
                    if ($existing->quantity != $entry->quantity) {
                        $values = $this->calculateEntryValues($entry->type_id, $entry->quantity);
                        $existing->update([
                            'quantity' => $entry->quantity,
                            'unit_price' => $values['unit_price'],
                            'ore_value' => $values['ore_value'],
                            'mineral_value' => $values['mineral_value'],
                            'total_value' => $values['total_value'],
                            'processed_at' => Carbon::now(),
                        ]);
                        $updated++;

                        Log::debug("Mining Manager: Updated ledger entry for character {$characterId}, type {$entry->type_id}, quantity changed from {$existing->quantity} to {$entry->quantity}");
                    }
                } elseif ($this->hasObserverRecord($entry)) {
                    // Cross-source dedup: observer data already has this mining recorded
                    // Only applies to corp moon ore - personal ESI record is skipped
                    Log::debug("Mining Manager: Skipping character {$characterId}, type {$entry->type_id} - already recorded via observer data");
                } else {
                    // Classify ore type
                    $isMoonOre = TypeIdRegistry::isMoonOre($entry->type_id);
                    $isIce = TypeIdRegistry::isIce($entry->type_id);
                    $isGas = TypeIdRegistry::isGas($entry->type_id);
                    $isAbyssal = in_array($entry->type_id, TypeIdRegistry::ABYSSAL_ORES);
                    $oreCategory = $this->classifyOreCategory($entry->type_id);

                    // Calculate ore values using OreValuationService (daily session pricing)
                    $values = $this->calculateEntryValues($entry->type_id, $entry->quantity);

                    // Create new entry with calculated values
                    MiningLedger::create([
                        'character_id' => $entry->character_id,
                        'date' => $entry->date,
                        'type_id' => $entry->type_id,
                        'quantity' => $entry->quantity,
                        'solar_system_id' => $entry->solar_system_id,
                        'unit_price' => $values['unit_price'],
                        'ore_value' => $values['ore_value'],
                        'mineral_value' => $values['mineral_value'],
                        'total_value' => $values['total_value'],
                        'is_moon_ore' => $isMoonOre,
                        'is_ice' => $isIce,
                        'is_gas' => $isGas,
                        'is_abyssal' => $isAbyssal,
                        'ore_category' => $oreCategory,
                        'processed_at' => Carbon::now(),
                    ]);
                    $processed++;

                    Log::debug("Mining Manager: Created new ledger entry for character {$characterId}, type {$entry->type_id}, quantity {$entry->quantity}, value {$values['total_value']}");
                }
            }

            if ($processed > 0 || $updated > 0) {
                Log::info("Mining Manager: Processed mining ledger for character {$characterId}: {$processed} new, {$updated} updated");

                // Update active mining events if feature is enabled
                if (config('mining-manager.features.mining_events', true)) {
                    $this->updateActiveEvents($characterId, $ledgerEntries);
                }

                // Check for jackpot ores in the new mining data
                $this->checkForJackpotOres($characterId, $ledgerEntries);
            }

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error processing mining ledger for character {$characterId}: " . $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * Update active mining events with new data
     *
     * @param int $characterId
     * @param \Illuminate\Support\Collection $ledgerEntries
     * @return void
     */
    private function updateActiveEvents(int $characterId, $ledgerEntries)
    {
        try {
            // Get active mining events
            $activeEvents = MiningEvent::where('status', 'active')
                ->where('start_time', '<=', Carbon::now())
                ->where(function ($query) {
                    $query->whereNull('end_time')
                        ->orWhere('end_time', '>=', Carbon::now());
                })
                ->get();

            if ($activeEvents->isEmpty()) {
                return;
            }

            foreach ($activeEvents as $event) {
                // Filter ledger entries that fall within event timeframe
                $eventEntries = $ledgerEntries->filter(function ($entry) use ($event) {
                    $entryDate = Carbon::parse($entry->date);
                    
                    // Check if within event timeframe
                    if ($entryDate < $event->start_time) {
                        return false;
                    }

                    if ($event->end_time && $entryDate > $event->end_time) {
                        return false;
                    }

                    // Check if in correct system (if specified)
                    if ($event->solar_system_id && $entry->solar_system_id != $event->solar_system_id) {
                        return false;
                    }

                    return true;
                });

                if ($eventEntries->isEmpty()) {
                    continue;
                }

                // Calculate total quantity for this character in this event
                $totalQuantity = $eventEntries->sum('quantity');

                // Update or create participant record
                $participant = EventParticipant::updateOrCreate(
                    [
                        'event_id' => $event->id,
                        'character_id' => $characterId,
                    ],
                    [
                        'quantity_mined' => $totalQuantity,
                        'last_updated' => Carbon::now(),
                    ]
                );

                Log::debug("Mining Manager: Updated event {$event->id} participant data for character {$characterId}: {$totalQuantity} ore");

                // Recalculate event totals
                $event->participant_count = $event->participants()->count();
                $event->total_mined = $event->participants()->sum('quantity_mined');
                $event->last_updated = Carbon::now();
                $event->save();
            }

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error updating active events for character {$characterId}: " . $e->getMessage());
        }
    }

    /**
     * Check if any newly mined ores are jackpot variants and flag the extraction
     *
     * @param int $characterId
     * @param \Illuminate\Support\Collection $ledgerEntries
     * @return void
     */
    private function checkForJackpotOres(int $characterId, $ledgerEntries)
    {
        try {
            $jackpotTypeIds = TypeIdRegistry::getAllJackpotOres();

            // Filter ledger entries that contain jackpot ore type IDs
            $jackpotEntries = $ledgerEntries->filter(function ($entry) use ($jackpotTypeIds) {
                return in_array($entry->type_id, $jackpotTypeIds);
            });

            if ($jackpotEntries->isEmpty()) {
                return;
            }

            Log::info("Mining Manager: Jackpot ores detected in mining data for character {$characterId}", [
                'jackpot_type_ids' => $jackpotEntries->pluck('type_id')->unique()->toArray(),
            ]);

            // Group by solar system to match against extractions
            $bySystem = $jackpotEntries->groupBy('solar_system_id');

            foreach ($bySystem as $systemId => $entries) {
                // Find active extraction in this solar system
                $structure = DB::table('universe_structures')
                    ->where('solar_system_id', $systemId)
                    ->first();

                if (!$structure) {
                    continue;
                }

                // Find extraction that covers this date range
                $entryDate = Carbon::parse($entries->first()->date);

                $extraction = MoonExtraction::where('structure_id', $structure->structure_id)
                    ->where('chunk_arrival_time', '<=', $entryDate->endOfDay())
                    ->where('natural_decay_time', '>=', $entryDate->startOfDay())
                    ->where('is_jackpot', false)
                    ->first();

                if (!$extraction) {
                    // No matching extraction found, but still log it
                    Log::info("Mining Manager: Jackpot ores found in system {$systemId} but no matching extraction");
                    continue;
                }

                // Mark as jackpot
                $extraction->is_jackpot = true;
                $extraction->jackpot_detected_at = now();
                $extraction->save();

                Log::info("Mining Manager: JACKPOT EXTRACTION flagged!", [
                    'extraction_id' => $extraction->id,
                    'moon_name' => $extraction->moon_name,
                    'structure_id' => $extraction->structure_id,
                ]);

                // Get character name for the notification
                $characterName = DB::table('character_infos')
                    ->where('character_id', $characterId)
                    ->value('name') ?? "Character {$characterId}";

                // Get system name
                $systemName = DB::table('solar_systems')
                    ->where('system_id', $systemId)
                    ->value('name') ?? "System {$systemId}";

                // Build jackpot ore details for the notification
                $jackpotOreDetails = [];
                foreach ($entries as $entry) {
                    $oreName = DB::table('invTypes')
                        ->where('typeID', $entry->type_id)
                        ->value('typeName') ?? "Type {$entry->type_id}";

                    $jackpotOreDetails[] = [
                        'name' => $oreName,
                        'type_id' => $entry->type_id,
                        'quantity' => $entry->quantity,
                    ];
                }

                // Send webhook notification
                try {
                    $webhookService = app(WebhookService::class);
                    $webhookService->sendMoonNotification('jackpot_detected', [
                        'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                        'structure_name' => $structure->name ?? "Structure {$extraction->structure_id}",
                        'system_name' => $systemName,
                        'detected_by' => $characterName,
                        'jackpot_ores' => $jackpotOreDetails,
                        'jackpot_percentage' => count($jackpotOreDetails) > 0 ? 100 : 0,
                        'extraction_id' => $extraction->id,
                    ], $extraction->corporation_id);
                } catch (\Exception $e) {
                    Log::error("Mining Manager: Failed to send jackpot notification", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error checking for jackpot ores: " . $e->getMessage());
        }
    }

    /**
     * Classify ore into a category string for statistics.
     *
     * @param int $typeId
     * @return string
     */
    private function classifyOreCategory(int $typeId): string
    {
        if (TypeIdRegistry::isMoonOre($typeId)) {
            // Get specific rarity
            $rarity = TypeIdRegistry::getMoonOreRarity($typeId);
            return $rarity ? 'moon_' . $rarity : 'moon';
        }

        if (TypeIdRegistry::isIce($typeId)) {
            return 'ice';
        }

        if (TypeIdRegistry::isGas($typeId)) {
            return 'gas';
        }

        if (in_array($typeId, TypeIdRegistry::ABYSSAL_ORES)) {
            return 'abyssal';
        }

        if (TypeIdRegistry::isDeepSpaceSurveyOre($typeId)) {
            return 'ore';
        }

        if (TypeIdRegistry::isOreProspectingArrayOre($typeId)) {
            return 'ore';
        }

        if (TypeIdRegistry::isRegularOre($typeId)) {
            return 'ore';
        }

        return 'ore';
    }

    /**
     * Calculate ore values for a ledger entry using OreValuationService.
     * This implements "daily session pricing" — ore is priced at the day's market rate.
     *
     * @param int $typeId
     * @param int $quantity
     * @return array ['unit_price' => float, 'ore_value' => float, 'mineral_value' => float, 'total_value' => float]
     */
    private function calculateEntryValues(int $typeId, int $quantity): array
    {
        try {
            $valuationService = app(\MiningManager\Services\Pricing\OreValuationService::class);
            $values = $valuationService->calculateOreValue($typeId, $quantity);

            return [
                'unit_price' => $values['unit_price'] ?? 0,
                'ore_value' => $values['ore_value'] ?? 0,
                'mineral_value' => $values['mineral_value'] ?? 0,
                'total_value' => $values['total_value'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::warning("Mining Manager: Failed to calculate values for type_id {$typeId}: {$e->getMessage()}");
            return [
                'unit_price' => 0,
                'ore_value' => 0,
                'mineral_value' => 0,
                'total_value' => 0,
            ];
        }
    }

    /**
     * Check if this mining entry already exists from observer data (cross-source dedup).
     * Prevents counting the same mining twice when both listener and command run.
     *
     * @param object $entry
     * @return bool True if observer record already exists
     */
    private function hasObserverRecord($entry): bool
    {
        return MiningLedger::where('character_id', $entry->character_id)
            ->whereDate('date', $entry->date)
            ->where('type_id', $entry->type_id)
            ->where('solar_system_id', $entry->solar_system_id)
            ->whereNotNull('observer_id')
            ->exists();
    }

    /**
     * Handle a job failure.
     *
     * @param CharacterMiningUpdated $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(CharacterMiningUpdated $event, $exception)
    {
        Log::error("Mining Manager: Failed to process mining ledger for character {$event->character_id}: " . $exception->getMessage());
    }
}
