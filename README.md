# Mining Manager for SeAT

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/mining-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/mining-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-blue.svg?style=flat-square)](https://github.com/eveseat/seat)

A comprehensive mining management plugin for SeAT. Track mining operations, manage moon extractions, calculate taxes, and generate detailed analytics for your corporation's mining activities.

📸 **[See screenshots in the Wiki →](https://github.com/MattFalahe/Mining-Manager/wiki)**

## Features

### ⛏️ Mining Activity Tracking
- **Automated ledger processing** from character mining data
- **Real-time mining event management** with participant tracking
- **Individual and corporation-wide statistics** for performance monitoring
- **Mining event leaderboards** with quantity tracking and bonuses

### 🌕 Moon Mining Operations
- **Moon extraction tracking** with automated notifications
- **Ore composition analysis** and value calculations
- **Extraction calendar** for scheduling and planning
- **Ready-to-fracture alerts** for timely moon pops
- **Historical extraction data** and performance metrics

### 💰 Tax Management System
- **Automated monthly tax calculation** based on mining activity
- **Tax invoice generation** via EVE contracts
- **Smart payment verification** with tax code matching
- **Automated payment tracking** from wallet transactions
- **Reminder system** for unpaid taxes
- **Flexible tax rates** per ore type or flat percentage

### 📊 Analytics & Reporting
- **Customizable mining reports** (daily, weekly, monthly)
- **Mining activity trends** and performance charts
- **Character contribution analysis** for payouts
- **Export capabilities** (CSV, JSON) for external tools
- **Dashboard metrics** for quick overview

### 🤖 Full Automation
- **Event-driven data processing** for real-time updates
- **Scheduled task automation** for background jobs
- **Market price caching** for accurate ISK calculations
- **Wallet and contract monitoring** for payment tracking
- **Automatic event participant updates** as mining happens

## Installation

```bash
composer require mattfalahe/mining-manager
```

## Usage

### Dashboard
Access Mining Manager from the main SeAT sidebar. The dashboard shows:
- Recent mining activity and event status
- Quick stats for active mining operations
- Unpaid tax overview
- Upcoming moon extractions

### Mining Ledger
Track all mining activity:
- Character-by-character mining records
- Filter by date range, ore type, or solar system
- View quantities mined and estimated values
- Export data for analysis

### Mining Events
Manage corporation mining operations:
- Create and schedule mining events
- Track participant contributions in real-time
- Set bonus percentages for event participation
- View event leaderboards and statistics
- Automatic ore tracking during events

### Moon Mining
Monitor moon extraction operations:
- View active and scheduled extractions
- Track extraction progress and timelines
- Ore composition and estimated values
- Automatic notifications when ready to fracture
- Historical extraction records

### Tax Management
Handle mining taxes efficiently:
- Automated monthly tax calculations
- View unpaid, paid, and overdue taxes
- Generate and send tax invoices as contracts
- Track payments automatically via tax codes
- Send reminder notifications
- Manual payment verification tools

### Reports & Analytics
Generate detailed mining reports:
- Performance trends over time
- Top miners and contributors
- System and ore type breakdowns
- Tax collection statistics
- Custom date ranges and filters

## Permissions

Mining Manager uses SeAT's permission system:

- `mining-manager.view`: View mining data and reports
- `mining-manager.manage_events`: Create and manage mining events
- `mining-manager.manage_taxes`: Calculate and manage taxes
- `mining-manager.admin`: Full administrative access

Assign permissions via SeAT's Settings → Access Management.

## Automated Tasks

The plugin runs nine scheduled jobs for full automation:

### High-Frequency Tasks
- **Cache Market Prices** - Every 15 minutes
- **Update Moon Extractions** - Every 30 minutes

### Hourly Tasks
- **Process Mining Ledger** - :05 past each hour
- **Update Mining Events** - :15 past each hour
- **Verify Wallet Payments** - :35 past each hour

### Daily Tasks
- **Send Tax Reminders** - 10:00 AM daily
- **Generate Reports** - 4:00 AM daily

### Monthly Tasks
- **Calculate Monthly Taxes** - 1st of month at 2:00 AM
- **Generate Tax Invoices** - 2nd of month at 3:00 AM

### Manual Commands

```bash
# Process mining ledger manually
php artisan mining-manager:process-ledger

# Update mining events
php artisan mining-manager:update-events

# Calculate taxes for specific month
php artisan mining-manager:calculate-taxes

# Generate tax invoices
php artisan mining-manager:generate-invoices

# Verify wallet payments
php artisan mining-manager:verify-payments

# Send tax reminders
php artisan mining-manager:send-reminders

# Update moon extractions
php artisan mining-manager:update-extractions

# Cache market prices
php artisan mining-manager:cache-prices

# Generate reports
php artisan mining-manager:generate-reports
```

## Requirements

- SeAT 5.x
- PHP 8.0 or higher
- MySQL/MariaDB
- Character mining ledger data tracked by SeAT
- Corporation structure data (for moon mining)
- Wallet and contract tracking (for tax automation)

## Troubleshooting

### No mining data showing
1. Ensure character ESI tokens have the required scopes
2. Wait for SeAT to complete initial character mining ledger sync
3. Run `php artisan mining-manager:process-ledger` manually
4. Check Laravel logs for any errors

### Tax payments not auto-matching
1. Verify wallet journal tracking is enabled in SeAT
2. Ensure tax code is included in payment description (e.g., "TAX-ABC123")
3. Check that `mining-manager.tax.auto_match_payments` config is enabled
4. Run `php artisan mining-manager:verify-payments` manually

### Moon extractions not updating
1. Ensure corporation structures are tracked by SeAT
2. Verify structure ESI scopes are correct
3. Check that extraction data is present in SeAT database
4. Run `php artisan mining-manager:update-extractions` manually

### Events not tracking participant mining
1. Verify event has correct solar system set (if filtering by system)
2. Ensure event time range covers mining activity
3. Check that characters are mining during event period
4. Event listener must be registered (automatic after installation)

## Support & Contributing

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/Mining-Manager/issues)
- **Discussions**: [GitHub Discussions](https://github.com/MattFalahe/Mining-Manager/discussions)
- **Wiki**: [Full documentation](https://github.com/MattFalahe/Mining-Manager/wiki)
- **Pull Requests**: Always welcome!

## License

This project is licensed under the GNU General Public License v2.0 - see the [LICENSE](LICENSE) file for details.

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

## Credits

**Author**: Matt Falahe  
**Version**: 2.0-dev  
**SeAT Compatibility**: 5.x

Built for the EVE Online community. Special thanks to the SeAT development team and all contributors.

---

*EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights are reserved worldwide. All other trademarks are the property of their respective owners. EVE Online, the EVE logo, EVE and all associated logos and designs are the intellectual property relating to these trademarks are likewise the intellectual property of CCP hf. All artwork, screenshots, characters, vehicles, storylines, world facts or other recognizable features of the intellectual property relating to these trademarks are likewise the intellectual property of CCP hf.*
