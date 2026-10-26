<?php

return [
    // Page Headers
    'settings' => 'Settings',
    'settings_menu' => 'Settings Menu',
    'pricing' => 'Pricing',
    'features' => 'Features',
    'advanced' => 'Advanced',
    'help' => 'Help',
    
    // Common
    'info' => 'Info',
    'warning' => 'Warning',
    'save_changes' => 'Save Changes',
    'reset_form' => 'Reset Form',
    'days' => 'days',
    
    // Validation
    'validation_errors' => 'Please fix the following errors',
    
    // Feature Settings
    'feature_settings' => 'Feature Settings',
    'features_info' => 'Enable or disable specific features and customize their behavior.',
    
    // Core Features
    'core_features' => 'Core Features',
    'enable_tax_tracking' => 'Calculate Taxes Tab and Tax Banner',
    'enable_tax_tracking_help' => 'Shows admins the Calculate Taxes tab, and everyone the tax banner on the dashboards. Bills are still worked out on schedule while this is off: that is Auto-Calculate Taxes.',
    'enable_ledger_tracking' => 'Import Personal Mining',
    'enable_ledger_tracking_help' => 'Copies each member\'s own mining ledger from SeAT every half hour, so mining away from your refineries is counted and taxed. Off, only mining at your refineries comes in.',
    'enable_analytics' => 'Enable Analytics',
    'enable_analytics_help' => 'Shows the Analytics page and keeps its monthly statistics up to date.',
    'enable_reports' => 'Enable Reports',
    'enable_reports_help' => 'Shows the Reports pages and runs the scheduled reports. Downloads follow Allow Data Export.',
    
    // Mining Events
    'mining_events' => 'Mining Events',
    'enable_events' => 'Enable Mining Events',
    'enable_events_help' => 'Allow creation and tracking of mining events',
    'allow_event_creation' => 'Allow Event Creation',
    'allow_event_creation_help' => 'Let directors create new mining events',
    
    // Moon Mining
    'moon_mining' => 'Moon Mining',
    'enable_moon_tracking' => 'Enable Moon Tracking',
    'enable_moon_tracking_help' => 'Track moon mining extractions. Theft detection only runs while this is on.',

    // Permissions & Access
    'permissions_access' => 'Permissions & Access',
    'allow_member_leaderboard' => 'Leaderboards on Analytics and Events',
    'allow_member_leaderboard_help' => 'Shows the top miners on the Analytics page and on running events. The dashboards keep their own leaderboards, set on the Dashboard page.',
    'show_character_names' => 'Show Character Names',
    'show_character_names_help' => 'Names the miners on those leaderboards. Off, they show as Miner and a number.',
    'allow_export_data' => 'Allow Data Export',
    'allow_export_data_help' => 'Exporting is off for everyone when this is off, directors and admins included. Covers mining, tax, analytics, theft, report and moon downloads. Your settings backup is separate and always available.',
    'data_export_disabled' => 'Exporting is switched off. An administrator can turn it back on under Settings, Features, Allow Data Export.',
    
    // Automation & Processing
    'automation_processing' => 'Automation & Processing',
    'auto_process_ledger' => 'Import Refinery Mining',
    'auto_process_ledger_help' => 'Reads the mining your refineries record into the plugin every half hour. Off, no refinery mining comes in, so moon ore mined at your refineries is neither taxed nor checked for theft.',
    'ledger_processing_interval' => 'Processing Interval',
    'auto_calculate_taxes' => 'Auto-Calculate Taxes',
    'auto_calculate_taxes_help' => 'Works out each tax period\'s bills in the nightly run once the period has ended. Off, nothing is billed until someone uses Calculate Taxes.',
    'auto_generate_invoices' => 'Send Invoices',
    'auto_generate_invoices_help' => 'Each night, tells every member with a new bill, through the Tax Invoice notification. Off, bills are still made, but members are not told about them this way.',
    'verify_wallet_transactions' => 'Match Wallet Payments',
    'verify_wallet_transactions_help' => 'Reads the corporation wallet every six hours and matches payments to bills, and shows the Wallet Verification tab. Off, payments are not matched until it is back on.',
    
    // Data Retention
    'data_retention' => 'Extraction History',
    'data_retention_warning' => 'Only archived moon extractions are ever deleted here, and only with Auto-Cleanup on. Mining, tax and payment records are never deleted by these.',
    'ledger_retention_days' => 'Keep Archived Extractions For',
    'ledger_retention_days_help' => 'How long archived moon extractions are kept when Auto-Cleanup is on. Mining ledger records are not affected.',
    'auto_cleanup_old_data' => 'Auto-Cleanup Archived Extractions',
    'auto_cleanup_old_data_help' => 'Once a day, deletes archived moon extractions older than the period above. Nothing else is deleted.',
    
    // Advanced Settings
    'advanced_warning' => 'These settings are for advanced users only. Incorrect settings may cause issues.',
    'export_settings' => 'Export Settings',
    'export_description' => 'Export every setting, including the ones each corporation has of its own, to a JSON file for backup or transfer.',
    'export_now' => 'Export Settings',
    'applies_to_all_corporations' => 'All corporations',
    'export_include_webhooks' => 'Include webhooks',
    'export_webhooks_warning' => 'The file will contain your webhook URLs. Anyone who has it can post to those channels.',
    'import_settings' => 'Import Settings',
    'import_description' => 'Import settings from a previously exported JSON file.',
    'choose_file' => 'Choose File',
    'import_now' => 'Import Settings',
    'cache_description' => 'Clear all cached data including prices, stats, and calculated values.',
    'clear_now' => 'Clear Cache',
    'confirm_clear_cache' => 'Are you sure you want to clear all cached data?',
    'clearing' => 'Clearing',
    'cache_cleared' => 'Cache has been cleared successfully',
    'error_clearing_cache' => 'Error clearing cache',
    'reset_settings' => 'Reset Settings',
    'reset_description' => 'Reset all settings to their default values. This cannot be undone!',
    'reset_now' => 'Reset to Defaults',
    'confirm_reset' => 'Are you sure you want to reset all settings to defaults?',
    'confirm_reset_final' => 'This action cannot be undone! Continue?',
    'resetting' => 'Resetting',
    'settings_reset' => 'Settings have been reset to defaults',
    'error_resetting' => 'Error resetting settings',
    
    // Help & Documentation
    'help_title' => 'Settings Help',
    'help_intro' => 'What each group of pages in the menu is for.',
    'help_taxes' => 'Taxes',
    'help_taxes_desc' => 'Who is taxed, at what rate, and how they pay:',
    'help_taxes_1' => 'Setup: the Moon Owner Corporation, and the corporations whose members are taxed as members',
    'help_taxes_2' => 'Tax profiles: each corporation\'s billing period, code prefix and rates, and Global for everyone else',
    'help_taxes_3' => 'Billing and Payments: small amounts, due dates, reminders, payment codes and wallet matching',
    'help_taxes_4' => 'Events: how long an event for past days is held, and which moon managers\' events wait for a director',
    'help_moons' => 'Moons and Prices',
    'help_moons_desc' => 'How pulls are planned and ore is valued:',
    'help_moons_1' => 'Moon Planner: the gap between moons, the time between pulls on one refinery, and reminders',
    'help_moons_2' => 'Pricing: the price provider and its fallback, the price type, and how long prices are kept',
    'help_moons_3' => 'Refining efficiency, for ore valued by what it refines into',
    'help_alerts' => 'Alerts',
    'help_alerts_desc' => 'Where notifications go and who they ping:',
    'help_alerts_1' => 'Webhooks: the Discord and Slack channels alerts are sent to',
    'help_alerts_2' => 'Notifications: which alerts go out, and the role each one pings',
    'help_alerts_3' => 'Routing Map: every alert and the channels it reaches, on one page',
    'help_plugin' => 'Plugin',
    'help_plugin_desc' => 'How the plugin itself behaves:',
    'help_plugin_1' => 'Features: switch parts of the plugin on or off, with what each switch does',
    'help_plugin_2' => 'Dashboard: which corporations the leaderboards rank',
    'help_plugin_3' => 'Maintenance: export, import or reset settings, clear caches, and see what the move into tax profiles did',
    'need_more_help' => 'Need More Help?',
    'documentation_text' => 'For detailed documentation, examples, and troubleshooting, visit our GitHub repository.',
    'view_documentation' => 'View Documentation',
    
    // Additional Settings Terms
    'clear_cache' => 'Clear Cache',
    'help_documentation' => 'Help & Documentation',

    // Webhook Settings
    'webhooks' => 'Webhooks',
    'webhook_notifications' => 'Webhook Notifications',
    'webhooks_info' => 'Configure webhooks to receive real-time theft detection notifications in Discord, Slack, or custom endpoints.',
    'webhook_statistics' => 'Webhook Statistics',
    'webhooks_configured' => 'Configured',
    'webhooks_enabled' => 'Enabled',
    'total_sent' => 'Total Sent',
    'total_failed' => 'Total Failed',
    'add_webhook' => 'Add Webhook',
    'configured_webhooks' => 'Configured Webhooks',
    'no_webhooks_configured' => 'No webhooks configured yet',
    'add_first_webhook' => 'Add Your First Webhook',
    'name' => 'Name',
    'type' => 'Type',
    'events' => 'Events',
    'health' => 'Health',
    'actions' => 'Actions',
    'not_tested' => 'Not Tested',
    'test_webhook' => 'Test Webhook',
    'edit_webhook' => 'Edit Webhook',
    'delete_webhook' => 'Delete Webhook',

    // Webhook Form
    'webhook_name' => 'Webhook Name',
    'webhook_name_help' => 'A friendly name to identify this webhook',
    'webhook_type' => 'Webhook Type',
    'webhook_url' => 'Webhook URL',
    'discord_webhook_help' => 'Go to Discord Server Settings → Integrations → Webhooks to get your webhook URL',
    'webhook_corporation' => 'Assign to Corporation',
    'webhook_corporation_global' => 'Global (admin — sees all corps)',
    'webhook_corporation_help' => 'Which corporation should this webhook receive notifications for? "Global" receives everything. The Tax Program Corp receives everything regardless. A specific corp receives: moon/theft/broadcast tax for its own operations, plus individual tax reminders / invoices / overdue only for miners who belong to that corp. Use this to let each mining-group director see only their own members\' tax activity.',
    'notify_on_events' => 'Notify On Events',
    'theft_detected' => 'Theft Detected',
    'critical_theft' => 'Critical Theft',
    'active_theft' => 'Active Theft in Progress',
    'incident_resolved' => 'Incident Resolved',

    // Discord Settings
    'discord_settings' => 'Discord Settings',
    'discord_username' => 'Custom Username',
    'discord_username_help' => 'Override the webhook\'s username (optional)',

    // Slack Settings
    'slack_settings' => 'Slack Settings',
    'slack_channel' => 'Slack Channel',
    'slack_channel_help' => 'Override the default channel (e.g., #mining-alerts)',
    'slack_username' => 'Custom Username',
    'slack_username_help' => 'Override the webhook\'s username (optional)',

    // Custom Webhook Settings
    'custom_webhook_settings' => 'Custom Webhook Settings',
    'custom_webhook_info' => 'Custom webhooks receive a JSON payload with theft incident data. You can customize the payload structure below.',
    'custom_payload_template' => 'Custom Payload Template',
    'custom_payload_help' => 'JSON template with variables: {{event_type}}, {{character_id}}, {{character_name}}, {{ore_value}}, {{tax_owed}}, {{severity}}, {{status}}',

    // Report Notifications
    'reports_category' => 'Reports',
    'report_generated' => 'Report Generated',

    // Common
    'optional' => 'Optional',
    'cancel' => 'Cancel',
    'save_webhook' => 'Save Webhook',
];
