<?php

namespace MiningManager\Services\Character;

use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Character Information Service
 * 
 * Provides character and corporation information for both:
 * - Registered characters (in SeAT)
 * - Unregistered characters (from external APIs)
 */
class CharacterInfoService
{
    protected $externalService;

    public function __construct(ExternalCharacterService $externalService)
    {
        $this->externalService = $externalService;
    }

    /**
     * Get character information with multiple fallback methods
     * Returns consistent format for both registered and unregistered characters
     *
     * @param int $characterId
     * @return array [
     *   'character_id' => int,
     *   'name' => string,
     *   'corporation_id' => int|null,
     *   'corporation_name' => string,
     *   'is_registered' => bool,
     *   'main_character_id' => int|null
     * ]
     */
    public function getCharacterInfo(int $characterId): array
    {
        // Try to get from SeAT first
        $seatInfo = $this->getCharacterInfoFromSeAT($characterId);
        
        if ($seatInfo) {
            return $seatInfo;
        }
        
        // Character not in SeAT - get from external APIs
        $externalInfo = $this->externalService->getCharacterInfo($characterId);
        
        return [
            'character_id' => $characterId,
            'name' => $externalInfo['name'],
            'corporation_id' => $externalInfo['corporation_id'],
            'corporation_name' => $externalInfo['corporation_name'],
            'is_registered' => false,
            'main_character_id' => null,
        ];
    }

