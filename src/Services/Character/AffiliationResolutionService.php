<?php

namespace MiningManager\Services\Character;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Names and corporations for characters SeAT does not know.
 *
 * SeAT only keeps characters it has tokens or affiliations for, so a visiting
 * miner has no name or corporation anywhere in its tables. Everything the
 * plugin knows about those characters comes from here, and pages only ever
 * read it: a character nobody has looked up yet is asked for and shown as in
 * progress, and the lookup itself runs in the background, so a slow or broken
 * ESI never holds up or breaks a page.
 *
 * ESI is asked first, up to 1000 characters a request. When ESI is down, or
 * the error budget it shares with SeAT's own jobs is running low, a few
 * characters a run go to EVEWho and then zKillboard instead. Those answers
 * are looked at again sooner, so ESI replaces them on its next good run.
 */
class AffiliationResolutionService
{
    public const TABLE = 'mining_manager_character_affiliations';

    public const PENDING = 'pending';
    public const INVALID = 'invalid';

    /** How long an answer from ESI stands before it is looked at again. */
    public const ESI_TTL_HOURS = 24;

    /** A fallback answer is looked at again sooner, so ESI can replace it. */
    public const FALLBACK_TTL_HOURS = 6;

    /** An id ESI rejects is not sent again for this long. */
    public const INVALID_TTL_DAYS = 30;

    /** The most ids ESI takes in one affiliation or names request. */
    public const ESI_BATCH = 1000;

    /** Characters one run may send to EVEWho and zKillboard, a request each. */
    public const FALLBACK_PER_RUN = 20;

    /**
     * Stop calling ESI once the error budget it reports drops below this. The
     * budget is per IP and shared with SeAT's own authenticated jobs.
     */
    public const ESI_ERROR_BUDGET_FLOOR = 15;

    private const ESI = 'https://esi.evetech.net/latest';

    private const USER_AGENT = 'SeAT Mining Manager (https://github.com/MattFalahe/Mining-Manager)';

    /** Set when ESI says the error budget is low: no ESI calls until then. */
    private ?Carbon $esiPausedUntil = null;

    /** Characters asked for while building this page. */
    private array $requested = [];

    // ---------------------------------------------------------------- reading

    /**
     * What has been found for each character, from the table only. Characters
     * still waiting for a first answer are left out; an id ESI rejected comes
     * back with source "invalid".
     *
     * @param array<int, int|string> $characterIds
     * @return array<int, object> character_id => row
     */
    public function known(array $characterIds): array
    {
        $ids = self::ids($characterIds);
        if (!$ids) {
            return [];
        }

        try {
            $rows = DB::table(self::TABLE)
                ->whereIn('character_id', $ids)
                ->where('source', '!=', self::PENDING)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not read looked-up characters: ' . $e->getMessage());

            return [];
        }

        $known = [];
        foreach ($rows as $row) {
            $known[(int) $row->character_id] = $row;
        }

        return $known;
    }

