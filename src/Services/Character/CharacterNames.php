<?php

namespace MiningManager\Services\Character;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Names and corporations for characters, for every page, chart, export and
 * notice in the plugin.
 *
 * SeAT's own tables come first, then the plugin's lookups, in one batch
 * however many characters a page shows. Nothing here calls out on its own: a
 * character nobody has looked up yet is shown as in progress and asked for,
 * and the background lookup fills it in within a minute or so. Background
 * work that needs a name before it goes on, for a message or a record kept
 * for good, asks for it with lookUpNow().
 *
 * Rows that carry a character_id say so as they load (HasCharacterName), so
 * the first name a page asks for brings in the names for everything it
 * loaded, in the same batch.
 */
class CharacterNames
{
    /** The most ids one query asks for. */
    private const CHUNK = 1000;

    /** Keys a remembered result is kept under, next to what was still being looked up. */
    private const CACHED_VALUE = 'mm_names_value';
    private const CACHED_WAITING = 'mm_names_waiting';

    private AffiliationResolutionService $resolver;

    /** @var array<int, array> character_id => what is known */
    private array $info = [];

    /** @var array<int, true> loaded on this page, not named yet */
    private array $wanted = [];

    /** @var array<int, int> characters shown as in progress, in the order they were */
    private array $shownPending = [];

    public function __construct(AffiliationResolutionService $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Note a character this page has loaded, so it is named in the same batch
     * as the rest.
     */
    public function want($characterId): void
    {
        $id = (int) $characterId;
        if ($id > 0 && !isset($this->info[$id])) {
            $this->wanted[$id] = true;
        }
    }

    /**
     * Look these characters up now, together with everything loaded so far.
     *
     * @param iterable<int|string|null> $characterIds
     */
    public function preload(iterable $characterIds): void
    {
        foreach ($characterIds as $id) {
            $this->want($id);
        }

        $ids = array_keys($this->wanted);
        $this->wanted = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->load($chunk);
        }
    }

