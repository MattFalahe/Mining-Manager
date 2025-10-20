<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | General Settings
    |--------------------------------------------------------------------------
    */
    'general' => [
        // Corporation ID (can be set via settings page)
        'corporation_id' => null,
        
        // Ore refining efficiency percentage (0-100)
        'ore_refining_rate' => 90.0,
        
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
        
        // Tax calculation method: 'accumulated' or 'individually'
        // accumulated: Group alts under main character
        // individually: Each character taxed separately
        'tax_calculation_method' => 'accumulated',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Payment Method
    |--------------------------------------------------------------------------
    | Choose how players pay their mining taxes:
    | - 'contract': Item exchange contracts issued by designated character
    | - 'wallet': Direct wallet transfers with tax codes
    */
    'tax_payment' => [
        // Active payment method: 'contract' or 'wallet'
        'method' => 'wallet',
        
        // Grace period days after month end before overdue
        'grace_period_days' => 7,
        
        // Send reminder notifications
        'send_reminders' => true,
        
        // Days before due date to send reminder
        'reminder_days_before' => [3, 1],
        
        // Minimum tax amount to collect (ISK)
        'minimum_tax_amount' => 1000000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Contract Payment Settings
    |--------------------------------------------------------------------------
    | Settings for contract-based tax payment method
    */
    'contract' => [
        // Character name who issues tax contracts
        // This character MUST have ESI scopes for contract creation
        'issuer_character_name' => '',
        
        // Character ID of contract issuer (auto-filled from name)
        'issuer_character_id' => null,
        
        // Contract description template
        // Available variables: {year}, {month}, {month_name}, {character}, {amount}
        'contract_tag' => 'MINC TAX {year}-{month}',
        
        // Contract title template
        'contract_title' => 'Mining Tax - {month_name} {year}',
        
        // Minimum tax value to create contract (ISK)
        'minimum_contract_value' => 1000000,
        
        // Contract expiration time (days)
        'expire_in_days' => 7,
        
        // Contract type: 'item_exchange'
        'contract_type' => 'item_exchange',
        
        // Auto-generate contracts after tax calculation
        'auto_generate' => false,
        
        // Automatically mark paid when contract completed
        'auto_mark_paid' => true,
        
        // Delete expired contracts and regenerate
        'auto_regenerate_expired' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet Transfer Payment Settings
    |--------------------------------------------------------------------------
    | Settings for wallet transfer-based tax payment method with tax codes
    */
    'wallet' => [
        // Enable tax code generation
        'enable_tax_codes' => true,
        
        // Tax code prefix
        'tax_code_prefix' => 'TAX-',
        
        // Tax code length (excluding prefix) - characters
        'tax_code_length' => 6,
        
        // Tax code format: 'alphanumeric', 'numeric', 'alpha'
        'tax_code_format' => 'alphanumeric',
        
        // Auto-generate tax codes after calculation
        'auto_generate_codes' => true,
        
        // Automatically match wallet journal entries to taxes
        'auto_match_payments' => true,
        
        // Require exact amount match (vs allow overpayment)
        'require_exact_amount' => false,
        
        // Allow partial payments
        'allow_partial_payments' => true,
        
        // Minimum partial payment amount (ISK)
        'minimum_partial_payment' => 1000000,
        
        // Match tolerance (ISK) - allow this much difference
        'match_tolerance' => 1000,
        
        // Corporation wallet division to monitor (1-7)
        'wallet_division' => 1,
        
        // Reference types to watch in wallet journal
        'watch_reference_types' => [
            'player_donation',           // Most common for tax payments
            'corporation_account_withdrawal',
            'bounty_prizes',            // Sometimes used
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Calculation Data Source
    |--------------------------------------------------------------------------
    | Where to pull data from when calculating taxes
    */
    'calculation_source' => [
        // Data source: 'archived', 'live', 'hybrid'
        // archived: Use cached mining_ledger data
        // live: Fetch fresh data from ESI
        // hybrid: Use archived but verify with ESI
        'source' => 'archived',
        
        // Verify archived data age (hours)
        'max_data_age_hours' => 24,
        
        // Re-fetch if data older than threshold
        'auto_refresh_old_data' => true,
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
        
        // Mining event bonuses (percentage reduction)
        'event_bonus' => 2.0,  // 2% tax reduction for event participation
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Tax Tracking
    |--------------------------------------------------------------------------
    | Display current month's mining activity in real-time
    */
    'live_tracking' => [
        // Enable live tracking display
        'enabled' => true,
        
        // Refresh interval (seconds) for live data
        'refresh_interval' => 300,
        
        // Show individual character breakdown
        'show_character_breakdown' => true,
        
        // Number of top miners to display
        'top_miners_count' => 10,
        
        // Calculate estimated tax in real-time
        'show_estimated_tax' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        // Send notification when tax calculated
        'on_tax_calculated' => true,
        
        // Send reminder notifications
        'on_reminder' => true,
        
        // Send notification when payment received
        'on_payment_received' => true,
        
        // Send notification when marked overdue
        'on_overdue' => true,
        
        // Notification channels: 'mail', 'discord', 'slack'
        'channels' => ['mail'],
        
        // Discord webhook URL for notifications
        'discord_webhook' => env('MINING_MANAGER_DISCORD_WEBHOOK', ''),
        
        // Slack webhook URL for notifications
        'slack_webhook' => env('MINING_MANAGER_SLACK_WEBHOOK', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual Actions
    |--------------------------------------------------------------------------
    | Settings for manual tax management
    */
    'manual_actions' => [
        // Allow recalculation of already calculated taxes
        'allow_recalculation' => true,
        
        // Allow deletion of tax records
        'allow_deletion' => true,
        
        // Allow manual marking as paid
        'allow_manual_payment' => true,
        
        // Require reason when manually marking paid
        'require_payment_reason' => true,
        
        // Log all manual actions
        'log_manual_actions' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Moon Ore Rarity Mapping
    |--------------------------------------------------------------------------
    | Maps type_ids to rarity levels for moon ore
    */
    'moon_ore_rarity' => [
        // R64 (Exceptional)
        'r64' => [
            45490, // Zeolites
            45491, // Sylvite
            45492, // Bitumens
            45493, // Coesite
            // Compressed variants
            46676, // Compressed Zeolites
            46677, // Compressed Sylvite
            46678, // Compressed Bitumens
            46679, // Compressed Coesite
        ],
        
        // R32 (Rare)
        'r32' => [
            45494, // Cobaltite
            45495, // Euxenite
            45496, // Titanite
            45497, // Scheelite
            45498, // Otavite
            45499, // Sperrylite
            45500, // Vanadinite
            45501, // Chromite
            // Compressed variants
            46680, 46681, 46682, 46683, 46684, 46685, 46686, 46687,
        ],
        
        // R16 (Uncommon)
        'r16' => [
            45502, // Carnotite
            45503, // Cinnabar
            45504, // Pollucite
            45505, // Zircon
            45506, // Loparite
            45507, // Monazite
            45508, // Xenotime
            45509, // Ytterbite
            // Compressed variants
            46688, 46689, 46690, 46691, 46692, 46693, 46694, 46695,
        ],
        
        // R8 (Common)
        'r8' => [
            45510, // Caesium
            45511, // Hafnium
            45512, // Mercury
            45513, // Scandium
            45514, // Dysprosium
            45515, // Neodymium
            45516, // Promethium
            45517, // Thulium
            // Compressed variants
            46696, 46697, 46698, 46699, 46700, 46701, 46702, 46703,
        ],
        
        // R4 (Ubiquitous)
        'r4' => [
            45518, // Atmospheric Gases
            45519, // Evaporite Deposits
            45520, // Hydrocarbons
            45521, // Silicates
            // Compressed variants
            46704, 46705, 46706, 46707,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ore Categories
    |--------------------------------------------------------------------------
    | Type IDs for different ore categories
    */
    'ore_categories' => [
        // Ice type IDs
        'ice' => [
            16262, // Clear Icicle
            17975, // Glacial Mass
            16263, // Blue Ice
            17976, // White Glaze
            16264, // Dark Glitter
            // etc.
        ],
        
        // Regular ore (examples - add all type IDs)
        'ore' => [
            1230,  // Veldspar
            1228,  // Scordite
            1224,  // Pyroxeres
            1225,  // Plagioclase
            1232,  // Omber
            // etc.
        ],
        
        // Gas (if needed)
        'gas' => [
            // Add gas type IDs
        ],
        
        // Abyssal ore (if needed)
        'abyssal_ore' => [
            // Add abyssal type IDs
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */
    'pricing' => [
        // Price type: 'sell', 'buy', 'average'
        'price_type' => 'sell',
        
        // Use compressed ore prices if available
        'prefer_compressed_prices' => false,
        
        // Cache price data duration (hours)
        'cache_duration' => 24,
        
        // Fallback price if no market data (ISK per unit)
        'fallback_price' => 100,
        
        // Minimum price threshold (ISK)
        'minimum_price' => 0.01,
    ],

];
