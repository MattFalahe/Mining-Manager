<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionPlan;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Notification\NotificationService;

/**
 * The moons our refineries drill that nobody has scanned into SeAT.
 *
 * The scan is the first source for a moon's ore. Without one, Mining Manager
 * falls back to the game's own extraction notices: the chunk is still valued,
 * but only once the first notice is in, and the simulator, Find Moons and the
 * quality ratings cannot see the moon at all. Moon Scan Missing says so at
 * the moments that starts to matter: a refinery with a moon drill is on the
 * moon, an extraction is started there, or a pull is planned there.
 *
 * Each of those is said once. With the daily reminder switched on, every moon
 * still missing its scan is listed again once a day until it has one.
 *
 * A refinery that cannot pull again is left out, as the planner's reminders
 * leave it out: reported destroyed, or being unanchored with nothing running.
 * One that has never run an extraction has no known moon yet, so it is first
 * mentioned when its first extraction starts.
 *
 * Settings are read without a corporation context, because this runs from a
 * scheduled command.
 */
class MoonScanWatch
{
    public const TABLE = 'mining_manager_moon_scan_alerts';

    public const KIND_REFINERY = 'refinery';
    public const KIND_EXTRACTION = 'extraction';
    public const KIND_PLAN = 'plan';

    /**
     * Moons one message lists before it says how many more there are. Keeps
     * the message inside what Discord and Slack accept.
     */
    public const LIST_LIMIT = 25;

    /**
     * Characters the listed lines may take. Discord allows 4096 in an embed's
     * description and the opening paragraph uses some of them, so long
     * structure names cut the list sooner rather than lose the message.
     */
    public const LIST_CHARS = 3200;

    /** Opt-in: list every moon still missing a scan again once a day. */
    public const DAILY_SETTING = 'notifications.moon_scan_missing_daily';

    /** When the daily list last went out, install wide. */
    public const DAILY_LAST_SENT = 'notifications.moon_scan_missing_daily_last_sent';

    /**
     * The check runs every two hours, and a run can land a few seconds earlier
     * in the day than the one before. Waiting a full 24 hours would push the
     * reminder two hours later every day.
     */
    protected const DAILY_HOURS = 23;

    /** A chunk on its way, arrived, or fractured and still being mined. */
    protected const LIVE_STATUSES = ['extracting', 'ready', 'fractured'];

    protected RefineryService $refineries;
    protected SettingsManagerService $settings;
    protected NotificationService $notifications;

    public function __construct(RefineryService $refineries, SettingsManagerService $settings, NotificationService $notifications)
    {
        $this->refineries = $refineries;
        $this->settings = $settings;
        $this->notifications = $notifications;
    }

    /**
     * Check, and send what is due. Returns how many moons went out, 0 when
     * nothing did.
     *
     * Nothing counts as said until a message has reached somebody. While no
     * webhook is bound to the alert nothing is built at all, so binding one
     * later hears about every moon still missing a scan.
     */
    public function run(int $corporationId, ?Carbon $now = null): int
    {
        if (!Schema::hasTable(self::TABLE)) {
            return 0;
        }

        $now = $now ?? Carbon::now();

        $this->tidy();

        if (!$this->notifications->reachesAnyone(NotificationService::TYPE_MOON_SCAN_MISSING)) {
            return 0;
        }

        $due = $this->due($corporationId, $now);
        if (!$due) {
            return 0;
        }

        $result = $this->notifications->sendMoonScanMissing($due['message']);
        if (!$this->delivered($result)) {
            return 0;
        }

        $this->remember($due['new'], $now);

        if ($due['message']['reminder']) {
            $this->settings->updateGlobalSetting(self::DAILY_LAST_SENT, $now->toDateTimeString());
        }

        return $due['message']['total'];
    }

