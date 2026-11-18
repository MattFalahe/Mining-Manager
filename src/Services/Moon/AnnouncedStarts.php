<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Configuration\SettingsManagerService;

/**
 * The extraction starts Manager Core's fast-poll has already announced.
 *
 * Fast-poll announces a start from the in-game notification, usually an hour
 * or two before the extraction itself shows up in SeAT, so there is no row to
 * mark as announced at that moment. It leaves a record here instead, by
 * refinery and chunk arrival. When the row appears, the every-minute pass
 * finds the record and marks the row, and only announces a start that has no
 * record: one Manager Core never delivered, or one whose alert failed.
 *
 * Kept as one of the plugin's own records in the settings table, so a restart
 * or a cache clear cannot make a start be announced twice.
 */
class AnnouncedStarts
{
    public const KEY = 'notifications.extraction_started_announced';

    public const SINCE_KEY = 'notifications.extraction_started_announced_since';

    /** The pass looks back 72 hours, so nothing older is ever asked about. */
    private const KEEP_HOURS = 96;

    /** How far apart the notification's arrival time and SeAT's may be. */
    private const ARRIVAL_SLACK_SECONDS = 600;

    /** With no arrival time on the record, how long before the start it may date from. */
    private const START_SLACK_SECONDS = 1800;

    protected SettingsManagerService $settings;

    public function __construct(SettingsManagerService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Fast-poll announced a start at this refinery.
     */
    public function record(int $structureId, ?Carbon $arrival): void
    {
        $write = function () use ($structureId, $arrival) {
            $records = $this->records();
            $records[] = [$structureId, $arrival ? $arrival->getTimestamp() : 0, Carbon::now()->getTimestamp()];

            $this->settings->updateGlobalSetting(self::KEY, array_values($records));
        };

        // Two refineries started in the same minute are handled by two
        // workers at once, and the second write must not lose the first.
        try {
            Cache::lock('mining-manager:extraction-started-announced', 10)->block(5, $write);
        } catch (\Throwable $e) {
            $write();
        }
    }

    /**
     * Whether fast-poll already announced this extraction's start.
     */
    public function covers(MoonExtraction $extraction): bool
    {
        $arrival = $extraction->chunk_arrival_time ? Carbon::parse($extraction->chunk_arrival_time)->getTimestamp() : null;
        $started = $extraction->extraction_start_time ? Carbon::parse($extraction->extraction_start_time)->getTimestamp() : null;

        foreach ($this->records() as [$structureId, $recordedArrival, $announcedAt]) {
            if ((int) $structureId !== (int) $extraction->structure_id) {
                continue;
            }

            if ($recordedArrival > 0 && $arrival !== null) {
                if (abs($recordedArrival - $arrival) <= self::ARRIVAL_SLACK_SECONDS) {
                    return true;
                }

                continue;
            }

            // The notification gave no arrival time to match on: any
            // announcement for the refinery since about when this one started.
            if ($started !== null && $announcedAt >= $started - self::START_SLACK_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * When records started being kept, set the first time it is asked for.
     *
     * Fast-poll announced starts before then without leaving a record, so
     * with fast-poll on the pass leaves those starts alone rather than
     * announce them again. Pass $start = false to read it without setting it.
     */
    public function since(bool $start = true): ?Carbon
    {
        $stored = $this->settings->getSettingForCorporation(self::SINCE_KEY, null, null);

        if (is_numeric($stored) && (int) $stored > 0) {
            return Carbon::createFromTimestamp((int) $stored);
        }

        if (!$start) {
            return null;
        }

        $now = Carbon::now();
        $this->settings->updateGlobalSetting(self::SINCE_KEY, $now->getTimestamp());

        return $now;
    }

    /**
     * @return array<int, array{0: int, 1: int, 2: int}> refinery, chunk arrival (0 when unknown), announced at
     */
    private function records(): array
    {
        $stored = $this->settings->getSettingForCorporation(self::KEY, null, []);
        $oldest = Carbon::now()->subHours(self::KEEP_HOURS)->getTimestamp();

        $records = [];
        foreach (is_array($stored) ? $stored : [] as $record) {
            if (is_array($record) && count($record) === 3 && (int) $record[2] >= $oldest) {
                $records[] = [(int) $record[0], (int) $record[1], (int) $record[2]];
            }
        }

        return $records;
    }
}
