<?php

namespace MiningManager\Services;

/**
 * Centralized Type ID Registry
 * 
 * Single source of truth for all EVE Online type IDs used in Mining Manager.
 * All type IDs verified against SeAT database as of December 2025.
 * 
 * MAINTENANCE: Update IDs here only - all other files reference this class.
 * 
 * UPDATED: Added new ore families from YC124-YC126 expansions
 * - Deep Space Survey Ores (YC124): Mordunium, Ytirium, Eifyrium, Ducinium
 * - Ore Prospecting Array Ores (YC126 - Equinox): Griemeer, Nocxite, Kylixium, Hezorime, Ueganite
 */
class TypeIdRegistry
{
    // ============================================
    // REGULAR ORES (48 items - added Spodumain)
    // ============================================

    const REGULAR_ORES = [
        // Veldspar family
        1230, 17470, 17471,
        // Scordite family
        1228, 17463, 17464,
        // Pyroxeres family
        1224, 17459, 17460,
        // Plagioclase family
        18, 17455, 17456,
        // Omber family
        1227, 17867, 17868,
        // Kernite family
        20, 17452, 17453,
        // Jaspet family
        1226, 17448, 17449,
        // Hemorphite family
        1231, 17444, 17445,
        // Hedbergite family
        21, 17440, 17441,
        // Gneiss family
        1229, 17865, 17866,
        // Dark Ochre family
        1232, 17436, 17437,
        // Crokite family
        1225, 17432, 17433,
        // Bistot family
        1223, 17428, 17429,
        // Arkonor family
        22, 17425, 17426,
        // Mercoxit family
        11396, 17869, 17870,
        // Spodumain family
        19, 17466, 17467,
    ];

    const COMPRESSED_REGULAR_ORES = [
        // Compressed Veldspar family (4 variants)
        62516, 62517, 62518, 62519,  // Veldspar, Concentrated, Dense, Stable
        // Compressed Scordite family (4 variants)
        62520, 62521, 62522, 62523,  // Scordite, Condensed, Massive, Glossy
        // Compressed Pyroxeres family (4 variants)
        62524, 62525, 62526, 62527,  // Pyroxeres, Solid, Viscous, Opulent
        // Compressed Plagioclase family (4 variants)
        62528, 62529, 62530, 62531,  // Plagioclase, Azure, Rich, Sparkling
        // Compressed Omber family (4 variants)
        62532, 62533, 62534, 62535,  // Omber, Silvery, Golden, Platinoid
        // Compressed Kernite family (4 variants)
        62536, 62537, 62538, 62539,  // Kernite, Luminous, Fiery, Resplendant
        // Compressed Jaspet family (4 variants)
        62540, 62541, 62542, 62543,  // Jaspet, Pure, Pristine, Immaculate
        // Compressed Hemorphite family (4 variants)
        62544, 62545, 62546, 62547,  // Hemorphite, Vivid, Radiant, Scintillating
        // Compressed Hedbergite family (4 variants)
        62548, 62549, 62550, 62551,  // Hedbergite, Vitric, Glazed, Lustrous
        // Compressed Gneiss family (4 variants)
        62552, 62553, 62554, 62555,  // Gneiss, Iridescent, Prismatic, Brilliant
        // Compressed Dark Ochre family (4 variants)
        62556, 62557, 62558, 62559,  // Dark Ochre, Onyx, Obsidian, Jet
        // Compressed Crokite family (4 variants)
        62560, 62561, 62562, 62563,  // Crokite, Sharp, Crystalline, Pellucid
        // Compressed Bistot family (4 variants)
        62564, 62565, 62566, 62567,  // Bistot, Triclinic, Monoclinic, Cubic
        // Compressed Arkonor family (4 variants)
        62568, 62569, 62570, 62571,  // Arkonor, Crimson, Prime, Flawless
        // Compressed Spodumain family (4 variants)
        62572, 62573, 62574, 62575,  // Spodumain, Bright, Gleaming, Dazzling
        // Compressed Mercoxit family (3 variants)
        62586, 62587, 62588,  // Mercoxit, Magma, Vitreous
    ];

    // ============================================
    // MOON ORES - ALL VARIANTS (60 items)
    // ============================================

