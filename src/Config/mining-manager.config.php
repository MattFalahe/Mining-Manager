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
        
        // Default region for price lookups
        'default_region_id' => 10000002, // The Forge (Jita)
        
        // Use refined mineral values instead of raw ore
        'use_refined_value' => false,
        
        // Refining efficiency percentage (for refined value calculations)
        'refining_efficiency' => 87.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Moon Extractions
    |--------------------------------------------------------------------------
    */
    'moon' => [
        // Estimated chunk size in cubic meters (m³)
        // Moon chunks typically range from 100,000 to 200,000 m³
        // Adjust based on your corporation's moon sizes
        'estimated_chunk_size' => env('MOON_CHUNK_SIZE', 150000),
        
        // Hours before extraction to send notifications
        // Example: [24, 4, 1] sends at 24h, 4h, and 1h before chunk arrival
        'notification_hours_before' => [24, 4, 1],
        
        // Auto-calculate extraction values
        'auto_calculate_values' => true,
        
        // Include unscanned moons in reports
        'show_unscanned_moons' => true,
    ],

];
