<?php

namespace MiningManager\Services\Moon;

use Illuminate\Support\Facades\DB;

/**
 * The character behind a moon mining notification, by name.
 *
 * CCP puts the character's id in one field (startedBy, firedBy, cancelledBy)
 * and a showinfo link carrying their name in the matching ...Link field. The
 * name in the link is what the game showed at the time, so it wins. The id is
 * only used to look a name up when the link is missing or unreadable.
 */
final class MoonNotificationCharacter
{
    /**
     * The character's name.
     *
     * @param array  $data  The notification text, parsed from YAML.
     * @param string $field The id field, for example 'startedBy'.
     */
    public static function name(array $data, string $field): ?string
    {
        $fromLink = self::nameFromLink($data[$field . 'Link'] ?? null);

        if ($fromLink !== null) {
            return $fromLink;
        }

        $id = self::id($data, $field);

        if ($id === null) {
            return null;
        }

        return self::lookupName($id) ?? 'Character #' . $id;
    }

    /**
     * The character's name, followed by the main of their SeAT account when
     * that is someone else: "Fat Tony Junior (main: Fat Tony)".
     *
     * A pilot with no account on this install is shown by name alone.
     */
    public static function describe(array $data, string $field): ?string
    {
        $name = self::name($data, $field);

        if ($name === null) {
            return null;
        }

        $id = self::id($data, $field);

        return self::withMain($name, $id, $id !== null ? self::mainOf($id) : null);
    }

    /**
     * @param array{0: int, 1: string}|null $main
     */
    private static function withMain(string $name, ?int $id, ?array $main): string
    {
        if ($main === null || $main[0] === $id) {
            return $name;
        }

        return "{$name} (main: {$main[1]})";
    }

    private static function id(array $data, string $field): ?int
    {
        $id = $data[$field] ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
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
     * SeAT's own tables only. The alert should not hang on a call to ESI just
     * to put a name to an id.
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

    /**
     * The main of the SeAT account a character is on, as [id, name].
     *
     * Follows the same link the tax calculation groups an account by: the
     * character's refresh token names the user, and the user names its main.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function mainOf(int $id): ?array
    {
        try {
            $mainId = DB::table('refresh_tokens as rt')
                ->join('users as u', 'u.id', '=', 'rt.user_id')
                ->where('rt.character_id', $id)
                ->value('u.main_character_id');
        } catch (\Throwable $e) {
            return null;
        }

        if (!$mainId) {
            return null;
        }

        $mainName = self::lookupName((int) $mainId);

        return $mainName !== null ? [(int) $mainId, $mainName] : null;
    }
}
