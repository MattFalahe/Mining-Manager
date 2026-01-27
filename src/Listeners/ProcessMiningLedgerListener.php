<?php

namespace MiningManager\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Seat\Eveapi\Events\CharacterMiningUpdated;
use Seat\Eveapi\Models\Industry\CharacterMining;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\EventParticipant;
use MiningManager\Services\TypeIdRegistry;
use Carbon\Carbon;
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
                // Check if already processed
                $existing = MiningLedger::where('character_id', $entry->character_id)
                    ->where('date', $entry->date)
                    ->where('type_id', $entry->type_id)
                    ->where('solar_system_id', $entry->solar_system_id)
                    ->first();

                if ($existing) {
                    // Update if quantity changed
                    if ($existing->quantity != $entry->quantity) {
                        $existing->update([
                            'quantity' => $entry->quantity,
                            'processed_at' => Carbon::now(),
                        ]);
                        $updated++;
                        
                        Log::debug("Mining Manager: Updated ledger entry for character {$characterId}, type {$entry->type_id}, quantity changed from {$existing->quantity} to {$entry->quantity}");
                    }
                } else {
                    // Classify ore type
                    $isMoonOre = TypeIdRegistry::isMoonOre($entry->type_id);
                    $isIce = TypeIdRegistry::isIce($entry->type_id);
                    $isGas = TypeIdRegistry::isGas($entry->type_id);

                    // Create new entry
                    MiningLedger::create([
                        'character_id' => $entry->character_id,
                        'date' => $entry->date,
                        'type_id' => $entry->type_id,
                        'quantity' => $entry->quantity,
                        'solar_system_id' => $entry->solar_system_id,
                        'is_moon_ore' => $isMoonOre,
                        'is_ice' => $isIce,
                        'is_gas' => $isGas,
                        'processed_at' => Carbon::now(),
                    ]);
                    $processed++;

                    Log::debug("Mining Manager: Created new ledger entry for character {$characterId}, type {$entry->type_id}, quantity {$entry->quantity}");
                }
            }

            if ($processed > 0 || $updated > 0) {
                Log::info("Mining Manager: Processed mining ledger for character {$characterId}: {$processed} new, {$updated} updated");

                // Update active mining events if feature is enabled
                if (config('mining-manager.features.mining_events', true)) {
                    $this->updateActiveEvents($characterId, $ledgerEntries);
                }
            }

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error processing mining ledger for character {$characterId}: " . $e->getMessage());
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
