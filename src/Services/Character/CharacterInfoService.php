<?php

namespace MiningManager\Services\Character;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Character, corporation and account details for any character, registered
 * with SeAT or not.
 *
 * Names and corporations come from CharacterNames, so every page tells the
 * same story about a character, and a page never waits on ESI. On top of that
 * this adds which main character an alt belongs to.
 */
class CharacterInfoService
{
    /**
     * One character's details. Same shape as getBatchCharacterInfo().
     */
    public function getCharacterInfo(int $characterId): array
    {
        return $this->getBatchCharacterInfo([$characterId])[$characterId] ?? [
            'character_id' => $characterId,
            'name' => "Character {$characterId}",
            'corporation_id' => null,
            'corporation_name' => 'Unknown Corporation',
            'is_registered' => false,
            'main_character_id' => null,
            'pending' => false,
        ];
    }

    /**
     * Get all characters belonging to the same SeAT user account
     *
     * @param int $characterId Any character ID from the account
     * @return array Array of character info arrays, keyed by character_id
     */
    public function getAccountCharacters(int $characterId): array
    {
        try {
            // Find user_id for this character
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->value('user_id');

            if (!$userId) {
                return [];
            }

            // Get all character IDs for this user
            $characterIds = DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->pluck('character_id')
                ->toArray();

            if (empty($characterIds)) {
                return [];
            }

            // Get character info for all of them
            return $this->getBatchCharacterInfo($characterIds);

        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to get account characters', [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Details for many characters at once, in the order asked for: name,
     * corporation_id, corporation_name, is_registered (SeAT has the
     * character), main_character_id (registered characters only) and pending
     * (still being looked up in the background).
     *
     * @param array<int, int|string> $characterIds
     * @return array<int, array> keyed by character_id
     */
    public function getBatchCharacterInfo(array $characterIds): array
    {
        // Callers running in the background put these names in messages and
        // records, so they are looked up there and then rather than shown as
        // in progress. A page only reads what is there.
        $lookup = app(CharacterNames::class);
        $lookup->lookUpNow($characterIds);
        $names = $lookup->many($characterIds);

        $mains = [];
        foreach (array_chunk(array_keys($names), 1000) as $chunk) {
            $mains += $this->getMainCharacterIdsForBatch($chunk);
        }

        $result = [];
        foreach ($names as $id => $info) {
            $result[$id] = [
                'character_id' => $id,
                'name' => $info['name'],
                'corporation_id' => $info['corporation_id'],
                'corporation_name' => $info['corporation_name'],
                'is_registered' => $info['registered'],
                'main_character_id' => $info['registered'] ? (int) ($mains[$id] ?? $id) : null,
                'pending' => $info['pending'],
            ];
        }

        return $result;
    }

    /**
     * Get main character IDs for a batch of character IDs
     * Handles SeAT v5.x structure where user-character relationship is through refresh_tokens
     *
     * @param array $characterIds
     * @return array Keyed by character_id, value is main_character_id
     */
    protected function getMainCharacterIdsForBatch(array $characterIds): array
    {
        $mainCharIds = [];
        
        try {
            // Method 1: Try refresh_tokens table (SeAT v5.x standard)
            $userIds = DB::table('refresh_tokens')
                ->whereIn('character_id', $characterIds)
                ->select('character_id', 'user_id')
                ->get()
                ->pluck('user_id', 'character_id');
            
            if ($userIds->isNotEmpty()) {
                $mainCharacterMapping = DB::table('users')
                    ->whereIn('id', $userIds->values()->unique())
                    ->pluck('main_character_id', 'id');
                
                foreach ($userIds as $charId => $userId) {
                    $mainCharIds[$charId] = $mainCharacterMapping[$userId] ?? $charId;
                }
                
                return $mainCharIds;
            }
        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to get main character IDs from refresh_tokens', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 2: Try character_users table if it exists
        try {
            if (DB::getSchemaBuilder()->hasTable('character_users')) {
                $userIds = DB::table('character_users')
                    ->whereIn('character_id', $characterIds)
                    ->select('character_id', 'user_id')
                    ->get()
                    ->pluck('user_id', 'character_id');
                
                if ($userIds->isNotEmpty()) {
                    $mainCharacterMapping = DB::table('users')
                        ->whereIn('id', $userIds->values()->unique())
                        ->pluck('main_character_id', 'id');
                    
                    foreach ($userIds as $charId => $userId) {
                        $mainCharIds[$charId] = $mainCharacterMapping[$userId] ?? $charId;
                    }
                    
                    return $mainCharIds;
                }
            }
        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to get main character IDs from character_users', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 3: Fallback - each character is its own main
        foreach ($characterIds as $charId) {
            $mainCharIds[$charId] = $charId;
        }
        
        return $mainCharIds;
    }
}
