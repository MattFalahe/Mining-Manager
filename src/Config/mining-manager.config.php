<?php

return [
    'version' => '1.0.0',
    
    // System configuration (not user-editable)
    'system' => [
        'cache_prefix' => 'mining_manager_',
        'cache_ttl' => 300, // 5 minutes
        'chunk_size' => 1000,
        'queue_name' => 'mining',
        'max_chart_points' => 100,
    ],
    
    // Default values for initial setup (will be stored in DB after)
    'defaults' => [
        'corporation_id' => null,
        'refining_efficiency' => 90.6,
        'price_source' => 'market',
        'price_modifier' => 100,
        'tax_calculation_method' => 'main_character',
        'minimum_contract_value' => 1000000,
        'contract_expiry_days' => 7,
        'contract_tag' => 'Mining Tax',
        'enable_notifications' => true,
        'auto_generate_invoices' => false,
    ],
    
    // Ore group definitions (system-level, not editable)
    'ore_groups' => [
        'standard' => [450, 451, 452, 453, 454, 455, 456, 457, 458, 459, 460, 461, 462, 467, 468, 469],
        'ice' => [465],
        'gas' => [711],
        'moon_r4' => [1884],
        'moon_r8' => [1920],
        'moon_r16' => [1921],
        'moon_r32' => [1922],
        'moon_r64' => [1923],
        'abyssal' => [1996],
    ],
    
    // Features that can be enabled/disabled
    'features' => [
        'events' => true,
        'moon_tracking' => true,
        'tax_invoices' => true,
        'analytics' => true,
        'reports' => true,
        'notifications' => true,
        'api_access' => true,
    ],
];
