<?php

return [
    'mining-manager' => [
        'name' => 'Mining Manager',
        'label' => 'mining-manager::menu.title',
        'plural' => true,
        'icon' => 'fas fa-gem',
        'route_segment' => 'mining',
        'permission' => 'mining-manager.view',
        'entries' => [
            [
                'name' => 'Dashboard',
                'label' => 'mining-manager::menu.dashboard',
                'icon' => 'fas fa-tachometer-alt',
                'route' => 'mining.dashboard',
                'permission' => 'mining-manager.view'
            ],
            [
                'name' => 'Analytics',
                'label' => 'mining-manager::menu.analytics',
                'icon' => 'fas fa-chart-line',
                'route' => 'mining.analytics',
                'permission' => 'mining-manager.analytics'
            ],
            [
                'name' => 'Tax Management',
                'label' => 'mining-manager::menu.taxes',
                'icon' => 'fas fa-calculator',
                'route' => 'mining.taxes',
                'permission' => 'mining-manager.taxes'
            ],
            [
                'name' => 'Events',
                'label' => 'mining-manager::menu.events',
                'icon' => 'fas fa-calendar-alt',
                'route' => 'mining.events',
                'permission' => 'mining-manager.events'
            ],
            [
                'name' => 'Moon Tracking',
                'label' => 'mining-manager::menu.moons',
                'icon' => 'fas fa-moon',
                'route' => 'mining.moons',
                'permission' => 'mining-manager.moons'
            ],
            [
                'name' => 'Reports',
                'label' => 'mining-manager::menu.reports',
                'icon' => 'fas fa-file-alt',
                'route' => 'mining.reports',
                'permission' => 'mining-manager.reports'
            ],
            [
                'name' => 'Settings',
                'label' => 'mining-manager::menu.settings',
                'icon' => 'fas fa-cog',
                'route' => 'mining.settings',
                'permission' => 'mining-manager.admin'
            ],
        ],
    ],
];
