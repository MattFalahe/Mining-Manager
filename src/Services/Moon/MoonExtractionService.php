<?php

namespace MiningManager\Services\Moon;

use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Moon\MoonOreHelper;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Symfony\Component\Yaml\Yaml;

class MoonExtractionService
{
    /**
     * How long an Extraction Started alert is held for the in-game notification
     * that names who started the extraction, and how often it looks meanwhile.
     */
    private const STARTED_BY_WAIT_HOURS = 6;
    private const STARTED_BY_RECHECK_SECONDS = 600;

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
                    $extraction->extraction_start_time,
                    $extraction->chunk_arrival_time,
                    $extraction->natural_decay_time
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
     * Get actual ore volumes from the game's own notices.
     * This provides the REAL chunk volumes, not estimated from percentages.
     *
     * Four notices carry them: Extraction Started when the drill is lit,
     * Extraction Finished when the chunk arrives, and Laser Fired or Automatic
     * Fracture when it breaks. The newest one wins. Whatever happened to the
     * refinery in between, a yield rig fitted or taken off included, the latest
     * notice is the game's own count of what is in the chunk, so the values and
     * Analytics follow it rather than the figure from when it was started.
     *
     * @param int $structureId
     * @param string $extractionStartTime
     * @return array|null Array of [type_id => volume_in_m3]
     */
    private function getActualOreVolumesFromNotification(int $structureId, string $extractionStartTime, $chunkArrivalTime = null, $naturalDecayTime = null): ?array
    {
        try {
            $notification = $this->latestVolumeNotice($structureId, $extractionStartTime, $chunkArrivalTime, $naturalDecayTime);

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
     * Notices after Extraction Started that carry the chunk's ore volumes.
     */
    private const LATER_VOLUME_NOTICES = [
        'MoonminingExtractionFinished',
        'MoonminingLaserFired',
        'MoonminingAutomaticFracture',
    ];

    /**
     * What each volume notice is called on the extraction page.
     */
    public const VOLUME_NOTICE_LABELS = [
        'MoonminingExtractionStarted' => 'Extraction started',
        'MoonminingExtractionFinished' => 'Chunk arrived',
        'MoonminingLaserFired' => 'Laser fired',
        'MoonminingAutomaticFracture' => 'Fractured on its own',
    ];

    /**
     * The newest notice carrying this extraction's ore volumes: one from the
     * chunk's arrival or fracture once it has arrived, otherwise the one from
     * when it was started.
     */
    private function latestVolumeNotice(int $structureId, $extractionStartTime, $chunkArrivalTime = null, $naturalDecayTime = null): ?object
    {
        $later = $this->laterVolumeNotices($structureId, $chunkArrivalTime, $naturalDecayTime);

        return $later ? end($later) : $this->findExtractionStartedNotification($structureId, $extractionStartTime);
    }

    /**
     * Finished, Laser Fired and Automatic Fracture notices for one chunk,
     * oldest first: from a few minutes before it arrived to an hour after it
     * would fracture on its own. Only ones that carry ore volumes, each kind
     * once, as every character with the role gets their own copy.
     *
     * @return array<int, object>
     */
    private function laterVolumeNotices(int $structureId, $chunkArrivalTime = null, $naturalDecayTime = null): array
    {
        if (!$chunkArrivalTime || Carbon::parse($chunkArrivalTime)->isFuture()) {
            return [];
        }

        $arrival = Carbon::parse($chunkArrivalTime);
        $autoFracture = $naturalDecayTime
            ? Carbon::parse($naturalDecayTime)
            : $arrival->copy()->addMinutes(MoonDrillingRigs::autoFractureMinutes(2));

        $rows = DB::table('character_notifications')
            ->whereIn('type', self::LATER_VOLUME_NOTICES)
            ->where('text', 'LIKE', '%structureID: ' . $structureId . '%')
            ->where('timestamp', '>=', $arrival->copy()->subMinutes(5))
            ->where('timestamp', '<=', $autoFracture->copy()->addHour())
            ->orderBy('timestamp')
            ->get(['type', 'text', 'timestamp']);

        $notices = [];

        foreach ($rows as $row) {
            if (isset($notices[$row->type])
                || !str_contains((string) $row->text, 'oreVolumeByType')
                || !StructureNotificationText::mentions((string) $row->text, $structureId)) {
                continue;
            }

            $notices[$row->type] = $row;
        }

        usort($notices, fn ($a, $b) => strcmp((string) $a->timestamp, (string) $b->timestamp));

        return $notices;
    }

    /**
     * Every notice that reported this extraction's ore volumes, oldest first,
     * with its total, so the extraction page can show where the figures came
     * from and whether the game's count changed along the way.
     *
     * @param \MiningManager\Models\MoonExtraction|\MiningManager\Models\MoonExtractionHistory $extraction
     * @return array<int, array{type: string, label: string, at: Carbon, total_m3: float}>
     */
    public function volumeChecks($extraction): array
    {
        if (!$extraction->structure_id || !$extraction->extraction_start_time) {
            return [];
        }

        try {
            $notices = array_filter(array_merge(
                [$this->findExtractionStartedNotification((int) $extraction->structure_id, $extraction->extraction_start_time)],
                $this->laterVolumeNotices((int) $extraction->structure_id, $extraction->chunk_arrival_time, $extraction->natural_decay_time)
            ));
        } catch (\Throwable $e) {
            Log::debug("Mining Manager: could not read volume notices for extraction {$extraction->id}: " . $e->getMessage());

            return [];
        }

        $checks = [];

        foreach ($notices as $notice) {
            try {
                $volumes = Yaml::parse((string) $notice->text)['oreVolumeByType'] ?? null;
            } catch (\Throwable $e) {
                $volumes = null;
            }

            if (!is_array($volumes)) {
                continue;
            }

            $checks[] = [
                'type' => $notice->type,
                'label' => self::VOLUME_NOTICE_LABELS[$notice->type] ?? $notice->type,
                'at' => Carbon::parse($notice->timestamp),
                'total_m3' => (float) array_sum(array_map('floatval', $volumes)),
            ];
        }

        return $checks;
    }

    /**
     * The in-game MoonminingExtractionStarted notification for one extraction.
     *
     * Matched on the refinery and a few minutes either side of the start time.
     * The real ore volumes and the name of whoever started the extraction both
     * come from it, because the corporation's extraction endpoint has neither.
     *
     * @param int $structureId
     * @param mixed $extractionStartTime
     * @return object|null
     */
    private function findExtractionStartedNotification(int $structureId, $extractionStartTime): ?object
    {
        return DB::table('character_notifications')
            ->where('type', 'MoonminingExtractionStarted')
            ->where('text', 'LIKE', '%structureID: ' . $structureId . '%')
            ->where('timestamp', '>=', Carbon::parse($extractionStartTime)->subMinutes(5))
            ->where('timestamp', '<=', Carbon::parse($extractionStartTime)->addMinutes(5))
            ->orderBy('timestamp', 'desc')
            ->first();
    }

    /**
     * Who started an extraction, with their main.
     *
     * Null when SeAT has not pulled the notification yet. Without Manager Core
     * that is normal for a while, because SeAT fetches the extraction endpoint
     * and the notifications on separate schedules.
     */
    private function extractionStartedBy(MoonExtraction $extraction): ?string
    {
        if (!$extraction->structure_id || !$extraction->extraction_start_time) {
            return null;
        }

        try {
            $notification = $this->findExtractionStartedNotification(
                (int) $extraction->structure_id,
                $extraction->extraction_start_time
            );
        } catch (\Throwable $e) {
            Log::debug("Mining Manager: could not look for who started extraction {$extraction->id}: " . $e->getMessage());

            return null;
        }

        return $notification
            ? $this->characterFromNotification((string) $notification->text, 'startedBy')
            : null;
    }

    /**
     * The pilot a moon mining notification names in one of its character
     * fields, with their main.
     *
     * Null when the text will not parse or the field is not there.
     */
    private function characterFromNotification(string $text, string $field): ?string
    {
        try {
            $data = Yaml::parse($text);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($data) ? MoonNotificationCharacter::describe($data, $field) : null;
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
    private function getMoonComposition(int $moonId, ?int $structureId = null, ?string $extractionStartTime = null, $chunkArrivalTime = null, $naturalDecayTime = null): ?array
    {
        try {
            // Try to get actual volumes from notification first (most accurate)
            $actualVolumes = null;
            if ($structureId && $extractionStartTime) {
                $actualVolumes = $this->getActualOreVolumesFromNotification($structureId, $extractionStartTime, $chunkArrivalTime, $naturalDecayTime);
            }

            // The moon's scan gives each ore's share of the chunk.
            $contents = Schema::hasTable('universe_moon_contents')
                ? DB::table('universe_moon_contents')->where('moon_id', $moonId)->get()->all()
                : [];

            // The game's notice lists every ore in the chunk with its volume, so
            // a moon nobody scanned into SeAT, or an ore its scan misses, still
            // gets a line: the notice's own share stands in for the scan's rate.
            $scanned = array_map(fn ($content) => (int) $content->type_id, $contents);
            $noticeTotal = $actualVolumes ? array_sum($actualVolumes) : 0;
            foreach ((array) $actualVolumes as $typeId => $volume) {
                if ($noticeTotal > 0 && !in_array((int) $typeId, $scanned, true)) {
                    $contents[] = (object) ['type_id' => (int) $typeId, 'rate' => $volume / $noticeTotal];
                }
            }

            if (!$contents) {
                Log::debug("Mining Manager: No composition for moon {$moonId}: not scanned, and no notice with its ore yet");
                return null;
            }

            // Batch-load all ore type data to avoid N+1 queries
            $typeIds = array_values(array_unique(array_map(fn ($content) => (int) $content->type_id, $contents)));
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
    public function updateStructureExtractions(CorporationStructure $structure): array
    {
        $extractionData = $this->fetchExtractionData($structure->structure_id);

        if (empty($extractionData)) {
            return ['updated' => 0, 'created' => 0];
        }

        $updated = 0;
        $created = 0;
        $refineries = app(RefineryService::class);

        DB::transaction(function () use ($structure, $extractionData, $refineries, &$updated, &$created) {
            foreach ($extractionData as $data) {
                // The rigs a chunk is pulled with are looked at until it
                // arrives; after that the chunk is what it is.
                $onItsWay = Carbon::parse($data['chunk_arrival_time'])->isFuture();

                $existing = MoonExtraction::where('structure_id', $structure->structure_id)
                    ->where('extraction_start_time', $data['extraction_start_time'])
                    ->first();

                if ($existing) {
                    $existing->update([
                        'chunk_arrival_time' => $data['chunk_arrival_time'],
                        'natural_decay_time' => $data['natural_decay_time'],
                        // Pass $existing so determineStatus() can read
                        // fractured_at (which is populated separately by
                        // detectAutoFractures, not by the ESI extraction
                        // payload). Without this, status was being computed
                        // off natural_decay_time alone and flipping to
                        // 'expired' ~3h after chunk arrival — see the
                        // method docblock for the full lifecycle story.
                        'status' => $this->determineStatus($data, $existing),
                        'moon_id' => $data['moon_id'] ?? null,
                        'ore_composition' => $data['ore_composition'] ?? null,
                    ]);

                    if ($onItsWay) {
                        $existing->update([
                            'moon_rigs' => $refineries->rigSnapshot((int) $structure->structure_id, $existing->moon_rigs),
                        ]);
                    }

                    if (isset($data['ore_composition'])) {
                        $existing->update([
                            'estimated_value' => $this->valueService->calculateExtractionValue($existing),
                        ]);
                    }

                    $updated++;
                } else {
                    // Another run can create the same row between the lookup
                    // and here; the unique key on structure and start time
                    // catches it, and that run's row stands.
                    try {
                        $extraction = MoonExtraction::create([
                            'structure_id' => $structure->structure_id,
                            'corporation_id' => $structure->corporation_id,
                            'moon_id' => $data['moon_id'] ?? null,
                            'extraction_start_time' => $data['extraction_start_time'],
                            'chunk_arrival_time' => $data['chunk_arrival_time'],
                            'natural_decay_time' => $data['natural_decay_time'],
                            'status' => $this->determineStatus($data),
                            'ore_composition' => $data['ore_composition'] ?? null,
                            'moon_rigs' => $refineries->rigSnapshot((int) $structure->structure_id),
                        ]);
                    } catch (QueryException $e) {
                        if (str_contains($e->getMessage(), 'Duplicate entry') || $e->getCode() === '23000') {
                            continue;
                        }

                        throw $e;
                    }

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
     * Read one extraction's ore again from the newest notice and price it now.
     *
     * The import does this for every extraction on each run. This is for the
     * moments that should not wait for the next run: the chunk arriving, and
     * the chunk being fractured by the laser or on its own, each of which
     * brings a notice with the game's latest count.
     *
     * Never throws: the arrival notification and fracture detection that call
     * it must carry on whatever happens to the pricing.
     */
    public function refreshChunk(MoonExtraction $extraction): bool
    {
        if (!$extraction->moon_id || !$extraction->extraction_start_time) {
            return false;
        }

        try {
            $composition = $this->getMoonComposition(
                (int) $extraction->moon_id,
                (int) $extraction->structure_id,
                $extraction->extraction_start_time->format('Y-m-d H:i:s'),
                $extraction->chunk_arrival_time,
                $extraction->natural_decay_time
            );

            if ($composition === null) {
                return false;
            }

            $extraction->ore_composition = $composition;
            $extraction->update([
                'ore_composition' => $composition,
                'estimated_value' => $this->valueService->calculateExtractionValue($extraction, true),
                'value_last_updated' => Carbon::now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Mining Manager: could not refresh the ore and value of extraction {$extraction->id}: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Determine the persisted `status` value for a moon_extractions row,
     * based on the chunk's lifecycle position.
     *
     * IMPORTANT LIFECYCLE NOTE (fixed 2026-05-31):
     *   `natural_decay_time` is the **auto-fracture mark** (~3h after
     *   chunk_arrival_time), NOT the end of the chunk's life.
     *   The actual mineable window is:
     *     chunk_arrival_time → fractured_at (manual or auto)
     *     fractured_at → fractured_at + 48h  (ready window; 72h or 96h
     *       with a Stability or Proficiency rig, read from the chunk's timers)
     *     then 2h unstable → expired
     *   So the chunk has 50 hours of life from fracture (74 / 98 rigged),
     *   not from arrival.
     *
     * The previous implementation treated `natural_decay_time` itself as
     * the expiry point, which flipped every chunk to `status='expired'`
     * about 3 hours after arrival — even though the chunk had 50 more
     * hours of mineable life ahead of it. Downstream filters
     * (`whereNotIn('status', ['cancelled', 'expired'])`) then excluded
     * the row from `moon_arrival` re-checks, `moon_chunk_unstable`
     * warning evaluation, and analytics roll-ups. This is why
     * unstable-warning notifications never fired in production.
     *
     * The fix mirrors `MoonExtraction::scopeExpiredByTime()` — uses
     * `fractured_at` when present (set by `detectAutoFractures()` from
     * `MoonminingLaserFired` / `MoonminingAutomaticFracture` ESI
     * notifications), and falls back to `natural_decay_time` as a
     * conservative auto-fracture estimate when fractured_at isn't yet
     * known. Either way fracture + the mining window + 2h is the canonical
     * end of life.
     *
     * @param array $data       Fresh ESI payload (chunk_arrival_time,
     *                          natural_decay_time, etc.)
     * @param MoonExtraction|null $existing  When updating an existing
     *                          row, pass it so we can read fractured_at
     *                          (which lives on the model, not in ESI data).
     * @return string
     */
    public function determineStatus(array $data, ?MoonExtraction $existing = null): string
    {
        // Cancelled is final. SeAT keeps the extraction row after the game
        // drops it, so a re-import must not bring it back to life and set off
        // the cancellation notification all over again.
        if ($existing && $existing->status === 'cancelled') {
            return 'cancelled';
        }

        $now = Carbon::now();
        $chunkArrival = Carbon::parse($data['chunk_arrival_time']);

        if ($now < $chunkArrival) {
            return 'extracting';
        }

        // Determine the actual fracture point:
        //  - Prefer `fractured_at` on the existing row (populated by
        //    detectAutoFractures from ESI notifications — accurate).
        //  - Fall back to `natural_decay_time` as the auto-fracture mark
        //    (conservative: assumes no manual laser fire happened).
        $fractureTime = ($existing && $existing->fractured_at)
            ? $existing->fractured_at
            : Carbon::parse($data['natural_decay_time']);

        // The mining window, 48 hours or stretched by the chunk's rig, then the
        // 2 hour unstable tail. The rig comes from the chunk's own timers.
        $tier = MoonDrillingRigs::timerTier($data['chunk_arrival_time'], $data['natural_decay_time'] ?? null)
            ?? ($existing ? $existing->timerRigTier() : 0);
        $expiryTime = $fractureTime->copy()->addHours(MoonDrillingRigs::readyHours($tier) + MoonDrillingRigs::UNSTABLE_HOURS);

        return $now < $expiryTime ? 'ready' : 'expired';
    }

    /**
     * Detect fracture events from ESI notifications.
     *
     * Checks for both:
     * - MoonminingLaserFired: player manually fired the laser
     * - MoonminingAutomaticFracture: no one fired, EVE auto-fractured after 3h
     *   (3h 36m / 3h 43m with a moon rig)
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
                $firedBy = $this->characterFromNotification((string) $laserNotification->text, 'firedBy');

                $extraction->fractured_at = Carbon::parse($laserNotification->timestamp);
                $extraction->fractured_by = $firedBy;
                $extraction->auto_fractured = false;
                $extraction->status = 'ready';
                $extraction->save();
                $this->refreshChunk($extraction);
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
                $this->refreshChunk($extraction);
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
                $cancelledBy = $this->characterFromNotification((string) $cancelNotification->text, 'cancelledBy');

                $extraction->update(['status' => 'cancelled']);
                $cancelled++;

                Log::info("Mining Manager: Extraction {$extraction->id} marked cancelled" .
                    ($cancelledBy ? " (cancelled by {$cancelledBy})" : '') .
                    " based on MoonminingExtractionCancelled notification at {$cancelNotification->timestamp}");

                // The status only ever moves to cancelled once, so this fires once.
                $this->sendExtractionCancelledNotification($extraction, $cancelledBy);
            }
        }

        return $cancelled;
    }

    /**
     * Tell the corporation an extraction was stopped before its chunk arrived.
     *
     * Same scope as Extraction Started: the Moon Owner Corporation's refineries
     * only, when one is set. A failure is logged and left there; the
     * cancellation itself is already recorded.
     */
    public function sendExtractionCancelledNotification(MoonExtraction $extraction, ?string $cancelledBy): string
    {
        $moonOwner = $this->settingsService->getTaxProgramCorporationId();
        if ($moonOwner !== null && (int) $extraction->corporation_id !== (int) $moonOwner) {
            return 'skipped';
        }

        try {
            $structureName = DB::table('universe_structures')
                ->where('structure_id', $extraction->structure_id)
                ->value('name') ?? "Structure {$extraction->structure_id}";

            app(\MiningManager\Services\Notification\NotificationService::class)->sendExtractionCancelled(array_filter([
                'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                'structure_name' => $structureName,
                'cancelled_by' => $cancelledBy,
                'chunk_arrival_time' => $extraction->chunk_arrival_time ? $extraction->chunk_arrival_time->format('Y-m-d H:i') : null,
                'planner_url' => rtrim(config('app.url', ''), '/') . '/mining-manager/moon/planner',
            ], fn ($value) => $value !== null));

            Log::info("Mining Manager: fired extraction_cancelled for extraction {$extraction->id}");

            return 'sent';
        } catch (\Throwable $e) {
            Log::error("Mining Manager: failed to send extraction_cancelled for extraction {$extraction->id}: " . $e->getMessage());

            return 'failed';
        }
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
            $firedBy = $this->characterFromNotification((string) $laserNotification->text, 'firedBy');

            $extraction->update([
                'fractured_at' => Carbon::parse($laserNotification->timestamp),
                'fractured_by' => $firedBy,
                'auto_fractured' => false,
                'status' => 'ready',
            ]);
            $this->refreshChunk($extraction);

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
            $this->refreshChunk($extraction);

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
            ->get()
            ->filter(fn (MoonExtraction $extraction) => $extraction->isExpired());

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

        // Mark as expired: past the chunk's mining window and its unstable tail
        $expired = MoonExtraction::markExpired();

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

        $this->warnAboutUnannouncedArrivals($now);

        Log::info("Mining Manager: updateExtractionStatuses() finished");
    }

    /**
     * Log a warning for chunks that arrived a while ago and still have no
     * arrival notification.
     *
     * The import marks an arrived chunk ready by itself, and the arrival
     * notification is check-extraction-arrivals' job every minute, so a ready
     * chunk that did not change state in updateExtractionStatuses() is normal.
     * One with no notification half an hour after it arrived is not: that
     * command is not running, or its webhook keeps failing.
     *
     * @return int how many were warned about
     */
    protected function warnAboutUnannouncedArrivals(Carbon $now): int
    {
        $unannounced = MoonExtraction::where('notification_sent', false)
            ->where('status', '!=', 'cancelled')
            ->where('chunk_arrival_time', '>=', $now->copy()->subHours(6))
            ->where('chunk_arrival_time', '<=', $now->copy()->subMinutes(30));

        $moonOwnerCorpId = $this->getMoonOwnerCorporationId();
        if ($moonOwnerCorpId) {
            $unannounced->where('corporation_id', $moonOwnerCorpId);
        }

        $missed = $unannounced->get();

        foreach ($missed as $extraction) {
            Log::warning("Mining Manager: Extraction {$extraction->id} (moon: {$extraction->moon_name}) arrived at {$extraction->chunk_arrival_time} and its arrival notification has still not gone out. Check that mining-manager:check-extraction-arrivals is running and that its webhook works.");
        }

        return $missed->count();
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
     * directly, rather than reaching a private method via
     * `ReflectionClass::getMethod()->setAccessible(true)` (breaks IDE
     * refactor and bypasses PHP's accessibility model).
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
        // A plain `if ($extraction->notification_sent) return` check at the
        // bottom of the try block (after structure + ore lookups) reads a
        // stale model: both workers pass the check, both dispatch, both flip
        // the flag — duplicate Discord pings for one arrival.
        //
        // Instead: UPDATE WHERE notification_sent=false returns the count of
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

            // When it fractures on its own: EVE's own time, which already
            // carries the refinery's rig, else 3 hours stretched by it.
            if ($extraction->natural_decay_time) {
                $autoFractureTime = $extraction->natural_decay_time->format('Y-m-d H:i');
            } elseif ($extraction->chunk_arrival_time) {
                $autoFractureTime = $extraction->chunk_arrival_time->copy()
                    ->addSeconds((int) round($extraction->getAutoFractureDelayMinutes() * 60))
                    ->format('Y-m-d H:i');
            } else {
                $autoFractureTime = 'Unknown';
            }

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
     * Send an `extraction_started` notification for a freshly-started
     * extraction (new chunk forming, arrival in the future).
     *
     * Holds the alert until the in-game notification that names who started
     * the extraction has reached SeAT. The alert is not urgent and is worth
     * more with a name on it, and the extraction endpoint usually lands first.
     * The hold is capped, so an install where no character receives that
     * notification still gets the alert, later and without the name.
     *
     * Atomic claim on `extraction_started_sent` mirrors the moon-arrival
     * dedup pattern: UPDATE WHERE flag=false returns the claim count, so
     * only one worker dispatches even if two crons interleave. Rolls the
     * claim back on dispatch failure so a later tick retries.
     *
     * @param MoonExtraction $extraction
     * @return string sent, waiting, skipped (already claimed) or failed
     */
    public function sendExtractionStartedNotification(MoonExtraction $extraction): string
    {
        $stillWaiting = $extraction->extraction_start_time
            && Carbon::parse($extraction->extraction_start_time)->gt(Carbon::now()->subHours(self::STARTED_BY_WAIT_HOURS));

        // character_notifications has no index on type or time, so every lookup
        // reads the whole table, and this runs once a minute. While waiting,
        // looking every ten minutes is enough: ESI only refreshes notifications
        // that often anyway.
        if ($stillWaiting && !Cache::add('mining-manager:extraction-started-by:' . $extraction->id, true, self::STARTED_BY_RECHECK_SECONDS)) {
            return 'waiting';
        }

        $startedBy = $this->extractionStartedBy($extraction);

        if ($startedBy === null && $stillWaiting) {
            return 'waiting';
        }

        $claimed = MoonExtraction::where('id', $extraction->id)
            ->where('extraction_started_sent', false)
            ->update(['extraction_started_sent' => true]);

        if ($claimed === 0) {
            Log::info("Mining Manager: Skipping extraction_started — already claimed for extraction {$extraction->id}");
            return 'skipped';
        }

        $extraction->refresh();

        try {
            $structure = DB::table('universe_structures')
                ->where('structure_id', $extraction->structure_id)
                ->first();
            $structureName = $structure->name ?? "Structure {$extraction->structure_id}";

            $arrival = $extraction->chunk_arrival_time;
            $timeUntil = ($arrival && $arrival->isFuture())
                ? Carbon::now()->diffForHumans($arrival, ['parts' => 2, 'syntax' => Carbon::DIFF_ABSOLUTE])
                : null;

            $baseUrl = rtrim(config('app.url', ''), '/');

            $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
            $notificationService->sendExtractionStarted(array_filter([
                'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                'structure_name' => $structureName,
                'started_by' => $startedBy,
                'chunk_arrival_time' => $arrival ? $arrival->format('Y-m-d H:i') : null,
                'time_until_arrival' => $timeUntil,
                'estimated_value' => $extraction->estimated_value ?? 0,
                'extraction_id' => $extraction->id,
                'extraction_url' => $baseUrl . '/mining-manager/moon/' . $extraction->id,
            ], fn ($v) => $v !== null));

            Log::info("Mining Manager: fired extraction_started for extraction {$extraction->id}");

            return 'sent';
        } catch (\Exception $e) {
            MoonExtraction::where('id', $extraction->id)->update(['extraction_started_sent' => false]);
            Log::error("Mining Manager: Failed to send extraction_started — claim rolled back", [
                'extraction_id' => $extraction->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Send a `next_extraction_planned` notification — the planner "re-fire"
     * nudge. Called AFTER the moon-ready notification has latched for the
     * just-arrived chunk; announces the refinery's next planned pull so the
     * staggered rotation is maintained.
     *
     * Looks up the soonest future active plan for the structure on the Moon
     * Extraction Planner. If none exists, falls back to an on-the-fly cadence
     * projection (not persisted). If neither yields a date, skips silently —
     * we don't nag without something to point at.
     *
     * Latches on `next_planned_sent` of the ARRIVED extraction so each
     * arrival triggers at most one nudge.
     *
     * @param MoonExtraction $extraction The chunk that just became ready.
     * @return void
     */
    public function sendNextExtractionPlannedNotification(MoonExtraction $extraction): void
    {
        $claimed = MoonExtraction::where('id', $extraction->id)
            ->where('next_planned_sent', false)
            ->update(['next_planned_sent' => true]);

        if ($claimed === 0) {
            return;
        }

        try {
            $planner = app(\MiningManager\Services\Moon\MoonPlannerService::class);
            $baseUrl = rtrim(config('app.url', ''), '/');

            // A refinery whose last pull is planned: the chunk that arrived was
            // that pull, came after it, or comes before it.
            $role = $extraction->chunk_arrival_time
                ? app(\MiningManager\Services\Moon\FinalPulls::class)->arrivalRole((int) $extraction->structure_id, $extraction->chunk_arrival_time)
                : null;

            if ($role === 'final') {
                app(\MiningManager\Services\Notification\NotificationService::class)->sendNextExtractionPlanned(array_filter([
                    'variant' => 'final',
                    'structure_name' => DB::table('universe_structures')->where('structure_id', $extraction->structure_id)->value('name')
                        ?? "Structure {$extraction->structure_id}",
                    'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                    'arrived_at' => $extraction->chunk_arrival_time->format('Y-m-d H:i'),
                    'planner_url' => $baseUrl . '/mining-manager/moon/planner',
                ], fn ($v) => $v !== null));

                Log::info("Mining Manager: the final pull arrived on structure {$extraction->structure_id}; said so instead of a next pull");
                return;
            }

            // A pull started after the final one was reported when it started.
            if ($role === 'after') {
                return;
            }

            // Prefer an explicit active plan for this refinery in the future.
            $plan = \MiningManager\Models\MoonExtractionPlan::where('structure_id', $extraction->structure_id)
                ->active()
                ->where('planned_arrival_time', '>', Carbon::now())
                ->orderBy('planned_arrival_time')
                ->first();

            $plannedAt = $plan?->planned_arrival_time;
            $source = $plan ? $plan->source : null;
            $cadenceDays = $plan?->cadence_days;

            // Fall back to a fresh projection if there's no explicit plan. Not
            // for a refinery whose final pull is still to come: its next pull
            // is whatever is planned, never a guess from its history.
            if (!$plannedAt && $role === null) {
                $plannedAt = $planner->projectNextArrival((int) $extraction->structure_id);
                $source = 'auto';
                $cadenceDays = $planner->cadence((int) $extraction->structure_id)['cadence_days'] ?? null;
            }

            // Nothing to announce — roll back the latch so a later tick can
            // try again once a plan exists, and bail.
            if (!$plannedAt) {
                MoonExtraction::where('id', $extraction->id)->update(['next_planned_sent' => false]);
                Log::info("Mining Manager: next_extraction_planned skipped — no plan/projection for structure {$extraction->structure_id}");
                return;
            }

            $structure = DB::table('universe_structures')
                ->where('structure_id', $extraction->structure_id)
                ->first();
            $structureName = $structure->name ?? "Structure {$extraction->structure_id}";

            $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
            $notificationService->sendNextExtractionPlanned(array_filter([
                'structure_name' => $structureName,
                'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                'planned_arrival_time' => $plannedAt->format('Y-m-d H:i'),
                'cadence_label' => $cadenceDays ? "~{$cadenceDays} days" : null,
                'source' => $source,
                'next_is_final' => $plan && $plan->is_final ? true : null,
                'planner_url' => $baseUrl . '/mining-manager/moon/planner',
            ], fn ($v) => $v !== null));

            Log::info("Mining Manager: fired next_extraction_planned after arrival of extraction {$extraction->id}");
        } catch (\Exception $e) {
            MoonExtraction::where('id', $extraction->id)->update(['next_planned_sent' => false]);
            Log::error("Mining Manager: Failed to send next_extraction_planned — claim rolled back", [
                'extraction_id' => $extraction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send the "chunk going unstable soon" SAFETY warning for capital pilots.
     *
     * Fired ~2 hours before the chunk enters the plugin's UNSTABLE state
     * (fracture + the chunk's mining window: 48h, or 72 / 96h with a
     * Stability or Proficiency rig). Unstable chunks attract hostile activity —
     * this gives Rorqual / Orca pilots time to dock up or warp to safety
     * before the situation gets dangerous.
     *
     * IMPORTANT: this uses the PLUGIN's lifecycle model, not raw ESI data.
     * The plugin's lifecycle is richer than CCP's:
     *
     *     chunk_arrival_time → fractured_at (manual laser fire OR auto at
     *                          natural_decay_time)
     *                       → READY for the mining window (48h, 72h or 96h)
     *                       → 2h UNSTABLE window (getUnstableStartTime())
     *                       → expired
     *
     * The unstable phase is what MoonExtraction::isUnstable() returns true
     * for. We fire this warning 2h BEFORE that phase starts, in the last
     * 2 hours of the mining window.
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
        // natural_decay_time. getUnstableStartTime() = fracture + the chunk's mining window,
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
     * Simulate an extraction for a given moon and duration.
     *
     * Priced from the price cache only; see MoonValuation for why.
     *
     * @param int $moonId
     * @param int $extractionDays Number of days for extraction (6-56)
     * @return array|null
     */
    public function simulateExtraction(int $moonId, int $extractionDays = 14, array $fit = []): ?array
    {
        if (!Schema::hasTable('universe_moon_contents')) {
            return null;
        }

        $ores = DB::table('universe_moon_contents')
            ->where('moon_id', $moonId)
            ->pluck('rate', 'type_id')
            ->map(function ($rate) {
                return (float) $rate;
            })
            ->all();

        if (empty($ores)) {
            return null;
        }

        // The refinery and its moon rigs. An Athanor takes Efficiency for the
        // yield and Stability for the timers; a Tatara gets both from one
        // Proficiency rig.
        $rig = $this->simulatedRig($fit);

        $valuation = app(MoonValuation::class);
        $valued = $valuation->value($ores, $extractionDays, $rig['yield_bonus']);

        $moonOreShare = 0.0;
        foreach ($ores as $typeId => $oreShare) {
            if (MoonOreHelper::getRarity((int) $typeId)) {
                $moonOreShare += (float) $oreShare;
            }
        }

        $moon = DB::table('moons')->where('moon_id', $moonId)->first();
        $moonName = $moon ? $moon->name : "Moon {$moonId}";

        $composition = [];
        foreach ($valued['ores'] as $line) {
            $composition[] = [
                'ore_name' => $line['ore_name'],
                'type_id' => $line['type_id'],
                'percentage' => round($line['share'] * 100, 2),
                'volume' => round($line['volume'], 0),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'value' => $line['value'],
                'refined_value' => $line['refined_value'],
                'rarity' => MoonOreHelper::getRarity($line['type_id']),
            ];
        }

        // Sort by value descending
        usort($composition, function ($a, $b) {
            return $b['value'] <=> $a['value'];
        });

        return [
            'moon_id' => $moonId,
            'moon_name' => $moonName,
            'extraction_days' => $extractionDays,
            'extraction_hours' => $extractionDays * 24,
            'extraction_rate_m3h' => $valued['rate'],
            // Moon ore as a share of the scan. $valued['share'] is every ore
            // in it, regular asteroid ore included, so it reads as 100% on any
            // fully scanned moon and says nothing about how rich it is.
            'composition_percent' => round($moonOreShare * 100, 1),
            'total_volume_m3' => $valued['volume'],
            'total_value' => $valued['raw'],
            'total_refined_value' => $valued['refined'],
            'refining_efficiency' => round($valuation->refiningEfficiency() * 100, 1),
            'unpriced_ores' => $valued['unpriced'],
            'unpriced_materials' => $valued['unpriced_materials'],
            'prices_updated_at' => $valuation->pricesUpdatedAt(),
            'composition' => $composition,
            'moon_classification' => $this->determineMoonClassification($composition),
            'rig' => $rig,
        ];
    }

    /**
     * What a refinery and its moon rigs do for one chunk.
     *
     * @param array{hull?: int, tiers?: array<string, int>} $fit
     * @return array{hull: int, tiers: array<string, int>, names: string[], yield_bonus: float, timer_tier: int, mining_window_hours: int, unstable_hours: int, auto_fracture_minutes: float}
     */
    public function simulatedRig(array $fit): array
    {
        $tiers = $fit['tiers'] ?? [];
        $tier = fn (string $kind) => max(0, min(2, (int) ($tiers[$kind] ?? 0)));

        if ((int) ($fit['hull'] ?? 0) === RefineryService::TATARA) {
            $hull = RefineryService::TATARA;
            $chosen = [MoonDrillingRigs::PROFICIENCY => $tier(MoonDrillingRigs::PROFICIENCY)];
            $timerTier = $yieldTier = $chosen[MoonDrillingRigs::PROFICIENCY];
        } else {
            $hull = RefineryService::ATHANOR;
            $chosen = [
                MoonDrillingRigs::EFFICIENCY => $tier(MoonDrillingRigs::EFFICIENCY),
                MoonDrillingRigs::STABILITY => $tier(MoonDrillingRigs::STABILITY),
            ];
            $timerTier = $chosen[MoonDrillingRigs::STABILITY];
            $yieldTier = $chosen[MoonDrillingRigs::EFFICIENCY];
        }

        $names = [];
        foreach ($chosen as $kind => $kindTier) {
            if ($kindTier > 0) {
                $names[] = MoonDrillingRigs::RIGS[MoonDrillingRigs::typeFor($kind, $kindTier)]['name'];
            }
        }

        return [
            'hull' => $hull,
            'tiers' => $chosen,
            'names' => $names,
            'yield_bonus' => MoonDrillingRigs::YIELD_BONUS[$yieldTier],
            'timer_tier' => $timerTier,
            'mining_window_hours' => MoonDrillingRigs::readyHours($timerTier),
            'unstable_hours' => MoonDrillingRigs::UNSTABLE_HOURS,
            'auto_fracture_minutes' => MoonDrillingRigs::autoFractureMinutes($timerTier),
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
