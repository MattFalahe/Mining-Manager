<?php

return [
    'mining-manager' => [
        'label' => 'mining-manager::menu.mining_manager',
        'icon' => 'fas fa-industry',
        'permissions' => [
            
            // Dashboard permissions
            [
                'permission' => 'mining-manager.dashboard',
                'label' => 'mining-manager::permissions.dashboard',
                'description' => 'View the mining manager dashboard',
                'division' => 'financial',
            ],

            // Mining Ledger permissions
            [
                'permission' => 'mining-manager.ledger.view',
                'label' => 'mining-manager::permissions.view_ledger',
                'description' => 'View mining ledger entries',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.ledger.process',
                'label' => 'mining-manager::permissions.process_ledger',
                'description' => 'Process and import mining ledger data',
                'division' => 'financial',
            ],

            // Tax permissions
            [
                'permission' => 'mining-manager.tax.view',
                'label' => 'mining-manager::permissions.view_tax',
                'description' => 'View tax records and calculations',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.tax.calculate',
                'label' => 'mining-manager::permissions.calculate_tax',
                'description' => 'Calculate taxes for miners',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.tax.generate_invoices',
                'label' => 'mining-manager::permissions.generate_invoices',
                'description' => 'Generate tax invoices and contracts',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.tax.verify_payments',
                'label' => 'mining-manager::permissions.verify_payments',
                'description' => 'Verify and match wallet payments to taxes',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.tax.send_reminders',
                'label' => 'mining-manager::permissions.send_reminders',
                'description' => 'Send tax payment reminders',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.tax.manage',
                'label' => 'mining-manager::permissions.manage_tax',
                'description' => 'Full tax management (edit, delete, override)',
                'division' => 'financial',
            ],

            // Mining Events permissions
            [
                'permission' => 'mining-manager.events.view',
                'label' => 'mining-manager::permissions.view_events',
                'description' => 'View mining events',
                'division' => 'military',
            ],
            [
                'permission' => 'mining-manager.events.create',
                'label' => 'mining-manager::permissions.create_events',
                'description' => 'Create new mining events',
                'division' => 'military',
            ],
            [
                'permission' => 'mining-manager.events.edit',
                'label' => 'mining-manager::permissions.edit_events',
                'description' => 'Edit existing mining events',
                'division' => 'military',
            ],
            [
                'permission' => 'mining-manager.events.delete',
                'label' => 'mining-manager::permissions.delete_events',
                'description' => 'Delete mining events',
                'division' => 'military',
            ],
            [
                'permission' => 'mining-manager.events.manage_participants',
                'label' => 'mining-manager::permissions.manage_participants',
                'description' => 'Manage event participants and bonuses',
                'division' => 'military',
            ],

            // Moon Extraction permissions
            [
                'permission' => 'mining-manager.moon.view',
                'label' => 'mining-manager::permissions.view_moon',
                'description' => 'View moon extraction data',
                'division' => 'industrial',
            ],
            [
                'permission' => 'mining-manager.moon.update',
                'label' => 'mining-manager::permissions.update_moon',
                'description' => 'Update moon extraction information',
                'division' => 'industrial',
            ],
            [
                'permission' => 'mining-manager.moon.manage',
                'label' => 'mining-manager::permissions.manage_moon',
                'description' => 'Full moon extraction management',
                'division' => 'industrial',
            ],

            // Analytics permissions
            [
                'permission' => 'mining-manager.analytics.view',
                'label' => 'mining-manager::permissions.view_analytics',
                'description' => 'View mining analytics and statistics',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.analytics.export',
                'label' => 'mining-manager::permissions.export_analytics',
                'description' => 'Export analytics data',
                'division' => 'financial',
            ],

            // Report permissions
            [
                'permission' => 'mining-manager.reports.view',
                'label' => 'mining-manager::permissions.view_reports',
                'description' => 'View generated reports',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.reports.generate',
                'label' => 'mining-manager::permissions.generate_reports',
                'description' => 'Generate new reports',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.reports.delete',
                'label' => 'mining-manager::permissions.delete_reports',
                'description' => 'Delete old reports',
                'division' => 'financial',
            ],

            // Settings permissions
            [
                'permission' => 'mining-manager.settings.view',
                'label' => 'mining-manager::permissions.view_settings',
                'description' => 'View plugin settings',
                'division' => 'financial',
            ],
            [
                'permission' => 'mining-manager.settings.edit',
                'label' => 'mining-manager::permissions.edit_settings',
                'description' => 'Edit plugin settings and configuration',
                'division' => 'financial',
            ],

            // API permissions
            [
                'permission' => 'mining-manager.api.access',
                'label' => 'mining-manager::permissions.api_access',
                'description' => 'Access Mining Manager API endpoints',
                'division' => 'financial',
            ],

            // Superuser permission
            [
                'permission' => 'mining-manager.admin',
                'label' => 'mining-manager::permissions.admin',
                'description' => 'Full administrative access to all Mining Manager features',
                'division' => 'financial',
            ],
        ],
    ],
];