    /**
     * What to send on this pass, or null: the moons with something new to say,
     * or every moon still missing a scan when the daily reminder is due.
     *
     * @return array{message: array, new: array<int, array>}|null
     */
    public function due(int $corporationId, ?Carbon $now = null): ?array
    {
        $now = $now ?? Carbon::now();

        $refineries = $this->unscannedRefineries($corporationId, $now);
        if (!$refineries) {
            return null;
        }

        $said = $this->alreadySaid(array_column($refineries, 'moon_id'));

        $new = [];
        $listed = [];
        foreach ($refineries as $structureId => $refinery) {
            foreach ($refinery['triggers'] as [$kind, $refId]) {
                if (!isset($said[$refinery['moon_id'] . ':' . $kind . ':' . $refId])) {
                    $new[] = ['moon_id' => $refinery['moon_id'], 'kind' => $kind, 'ref_id' => $refId];
                    $listed[$structureId] = $refinery;
                }
            }
        }

        $reminder = $this->reminderDue($now);
        if (!$new && !$reminder) {
            return null;
        }

        if ($reminder) {
            $listed = $refineries;
        }

        uasort($listed, fn ($a, $b) => $this->sortKey($a) <=> $this->sortKey($b));

        $lines = [];
        $length = 0;
        foreach (array_slice($listed, 0, self::LIST_LIMIT, true) as $refinery) {
            $line = $this->line($refinery);
            $length += mb_strlen($line) + 3;
            if ($length > self::LIST_CHARS) {
                break;
            }
            $lines[] = $line;
        }

        return [
            'message' => [
                'moons' => $lines,
                'more_count' => count($listed) - count($lines),
                'total' => count($listed),
                'reminder' => $reminder,
                'moons_url' => rtrim(config('app.url', ''), '/') . '/tools/moons',
            ],
            'new' => $new,
        ];
    }

    /**
     * Our refineries with a moon drill on a moon that has no scan, each with
     * what is going on there and the reasons it is worth saying so.
     *
     * @return array<int, array> keyed by structure id
     */
    protected function unscannedRefineries(int $corporationId, Carbon $now): array
    {
        $refineries = $this->refineries->refineriesForCorporation($corporationId);

        $onMoon = [];
        foreach ($refineries as $refinery) {
            if (!empty($refinery->moon_id)) {
                $onMoon[(int) $refinery->structure_id] = (int) $refinery->moon_id;
            }
        }

        $scanned = $this->scannedMoonIds(array_values($onMoon));
        $onMoon = array_filter($onMoon, fn ($moonId) => !in_array($moonId, $scanned, true));

        if (!$onMoon) {
            return [];
        }

        $ids = array_keys($onMoon);
        $cannotPull = array_merge(
            $this->refineries->reportedDestroyed($ids),
            $this->refineries->unanchoringIdle($refineries, $this->refineries->running($ids))
        );
        $onMoon = array_diff_key($onMoon, array_flip($cannotPull));

        if (!$onMoon) {
            return [];
        }

        $ids = array_keys($onMoon);

        $extractions = MoonExtraction::whereIn('structure_id', $ids)
            ->whereIn('status', self::LIVE_STATUSES)
            ->orderBy('chunk_arrival_time')
            ->get();

        $plans = MoonExtractionPlan::whereIn('structure_id', $ids)
            ->active()
            ->where('planned_arrival_time', '>', $now)
            ->orderBy('planned_arrival_time')
            ->get();

        $found = [];

        foreach ($onMoon as $structureId => $moonId) {
            $live = $extractions->filter(fn ($extraction) => (int) $extraction->structure_id === $structureId)->values();
            $planned = $plans->filter(fn ($plan) => (int) $plan->structure_id === $structureId)->values();

            $triggers = [[self::KIND_REFINERY, $structureId]];
            foreach ($live as $extraction) {
                $triggers[] = [self::KIND_EXTRACTION, (int) $extraction->id];
            }
            foreach ($planned as $plan) {
                $triggers[] = [self::KIND_PLAN, (int) $plan->id];
            }

            // After a fracture the next extraction can start while the old
            // belt is still mined, so the newest one is the one to talk about.
            $latest = null;
            foreach ($live as $extraction) {
                $latest = $extraction;
            }

            $found[$structureId] = [
                'structure_id' => $structureId,
                'moon_id' => $moonId,
                'extraction' => $latest,
                'planned' => $planned->count(),
                'next_planned' => $planned->isNotEmpty() ? $planned->first()->planned_arrival_time : null,
                'triggers' => $triggers,
            ];
        }

        return $found;
    }

    /**
     * One line per refinery: where it is, what is running, what is planned.
     */
    protected function line(array $refinery): string
    {
        $names = $this->refineries->names($refinery['structure_id']);
        $where = isset($names['system_name']) ? $names['system_name'] . ': ' : '';
        $moon = $names['moon_name'] ?? "Moon {$refinery['moon_id']}";

        $state = [];

        $extraction = $refinery['extraction'];
        if ($extraction) {
            $arrival = Carbon::parse($extraction->chunk_arrival_time)->format('Y-m-d H:i') . ' EVE';
            $state[] = $extraction->status === 'extracting' ? "chunk due {$arrival}" : "chunk in since {$arrival}";

            if ((int) ($extraction->estimated_value ?? 0) <= 0) {
                $state[] = 'no value yet';
            }
        }

        if ($refinery['planned'] > 0) {
            $state[] = sprintf(
                '%d %s planned, next %s EVE',
                $refinery['planned'],
                $refinery['planned'] === 1 ? 'pull' : 'pulls',
                Carbon::parse($refinery['next_planned'])->format('Y-m-d H:i')
            );
        }

        if (!$state) {
            $state[] = 'nothing running or planned yet';
        }

        return $where . $moon . ' (' . $names['structure_name'] . '), ' . implode(', ', $state);
    }

