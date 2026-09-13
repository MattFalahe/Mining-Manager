<?php

namespace MiningManager\Services\Moon;

use Illuminate\Support\Facades\DB;

/**
 * The character behind a moon mining notification, by name.
 *
 * CCP puts the character's id in one field (startedBy on an extraction start)
 * and a showinfo link carrying their name in the matching ...Link field. The
 * name in the link is what the game showed at the time, so it wins. The id is
 * only used to look a name up when the link is missing or unreadable.
 */
final class MoonNotificationCharacter
{
    /**
     * @param array  $data  The notification text, parsed from YAML.
     * @param string $field The id field, for example 'startedBy'.
     */
    public static function name(array $data, string $field): ?string
    {
        $fromLink = self::nameFromLink($data[$field . 'Link'] ?? null);

        if ($fromLink !== null) {
            return $fromLink;
        }

        $id = $data[$field] ?? null;

        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        return self::lookupName((int) $id) ?? 'Character #' . (int) $id;
    }

    private static function nameFromLink($link): ?string
    {
        if (!is_string($link) || !preg_match('/>([^<]+)<\/a>/', $link, $matches)) {
            return null;
        }

        $name = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5));

        return $name !== '' ? $name : null;
    }

    /**
     * SeAT's own tables only. The alert goes out as soon as the drill is lit,
     * and holding it back to ask ESI for a name is not worth the delay.
     */
    private static function lookupName(int $id): ?string
    {
        try {
            return DB::table('character_infos')->where('character_id', $id)->value('name')
                ?? DB::table('universe_names')->where('entity_id', $id)->value('name');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
