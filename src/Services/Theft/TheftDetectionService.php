<?php

namespace MiningManager\Services\Theft;

use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
use MiningManager\Models\Setting;
use MiningManager\Models\TheftIncident;
use MiningManager\Services\Character\CharacterNames;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Theft Detection Service
 *
 * Moon ore mined at the moon owner's refineries by a character outside every
 * configured corporation is theft when either a bill on the player's account
 * is more than "Days Overdue Before Theft" past its due date and still unpaid,
 * or guest mining is switched off. Members are never on the list: their
 * overdue bills are chased by the reminders and the outstanding digest.
 *
 * A player is everyone on one SeAT account, or the character alone when SeAT
 * has no account for it. A player has one incident open at a time, named after
 * the character caught mining.
 *
 * Values come from the mining ledger, the same rows the bills are built from.
 * Only whole days are added to an incident, each day once per player.
 */
class TheftDetectionService
{
    /** The day this version first counted mining into incidents. */
    const COUNTING_FROM_KEY = 'theft.counting_from';

    protected $settingsService;
    protected $names;

    public function __construct(
        SettingsManagerService $settingsService,
        CharacterNames $names
    ) {
        $this->settingsService = $settingsService;
        $this->names = $names;
    }

    /**
     * What a run checks against, from the settings. Every method that applies
     * them also takes them, so other values can be tried without changing a
     * setting.
     *
     * @return array{moon_owner: int|null, home: array<int, int>, overdue_days: int, guests_allowed: bool, guests_off_since: Carbon|null}
     */
    public function rules(): array
    {
        $flags = $this->settingsService->getFeatureFlags();
        $moonOwner = (int) $this->settingsService->getSettingForCorporation('general.moon_owner_corporation_id', null, 0);
        $guestsAllowed = (bool) ($flags['allow_guest_mining'] ?? true);

        $home = array_map('intval', $this->settingsService->getHomeCorporationIds());
        if (!$home && $moonOwner) {
            $home = [$moonOwner];
        }

        return [
            'moon_owner' => $moonOwner ?: null,
            'home' => $home,
            'overdue_days' => max(0, (int) ($flags['theft_overdue_days'] ?? 15)),
            'guests_allowed' => $guestsAllowed,
            'guests_off_since' => $guestsAllowed ? null : $this->guestsOffSince(),
        ];
    }

    /**
     * The daily run: add the last whole days of mining to the incidents that
     * are open, take players with nothing overdue off the list, then open
     * incidents for new theft. Running it twice in a day changes nothing.
     *
     * @param int $lookbackDays How far back a new incident may reach
     * @return array{counted: int, removed: Collection, incidents: Collection, new_incidents: int, error?: string}
     */
    public function detectThefts(int $lookbackDays = 15, ?array $rules = null): array
    {
        $rules = $rules ?? $this->rules();

        $result = [
            'counted' => 0,
            'removed' => collect(),
            'incidents' => collect(),
            'new_incidents' => 0,
        ];

        if (!$rules['moon_owner']) {
            Log::warning('TheftDetectionService: No moon owner corporation configured');

            return $result + ['error' => 'No moon owner corporation configured in settings'];
        }

        $observers = $this->ownerObservers($rules['moon_owner']);
        $today = Carbon::today();

        $result['counted'] = $this->countOpenIncidents($rules, $observers, $today);
        $result['removed'] = $this->removePaidUp();
        $result['incidents'] = $this->openNewIncidents($rules, $observers, $today, max(1, $lookbackDays));
        $result['new_incidents'] = $result['incidents']->count();

        Log::info('TheftDetectionService: Completed theft detection', [
            'counted' => $result['counted'],
            'removed' => $result['removed']->count(),
            'new_incidents' => $result['new_incidents'],
        ]);

        return $result;
    }

