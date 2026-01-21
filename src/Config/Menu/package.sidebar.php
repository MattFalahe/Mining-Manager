<?php

return [
    'mining-manager' => [
        'name'          => 'Mining Manager',
        'label'         => 'mining-manager::menu.mining_manager',
        'plural'        => true,
        'icon'          => 'fas fa-industry',
        'route_segment' => 'mining-manager',
        'permission'    => 'mining-manager.view',
        'entries'       => [
            [
                'name'       => 'Dashboard',
                'label'      => 'mining-manager::menu.dashboard',
                'icon'       => 'fas fa-tachometer-alt',
                'route'      => 'mining-manager.dashboard',
                'permission' => 'mining-manager.view',
            ],
            [
                'name'       => 'Mining Ledger',
                'label'      => 'mining-manager::menu.mining_ledger',
                'icon'       => 'fas fa-book',
                'route'      => 'mining-manager.ledger.index',
                'permission' => 'mining-manager.ledger.view',
            ],
            [
                'name'       => 'Tax Management',
                'label'      => 'mining-manager::menu.tax_management',
                'icon'       => 'fas fa-file-invoice-dollar',
                'route'      => 'mining-manager.taxes.index',
                'permission' => 'mining-manager.tax.view',
            ],
            [
                'name'       => 'Mining Events',
                'label'      => 'mining-manager::menu.mining_events',
                'icon'       => 'fas fa-users',
                'route'      => 'mining-manager.events.index',
                'permission' => 'mining-manager.events.view',
            ],
            [
                'name'       => 'Moon Extractions',
                'label'      => 'mining-manager::menu.moon_extractions',
                'icon'       => 'fas fa-moon',
                'route'      => 'mining-manager.moon.index',
                'permission' => 'mining-manager.moon.view',
            ],
            [
                'name'       => 'Analytics',
                'label'      => 'mining-manager::menu.analytics',
                'icon'       => 'fas fa-chart-line',
                'route'      => 'mining-manager.analytics.index',
                'permission' => 'mining-manager.analytics.view',
            ],
            [
                'name'       => 'Reports',
                'label'      => 'mining-manager::menu.reports',
                'icon'       => 'fas fa-file-alt',
                'route'      => 'mining-manager.reports.index',
                'permission' => 'mining-manager.reports.view',
            ],
            [
                'name'       => 'Theft Detection',
                'label'      => 'mining-manager::menu.theft_detection',
                'icon'       => 'fas fa-exclamation-triangle',
                'route'      => 'mining-manager.theft.index',
                'permission' => 'mining-manager.view',
            ],
            [
                'name'       => 'Settings',
                'label'      => 'mining-manager::menu.settings',
                'icon'       => 'fas fa-cog',
                'route'      => 'mining-manager.settings.index',
                'permission' => 'mining-manager.settings.view',
            ],
            [
                'name'       => 'Help',
                'label'      => 'mining-manager::menu.help',
                'icon'       => 'fas fa-question-circle',
                'route'      => 'mining-manager.help',
                'permission' => 'mining-manager.view',
            ],
        ]
    ]
];
