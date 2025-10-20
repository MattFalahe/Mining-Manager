<?php

return [
    'mining-manager' => [
        'permission' => 'mining-manager.view',
        'name' => 'mining-manager::menu.mining_manager',
        'icon' => 'fas fa-industry',
        'route_segment' => 'mining-manager',
        'entries' => [
            
            // ==================== DASHBOARD ====================
            [
                'name' => 'mining-manager::menu.dashboard',
                'icon' => 'fas fa-tachometer-alt',
                'route' => 'mining-manager.dashboard.index',
                'permission' => 'mining-manager.view',
            ],

            // ==================== MINING LEDGER ====================
            [
                'name' => 'mining-manager::menu.mining_ledger',
                'icon' => 'fas fa-book',
                'permission' => 'mining-manager.ledger.view',
                'entries' => [
                    [
                        'name' => 'mining-manager::menu.view_ledger',
                        'icon' => 'fas fa-list',
                        'route' => 'mining-manager.ledger.index',
                        'permission' => 'mining-manager.ledger.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.my_mining',
                        'icon' => 'fas fa-user',
                        'route' => 'mining-manager.ledger.my-mining',
                        'permission' => 'mining-manager.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.process_ledger',
                        'icon' => 'fas fa-cogs',
                        'route' => 'mining-manager.ledger.process',
                        'permission' => 'mining-manager.ledger.process',
                    ],
                ],
            ],

            // ==================== TAX MANAGEMENT ====================
            [
                'name' => 'mining-manager::menu.tax_management',
                'icon' => 'fas fa-file-invoice-dollar',
                'permission' => 'mining-manager.tax.view',
                'entries' => [
                    [
                        'name' => 'mining-manager::menu.tax_overview',
                        'icon' => 'fas fa-chart-pie',
                        'route' => 'mining-manager.taxes.index',
                        'permission' => 'mining-manager.tax.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.calculate_taxes',
                        'icon' => 'fas fa-calculator',
                        'route' => 'mining-manager.taxes.calculate',
                        'permission' => 'mining-manager.tax.calculate',
                    ],
                    [
                        'name' => 'mining-manager::menu.my_taxes',
                        'icon' => 'fas fa-receipt',
                        'route' => 'mining-manager.taxes.my-taxes',
                        'permission' => 'mining-manager.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.tax_codes',
                        'icon' => 'fas fa-barcode',
                        'route' => 'mining-manager.taxes.codes',
                        'permission' => 'mining-manager.tax.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.tax_contracts',
                        'icon' => 'fas fa-file-contract',
                        'route' => 'mining-manager.taxes.contracts',
                        'permission' => 'mining-manager.tax.generate_invoices',
                    ],
                    [
                        'name' => 'mining-manager::menu.wallet_verification',
                        'icon' => 'fas fa-wallet',
                        'route' => 'mining-manager.taxes.wallet',
                        'permission' => 'mining-manager.tax.verify_payments',
                    ],
                ],
            ],

            // ==================== MINING EVENTS ====================
            [
                'name' => 'mining-manager::menu.mining_events',
                'icon' => 'fas fa-users',
                'permission' => 'mining-manager.events.view',
                'entries' => [
                    [
                        'name' => 'mining-manager::menu.all_events',
                        'icon' => 'fas fa-list',
                        'route' => 'mining-manager.events.index',
                        'permission' => 'mining-manager.events.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.active_events',
                        'icon' => 'fas fa-play-circle',
                        'route' => 'mining-manager.events.active',
                        'permission' => 'mining-manager.events.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.create_event',
                        'icon' => 'fas fa-plus-circle',
                        'route' => 'mining-manager.events.create',
                        'permission' => 'mining-manager.events.create',
                    ],
                    [
                        'name' => 'mining-manager::menu.event_calendar',
                        'icon' => 'fas fa-calendar-alt',
                        'route' => 'mining-manager.events.calendar',
                        'permission' => 'mining-manager.events.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.my_events',
                        'icon' => 'fas fa-user-check',
                        'route' => 'mining-manager.events.my-events',
                        'permission' => 'mining-manager.view',
                    ],
                    [
                        'name' => 'Calendar',
                        'icon' => 'fas fa-calendar-alt',
                        'route' => 'mining-manager.events.calendar',
                        'permission' => 'mining-manager.events.view',
                    ],
                    [
                        'name' => 'My Events',
                        'icon' => 'fas fa-user-clock',
                        'route' => 'mining-manager.events.my-events',
                        'permission' => 'mining-manager.events.view',
                    ],

                ],
            ],

            // ==================== MOON EXTRACTIONS ====================
            [
                'name' => 'mining-manager::menu.moon_extractions',
                'icon' => 'fas fa-moon',
                'permission' => 'mining-manager.moon.view',
                'entries' => [
                    [
                        'name' => 'mining-manager::menu.all_extractions',
                        'icon' => 'fas fa-list',
                        'route' => 'mining-manager.moon.index',
                        'permission' => 'mining-manager.moon.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.active_extractions',
                        'icon' => 'fas fa-hourglass-half',
                        'route' => 'mining-manager.moon.active',
                        'permission' => 'mining-manager.moon.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.extraction_calendar',
                        'icon' => 'fas fa-calendar-alt',
                        'route' => 'mining-manager.moon.calendar',
                        'permission' => 'mining-manager.moon.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.moon_compositions',
                        'icon' => 'fas fa-chart-bar',
                        'route' => 'mining-manager.moon.compositions',
                        'permission' => 'mining-manager.moon.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.moon_value_calculator',
                        'icon' => 'fas fa-coins',
                        'route' => 'mining-manager.moon.calculator',
                        'permission' => 'mining-manager.moon.view',
                    ],
                ],
            ],

            // ==================== ANALYTICS ====================
            [
                'name' => 'mining-manager::menu.analytics',
                'icon' => 'fas fa-chart-line',
                'permission' => 'mining-manager.analytics.view',
                'entries' => [
                    [
                        'name' => 'mining-manager::menu.analytics_overview',
                        'icon' => 'fas fa-chart-area',
                        'route' => 'mining-manager.analytics.index',
                        'permission' => 'mining-manager.analytics.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.performance_charts',
                        'icon' => 'fas fa-chart-line',
                        'route' => 'mining-manager.analytics.charts',
                        'permission' => 'mining-manager.analytics.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.data_tables',
                        'icon' => 'fas fa-table',
                        'route' => 'mining-manager.analytics.tables',
                        'permission' => 'mining-manager.analytics.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.comparative_analysis',
                        'icon' => 'fas fa-balance-scale',
                        'route' => 'mining-manager.analytics.compare',
                        'permission' => 'mining-manager.analytics.view',
                    ],
                ],
            ],

            // ==================== REPORTS ====================
            [
                'name' => 'mining-manager::menu.reports',
                'icon' => 'fas fa-file-alt',
                'permission' => 'mining-manager.reports.view',
                'entries' => [
                    [
                        'name' => 'mining-manager::menu.view_reports',
                        'icon' => 'fas fa-list',
                        'route' => 'mining-manager.reports.index',
                        'permission' => 'mining-manager.reports.view',
                    ],
                    [
                        'name' => 'mining-manager::menu.generate_report',
                        'icon' => 'fas fa-plus-circle',
                        'route' => 'mining-manager.reports.generate',
                        'permission' => 'mining-manager.reports.generate',
                    ],
                    [
                        'name' => 'mining-manager::menu.scheduled_reports',
                        'icon' => 'fas fa-clock',
                        'route' => 'mining-manager.reports.scheduled',
                        'permission' => 'mining-manager.reports.generate',
                    ],
                    [
                        'name' => 'mining-manager::menu.export_data',
                        'icon' => 'fas fa-download',
                        'route' => 'mining-manager.reports.export',
                        'permission' => 'mining-manager.reports.view',
                    ],
                ],
            ],
    
            // ==================== Help ====================
            [
                'name' => 'Help',
                'icon' => 'fas fa-question-circle',
                'route' => 'mining-manager.help.index',
                'permission' => 'mining-manager.view',
            ]

            // ==================== SEPARATOR ====================
            [
                'name' => '',
                'icon' => '',
                'route' => '',
            ],

            // ==================== SETTINGS ====================
            [
                'name' => 'mining-manager::menu.settings',
                'icon' => 'fas fa-cog',
                'route' => 'mining-manager.settings.index',
                'permission' => 'mining-manager.settings.view',
            ],

            // ==================== HELP & DOCUMENTATION ====================
            [
                'name' => 'mining-manager::menu.help',
                'icon' => 'fas fa-question-circle',
                'route' => 'mining-manager.help.index',
                'permission' => 'mining-manager.view',
            ],
        ],
    ],
];
