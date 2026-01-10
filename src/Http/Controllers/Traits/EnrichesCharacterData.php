<?php

namespace MiningManager\Http\Controllers\Traits;

use MiningManager\Services\Character\CharacterInfoService;
use Illuminate\Support\Collection;

trait EnrichesCharacterData
{
    /**
     * Enrich a collection of items with character information
     *
     * @param Collection $items Collection of items with character_id
     * @param CharacterInfoService $service
     * @param string $characterIdField Field name containing character_id (default: 'character_id')
     * @return Collection Enriched collection with character_info added
     */
    protected function enrichWithCharacterInfo(
        Collection $items, 
        CharacterInfoService $service,
        string $characterIdField = 'character_id'
    ): Collection {
        // Get all unique character IDs
        $characterIds = $items->pluck($characterIdField)->unique()->filter()->toArray();
        
        if (empty($characterIds)) {
            return $items;
        }
        
        // Batch load character info
        $charactersInfo = $service->getBatchCharacterInfo($characterIds);
        
        // Add character info to each item
        return $items->map(function($item) use ($charactersInfo, $characterIdField) {
            $charId = is_object($item) ? $item->$characterIdField : $item[$characterIdField];
            
            if (isset($charactersInfo[$charId])) {
                if (is_object($item)) {
                    $item->character_info = $charactersInfo[$charId];
                } else {
                    $item['character_info'] = $charactersInfo[$charId];
                }
            }
            
            return $item;
        });
    }
    
    /**
     * Enrich paginated results with character information
     *
     * @param mixed $paginator Laravel paginator instance
     * @param CharacterInfoService $service
     * @param string $characterIdField Field name containing character_id
     * @return mixed Same paginator with enriched items
     */
    protected function enrichPaginatorWithCharacterInfo(
        $paginator,
        CharacterInfoService $service,
        string $characterIdField = 'character_id'
    ) {
        $enrichedItems = $this->enrichWithCharacterInfo(
            collect($paginator->items()),
            $service,
            $characterIdField
        );
        
        // Replace paginator items with enriched items
        $paginator->setCollection($enrichedItems);
        
        return $paginator;
    }
    
    /**
     * Get character name with fallback to external lookup
     *
     * @param int $characterId
     * @param CharacterInfoService $service
     * @return string
     */
    protected function getCharacterName(int $characterId, CharacterInfoService $service): string
    {
        $info = $service->getCharacterInfo($characterId);
        return $info['name'] ?? "Character {$characterId}";
    }
    
    /**
     * Get character corporation name with fallback to external lookup
     *
     * @param int $characterId
     * @param CharacterInfoService $service
     * @return string
     */
    protected function getCharacterCorporation(int $characterId, CharacterInfoService $service): string
    {
        $info = $service->getCharacterInfo($characterId);
        return $info['corporation_name'] ?? 'Unknown Corporation';
    }
    
    /**
     * Enrich a single model instance with character information
     *
     * @param object $model
     * @param CharacterInfoService $service
     * @param string $characterIdField
     * @return object
     */
    protected function enrichModelWithCharacterInfo(
        $model,
        CharacterInfoService $service,
        string $characterIdField = 'character_id'
    ) {
        $charId = $model->$characterIdField ?? null;
        
        if ($charId) {
            $model->character_info = $service->getCharacterInfo($charId);
        }
        
        return $model;
    }
}