    /**
     * Look up now whoever among these characters nobody has looked up yet.
     * For background work that needs the name before it goes on, like a
     * message or a record kept for good. On a page this only reads what is
     * there, as a page never waits on ESI.
     *
     * @param iterable<int|string|null> $characterIds
     */
    public function lookUpNow(iterable $characterIds): void
    {
        $ids = [];
        foreach ($characterIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
        $ids = array_keys($ids);

        $this->preload($ids);

        if (!app()->runningInConsole()) {
            return;
        }

        $unknown = array_values(array_filter($ids, fn ($id) => $this->info[$id]['pending'] ?? false));
        if (!$unknown) {
            return;
        }

        $this->resolver->resolve($unknown);
        foreach ($unknown as $id) {
            unset($this->info[$id]);
        }
        $this->preload($unknown);
    }

    /**
     * Everything known about a character:
     * character_id, name, corporation_id, corporation_name, alliance_id,
     * registered (SeAT has it), named (a real name was found) and pending
     * (still being looked up).
     */
    public function info($characterId): array
    {
        $info = $this->lookup($characterId);
        if ($info['pending']) {
            $this->shownPending[] = $info['character_id'];
        }

        return $info;
    }

    public function name($characterId): string
    {
        return $this->info($characterId)['name'];
    }

    public function corporationName($characterId): string
    {
        return $this->info($characterId)['corporation_name'];
    }

    /**
     * The name, or "Character 123" while it is still being looked up. For
     * files, receipts and anything stored, where "in progress" would outlive
     * the lookup it describes.
     */
    public function nameOrId($characterId): string
    {
        $info = $this->lookup($characterId);

        return $info['pending'] ? 'Character ' . $info['character_id'] : $info['name'];
    }

    /**
     * @param iterable<int|string|null> $characterIds
     * @return array<int, array> character_id => info, in the order asked for
     */
    public function many(iterable $characterIds): array
    {
        $ids = [];
        foreach ($characterIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        $this->preload(array_keys($ids));

        $many = [];
        foreach (array_keys($ids) as $id) {
            $many[$id] = $this->info($id);
        }

        return $many;
    }

    /**
     * Characters this request showed as in progress.
     *
     * @return array<int, int>
     */
    public function shownPending(): array
    {
        return array_values(array_unique($this->shownPending));
    }

    /**
     * Cache what a page shows, but not past the names in it. A result built
     * while some characters were still being looked up is rebuilt as soon as
     * they are in. Until then they are asked for again, so the page still
     * says they are coming and refreshes itself when they are.
     *
     * @param \DateTimeInterface|\DateInterval|int $ttl
     */
    public function remember(string $key, $ttl, Closure $build)
    {
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists(self::CACHED_VALUE, $cached)) {
            $waiting = $cached[self::CACHED_WAITING] ?? [];
            if (!$waiting) {
                return $cached[self::CACHED_VALUE];
            }

            $still = $this->resolver->stillPending($waiting);
            if ($still) {
                $this->resolver->request($still);
                $this->shownPending = array_merge($this->shownPending, $still);

                return $cached[self::CACHED_VALUE];
            }
        }

        $before = count($this->shownPending);
        $value = $build();
        $waiting = array_values(array_unique(array_slice($this->shownPending, $before)));

        Cache::put($key, [self::CACHED_VALUE => $value, self::CACHED_WAITING => $waiting], $ttl);

        return $value;
    }

    private function lookup($characterId): array
    {
        $id = (int) $characterId;

        if (!isset($this->info[$id])) {
            $this->preload([$id]);
        }

        return $this->info[$id] ?? $this->unnamed($id);
    }

    /**
     * Name a chunk of characters: SeAT's character records and the names it
     * has seen, then the plugin's lookups. Corporations come from SeAT's
     * affiliations first.
     *
     * @param array<int, int> $ids
     */
    private function load(array $ids): void
    {
        $names = $this->pluck('character_infos', 'character_id', 'name', $ids);
        $registered = $names;

        $seen = array_values(array_diff($ids, array_keys($names)));
        if ($seen) {
            $names += $this->pluck('universe_names', 'entity_id', 'name', $seen, ['category' => 'character']);
        }

        $affiliations = [];
        try {
            foreach (DB::table('character_affiliations')
                ->whereIn('character_id', $ids)
                ->get(['character_id', 'corporation_id', 'alliance_id']) as $row) {
                $affiliations[(int) $row->character_id] = $row;
            }
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not read character affiliations: ' . $e->getMessage());
        }

        // The plugin's own lookups, for anyone SeAT cannot name or place.
        $needLookup = array_values(array_filter($ids, fn ($id) => !isset($names[$id]) || !isset($affiliations[$id])));
        $lookedUp = $needLookup ? $this->resolver->known($needLookup) : [];

        $unknown = array_values(array_filter($ids, fn ($id) => !isset($names[$id]) && !isset($lookedUp[$id])));
        if ($unknown) {
            $this->resolver->request($unknown);
        }

        $corporationIds = [];
        foreach ($ids as $id) {
            $corporationId = isset($affiliations[$id])
                ? (int) $affiliations[$id]->corporation_id
                : (int) ($lookedUp[$id]->corporation_id ?? 0);
            if ($corporationId > 0) {
                $corporationIds[$corporationId] = true;
            }
        }
        $corporationNames = $this->corporationNames(array_keys($corporationIds));

        $pendingCharacter = trans('mining-manager::common.character_pending');
        $pendingCorporation = trans('mining-manager::common.corporation_pending');

        foreach ($ids as $id) {
            $row = $lookedUp[$id] ?? null;
            $pending = !isset($names[$id]) && $row === null;

            $name = $names[$id] ?? ($row && $row->character_name ? $row->character_name : null);

            $corporationId = isset($affiliations[$id])
                ? (int) $affiliations[$id]->corporation_id
                : ($row && $row->corporation_id ? (int) $row->corporation_id : null);
            $allianceId = isset($affiliations[$id])
                ? (!empty($affiliations[$id]->alliance_id) ? (int) $affiliations[$id]->alliance_id : null)
                : ($row && !empty($row->alliance_id) ? (int) $row->alliance_id : null);

            $corporationName = $corporationId
                ? ($corporationNames[$corporationId] ?? ($row && (int) $row->corporation_id === $corporationId ? $row->corporation_name : null))
                : null;

            $this->info[$id] = [
                'character_id' => $id,
                'name' => $name ?? ($pending ? $pendingCharacter : "Character {$id}"),
                'corporation_id' => $corporationId,
                'corporation_name' => $corporationName ?? ($pending ? $pendingCorporation : 'Unknown Corporation'),
                'alliance_id' => $allianceId,
                'registered' => isset($registered[$id]),
                'named' => $name !== null,
                'pending' => $pending,
            ];
        }
    }

    /**
     * Corporation names from SeAT's corporation records, the names it has
     * seen, then any character the plugin's lookups placed in it.
     *
     * @param array<int, int> $corporationIds
     * @return array<int, string>
     */
    private function corporationNames(array $corporationIds): array
    {
        if (!$corporationIds) {
            return [];
        }

        $names = $this->pluck('corporation_infos', 'corporation_id', 'name', $corporationIds);

        $rest = array_values(array_diff($corporationIds, array_keys($names)));
        if ($rest) {
            $names += $this->pluck('universe_names', 'entity_id', 'name', $rest, ['category' => 'corporation']);
        }

        $rest = array_values(array_diff($corporationIds, array_keys($names)));
        if ($rest) {
            $names += $this->pluck(AffiliationResolutionService::TABLE, 'corporation_id', 'corporation_name', $rest);
        }

        return $names;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, string> id => value, empty values left out
     */
    private function pluck(string $table, string $key, string $column, array $ids, array $where = []): array
    {
        try {
            $query = DB::table($table)->whereIn($key, $ids)->whereNotNull($column);
            foreach ($where as $field => $value) {
                $query->where($field, $value);
            }

            $found = [];
            foreach ($query->pluck($column, $key) as $id => $value) {
                if ($value !== null && $value !== '') {
                    $found[(int) $id] = (string) $value;
                }
            }

            return $found;
        } catch (\Throwable $e) {
            Log::warning("Mining Manager: could not read names from {$table}: " . $e->getMessage());

            return [];
        }
    }

    private function unnamed(int $id): array
    {
        return [
            'character_id' => $id,
            'name' => "Character {$id}",
            'corporation_id' => null,
            'corporation_name' => 'Unknown Corporation',
            'alliance_id' => null,
            'registered' => false,
            'named' => false,
            'pending' => false,
        ];
    }
}
