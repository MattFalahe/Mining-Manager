<?php

namespace MiningManager\Services\Moon;

/**
 * Helper service for moon ore detection and classification
 * 
 * TYPE IDs VERIFIED AGAINST DATABASE - ALL CORRECT
 */
class MoonOreHelper
{
    /**
     * All jackpot ore type IDs (+100% variants)
     * These indicate a jackpot extraction occurred
     *
     * @var array
     */
    const JACKPOT_ORE_IDS = [
        // R4 (Ubiquitous) Jackpot Ores
        46285,  // Glistening Bitumens
        46287,  // Glistening Coesite
        46283,  // Glistening Sylvite
        46281,  // Glistening Zeolites
        
        // R8 (Common) Jackpot Ores
        46289,  // Twinkling Cobaltite
        46291,  // Twinkling Euxenite
        46295,  // Twinkling Scheelite
        46293,  // Twinkling Titanite
        
        // R16 (Uncommon) Jackpot Ores
        46303,  // Shimmering Chromite
        46297,  // Shimmering Otavite
        46299,  // Shimmering Sperrylite
        46301,  // Shimmering Vanadinite
        
        // R32 (Rare) Jackpot Ores
        46305,  // Glowing Carnotite
        46311,  // Glowing Cinnabar
        46309,  // Glowing Pollucite
        46307,  // Glowing Zircon
        
        // R64 (Exceptional) Jackpot Ores
        46313,  // Shining Xenotime
        46315,  // Shining Monazite
        46317,  // Shining Loparite
        46319,  // Shining Ytterbite
    ];

    /**
     * Compressed jackpot ore type IDs
     *
     * @var array
     */
    const COMPRESSED_JACKPOT_ORE_IDS = [
        // R4 Compressed Jackpot
        62456,  // Compressed Glistening Bitumens
        62459,  // Compressed Glistening Coesite
        62466,  // Compressed Glistening Sylvite
        62467,  // Compressed Glistening Zeolites
        
        // R8 Compressed Jackpot
        62476,  // Compressed Twinkling Cobaltite
        62473,  // Compressed Twinkling Euxenite
        62470,  // Compressed Twinkling Scheelite
        62479,  // Compressed Twinkling Titanite
        
        // R16 Compressed Jackpot
        62482,  // Compressed Shimmering Chromite
        62485,  // Compressed Shimmering Otavite
        62488,  // Compressed Shimmering Sperrylite
        62491,  // Compressed Shimmering Vanadinite
        
        // R32 Compressed Jackpot
        62494,  // Compressed Glowing Carnotite
        62497,  // Compressed Glowing Cinnabar
        62500,  // Compressed Glowing Pollucite
        62503,  // Compressed Glowing Zircon
        
        // R64 Compressed Jackpot
        62512,  // Compressed Shining Xenotime
        62509,  // Compressed Shining Monazite
        62506,  // Compressed Shining Loparite
        62515,  // Compressed Shining Ytterbite
    ];

    /**
     * All moon ore type IDs (base, +15%, +100%)
     *
     * @var array
     */
    const ALL_MOON_ORE_IDS = [
        // R4 (Ubiquitous) - 12 items
        45492, 46284, 46285,  // Bitumens family
        45493, 46286, 46287,  // Coesite family
        45491, 46282, 46283,  // Sylvite family
        45490, 46280, 46281,  // Zeolites family
        
        // R8 (Common) - 12 items
        45494, 46288, 46289,  // Cobaltite family
        45495, 46290, 46291,  // Euxenite family
        45497, 46294, 46295,  // Scheelite family
        45496, 46292, 46293,  // Titanite family
        
        // R16 (Uncommon) - 12 items
        45501, 46302, 46303,  // Chromite family
        45498, 46296, 46297,  // Otavite family
        45499, 46298, 46299,  // Sperrylite family
        45500, 46300, 46301,  // Vanadinite family
        
        // R32 (Rare) - 12 items
        45502, 46304, 46305,  // Carnotite family
        45506, 46310, 46311,  // Cinnabar family
        45504, 46308, 46309,  // Pollucite family
        45503, 46306, 46307,  // Zircon family
        
        // R64 (Exceptional) - 12 items
        45510, 46312, 46313,  // Xenotime family
        45511, 46314, 46315,  // Monazite family
        45512, 46316, 46317,  // Loparite family
        45513, 46318, 46319,  // Ytterbite family
    ];