    /**
     * Players on the list whose outside characters the ledger has recorded
     * mining for in the last $hours, each marked active once per check.
     *
     * The ledger keeps a running total per day, so what is reported is the
     * day's mining so far. It is added to the incident by the daily run once
     * the day is over.
     *
     * @return Collection of ['incident' => TheftIncident, 'new_value' => float, 'mining_date' => string]
     */
    public function monitorActiveThefts(int $hours = 6, ?array $rules = null): Collection
    {
        $rules = $rules ?? $this->rules();
        $active = collect();

        $observers = $rules['moon_owner'] ? $this->ownerObservers($rules['moon_owner']) : [];
        if (!$observers) {
            return $active;
        }

        $since = now()->subHours(max(1, $hours));
        $today = Carbon::today();

        // A recalculation stamps old days with the same time, so only
        // yesterday and today can count as mining again.
        $earliestDay = $today->copy()->subDay()->toDateString();

        foreach ($this->openIncidentsByPlayer() as $player) {
            if (!$this->theftApplies($player['members'], $rules, $today)) {
                continue;
            }

            $outsiders = array_keys($this->outsiders($player['members'], $rules['home']));
            if (!$outsiders) {
                continue;
            }

            $latest = $this->ledger($outsiders, $observers)
                ->where('processed_at', '>=', $since)
                ->where('date', '>=', $earliestDay)
                ->max('date');

            if (!$latest) {
                continue;
            }

            $day = Carbon::parse($latest)->toDateString();
            $value = (float) $this->ledger($outsiders, $observers)
                ->where('date', $day)
                ->sum('total_value');

            $incident = $player['incidents']->first();
            $incident->markAsActiveTheft(0);

            $active->push([
                'incident' => $incident,
                'new_value' => $value,
                'mining_date' => $day,
            ]);

            Log::warning('ACTIVE THEFT IN PROGRESS', [
                'incident_id' => $incident->id,
                'character_id' => $incident->character_id,
                'mining_date' => $day,
                'mined_that_day' => $value,
                'activity_count' => $incident->activity_count,
            ]);
        }

        return $active;
    }

    /**
     * Incidents raised over a bill whose player now has nothing overdue: the
     * ones the next run takes off the list. Reads only.
     *
     * Incidents raised because guest mining was off are not among them.
     * Paying the bill does not undo mining that was not allowed, so a director
     * resolves those.
     */
    public function paidUpIncidents(): Collection
    {
        $paidUp = collect();

        foreach ($this->openIncidentsByPlayer() as $player) {
            $stillOverdue = $this->unpaidBills($player['members'])
                ->contains(fn ($bill) => $bill->isOverdue());

            if ($stillOverdue) {
                continue;
            }

            foreach ($player['incidents'] as $incident) {
                if ($incident->mining_tax_id !== null) {
                    $paidUp->push($incident);
                }
            }
        }

        return $paidUp;
    }

