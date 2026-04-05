<?php

return [
    // Page Title
    'help_documentation' => 'Help & Documentation',
    'navigation' => 'Navigation',
    'search_placeholder' => 'Search help documentation...',

    // Navigation Items
    'overview' => 'Overview',
    'getting_started' => 'Getting Started',
    'dashboard' => 'Dashboard',
    'tax_system' => 'Tax System',
    'mining_events' => 'Mining Events',
    'moon_mining' => 'Moon Mining',
    'theft_detection' => 'Theft Detection',
    'analytics_reports' => 'Analytics & Reports',
    'settings' => 'Settings',
    'cli_commands' => 'CLI Commands',
    'permissions' => 'Permissions',
    'faq' => 'FAQ',
    'troubleshooting' => 'Troubleshooting',

    // Common
    'tip' => 'Tip',
    'note' => 'Note',
    'important' => 'Important',
    'example' => 'Example',

    // Overview Section - Plugin Information
    'plugin_information' => 'Plugin Information',
    'plugin_author' => 'Matt Falahe',
    'plugin_author_email' => 'mattfalahe@gmail.com',
    'plugin_license' => 'GPL-2.0',
    'github_repository' => 'GitHub Repository',
    'full_changelog' => 'Full Changelog',
    'report_issues' => 'Report Issues',
    'readme' => 'README',
    'support_the_project' => 'Support the Project',
    'support_star' => 'Star the GitHub repository',
    'support_issues' => 'Report bugs and issues',
    'support_features' => 'Suggest new features',
    'support_contribute' => 'Contributing code improvements',
    'support_share' => 'Share with other SeAT users',

    // Overview Section - Welcome
    'welcome_title' => 'Welcome to Mining Manager',
    'welcome_desc' => 'Your comprehensive mining management and tax collection system for EVE Online corporations using SeAT.',

    // Overview Section - What is
    'what_is_mining_manager' => 'What is Mining Manager?',
    'what_is_mining_manager_desc' => 'Mining Manager is a comprehensive SeAT plugin designed to help EVE Online corporations track mining activities, calculate and collect taxes, organize mining events, monitor moon extractions, detect unauthorized mining, and provide detailed analytics on mining performance.',
    'key_benefits' => 'Key Benefits',
    'key_benefits_desc' => 'Automated tax calculation, wallet payment verification, moon extraction tracking, theft detection, Discord/Slack notifications, and comprehensive analytics.',

    // Overview Section - Core Features
    'core_features' => 'Core Features',
    'feature_ledger' => 'Mining Ledger',
    'feature_ledger_desc' => 'Track all mining activities with detailed volume, quantity, and ISK value breakdowns per character and corporation.',
    'feature_tax' => 'Tax Management',
    'feature_tax_desc' => 'Calculate and collect mining taxes with configurable rates, tax codes, wallet verification, and accumulated mode for alt grouping.',
    'feature_moon' => 'Moon Mining',
    'feature_moon_desc' => 'Track moon extractions, compositions, ore classifications (R4-R64), quality ratings, and structure assignments.',
    'feature_events' => 'Mining Events',
    'feature_events_desc' => 'Organize mining operations with bonus multipliers, time windows, ore type restrictions, and participation tracking.',
    'feature_analytics' => 'Analytics & Reports',
    'feature_analytics_desc' => 'Comprehensive charts, moon utilization analysis, pool vs mined comparisons, and ore popularity tracking.',
    'feature_theft' => 'Theft Detection',
    'feature_theft_desc' => 'Monitor unauthorized mining on corporation moons and track active theft incidents with automated alerts.',

    // Getting Started
    'getting_started_intro' => 'Welcome to the SeAT Mining Manager! This guide will help you get started with managing your corporation\'s mining operations.',
    'what_is_mining_manager' => 'What is Mining Manager?',
    'what_is_mining_manager_desc' => 'Mining Manager is a comprehensive SeAT plugin designed to help EVE Online corporations track mining activities, calculate and collect taxes, organize mining events, monitor moon extractions, detect unauthorized mining, and provide detailed analytics on mining performance.',

    // Quick Start Steps
    'quick_start_guide' => 'Quick Start Guide',
    'step_1_title' => 'Configure Settings',
    'step_1_desc' => 'Navigate to Settings and configure your corporation, ore valuation method, price provider, and market hub region.',
    'step_2_title' => 'Set Tax Rates',
    'step_2_desc' => 'Configure tax rates for different ore categories: moon ore by rarity (R64, R32, R16, R8, R4), regular ore, ice, gas, and abyssal ore. Select which ore types to tax.',
    'step_3_title' => 'Configure Tax Payment',
    'step_3_desc' => 'Set up wallet transfer payment settings including tax code prefix, grace period, reminder schedule, and minimum tax amount.',
    'step_4_title' => 'Grant Permissions',
    'step_4_desc' => 'Set up permissions for your members using SeAT\'s ACL system. Assign view, member, director, or admin roles as appropriate.',
    'step_5_title' => 'Start Mining',
    'step_5_desc' => 'Members can now start mining! The system will automatically track all mining activities from ESI and calculate taxes based on your configuration.',
    'getting_started_tip' => 'Mining ledger data is fetched from ESI automatically. The scheduled command processes new data every 30 minutes.',

    // Quick Links
    'quick_links' => 'Quick Links',
    'calculate_tax' => 'Calculate Tax',
    'view_events' => 'View Events',

    // Dashboard Guide
    'dashboard_guide' => 'Dashboard Overview',
    'dashboard_intro' => 'The dashboard is your central hub for monitoring mining activities. It provides different views for regular members and directors.',
    'member_dashboard' => 'Member Dashboard',
    'member_dashboard_desc' => 'As a regular member, your dashboard shows:',
    'personal_stats' => 'Personal Statistics',
    'personal_stats_desc' => 'Your mining totals, tax owed, and personal rankings',
    'tax_status' => 'Tax Status',
    'tax_status_desc' => 'Current tax balance, payment status, and deadlines',
    'recent_activity' => 'Recent Activity',
    'recent_activity_desc' => 'Your recent mining operations and ledger entries',
    'upcoming_events' => 'Upcoming Events',
    'upcoming_events_desc' => 'Mining events you\'re registered for',
    'director_dashboard' => 'Director Dashboard',
    'director_dashboard_desc' => 'As a director, your dashboard includes:',
    'corp_overview' => 'Corporation Overview',
    'corp_overview_desc' => 'Total mining volume, value, and tax collection statistics',
    'top_miners' => 'Top Miners',
    'top_miners_desc' => 'Leaderboard of most productive miners',
    'tax_collection' => 'Tax Collection',
    'tax_collection_desc' => 'Outstanding taxes, payment rates, and delinquent members',
    'active_events' => 'Active Events',
    'active_events_desc' => 'Currently running mining events and participation',
    'dashboard_charts' => 'Director Dashboard Charts',
    'dashboard_charts_desc' => 'The director dashboard includes several 12-month charts:',
    'chart_mining_tax' => 'Mining Tax (12 Months) - Shows tax collected vs tax owed per month. The current month uses daily summaries for live estimates; past months show finalized data.',
    'chart_mining_performance' => 'Corp Mining Performance - Total mining value across all corporation characters per month.',
    'chart_moon_mining' => 'Moon Mining Performance - Moon ore value mined per month, tracked separately from regular ore.',
    'chart_event_tax' => 'Event Tax Impact - Shows how mining events affected tax amounts through bonus modifiers.',
    'chart_mining_by_group' => 'Mining by Group - Breakdown of mining value by ore category (Moon, Ice, Gas, Regular).',
    'chart_mining_by_type' => 'Mining by Type - Top 10 most mined ore types by value.',
    'dashboard_note' => 'The dashboard updates automatically as new ESI data is processed. Use the refresh button to manually update data.',

    // Tax System
    'tax_system_explained' => 'Tax System Explained',
    'tax_system_intro' => 'The tax system automatically calculates what each member owes based on the value of ore they\'ve mined and the configured tax rates. Daily summaries are the single source of truth for all tax data — every page (My Taxes, Calculate Taxes, Tax Overview, Dashboard) reads from daily summaries.',

    // How the Tax Chain Works
    'how_taxes_work' => 'How the Tax Chain Works',
    'tax_chain_intro' => 'Mining tax follows a clear chain from ESI data to final payment:',
    'tax_step_1_title' => '1. Mining Data is Fetched',
    'tax_step_1_desc' => 'The process-ledger command runs every 30 minutes and fetches mining data from ESI corporation mining observers. Each entry records the character, ore type, quantity, date, and solar system.',
    'tax_step_2_title' => '2. Prices are Applied',
    'tax_step_2_desc' => 'The update-ledger-prices command runs daily at 1:00 AM and applies current market prices to each mining entry. Prices come from your configured price provider (SeAT, Janice, Fuzzwork, or Custom) using the selected price type (sell, buy, or average) from your chosen market hub region.',
    'tax_step_3_title' => '3. Daily Summaries are Generated',
    'tax_step_3_desc' => 'The update-daily-summaries command (1:30 AM) creates per-character, per-day summaries containing: total ISK value, per-ore breakdown with tax rates, event modifiers, and estimated tax. These summaries always use your current tax settings — if you change a rate, the next summary regeneration picks it up.',
    'tax_step_4_title' => '4. Month is Finalized',
    'tax_step_4_desc' => 'On the 2nd of each month at 2:00 AM, the finalize-month command locks the previous month\'s daily summaries. This runs on the 2nd (not 1st) to allow observer data from EVE ESI — which can lag 12-24 hours — to settle before locking in final numbers.',
    'tax_step_5_title' => '5. Tax is Calculated',
    'tax_step_5_desc' => 'The calculate-taxes command runs daily at 2:15 AM but only acts on period boundaries — the 2nd for monthly, the 2nd and 16th for biweekly, or Tuesdays for weekly. The 1-day shift allows late-arriving observer data to be reconciled before tax calculation. It sums daily summaries into a tax record per member (grouped by main character) for the previous completed period. Each record stores the period type, start/end dates, due date, and a triggered_by audit trail.',
    'tax_step_6_title' => '6. Invoice is Generated',
    'tax_step_6_desc' => 'The generate-invoices command runs daily at 2:30 AM and creates invoice records for any unpaid taxes with completed periods. It skips taxes that already have an invoice — safe to run every day.',
    'tax_step_7_title' => '7. Payment Codes are Issued',
    'tax_step_7_desc' => 'Each tax record gets a unique payment code (e.g., TAX-MINC-VX45XUQC). Members pay by sending ISK to the corporation wallet with their code in the transfer reason field. Each period gets its own code.',
    'tax_step_8_title' => '8. Payments are Verified',
    'tax_step_8_desc' => 'The verify-payments command scans corporation wallet journal entries every 6 hours and automatically matches the reason field against issued tax codes. Matched payments update the tax status to "paid".',
    'tax_step_9_title' => '9. Reminders are Sent',
    'tax_step_9_desc' => 'The send-reminders command runs daily at 10:00 AM. It finds unpaid taxes approaching their due date (within the configured reminder window, default 3 days before) or already overdue, and sends one notification per character with their total owed and earliest due date.',

    // Tax Periods
    'tax_periods_title' => 'Tax Calculation Periods',
    'tax_periods_desc' => 'Configure how often taxes are calculated in Settings > Tax Rates > Tax Calculation Period. Three period types are supported:',
    'tax_period_monthly' => 'Monthly — One tax bill per calendar month (1st to last day). Calculated on the 2nd of the following month.',
    'tax_period_biweekly' => 'Biweekly — Two tax bills per month: 1st-14th and 15th-end. Calculated on the 2nd and 16th.',
    'tax_period_weekly' => 'Weekly — One tax bill per ISO week (Monday to Sunday). Calculated every Tuesday.',
    'tax_periods_charts' => 'Charts always display data monthly regardless of period type. Multiple periods within the same month are aggregated into one data point.',
    'tax_periods_due_date' => 'Each tax record gets a due date calculated as: period end date + payment deadline days (configurable in settings, default 7 days). For example, a biweekly period ending March 14 would be due March 21.',
    'tax_periods_codes' => 'Each period generates its own unique tax code. Biweekly members receive 2 codes per month, weekly members receive 4-5 codes per month.',

    // Nightly Pipeline
    'nightly_pipeline_title' => 'Behind the Scenes — Nightly Pipeline',
    'nightly_pipeline_desc' => 'Every night, a chain of automated commands runs in a specific order. Each step depends on the previous one completing first:',
    'pipeline_step_1' => '1:00 AM — update-ledger-prices: Locks in current market prices on mining ledger entries.',
    'pipeline_step_2' => '1:30 AM — update-daily-summaries: Catches any missed mining data (belt mining, late ESI imports), reconciles character-imported entries against late-arriving observer data from the previous 2 days, and ensures all summaries are current.',
    'pipeline_step_3' => '2:00 AM — finalize-month (2nd only): Locks the previous month\'s summaries as final so they won\'t be regenerated. Runs on the 2nd to allow observer data to settle.',
    'pipeline_step_4' => '2:15 AM — calculate-taxes: Creates tax records for the completed period. Only acts on period boundary days (2nd for monthly, 2nd/16th for biweekly, Tuesdays for weekly). The 1-day shift allows late observer data to be reconciled first.',
    'pipeline_step_5' => '2:30 AM — generate-invoices: Creates invoice records for any new unpaid taxes with completed periods.',
    'pipeline_step_6' => '3:00 AM — calculate-monthly-stats (2nd only): Pre-calculates dashboard statistics for the closed month.',
    'pipeline_step_7' => '10:00 AM — send-reminders: Sends payment reminders for taxes approaching or past their due date.',
    'pipeline_other' => 'Additionally, verify-payments runs every 6 hours to match wallet transfers against tax codes, and calculate-monthly-stats --current-month runs every 30 minutes to keep the dashboard current.',

    // Triggered By / Audit Trail
    'triggered_by_title' => 'Audit Trail (Triggered By)',
    'triggered_by_desc' => 'Every tax record logs who or what created it in the "Triggered By" field:',
    'triggered_by_scheduled' => 'Scheduled Task — Created automatically by the nightly calculate-taxes cron job.',
    'triggered_by_manual' => 'Manual: CharacterName — Created by an admin clicking Calculate or Recalculate in the UI.',
    'triggered_by_regenerate' => 'Regenerate: CharacterName — Created by an admin clicking Regenerate Codes in the UI.',

    // Admin Tax Controls
    'admin_controls_title' => 'Admin Tax Management',
    'admin_controls_desc' => 'Administrators have additional controls on the Tax Overview and Tax Details pages:',
    'admin_control_delete' => 'Delete — Remove a tax record entirely. Useful for incorrectly created records.',
    'admin_control_mark_paid' => 'Mark as Paid — Manually mark a tax as paid with optional notes (e.g., "Paid via contract" or "Waived"). This also marks any active tax codes as "used" to prevent the wallet verification from double-processing.',
    'admin_control_status' => 'Change Status — Change a tax record\'s status (unpaid, paid, overdue, waived). Changing to "paid" also marks tax codes as used.',

    // Daily Summaries as Source of Truth
    'daily_summaries_explained' => 'Daily Summaries (Source of Truth)',
    'daily_summaries_desc' => 'Daily summaries are the single source of truth for all tax calculations. Each summary stores a JSON breakdown per ore type containing: type ID, ore name, category, moon rarity, quantity, unit price, total value, tax rate, event modifier, effective rate, taxable flag, and estimated tax. When you view "My Taxes", the system reads directly from these summaries rather than recalculating from raw mining data.',
    'daily_summaries_when_generated' => 'Summaries are generated automatically when mining data is processed and when prices are updated. They can also be regenerated on demand using the Recalculate button or the update-daily-summaries command.',
    'daily_summaries_settings' => 'Tax rates in daily summaries always reflect your current settings at the time of generation. If you change a tax rate mid-month, use Recalculate to regenerate all summaries for that month with the new rates.',
    'daily_summaries_reconciliation_title' => 'Observer Data Reconciliation:',
    'daily_summaries_reconciliation_desc' => 'EVE ESI corporation observer data can lag 12-24 hours behind character mining data. The daily summary update command automatically reconciles the previous 2 days — matching character-imported moon ore entries against late-arriving Moon Owner Corporation observer data, removing duplicates, and adjusting quantities. Only observer data from the configured Moon Owner Corporation is used for reconciliation — observer data from other corporations is ignored. Affected daily summaries are regenerated automatically.',

    // ================================================================
    // Corporation Tax Model
    // ================================================================
    'corp_tax_model_title' => 'Corporation Tax Model — How Corporations and Tax Rates Interact',
    'corp_tax_model_intro' => 'Mining Manager uses two key settings to determine who gets taxed and at what rate. Understanding this model is essential for multi-corporation setups.',

    'corp_model_moon_owner_title' => 'Moon/Structure Owner Corporation',
    'corp_model_moon_owner_desc' => 'This is the corporation that owns the moon mining structures (configured in Settings > General). Only mining observer data from THIS corporation\'s structures is used for moon ore taxation. If someone else\'s corporation has a director in your SeAT, their observer data will flow into the database but will be completely ignored for tax purposes — you will never accidentally tax someone for mining on structures you don\'t own.',

    'corp_model_configured_title' => 'Configured Corporations (Switch Corporation Context)',
    'corp_model_configured_desc' => 'Each corporation in the "Switch Corporation Context" dropdown has its own tax rates. When a member of that corporation mines, they are taxed at their corporation\'s configured rates. Character ledger data (regular ore, ice, gas mined anywhere) is taxed based on which configured corporation the miner belongs to. If a character is not a member of any configured corporation, their character ledger mining is not taxed.',

    'corp_model_flow_title' => 'How Mining Entries Are Processed',
    'corp_model_flow_desc' => 'Every mining entry in the database has a corporation_id field. Here is how the system decides what to do with each entry:',

    'corp_model_col_situation' => 'Situation',
    'corp_model_col_source' => 'Data Source',
    'corp_model_col_result' => 'Result',

    'corp_model_row1_situation' => 'Corp A member mines on Moon Owner Corp\'s moon',
    'corp_model_row1_source' => 'Observer (Moon Owner Corp)',
    'corp_model_row1_result' => 'Taxed at Corp A\'s moon ore rates',

    'corp_model_row2_situation' => 'Guest miner (no configured corp) mines on Moon Owner Corp\'s moon',
    'corp_model_row2_source' => 'Observer (Moon Owner Corp)',
    'corp_model_row2_result' => 'Taxed at guest rates (0% = no tax)',

    'corp_model_row3_situation' => 'Anyone mines on another corp\'s moon (not Moon Owner)',
    'corp_model_row3_source' => 'Observer (other corp)',
    'corp_model_row3_result' => 'Skipped entirely — not your structure',

    'corp_model_row4_situation' => 'Corp A member mines regular ore/ice/gas anywhere',
    'corp_model_row4_source' => 'Character ledger (NULL corp)',
    'corp_model_row4_result' => 'Taxed at Corp A\'s ore/ice/gas rates',

    'corp_model_row5_situation' => 'Non-configured character mines ore anywhere',
    'corp_model_row5_source' => 'Character ledger (NULL corp)',
    'corp_model_row5_result' => 'Skipped — not a member of any configured corp',

    'corp_model_multicorp_title' => 'Multi-Corporation / Alliance Setup',
    'corp_model_multicorp_desc' => 'You can configure multiple corporations with different tax rates. For example, in an alliance: Corp A at 10% moon tax and Corp B at 15% moon tax. When Corp A members mine on the Moon Owner Corporation\'s moon, they pay Corp A\'s rates. Corp B members on the same moon pay Corp B\'s rates. If Corp B also has their own moons and a director in SeAT, that observer data flows in but is never used for your tax calculations — only Moon Owner Corporation observer data matters.',

    'corp_model_observer_warning' => 'If a SeAT user has a director alt in another corporation, that corporation\'s mining observer data will be imported into your database. This is normal SeAT behavior. Mining Manager will ignore all observer data that does not come from your configured Moon Owner Corporation — those entries are skipped during daily summary generation and tax calculation.',

    // Tax Rates and Categories
    'tax_rates_explained' => 'Tax Rates and Ore Categories',
    'tax_rates_desc' => 'Tax rates are configured per-corporation in Settings > Tax Rates. Each ore category has its own rate:',
    'tax_rate_moon_ore' => 'Moon Ore — Separate rates for each rarity tier: R64, R32, R16, R8, R4. Higher rarity typically means higher tax.',
    'tax_rate_regular_ore' => 'Regular Ore — A single rate applied to all non-moon, non-ice, non-gas ores (Veldspar, Scordite, Plagioclase, etc.).',
    'tax_rate_ice' => 'Ice — Applied to all ice mining products.',
    'tax_rate_gas' => 'Gas — Applied to all gas cloud harvesting.',
    'tax_rate_abyssal' => 'Abyssal Ore — Applied to rare ores from Abyssal Deadspace (Bezdnacine, Rakovene, Talassonite). Disabled by default in the Tax Selector.',

    // Tax Selector
    'tax_selector_explained' => 'Tax Selector (What Gets Taxed)',
    'tax_selector_desc' => 'The Tax Selector controls which ore categories are subject to taxation. Found in Settings > Tax Rates under "Tax Selector":',
    'tax_selector_all_moon' => 'All Moon Ore — Tax all moon ore regardless of which structure it was mined from.',
    'tax_selector_corp_moon' => 'Only Corp Moon Ore — Only tax moon ore mined from structures owned by your configured Moon Owner Corporation. Moon ore mined from other corporations\' structures is not taxed.',
    'tax_selector_no_moon' => 'No Moon Ore — Disable moon ore taxation entirely.',
    'tax_selector_toggles' => 'Individual toggles exist for regular ore, ice, gas, and abyssal ore. Each can be independently enabled or disabled.',

    // Guest Mining
    'guest_mining_explained' => 'Guest Mining and Guest Tax Rates',
    'guest_mining_desc' => 'A "guest miner" is any character whose corporation is not in the list of configured ("home") corporations. The home corporation list includes all corporations with settings configured (any corp in the "Switch Corporation Context" dropdown) plus the Moon Owner Corporation. Guest miners only appear via moon mining observer data — they are characters who mine on your Moon Owner Corporation\'s structures but are not members of any configured corporation.',
    'guest_rates_config' => 'Guest tax rates are configured in Settings > General under "Guest Miner Tax Rates" and are always tied to the Moon Owner Corporation. These are global rates — they do not change when you switch corporation context. You can set different rates for each category (moon ore by rarity, regular ore, ice, gas, abyssal).',
    'guest_zero_rate' => 'Setting a guest rate to 0 means actual 0% tax (no tax charged). It does NOT fall back to the regular member rate. If you want guests to pay the same rate as members, enter the same percentage. If you want guests to mine for free, set it to 0.',
    'guest_detection' => 'Guest detection happens automatically during daily summary generation by comparing each character\'s corporation ID against all configured corporations. Members of any configured corporation are taxed at that corporation\'s rates, not as guests. Guest miners\' character ledger data (non-moon mining) is not taxed — only their moon mining on your structures is subject to guest rates.',

    // Event Tax Modifiers
    'event_modifiers_explained' => 'Mining Event Tax Modifiers',
    'event_modifiers_desc' => 'Mining events can include a tax modifier that adjusts the effective tax rate for participants:',
    'event_modifier_range' => 'Modifiers range from -100 (tax-free) to +100 (double tax). A modifier of -50 means participants pay half the normal tax rate.',
    'event_modifier_calc' => 'The effective rate is calculated as: base_rate * (1 + modifier/100). For example, a 26% R64 rate with a -50 modifier becomes 13%.',
    'event_modifier_overlap' => 'When multiple events overlap for the same character on the same date, the most beneficial modifier (lowest value) is used.',
    'event_modifier_daily' => 'Event modifiers are stored in daily summaries, so they are visible in the ore breakdown on the My Taxes page.',

    // Payment Methods
    'payment_methods' => 'Payment Methods',
    'wallet_method_title' => 'Wallet Transfer Method',
    'wallet_method_desc' => 'Members send ISK directly to the corporation wallet:',
    'wallet_step_1' => 'Go to your "My Taxes" page to see your tax code and amount owed',
    'wallet_step_2' => 'In EVE, open the corporation wallet and click "Give Money"',
    'wallet_step_3' => 'Enter the tax amount and paste your tax code into the "Reason" field',
    'wallet_step_4' => 'The system will automatically verify your payment within 6 hours when the verify-payments command runs',
    'tax_warning' => 'Always include your tax code in the reason field! Without it, payments cannot be automatically matched and must be manually processed by a director.',

    // Wallet Verification
    'wallet_verification' => 'Wallet Verification',
    'wallet_verification_desc' => 'The Wallet Verification page shows corporation donations and their matching status. Access is permission-based:',
    'wallet_verification_member' => 'Members can only see their own wallet transfers and personal payment stats.',
    'wallet_verification_director' => 'Directors and admins see all corporation donations, can verify payments, sync wallets, auto-match tax codes, and manually record payments.',

    // Calculation Buttons
    'calculation_methods' => 'Calculate Taxes Page — Buttons',
    'calculation_methods_desc' => 'The Calculate Taxes page provides three action buttons. All of them read from daily summaries as the single source of truth:',
    'calc_calculate' => 'Calculate — Sums existing daily summaries to create tax records for all periods within the selected month. This is fast because it only reads stored data. Use this for routine tax finalization when you are happy with the current daily summaries. For biweekly/weekly, this creates a separate record for each period in the month.',
    'calc_recalculate' => 'Recalculate — Regenerates ALL daily summaries for the selected month using current market prices and current tax rate settings, then creates tax records for all periods. Use this after changing tax rates, after running a manual price cache refresh, or if prices were stale when summaries were originally created. This is slower because it recalculates every character/date pair.',
    'calc_assign_codes' => 'Assign Codes — Generates payment codes for any unpaid tax records that don\'t already have one. Does NOT recalculate taxes or regenerate daily summaries — it only assigns codes to existing tax records. Use this after running Calculate when you are ready to issue codes to members.',
    'calc_regenerate_codes' => 'Regenerate Codes — Performs a full recalculation (same as Recalculate) and then generates or updates unique payment codes for each member for each period. Use this when you are ready to issue tax codes to members for payment. Members will see their codes on the "My Taxes" page.',

    // Exemptions and Minimum Tax
    'exemptions_explained' => 'Exemptions and Minimum Tax',
    'exemptions_desc' => 'Two thresholds control small tax amounts:',
    'exemption_threshold' => 'Exemption Threshold — If a character\'s total tax for the month is below this amount, they are exempt from tax entirely (tax = 0 ISK). Configured in Settings > Tax Rates > Exemptions.',
    'minimum_tax' => 'Minimum Tax Amount — If a character\'s tax is above the exemption threshold but below this minimum, it is raised to the minimum. This prevents tiny invoices. Configured in Settings > Payment.',

    // Tax Codes
    'tax_codes' => 'Tax Codes Explained',
    'tax_codes_desc' => 'Each member receives a unique tax code for each tax period. The code includes a configurable prefix followed by a random alphanumeric string.',
    'tax_codes_usage' => 'Your tax code looks like this:',
    'tax_code_example' => 'TAX-MINC-VX45XUQC (prefix "TAX-MINC-" + unique code)',
    'tax_code_prefix_note' => 'The tax code prefix is configured in Settings > Wallet. The EVE transfer reason field has a 40-character limit, so keep the prefix short.',

    // Accumulated Mode
    'accumulated_mode' => 'Accumulated Mode (Alt Grouping)',
    'accumulated_mode_desc' => 'Mining Manager automatically groups all alt characters under a single main character for tax calculation. This uses SeAT\'s refresh token and user affiliation system to determine which characters belong to the same player. Tax is calculated on the combined total of all characters in the group, and the main character receives the tax invoice. The "Calculate Taxes" page shows grouped totals per main character. Characters not linked to a SeAT user account are taxed individually.',

    // Mining Events
    'mining_events_guide' => 'Mining Events Guide',
    'events_intro' => 'Mining events are organized operations where participants can earn bonus multipliers on their mining activity.',
    'creating_events' => 'Creating an Event (Directors)',
    'event_create_step_1' => 'Navigate to Events > Create Event',
    'event_create_step_2' => 'Fill in event details: name, location, start/end time',
    'event_create_step_3' => 'Set the bonus multiplier (e.g., 1.5x for 50% bonus)',
    'event_create_step_4' => 'Optionally restrict ore types (e.g., only moon ore)',
    'event_create_step_5' => 'Save and announce the event to your members',
    'participating_events' => 'Participating in Events',
    'participating_desc' => 'To participate in a mining event:',
    'participate_step_1' => 'View active events on the Events page or dashboard',
    'participate_step_2' => 'Mine during the event time window in the specified location',
    'participate_step_3' => 'Your bonus will be automatically applied to that mining',
    'bonus_tip' => 'Bonus Tip',
    'event_bonus_desc' => 'Event bonuses reduce the effective tax rate for that mining. A 1.5x bonus means you only pay 2/3 of the normal tax!',

    // Moon Mining
    'moon_mining_guide' => 'Moon Mining Guide',
    'moon_intro' => 'Moon mining features help you track extractions, analyze moon compositions, and calculate potential value from your Athanors and Tataras.',
    'moon_tracking' => 'Tracking Moon Extractions',
    'moon_tracking_desc' => 'The system automatically tracks all moon extraction activities from your structures. View extraction history, active extractions, completion progress, and structure names.',
    'moon_compositions' => 'Moon Compositions',
    'moon_compositions_desc' => 'Record and analyze the ore composition of each moon. View ore percentages, rarity classification (R4-R64), and estimated extraction values based on current market prices.',
    'extraction_notifications' => 'Extraction Notifications',
    'extraction_notifications_desc' => 'Enable notifications to remind members when moon extractions are ready for mining. Configure how many hours before chunk arrival to send the reminder.',
    'moon_value' => 'Moon Value Calculator',
    'moon_value_desc' => 'The system estimates the ISK value of each extraction based on current market prices, ore compositions, and estimated chunk sizes.',

    // Moon Extraction Lifecycle
    'moon_lifecycle' => 'Moon Extraction Lifecycle',
    'moon_lifecycle_intro' => 'Understanding the moon extraction lifecycle helps you track moon mining operations effectively.',
    'moon_status_extracting' => 'Extracting',
    'moon_status_extracting_desc' => 'The moon drill beam is active, forming a chunk. Duration depends on extraction settings (typically 6-56 days).',
    'moon_status_ready' => 'Ready',
    'moon_status_ready_desc' => 'The chunk has fractured and an asteroid belt is available for mining. Belt lasts approximately 48 hours.',
    'moon_status_unstable' => 'Unstable',
    'moon_status_unstable_desc' => 'Belt is approaching natural decay (48-51 hours after arrival). Mining should be prioritized.',
    'moon_status_expired' => 'Expired',
    'moon_status_expired_desc' => 'Belt has despawned. No further mining possible until next extraction cycle.',

    // Moon Classification
    'moon_classification' => 'Moon Classification',
    'moon_classification_desc' => 'Moons are classified by their highest rarity ore:',
    'moon_r64' => 'R64 - Contains extremely rare ores (Loparite, Monazite, Xenotime, Ytterbite)',
    'moon_r32' => 'R32 - Contains rare ores (Carnotite, Cinnabar, Pollucite, Zircon)',
    'moon_r16' => 'R16 - Contains uncommon ores (Chromite, Otavite, Sperrylite, Vanadinite)',
    'moon_r8' => 'R8 - Contains common ores (Cobaltite, Euxenite, Scheelite, Titanite)',
    'moon_r4' => 'R4 - Contains basic ores (Bitumite, Coesite, Sylvite, Zeolites)',
    'moon_quality' => 'Moon Quality Rating',
    'moon_quality_desc' => 'Moons are rated by their estimated 28-day extraction value:',
    'moon_quality_exceptional' => 'Exceptional - Over 10 billion ISK',
    'moon_quality_excellent' => 'Excellent - Over 8 billion ISK',
    'moon_quality_good' => 'Good - Over 5 billion ISK',
    'moon_quality_average' => 'Average - Over 2 billion ISK',
    'moon_quality_poor' => 'Poor - Under 2 billion ISK',

    // Theft Detection
    'theft_detection_guide' => 'Theft Detection Guide',
    'theft_detection_intro' => 'The theft detection system monitors your corporation\'s moon mining structures for unauthorized mining activity by non-corporation members.',
    'how_theft_detection_works' => 'How It Works',
    'theft_step_1' => 'The system compares mining ledger data from your moon structures against your corporation\'s member list.',
    'theft_step_2' => 'Any mining activity by characters not in your corporation (or alliance, depending on configuration) is flagged as potential theft.',
    'theft_step_3' => 'Active theft incidents are tracked and monitored over time, building a history of unauthorized mining.',
    'theft_commands' => 'Theft Detection Commands',
    'theft_detect_desc' => 'Full scan for unauthorized mining on all tracked moons. Runs automatically on the 1st and 15th of each month.',
    'theft_monitor_desc' => 'Monitors currently active theft incidents for ongoing unauthorized mining. Runs every 6 hours.',
    'theft_dry_run' => 'Use the --dry-run flag to preview detection results without creating incident records.',
    'theft_note' => 'Theft detection relies on ESI mining observer data. Only structures with active moon mining observers will be monitored.',

    // Analytics & Reports
    'analytics_reports_guide' => 'Analytics & Reports Guide',
    'analytics_intro' => 'The analytics section provides comprehensive insights into your corporation\'s mining performance with interactive charts and detailed breakdowns.',
    'available_reports' => 'Available Reports',
    'report_monthly' => 'Monthly Mining Report',
    'report_monthly_desc' => 'Complete breakdown of mining activity, taxes collected, and top performers for each month.',
    'report_member' => 'Member Activity Report',
    'report_member_desc' => 'Individual member performance, mining history, and trends.',
    'report_event' => 'Event Performance Report',
    'report_event_desc' => 'Analysis of mining event participation and effectiveness.',
    'report_moon' => 'Moon Extraction Report',
    'report_moon_desc' => 'Moon mining statistics, extraction values, and composition analysis.',
    'report_comparison' => 'Comparative Analysis',
    'report_comparison_desc' => 'Compare time periods, members, or ore types side-by-side.',

    // Moon Analytics
    'moon_analytics' => 'Moon Analytics',
    'moon_analytics_desc' => 'Detailed analytics for moon mining operations:',
    'moon_analytics_utilization' => 'Moon Utilization - Track how much of each extraction pool is actually mined, with structure names and completion percentages.',
    'moon_analytics_pool_vs_mined' => 'Pool vs Mined - Compare what was available in each extraction against what was actually mined, broken down by ore type.',
    'moon_analytics_per_extraction' => 'Per-Extraction Analysis - Detailed view of individual extractions showing pool composition, mining activity, unique miners, and ISK values.',
    'moon_analytics_popularity' => 'Ore Popularity - Track which ores are most frequently mined across all moon extractions.',

    'exporting_data' => 'Exporting Data',
    'exporting_desc' => 'Reports can be exported for further analysis or sharing with your leadership team.',

    // Settings
    'settings_guide' => 'Settings Configuration Guide',
    'settings_intro' => 'The settings page is organized into Global Settings (apply to all corporations), Corporation-Specific Settings (per-corp overrides), and System Settings. Admin permission is required to modify settings.',
    'settings_tabs' => 'Settings Tabs',

    // Global Settings
    'settings_global_header' => 'Global Settings',
    'settings_general' => 'General',
    'settings_general_desc' => 'Moon owner corporation, ore valuation method (ore price or mineral price), price provider (SeAT, Janice, Fuzzwork, Custom), price modifier percentage, and default region for market data.',
    'settings_pricing' => 'Pricing',
    'settings_pricing_desc' => 'Price type (sell, buy, or average), refining efficiency for mineral price valuation, cache duration, and market hub selection. Controls how ore values are calculated across the plugin.',
    'settings_features' => 'Features',
    'settings_features_desc' => 'Toggle individual plugin features on/off: wallet verification, mining events, theft detection, moon analytics, notifications, reports, and diagnostics. Disabled features are hidden from the UI.',
    'settings_webhooks' => 'Webhooks',
    'settings_webhooks_desc' => 'Configure Discord and Slack webhook integrations for notifications. Set up webhook URLs, event triggers (theft detected, extraction ready, tax reminders), severity levels, and role pinging.',
    'settings_notifications' => 'Notifications',
    'settings_notifications_desc' => 'Configure notification channels (EVE Mail, Discord, Slack) and event triggers. Set up sender characters for EVE Mail, notification templates, and delivery preferences for tax reminders, event announcements, and extraction alerts.',
    'settings_dashboard' => 'Dashboard',
    'settings_dashboard_desc' => 'Customize dashboard appearance and behavior. Configure which charts and widgets are visible, default date ranges, top miner counts, and chart display preferences.',

    // Corporation-Specific Settings
    'settings_corp_header' => 'Corporation-Specific Settings',
    'settings_tax_rates' => 'Tax Rates',
    'settings_tax_rates_desc' => 'Per-corporation tax configuration with three sections: Tax Rates (moon ore by rarity R64-R4, regular ore, ice, gas, abyssal), Tax Selector (which ore types to include), and Exemptions (minimum thresholds). Guest tax rates apply different rates for characters not belonging to any configured corporation.',

    // System Settings
    'settings_system_header' => 'System Settings',
    'settings_advanced' => 'Advanced',
    'settings_advanced_desc' => 'System maintenance tools: clear price cache, reset settings to defaults, export/import settings as JSON, and view system information. Use with caution — some actions cannot be undone.',
    'settings_help' => 'Help & Documentation',
    'settings_help_desc' => 'Quick access to this help page from within the settings panel.',

    'settings_warning' => 'Admin Permission Required',
    'settings_warning_desc' => 'Only users with admin permissions can modify settings. Changes affect all members immediately.',

    // Custom Styling
    'custom_styling' => 'Custom Styling',
    'custom_styling_guide' => 'CSS Overrides Guide',
    'custom_styling_intro' => 'Mining Manager uses CSS wrapper classes on every page, allowing you to customize the appearance using SeAT\'s custom CSS feature or your own stylesheets.',
    'css_class_hierarchy' => 'CSS Class Hierarchy',
    'css_class_hierarchy_desc' => 'Every page uses a layered class structure for targeted styling:',
    'css_base_class' => '.mining-manager-wrapper - Present on ALL plugin pages. Use this to style everything globally.',
    'css_tab_class' => '.mining-dashboard - Applied to pages with Dashboard-style tab navigation. Controls tab appearance (font size, active state, rounded corners).',
    'css_page_class' => 'Page-specific classes - Target individual pages for fine-grained overrides.',
    'css_available_pages' => 'Available Page Classes',
    'css_analytics_pages' => 'Analytics: .analytics-page, .analytics-charts-page, .analytics-tables-page, .analytics-compare-page, .analytics-moons-page',
    'css_dashboard_pages' => 'Dashboard: .combined-director-dashboard, .personal-dashboard',
    'css_diagnostic_pages' => 'Diagnostics: .diagnostic-page',
    'css_events_pages' => 'Events: .events-page, .events-active-page, .events-calendar-page, .events-create-page, .events-my-events-page',
    'css_ledger_pages' => 'Ledger: .mining-ledger, .mining-ledger-summary, .mining-ledger-details, .my-mining',
    'css_moon_pages' => 'Moon: .moon-index-page, .moon-active-page, .moon-calendar-page, .moon-compositions-page, .moon-extractions-page, .moon-simulator-page',
    'css_reports_pages' => 'Reports: .reports-page, .reports-generate-page, .reports-export-page, .reports-scheduled-page',
    'css_settings_pages' => 'Settings: .settings-page',
    'css_taxes_pages' => 'Taxes: .taxes-index-page, .taxes-calculate-page, .taxes-codes-page, .taxes-my-taxes-page, .taxes-wallet-page',
    'css_theft_pages' => 'Theft: .theft-detection-wrapper',
    'css_example_title' => 'Example Overrides',
    'css_example_global' => '/* Change tab font size on all pages */',
    'css_example_global_code' => '.mining-dashboard .nav-tabs .nav-link { font-size: 0.85rem; }',
    'css_example_specific' => '/* Change tab font size only on the diagnostic page */',
    'css_example_specific_code' => '.diagnostic-page .nav-tabs .nav-link { font-size: 0.90rem; }',
    'css_example_all' => '/* Add custom background to all plugin pages */',
    'css_example_all_code' => '.mining-manager-wrapper { background-color: #1a1a2e; }',
    'css_where_to_add' => 'Where to Add Custom CSS',
    'css_where_to_add_desc' => 'Add your CSS overrides in SeAT\'s custom CSS section (Administration > SeAT Settings > Custom CSS) or in your own theme stylesheet. This way your customizations survive plugin updates.',

    // FAQ
    'frequently_asked' => 'Frequently Asked Questions',
    'faq_q1' => 'How often is mining data updated?',
    'faq_a1' => 'Mining ledger data is fetched from ESI and processed every 30 minutes by the scheduled command. Moon extractions update every 6 hours.',
    'faq_q2' => 'How does alt grouping work for taxes?',
    'faq_a2' => 'Mining Manager uses accumulated mode, which groups all alt characters under the main character (via SeAT\'s refresh token / user affiliation system). Tax is calculated on the combined total.',
    'faq_q3' => 'What happens if someone doesn\'t pay their taxes?',
    'faq_a3' => 'The system tracks payment status and sends reminder notifications daily at 10:00 AM for taxes approaching their due date (configurable reminder window, default 3 days before) or already overdue. Directors can view all outstanding taxes on the Tax Overview page.',
    'faq_q4' => 'Where do I put my tax code when paying?',
    'faq_a4' => 'When sending ISK to the corporation wallet in-game, enter your tax code in the "reason" field of the transfer dialog. This is how the system matches your payment.',
    'faq_q5' => 'How are ore prices determined?',
    'faq_a5' => 'Ore prices are fetched from your configured price provider and cached. You can choose between sell, buy, or average prices from your selected market hub region.',
    'faq_q6' => 'Can members see other members\' mining data?',
    'faq_a6' => 'Members with the "view" permission can only see their own data and the help page. Director and admin permissions grant access to corporation-wide data and management features.',
    'faq_q7' => 'What is a jackpot extraction?',
    'faq_a7' => 'A jackpot extraction occurs when the moon drill hits an exceptionally rich deposit, yielding significantly more ore than normal. The system automatically detects and flags these.',
    'faq_q8' => 'How does theft detection work?',
    'faq_a8' => 'The system compares mining observer data from your moon structures against your corporation member list. Any mining by non-members is flagged as potential theft.',
    'faq_q9' => 'How do mining events work?',
    'faq_a9' => 'Mining events are time-limited operations where participants earn bonus multipliers. The bonus reduces effective tax rates, incentivizing participation.',
    'faq_q10' => 'Is this compatible with other SeAT plugins?',
    'faq_a10' => 'Mining Manager is designed to work alongside other SeAT v5.x plugins. It uses standard SeAT authentication, permissions, and database systems.',
    'faq_q11' => 'Why are taxes calculated on the 2nd instead of the 1st?',
    'faq_a11' => 'EVE ESI corporation mining observer data can lag 12-24 hours behind the actual mining. Tax calculation, monthly finalization, and stats are shifted to the 2nd of the month (or Tuesday for weekly, 2nd/16th for biweekly) to allow this late-arriving data to settle. Additionally, the daily summary update command runs a reconciliation step on the previous 2 days, matching character-imported entries against observer data that arrived late.',
    'faq_q12' => 'Why does some moon ore show 0% tax?',
    'faq_a12' => 'If you use the "Only Corp Moon Ore" tax selector, moon ore must have observer data (corporation_id) to be taxed. Character mining ESI data arrives without corporation_id — it only gets linked when corporation observer data arrives (up to 24h later). The daily reconciliation process matches these entries automatically. If you see 0% tax, wait for the next update-daily-summaries run or manually trigger it.',

    // Troubleshooting
    'troubleshooting_guide' => 'Troubleshooting Guide',
    'troubleshooting_intro' => 'Having issues? Here are solutions to common problems.',
    'common_issues' => 'Common Issues',

    'issue_1_title' => 'Mining data not showing up',
    'issue_1_desc' => 'If your mining isn\'t being tracked:',
    'issue_1_solution_1' => 'Verify ESI tokens are valid and have the correct scopes (check SeAT settings)',
    'issue_1_solution_2' => 'Wait 30 minutes for the next scheduled ledger processing',
    'issue_1_solution_3' => 'Check that the character is in the correct corporation',
    'issue_1_solution_4' => 'Run php artisan mining-manager:diagnose-character {character_id} to check data',

    'issue_2_title' => 'Prices seem wrong or outdated',
    'issue_2_desc' => 'If ore values look incorrect:',
    'issue_2_solution_1' => 'Check your price provider and market hub in Settings > Pricing',
    'issue_2_solution_2' => 'Run php artisan mining-manager:diagnose-prices to test provider connectivity',
    'issue_2_solution_3' => 'Run php artisan mining-manager:cache-prices to force a price refresh',

    'issue_3_title' => 'Tax payment not being recognized',
    'issue_3_desc' => 'If your payment isn\'t showing as verified:',
    'issue_3_solution_1' => 'Verify you included your tax code in the transfer reason field (not the description)',
    'issue_3_solution_2' => 'Wait for the verify-payments command to run (every 6 hours), or ask a director to check',
    'issue_3_solution_3' => 'Check the Wallet Verification page to see if your payment appears with the correct reason text',

    'issue_4_title' => 'Moon extraction data missing',
    'issue_4_desc' => 'If moon extractions aren\'t showing:',
    'issue_4_solution_1' => 'Ensure the corporation has structure ESI scopes configured',
    'issue_4_solution_2' => 'Run php artisan mining-manager:diagnose-extractions to check extraction data',
    'issue_4_solution_3' => 'Run php artisan mining-manager:update-extractions to force an update',

    'need_help' => 'Still Need Help?',
    'support_message' => 'If you\'re still experiencing issues, please contact your corporation directors or file an issue on the GitHub repository with detailed information about the problem.',

    // Permissions
    'permissions_guide' => 'Permissions Guide',
    'permissions_intro' => 'Mining Manager uses SeAT\'s permission system with four access tiers. All permissions are in the financial division.',
    'available_permissions' => 'Available Permissions',
    'perm_view' => 'mining-manager.view',
    'perm_view_desc' => 'Basic access to the help page only. Grants no access to mining data or management features.',
    'perm_member' => 'mining-manager.member',
    'perm_member_desc' => 'Corp miner access. View personal mining data, tax status, My Mining page, and participate in mining events.',
    'perm_director' => 'mining-manager.director',
    'perm_director_desc' => 'Corp management access. View all member data, analytics, reports, tax management, moon mining, and theft detection.',
    'perm_admin' => 'mining-manager.admin',
    'perm_admin_desc' => 'Full control. Everything in director plus access to settings, diagnostics, manual actions, and system configuration.',
    'setting_permissions' => 'Setting Up Permissions',
    'setting_permissions_desc' => 'Permissions are managed through SeAT\'s standard ACL system. Navigate to SeAT Configuration > Access Control to assign roles and permissions to users. Most corporation members should receive the "member" permission, directors get "director", and only administrators get "admin".',

    // CLI Commands
    'cli_intro' => 'Run commands via SSH from your SeAT installation directory. For Docker installations: docker exec -it seat-docker-front-1 php artisan command-name. For bare-metal: php artisan command-name',
    'cli_scheduled' => 'Scheduled Commands',
    'cli_scheduled_desc' => 'These commands run automatically via Laravel\'s task scheduler. You can also run them manually at any time:',
    'cli_diagnostic' => 'Diagnostic Commands',
    'cli_diagnostic_desc' => 'Use these commands to troubleshoot issues. They do not modify data unless specified:',
    'cli_manual' => 'Common Manual Commands',
    'cli_manual_desc' => 'Frequently used commands with their most useful options:',
    'cli_data_management' => 'Data Management Commands',
    'cli_data_management_desc' => 'One-time or maintenance commands for data backfilling and cleanup:',
    'cli_test' => 'Test Commands (Development Only)',
    'cli_test_desc' => 'Commands for generating test data. Only use in development environments — they create fake data:',

    // Schedule Labels
    'schedule_30min' => 'Every 30 min',
    'schedule_2hours' => 'Every 2 hours',
    'schedule_4hours' => 'Every 4 hours',
    'schedule_6hours' => 'Every 6 hours',
    'schedule_daily' => 'Daily',
    'schedule_twice_daily' => 'Twice daily',
    'schedule_monthly' => '2nd of month',
    'schedule_twice_monthly' => '1st & 15th',
    'schedule_daily_smart' => 'Daily (smart)',
    'schedule_manual' => 'Manual',

    // ================================================================
    // Navigation - New Sections
    // ================================================================
    'how_to_pay' => 'How to Pay Taxes',
    'how_to_collect' => 'How to Collect Taxes',
    'webhooks_notifications' => 'Webhooks & Notifications',

    // ================================================================
    // How to Pay Your Taxes (Member Guide)
    // ================================================================
    'how_to_pay_title' => 'How to Pay Your Mining Taxes',
    'how_to_pay_intro' => 'This is a step-by-step guide for corporation members on how the tax system works and how to pay your mining taxes. If you mine on corporation moons, you owe tax on what you mine. Here is exactly what happens and what you need to do.',

    'pay_timeline_title' => 'What Happens (Timeline)',
    'pay_step_1' => 'You mine ore on a corporation moon (or anywhere, depending on corp settings). The system automatically tracks what you mine via ESI data — you do not need to report anything.',
    'pay_step_2' => 'Every day, the system calculates the value of what you mined and how much tax you owe based on the ore type and corporation tax rates. This is stored as a daily summary.',
    'pay_step_3' => 'At the end of the tax period (monthly, biweekly, or weekly — depends on your corp settings), all your daily totals are added up into one tax bill.',
    'pay_step_4' => 'You receive a notification (Discord, Slack, or EVE Mail depending on corp setup) with your tax amount and a link to the Tax page.',
    'pay_step_5' => 'Go to Mining Manager > My Taxes to see your bill. You will see the amount owed, due date, and your unique tax code (e.g. TAX-A1B2C3).',
    'pay_step_6' => 'Send ISK to your corporation wallet in-game with your tax code in the "reason" field. That is how the system matches your payment to your bill.',
    'pay_step_7' => 'The system automatically scans wallet transactions every 6 hours. Once it finds your payment with the matching tax code, your status changes to "Paid". Done!',

    'pay_ingame_title' => 'How to Send Payment In-Game',
    'pay_ingame_step_1' => 'Open your wallet in EVE Online.',
    'pay_ingame_step_2' => 'Click "Give Money" or use the corporation\'s "Deposit" option.',
    'pay_ingame_step_3' => 'Set the recipient to your corporation.',
    'pay_ingame_step_4' => 'Enter the exact amount shown on your tax bill (or a partial amount if paying in installments).',
    'pay_ingame_step_5' => 'In the "Reason" field, paste your tax code exactly as shown (e.g. TAX-A1B2C3). This is the most important step — without the code, the system cannot match your payment.',

    'pay_reason_warning' => 'The tax code MUST be in the "Reason" field of the wallet transfer, not the description or anywhere else. If you forget the code or type it wrong, your payment will not be automatically matched and a director will need to manually verify it.',

    'pay_partial_title' => 'Can I Pay in Installments?',
    'pay_partial_desc' => 'Yes. You can split your payment into multiple transfers using the same tax code each time. For example, if you owe 100M ISK, you can send 50M now and 50M later — both with the same tax code. The system tracks partial payments and updates your status accordingly (Partial → Paid once the full amount is received).',

    'pay_verification_title' => 'How Do I Know My Payment Went Through?',
    'pay_verification_desc' => 'Go to Mining Manager > My Taxes. Your tax status will update automatically: "Unpaid" means no payment detected yet, "Partial" means some amount received but not the full bill, "Paid" means you are all clear. The Wallet Verification page also shows your payment history. Payments are scanned every 6 hours, so it may take up to 6 hours for your status to update.',

    'pay_tip' => 'If you have alt characters linked to your SeAT account, all their mining is combined into one tax bill under your main character. You only need to pay once for all your alts.',

    // ================================================================
    // How to Collect Taxes (Director Guide)
    // ================================================================
    'how_to_collect_title' => 'How to Collect Mining Taxes (Director Guide)',
    'how_to_collect_intro' => 'This guide walks directors through the entire tax collection process — from initial setup to verifying payments and handling issues.',

    'collect_setup_title' => 'One-Time Setup',
    'collect_setup_desc' => 'Before taxes can be collected, an admin needs to configure these settings:',
    'collect_setup_step_1' => 'Settings > Tax Rates — Set tax percentages for each ore type (moon R64-R4, regular ore, ice, gas). The tax selector controls which ore types are taxed.',
    'collect_setup_step_2' => 'Settings > General — Set the moon owner corporation, price provider, and valuation method. This controls how ore values are calculated.',
    'collect_setup_step_3' => 'Settings > Webhooks — Create webhook(s) for Discord/Slack notifications. Enable the tax-related toggles (Tax Generated, Tax Reminder, Tax Invoice, Tax Overdue) so members get notified.',
    'collect_setup_step_4' => 'Settings > Features — Make sure "Wallet Verification" and "Notifications" are enabled.',

    'collect_timeline_title' => 'The Tax Collection Timeline',
    'collect_timeline_desc' => 'Here is what happens each month (or period), step by step:',
    'collect_step_1' => 'Mining data flows in automatically via ESI. The system processes it every 30 minutes and updates daily summaries with current prices and tax rates.',
    'collect_step_2' => 'On the 2nd of the month (or period boundary), the system automatically calculates taxes for the previous period. It sums up each character\'s daily summaries, groups alts under their main character, and creates tax bills.',
    'collect_step_3' => 'Tax codes are automatically generated and assigned to each bill. These unique codes (e.g. TAX-A1B2C3) are how members identify their payments.',
    'collect_step_4' => 'Notifications go out — members receive their tax bill amount, due date, and a link to the tax page. Check the Tax Overview page to see all generated bills.',
    'collect_step_5' => 'Members pay by sending ISK to the corp wallet with their tax code in the "Reason" field.',
    'collect_step_6' => 'Every 6 hours, the verify-payments command scans the corporation wallet journal and auto-matches payments using tax codes. Matched payments update the bill status.',
    'collect_step_7' => 'Daily at 10:00 AM, the send-reminders command checks for unpaid taxes approaching their due date and sends reminder notifications to those members.',
    'collect_step_8' => 'After the grace period, unpaid taxes are marked as "Overdue". You can view all overdue taxes on the Tax Overview page and take action.',

    'collect_reminders_title' => 'Sending Reminders',
    'collect_reminders_desc' => 'There are three ways to remind members about unpaid taxes:',
    'collect_reminder_auto' => 'Automatic — The send-reminders command runs daily at 10:00 AM and notifies members with taxes due within the configured reminder window (default: 3 days before due date) or already overdue.',
    'collect_reminder_individual' => 'Individual — On the Tax Overview page, click the bell icon next to any unpaid tax to send a reminder to that specific member.',
    'collect_reminder_bulk' => 'Bulk — Click the "Remind All Unpaid" button on the Tax Overview page to send reminders to ALL members with unpaid, partial, or overdue taxes in one click. Personal reminders skip the general role ping to avoid spam.',

    'collect_verify_title' => 'Verifying Payments',
    'collect_verify_desc' => 'Payment verification happens automatically, but directors have additional tools:',
    'collect_verify_auto' => 'Auto-match — Runs every 6 hours. Scans wallet journal for transfers containing tax codes and matches them to bills. Handles partial payments and prevents duplicate processing.',
    'collect_verify_manual' => 'Manual verify — On the Wallet Verification page (visible to directors and admins), you can see all corporation wallet transactions and manually match payments that were not auto-detected.',
    'collect_verify_reset' => 'Reset month — If payment data gets corrupted (e.g. double-counted transactions), run: docker exec -it seat-docker-front-1 php artisan mining-manager:verify-payments --reset-month=2026-03 — this resets all payment data for that month and re-matches everything from scratch.',

    'collect_troubleshoot_title' => 'Troubleshooting Payments',
    'collect_troubleshoot_desc' => 'If a member says they paid but it is not showing: check the Wallet Verification page to see if the transaction exists. Common issues include misspelled tax codes, missing "Reason" field, or sending to the wrong corp wallet. Directors can manually mark taxes as paid from the Tax Overview page if needed.',

    'collect_tip' => 'Use the Diagnostic page > Tax Trace to investigate specific characters. It shows stored daily summaries, live recalculation comparison, account/alt info, and flags mismatches between the bill and what was calculated.',

    // ================================================================
    // Webhooks & Notifications
    // ================================================================
    'webhooks_notifications_title' => 'Webhooks & Notifications',
    'webhooks_notifications_intro' => 'Mining Manager can send notifications to Discord and Slack via webhooks. Each webhook is independently configured with its own URL and event toggles, so you can route different notification types to different channels.',

    'webhook_setup_title' => 'Setting Up a Webhook',
    'webhook_setup_desc' => 'To create a new webhook:',
    'webhook_setup_step_1' => 'Go to Settings > Webhooks tab and click "Add Webhook".',
    'webhook_setup_step_2' => 'Enter a name (for your reference), select the type (Discord or Slack), and paste the webhook URL from your Discord/Slack channel settings.',
    'webhook_setup_step_3' => 'Check the boxes for which notification types this webhook should receive. Each checkbox controls a specific event — only checked events are sent to this webhook.',
    'webhook_setup_step_4' => 'Optionally set a Discord Role ID to ping that role on broadcast notifications (theft alerts, tax generated, reports). Save the webhook.',

    'webhook_multiple_title' => 'Multiple Webhooks',
    'webhook_multiple_desc' => 'You can create as many webhooks as you need. Each webhook works independently — when a notification fires, the system checks ALL enabled webhooks and sends to every one that has that event type toggled on. This lets you route notifications to different channels:',

    'webhook_example_theft' => '#theft-alerts',
    'webhook_example_theft_desc' => 'Webhook with only theft toggles enabled. Theft detected, critical theft, active theft, and incident resolved notifications go here.',
    'webhook_example_tax' => '#tax-channel',
    'webhook_example_tax_desc' => 'Webhook with only tax toggles enabled. Tax generated, reminders, invoices, and overdue notifications go here.',
    'webhook_example_officers' => '#officers',
    'webhook_example_officers_desc' => 'Webhook with everything enabled. All notifications go here for leadership visibility.',

    'webhook_toggles_title' => 'Notification Event Toggles',
    'webhook_toggles_desc' => 'Each webhook has independent toggles for every notification type. Only events with their checkbox enabled will be sent to that webhook:',

    'webhook_toggle_category' => 'Category',
    'webhook_toggle_events' => 'Events',
    'webhook_cat_theft' => 'Theft',
    'webhook_cat_theft_events' => 'Theft Detected, Critical Theft, Active Theft, Incident Resolved',
    'webhook_cat_moon' => 'Moon',
    'webhook_cat_moon_events' => 'Moon Arrival (extraction ready), Jackpot Detected',
    'webhook_cat_events' => 'Mining Events',
    'webhook_cat_events_list' => 'Event Created, Event Started, Event Completed',
    'webhook_cat_tax' => 'Tax',
    'webhook_cat_tax_events' => 'Tax Generated (broadcast when period taxes are calculated), Tax Reminder (personal to member), Tax Invoice (personal), Tax Overdue (personal)',
    'webhook_cat_reports' => 'Reports',
    'webhook_cat_reports_events' => 'Report Generated (when a scheduled report completes)',

    'webhook_role_ping_title' => 'Discord Role Pinging',
    'webhook_role_ping_desc' => 'If you set a Discord Role ID on a webhook, that role will be @mentioned on broadcast notifications like theft alerts, tax generated, event announcements, and reports. However, personal notifications (tax reminders, invoices, overdue notices sent to individual members) automatically skip the role ping to avoid spamming the entire role every time one person gets a reminder.',

    'webhook_role_ping_note' => 'Personal notifications (reminders, invoices, overdue) never ping the role — even if one is configured. This is by design so that individual tax reminders do not spam your entire officer team or corporation.',

    // ================================================================
    // Diagnostic Page (Tax Trace)
    // ================================================================
    'diagnostic_page_title' => 'Diagnostic Page (Web UI)',
    'diagnostic_page_desc' => 'The Diagnostic page (accessible to admins under Mining Manager > Diagnostic) provides web-based tools for troubleshooting. The most powerful tool is the Tax Trace.',

    'tax_trace_title' => 'Tax Trace',
    'tax_trace_desc' => 'Enter a character ID and month to get a comprehensive tax diagnostic. The trace shows four sections:',

    'tax_trace_section_1' => 'Character & Account Info',
    'tax_trace_section_1_desc' => 'Shows the character, their corporation, main account (which SeAT user they belong to), all alt characters under that account, and the tax bill for that month including status, amount, due date, and tax code.',

    'tax_trace_section_2' => 'Stored Daily Summaries',
    'tax_trace_section_2_desc' => 'Shows the actual data the system used to calculate the tax bill. Each day is expandable and shows every ore type with its quantity, unit price, total value, tax rate, event modifier, effective rate, and estimated tax. Warnings flag issues like zero prices or missing data.',

    'tax_trace_section_3' => 'Live Recalculation',
    'tax_trace_section_3_desc' => 'Recalculates the tax using current prices and rates (read-only, does not change anything). Useful for comparing against stored data — if prices changed since the bill was generated, you will see the difference here.',

    'tax_trace_section_4' => 'Mismatch Detection',
    'tax_trace_section_4_desc' => 'Automatically flags problems: stored vs live tax differences, daily summary total vs bill amount discrepancies, mining ledger dates missing daily summaries, and zero-priced ore entries.',

    'tax_trace_note' => 'The live recalculation is read-only — it never changes any stored data. To actually update stored data, use the calculate-taxes or update-daily-summaries commands.',
];
