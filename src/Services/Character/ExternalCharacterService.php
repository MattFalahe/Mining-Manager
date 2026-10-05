<?php

namespace MiningManager\Services\Character;

/**
 * Character and corporation details from outside SeAT, for background work.
 *
 * The lookups themselves, ESI with EVEWho and zKillboard behind it, live in
 * AffiliationResolutionService, which keeps every answer for pages to read.
 * Each method here looks a character up when nothing is stored yet, so it
 * calls out: nothing that builds a page should use it.
 */
class ExternalCharacterService
{
    protected AffiliationResolutionService $resolver;

    public function __construct(AffiliationResolutionService $resolver)
    {
        $this->resolver = $resolver;
    }

    public function getCharacterName(int $characterId): ?string
    {
        return $this->lookup($characterId)->character_name ?? null;
    }

    public function getCharacterCorporationId(int $characterId): ?int
    {
        $corporationId = $this->lookup($characterId)->corporation_id ?? null;

        return $corporationId ? (int) $corporationId : null;
    }

    public function getCorporationName(int $corporationId): ?string
    {
        return $this->resolver->lookUpCorporationName($corporationId);
    }

    /**
     * @return array{name: string, corporation_id: ?int, corporation_name: string, is_registered: bool}
     */
    public function getCharacterInfo(int $characterId): array
    {
        $row = $this->lookup($characterId);

        return [
            'name' => ($row->character_name ?? null) ?: "Character {$characterId}",
            'corporation_id' => !empty($row->corporation_id) ? (int) $row->corporation_id : null,
            'corporation_name' => ($row->corporation_name ?? null) ?: 'Unknown Corporation',
            'is_registered' => false,
        ];
    }

    /**
     * Look the character up again on the next background run.
     */
    public function clearCharacterCache(int $characterId): void
    {
        $this->resolver->forget($characterId);
    }

    /**
     * Corporation names are read from SeAT's tables and the stored lookups,
     * so there is nothing of ours to clear.
     */
    public function clearCorporationCache(int $corporationId): void
    {
    }

    /**
     * What is stored for the character, looked up there and then if nothing is.
     */
    private function lookup(int $characterId): ?object
    {
        $row = $this->resolver->known([$characterId])[$characterId] ?? null;

        if ($row === null) {
            $this->resolver->resolve([$characterId]);
            $row = $this->resolver->known([$characterId])[$characterId] ?? null;
        }

        return $row;
    }
}