    /**
     * The ledger rows behind an incident: moon ore at the moon owner's
     * refineries by the player's outside characters, over its mining dates.
     */
    public function incidentMining(TheftIncident $incident, ?array $rules = null): Collection
    {
        $rules = $rules ?? $this->rules();

        if (!$rules['moon_owner'] || !$incident->mining_date_from || !$incident->mining_date_to) {
            return collect();
        }

        $observers = $this->ownerObservers($rules['moon_owner']);
        $members = $this->players([$incident->character_id])[(int) $incident->character_id];
        $outsiders = array_keys($this->outsiders($members, $rules['home']));

        if (!$observers || !$outsiders) {
            return collect();
        }

        return MiningLedger::whereIn('character_id', $outsiders)
            ->whereIn('observer_id', $observers)
            ->where('is_moon_ore', true)
            ->whereBetween('date', [
                $incident->mining_date_from->toDateString(),
                $incident->mining_date_to->toDateString(),
            ])
            ->with(['type', 'solarSystem'])
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * Why the next run would or would not open an incident over this
     * character, for the Diagnostic page. Reads only.
     */
    public function explainCharacter(int $characterId, ?array $rules = null): array
    {
        $rules = $rules ?? $this->rules();
        $today = Carbon::today();

        $info = $this->names->info($characterId);
        $members = $this->players([$characterId])[$characterId];
        $outsiders = $this->outsiders($members, $rules['home']);
        $observers = $rules['moon_owner'] ? $this->ownerObservers($rules['moon_owner']) : [];

        $bills = $this->unpaidBills($members);
        $overdue = $bills->filter(fn ($bill) => $bill->isOverdue());
        $pastLine = $bills->filter(fn ($bill) => $this->theftLine($bill, $rules['overdue_days'])->lt($today));

        $open = TheftIncident::onTheftList()
            ->whereIn('character_id', $members)
            ->orderByDesc('id')
            ->first();

        $mined = $observers
            ? $this->ledger([$characterId], $observers)
                ->where('date', '>=', $today->copy()->subDays(30)->toDateString())
                ->groupBy('type_id')
                ->get(['type_id', DB::raw('SUM(quantity) as quantity'), DB::raw('MAX(date) as last_seen')])
            : collect();

        $typeNames = $mined->isEmpty() ? collect() : DB::table('invTypes')
            ->whereIn('typeID', $mined->pluck('type_id'))
            ->pluck('typeName', 'typeID');

        $days = $rules['overdue_days'];

        if (!$rules['moon_owner']) {
            $verdict = 'No moon owner corporation is set, so nothing is checked.';
        } elseif (!isset($outsiders[$characterId])) {
            $verdict = (int) $info['corporation_id'] > 0
                ? 'In a configured corporation, so mining here is never theft.'
                : 'Not placed in a corporation yet, so left out until the lookup places them.';
        } elseif ($open) {
            $verdict = "Already on the theft list (incident #{$open->id}). The daily run adds to it.";
        } elseif ($pastLine->isNotEmpty()) {
            $line = $pastLine->map(fn ($bill) => $this->theftLine($bill, $days))->min();
            $verdict = "{$pastLine->count()} bill(s) more than {$days} days overdue: mining at our refineries after {$line->toDateString()} opens an incident on the next run.";
        } elseif (!$rules['guests_allowed']) {
            $verdict = "Guest mining is switched off: mining at our refineries after {$rules['guests_off_since']->toDateString()} opens an incident on the next run.";
        } elseif ($overdue->isNotEmpty()) {
            $line = $overdue->map(fn ($bill) => $this->theftLine($bill, $days))->min();
            $verdict = "Overdue, but not more than {$days} days yet. Mining after {$line->toDateString()} is theft if the bill is still unpaid then.";
        } else {
            $verdict = 'Outside our corporations with nothing overdue: a guest, billed at guest rates.';
        }

        return [
            'character_id' => $characterId,
            'character_name' => $info['name'],
            'corporation_name' => $info['corporation_name'] ?? null,
            'is_external_miner' => isset($outsiders[$characterId]),
            'player_characters' => count($members),
            'overdue_bills' => $overdue->count(),
            'bills_past_theft_line' => $pastLine->count(),
            'moon_ore_mined_30d' => $mined->map(fn ($row) => [
                'type_id' => (int) $row->type_id,
                'name' => $typeNames[$row->type_id] ?? "Type {$row->type_id}",
                'quantity' => (int) $row->quantity,
                'last_seen' => $row->last_seen,
            ])->values()->all(),
            'existing_incident' => $open ? [
                'id' => $open->id,
                'status' => $open->status,
                'severity' => $open->severity,
                'ore_value' => $open->ore_value,
                'tax_owed' => $open->tax_owed,
            ] : null,
            'verdict' => $verdict,
        ];
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

    /**
     * Add the whole days mined since each open incident was last counted.
     * Only while the mining is still theft: a player who has paid down to
     * bills inside the allowed days is mining as a guest again.
     */
    protected function countOpenIncidents(array $rules, array $observers, Carbon $today): int
    {
        if (!$observers) {
            return 0;
        }

        // Incidents raised before this version gain mining only from the day
        // it first ran, so upgrading never adds older mining to them.
        $countingFrom = $this->countingFrom();
        $counted = 0;

        foreach ($this->openIncidentsByPlayer() as $player) {
            if (!$this->theftApplies($player['members'], $rules, $today)) {
                continue;
            }

            $outsiders = array_keys($this->outsiders($player['members'], $rules['home']));
            if (!$outsiders) {
                continue;
            }

            $incident = $player['incidents']->first();
            $countedTo = $incident->mining_date_to
                ? $incident->mining_date_to->copy()->startOfDay()
                : $incident->incident_date->copy()->startOfDay();

            $rows = $this->dailyMining($outsiders, $observers, $countedTo->max($countingFrom->copy()->subDay()), $today);
            if ($rows->isEmpty()) {
                continue;
            }

            $incident->addCountedMining(
                (int) $rows->sum('quantity'),
                (float) $rows->sum('value'),
                Carbon::parse($rows->max('date'))
            );
            $counted++;
        }

        return $counted;
    }

    /**
     * Take players off the list once nothing on their account is overdue.
     */
    protected function removePaidUp(): Collection
    {
        $removed = $this->paidUpIncidents();

        foreach ($removed as $incident) {
            $incident->removeFromList();

            Log::info('Character removed from theft list (taxes paid)', [
                'character_id' => $incident->character_id,
                'character_name' => $incident->character_name,
                'incident_id' => $incident->id,
            ]);
        }

        return $removed;
    }

    /**
     * Open an incident for each player whose outside characters mined here
     * in the last $lookbackDays while it was theft, unless one is open.
     */
    protected function openNewIncidents(array $rules, array $observers, Carbon $today, int $lookbackDays): Collection
    {
        $created = collect();
        if (!$observers) {
            return $created;
        }

        $windowStart = $today->copy()->subDays($lookbackDays);

        $miners = $this->ledger(null, $observers)
            ->where('date', '>=', $windowStart->toDateString())
            ->where('date', '<', $today->toDateString())
            ->distinct()
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $outsiders = $this->outsiders($miners, $rules['home']);
        if (!$outsiders) {
            return $created;
        }

        $listed = TheftIncident::onTheftList()
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $done = [];
        foreach ($this->players(array_keys($outsiders)) as $members) {
            $player = min($members);
            if (isset($done[$player]) || array_intersect($members, $listed)) {
                continue;
            }
            $done[$player] = true;

            try {
                $incident = $this->openIncident($members, $rules, $observers, $today, $windowStart);
                if ($incident) {
                    $created->push($incident);
                }
            } catch (\Exception $e) {
                Log::error('TheftDetectionService: Failed to check player', [
                    'character_ids' => $members,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    /**
     * The incident for one player, or null when none of their mining in the
     * window was theft.
     */
    protected function openIncident(array $members, array $rules, array $observers, Carbon $today, Carbon $windowStart): ?TheftIncident
    {
        $outsiders = $this->outsiders($members, $rules['home']);
        if (!$outsiders) {
            return null;
        }

        // A day already counted on an earlier incident is never counted again.
        $after = $windowStart->copy()->subDay();
        $lastCounted = TheftIncident::whereIn('character_id', $members)->max('mining_date_to');
        if ($lastCounted) {
            $after = $after->max(Carbon::parse($lastCounted)->startOfDay());
        }

        $days = $rules['overdue_days'];
        $pastLine = $this->unpaidBills($members)
            ->filter(fn ($bill) => $this->theftLine($bill, $days)->lt($today))
            ->sortBy(fn ($bill) => $this->theftLine($bill, $days)->timestamp)
            ->values();

        $rows = collect();
        $reason = null;

        if ($pastLine->isNotEmpty()) {
            $line = $this->theftLine($pastLine->first(), $days);
            $rows = $this->dailyMining(array_keys($outsiders), $observers, $after->copy()->max($line), $today);
            $reason = $rows->isNotEmpty() ? 'overdue' : null;
        }

        if (!$reason && !$rules['guests_allowed']) {
            $rows = $this->dailyMining(array_keys($outsiders), $observers, $after->copy()->max($rules['guests_off_since']), $today);
            $reason = $rows->isNotEmpty() ? 'guests_off' : null;
        }

        if (!$reason) {
            return null;
        }

        // Named after whoever mined the most.
        $characterId = (int) $rows->groupBy('character_id')
            ->map(fn ($characterRows) => $characterRows->sum('value'))
            ->sortDesc()
            ->keys()
            ->first();

        // Kept for good and sent out, so worth a lookup if nobody has one yet.
        $this->names->lookUpNow([$characterId]);
        $info = $this->names->info($characterId);

        $oreValue = round((float) $rows->sum('value'), 2);
        $taxOwed = $reason === 'overdue'
            ? round((float) $pastLine->sum(fn ($bill) => $bill->getRemainingBalance()), 2)
            : 0.0;

        $notes = $reason === 'overdue'
            ? sprintf(
                'Mined our moons with %d bill(s) more than %d days overdue, %s ISK left to pay.',
                $pastLine->count(),
                $days,
                number_format($taxOwed, 0)
            )
            : 'Mined our moons while guest mining was switched off.';

        $dates = $rows->pluck('date')->map(fn ($date) => Carbon::parse($date)->toDateString());

        $incident = $this->createIncident([
            'character_id' => $characterId,
            'character_name' => $info['named'] ? $info['name'] : null,
            'corporation_id' => $outsiders[$characterId],
            'mining_tax_id' => $reason === 'overdue' ? $pastLine->first()->id : null,
            'incident_date' => Carbon::now(),
            'mining_date_from' => $dates->min(),
            'mining_date_to' => $dates->max(),
            'ore_value' => $oreValue,
            'tax_owed' => $taxOwed,
            'quantity_mined' => (int) $rows->sum('quantity'),
            'status' => 'detected',
            'severity' => $this->calculateSeverity($taxOwed, $oreValue),
            'on_theft_list' => true,
            'notes' => $notes,
        ]);

        Log::info('TheftDetectionService: Created theft incident', [
            'incident_id' => $incident->id,
            'character_id' => $characterId,
            'reason' => $reason,
            'severity' => $incident->severity,
            'tax_owed' => $taxOwed,
        ]);

        return $incident;
    }

    /**
     * Whether this player's outside characters mining here is theft today:
     * guest mining is off, or a bill on the account is past its theft line.
     */
    protected function theftApplies(array $members, array $rules, Carbon $today): bool
    {
        if (!$rules['guests_allowed']) {
            return true;
        }

        return $this->unpaidBills($members)
            ->contains(fn ($bill) => $this->theftLine($bill, $rules['overdue_days'])->lt($today));
    }

    /**
     * The last day mining against this bill is still allowed: its due date
     * plus the days overdue allowed. Mining after it is theft.
     */
    protected function theftLine(MiningTax $bill, int $days): Carbon
    {
        return $bill->effectiveDueDate()->copy()->startOfDay()->addDays($days);
    }

    /**
     * Bills on these characters that theft treats as unpaid.
     */
    protected function unpaidBills(array $characterIds): Collection
    {
        return MiningTax::whereIn('character_id', $characterIds)
            ->unpaidForTheft()
            ->get();
    }

    /**
     * Open incidents grouped by player, newest first within each. The newest
     * carries any new count, so a player listed twice before this version is
     * only counted and alerted once.
     *
     * @return array<int, array{members: array<int, int>, incidents: Collection}>
     */
    protected function openIncidentsByPlayer(): array
    {
        $incidents = TheftIncident::onTheftList()->orderByDesc('id')->get();
        if ($incidents->isEmpty()) {
            return [];
        }

        $players = $this->players($incidents->pluck('character_id')->all());

        $grouped = [];
        foreach ($incidents as $incident) {
            $members = $players[(int) $incident->character_id];
            $player = min($members);

            $grouped[$player] ??= ['members' => $members, 'incidents' => collect()];
            $grouped[$player]['incidents']->push($incident);
        }

        return $grouped;
    }

    /**
     * Each character's player: everyone on the same SeAT account, or the
     * character alone when SeAT has no account for it. The same grouping the
     * bills use, so an account's bills are found whichever character holds them.
     *
     * @param array<int, int|string> $characterIds
     * @return array<int, array<int, int>> character_id => the player's character ids
     */
    protected function players(array $characterIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $characterIds)));

        $users = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('refresh_tokens')->whereIn('character_id', $chunk)->get(['character_id', 'user_id']) as $row) {
                $users[(int) $row->character_id] = (int) $row->user_id;
            }
        }

        $byUser = [];
        foreach (array_chunk(array_values(array_unique($users)), 1000) as $chunk) {
            foreach (DB::table('refresh_tokens')->whereIn('user_id', $chunk)->get(['character_id', 'user_id']) as $row) {
                $byUser[(int) $row->user_id][] = (int) $row->character_id;
            }
        }

        $players = [];
        foreach ($ids as $id) {
            $players[$id] = isset($users[$id])
                ? array_values(array_unique($byUser[$users[$id]] ?? [$id]))
                : [$id];
        }

        return $players;
    }

    /**
     * Of these characters, the ones placed in a corporation outside the home
     * ones, with that corporation: SeAT's affiliation first, the plugin's
     * lookups for characters SeAT has none for. A character nobody has placed
     * yet is left out, as an unregistered member is far likelier than a
     * stranger and the lookup places them soon enough.
     *
     * @return array<int, int> character_id => corporation_id
     */
    protected function outsiders(array $characterIds, array $home): array
    {
        $this->names->preload($characterIds);

        $outside = [];
        foreach ($characterIds as $id) {
            $corporationId = (int) ($this->names->info($id)['corporation_id'] ?? 0);

            if ($corporationId > 0 && !in_array($corporationId, $home, true)) {
                $outside[(int) $id] = $corporationId;
            }
        }

        return $outside;
    }

    /**
     * The moon owner's refineries. Tax only covers mining at these, so theft
     * only does too.
     *
     * @return array<int, int>
     */
    protected function ownerObservers(int $moonOwner): array
    {
        return DB::table('corporation_industry_mining_observers')
            ->where('corporation_id', $moonOwner)
            ->pluck('observer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Moon ore in the ledger mined at these refineries, by these characters
     * or by anyone.
     */
    protected function ledger(?array $characterIds, array $observers)
    {
        return DB::table('mining_ledger')
            ->whereNull('deleted_at')
            ->whereIn('observer_id', $observers)
            ->where('is_moon_ore', true)
            ->when($characterIds !== null, fn ($query) => $query->whereIn('character_id', $characterIds));
    }

    /**
     * Moon ore these characters mined at these refineries on the days after
     * $after and before $before, a row per character and day.
     */
    protected function dailyMining(array $characterIds, array $observers, Carbon $after, Carbon $before): Collection
    {
        if (!$characterIds || !$observers) {
            return collect();
        }

        return $this->ledger($characterIds, $observers)
            ->where('date', '>', $after->toDateString())
            ->where('date', '<', $before->toDateString())
            ->groupBy('character_id', 'date')
            ->orderBy('date')
            ->get([
                'character_id',
                'date',
                DB::raw('SUM(quantity) as quantity'),
                DB::raw('SUM(total_value) as value'),
            ]);
    }

    /**
     * The day guest mining was switched off. The setting only changes when
     * someone flips it, so its last change is that day, and mining from the
     * next day on counts.
     */
    protected function guestsOffSince(): Carbon
    {
        $changed = Setting::where('key', 'features.allow_guest_mining')
            ->whereNull('corporation_id')
            ->value('updated_at');

        return $changed ? Carbon::parse($changed)->startOfDay() : Carbon::today();
    }

    /**
     * The day this version first counted mining, recorded on its first run.
     */
    protected function countingFrom(): Carbon
    {
        $stored = Setting::where('key', self::COUNTING_FROM_KEY)
            ->whereNull('corporation_id')
            ->orderBy('id')
            ->value('value');

        if ($stored) {
            try {
                return Carbon::parse($stored)->startOfDay();
            } catch (\Throwable $e) {
                Log::warning('TheftDetectionService: ' . self::COUNTING_FROM_KEY . ' unreadable, counting from today', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $today = Carbon::today();
        Setting::setValue(self::COUNTING_FROM_KEY, $today->toDateString(), 'string', null);

        return $today;
    }
}
