<?php

namespace MiningManager\Services\Moon;

/**
 * Finding one structure in the game's notifications, in the raw YAML text
 * SeAT keeps in character_notifications.
 *
 * A notification that also carries structureShowInfoData writes the id once
 * with a YAML anchor and points back to it, so the line reads
 * "structureID: &id001 1032717532381". Matching on "structureID: " followed by
 * the id misses every StructureDestroyed and StructureUnanchoring because of it.
 */
final class StructureNotificationText
{
    /**
     * Narrow a character_notifications query to rows that may be about one of
     * these structures. A first pass only: confirm each row with mentions(),
     * because matching the digits alone also takes a longer id that starts
     * with them.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array<int, int> $structureIds
     * @return \Illuminate\Database\Query\Builder
     */
    public static function whereMayMention($query, array $structureIds)
    {
        if (!$structureIds) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($query) use ($structureIds) {
            foreach ($structureIds as $structureId) {
                $query->orWhere('text', 'LIKE', '%' . (int) $structureId . '%');
            }
        });
    }

    /**
     * Whether the notification is about this structure.
     */
    public static function mentions(string $text, int $structureId): bool
    {
        return (bool) preg_match('/structureID:\s*(?:&\w+\s+)?' . $structureId . '\b/', $text);
    }
}
