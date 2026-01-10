<?php

namespace MiningManager\Services\Character;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * External Character Service
 * 
 * Fetches character and corporation information from external APIs
 * for characters not registered in SeAT.
 * 
 * APIs used:
 * - ESI (EVE Swagger Interface) - Primary source
 * - zKillboard - Fallback for character info
 * - EVEWho - Fallback for corporation info
 */
class ExternalCharacterService
{
    /**
     * Get character name from external APIs
     *
     * @param int $characterId
     * @return string|null
     */
    public function getCharacterName(int $characterId): ?string
    {
        $cacheKey = "external_character_name_{$characterId}";
        
        return Cache::remember($cacheKey, 86400, function () use ($characterId) {
            // Try ESI first (official API)
            $name = $this->getCharacterNameFromESI($characterId);
            if ($name) {
                return $name;
            }
            
            // Fallback to zKillboard
            $name = $this->getCharacterNameFromZKill($characterId);
            if ($name) {
                return $name;
            }
            
            return null;
        });
    }

    /**
     * Get corporation ID for a character from external APIs
     *
     * @param int $characterId
     * @return int|null
     */
    public function getCharacterCorporationId(int $characterId): ?int
    {
        $cacheKey = "external_character_corp_{$characterId}";
        
        return Cache::remember($cacheKey, 3600, function () use ($characterId) {
            // Try ESI first
            $corpId = $this->getCharacterCorporationFromESI($characterId);
            if ($corpId) {
                return $corpId;
            }
            
            // Fallback to EVEWho
            $corpId = $this->getCharacterCorporationFromEVEWho($characterId);
            if ($corpId) {
                return $corpId;
            }
            
            return null;
        });
    }

    /**
     * Get corporation name from external APIs
     *
     * @param int $corporationId
     * @return string|null
     */
    public function getCorporationName(int $corporationId): ?string
    {
        $cacheKey = "external_corporation_name_{$corporationId}";
        
        return Cache::remember($cacheKey, 86400, function () use ($corporationId) {
            // Try ESI first
            $name = $this->getCorporationNameFromESI($corporationId);
            if ($name) {
                return $name;
            }
            
            // Fallback to EVEWho
            $name = $this->getCorporationNameFromEVEWho($corporationId);
            if ($name) {
                return $name;
            }
            
            return null;
        });
    }

    /**
     * Get complete character information (name + corporation)
     *
     * @param int $characterId
     * @return array ['name' => string, 'corporation_id' => int, 'corporation_name' => string, 'is_registered' => false]
     */
    public function getCharacterInfo(int $characterId): array
    {
        $name = $this->getCharacterName($characterId);
        $corpId = $this->getCharacterCorporationId($characterId);
        $corpName = $corpId ? $this->getCorporationName($corpId) : null;
        
        return [
            'name' => $name ?? "Character {$characterId}",
            'corporation_id' => $corpId,
            'corporation_name' => $corpName ?? 'Unknown Corporation',
            'is_registered' => false,
        ];
    }

    // ==================== ESI API METHODS ====================

    /**
     * Get character name from ESI
     */
    private function getCharacterNameFromESI(int $characterId): ?string
    {
        try {
            $response = Http::timeout(5)
                ->get("https://esi.evetech.net/latest/characters/{$characterId}/");
            
            if ($response->successful()) {
                return $response->json()['name'] ?? null;
            }
        } catch (\Exception $e) {
            Log::debug("ExternalCharacterService: Failed to get character name from ESI", [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Get character corporation from ESI
     */
    private function getCharacterCorporationFromESI(int $characterId): ?int
    {
        try {
            $response = Http::timeout(5)
                ->get("https://esi.evetech.net/latest/characters/{$characterId}/");
            
            if ($response->successful()) {
                return $response->json()['corporation_id'] ?? null;
            }
        } catch (\Exception $e) {
            Log::debug("ExternalCharacterService: Failed to get character corporation from ESI", [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Get corporation name from ESI
     */
    private function getCorporationNameFromESI(int $corporationId): ?string
    {
        try {
            $response = Http::timeout(5)
                ->get("https://esi.evetech.net/latest/corporations/{$corporationId}/");
            
            if ($response->successful()) {
                return $response->json()['name'] ?? null;
            }
        } catch (\Exception $e) {
            Log::debug("ExternalCharacterService: Failed to get corporation name from ESI", [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    // ==================== ZKILLBOARD API METHODS ====================

    /**
     * Get character name from zKillboard
     */
    private function getCharacterNameFromZKill(int $characterId): ?string
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => 'SeAT Mining Manager Plugin'
                ])
                ->get("https://zkillboard.com/api/characterID/{$characterId}/");
            
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['characterName'])) {
                    return $data[0]['characterName'];
                }
            }
        } catch (\Exception $e) {
            Log::debug("ExternalCharacterService: Failed to get character name from zKillboard", [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    // ==================== EVEWHO API METHODS ====================

    /**
     * Get character corporation from EVEWho
     */
    private function getCharacterCorporationFromEVEWho(int $characterId): ?int
    {
        try {
            $response = Http::timeout(5)
                ->get("https://evewho.com/api/character/{$characterId}");
            
            if ($response->successful()) {
                return $response->json()['corporation_id'] ?? null;
            }
        } catch (\Exception $e) {
            Log::debug("ExternalCharacterService: Failed to get character from EVEWho", [
                'character_id' => $characterId,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Get corporation name from EVEWho
     */
    private function getCorporationNameFromEVEWho(int $corporationId): ?string
    {
        try {
            $response = Http::timeout(5)
                ->get("https://evewho.com/api/corporation/{$corporationId}");
            
            if ($response->successful()) {
                return $response->json()['name'] ?? null;
            }
        } catch (\Exception $e) {
            Log::debug("ExternalCharacterService: Failed to get corporation from EVEWho", [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Clear cache for a specific character
     */
    public function clearCharacterCache(int $characterId): void
    {
        Cache::forget("external_character_name_{$characterId}");
        Cache::forget("external_character_corp_{$characterId}");
    }

    /**
     * Clear cache for a specific corporation
     */
    public function clearCorporationCache(int $corporationId): void
    {
        Cache::forget("external_corporation_name_{$corporationId}");
    }
}