    const MOON_ORES = [
        // R4 (Ubiquitous) - 12 items
        45492, 46284, 46285,  // Bitumens family (base, +15%, +100%)
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

    const COMPRESSED_MOON_ORES = [
        // R4 (Ubiquitous) Compressed - 12 items
        62454, 62455, 62456,  // Compressed Bitumens family
        62457, 62458, 62459,  // Compressed Coesite family
        62460, 62461, 62466,  // Compressed Sylvite family
        62463, 62464, 62467,  // Compressed Zeolites family
        
        // R8 (Common) Compressed - 12 items
        62474, 62475, 62476,  // Compressed Cobaltite family
        62471, 62472, 62473,  // Compressed Euxenite family
        62468, 62469, 62470,  // Compressed Scheelite family
        62477, 62478, 62479,  // Compressed Titanite family
        
        // R16 (Uncommon) Compressed - 12 items
        62480, 62481, 62482,  // Compressed Chromite family
        62483, 62484, 62485,  // Compressed Otavite family
        62486, 62487, 62488,  // Compressed Sperrylite family
        62489, 62490, 62491,  // Compressed Vanadinite family
        
        // R32 (Rare) Compressed - 12 items
        62492, 62493, 62494,  // Compressed Carnotite family
        62495, 62496, 62497,  // Compressed Cinnabar family
        62498, 62499, 62500,  // Compressed Pollucite family
        62501, 62502, 62503,  // Compressed Zircon family
        
        // R64 (Exceptional) Compressed - 12 items
        62510, 62511, 62512,  // Compressed Xenotime family
        62507, 62508, 62509,  // Compressed Monazite family
        62504, 62505, 62506,  // Compressed Loparite family
        62513, 62514, 62515,  // Compressed Ytterbite family
    ];

    // Jackpot ore IDs (+100% variants only)
    const JACKPOT_ORES_UNCOMPRESSED = [
        // R4 Jackpot
        46285,  // Glistening Bitumens
        46287,  // Glistening Coesite
        46283,  // Glistening Sylvite
        46281,  // Glistening Zeolites
        
        // R8 Jackpot
        46289,  // Twinkling Cobaltite
        46291,  // Twinkling Euxenite
        46295,  // Twinkling Scheelite
        46293,  // Twinkling Titanite
        
        // R16 Jackpot
        46303,  // Shimmering Chromite
        46297,  // Shimmering Otavite
        46299,  // Shimmering Sperrylite
        46301,  // Shimmering Vanadinite
        
        // R32 Jackpot
        46305,  // Glowing Carnotite
        46311,  // Glowing Cinnabar
        46309,  // Glowing Pollucite
        46307,  // Glowing Zircon
        
        // R64 Jackpot
        46313,  // Shining Xenotime
        46315,  // Shining Monazite
        46317,  // Shining Loparite
        46319,  // Shining Ytterbite
    ];

    const JACKPOT_ORES_COMPRESSED = [
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

    // ============================================
    // ICE (20 items - 8 raw + 12 compressed variants)
    // ============================================

    const ICE = [
        // Standard Ice (8 types)
        16262, // Clear Icicle
        16263, // Glacial Mass
        16264, // Blue Ice
        16265, // White Glaze
        16266, // Glare Crust
        16267, // Dark Glitter
        16268, // Gelidus
        16269, // Krystallos

        // Ice Variants (improved versions found in specific space)
        17975, // Thick Blue Ice
        17976, // Pristine White Glaze
        17977, // Smooth Glacial Mass
        17978, // Enriched Clear Icicle
        28627, // Azure Ice
        28628, // Crystalline Icicle
    ];

    const COMPRESSED_ICE = [
        // Compressed Ice (8 types) - these are actually using the 284xx range
        28433, // Compressed Blue Ice
        28434, // Compressed Clear Icicle
        28435, // Compressed Dark Glitter
        28436, // Compressed Enriched Clear Icicle
        28437, // Compressed Gelidus
        28438, // Compressed Glacial Mass
        28439, // Compressed Glare Crust
        28440, // Compressed Krystallos
        28441, // Compressed Pristine White Glaze
        28442, // Compressed Smooth Glacial Mass
        28443, // Compressed Thick Blue Ice
        28444, // Compressed White Glaze
    ];

    // ============================================
    // GAS (12 items)
    // ============================================

    const GAS_FULLERITES = [
        // Fullerites (C-X) - 8 types
        30370, 30371, 30372, 30373, 30374, 30375, 30377, 30378,
    ];

    const GAS_BOOSTERS = [
        // Booster Gases - base types
        25276, 25278, 25274, 25268,
    ];

    const GAS_CYTOSEROCIN = [
        // Cytoserocin variants (used in Strong Booster production)
        25275, // Celadon Cytoserocin
        25279, // Azure Cytoserocin
        28629, // Gamboge Cytoserocin
        28630, // Chartreuse Cytoserocin
    ];

    const GAS_MYKOSEROCIN = [
        // Mykoserocin variants (used in Synth Booster production)
        28694, // Amber Mykoserocin
        28695, // Azure Mykoserocin
    ];

    const TRIGLAVIAN_ORES = [
        // Pochven/Triglavian ores (found in Pochven and Triglavian space)
        28617, // Banidine
        28618, // Augumene
        28619, // Mercium
        28620, // Lyavite
        28621, // Pithix
        28622, // Green Arisite
        28623, // Oeryl
        28624, // Geodite
        28625, // Polygypsum
    ];

    // ============================================
    // MINERALS (8 items)
    // ============================================

    const MINERALS = [
        34,    // Tritanium
        35,    // Pyerite
        36,    // Mexallon
        37,    // Isogen
        38,    // Nocxium
        39,    // Zydrine
        40,    // Megacyte
        11399, // Morphite
    ];

    // ============================================
    // MOON MATERIALS (24 items)
    // ============================================

    const MOON_MATERIALS_R4 = [
        16633, // Hydrocarbons
        16634, // Atmospheric Gases
        16635, // Evaporite Deposits
        16636, // Silicates
    ];

    const MOON_MATERIALS_R8 = [
        16637, // Tungsten
        16638, // Titanium
        16639, // Scandium
        16640, // Cobalt
    ];

    const MOON_MATERIALS_R16 = [
        16641, // Chromium
        16642, // Vanadium
        16643, // Cadmium
        16644, // Platinum
    ];

    const MOON_MATERIALS_R32 = [
        16646, // Mercury
        16647, // Caesium
        16648, // Hafnium
        16649, // Technetium
    ];

    const MOON_MATERIALS_R64 = [
        16650, // Dysprosium
        16651, // Neodymium
        16652, // Promethium
        16653, // Thulium
    ];

    // ============================================
    // ICE PRODUCTS (7 items)
    // ============================================

    const ICE_PRODUCTS = [
        16272, // Heavy Water
        16274, // Helium Isotopes
        17889, // Hydrogen Isotopes
        16273, // Liquid Ozone
        17888, // Nitrogen Isotopes
        17887, // Oxygen Isotopes
        16275, // Strontium Clathrates
    ];

    // ============================================
    // ABYSSAL ORES (10 items - Optional)
    // ============================================

    const ABYSSAL_ORES = [
        52306,  // Talassonite (base)
        56625,  // Abyssal Talassonite
        56626,  // Hadal Talassonite
        62582,  // Compressed Talassonite
        62583,  // Compressed Abyssal Talassonite
        62584,  // Compressed Hadal Talassonite
        56629,  // Abyssal Rakovene
        56630,  // Hadal Rakovene
        56627,  // Abyssal Bezdnacine
        56628,  // Hadal Bezdnacine
    ];

    // ============================================
    // NEW ORES - DEEP SPACE SURVEY (YC124)
    // ============================================

    /**
     * Mordunium Family - Pyerite-focused
     * Discovered by DEMY Deep Space Survey (YC124)
     * Found in nullsec/W-space with A0 stars and border systems
     */
    const MORDUNIUM_ORES = [
        74521,  // Mordunium (base)
        74522,  // Plum Mordunium (+5%)
        74523,  // Prize Mordunium (+10%)
        74524,  // Plunder Mordunium (+15%)
    ];

    const COMPRESSED_MORDUNIUM_ORES = [
        75275,  // Compressed Mordunium
        75276,  // Compressed Plum Mordunium
        75277,  // Compressed Prize Mordunium
        75278,  // Compressed Plunder Mordunium
    ];

    /**
     * Ytirium Family - Isogen-focused
     * Discovered by DEMY Deep Space Survey (YC124)
     * Found in nullsec/W-space with A0 stars and border systems
     */
    const YTIRIUM_ORES = [
        74525,  // Ytirium (base)
        74526,  // Bootleg Ytirium (+5%)
        74527,  // Firewater Ytirium (+10%)
        74528,  // Moonshine Ytirium (+15%)
    ];

    const COMPRESSED_YTIRIUM_ORES = [
        75279,  // Compressed Ytirium
        75280,  // Compressed Bootleg Ytirium
        75281,  // Compressed Firewater Ytirium
        75282,  // Compressed Moonshine Ytirium
    ];

    /**
     * Eifyrium Family - Zydrine-focused
     * Discovered by DEMY Deep Space Survey (YC124)
     * Found in nullsec/W-space with A0 stars and border systems
     */
    const EIFYRIUM_ORES = [
        74529,  // Eifyrium (base)
        74530,  // Doped Eifyrium (+5%)
        74531,  // Boosted Eifyrium (+10%)
        74532,  // Augmented Eifyrium (+15%)
    ];

    const COMPRESSED_EIFYRIUM_ORES = [
        75283,  // Compressed Eifyrium
        75284,  // Compressed Doped Eifyrium
        75285,  // Compressed Boosted Eifyrium
        75286,  // Compressed Augmented Eifyrium
    ];

    /**
     * Ducinium Family - Megacyte-focused
     * Discovered by DEMY Deep Space Survey (YC124)
     * Found in nullsec/W-space with A0 stars and border systems
     */
    const DUCINIUM_ORES = [
        74533,  // Ducinium (base)
        74534,  // Noble Ducinium (+5%)
        74535,  // Royal Ducinium (+10%)
        74536,  // Imperial Ducinium (+15%)
    ];

    const COMPRESSED_DUCINIUM_ORES = [
        75287,  // Compressed Ducinium
        75288,  // Compressed Noble Ducinium
        75289,  // Compressed Royal Ducinium
        75290,  // Compressed Imperial Ducinium
    ];

    // ============================================
    // NEW ORES - ORE PROSPECTING ARRAYS (YC126 - EQUINOX)
    // ============================================

    /**
     * Griemeer Family - Isogen-focused
     * From Ore Prospecting Arrays (Equinox Expansion YC126)
     * Found in nullsec sovereignty systems
     */
    const GRIEMEER_ORES = [
        81975,  // Griemeer (base)
        81976,  // Clear Griemeer (+5%)
        81977,  // Inky Griemeer (+10%)
        81978,  // Opaque Griemeer (+15%)
    ];

    const COMPRESSED_GRIEMEER_ORES = [
        82316,  // Compressed Griemeer
        82317,  // Compressed Clear Griemeer
        82318,  // Compressed Inky Griemeer
        82319,  // Compressed Opaque Griemeer
    ];

    /**
     * Nocxite Family - Nocxium-focused
     * From Ore Prospecting Arrays (Equinox Expansion YC126)
     * Found in nullsec sovereignty systems
     */
    const NOCXITE_ORES = [
        82016,  // Nocxite (base)
        82017,  // Fragrant Nocxite (+5%)
        82018,  // Intoxicating Nocxite (+10%)
        82019,  // Ambrosial Nocxite (+15%)
    ];

    const COMPRESSED_NOCXITE_ORES = [
        82304,  // Compressed Nocxite
        82305,  // Compressed Fragrant Nocxite
        82306,  // Compressed Intoxicating Nocxite
        82307,  // Compressed Ambrosial Nocxite
    ];

    /**
     * Kylixium Family - Mexallon-focused
     * From Ore Prospecting Arrays (Equinox Expansion YC126)
     * Found in nullsec sovereignty systems
     */
    const KYLIXIUM_ORES = [
        81900,  // Kylixium (base)
        81901,  // Kaolin Kylixium (+5%)
        81902,  // Argil Kylixium (+10%)
        81903,  // Adobe Kylixium (+15%)
    ];

    const COMPRESSED_KYLIXIUM_ORES = [
        82300,  // Compressed Kylixium
        82301,  // Compressed Kaolin Kylixium
        82302,  // Compressed Argil Kylixium
        82303,  // Compressed Adobe Kylixium
    ];

    /**
     * Hezorime Family - Zydrine-focused
     * From Ore Prospecting Arrays (Equinox Expansion YC126)
     * Found in nullsec sovereignty systems
     */
    const HEZORIME_ORES = [
        82163,  // Hezorime (base)
        82164,  // Dull Hezorime (+5%)
        82165,  // Serrated Hezorime (+10%)
        82166,  // Sharp Hezorime (+15%)
    ];

    const COMPRESSED_HEZORIME_ORES = [
        82312,  // Compressed Hezorime
        82313,  // Compressed Dull Hezorime
        82314,  // Compressed Serrated Hezorime
        82315,  // Compressed Sharp Hezorime
    ];

    /**
     * Ueganite Family - Megacyte-focused
     * From Ore Prospecting Arrays (Equinox Expansion YC126)
     * Found in nullsec sovereignty systems
     */
    const UEGANITE_ORES = [
        82205,  // Ueganite (base)
        82206,  // Foggy Ueganite (+5%)
        82207,  // Overcast Ueganite (+10%)
        82208,  // Stormy Ueganite (+15%)
    ];

    const COMPRESSED_UEGANITE_ORES = [
        82308,  // Compressed Ueganite
        82309,  // Compressed Foggy Ueganite
        82310,  // Compressed Overcast Ueganite
        82311,  // Compressed Stormy Ueganite
    ];

    // ============================================
    // AGGREGATE GETTERS
    // ============================================

    /**
     * Get all ore type IDs (regular ores only - legacy method)
     */
    public static function getAllRegularOres(): array
    {
        return self::REGULAR_ORES;
    }

    /**
     * Get all compressed regular ore type IDs
     */
    public static function getAllCompressedRegularOres(): array
    {
        return self::COMPRESSED_REGULAR_ORES;
    }

    /**
     * Get all moon ore type IDs (all variants)
     */
    public static function getAllMoonOres(): array
    {
        return self::MOON_ORES;
    }

    /**
     * Get all compressed moon ore type IDs (all variants)
     */
    public static function getAllCompressedMoonOres(): array
    {
        return self::COMPRESSED_MOON_ORES;
    }

    /**
     * Get all jackpot ore type IDs (both compressed and uncompressed)
     */
    public static function getAllJackpotOres(): array
    {
        return array_merge(
            self::JACKPOT_ORES_UNCOMPRESSED,
            self::JACKPOT_ORES_COMPRESSED
        );
    }

    /**
     * Get all ice type IDs (raw + compressed)
     */
    public static function getAllIce(): array
    {
        return array_merge(self::ICE, self::COMPRESSED_ICE);
    }

    /**
     * Get all gas type IDs
     */
    public static function getAllGas(): array
    {
        return array_merge(self::GAS_FULLERITES, self::GAS_BOOSTERS, self::GAS_CYTOSEROCIN, self::GAS_MYKOSEROCIN);
    }

    /**
     * Get all Triglavian/Pochven ore type IDs
     */
    public static function getAllTriglavianOres(): array
    {
        return self::TRIGLAVIAN_ORES;
    }

    /**
     * Get all moon material type IDs
     */
    public static function getAllMoonMaterials(): array
    {
        return array_merge(
            self::MOON_MATERIALS_R4,
            self::MOON_MATERIALS_R8,
            self::MOON_MATERIALS_R16,
            self::MOON_MATERIALS_R32,
            self::MOON_MATERIALS_R64
        );
    }

    /**
     * Get all refined materials (moon materials + minerals + ice products)
     * These are the materials that ores/ice refine into
     */
    public static function getAllRefinedMaterials(): array
    {
        return array_merge(
            self::getAllMoonMaterials(),  // 20 moon materials
            self::MINERALS,                // 8 minerals
            self::ICE_PRODUCTS             // 7 ice products
        );
    }

    /**
     * Get all Deep Space Survey ores (YC124)
     * Includes: Mordunium, Ytirium, Eifyrium, Ducinium
     */
    public static function getAllDeepSpaceSurveyOres(): array
    {
        return array_merge(
            self::MORDUNIUM_ORES,
            self::COMPRESSED_MORDUNIUM_ORES,
            self::YTIRIUM_ORES,
            self::COMPRESSED_YTIRIUM_ORES,
            self::EIFYRIUM_ORES,
            self::COMPRESSED_EIFYRIUM_ORES,
            self::DUCINIUM_ORES,
            self::COMPRESSED_DUCINIUM_ORES
        );
    }

    /**
     * Get all Ore Prospecting Array ores (YC126 - Equinox)
     * Includes: Griemeer, Nocxite, Kylixium, Hezorime, Ueganite
     */
    public static function getAllOreProspectingArrayOres(): array
    {
        return array_merge(
            self::GRIEMEER_ORES,
            self::COMPRESSED_GRIEMEER_ORES,
            self::NOCXITE_ORES,
            self::COMPRESSED_NOCXITE_ORES,
            self::KYLIXIUM_ORES,
            self::COMPRESSED_KYLIXIUM_ORES,
            self::HEZORIME_ORES,
            self::COMPRESSED_HEZORIME_ORES,
            self::UEGANITE_ORES,
            self::COMPRESSED_UEGANITE_ORES
        );
    }

    /**
     * Get all new ores (both Deep Space Survey and Ore Prospecting Arrays)
     */
    public static function getAllNewOres(): array
    {
        return array_merge(
            self::getAllDeepSpaceSurveyOres(),
            self::getAllOreProspectingArrayOres()
        );
    }

    /**
     * Get type IDs for a specific category
     * 
     * @param string $category Category name (ore, moon, ice, gas, minerals, materials, etc)
     * @return array
     */
    public static function getTypeIdsByCategory(string $category): array
    {
        switch (strtolower($category)) {
            case 'ore':
                return self::REGULAR_ORES;
            
            case 'compressed-ore':
                return self::COMPRESSED_REGULAR_ORES;
            
            case 'moon':
                return self::MOON_ORES;
            
            case 'compressed-moon':
                return self::COMPRESSED_MOON_ORES;
            
            case 'jackpot':
                return self::getAllJackpotOres();
            
            case 'ice':
                return self::getAllIce();
            
            case 'gas':
                return self::getAllGas();
            
            case 'minerals':
                return self::MINERALS;

            case 'materials':
                return self::getAllMoonMaterials();

            case 'refined-materials':
                return self::getAllRefinedMaterials();

            case 'ice-products':
                return self::ICE_PRODUCTS;
            
            case 'abyssal':
                return self::ABYSSAL_ORES;

            case 'triglavian':
            case 'pochven':
                return self::TRIGLAVIAN_ORES;
            
            // New ore categories
            case 'deep-space-survey':
                return self::getAllDeepSpaceSurveyOres();
            
            case 'ore-prospecting-array':
                return self::getAllOreProspectingArrayOres();
            
            case 'new-ores':
                return self::getAllNewOres();
            
            case 'griemeer':
                return array_merge(self::GRIEMEER_ORES, self::COMPRESSED_GRIEMEER_ORES);
            
            case 'nocxite':
                return array_merge(self::NOCXITE_ORES, self::COMPRESSED_NOCXITE_ORES);
            
            case 'kylixium':
                return array_merge(self::KYLIXIUM_ORES, self::COMPRESSED_KYLIXIUM_ORES);
            
            case 'hezorime':
                return array_merge(self::HEZORIME_ORES, self::COMPRESSED_HEZORIME_ORES);
            
            case 'ueganite':
                return array_merge(self::UEGANITE_ORES, self::COMPRESSED_UEGANITE_ORES);
            
            case 'mordunium':
                return array_merge(self::MORDUNIUM_ORES, self::COMPRESSED_MORDUNIUM_ORES);
            
            case 'ytirium':
                return array_merge(self::YTIRIUM_ORES, self::COMPRESSED_YTIRIUM_ORES);
            
            case 'eifyrium':
                return array_merge(self::EIFYRIUM_ORES, self::COMPRESSED_EIFYRIUM_ORES);
            
            case 'ducinium':
                return array_merge(self::DUCINIUM_ORES, self::COMPRESSED_DUCINIUM_ORES);
            
            case 'compressed':
                return array_merge(
                    self::COMPRESSED_REGULAR_ORES,
                    self::COMPRESSED_MOON_ORES,
                    self::COMPRESSED_MORDUNIUM_ORES,
                    self::COMPRESSED_YTIRIUM_ORES,
                    self::COMPRESSED_EIFYRIUM_ORES,
                    self::COMPRESSED_DUCINIUM_ORES,
                    self::COMPRESSED_GRIEMEER_ORES,
                    self::COMPRESSED_NOCXITE_ORES,
                    self::COMPRESSED_KYLIXIUM_ORES,
                    self::COMPRESSED_HEZORIME_ORES,
                    self::COMPRESSED_UEGANITE_ORES
                );
            
            case 'all':
                return array_merge(
                    self::REGULAR_ORES,
                    self::COMPRESSED_REGULAR_ORES,
                    self::MOON_ORES,
                    self::COMPRESSED_MOON_ORES,
                    self::getAllIce(),
                    self::getAllGas(),
                    self::MINERALS,
                    self::getAllMoonMaterials(),
                    self::ICE_PRODUCTS,
                    self::ABYSSAL_ORES,
                    self::TRIGLAVIAN_ORES,
                    self::getAllNewOres()
                );
            
            default:
                return [];
        }
    }

    /**
     * Get count of items in a category
     */
    public static function getCategoryCount(string $category): int
    {
        return count(self::getTypeIdsByCategory($category));
    }

    /**
     * Get total count of all tracked type IDs
     */
    public static function getTotalTrackedTypeIds(): int
    {
        return count(self::getTypeIdsByCategory('all'));
    }

    // ============================================
    // MOON ORE VARIANT HELPERS
    // ============================================

    /**
     * Get all improved ore type IDs (+15% variants)
     * For both uncompressed and compressed
     */
    public static function getImprovedOreTypeIds(): array
    {
        return [
            // Uncompressed improved ores (+15%)
            46284, 46286, 46282, 46280,  // R4 improved
            46288, 46290, 46294, 46292,  // R8 improved
            46302, 46296, 46298, 46300,  // R16 improved
            46304, 46310, 46308, 46306,  // R32 improved
            46312, 46314, 46316, 46318,  // R64 improved
        ];
    }

    /**
     * Get all compressed improved ore type IDs (+15% variants)
     */
    public static function getCompressedImprovedOreTypeIds(): array
    {
        return [
            62455, 62458, 62461, 62464,  // R4 compressed improved
            62475, 62472, 62469, 62478,  // R8 compressed improved
            62481, 62484, 62487, 62490,  // R16 compressed improved
            62493, 62496, 62499, 62502,  // R32 compressed improved
            62511, 62508, 62505, 62514,  // R64 compressed improved
        ];
    }

    /**
     * Get base ore type IDs (no modifiers)
     * For both uncompressed and compressed
     */
    public static function getBaseOreTypeIds(): array
    {
        return [
            // R4 base
            45492, 45493, 45491, 45490,
            // R8 base
            45494, 45495, 45497, 45496,
            // R16 base
            45501, 45498, 45499, 45500,
            // R32 base
            45502, 45506, 45504, 45503,
            // R64 base
            45510, 45511, 45512, 45513,
        ];
    }

    /**
     * Get moon ore rarity mapping
     * Returns array mapping rarity level to type IDs
     */
    public static function getMoonOreRarityMap(): array
    {
        return [
            'R4' => [45492, 46284, 46285, 45493, 46286, 46287, 45491, 46282, 46283, 45490, 46280, 46281],
            'R8' => [45494, 46288, 46289, 45495, 46290, 46291, 45497, 46294, 46295, 45496, 46292, 46293],
            'R16' => [45501, 46302, 46303, 45498, 46296, 46297, 45499, 46298, 46299, 45500, 46300, 46301],
            'R32' => [45502, 46304, 46305, 45506, 46310, 46311, 45504, 46308, 46309, 45503, 46306, 46307],
            'R64' => [45510, 46312, 46313, 45511, 46314, 46315, 45512, 46316, 46317, 45513, 46318, 46319],
        ];
    }

    /**
     * Get type IDs for a specific rarity level
     */
    public static function getMoonOresByRarity(string $rarity): array
    {
        $rarityMap = self::getMoonOreRarityMap();
        return $rarityMap[$rarity] ?? [];
    }

    /**
     * Get the rarity level for a moon ore type ID.
     *
     * @param int $typeId
     * @return string|null Lowercase rarity (r4, r8, r16, r32, r64) or null if not moon ore
     */
    public static function getMoonOreRarity(int $typeId): ?string
    {
        $rarityMap = self::getMoonOreRarityMap();

        foreach ($rarityMap as $rarity => $typeIds) {
            if (in_array($typeId, $typeIds)) {
                return strtolower($rarity);
            }
        }

        return null;
    }

    /**
     * Check if a type ID is a regular ore (including new ores)
     */
    public static function isRegularOre(int $typeId): bool
    {
        return in_array($typeId, self::REGULAR_ORES) ||
               in_array($typeId, self::COMPRESSED_REGULAR_ORES) ||
               in_array($typeId, self::getAllNewOres());
    }

    /**
     * Check if a type ID is a moon ore (any variant)
     */
    public static function isMoonOre(int $typeId): bool
    {
        return in_array($typeId, self::MOON_ORES) ||
               in_array($typeId, self::COMPRESSED_MOON_ORES);
    }

    /**
     * Check if a type ID is compressed
     */
    public static function isCompressedOre(int $typeId): bool
    {
        return in_array($typeId, self::COMPRESSED_REGULAR_ORES) ||
               in_array($typeId, self::COMPRESSED_MOON_ORES) ||
               in_array($typeId, self::COMPRESSED_MORDUNIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_YTIRIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_EIFYRIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_DUCINIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_GRIEMEER_ORES) ||
               in_array($typeId, self::COMPRESSED_NOCXITE_ORES) ||
               in_array($typeId, self::COMPRESSED_KYLIXIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_HEZORIME_ORES) ||
               in_array($typeId, self::COMPRESSED_UEGANITE_ORES);
    }

    /**
     * Check if a type ID is ice
     */
    public static function isIce(int $typeId): bool
    {
        return in_array($typeId, self::ICE) ||
               in_array($typeId, self::COMPRESSED_ICE);
    }

    /**
     * Check if a type ID is gas
     */
    public static function isGas(int $typeId): bool
    {
        return in_array($typeId, self::GAS_FULLERITES) ||
               in_array($typeId, self::GAS_BOOSTERS) ||
               in_array($typeId, self::GAS_CYTOSEROCIN) ||
               in_array($typeId, self::GAS_MYKOSEROCIN);
    }

    /**
     * Check if a type ID is a Triglavian/Pochven ore
     */
    public static function isTriglavianOre(int $typeId): bool
    {
        return in_array($typeId, self::TRIGLAVIAN_ORES);
    }

    // ============================================
    // NEW ORE FAMILY HELPERS
    // ============================================

    /**
     * Check if a type ID is Griemeer ore
     */
    public static function isGriemeer(int $typeId): bool
    {
        return in_array($typeId, self::GRIEMEER_ORES) ||
               in_array($typeId, self::COMPRESSED_GRIEMEER_ORES);
    }

    /**
     * Check if a type ID is Nocxite ore
     */
    public static function isNocxite(int $typeId): bool
    {
        return in_array($typeId, self::NOCXITE_ORES) ||
               in_array($typeId, self::COMPRESSED_NOCXITE_ORES);
    }

    /**
     * Check if a type ID is Kylixium ore
     */
    public static function isKylixium(int $typeId): bool
    {
        return in_array($typeId, self::KYLIXIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_KYLIXIUM_ORES);
    }

    /**
     * Check if a type ID is Hezorime ore
     */
    public static function isHezorime(int $typeId): bool
    {
        return in_array($typeId, self::HEZORIME_ORES) ||
               in_array($typeId, self::COMPRESSED_HEZORIME_ORES);
    }

    /**
     * Check if a type ID is Ueganite ore
     */
    public static function isUeganite(int $typeId): bool
    {
        return in_array($typeId, self::UEGANITE_ORES) ||
               in_array($typeId, self::COMPRESSED_UEGANITE_ORES);
    }

    /**
     * Check if a type ID is Mordunium ore
     */
    public static function isMordunium(int $typeId): bool
    {
        return in_array($typeId, self::MORDUNIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_MORDUNIUM_ORES);
    }

    /**
     * Check if a type ID is Ytirium ore
     */
    public static function isYtirium(int $typeId): bool
    {
        return in_array($typeId, self::YTIRIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_YTIRIUM_ORES);
    }

    /**
     * Check if a type ID is Eifyrium ore
     */
    public static function isEifyrium(int $typeId): bool
    {
        return in_array($typeId, self::EIFYRIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_EIFYRIUM_ORES);
    }

    /**
     * Check if a type ID is Ducinium ore
     */
    public static function isDucinium(int $typeId): bool
    {
        return in_array($typeId, self::DUCINIUM_ORES) ||
               in_array($typeId, self::COMPRESSED_DUCINIUM_ORES);
    }

    /**
     * Check if a type ID is from Deep Space Survey ores
     */
    public static function isDeepSpaceSurveyOre(int $typeId): bool
    {
        return self::isMordunium($typeId) ||
               self::isYtirium($typeId) ||
               self::isEifyrium($typeId) ||
               self::isDucinium($typeId);
    }

    /**
     * Check if a type ID is from Ore Prospecting Array ores
     */
    public static function isOreProspectingArrayOre(int $typeId): bool
    {
        return self::isGriemeer($typeId) ||
               self::isNocxite($typeId) ||
               self::isKylixium($typeId) ||
               self::isHezorime($typeId) ||
               self::isUeganite($typeId);
    }
}