    /**
     * Of these characters, the ones SeAT has no affiliation for that were found
     * in a corporation outside the home ones. Characters nobody has placed yet
     * are not in it: unknown is treated as a member, as before.
     *
     * @param array<int, int|string> $characterIds
     * @param array<int, int|string> $homeCorporationIds
     * @return array<int, int>
     */
    public function outsideHome(array $characterIds, array $homeCorporationIds): array
    {
        $ids = self::ids($characterIds);
        if (!$ids) {
            return [];
        }

        $home = array_map('intval', $homeCorporationIds);

        try {
            $seat = DB::table('character_affiliations')
                ->whereIn('character_id', $ids)
                ->pluck('character_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            $seat = [];
        }

        $outside = [];
        foreach ($this->known(array_diff($ids, $seat)) as $id => $row) {
            if (!empty($row->corporation_id) && !in_array((int) $row->corporation_id, $home, true)) {
                $outside[] = $id;
            }
        }

        return $outside;
    }

    /**
     * A corporation's name from what is already stored: SeAT's own copy first,
     * then any character of ours already placed in it.
     */
    public function corporationName(?int $corporationId): ?string
    {
        if (!$corporationId) {
            return null;
        }

        try {
            return DB::table('corporation_infos')->where('corporation_id', $corporationId)->value('name')
                ?: DB::table(self::TABLE)
                    ->where('corporation_id', $corporationId)
                    ->whereNotNull('corporation_name')
                    ->value('corporation_name');
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------- asking

    /**
     * Ask for characters a page could not show yet. The next run of
     * mining-manager:resolve-characters picks them up, within a minute or so.
     *
     * @param array<int, int|string> $characterIds
     */
    public function request(array $characterIds): void
    {
        $ids = self::ids($characterIds);
        if (!$ids) {
            return;
        }

        $this->requested = array_values(array_unique(array_merge($this->requested, $ids)));

        try {
            $have = DB::table(self::TABLE)
                ->whereIn('character_id', $ids)
                ->pluck('character_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $new = array_values(array_diff($ids, $have));

            if ($new) {
                $now = Carbon::now();
                DB::table(self::TABLE)->insertOrIgnore(array_map(fn ($id) => [
                    'character_id' => $id,
                    'source' => self::PENDING,
                    'requested_at' => $now,
                    'expires_at' => $now,
                ], $new));
            }
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not ask for character lookups: ' . $e->getMessage());
        }
    }

    /**
     * Characters asked for while building this page, for the in-progress
     * notice.
     *
     * @return array<int, int>
     */
    public function requestedThisPage(): array
    {
        return $this->requested;
    }

    /**
     * Which of these characters are still waiting for a first answer.
     *
     * @param array<int, int|string> $characterIds
     * @return array<int, int>
     */
    public function stillPending(array $characterIds): array
    {
        $ids = self::ids($characterIds);
        if (!$ids) {
            return [];
        }

        try {
            $answered = DB::table(self::TABLE)
                ->whereIn('character_id', $ids)
                ->where('source', '!=', self::PENDING)
                ->pluck('character_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            return $ids;
        }

        return array_values(array_diff($ids, $answered));
    }

    // ---------------------------------------------------------------- looking up, background only

    /**
     * Who needs looking up: characters pages asked for first, then miners SeAT
     * has no affiliation for and nobody has looked up yet, then answers due
     * another look.
     *
     * @return array<int, int>
     */
    public function due(bool $requestedOnly = false): array
    {
        $requested = DB::table(self::TABLE)
            ->where('source', self::PENDING)
            ->orderBy('requested_at')
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($requestedOnly) {
            return $requested;
        }

        $miners = DB::table('mining_ledger')
            ->distinct()
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $placed = [];
        foreach (array_chunk($miners, self::ESI_BATCH) as $chunk) {
            foreach (DB::table('character_affiliations')->whereIn('character_id', $chunk)->pluck('character_id') as $id) {
                $placed[(int) $id] = true;
            }
            foreach (DB::table(self::TABLE)->whereIn('character_id', $chunk)->pluck('character_id') as $id) {
                $placed[(int) $id] = true;
            }
        }
        $unseen = array_values(array_filter($miners, fn ($id) => !isset($placed[$id])));

        $stale = DB::table(self::TABLE)
            ->where('source', '!=', self::PENDING)
            ->where('expires_at', '<=', Carbon::now())
            ->orderBy('expires_at')
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($requested, $unseen, $stale)));
    }

    /**
     * Look characters up and keep what is found. For the scheduled command and
     * other background work only: it calls out to ESI and the fallbacks.
     *
     * @param array<int, int|string> $characterIds
     * @return array{esi: int, fallback: int, invalid: int, left: int}
     */
    public function resolve(array $characterIds): array
    {
        $ids = self::ids($characterIds);
        $counts = ['esi' => 0, 'fallback' => 0, 'invalid' => 0, 'left' => 0];
        $unanswered = [];

        foreach (array_chunk($ids, self::ESI_BATCH) as $chunk) {
            [$found, $invalid, $missed] = $this->esiAffiliations($chunk);
            $names = $found
                ? $this->esiNames(array_merge(array_keys($found), array_column($found, 'corporation_id')))
                : [];

            foreach ($found as $id => $answer) {
                $this->store($id, [
                    'character_name' => $names[$id] ?? null,
                    'corporation_id' => $answer['corporation_id'],
                    'corporation_name' => $names[$answer['corporation_id']] ?? null,
                    'alliance_id' => $answer['alliance_id'],
                ], 'esi', Carbon::now()->addHours(self::ESI_TTL_HOURS));
                $counts['esi']++;
            }

            foreach ($invalid as $id) {
                $this->store($id, [], self::INVALID, Carbon::now()->addDays(self::INVALID_TTL_DAYS));
                $counts['invalid']++;
            }

            $unanswered = array_merge($unanswered, $missed);
        }

        // Whoever ESI could not answer, a few a run, from the community copies.
        foreach (array_slice($unanswered, 0, self::FALLBACK_PER_RUN) as $id) {
            $answer = $this->evewho($id) ?? $this->zkillboard($id);
            if ($answer) {
                $this->store($id, $answer, $answer['source'], Carbon::now()->addHours(self::FALLBACK_TTL_HOURS));
                $counts['fallback']++;
            }
        }

        $counts['left'] = count($unanswered) - $counts['fallback'];

        return $counts;
    }

    /**
     * A corporation's name from what is stored, or else from ESI. Background
     * work only.
     */
    public function lookUpCorporationName(int $corporationId): ?string
    {
        return $this->corporationName($corporationId)
            ?: ($this->esiNames([$corporationId])[$corporationId] ?? null);
    }

    /**
     * Drop what is stored for a character, so the next run looks it up again.
     */
    public function forget(int $characterId): void
    {
        try {
            DB::table(self::TABLE)->where('character_id', $characterId)->delete();
        } catch (\Throwable $e) {
            Log::warning("Mining Manager: could not clear the lookup for character {$characterId}: " . $e->getMessage());
        }
    }

    /**
     * How many characters the table holds, by where the answer came from.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        return DB::table(self::TABLE)
            ->select('source', DB::raw('COUNT(*) as total'))
            ->groupBy('source')
            ->pluck('total', 'source')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * ESI's affiliations for these characters. One id ESI does not accept
     * fails the whole request with a 400, so the batch is halved until the bad
     * one is alone, and it is marked so it is not sent again.
     *
     * @param array<int, int> $ids
     * @return array{0: array<int, array{corporation_id: int, alliance_id: ?int}>, 1: array<int, int>, 2: array<int, int>}
     *         found, invalid, not answered
     */
    private function esiAffiliations(array $ids): array
    {
        $response = $this->esiPost('/characters/affiliation/', $ids);

        if ($response === null) {
            return [[], [], $ids];
        }

        if ($response->status() === 400) {
            if (count($ids) === 1) {
                return [[], $ids, []];
            }

            $half = intdiv(count($ids), 2);
            [$found1, $invalid1, $missed1] = $this->esiAffiliations(array_slice($ids, 0, $half));
            [$found2, $invalid2, $missed2] = $this->esiAffiliations(array_slice($ids, $half));

            return [$found1 + $found2, array_merge($invalid1, $invalid2), array_merge($missed1, $missed2)];
        }

        if (!$response->successful()) {
            return [[], [], $ids];
        }

        $found = [];
        foreach ((array) $response->json() as $row) {
            if (isset($row['character_id'], $row['corporation_id'])) {
                $found[(int) $row['character_id']] = [
                    'corporation_id' => (int) $row['corporation_id'],
                    'alliance_id' => isset($row['alliance_id']) ? (int) $row['alliance_id'] : null,
                ];
            }
        }

        return [$found, [], array_values(array_diff($ids, array_keys($found)))];
    }

    /**
     * Names for characters and corporations ESI just answered for. A name is
     * nice to have, so a failure here leaves names empty rather than retrying.
     *
     * @param array<int, int> $ids
     * @return array<int, string> id => name
     */
    private function esiNames(array $ids): array
    {
        $response = $this->esiPost('/universe/names/', self::ids($ids));

        if ($response === null || !$response->successful()) {
            return [];
        }

        $names = [];
        foreach ((array) $response->json() as $row) {
            if (isset($row['id'], $row['name'])) {
                $names[(int) $row['id']] = (string) $row['name'];
            }
        }

        return $names;
    }

    /**
     * A tokenless ESI POST. Once ESI says the error budget is low, or is
     * already limiting us, no more calls go out until its error window resets.
     */
    private function esiPost(string $path, array $body)
    {
        if (!$body || ($this->esiPausedUntil && Carbon::now()->lt($this->esiPausedUntil))) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['Accept' => 'application/json', 'User-Agent' => self::USER_AGENT])
                ->post(self::ESI . $path . '?datasource=tranquility', array_values($body));
        } catch (\Throwable $e) {
            Log::info('Mining Manager: ESI did not answer a character lookup: ' . $e->getMessage());

            return null;
        }

        $remain = $response->header('X-Esi-Error-Limit-Remain');
        if ($response->status() === 420 || ($remain !== null && $remain !== '' && (int) $remain < self::ESI_ERROR_BUDGET_FLOOR)) {
            $this->esiPausedUntil = Carbon::now()->addSeconds(max(1, (int) ($response->header('X-Esi-Error-Limit-Reset') ?: 60)));
            Log::info('Mining Manager: ESI error budget is low, character lookups use the fallbacks until it resets');
        }

        return $response->status() === 420 ? null : $response;
    }

    /**
     * EVEWho's copy of a character: name, corporation and alliance in info[0].
     */
    private function evewho(int $characterId): ?array
    {
        $info = $this->getJson("https://evewho.com/api/character/{$characterId}")['info'][0] ?? null;

        if (!is_array($info) || empty($info['corporation_id'])) {
            return null;
        }

        return [
            'source' => 'evewho',
            'character_name' => $info['name'] ?? null,
            'corporation_id' => (int) $info['corporation_id'],
            'alliance_id' => !empty($info['alliance_id']) ? (int) $info['alliance_id'] : null,
        ];
    }

    /**
     * zKillboard's stats for a character, whose info block carries its name,
     * corporation and alliance. The killmail endpoint has no names, and a
     * killmail's corporation is only as of that kill, so it is not used.
     */
    private function zkillboard(int $characterId): ?array
    {
        $info = $this->getJson("https://zkillboard.com/api/stats/characterID/{$characterId}/", ['Accept-Encoding' => 'gzip'])['info'] ?? null;

        if (!is_array($info) || empty($info['corporationID'])) {
            return null;
        }

        return [
            'source' => 'zkillboard',
            'character_name' => $info['name'] ?? null,
            'corporation_id' => (int) $info['corporationID'],
            'alliance_id' => !empty($info['allianceID']) ? (int) $info['allianceID'] : null,
        ];
    }

    private function getJson(string $url, array $headers = []): ?array
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['Accept' => 'application/json', 'User-Agent' => self::USER_AGENT] + $headers)
                ->get($url);

            return $response->successful() && is_array($response->json()) ? $response->json() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Keep an answer. A name that did not come back this time keeps the one
     * already stored; a corporation always comes with its own name, so a move
     * never leaves the old corporation's name behind.
     */
    private function store(int $characterId, array $answer, string $source, Carbon $expires): void
    {
        $now = Carbon::now();
        $values = [
            'source' => $source,
            'requested_at' => null,
            'resolved_at' => $now,
            'expires_at' => $expires,
        ];

        if (array_key_exists('corporation_id', $answer)) {
            $values['corporation_id'] = $answer['corporation_id'];
            $values['corporation_name'] = $answer['corporation_name'] ?? $this->corporationName($answer['corporation_id']);
            $values['alliance_id'] = $answer['alliance_id'] ?? null;
        }

        if (!empty($answer['character_name'])) {
            $values['character_name'] = $answer['character_name'];
        }

        try {
            DB::table(self::TABLE)->updateOrInsert(['character_id' => $characterId], $values);
        } catch (\Throwable $e) {
            Log::warning("Mining Manager: could not keep the lookup for character {$characterId}: " . $e->getMessage());
        }
    }

    /**
     * @param array<int, int|string|null> $ids
     * @return array<int, int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
    }
}
