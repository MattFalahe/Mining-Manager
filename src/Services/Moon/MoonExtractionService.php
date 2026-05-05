<?php

namespace MiningManager\Services\Moon;

use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Moon\MoonOreHelper;
use MiningManager\Services\Pricing\PriceProviderService;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;

class MoonExtractionService
{
    /**
     * Moon value calculation service
     *
     * @var MoonValueCalculationService
     */
    protected $valueService;

    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Price provider service
     *
     * @var PriceProviderService
     */
    protected $priceService;

    /**
     * Constructor
     *
     * @param MoonValueCalculationService $valueService
     * @param SettingsManagerService $settingsService
     * @param PriceProviderService $priceService
     */
    public function __construct(MoonValueCalculationService $valueService, SettingsManagerService $settingsService, PriceProviderService $priceService)
    {
        $this->valueService = $valueService;
        $this->settingsService = $settingsService;
        $this->priceService = $priceService;
    }

    /**
     * Get the moon owner corporation ID from settings.
     * Falls back to first available corporation if not configured.
     *
     * @return int|null
     */
    protected function getMoonOwnerCorporationId(): ?int
    {
        $corpId = $this->settingsService->getTaxProgramCorporationId();

        if ($corpId) {
            return $corpId;
        }

        // Last-resort heuristic: if the tax program corp is not configured,
        // try to pick up any corp that has extraction data in SeAT's tables.
        // This keeps the plugin functional on a fresh install before the admin
        // sets the setting; a warning is logged so the state is visible.
        $firstExtraction = DB::table('corporation_industry_mining_extractions')
            ->select('corporation_id')
            ->first();

        if ($firstExtraction?->corporation_id) {
            Log::warning("Mining Manager: tax program corporation not configured, falling back to corporation {$firstExtraction->corporation_id} from extraction data");
            return (int) $firstExtraction->corporation_id;
        }

        return null;
    }