    /**
     * Get character info from SeAT database
     *
     * @param int $characterId
     * @return array|null
     */
    protected function getCharacterInfoFromSeAT(int $characterId): ?array
    {
        try {
            $character = CharacterInfo::find($characterId);
            
            if (!$character) {
                return null;
            }
            
            // Get affiliation for corporation info (more reliable than character_infos)
            $affiliation = CharacterAffiliation::where('character_id', $characterId)->first();
            
            // Use corporation_id from affiliation if character_infos doesn't have it
            $corporationId = $character->corporation_id ?? ($affiliation->corporation_id ?? null);
            
            // Get corporation name
            $corporationName = $this->getCorporationNameForCharacter($character, $affiliation);
            
            // Get main character ID if this is an alt
            $mainCharacterId = $this->getMainCharacterId($characterId);
            
            return [
                'character_id' => $character->character_id,
                'name' => $character->name ?? "Character {$characterId}",
                'corporation_id' => $corporationId,
                'corporation_name' => $corporationName,
                'is_registered' => true,
                'main_character_id' => $mainCharacterId,
            ];
            
        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to get character from SeAT', [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get corporation name for a character with multiple fallback methods
     * 
     * @param CharacterInfo $character
     * @param CharacterAffiliation|null $affiliation
     * @return string
     */
    protected function getCorporationNameForCharacter(CharacterInfo $character, ?CharacterAffiliation $affiliation = null): string
    {
        // Try to get corporation_id from affiliation first, then character
        $corporationId = $affiliation->corporation_id ?? $character->corporation_id ?? null;
        
        if (!$corporationId) {
            Log::debug('CharacterInfoService: No corporation_id for character', [
                'character_id' => $character->character_id,
                'character_name' => $character->name
            ]);
            return 'Unknown Corporation';
        }
        
        // Method 1: Try affiliation corporation name if provided
        if ($affiliation && !empty($affiliation->corporation_name)) {
            Log::debug('CharacterInfoService: Found corporation via affiliation object', [
                'character_id' => $character->character_id,
                'corporation_name' => $affiliation->corporation_name
            ]);
            return $affiliation->corporation_name;
        }
        
        // Method 2: Try loading affiliation if not provided
        if (!$affiliation) {
            try {
                $affiliation = CharacterAffiliation::where('character_id', $character->character_id)->first();
                if ($affiliation && !empty($affiliation->corporation_name)) {
                    Log::debug('CharacterInfoService: Found corporation via loaded affiliation', [
                        'character_id' => $character->character_id,
                        'corporation_name' => $affiliation->corporation_name
                    ]);
                    return $affiliation->corporation_name;
                }
            } catch (\Exception $e) {
                Log::debug('CharacterInfoService: Failed to load affiliation', [
                    'character_id' => $character->character_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Method 3: Try corporation_infos table
        try {
            $corporation = CorporationInfo::find($corporationId);
            if ($corporation && !empty($corporation->name)) {
                Log::debug('CharacterInfoService: Found corporation via corporation_infos', [
                    'character_id' => $character->character_id,
                    'corporation_id' => $corporationId,
                    'corporation_name' => $corporation->name
                ]);
                return $corporation->name;
            }
        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to get corporation from corporation_infos', [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 4: Try direct database query on character_affiliations
        try {
            $corpName = DB::table('character_affiliations')
                ->where('character_id', $character->character_id)
                ->value('corporation_name');
            
            if ($corpName) {
                Log::debug('CharacterInfoService: Found corporation via DB query on affiliations', [
                    'character_id' => $character->character_id,
                    'corporation_name' => $corpName
                ]);
                return $corpName;
            }
        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to query character_affiliations', [
                'character_id' => $character->character_id,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 5: Try corporation_infos table with direct query
        try {
            $corpName = DB::table('corporation_infos')
                ->where('corporation_id', $corporationId)
                ->value('name');
            
            if ($corpName) {
                Log::debug('CharacterInfoService: Found corporation via DB query on corporation_infos', [
                    'corporation_id' => $corporationId,
                    'corporation_name' => $corpName
                ]);
                return $corpName;
            }
        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to query corporation_infos table', [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 6: Try external API as last resort
        try {
            $corpName = $this->externalService->getCorporationName($corporationId);
            if ($corpName) {
                Log::info('CharacterInfoService: Found corporation via external API', [
                    'corporation_id' => $corporationId,
                    'corporation_name' => $corpName,
                    'character_id' => $character->character_id
                ]);
                return $corpName;
            }
        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to get corporation from external API', [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage()
            ]);
        }
        
        // All methods failed
        Log::warning('CharacterInfoService: All methods failed to find corporation name', [
            'character_id' => $character->character_id,
            'corporation_id' => $corporationId,
            'character_name' => $character->name
        ]);
        
        return 'Unknown Corporation';
    }

    /**
     * Get the main character ID for a given character
     * Returns the character ID itself if it's a main, or can't determine
     *
     * @param int $characterId
     * @return int
     */
    protected function getMainCharacterId(int $characterId): int
    {
        try {
            // Method 1: Try refresh_tokens table (SeAT v5.x standard)
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->value('user_id');
            
            if ($userId) {
                $mainCharacterId = DB::table('users')
                    ->where('id', $userId)
                    ->value('main_character_id');
                
                return $mainCharacterId ?? $characterId;
            }
        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to get main character ID from refresh_tokens', [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
        }
        
        // Method 2: Try character_users table if it exists
        try {
            if (DB::getSchemaBuilder()->hasTable('character_users')) {
                $userId = DB::table('character_users')
                    ->where('character_id', $characterId)
                    ->value('user_id');
                
                if ($userId) {
                    $mainCharacterId = DB::table('users')
                        ->where('id', $userId)
                        ->value('main_character_id');
                    
                    return $mainCharacterId ?? $characterId;
                }
            }
        } catch (\Exception $e) {
            Log::debug('CharacterInfoService: Failed to get main character ID from character_users', [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
        }
        
        return $characterId;
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
     * Get batch character information
     * Optimized for multiple characters at once
     *
     * @param array $characterIds
     * @return array Keyed by character_id
     */
    public function getBatchCharacterInfo(array $characterIds): array
    {
        $result = [];
        
        // Get all registered characters in one query
        $registeredChars = CharacterInfo::whereIn('character_id', $characterIds)->get()->keyBy('character_id');
        
        // Get all affiliations in one query
        $affiliations = CharacterAffiliation::whereIn('character_id', $characterIds)
            ->get()
            ->keyBy('character_id');
        
        // Get corporation info for all corporation IDs we have
        // Collect from both character_infos AND affiliations
        $corporationIds = collect();
        $corporationIds = $corporationIds->merge($registeredChars->pluck('corporation_id')->filter());
        $corporationIds = $corporationIds->merge($affiliations->pluck('corporation_id')->filter());
        $corporationIds = $corporationIds->unique()->toArray();
        
        $corporations = [];
        if (!empty($corporationIds)) {
            $corporations = CorporationInfo::whereIn('corporation_id', $corporationIds)
                ->get()
                ->keyBy('corporation_id');
        }
        
        // Get all main character IDs in one query
        // In SeAT v5.x, the relationship is through refresh_tokens table
        $mainCharacterIds = $this->getMainCharacterIdsForBatch($characterIds);
        
        foreach ($characterIds as $charId) {
            if (isset($registeredChars[$charId])) {
                // Registered character
                $character = $registeredChars[$charId];
                $affiliation = $affiliations[$charId] ?? null;
                $mainCharId = $mainCharacterIds[$charId] ?? $charId;
                
                // Use corporation_id from affiliation if character doesn't have it
                $corporationId = $character->corporation_id ?? ($affiliation->corporation_id ?? null);
                
                // Get corporation name with priority order
                $corporationName = 'Unknown Corporation';
                
                // Priority 1: Affiliation corporation name
                if ($affiliation && !empty($affiliation->corporation_name)) {
                    $corporationName = $affiliation->corporation_name;
                }
                // Priority 2: Corporation info from batch load
                elseif ($corporationId && isset($corporations[$corporationId])) {
                    $corporationName = $corporations[$corporationId]->name;
                }
                // Priority 3: Fall back to individual lookup (which tries external API too)
                else {
                    $corporationName = $this->getCorporationNameForCharacter($character, $affiliation);
                }
                
                $result[$charId] = [
                    'character_id' => $character->character_id,
                    'name' => $character->name ?? "Character {$charId}",
                    'corporation_id' => $corporationId,
                    'corporation_name' => $corporationName,
                    'is_registered' => true,
                    'main_character_id' => $mainCharId,
                ];
            } else {
                // Unregistered character - fetch from external
                $externalInfo = $this->externalService->getCharacterInfo($charId);
                $result[$charId] = [
                    'character_id' => $charId,
                    'name' => $externalInfo['name'],
                    'corporation_id' => $externalInfo['corporation_id'],
                    'corporation_name' => $externalInfo['corporation_name'],
                    'is_registered' => false,
                    'main_character_id' => null,
                ];
            }
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