    /**
     * Moon ore rarity classifications
     *
     * @var array
     */
    const RARITY_MAP = [
        'R4' => [45492, 46284, 46285, 45493, 46286, 46287, 45491, 46282, 46283, 45490, 46280, 46281],
        'R8' => [45494, 46288, 46289, 45495, 46290, 46291, 45497, 46294, 46295, 45496, 46292, 46293],
        'R16' => [45501, 46302, 46303, 45498, 46296, 46297, 45499, 46298, 46299, 45500, 46300, 46301],
        'R32' => [45502, 46304, 46305, 45506, 46310, 46311, 45504, 46308, 46309, 45503, 46306, 46307],
        'R64' => [45510, 46312, 46313, 45511, 46314, 46315, 45512, 46316, 46317, 45513, 46318, 46319],
    ];

    /**
     * Check if a type ID is a jackpot ore
     *
     * @param int $typeId
     * @return bool
     */
    public static function isJackpotOre(int $typeId): bool
    {
        return in_array($typeId, self::JACKPOT_ORE_IDS) || 
               in_array($typeId, self::COMPRESSED_JACKPOT_ORE_IDS);
    }

    /**
     * Check if a type ID is any moon ore
     *
     * @param int $typeId
     * @return bool
     */
    public static function isMoonOre(int $typeId): bool
    {
        return in_array($typeId, self::ALL_MOON_ORE_IDS);
    }

    /**
     * Get the rarity level of a moon ore
     *
     * @param int $typeId
     * @return string|null (R4, R8, R16, R32, R64, or null)
     */
    public static function getRarity(int $typeId): ?string
    {
        foreach (self::RARITY_MAP as $rarity => $typeIds) {
            if (in_array($typeId, $typeIds)) {
                return $rarity;
            }
        }
        
        return null;
    }

    /**
     * Get the quality variant of an ore
     *
     * @param int $typeId
     * @return string (base, improved, or excellent)
     */
    public static function getQuality(int $typeId): string
    {
        if (in_array($typeId, self::JACKPOT_ORE_IDS) || 
            in_array($typeId, self::COMPRESSED_JACKPOT_ORE_IDS)) {
            return 'excellent'; // +100% jackpot
        }
        
        // Check if it's an improved variant (+15%)
        $improvedOres = [
            46284, 46286, 46282, 46280,  // R4 improved
            46288, 46290, 46294, 46292,  // R8 improved
            46302, 46296, 46298, 46300,  // R16 improved
            46304, 46310, 46308, 46306,  // R32 improved
            46312, 46314, 46316, 46318,  // R64 improved
        ];
        
        $compressedImprovedOres = [
            62455, 62458, 62461, 62464,  // R4 compressed improved
            62475, 62472, 62469, 62478,  // R8 compressed improved
            62481, 62484, 62487, 62490,  // R16 compressed improved
            62493, 62496, 62499, 62502,  // R32 compressed improved
            62511, 62508, 62505, 62514,  // R64 compressed improved
        ];
        
        if (in_array($typeId, $improvedOres) || in_array($typeId, $compressedImprovedOres)) {
            return 'improved'; // +15%
        }
        
        return 'base';
    }

    /**
     * Get display name for quality
     *
     * @param string $quality
     * @return string
     */
    public static function getQualityDisplayName(string $quality): string
    {
        switch ($quality) {
            case 'excellent':
                return 'Jackpot (+100%)';
            case 'improved':
                return 'Improved (+15%)';
            case 'base':
            default:
                return 'Standard';
        }
    }

    /**
     * Get badge color for quality
     *
     * @param string $quality
     * @return string
     */
    public static function getQualityBadgeClass(string $quality): string
    {
        switch ($quality) {
            case 'excellent':
                return 'badge-warning'; // Gold/yellow for jackpot
            case 'improved':
                return 'badge-info'; // Blue for improved
            case 'base':
            default:
                return 'badge-secondary'; // Gray for standard
        }
    }

    /**
     * Get icon for quality
     *
     * @param string $quality
     * @return string
     */
    public static function getQualityIcon(string $quality): string
    {
        switch ($quality) {
            case 'excellent':
                return 'fa-star'; // Star for jackpot
            case 'improved':
                return 'fa-arrow-up'; // Arrow up for improved
            case 'base':
            default:
                return 'fa-circle'; // Circle for standard
        }
    }

    /**
     * Check if a moon extraction contains any jackpot ores
     * 
     * @param \MiningManager\Models\MoonExtraction $extraction
     * @return bool
     */
    public static function extractionHasJackpot($extraction): bool
    {
        // Check mining ledger entries for this extraction
        $hasJackpot = $extraction->miningLedger()
            ->whereIn('type_id', array_merge(self::JACKPOT_ORE_IDS, self::COMPRESSED_JACKPOT_ORE_IDS))
            ->exists();
            
        return $hasJackpot;
    }

    /**
     * Get all type IDs for jackpot detection
     *
     * @return array
     */
    public static function getAllJackpotTypeIds(): array
    {
        return array_merge(self::JACKPOT_ORE_IDS, self::COMPRESSED_JACKPOT_ORE_IDS);
    }
}