    /**
     * Fetch extraction data from SeAT's database for a structure.
     * This reads from corporation_industry_mining_extractions instead of calling ESI.
     * Now filters by moon owner corporation ID from settings.
     *
     * @param int $structureId
     * @return array
     */
    public function fetchExtractionData(int $structureId): array
    {
        try {
            Log::debug("Mining Manager: Fetching extraction data for structure {$structureId}");

            // Check if SeAT's extraction table exists
            if (!Schema::hasTable('corporation_industry_mining_extractions')) {
                Log::warning("Mining Manager: SeAT extraction table not found");
                return [];
            }

            // Get moon owner corporation ID from settings
            $moonOwnerCorpId = $this->getMoonOwnerCorporationId();

            if (!$moonOwnerCorpId) {
                Log::warning("Mining Manager: No moon owner corporation ID configured and no fallback available");
                return [];
            }

            // Fetch extraction data from SeAT's database
            // IMPORTANT: Filter by corporation_id to only show extractions from moon owner corp
            $extractions = DB::table('corporation_industry_mining_extractions')
                ->where('structure_id', $structureId)
                ->where('corporation_id', $moonOwnerCorpId)
                ->where('natural_decay_time', '>', Carbon::now()->subDays(7)) // Only get recent/future extractions
                ->orderBy('extraction_start_time', 'desc')
                ->get();

            if ($extractions->isEmpty()) {
                Log::debug("Mining Manager: No extractions found for structure {$structureId}");
                return [];
            }

            $extractionData = [];

            foreach ($extractions as $extraction) {
                // Get moon name from moons table
                $moon = DB::table('moons')
                    ->where('moon_id', $extraction->moon_id)
                    ->first();

                $moonName = $moon ? $moon->name : "Moon {$extraction->moon_id}";

                // Get ore composition with actual volumes from notification
                $oreComposition = $this->getMoonComposition(
                    $extraction->moon_id,
                    $extraction->structure_id,
                    $extraction->extraction_start_time
                );

                $extractionData[] = [
                    'structure_id' => $extraction->structure_id,
                    'corporation_id' => $extraction->corporation_id,
                    'moon_id' => $extraction->moon_id,
                    'moon_name' => $moonName,
                    'extraction_start_time' => $extraction->extraction_start_time,
                    'chunk_arrival_time' => $extraction->chunk_arrival_time,
                    'natural_decay_time' => $extraction->natural_decay_time,
                    'ore_composition' => $oreComposition,
                ];
            }

            Log::debug("Mining Manager: Found " . count($extractionData) . " extractions for structure {$structureId}");

            return $extractionData;

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error fetching extraction data for structure {$structureId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get actual ore volumes from MoonminingExtractionStarted notification.
     * This provides the REAL chunk volumes, not estimated from percentages.
     *
     * @param int $structureId
     * @param string $extractionStartTime
     * @return array|null Array of [type_id => volume_in_m3]
     */
    private function getActualOreVolumesFromNotification(int $structureId, string $extractionStartTime): ?array
    {
        try {
            // Query character_notifications for MoonminingExtractionStarted notifications
            // We need to find the notification for this specific extraction
            $notification = DB::table('character_notifications')
                ->where('type', 'MoonminingExtractionStarted')
                ->where('text', 'LIKE', '%structureID: ' . $structureId . '%')
                ->where('timestamp', '>=', Carbon::parse($extractionStartTime)->subMinutes(5))
                ->where('timestamp', '<=', Carbon::parse($extractionStartTime)->addMinutes(5))
                ->orderBy('timestamp', 'desc')
                ->first();

            if (!$notification) {
                Log::debug("Mining Manager: No notification found for structure {$structureId} at {$extractionStartTime}");
                return null;
            }

            // Parse the YAML text to extract ore volumes
            try {
                $data = Yaml::parse($notification->text);

                if (!isset($data['oreVolumeByType'])) {
                    Log::warning("Mining Manager: Notification for structure {$structureId} missing oreVolumeByType");
                    return null;
                }

                // Return the actual ore volumes [type_id => volume]
                $volumes = [];
                foreach ($data['oreVolumeByType'] as $typeId => $volume) {
                    $volumes[(int)$typeId] = (float)$volume;
                }

                Log::info("Mining Manager: Found actual ore volumes from notification for structure {$structureId}", [
                    'volumes' => $volumes,
                    'total_volume' => array_sum($volumes),
                ]);

                return $volumes;

            } catch (\Exception $e) {
                Log::error("Mining Manager: Failed to parse notification YAML for structure {$structureId}: " . $e->getMessage());
                return null;
            }

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error fetching notification data for structure {$structureId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get moon ore composition from universe_moon_contents table.
     * Now enhanced to merge actual volumes from notifications when available.
     *
     * @param int $moonId
     * @param int|null $structureId Optional structure ID to fetch actual volumes
     * @param string|null $extractionStartTime Optional extraction start time to find notification
     * @return array|null
     */
    private function getMoonComposition(int $moonId, ?int $structureId = null, ?string $extractionStartTime = null): ?array
    {
        try {
            // Try to get actual volumes from notification first (most accurate)
            $actualVolumes = null;
            if ($structureId && $extractionStartTime) {
                $actualVolumes = $this->getActualOreVolumesFromNotification($structureId, $extractionStartTime);
            }

            // Check if universe_moon_contents table exists
            if (!Schema::hasTable('universe_moon_contents')) {
                Log::debug("Mining Manager: universe_moon_contents table not found");
                return null;
            }

            // Get moon composition percentages
            $contents = DB::table('universe_moon_contents')
                ->where('moon_id', $moonId)
                ->get();

            if ($contents->isEmpty()) {
                Log::debug("Mining Manager: No composition data found for moon {$moonId} - moon not scanned");
                return null;
            }

            // Batch-load all ore type data to avoid N+1 queries
            $typeIds = $contents->pluck('type_id')->unique()->toArray();
            $oreTypes = DB::table('invTypes')
                ->whereIn('typeID', $typeIds)
                ->get()
                ->keyBy('typeID');

            // Build composition array with ore types and percentages
            $composition = [];
            foreach ($contents as $content) {
                $oreType = $oreTypes->get($content->type_id);

                if ($oreType) {
                    // Check if we have actual volume from notification
                    $actualQuantity = $actualVolumes[$content->type_id] ?? 0;
                    $actualVolume = $actualQuantity; // Volume in m³

                    // Convert volume to quantity (units) by dividing by ore volume
                    $unitVolume = $oreType->volume ?? 16; // Moon ores are typically 16 m³/unit
                    $quantityInUnits = $actualVolume > 0 ? ($actualVolume / $unitVolume) : 0;

                    // Calculate the refined value of this ore
                    $oreValue = 0;
                    if ($quantityInUnits > 0) {
                        $minerals = MoonOreHelper::getRefinedMinerals($content->type_id, $quantityInUnits);
                        $oreValue = array_sum(array_column($minerals, 'value'));
                    }

                    $composition[$oreType->typeName] = [
                        'type_id' => $content->type_id,
                        'percentage' => $content->rate * 100, // Convert to percentage
                        'quantity' => $quantityInUnits, // Actual quantity in units (not m³)
                        'volume_m3' => $actualVolume, // Store actual volume for reference
                        'value' => $oreValue, // Calculated refined value
                    ];
                }
            }

            if ($actualVolumes) {
                Log::info("Mining Manager: Merged actual volumes from notification for moon {$moonId}", [
                    'total_volume_m3' => array_sum(array_column($composition, 'volume_m3')),
                ]);
            }

            return empty($composition) ? null : $composition;

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error getting moon composition for moon {$moonId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update extraction data for a specific extraction.
     *
     * @param MoonExtraction $extraction
     * @return bool
     */
    public function updateExtraction(MoonExtraction $extraction): bool
    {
        try {
            Log::debug("Mining Manager: Updating extraction {$extraction->id}");

            // Fetch latest data from SeAT's database
            $extractionData = $this->fetchExtractionData($extraction->structure_id);

            if (empty($extractionData)) {
                return false;
            }

            // Find matching extraction in the fetched data
            $matchingData = collect($extractionData)->first(function ($data) use ($extraction) {
                return $data['extraction_start_time'] == $extraction->extraction_start_time->format('Y-m-d H:i:s');
            });

            if (!$matchingData) {
                Log::warning("Mining Manager: No matching extraction data found for extraction {$extraction->id}");
                return false;
            }

            // Update extraction record
            $extraction->update([
                'moon_id' => $matchingData['moon_id'],
                'chunk_arrival_time' => $matchingData['chunk_arrival_time'],
                'natural_decay_time' => $matchingData['natural_decay_time'],
                'status' => $this->determineStatus($matchingData),
                'updated_at' => Carbon::now(),
            ]);

            // Update ore composition if available
            if (isset($matchingData['ore_composition']) && $matchingData['ore_composition'] !== null) {
                $extraction->update([
                    'ore_composition' => $matchingData['ore_composition'],
                    'estimated_value' => $this->valueService->calculateExtractionValue($extraction),
                ]);
            }

            Log::info("Mining Manager: Updated extraction {$extraction->id}");

            return true;

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error updating extraction {$extraction->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Refresh all extractions for all corporation refineries.
     * Now filters by moon owner corporation ID from settings.
     *
     * @return array
     */
    public function refreshAllExtractions(): array
    {
        Log::info("Mining Manager: Refreshing all moon extractions");

        // Get moon owner corporation ID from settings
        $moonOwnerCorpId = $this->getMoonOwnerCorporationId();

        if (!$moonOwnerCorpId) {
            Log::warning("Mining Manager: No moon owner corporation ID configured");
            return [
                'updated' => 0,
                'created' => 0,
                'errors' => ['No moon owner corporation configured'],
            ];
        }

        // Get all refinery structures (Athanor and Tatara) for moon owner corporation
        $refineries = CorporationStructure::whereIn('type_id', [35835, 35836])
            ->where('corporation_id', $moonOwnerCorpId)
            ->get();

        if ($refineries->isEmpty()) {
            Log::info("Mining Manager: No refineries found for corporation {$moonOwnerCorpId}");
            return [
                'updated' => 0,
                'created' => 0,
                'errors' => [],
            ];
        }

        Log::info("Mining Manager: Found {$refineries->count()} refinery structures for corporation {$moonOwnerCorpId}");

        $updated = 0;
        $created = 0;
        $errors = [];

        foreach ($refineries as $refinery) {
            try {
                $result = $this->updateStructureExtractions($refinery);
                $updated += $result['updated'];
                $created += $result['created'];
            } catch (\Exception $e) {
                Log::error("Mining Manager: Error refreshing extractions for structure {$refinery->structure_id}: " . $e->getMessage());
                $errors[] = [
                    'structure_id' => $refinery->structure_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Update statuses of existing extractions
        $this->updateExtractionStatuses();

        Log::info("Mining Manager: Extraction refresh complete. Created: {$created}, Updated: {$updated}");

        return [
            'updated' => $updated,
            'created' => $created,
            'errors' => $errors,
        ];
    }

    /**
     * Update extractions for a specific structure.
     *
     * @param CorporationStructure $structure
     * @return array
     */
    private function updateStructureExtractions(CorporationStructure $structure): array
    {
        $extractionData = $this->fetchExtractionData($structure->structure_id);

        if (empty($extractionData)) {
            return ['updated' => 0, 'created' => 0];
        }

        $updated = 0;
        $created = 0;

        DB::transaction(function () use ($structure, $extractionData, &$updated, &$created) {
            foreach ($extractionData as $data) {
                $existing = MoonExtraction::where('structure_id', $structure->structure_id)
                    ->where('extraction_start_time', $data['extraction_start_time'])
                    ->first();

                if ($existing) {
                    $existing->update([
                        'chunk_arrival_time' => $data['chunk_arrival_time'],
                        'natural_decay_time' => $data['natural_decay_time'],
                        'status' => $this->determineStatus($data),
                        'moon_id' => $data['moon_id'] ?? null,
                        'ore_composition' => $data['ore_composition'] ?? null,
                    ]);

                    if (isset($data['ore_composition'])) {
                        $existing->update([
                            'estimated_value' => $this->valueService->calculateExtractionValue($existing),
                        ]);
                    }

                    $updated++;
                } else {
                    $extraction = MoonExtraction::create([
                        'structure_id' => $structure->structure_id,
                        'corporation_id' => $structure->corporation_id,
                        'moon_id' => $data['moon_id'] ?? null,
                        'extraction_start_time' => $data['extraction_start_time'],
                        'chunk_arrival_time' => $data['chunk_arrival_time'],
                        'natural_decay_time' => $data['natural_decay_time'],
                        'status' => $this->determineStatus($data),
                        'ore_composition' => $data['ore_composition'] ?? null,
                    ]);

                    if (isset($data['ore_composition'])) {
                        $extraction->update([
                            'estimated_value' => $this->valueService->calculateExtractionValue($extraction),
                        ]);
                    }

                    $created++;
                }
            }
        });

        return ['updated' => $updated, 'created' => $created];
    }

    /**
     * Determine extraction status based on times.
     *
     * @param array $data
     * @return string
     */
    private function determineStatus(array $data): string
    {
        $now = Carbon::now();
        $chunkArrival = Carbon::parse($data['chunk_arrival_time']);
        $naturalDecay = Carbon::parse($data['natural_decay_time']);

        if ($now < $chunkArrival) {
            return 'extracting';
        } elseif ($now >= $chunkArrival && $now < $naturalDecay) {
            return 'ready';
        } else {
            return 'expired';
        }
    }

    /**
     * Detect auto-fractured extractions by scanning EVE notifications.
     * When EVE auto-fractures a moon (no player fired the laser), a
     * MoonminingAutoFracture notification is generated. This extends
     * the ready window from 48h to 51h.
     *
     * @return int Number of auto-fractures detected
     */
    /**
     * Detect fracture events from ESI notifications.
     *
     * Checks for both:
     * - MoonminingLaserFired: player manually fired the laser
     * - MoonminingAutomaticFracture: no one fired, EVE auto-fractured after 3h
     *
     * Sets fractured_at timestamp and fractured_by (player name for manual).
     */
    public function detectAutoFractures(): int
    {
        // Get all ready extractions that don't have a fracture time yet
        $readyExtractions = MoonExtraction::whereNull('fractured_at')
            ->whereIn('status', ['extracting', 'ready'])
            ->whereNotNull('chunk_arrival_time')
            ->where('chunk_arrival_time', '<=', Carbon::now())
            ->get();

        $detected = 0;
        foreach ($readyExtractions as $extraction) {
            // Check for manual laser fire first (player blew it up)
            $laserNotification = DB::table('character_notifications')
                ->where('type', 'MoonminingLaserFired')
                ->where('text', 'LIKE', '%structureID: ' . $extraction->structure_id . '%')
                ->where('timestamp', '>=', $extraction->chunk_arrival_time)
                ->where('timestamp', '<=', Carbon::now())
                ->orderBy('timestamp')
                ->first();

            if ($laserNotification) {
                // Extract player name from notification text if possible
                $firedBy = null;
                if (preg_match('/firedBy:\s*\[.*?,\s*"([^"]+)"\]/', $laserNotification->text, $matches)) {
                    $firedBy = $matches[1];
                } elseif (preg_match('/fired by (.+?) and/', $laserNotification->text, $matches)) {
                    $firedBy = $matches[1];
                }

                $extraction->fractured_at = Carbon::parse($laserNotification->timestamp);
                $extraction->fractured_by = $firedBy;
                $extraction->auto_fractured = false;
                $extraction->status = 'ready';
                $extraction->save();
                $detected++;
                Log::info("Mining Manager: Manual fracture detected for extraction {$extraction->id} at structure {$extraction->structure_id}" .
                    ($firedBy ? " by {$firedBy}" : ''));
                continue;
            }

            // Check for auto-fracture notification
            $autoNotification = DB::table('character_notifications')
                ->where('type', 'MoonminingAutomaticFracture')
                ->where('text', 'LIKE', '%structureID: ' . $extraction->structure_id . '%')
                ->where('timestamp', '>=', $extraction->chunk_arrival_time)
                ->where('timestamp', '<=', Carbon::now())
                ->orderBy('timestamp')
                ->first();

            if ($autoNotification) {
                $extraction->fractured_at = Carbon::parse($autoNotification->timestamp);
                $extraction->fractured_by = null;
                $extraction->auto_fractured = true;
                $extraction->status = 'ready';
                $extraction->save();
                $detected++;
                Log::info("Mining Manager: Auto-fracture detected for extraction {$extraction->id} at structure {$extraction->structure_id}");
            }
        }

        return $detected;
    }

    /**
     * Detect cancelled extractions by scanning EVE notifications.
     *
     * When a director cancels an extraction in-game, EVE sends a
     * MoonminingExtractionCancelled notification to corporation members
     * with the appropriate role. This method finds those notifications
     * and marks the corresponding extraction as 'cancelled' so:
     *   1. The time-based arrival notification watchdog
     *      (check-extraction-arrivals) does NOT fire a false alert
     *      at the original chunk_arrival_time
     *   2. UI views can display the cancelled status correctly
     *
     * Matches by structure_id + timestamp window (cancellation must have
     * happened after the extraction was imported and before the originally
     * planned chunk arrival).
     *
     * @return int Number of extractions marked cancelled
     */
    public function detectCancellations(): int
    {
        $now = Carbon::now();

        // Candidates: extractions still pending (chunk hasn't arrived yet).
        // We don't cancel ones that already arrived — too late to matter,
        // and the notification may already have fired correctly.
        $pending = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '>', $now)
            ->get();

        $cancelled = 0;
        foreach ($pending as $extraction) {
            // Look for a MoonminingExtractionCancelled notification for this
            // structure within the extraction's lifetime (between import and
            // originally scheduled arrival).
            $cancelNotification = DB::table('character_notifications')
                ->where('type', 'MoonminingExtractionCancelled')
                ->where('text', 'LIKE', '%structureID: ' . $extraction->structure_id . '%')
                ->where('timestamp', '>=', $extraction->created_at)
                ->where('timestamp', '<=', $extraction->chunk_arrival_time)
                ->orderBy('timestamp', 'desc')
                ->first();

            if ($cancelNotification) {
                // Extract canceller name if present in the YAML-like text
                $cancelledBy = null;
                if (preg_match('/cancelledBy:\s*\[.*?,\s*"([^"]+)"\]/', $cancelNotification->text, $matches)) {
                    $cancelledBy = $matches[1];
                }

                $extraction->update(['status' => 'cancelled']);
                $cancelled++;

                Log::info("Mining Manager: Extraction {$extraction->id} marked cancelled" .
                    ($cancelledBy ? " (cancelled by {$cancelledBy})" : '') .
                    " based on MoonminingExtractionCancelled notification at {$cancelNotification->timestamp}");
            }
        }

        return $cancelled;
    }

    /**
     * Check for fracture notification for a single extraction and update fractured_at.
     * Lightweight version of detectAutoFractures() for use on page load (show page).
     * Handles late-arriving notifications: ESI may deliver the notification hours after the event.
     *
     * @param MoonExtraction $extraction
     * @return bool True if fracture was detected and updated
     */
    public function detectFractureForExtraction(MoonExtraction $extraction): bool
    {
        // Only check if we don't have a fracture time yet and chunk has arrived
        if ($extraction->fractured_at || !$extraction->chunk_arrival_time || $extraction->chunk_arrival_time->isFuture()) {
            return false;
        }

        // Check for manual laser fire first
        $laserNotification = DB::table('character_notifications')
            ->where('type', 'MoonminingLaserFired')
            ->where('text', 'LIKE', '%structureID: ' . $extraction->structure_id . '%')
            ->where('timestamp', '>=', $extraction->chunk_arrival_time)
            ->where('timestamp', '<=', Carbon::now())
            ->orderBy('timestamp')
            ->first();

        if ($laserNotification) {
            $firedBy = null;
            if (preg_match('/firedBy:\s*\[.*?,\s*"([^"]+)"\]/', $laserNotification->text, $matches)) {
                $firedBy = $matches[1];
            } elseif (preg_match('/fired by (.+?) and/', $laserNotification->text, $matches)) {
                $firedBy = $matches[1];
            }

            $extraction->update([
                'fractured_at' => Carbon::parse($laserNotification->timestamp),
                'fractured_by' => $firedBy,
                'auto_fractured' => false,
                'status' => 'ready',
            ]);

            Log::info("Mining Manager: Manual fracture detected on page load for extraction {$extraction->id}" .
                ($firedBy ? " by {$firedBy}" : ''));
            return true;
        }

        // Check for auto-fracture notification
        $autoNotification = DB::table('character_notifications')
            ->where('type', 'MoonminingAutomaticFracture')
            ->where('text', 'LIKE', '%structureID: ' . $extraction->structure_id . '%')
            ->where('timestamp', '>=', $extraction->chunk_arrival_time)
            ->where('timestamp', '<=', Carbon::now())
            ->orderBy('timestamp')
            ->first();

        if ($autoNotification) {
            $extraction->update([
                'fractured_at' => Carbon::parse($autoNotification->timestamp),
                'fractured_by' => null,
                'auto_fractured' => true,
                'status' => 'ready',
            ]);

            Log::info("Mining Manager: Auto-fracture detected on page load for extraction {$extraction->id}");
            return true;
        }

        return false;
    }

    /**
     * Update extraction statuses based on current time.
     *
     * @return void
     */
    public function updateExtractionStatuses()
    {
        $now = Carbon::now();
        Log::info("Mining Manager: updateExtractionStatuses() started at {$now->toIso8601String()}");

        // Detect cancellations first — any pending extraction whose director
        // cancelled it (MoonminingExtractionCancelled notification) is marked
        // 'cancelled' so downstream code (notifications, UI) skips it.
        $cancelled = $this->detectCancellations();
        if ($cancelled > 0) {
            Log::info("Mining Manager: Marked {$cancelled} extractions as cancelled (director action detected via EVE notifications)");
        }

        // Detect auto-fractures (affects expiry timing)
        $this->detectAutoFractures();

        // Snapshot estimated_value for extractions about to expire that have 0 value
        // This preserves historical prices before archival
        $aboutToExpire = MoonExtraction::expiredByTime()
            ->where(function ($q) {
                $q->where('estimated_value', 0)->orWhereNull('estimated_value');
            })
            ->whereNotNull('ore_composition')
            ->get();

        foreach ($aboutToExpire as $extraction) {
            try {
                $value = $this->valueService->calculateExtractionValue($extraction);
                if ($value > 0) {
                    $extraction->update(['estimated_value' => $value]);
                }
            } catch (\Exception $e) {
                // Non-critical, continue
            }
        }

        // Mark as expired using fractured_at when available, legacy estimate otherwise
        $expired = MoonExtraction::expiredByTime()->update(['status' => 'expired']);

        if ($expired > 0) {
            Log::info("Mining Manager: Marked {$expired} extractions as expired");
        }

        // Diagnostic: log all extractions currently in 'extracting' status
        $extractingCount = MoonExtraction::where('status', 'extracting')->count();
        $pastArrivalCount = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '<=', $now)
            ->count();

        Log::info("Mining Manager: Status check — {$extractingCount} extraction(s) in 'extracting' state, {$pastArrivalCount} with chunk_arrival_time <= now");

        // Mark as ready if chunk has arrived but not expired
        $readyExtractions = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '<=', $now)
            ->get();

        // Filter to only those not yet expired
        $readyIds = $readyExtractions->filter(function ($e) {
            return !$e->isExpired();
        })->pluck('id');

        if ($readyIds->isNotEmpty()) {
            MoonExtraction::whereIn('id', $readyIds)
                ->update(['status' => 'ready']);

            Log::info("Mining Manager: Marked {$readyIds->count()} extractions as ready, IDs: " . $readyIds->implode(','));

            // Send moon arrival notifications
            foreach ($readyExtractions->whereIn('id', $readyIds) as $extraction) {
                Log::info("Mining Manager: Firing moon arrival notification for extraction {$extraction->id} (moon: {$extraction->moon_name}, structure: {$extraction->structure_id}, corp: {$extraction->corporation_id})");
                $this->sendMoonArrivalNotification($extraction);
            }
        } else {
            Log::info("Mining Manager: No extractions transitioned to 'ready' this cycle");
        }

        // DIAGNOSTIC: Detect extractions that might have been imported directly as 'ready'
        // (happens if import cron ran AFTER chunk_arrival_time — skips the transition path).
        // notification_sent flag is for the hours-before alerts, but we re-use it here to
        // avoid spamming. If an extraction is ready + recent arrival + never notified,
        // log a warning so admin can investigate / manually notify.
        $missedNotifications = MoonExtraction::where('status', 'ready')
            ->where('chunk_arrival_time', '>=', $now->copy()->subHours(6))
            ->where('chunk_arrival_time', '<=', $now)
            ->whereNotIn('id', $readyIds ?? [])
            ->get();

        if ($missedNotifications->isNotEmpty()) {
            foreach ($missedNotifications as $missed) {
                Log::warning("Mining Manager: Extraction {$missed->id} (moon: {$missed->moon_name}) is 'ready' with chunk_arrival_time {$missed->chunk_arrival_time} but did NOT transition from 'extracting' this cycle. May have been imported directly as ready — no notification was fired. Check UpdateMoonExtractionsCommand import logic.");
            }
        }

        Log::info("Mining Manager: updateExtractionStatuses() finished");
    }

    /**
     * Send moon arrival webhook notification
     *
     * @param MoonExtraction $extraction
     * @return void
     */
    /**
     * Send moon arrival webhook notification.
     *
     * Public so external callers (CheckExtractionArrivalsCommand) can invoke
     * directly. Pre-fix this was private and the command reached it via
     * `ReflectionClass::getMethod()->setAccessible(true)` — a code smell
     * (breaks IDE refactor + bypasses PHP's accessibility model just to
     * dodge writing a public entry point).
     *
     * Atomic claim is handled internally — see comments inside.
     *
     * @param MoonExtraction $extraction
     * @return void
     */
    public function sendMoonArrivalNotification(MoonExtraction $extraction)
    {
        Log::info("Mining Manager: sendMoonArrivalNotification() called for extraction {$extraction->id}", [
            'moon_id' => $extraction->moon_id,
            'moon_name' => $extraction->moon_name,
            'structure_id' => $extraction->structure_id,
            'corporation_id' => $extraction->corporation_id,
            'chunk_arrival_time' => $extraction->chunk_arrival_time?->toIso8601String(),
        ]);

        // ATOMIC CLAIM via compare-and-swap on notification_sent.
        //
        // Two cron paths can hit this method for the same extraction:
        //   1. UpdateMoonExtractionsCommand (every 2h) → updateExtractionStatuses
        //   2. CheckExtractionArrivalsCommand (every minute)
        // Each command has its own Cache::lock so it can't race itself, but
        // the two commands have separate locks and can interleave on the
        // same extraction within a 60-second overlap window.
        //
        // Pre-fix: an `if ($extraction->notification_sent) return` check at
        // the bottom of the try block (after structure + ore lookups) read
        // a stale model. Both workers passed the check, both dispatched,
        // both flipped the flag. Duplicate Discord pings for one arrival.
        //
        // Now: UPDATE WHERE notification_sent=false returns the count of
        // rows updated. Only the worker that flips false→true gets back
        // claimed=1; everyone else gets 0 and bails. Same row-level pattern
        // as StructureAlertHandler's dedup latches.
        //
        // On dispatch failure we roll the claim back so the next cron tick
        // can retry. A permanently-broken webhook re-fires every tick
        // until fixed — acceptable vs a stuck latch needing manual reset.
        $claimed = MoonExtraction::where('id', $extraction->id)
            ->where('notification_sent', false)
            ->update(['notification_sent' => true]);

        if ($claimed === 0) {
            Log::info("Mining Manager: Skipping moon arrival notification — already claimed for extraction {$extraction->id}");
            return;
        }

        // We won the claim — refresh local model so subsequent reads see
        // the updated flag (defensive; nothing downstream reads it today).
        $extraction->refresh();

        try {
            // Get structure name
            $structure = DB::table('universe_structures')
                ->where('structure_id', $extraction->structure_id)
                ->first();

            $structureName = $structure->name ?? "Structure {$extraction->structure_id}";

            // Build ore composition summary with volume.
            // Logic moved to MoonExtraction::buildOreSummary() so the same
            // format is reused by jackpot_detected notifications too.
            $oreSummary = $extraction->buildOreSummary();

            // Auto fracture = chunk arrival + 3 hours
            $autoFractureTime = $extraction->chunk_arrival_time
                ? $extraction->chunk_arrival_time->copy()->addHours(3)->format('Y-m-d H:i')
                : 'Unknown';

            $baseUrl = rtrim(config('app.url', ''), '/');

            $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
            $results = $notificationService->sendMoonArrival([
                'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                'structure_name' => $structureName,
                'chunk_arrival_time' => $extraction->chunk_arrival_time
                    ? $extraction->chunk_arrival_time->format('Y-m-d H:i')
                    : 'Unknown',
                'auto_fracture_time' => $autoFractureTime,
                'estimated_value' => $extraction->estimated_value ?? 0,
                'ore_summary' => $oreSummary,
                'extraction_id' => $extraction->id,
                'extraction_url' => $baseUrl . '/mining-manager/moon/' . $extraction->id,
            ]);

            Log::info("Mining Manager: sendMoonArrival() returned for extraction {$extraction->id}", [
                'moon_name' => $extraction->moon_name,
                'result_type' => gettype($results),
                'channels' => is_array($results) ? array_keys($results) : [],
                'discord_sent_count' => is_array($results) ? count($results['discord']['sent'] ?? []) : 0,
                'discord_failed_count' => is_array($results) ? count($results['discord']['failed'] ?? []) : 0,
                'notification_sent_flag_set' => true,
            ]);

        } catch (\Exception $e) {
            // Roll back the claim so a later cron tick can retry naturally.
            MoonExtraction::where('id', $extraction->id)->update(['notification_sent' => false]);
            Log::error("Mining Manager: Failed to send moon arrival notification — claim rolled back", [
                'extraction_id' => $extraction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Send the "chunk going unstable soon" SAFETY warning for capital pilots.
     *
     * Fired ~2 hours before the chunk enters the plugin's UNSTABLE state
     * (which is fractured_at + 48h, i.e. the last 2 hours of the 50-hour
     * post-fracture lifecycle). Unstable chunks attract hostile activity —
     * this gives Rorqual / Orca pilots time to dock up or warp to safety
     * before the situation gets dangerous.
     *
     * IMPORTANT: this uses the PLUGIN's lifecycle model, not raw ESI data.
     * The plugin's lifecycle is richer than CCP's:
     *
     *     chunk_arrival_time → fractured_at (manual laser fire OR auto +3h)
     *                       → 48h READY window (stable)
     *                       → 2h UNSTABLE window (getUnstableStartTime())
     *                       → expired
     *
     * The unstable phase is what MoonExtraction::isUnstable() returns true
     * for. We fire this warning 2h BEFORE that phase starts, which is
     * fractured_at + 46h (= last 2 hours of the 48h stable window).
     *
     * ESI's `natural_decay_time` is the auto-fracture mark (~3h after
     * chunk_arrival), which is much earlier in the lifecycle and NOT the
     * right trigger here.
     *
     * Idempotent via the `unstable_warning_sent` flag on moon_extractions
     * (set inside this method after a successful dispatch). The per-minute
     * cron re-evaluates eligibility on every tick but skips rows where
     * the flag is already true.
     *
     * @param MoonExtraction $extraction
     * @return void
     */
    public function sendMoonChunkUnstableNotification(MoonExtraction $extraction): void
    {
        if ($extraction->unstable_warning_sent) {
            Log::info("Mining Manager: Skipping moon_chunk_unstable — already sent for extraction {$extraction->id}");
            return;
        }

        // Use the plugin's canonical lifecycle helpers — NOT raw ESI
        // natural_decay_time. getUnstableStartTime() = fractured_at + 48h,
        // falling back to a chunk_arrival-based estimate if fracture_at
        // isn't populated yet.
        $unstableStart = $extraction->getUnstableStartTime();
        $fractureTime = $extraction->getFractureTime();

        if (!$unstableStart) {
            Log::warning("Mining Manager: Skipping moon_chunk_unstable for extraction {$extraction->id} — cannot compute unstable_start (missing fractured_at + chunk_arrival_time)");
            return;
        }

        Log::info("Mining Manager: sendMoonChunkUnstableNotification() called for extraction {$extraction->id}", [
            'moon_id' => $extraction->moon_id,
            'moon_name' => $extraction->moon_name,
            'structure_id' => $extraction->structure_id,
            'corporation_id' => $extraction->corporation_id,
            'fracture_time' => $fractureTime?->toIso8601String(),
            'unstable_start' => $unstableStart->toIso8601String(),
            'is_auto_fractured' => (bool) $extraction->auto_fractured,
        ]);

        try {
            $structure = DB::table('universe_structures')
                ->where('structure_id', $extraction->structure_id)
                ->first();

            $structureName = $structure->name ?? "Structure {$extraction->structure_id}";
            $baseUrl = rtrim(config('app.url', ''), '/');

            $timeUntilUnstable = Carbon::now()->diffForHumans($unstableStart, [
                'parts' => 2,
                'syntax' => Carbon::DIFF_ABSOLUTE,
            ]);

            $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
            $results = $notificationService->sendMoonChunkUnstable([
                'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                'structure_name' => $structureName,
                // Display the plugin's unstable_start time, not ESI natural_decay.
                'natural_decay_time' => $unstableStart->format('Y-m-d H:i') . ' UTC',
                'time_until_unstable' => $timeUntilUnstable,
                'estimated_value' => $extraction->estimated_value ?? 0,
                'extraction_id' => $extraction->id,
                'extraction_url' => $baseUrl . '/mining-manager/moon/' . $extraction->id,
            ]);

            // Mark as sent so subsequent cron ticks don't re-fire. Set only
            // after a successful dispatch (webhookService call returned
            // without throwing).
            $extraction->update(['unstable_warning_sent' => true]);

            Log::info("Mining Manager: sendMoonChunkUnstable() returned for extraction {$extraction->id}", [
                'moon_name' => $extraction->moon_name,
                'time_until_unstable' => $timeUntilUnstable,
                'unstable_start' => $unstableStart->toIso8601String(),
                'channels' => is_array($results) ? array_keys($results) : [],
                'discord_sent_count' => is_array($results) ? count($results['discord']['sent'] ?? []) : 0,
                'discord_failed_count' => is_array($results) ? count($results['discord']['failed'] ?? []) : 0,
                'unstable_warning_sent_flag_set' => true,
            ]);

        } catch (\Exception $e) {
            Log::error("Mining Manager: Failed to send moon chunk unstable notification", [
                'extraction_id' => $extraction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Do NOT set unstable_warning_sent — so a later retry can attempt again
        }
    }

    /**
     * Get moon extraction statistics.
     * Now filters by moon owner corporation ID from settings.
     *
     * @param int $days Number of days to look back
     * @return array
     */
    public function getExtractionStats(int $days = 30): array
    {
        $startDate = Carbon::now()->subDays($days);

        // Get moon owner corporation ID from settings
        $moonOwnerCorpId = $this->getMoonOwnerCorporationId();

        $query = MoonExtraction::where('extraction_start_time', '>=', $startDate);

        // Filter by corporation if configured
        if ($moonOwnerCorpId) {
            $query->where('corporation_id', $moonOwnerCorpId);
        }

        $extractions = $query->get();

        $totalValue = 0;
        $completed = 0;

        foreach ($extractions as $extraction) {
            if ($extraction->status === 'fractured') {
                $completed++;
            }
            if ($extraction->estimated_value) {
                $totalValue += $extraction->estimated_value;
            }
        }

        return [
            'total_extractions' => $extractions->count(),
            'active' => $extractions->where('status', 'extracting')->count(),
            'ready' => $extractions->where('status', 'ready')->count(),
            'completed' => $completed,
            'total_estimated_value' => $totalValue,
            'average_value_per_extraction' => $extractions->count() > 0 
                ? $totalValue / $extractions->count() 
                : 0,
            'completion_rate' => $extractions->count() > 0 
                ? ($completed / $extractions->count()) * 100 
                : 0,
        ];
    }

    /**
     * Get extraction history for a specific structure.
     *
     * @param int $structureId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getStructureHistory(int $structureId, int $limit = 20)
    {
        return MoonExtraction::where('structure_id', $structureId)
            ->orderBy('extraction_start_time', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get moon extraction efficiency metrics.
     *
     * @param int $structureId
     * @param int $days
     * @return array
     */
    public function getExtractionEfficiency(int $structureId, int $days = 30): array
    {
        $startDate = Carbon::now()->subDays($days);

        $extractions = MoonExtraction::where('structure_id', $structureId)
            ->where('extraction_start_time', '>=', $startDate)
            ->whereIn('status', ['expired', 'fractured'])
            ->get();

        if ($extractions->isEmpty()) {
            return [
                'efficiency' => 0,
                'average_cycle_time' => 0,
                'extractions_completed' => 0,
            ];
        }

        $totalCycleTime = 0;
        $completedCount = 0;

        foreach ($extractions as $extraction) {
            $cycleTime = $extraction->extraction_start_time->diffInHours($extraction->natural_decay_time);
            $totalCycleTime += $cycleTime;
            $completedCount++;
        }

        $averageCycleTime = $completedCount > 0 ? $totalCycleTime / $completedCount : 0;

        // Calculate efficiency based on standard cycle time (typically ~7 days = 168 hours)
        $standardCycleTime = 168;
        $efficiency = $standardCycleTime > 0 
            ? min(100, ($averageCycleTime / $standardCycleTime) * 100) 
            : 0;

        return [
            'efficiency' => round($efficiency, 2),
            'average_cycle_time_hours' => round($averageCycleTime, 2),
            'extractions_completed' => $completedCount,
            'total_days_analyzed' => $days,
        ];
    }

    /**
     * Check for overdue extractions (ready chunks expiring soon).
     * Now filters by moon owner corporation ID from settings.
     *
     * @param int $hoursBeforeExpiry
     * @return \Illuminate\Support\Collection
     */
    public function getOverdueExtractions(int $hoursBeforeExpiry = 6)
    {
        $expiryThreshold = Carbon::now()->addHours($hoursBeforeExpiry);

        // Get moon owner corporation ID from settings
        $moonOwnerCorpId = $this->getMoonOwnerCorporationId();

        $query = MoonExtraction::with(['structure'])
            ->where('status', 'ready')
            ->where('natural_decay_time', '<=', $expiryThreshold)
            ->where('natural_decay_time', '>=', Carbon::now());

        // Filter by corporation if configured
        if ($moonOwnerCorpId) {
            $query->where('corporation_id', $moonOwnerCorpId);
        }

        return $query->orderBy('natural_decay_time')->get();
    }

    /**
     * Mark an extraction as fractured (mined).
     *
     * @param int $extractionId
     * @return bool
     */
    public function markAsFractured(int $extractionId): bool
    {
        try {
            $extraction = MoonExtraction::findOrFail($extractionId);

            $extraction->update(['status' => 'fractured']);

            Log::info("Mining Manager: Marked extraction {$extractionId} as fractured");

            return true;

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error marking extraction as fractured: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get extraction timeline for a structure.
     *
     * @param int $structureId
     * @return array
     */
    public function getExtractionTimeline(int $structureId): array
    {
        $current = MoonExtraction::where('structure_id', $structureId)
            ->where('status', 'extracting')
            ->first();

        $upcoming = MoonExtraction::where('structure_id', $structureId)
            ->where('status', 'extracting')
            ->where('chunk_arrival_time', '>', Carbon::now())
            ->orderBy('chunk_arrival_time')
            ->limit(5)
            ->get();

        $recent = MoonExtraction::where('structure_id', $structureId)
            ->whereIn('status', ['expired', 'fractured'])
            ->orderBy('natural_decay_time', 'desc')
            ->limit(5)
            ->get();

        return [
            'current_extraction' => $current ? [
                'id' => $current->id,
                'status' => $current->status,
                'chunk_arrival_time' => $current->chunk_arrival_time,
                'hours_until_arrival' => max(0, Carbon::now()->diffInHours($current->chunk_arrival_time, false)),
                'estimated_value' => $current->estimated_value,
            ] : null,
            'upcoming_extractions' => $upcoming->map(fn($e) => [
                'id' => $e->id,
                'chunk_arrival_time' => $e->chunk_arrival_time,
                'hours_until_arrival' => Carbon::now()->diffInHours($e->chunk_arrival_time, false),
            ])->toArray(),
            'recent_extractions' => $recent->map(fn($e) => [
                'id' => $e->id,
                'status' => $e->status,
                'natural_decay_time' => $e->natural_decay_time,
                'estimated_value' => $e->estimated_value,
            ])->toArray(),
        ];
    }

    /**
     * Clean up old extraction records.
     *
     * @param int $daysOld
     * @return int Number of records deleted
     */
    public function cleanupOldExtractions(int $daysOld = 90): int
    {
        $cutoffDate = Carbon::now()->subDays($daysOld);

        $deleted = MoonExtraction::whereIn('status', ['expired', 'fractured'])
            ->where('natural_decay_time', '<', $cutoffDate)
            ->delete();

        if ($deleted > 0) {
            Log::info("Mining Manager: Cleaned up {$deleted} old extraction records");
        }

        return $deleted;
    }

    /**
     * Check if a moon has been scanned (has composition data).
     *
     * @param int $moonId
     * @return bool
     */
    public function isMoonScanned(int $moonId): bool
    {
        if (!Schema::hasTable('universe_moon_contents')) {
            return false;
        }

        return DB::table('universe_moon_contents')
            ->where('moon_id', $moonId)
            ->exists();
    }

    /**
     * Get all scanned moons with their basic info.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getScannedMoons(): \Illuminate\Support\Collection
    {
        if (!Schema::hasTable('universe_moon_contents')) {
            return collect();
        }

        // Get unique moon IDs that have been scanned
        $moonIds = DB::table('universe_moon_contents')
            ->select('moon_id')
            ->distinct()
            ->pluck('moon_id');

        if ($moonIds->isEmpty()) {
            return collect();
        }

        // Get moon details
        return DB::table('moons')
            ->whereIn('moon_id', $moonIds)
            ->select('moon_id', 'name')
            ->orderBy('name')
            ->get();
    }

    /**
     * Simulate an extraction for a given moon and duration.
     *
     * @param int $moonId
     * @param int $extractionDays Number of days for extraction (6-56)
     * @return array|null
     */
    public function simulateExtraction(int $moonId, int $extractionDays = 14): ?array
    {
        if (!Schema::hasTable('universe_moon_contents')) {
            return null;
        }

        // Get moon composition percentages
        $contents = DB::table('universe_moon_contents')
            ->where('moon_id', $moonId)
            ->get();

        if ($contents->isEmpty()) {
            return null;
        }

        // Calculate total composition percentage (moon ore vs regular ore)
        // Moons don't always have 100% moon ore - the remainder is regular asteroid ore
        $compositionSum = $contents->sum('rate');
        $compositionPercent = round($compositionSum * 100, 1);

        // Dynamic extraction rate based on composition percentage
        // Based on observed real data:
        // - ~100% composition = ~30,000-31,000 m³/h
        // - ~80% composition  = ~30,000 m³/h
        // - ~70% composition  = ~21,000 m³/h
        // Formula derived: base rate scales with composition richness
        // Using linear interpolation between observed data points
        $baseRateAt100Percent = 31000;  // m³/h for 100% composition moon
        $baseRateAt70Percent = 21000;   // m³/h for 70% composition moon

        // Linear interpolation: rate = minRate + (compositionSum - 0.70) * slope
        // slope = (31000 - 21000) / (1.0 - 0.70) = 10000 / 0.30 = 33333
        if ($compositionSum >= 0.70) {
            $extractionRatePerHour = $baseRateAt70Percent + (($compositionSum - 0.70) / 0.30) * ($baseRateAt100Percent - $baseRateAt70Percent);
        } else {
            // For very low composition moons, extrapolate down (minimum ~15,000)
            $extractionRatePerHour = max(15000, $baseRateAt70Percent * ($compositionSum / 0.70));
        }

        $extractionRatePerHour = round($extractionRatePerHour);
        $totalHours = $extractionDays * 24;
        $totalVolume = $extractionRatePerHour * $totalHours;

        // Get moon name
        $moon = DB::table('moons')->where('moon_id', $moonId)->first();
        $moonName = $moon ? $moon->name : "Moon {$moonId}";

        // Build composition with calculated volumes and values
        $composition = [];
        $totalValue = 0;

        foreach ($contents as $content) {
            // Get ore type info
            $oreType = DB::table('invTypes')
                ->where('typeID', $content->type_id)
                ->first();

            if ($oreType) {
                // Calculate volume for this ore based on percentage
                $oreVolume = $totalVolume * $content->rate;

                // Convert volume to quantity (units)
                $unitVolume = $oreType->volume ?? 16; // Moon ores typically 16 m³/unit
                $quantityInUnits = floor($oreVolume / $unitVolume);

                // Get unit price for the ore itself using price provider
                $unitPrice = $this->priceService->getPrice($content->type_id) ?? 0;
                $oreValue = $quantityInUnits * $unitPrice;
                $totalValue += $oreValue;

                // Get R-value classification for this ore
                $rarity = MoonOreHelper::getRarity($content->type_id);

                $composition[] = [
                    'ore_name' => $oreType->typeName,
                    'type_id' => $content->type_id,
                    'percentage' => round($content->rate * 100, 2),
                    'volume' => round($oreVolume, 0),
                    'quantity' => $quantityInUnits,
                    'unit_price' => $unitPrice,
                    'value' => $oreValue,
                    'rarity' => $rarity,
                ];
            }
        }

        // Sort by value descending
        usort($composition, function ($a, $b) {
            return $b['value'] <=> $a['value'];
        });

        // Determine moon classification based on highest R-value ore
        $moonClassification = $this->determineMoonClassification($composition);

        return [
            'moon_id' => $moonId,
            'moon_name' => $moonName,
            'extraction_days' => $extractionDays,
            'extraction_hours' => $totalHours,
            'extraction_rate_m3h' => $extractionRatePerHour,
            'composition_percent' => $compositionPercent,
            'total_volume_m3' => $totalVolume,
            'total_value' => $totalValue,
            'composition' => $composition,
            'moon_classification' => $moonClassification,
        ];
    }

    /**
     * Determine moon classification based on the highest R-value ore present.
     *
     * @param array $composition
     * @return string
     */
    private function determineMoonClassification(array $composition): string
    {
        $rarityOrder = ['R64' => 5, 'R32' => 4, 'R16' => 3, 'R8' => 2, 'R4' => 1];
        $highestRarity = null;
        $highestOrder = 0;

        foreach ($composition as $ore) {
            $rarity = $ore['rarity'] ?? null;
            if ($rarity && isset($rarityOrder[$rarity])) {
                if ($rarityOrder[$rarity] > $highestOrder) {
                    $highestOrder = $rarityOrder[$rarity];
                    $highestRarity = $rarity;
                }
            }
        }

        return $highestRarity ?? 'Standard';
    }
}