    /**
     * A chunk on its way first, soonest first, then moons with pulls planned,
     * then the rest.
     */
    protected function sortKey(array $refinery): array
    {
        if ($refinery['extraction']) {
            return [0, Carbon::parse($refinery['extraction']->chunk_arrival_time)->getTimestamp(), $refinery['structure_id']];
        }

        if ($refinery['next_planned']) {
            return [1, Carbon::parse($refinery['next_planned'])->getTimestamp(), $refinery['structure_id']];
        }

        return [2, 0, $refinery['structure_id']];
    }

    protected function reminderDue(Carbon $now): bool
    {
        if (!(bool) $this->settings->getSettingForCorporation(self::DAILY_SETTING, null, false)) {
            return false;
        }

        $lastSent = $this->settings->getSettingForCorporation(self::DAILY_LAST_SENT, null, null);

        return !$lastSent
            || ($now->getTimestamp() - Carbon::parse($lastSent)->getTimestamp()) >= self::DAILY_HOURS * 3600;
    }

    /**
     * Whether the message reached at least one webhook or Slack. A webhook that
     * failed is marked on the webhook itself, under Settings, Webhooks.
     */
    protected function delivered(array $result): bool
    {
        return !empty($result[NotificationService::CHANNEL_DISCORD]['sent'])
            || !empty($result[NotificationService::CHANNEL_SLACK]['success']);
    }

    /**
     * @param array<int, int> $moonIds
     * @return array<string, bool> "moon:kind:ref" => true
     */
    protected function alreadySaid(array $moonIds): array
    {
        $said = [];

        foreach (DB::table(self::TABLE)->whereIn('moon_id', array_values(array_unique($moonIds)))->get() as $row) {
            $said[$row->moon_id . ':' . $row->kind . ':' . $row->ref_id] = true;
        }

        return $said;
    }

    protected function remember(array $new, Carbon $now): void
    {
        if (!$new) {
            return;
        }

        $stamp = $now->toDateTimeString();

        DB::table(self::TABLE)->insertOrIgnore(array_map(
            fn ($row) => $row + ['created_at' => $stamp, 'updated_at' => $stamp],
            $new
        ));
    }

    /**
     * Forget a moon once it is scanned, so it starts over if the scan is ever
     * lost, and forget extractions and planned pulls that no longer exist.
     */
    protected function tidy(): void
    {
        $rows = DB::table(self::TABLE)->get();
        if ($rows->isEmpty()) {
            return;
        }

        $scanned = $this->scannedMoonIds($rows->pluck('moon_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $refs = fn (string $kind) => $rows->filter(fn ($row) => $row->kind === $kind)
            ->pluck('ref_id')->map(fn ($id) => (int) $id)->values()->all();
        $extractions = ($ids = $refs(self::KIND_EXTRACTION))
            ? MoonExtraction::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
        $plans = ($ids = $refs(self::KIND_PLAN))
            ? MoonExtractionPlan::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $gone = [];
        foreach ($rows as $row) {
            if (in_array((int) $row->moon_id, $scanned, true)
                || ($row->kind === self::KIND_EXTRACTION && !in_array((int) $row->ref_id, $extractions, true))
                || ($row->kind === self::KIND_PLAN && !in_array((int) $row->ref_id, $plans, true))) {
                $gone[] = (int) $row->id;
            }
        }

        if ($gone) {
            DB::table(self::TABLE)->whereIn('id', $gone)->delete();
        }
    }

    /**
     * @param array<int, int> $moonIds
     * @return array<int, int>
     */
    protected function scannedMoonIds(array $moonIds): array
    {
        if (!$moonIds || !Schema::hasTable('universe_moon_contents')) {
            return [];
        }

        return DB::table('universe_moon_contents')
            ->whereIn('moon_id', array_values(array_unique($moonIds)))
            ->distinct()
            ->pluck('moon_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
