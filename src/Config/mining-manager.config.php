<?php

/*
|--------------------------------------------------------------------------
| Mining Manager - Default Configuration
|--------------------------------------------------------------------------
|
| These are default values that can be overridden via the Settings UI.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | General Settings
    |--------------------------------------------------------------------------
    */
    'general' => [
        // Corporation ID (can be set via settings page)
        'corporation_id' => null,

        // Ore valuation method: 'ore_price' or 'mineral_price'
        'ore_valuation_method' => 'mineral_price',

        // Price provider: 'eve_market' or 'janice'
        'price_provider' => 'eve_market',

        // API key for price provider (if using Janice)
        'price_provider_api_key' => env('MINING_MANAGER_JANICE_API_KEY', ''),

        // Price modifier percentage (adjusts all prices by this %)
        'price_modifier' => 0.0,

        // Market hub region ID (10000002 = The Forge/Jita)
        'default_region_id' => 10000002,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Payment Method
    |--------------------------------------------------------------------------
    | Players pay their mining taxes via direct wallet transfers with tax codes.
    */
    'tax_payment' => [
        // Payment method (wallet transfers only)
        'method' => 'wallet',

        // Grace period days after month end before overdue
        'grace_period_days' => 7,

        // Minimum tax amount to collect (ISK)
        'minimum_tax_amount' => 1000000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet Transfer Payment Settings
    |--------------------------------------------------------------------------
    | Settings for wallet transfer-based tax payment method with tax codes
    */
    'wallet' => [
        // Match tolerance (ISK) - allow this much difference
        'match_tolerance' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Rates
    |--------------------------------------------------------------------------
    */
    'tax_rates' => [
        // Moon ore tax rates by rarity (percentage)
        'moon_ore' => [
            'r64' => 15.0,  // Exceptional (Zeolites, Sylvite, etc.)
            'r32' => 12.0,  // Rare (Cobaltite, Euxenite, etc.)
            'r16' => 10.0,  // Uncommon (Carnotite, Cinnabar, etc.)
            'r8' => 8.0,    // Common (Caesium, Hafnium, etc.)
            'r4' => 5.0,    // Ubiquitous
        ],

        // Regular ore types (percentage)
        'ice' => 10.0,
        'ore' => 10.0,
        'gas' => 10.0,
        'abyssal_ore' => 15.0,
        'triglavian_ore' => 10.0,

        // Note: Event tax modifiers are configured per-event, not globally.
        // See event creation/edit for tax modifier options (-100% to +100%).
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Selector - What to Tax
    |--------------------------------------------------------------------------
    */
    'tax_selector' => [
        'all_moon_ore' => true,          // Tax all moon ore
        'only_corp_moon_ore' => false,   // Only tax if mined at corp structures
        'ore' => true,                   // Regular ore
        'ice' => true,                   // Ice products
        'gas' => false,                  // Gas clouds
        'abyssal_ore' => false,          // Abyssal trace materials
        'triglavian_ore' => false,       // Triglavian/Pochven ores
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */
    'pricing' => [
        // Price type: 'sell', 'buy', 'average'
        'price_type' => 'sell',

        // Cache price data duration (minutes)
        'cache_duration' => 240,

        // Use refined mineral values instead of raw ore
        'use_refined_value' => false,

        // Refining efficiency percentage (for refined value calculations)
        'refining_efficiency' => 87.5,

        // Fallback to Jita prices if regional data unavailable
        'fallback_to_jita' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Moon Extractions
    |--------------------------------------------------------------------------
    */
    'moon' => [
        // Estimated chunk size in cubic meters (m3)
        // Moon chunks typically range from 100,000 to 200,000 m3
        // Adjust based on your corporation's moon sizes
        'estimated_chunk_size' => env('MOON_CHUNK_SIZE', 150000),

        // Auto-calculate extraction values
        'auto_calculate_values' => true,

        // Include unscanned moons in reports
        'show_unscanned_moons' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    | Enable or disable major features of the Mining Manager plugin.
    */
    'features' => [
        // Core features
        'enable_tax_tracking' => true,
        'enable_ledger_tracking' => true,
        'enable_analytics' => true,
        'enable_reports' => true,

        // Events
        'enable_events' => true,
        'allow_event_creation' => true,

        // Moon mining
        'enable_moon_tracking' => true,

        // Permissions & access
        'allow_public_stats' => false,
        'allow_member_leaderboard' => true,
        'show_character_names' => true,
        'allow_export_data' => true,

        // Automation
        'auto_process_ledger' => true,
        'auto_calculate_taxes' => true,
        'auto_generate_invoices' => true,
        'verify_wallet_transactions' => true,

        // Data retention
        'ledger_retention_days' => 365,
        'tax_record_retention_days' => 730,
        'auto_cleanup_old_data' => false,
    ],

];
