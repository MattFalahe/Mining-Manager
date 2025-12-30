<?php

namespace MiningManager\Services\Moon;

use MiningManager\Services\TypeIdRegistry;

/**
 * Helper service for moon ore detection and classification
 * 
 * Now uses TypeIdRegistry for centralized type ID management
 */
class MoonOreHelper
{
    /**
     * Moon ore rarity classifications
     * Maps type IDs to their rarity level
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
     * Uses TypeIdRegistry as single source of truth
     *
     * @param int $typeId
     * @return bool
     */
    public static function isJackpotOre(int $typeId): bool
    {
        return in_array($typeId, TypeIdRegistry::getAllJackpotOres());
    }

    /**
     * Check if a type ID is any moon ore
     *
     * @param int $typeId
     * @return bool
     */
    public static function isMoonOre(int $typeId): bool
    {
        return in_array($typeId, TypeIdRegistry::getAllMoonOres()) ||
               in_array($typeId, TypeIdRegistry::getAllCompressedMoonOres());
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
        // Check if it's a jackpot ore (+100%)
        if (self::isJackpotOre($typeId)) {
            return 'excellent';
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
            return 'improved';
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
            ->whereIn('type_id', TypeIdRegistry::getAllJackpotOres())
            ->exists();
            
        return $hasJackpot;
    }

    /**
     * Get all type IDs for jackpot detection
     * Delegates to TypeIdRegistry
     *
     * @return array
     */
    public static function getAllJackpotTypeIds(): array
    {
        return TypeIdRegistry::getAllJackpotOres();
    }

    // ============================================
    // METHODS FOR MoonExtraction MODEL COMPATIBILITY
    // ============================================

    /**
     * Detect if an ore composition contains any jackpot ores
     * 
     * @param array $oreComposition Array of ['type_id' => quantity] or similar structure
     * @return bool
     */
    public static function detectJackpotInComposition(array $oreComposition): bool
    {
        if (empty($oreComposition)) {
            return false;
        }

        // Check if any of the type IDs in the composition are jackpot ores
        foreach ($oreComposition as $ore) {
            // Handle both array and object structures
            $typeId = is_array($ore) ? ($ore['type_id'] ?? null) : ($ore->type_id ?? null);
            
            if ($typeId && self::isJackpotOre($typeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get detailed jackpot statistics for an ore composition
     * 
     * @param array $oreComposition Array of ore data
     * @return array Statistics including counts and percentages
     */
    public static function getJackpotStatistics(array $oreComposition): array
    {
        if (empty($oreComposition)) {
            return [
                'is_jackpot' => false,
                'total_ore_types' => 0,
                'jackpot_ore_types' => 0,
                'jackpot_percentage' => 0,
                'total_quantity' => 0,
                'jackpot_quantity' => 0,
                'jackpot_quantity_percentage' => 0,
            ];
        }

        $totalOreTypes = 0;
        $jackpotOreTypes = 0;
        $totalQuantity = 0;
        $jackpotQuantity = 0;

        foreach ($oreComposition as $ore) {
            // Handle both array and object structures
            $typeId = is_array($ore) ? ($ore['type_id'] ?? null) : ($ore->type_id ?? null);
            $quantity = is_array($ore) ? ($ore['quantity'] ?? 0) : ($ore->quantity ?? 0);

            if ($typeId) {
                $totalOreTypes++;
                $totalQuantity += $quantity;

                if (self::isJackpotOre($typeId)) {
                    $jackpotOreTypes++;
                    $jackpotQuantity += $quantity;
                }
            }
        }

        $jackpotPercentage = $totalOreTypes > 0 
            ? ($jackpotOreTypes / $totalOreTypes) * 100 
            : 0;

        $jackpotQuantityPercentage = $totalQuantity > 0 
            ? ($jackpotQuantity / $totalQuantity) * 100 
            : 0;

        return [
            'is_jackpot' => $jackpotOreTypes > 0,
            'total_ore_types' => $totalOreTypes,
            'jackpot_ore_types' => $jackpotOreTypes,
            'jackpot_percentage' => round($jackpotPercentage, 2),
            'total_quantity' => $totalQuantity,
            'jackpot_quantity' => $jackpotQuantity,
            'jackpot_quantity_percentage' => round($jackpotQuantityPercentage, 2),
        ];
    }

    /**
     * Get all jackpot ores from a composition
     * 
     * @param array $oreComposition Array of ore data
     * @return array Filtered array containing only jackpot ores
     */
    public static function getJackpotOresFromComposition(array $oreComposition): array
    {
        if (empty($oreComposition)) {
            return [];
        }

        $jackpotOres = [];

        foreach ($oreComposition as $ore) {
            // Handle both array and object structures
            $typeId = is_array($ore) ? ($ore['type_id'] ?? null) : ($ore->type_id ?? null);

            if ($typeId && self::isJackpotOre($typeId)) {
                $jackpotOres[] = $ore;
            }
        }

        return $jackpotOres;
    }

    /**
     * Calculate the value multiplier from jackpot ores in composition
     * 
     * @param array $oreComposition Array of ore data
     * @return float Multiplier (1.0 = no jackpot, >1.0 = jackpot bonus)
     */
    public static function calculateJackpotMultiplier(array $oreComposition): float
    {
        $stats = self::getJackpotStatistics($oreComposition);
        
        if (!$stats['is_jackpot'] || $stats['total_quantity'] == 0) {
            return 1.0;
        }

        // Jackpot ores yield 2x (100% more), regular ores yield 1x
        // Calculate weighted average based on quantity
        $jackpotPercentage = $stats['jackpot_quantity_percentage'] / 100;
        
        // Multiplier = (regular_pct * 1.0) + (jackpot_pct * 2.0)
        return (1 - $jackpotPercentage) + ($jackpotPercentage * 2);
    }
}
