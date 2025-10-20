<?php

namespace MiningManager\Services\Moon;

use MiningManager\Models\MoonExtraction;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class MoonExtractionService
{
    /**
     * Moon value calculation service
     *
     * @var MoonValueCalculationService
     */
    protected $valueService;

    /**
     * Constructor
     *
     * @param MoonValueCalculationService $valueService
     */
    public function __construct(MoonValueCalculationService $valueService)
    {
        $this->valueService = $valueService;
    }

    /**
     * Fetch extraction data from ESI for a structure.
     *
     * @param int $structureId
     * @return array
     */
    public function fetchExtractionData(int $structureId): array
    {
        // This would integrate with ESI to get actual extraction data
        // For now, this is a placeholder that returns sample data structure

        try {
            // In a real implementation:
            // 1. Get corporation token
            // 2. Call ESI endpoint: /v1/corporation/{corporation_id}/mining/extractions/
            // 3. Filter by structure_id
            // 4. Return extraction data

            Log::debug("Mining Manager: Fetching extraction data for structure {$structureId}");

            // Placeholder - would call ESI here
            // $response = Http::withToken($token)->get("https://esi.evetech.net/latest/corporation/{$corpId}/mining/extractions/");

            return [
                // Sample structure - actual ESI response would be parsed here
            ];

        } catch (\Exception $e) {
            Log::error("Mining Manager: Error fetching extraction data for structure {$structureId}: " . $e->getMessage());
            return [];
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

            // Fetch latest data from ESI
            $extractionData = $this->fetchExtractionData($extraction->structure_id);

            if (empty($extractionData)) {
                return false;
            }

            // Find matching extraction in the fetched data
            $matchingData = collect($extractionData)->firstWhere('extraction_start_time', $extraction->extraction_start_time);

            if (!$matchingData) {
                Log::warning("Mining Manager: No matching extraction data found for extraction {$extraction->id}");
                return false;
            }

            // Update extraction record
            $extraction->update([
                'chunk_arrival_time' => $matchingData['chunk_arrival_time'],
                'natural_decay_time' => $matchingData['natural_decay_time'],
                'status' => $this->determineStatus($matchingData),
                'updated_at' => Carbon::now(),
            ]);

            // Update ore composition if available
            if (isset($matchingData['ore_composition'])) {
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
     *
     * @return array
     */
    public function refreshAllExtractions(): array
    {
        Log::info("Mining Manager: Refreshing all moon extractions");

        // Get all refinery structures (Athanor and Tatara)
        $refineries = CorporationStructure::whereIn('type_id', [35835, 35836])->get();

        if ($refineries->isEmpty()) {
            Log::info("Mining Manager: No refineries found");
            return [
                'updated' => 0,
                'created' => 0,
                'errors' => [],
            ];
        }

        Log::info("Mining Manager: Found {$refineries->count()} refinery structures");

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
     * Update statuses of all extractions based on current time.
     *
     * @return array
     */
    public function updateExtractionStatuses(): array
    {
        $now = Carbon::now();

        // Mark as expired if past natural decay time
        $expired = MoonExtraction::where('status', '!=', 'expired')
            ->where('natural_decay_time', '<', $now)
            ->update(['status' => 'expired']);

        // Mark as ready if chunk has arrived but not expired
        $ready = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '<=', $now)
            ->where('natural_decay_time', '>', $now)
            ->update(['status' => 'ready']);

        // Mark as fractured (mined)
        // This would require tracking actual mining from the chunk
        // For now, we assume chunks that are ready for more than X hours and have activity are fractured
        $fractured = 0;

        if ($expired > 0 || $ready > 0 || $fractured > 0) {
            Log::info("Mining Manager: Updated extraction statuses - Ready: {$ready}, Expired: {$expired}, Fractured: {$fractured}");
        }

        return [
            'ready' => $ready,
            'expired' => $expired,
            'fractured' => $fractured,
        ];
    }

    /**
     * Get upcoming extractions for notifications.
     *
     * @param int $hours
     * @return \Illuminate\Support\Collection
     */
    public function getUpcomingExtractions(int $hours = 24)
    {
        return MoonExtraction::with(['structure', 'moon', 'corporation'])
            ->where('status', 'extracting')
            ->where('chunk_arrival_time', '>=', Carbon::now())
            ->where('chunk_arrival_time', '<=', Carbon::now()->addHours($hours))
            ->orderBy('chunk_arrival_time')
            ->get();
    }

    /**
     * Get ready extractions (chunks ready to mine).
     *
     * @return \Illuminate\Support\Collection
     */
    public function getReadyExtractions()
    {
        return MoonExtraction::with(['structure', 'moon', 'corporation'])
            ->where('status', 'ready')
            ->orderBy('natural_decay_time')
            ->get();
    }

    /**
     * Get extraction calendar data.
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function getExtractionCalendar(Carbon $startDate, Carbon $endDate): array
    {
        $extractions = MoonExtraction::with(['structure', 'moon'])
            ->whereBetween('chunk_arrival_time', [$startDate, $endDate])
            ->orderBy('chunk_arrival_time')
            ->get();

        // Group by day
        $calendar = [];
        foreach ($extractions as $extraction) {
            $day = $extraction->chunk_arrival_time->format('Y-m-d');
            if (!isset($calendar[$day])) {
                $calendar[$day] = [];
            }
            $calendar[$day][] = [
                'id' => $extraction->id,
                'structure_name' => $extraction->structure->name ?? 'Unknown',
                'moon_name' => $extraction->moon->name ?? 'Unknown',
                'chunk_arrival_time' => $extraction->chunk_arrival_time,
                'natural_decay_time' => $extraction->natural_decay_time,
                'status' => $extraction->status,
                'estimated_value' => $extraction->estimated_value,
            ];
        }

        return $calendar;
    }

    /**
     * Get extraction statistics for a corporation.
     *
     * @param int $corporationId
     * @param int $days
     * @return array
     */
    public function getCorporationStatistics(int $corporationId, int $days = 30): array
    {
        $startDate = Carbon::now()->subDays($days);

        $extractions = MoonExtraction::where('corporation_id', $corporationId)
            ->where('extraction_start_time', '>=', $startDate)
            ->get();

        $totalValue = $extractions->sum('estimated_value');
        $completed = $extractions->whereIn('status', ['expired', 'fractured'])->count();

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
            ->with(['moon'])
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
     *
     * @param int $hoursBeforeExpiry
     * @return \Illuminate\Support\Collection
     */
    public function getOverdueExtractions(int $hoursBeforeExpiry = 6)
    {
        $expiryThreshold = Carbon::now()->addHours($hoursBeforeExpiry);

        return MoonExtraction::with(['structure', 'moon'])
            ->where('status', 'ready')
            ->where('natural_decay_time', '<=', $expiryThreshold)
            ->where('natural_decay_time', '>=', Carbon::now())
            ->orderBy('natural_decay_time')
            ->get();
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
     *
     * @return array
     */
    public function getExtractionAlerts(): array
    {
        $notificationHours = config('mining-manager.moon.notification_hours_before', [24, 4, 1]);
        $alerts = [];

        foreach ($notificationHours as $hours) {
            $targetTime = Carbon::now()->addHours($hours);
            $windowStart = $targetTime->copy()->subMinutes(15);
            $windowEnd = $targetTime->copy()->addMinutes(15);

            $extractions = MoonExtraction::with(['structure', 'moon'])
                ->where('status', 'extracting')
                ->where('notification_sent', false)
                ->whereBetween('chunk_arrival_time', [$windowStart, $windowEnd])
                ->get();

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
}
