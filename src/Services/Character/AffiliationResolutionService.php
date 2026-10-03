<?php

namespace MiningManager\Services\Character;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AffiliationResolutionService
 *
 * Deferred character-to-corporation resolution.
 *
 * Writes resolved affiliations to the mining_manager_character_affiliations
 * cache table so the dashboard can read them without live ESI calls.
 *
 * Resolution flow per character:
 *   1. Check local cache table (by character_id)
 *   2. If missing or expired, resolve via ExternalCharacterService
 *   3. Write result back to local table with TTL
 *   4. Also store in Laravel cache (1h) as a fast path
 */
class AffiliationResolutionService
{
    /**
     * Default TTL for a cached affiliation entry (hours).
     * Corporations change infrequently — 24h is reasonable.
     */
    public const CACHE_TTL_HOURS = 24;

    /**
     * Batch size for the scheduled resolution command.
     * ESI allows ~5 req/s per key; we batch to ~3 concurrent batches.
     */
    public const RESOLVE_BATCH_SIZE = 20;

    protected ExternalCharacterService $externalService;

    public function __construct(ExternalCharacterService $externalService)
    {
        $this->externalService = $externalService;
    }

    // ---- Public API ----

    /**
     * Resolve affiliation for a single character.
     *
     * Returns [
     *   'character_id' => int,
     *   'corporation_id' => int|null,
     *   'corporation_name' => string|null,
     *   'source' => string,
     *   'resolved' => bool,
     * ]
     */
    public function resolve(int $characterId): array
    {
        // Fast path: Laravel cache (set by batch resolution)
        $cacheKey = "affiliation_{$characterId}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            $cached['character_id'] = $characterId;
            $cached['resolved'] = true;
            return $cached;
        }

        // Check local DB cache
        $row = DB::table('mining_manager_character_affiliations')
            ->where('character_id', $characterId)
            ->first();

        if ($row && $row->expires_at && Carbon::parse($row->expires_at)->isFuture()) {
            $result = [
                'character_id' => $characterId,
                'corporation_id' => $row->corporation_id,
                'corporation_name' => $row->corporation_name,
                'source' => $row->source,
                'resolved' => true,
            ];

            // Prime the Laravel cache too
            Cache::put($cacheKey, $result, 3600);

            return $result;
        }

        // Resolve via external services (may trigger ESI/zKill/EVEWho)
        $info = $this->externalService->getCharacterInfo($characterId);

        $result = [
            'character_id' => $characterId,
            'corporation_id' => $info['corporation_id'],
            'corporation_name' => $info['corporation_name'],
            'source' => 'esi',
            'resolved' => true,
        ];

        // Persist to local cache table
        $this->store($characterId, $result['corporation_id'], $result['corporation_name'], $result['source']);

        // Prime Laravel cache
        Cache::put($cacheKey, $result, 3600);

        return $result;
    }

    /**
     * Resolve a batch of characters in groups to avoid overwhelming ESI.
     *
     * @param int[] $characterIds
     * @return array Keyed by character_id
     */
    public function resolveBatch(array $characterIds): array
    {
        $results = [];

        foreach (array_chunk($characterIds, self::RESOLVE_BATCH_SIZE) as $chunk) {
            foreach ($chunk as $charId) {
                $results[$charId] = $this->resolve($charId);
            }

            // Small delay between batches to respect rate limits
            usleep(200000); // 200ms
        }

        return $results;
    }

    /**
     * Find characters in the mining ledger that have no affiliation record
     * and are not in the home corporations. Returns character IDs.
     */
    public function findUnresolvedGuests(array $homeCorporationIds): array
    {
        // All distinct miner character IDs from the ledger
        $minerIds = DB::table('mining_ledger')
            ->distinct()
            ->pluck('character_id')
            ->toArray();

        if (empty($minerIds)) {
            return [];
        }

        // Characters already in character_affiliations (SeAT-known)
        $affiliatedIds = DB::table('character_affiliations')
            ->whereIn('character_id', $minerIds)
            ->pluck('character_id')
            ->toArray();

        // Characters already cached locally
        $cachedIds = DB::table('mining_manager_character_affiliations')
            ->where(function ($q) {
                $q->where('expires_at', '>', now())
                  ->orWhereNull('expires_at');
            })
            ->whereIn('character_id', $minerIds)
            ->pluck('character_id')
            ->toArray();

        // Characters that are guests (in a non-home corp according to SeAT)
        $guestIds = DB::table('character_affiliations')
            ->whereIn('character_id', $minerIds)
            ->whereNotIn('corporation_id', $homeCorporationIds)
            ->pluck('character_id')
            ->toArray();

        // Unresolved = not affiliated in SeAT AND not cached locally AND not already known guest
        $unresolved = array_values(array_diff(
            $minerIds,
            $affiliatedIds,
            $cachedIds,
            $guestIds
        ));

        return $unresolved;
    }

    /**
     * Manually store a resolved affiliation in the local cache table.
     */
    public function store(int $characterId, ?int $corporationId, ?string $corporationName, string $source = 'esi'): void
    {
        DB::table('mining_manager_character_affiliations')->upsert(
            [
                'character_id' => $characterId,
                'corporation_id' => $corporationId,
                'corporation_name' => $corporationName,
                'resolved_at' => now(),
                'expires_at' => now()->addHours(self::CACHE_TTL_HOURS),
                'source' => $source,
            ],
            ['character_id']
        );
    }

    /**
     * Check whether a character has a cached affiliation entry.
     */
    public function hasCached(int $characterId): bool
    {
        $cacheKey = "affiliation_{$characterId}";
        if (Cache::has($cacheKey)) {
            return true;
        }

        return (bool) DB::table('mining_manager_character_affiliations')
            ->where('character_id', $characterId)
            ->where(function ($q) {
                $q->where('expires_at', '>', now())
                  ->orWhereNull('expires_at');
            })
            ->exists();
    }

    /**
     * Clear the local cache for a character (e.g. when corporation changed).
     */
    public function clear(int $characterId): void
    {
        Cache::forget("affiliation_{$characterId}");
        DB::table('mining_manager_character_affiliations')
            ->where('character_id', $characterId)
            ->delete();
    }

    /**
     * Clear all cached affiliations.
     */
    public function clearAll(): void
    {
        DB::table('mining_manager_character_affiliations')->truncate();
    }

    /**
     * Get statistics about the cache.
     */
    public function getStats(): array
    {
        $total = DB::table('mining_manager_character_affiliations')->count();
        $expired = DB::table('mining_manager_character_affiliations')
            ->where('expires_at', '<', now())
            ->whereNotNull('expires_at')
            ->count();
        $valid = $total - $expired;

        return [
            'total' => $total,
            'valid' => $valid,
            'expired' => $expired,
        ];
    }
}
