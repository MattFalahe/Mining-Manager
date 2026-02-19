<?php

return [
    // Base permissions
    'view_label' => 'View Mining Manager',
    'view_description' => 'Access to view Mining Manager module and dashboard',
    
    'dashboard_label' => 'View Dashboard',
    'dashboard_description' => 'View the mining manager dashboard',
    
    // Ledger permissions
    'ledger_view_label' => 'View Mining Ledger',
    'ledger_view_description' => 'View mining activity, ore extraction records, and personal mining history',
    
    'ledger_process_label' => 'Process Mining Ledger',
    'ledger_process_description' => 'Import and process mining data from ESI, recalculate prices and taxes',
    
    'ledger_delete_label' => 'Delete Ledger Entries',
    'ledger_delete_description' => 'Delete individual or multiple mining ledger entries',
    
    // Tax permissions (3-tier: member < director < admin)
    'member_label' => 'Tax Member',
    'member_description' => 'View own tax data, tax codes, and payment status. Read-only access to personal mining tax information.',
    
    // Event permissions
    'events_view_label' => 'View Mining Events',
    'events_view_description' => 'View scheduled mining operations, fleet events, and participation records',
    
    'events_create_label' => 'Create Mining Events',
    'events_create_description' => 'Create new mining events and operations',
    
    'events_edit_label' => 'Edit Mining Events',
    'events_edit_description' => 'Edit existing mining events',
    
    'events_delete_label' => 'Delete Mining Events',
    'events_delete_description' => 'Delete mining events',
    
    'events_manage_participants_label' => 'Manage Event Participants',
    'events_manage_participants_description' => 'Manage event participants and bonuses',
    
    // Moon permissions
    'moon_view_label' => 'View Moon Data',
    'moon_view_description' => 'View moon compositions, extraction schedules, and moon mining information',
    
    'moon_update_label' => 'Update Moon Data',
    'moon_update_description' => 'Update moon extraction information',
    
    'moon_manage_label' => 'Manage Moon Operations',
    'moon_manage_description' => 'Update moon data, schedule extractions, and manage moon mining operations',
    
    // Analytics permissions
    'analytics_view_label' => 'View Analytics',
    'analytics_view_description' => 'Access to mining analytics, charts, metrics, and comparative analysis',
    
    'analytics_export_label' => 'Export Analytics',
    'analytics_export_description' => 'Export analytics data',
    
    // Report permissions
    'reports_view_label' => 'View Reports',
    'reports_view_description' => 'View generated reports and scheduled report results',
    
    'reports_generate_label' => 'Generate Reports',
    'reports_generate_description' => 'Generate new reports',
    
    'reports_delete_label' => 'Delete Reports',
    'reports_delete_description' => 'Delete old reports',
    
    'reports_export_label' => 'Export Reports',
    'reports_export_description' => 'Generate, export, and schedule automated reports',
    
    // Settings permissions
    'settings_view_label' => 'View Settings',
    'settings_view_description' => 'View Mining Manager configuration and settings',
    
    'settings_edit_label' => 'Edit Settings',
    'settings_edit_description' => 'Edit plugin settings and configuration',
    
    // API permissions
    'api_access_label' => 'API Access',
    'api_access_description' => 'Access Mining Manager API endpoints',
    
    // Special permissions
    'director_label' => 'Tax Director',
    'director_description' => 'View all corporation tax data (toggle), verify wallet payments. Includes all Member permissions.',

    'admin_label' => 'Tax Admin',
    'admin_description' => 'Full tax management: calculate taxes, generate codes, send reminders, mark paid, manage settings. Includes all Director permissions.',
];
