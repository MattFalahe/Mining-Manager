<?php

namespace MiningManager\Models\Concerns;

use MiningManager\Services\Character\CharacterNames;

/**
 * The name to show for a row's character, from SeAT or the plugin's own
 * lookups, so a miner SeAT does not know is named like everyone else.
 *
 * Each row notes its character as it loads, so a page showing a few hundred
 * rows names them all in one batch when it asks for the first.
 */
trait HasCharacterName
{
    public static function bootHasCharacterName(): void
    {
        static::retrieved(function ($model) {
            app(CharacterNames::class)->want($model->getAttribute('character_id'));
        });
    }

    public function getCharacterNameAttribute(): string
    {
        return app(CharacterNames::class)->name($this->getAttribute('character_id'));
    }

    /**
     * Name, corporation and whether SeAT knows the character, for views that
     * show more than the name.
     */
    public function characterLookup(): array
    {
        return app(CharacterNames::class)->info($this->getAttribute('character_id'));
    }
}
