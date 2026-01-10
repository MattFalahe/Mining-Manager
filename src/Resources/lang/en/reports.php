<?php

return [
    // General
    'report_list' => 'Report List',
    'generate_report' => 'Generate Report',
    'scheduled_reports' => 'Scheduled Reports',
    'quick_export' => 'Quick Export',
    'reports' => 'Reports',
    'report' => 'Report',
    
    // Actions
    'generate_new_report' => 'Generate New Report',
    'generate_first_report' => 'Generate Your First Report',
    'manage_scheduled' => 'Manage Scheduled Reports',
    'cleanup_old_reports' => 'Cleanup Old Reports',
    'view_report' => 'View Report',
    'download' => 'Download',
    'delete' => 'Delete',
    'edit' => 'Edit',
    'view' => 'View',
    'run_now' => 'Run Now',
    'cancel' => 'Cancel',
    'reset_form' => 'Reset Form',
    'reset' => 'Reset',
    'generate' => 'Generate',
    'export' => 'Export',
    'export_data' => 'Export Data',
    'create_schedule' => 'Create Schedule',
    'back_to_list' => 'Back to Reports',
    'back_to_reports' => 'Back to Reports',
    
    // Report Types
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly',
    'custom' => 'Custom',
    'daily_report' => 'Daily Report',
    'weekly_report' => 'Weekly Report',
    'monthly_report' => 'Monthly Report',
    'custom_date_range' => 'Custom Date Range',
    
    // Report Type Descriptions
    'type_daily_description' => 'Generate a report for yesterday\'s activity',
    'type_weekly_description' => 'Generate a report for last week\'s activity',
    'type_monthly_description' => 'Generate a report for last month\'s activity',
    'type_custom_description' => 'Generate a report for a custom date range',
    
    // Formats
    'format' => 'Format',
    'json' => 'JSON',
    'csv' => 'CSV',
    'pdf' => 'PDF',
    
    // Format Descriptions
    'format_json_description' => 'Machine-readable data format',
    'format_csv_description' => 'Spreadsheet-compatible format',
    'format_pdf_description' => 'Printable document format with summary tables and charts',
    'best_for_excel' => 'Best for Excel/Spreadsheets',
    'best_for_api' => 'Best for API/Programming',
    
    // Stats & Metrics
    'total_reports' => 'Total Reports',
    'monthly_reports' => 'Monthly Reports',
    'csv_reports' => 'CSV Reports',
    'total_storage' => 'Total Storage',
    'active_schedules' => 'Active Schedules',
    'paused_schedules' => 'Paused Schedules',
    'total_generated' => 'Total Generated',
    'running_soon' => 'Running Soon',
    'reports_generated' => 'Reports Generated',
    
    // Table Columns
    'type' => 'Type',
    'period' => 'Period',
    'generated' => 'Generated',
    'size' => 'Size',
    'actions' => 'Actions',
    'schedule' => 'Schedule',
    'frequency' => 'Frequency',
    'last_run' => 'Last Run',
    'next_run' => 'Next Run',
    'status' => 'Status',
    'exported' => 'Exported',
    'data_type' => 'Data Type',
    
    // Date & Time
    'start_date' => 'Start Date',
    'end_date' => 'End Date',
    'date_range' => 'Date Range',
    'days' => 'days',
    'quick_ranges' => 'Quick Ranges',
    'last_7_days' => 'Last 7 Days',
    'last_30_days' => 'Last 30 Days',
    'last_90_days' => 'Last 90 Days',
    'last_year' => 'Last Year',
    'run_time' => 'Run Time',
    'run_time_help' => 'Time of day to run this report (UTC)',
    
    // Filters & Search
    'filters_actions' => 'Filters & Actions',
    'quick_actions' => 'Quick Actions',
    'filter_by_type' => 'Filter by Type',
    'all_reports' => 'All Reports',
    'search_reports' => 'Search Reports',
    'search_placeholder' => 'Search by date or type...',
    
    // Status Messages
    'report_generated' => 'Report Generated',
    'report_deleted' => 'Report deleted successfully',
    'report_generation_started' => 'Report generation started',
    'cleanup_success' => 'Old reports cleaned up successfully',
    'schedule_deleted' => 'Schedule deleted successfully',
    'no_reports_found' => 'No Reports Found',
    'no_reports_description' => 'You haven\'t generated any reports yet. Click the button below to create your first report.',
    'in_database' => 'In Database',
    'manual' => 'Manual',
    
    // Step Titles
    'step_1_select_type' => 'Step 1: Select Report Type',
    'step_2_select_format' => 'Step 2: Select Format',
    'step_3_configure' => 'Step 3: Configure Options',
    'step_3_filters' => 'Step 3: Add Filters',
    'step_4_preview' => 'Step 4: Preview & Generate',
    'step_1_select_data' => 'Step 1: Select Data Type',
    'select_type' => 'Select Type',
    'select_format' => 'Select Format',
    'configure' => 'Configure',
    'ready_to_export' => 'Ready to Export',
    
    // Help Text
    'type_selection_help' => 'Select the type of report you want to generate. Each type covers a different time period.',
    'format_selection_help' => 'Choose the output format for your report. CSV is best for spreadsheets, JSON for programming.',
    'custom_date_help' => 'Select a custom date range for your report. Choose any period from the past.',
    'generation_time_notice' => 'Report generation may take a few moments depending on the amount of data. You\'ll be redirected when complete.',
    'scheduled_reports_info' => 'Automated Report Generation',
    'scheduled_reports_description' => 'Schedule reports to run automatically at regular intervals. Perfect for monthly tax reports or weekly summaries.',
    
    // Preview & Summary
    'report_summary' => 'Report Summary',
    'report_will_include' => 'This Report Will Include:',
    'include_mining_activity' => 'Complete mining activity records',
    'include_value_calculations' => 'ISK value calculations at current prices',
    'include_miner_breakdown' => 'Breakdown by individual miners',
    'include_ore_distribution' => 'Ore type distribution analysis',
    'include_system_analysis' => 'System-by-system breakdown',
    'include_tax_data' => 'Tax calculations and summaries',
    'export_summary' => 'Export Summary',
    
    // Recent Activity
    'recent_activity' => 'Recent Activity',
    'recent_scheduled_reports' => 'Recent Scheduled Reports',
    'recent_exports' => 'Recent Exports',
    
    // Scheduled Reports
    'create_new_schedule' => 'Create New Schedule',
    'automate_report_generation' => 'Automate your report generation',
    'schedule_name' => 'Schedule Name',
    'schedule_name_placeholder' => 'e.g., Monthly Tax Report',
    'description' => 'Description',
    'description_placeholder' => 'Optional description for this schedule',
    'activate_immediately' => 'Activate Immediately',
    'no_schedules_yet' => 'No Scheduled Reports Yet',
    'no_schedules_description' => 'You haven\'t created any scheduled reports. Click the card on the left to create your first automated report.',
    
    // Confirmations
    'confirm_delete' => 'Are you sure you want to delete this report?',
    'confirm_cleanup' => 'This will delete old reports based on your retention policy. Continue?',
    'confirm_delete_schedule' => 'Are you sure you want to delete this schedule? All associated reports will remain.',
    
    // Generating/Processing
    'generating' => 'Generating...',
    'exporting' => 'Exporting...',
    'cleaning' => 'Cleaning...',
    
    // Errors
    'error_deleting' => 'Error deleting report',
    'error_cleanup' => 'Error cleaning up reports',
    
    // Quick Export
    'quick_export_tool' => 'Quick Export Tool',
    'quick_export_description' => 'Export specific data quickly without creating a full report. Perfect for one-time exports or ad-hoc analysis.',
    'mining_activity' => 'Mining Activity',
    'tax_records' => 'Tax Records',
    'miner_statistics' => 'Miner Statistics',
    'system_statistics' => 'System Statistics',
    'ore_breakdown' => 'Ore Breakdown',
    'event_data' => 'Event Data',
    
    // Export Type Descriptions
    'export_mining_desc' => 'Raw mining ledger data with all recorded activity',
    'export_tax_desc' => 'Tax calculations and payment records',
    'export_miners_desc' => 'Individual miner performance and statistics',
    'export_systems_desc' => 'Activity breakdown by solar system',
    'export_ores_desc' => 'Ore type quantities and values',
    'export_events_desc' => 'Mining event participation and results',
    
    // Additional Filters
    'minimum_value' => 'Minimum Value (ISK)',
    'region_filter' => 'Region Filter',
    'all_regions' => 'All Regions',
    'only_unpaid' => 'Only Unpaid Taxes',
    
    // Report Show/Detail
    'report_details' => 'Report Details',
    'total_quantity' => 'Total Quantity',
    'total_value_isk' => 'Total Value (ISK)',
    'unique_miners' => 'Unique Miners',
    'top_miners' => 'Top Miners',
    'miner_name' => 'Miner',
    'quantity' => 'Quantity',
    'value_isk' => 'Value (ISK)',
    'percentage' => 'Percentage',
    'ore_type' => 'Ore Type',
    'system_breakdown' => 'System Breakdown',
    'system_name' => 'System',
    'tax_summary' => 'Tax Summary',
    'tax_rate' => 'Tax Rate',
    'taxable_value' => 'Taxable Value',
    'total_paid' => 'Total Paid',
    'total_outstanding' => 'Total Outstanding',
    'no_report_data' => 'No Report Data Available',
    'no_report_data_description' => 'This report does not contain any viewable data. Try downloading the report file instead.',
    'unknown' => 'Unknown',

    // Discord
    'send_to_discord' => 'Send to Discord',
    'webhook' => 'Webhook',
    'sent_to_discord' => 'Sent to Discord',

    // Miscellaneous
    'generating_status' => 'Generating',
    'completed_status' => 'Completed',
    'failed_status' => 'Failed',
    'pending_status' => 'Pending',
];
