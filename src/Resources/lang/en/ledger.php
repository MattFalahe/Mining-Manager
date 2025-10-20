<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mining Ledger Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used in the mining ledger controller
    | and views. 
    |
    */

    // General
    'title' => 'Mining Ledger',
    'my_mining' => 'My Mining Activity',
    'process_ledger' => 'Process Ledger Data',
    'view_ledger' => 'View Ledger',
    'no_data' => 'No data',
    'never' => 'Never',

    // Filters
    'filter_by' => 'Filter By',
    'all_characters' => 'All Characters',
    'all_ore_types' => 'All Ore Types',
    'start_date' => 'Start Date',
    'end_date' => 'End Date',
    'apply_filters' => 'Apply Filters',
    'clear_filters' => 'Clear Filters',

    // Table Headers
    'date' => 'Date',
    'character' => 'Character',
    'system' => 'System',
    'ore_type' => 'Ore Type',
    'quantity' => 'Quantity',
    'price_per_unit' => 'Price/Unit',
    'total_value' => 'Total Value',
    'tax_rate' => 'Tax Rate',
    'tax_amount' => 'Tax Amount',
    'actions' => 'Actions',

    // Statistics
    'statistics' => 'Statistics',
    'total_mined' => 'Total Mined',
    'total_value' => 'Total Value',
    'total_tax' => 'Total Tax',
    'tax_owed' => 'Tax Owed',
    'tax_paid' => 'Tax Paid',
    'tax_outstanding' => 'Tax Outstanding',
    'unique_characters' => 'Unique Miners',
    'unique_ores' => 'Unique Ore Types',
    'mining_days' => 'Mining Days',
    'average_daily_value' => 'Average Daily Value',
    'favorite_ore' => 'Favorite Ore',

    // Personal Mining
    'no_characters' => 'You have no characters registered with this corporation. Please add characters to view your mining activity.',
    'personal_stats' => 'Your Mining Statistics',
    'mining_performance' => 'Mining Performance',
    'daily_mining_value' => 'Daily Mining Value',
    'top_ores_by_value' => 'Top Ores by Value',

    // Processing
    'process_title' => 'Process Mining Ledger Data',
    'process_description' => 'Import and process mining data from the EVE ESI API into the Mining Manager database.',
    'last_processed' => 'Last Processed',
    'pending_entries' => 'Pending Entries',
    'total_entries' => 'Total Entries',
    'characters_with_data' => 'Characters with Data',
    'select_character' => 'Select Character (Optional)',
    'all_characters_option' => 'Process All Characters',
    'date_range' => 'Date Range (Optional)',
    'recalculate_prices' => 'Recalculate Prices',
    'recalculate_prices_help' => 'Fetch fresh prices instead of using cached values',
    'process_button' => 'Process Mining Data',
    'processing' => 'Processing...',

    // Processing Messages
    'processing_complete' => 'Processing complete: :processed new entries created, :updated entries updated.',
    'processing_error' => 'Error processing ledger data: :error',
    'no_data_to_process' => 'No mining data found to process for the selected criteria.',

    // Tooltips
    'view_details' => 'View Details',
    'export_data' => 'Export Data',
    'refresh_data' => 'Refresh Data',

    // Empty States
    'no_entries' => 'No mining entries found for the selected criteria.',
    'no_entries_hint' => 'Try adjusting your filters or processing new data from ESI.',
    'get_started' => 'Get Started',
    'process_first_time' => 'To see your mining data, you need to process the mining ledger from ESI first.',
    'click_process' => 'Click the "Process Ledger" button to import your mining activity.',

    // Charts
    'chart_title_daily' => 'Daily Mining Value (ISK)',
    'chart_title_ores' => 'Top Ores by Value',
    'chart_title_characters' => 'Mining by Character',
    'isk' => 'ISK',
    'units' => 'Units',

    // Pagination
    'showing' => 'Showing',
    'to' => 'to',
    'of' => 'of',
    'entries' => 'entries',

    // Export
    'export_csv' => 'Export to CSV',
    'export_excel' => 'Export to Excel',
    'export_pdf' => 'Export to PDF',

    // Help
    'help_title' => 'Mining Ledger Help',
    'help_description' => 'The mining ledger tracks all mining activity from your characters. Data is imported from the EVE ESI API and automatically calculates values and taxes based on your settings.',
    'help_filtering' => 'Use the filters to narrow down your results by character, date range, or ore type.',
    'help_processing' => 'Click "Process Ledger" to import new mining data from ESI. This should be done regularly to keep your ledger up to date.',
];
