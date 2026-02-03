<?php

namespace MiningManager\Services\Moon;

use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Configuration\SettingsManagerService;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MiningManager\Services\Notification\WebhookService;
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
     * Constructor
     *
     * @param MoonValueCalculationService $valueService
     * @param SettingsManagerService $settingsService
     */
    public function __construct(MoonValueCalculationService $valueService, SettingsManagerService $settingsService)
    {
        $this->valueService = $valueService;
        $this->settingsService = $settingsService;
    }

    /**
     * Get the moon owner corporation ID from settings.
     * Falls back to first available corporation if not configured.
     *
     * @return int|null
     */
    protected function getMoonOwnerCorporationId(): ?int
    {
        $moonOwnerCorpId = $this->settingsService->getSetting('moon_owner_corporation_id');

        if (!$moonOwnerCorpId) {
            // Fallback to first corporation if not configured
            $firstCorp = DB::table('corporation_infos')
                ->orderBy('corporation_id')
                ->first();

            $moonOwnerCorpId = $firstCorp->corporation_id ?? null;

            if ($moonOwnerCorpId) {
                Log::warning("Mining Manager: moon_owner_corporation_id not configured, falling back to corporation {$moonOwnerCorpId}");
            }
        }

        return $moonOwnerCorpId;
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

            // Build composition array with ore types and percentages
            $composition = [];
            foreach ($contents as $content) {
                // Get ore type name
                $oreType = DB::table('invTypes')
                    ->where('typeID', $content->type_id)
                    ->first();

                if ($oreType) {
                    // Check if we have actual volume from notification
                    $actualQuantity = $actualVolumes[$content->type_id] ?? 0;
                    $actualVolume = $actualQuantity; // Volume in m³

                    // Convert volume to quantity (units) by dividing by ore volume
                    $unitVolume = $oreType->volume ?? 16; // Moon ores are typically 16 m³/unit
                    $quantityInUnits = $actualVolume > 0 ? ($actualVolume / $unitVolume) : 0;

                    $composition[$oreType->typeName] = [
                        'type_id' => $content->type_id,
                        'percentage' => $content->rate * 100, // Convert to percentage
                        'quantity' => $quantityInUnits, // Actual quantity in units (not m³)
                        'volume_m3' => $actualVolume, // Store actual volume for reference
                        'value' => 0, // Will be calculated by value service
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
     * Update extraction statuses based on current time.
     *
     * @return void
     */
    private function updateExtractionStatuses()
    {
        $now = Carbon::now();

        // Mark as expired if past natural decay time
        $expired = MoonExtraction::where('status', '!=', 'expired')
            ->where('natural_decay_time', '<', $now)
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            Log::info("Mining Manager: Marked {$expired} extractions as expired");
        }

        // Mark as ready if chunk has arrived but not expired
        $readyExtractions = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '<=', $now)
            ->where('natural_decay_time', '>', $now)
            ->get();

        if ($readyExtractions->isNotEmpty()) {
            MoonExtraction::whereIn('id', $readyExtractions->pluck('id'))
                ->update(['status' => 'ready']);

            Log::info("Mining Manager: Marked {$readyExtractions->count()} extractions as ready");

            // Send moon arrival notifications
            foreach ($readyExtractions as $extraction) {
                $this->sendMoonArrivalNotification($extraction);
            }
        }
    }

    /**
     * Send moon arrival webhook notification
     *
     * @param MoonExtraction $extraction
     * @return void
     */
    private function sendMoonArrivalNotification(MoonExtraction $extraction)
    {
        try {
            // Get structure name
            $structure = DB::table('universe_structures')
                ->where('structure_id', $extraction->structure_id)
                ->first();

            $structureName = $structure->name ?? "Structure {$extraction->structure_id}";

            // Build ore composition summary
            $oreSummary = '';
            if (!empty($extraction->ore_composition)) {
                $oreLines = [];
                foreach ($extraction->ore_composition as $oreName => $oreData) {
                    $percentage = $oreData['percentage'] ?? 0;
                    $oreLines[] = "{$oreName}: " . round($percentage, 1) . '%';
                }
                $oreSummary = implode("\n", $oreLines);
            }

            $webhookService = app(WebhookService::class);
            $webhookService->sendMoonNotification('moon_arrival', [
                'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                'structure_name' => $structureName,
                'chunk_arrival_time' => $extraction->chunk_arrival_time
                    ? $extraction->chunk_arrival_time->format('Y-m-d H:i')
                    : 'Unknown',
                'natural_decay_time' => $extraction->natural_decay_time
                    ? $extraction->natural_decay_time->format('Y-m-d H:i')
                    : 'Unknown',
                'estimated_value' => $extraction->estimated_value ?? 0,
                'ore_summary' => $oreSummary,
                'extraction_id' => $extraction->id,
            ], $extraction->corporation_id);

            Log::info("Mining Manager: Moon arrival notification sent for {$extraction->moon_name}");

        } catch (\Exception $e) {
            Log::error("Mining Manager: Failed to send moon arrival notification", [
                'extraction_id' => $extraction->id,
                'error' => $e->getMessage(),
            ]);
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
     * Get extraction alerts (for notifications).
     * Now filters by moon owner corporation ID from settings.
     *
     * @return array
     */
    public function getExtractionAlerts(): array
    {
        $notificationHours = config('mining-manager.moon.notification_hours_before', [24, 4, 1]);
        $alerts = [];

        // Get moon owner corporation ID from settings
        $moonOwnerCorpId = $this->getMoonOwnerCorporationId();

        foreach ($notificationHours as $hours) {
            $targetTime = Carbon::now()->addHours($hours);
            $windowStart = $targetTime->copy()->subMinutes(15);
            $windowEnd = $targetTime->copy()->addMinutes(15);

            $query = MoonExtraction::with(['structure'])
                ->where('status', 'extracting')
                ->where('notification_sent', false)
                ->whereBetween('chunk_arrival_time', [$windowStart, $windowEnd]);

            // Filter by corporation if configured
            if ($moonOwnerCorpId) {
                $query->where('corporation_id', $moonOwnerCorpId);
            }

            $extractions = $query->get();

            if ($extractions->isNotEmpty()) {
                $alerts[$hours] = $extractions;
            }
        }

        return $alerts;
    }

    /**
     * Mark extraction notification as sent.
     *
     * @param int $extractionId
     * @return bool
     */
    public function markNotificationSent(int $extractionId): bool
    {
        try {
            $extraction = MoonExtraction::findOrFail($extractionId);
            $extraction->update(['notification_sent' => true]);
            return true;
        } catch (\Exception $e) {
            Log::error("Mining Manager: Error marking notification as sent: " . $e->getMessage());
            return false;
        }
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
}
