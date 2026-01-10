# Mining Manager for SeAT

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/mining-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/mining-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-blue.svg?style=flat-square)](https://github.com/eveseat/seat)

A comprehensive mining management plugin for SeAT 5.x. Track mining operations, manage moon extractions, calculate taxes, and generate detailed reports for your corporation.

## Features

- **Mining Ledger** -- Automated processing of character and corporation mining data with daily summary aggregation
- **Moon Mining** -- Extraction tracking, ore composition, value estimation, jackpot detection (automatic + manual reporting), chunk arrival alerts
- **Tax System** -- Daily summary-based tax calculation with per-ore rates (moon R4-R64, regular ore, ice, gas, abyssal, triglavian), multi-corporation support, guest mining rates, event modifiers, configurable minimum tax amount with exempt/enforce behavior, wallet payment verification, and manual payment entry
- **Mining Events** -- Create events with tax modifiers, track participants, leaderboards
- **Reports** -- Daily/weekly/monthly reports with PDF/CSV/JSON export and scheduled Discord/Slack delivery
- **Theft Detection** -- Detect and monitor unauthorized mining with severity classification and incident tracking
- **Dashboard** -- Corporation-wide analytics with 12-month charts, leaderboards, and statistics
- **Notifications** -- 15 notification types via Discord webhooks and Slack with per-webhook event toggles
- **Diagnostics** -- 15-tab diagnostic suite: test data, price provider, cache health, system validation, settings health, tax trace, data integrity, valuation test, system status, notification testing, moon extractions, tax pipeline, theft detection, event lifecycle, analytics and reports

## Requirements

- SeAT 5.x
- PHP 8.1+
- MariaDB / MySQL

## Installation

```bash
composer require mattfalahe/mining-manager
php artisan migrate
php artisan db:seed --class=MiningManager\\Database\\Seeders\\ScheduleSeeder
```

After installation:

1. Open SeAT and navigate to **Mining Manager > Settings > General**
2. Set your **Moon Owner Corporation**
3. Configure tax rates in **Settings > Tax Rates**
4. Run the setup wizard to populate your data:

```bash
php artisan mining-manager:initialize
```

The wizard verifies your settings, populates current month data (prices, mining entries, summaries, extractions), and optionally backfills historical data for reports and analytics.

## Configuration

### Key Settings

| Setting | Location | Description |
|---|---|---|
| Moon Owner Corporation | Settings > General | Which corporation owns the moon structures -- determines observer data scope |
| Tax Rates | Settings > Tax Rates | Per-corporation rates for moon ore (R4-R64), regular ore, ice, gas, abyssal, triglavian |
| Guest Miner Tax Rates | Settings > General | Global rates for non-member miners on your moons (0% = no tax) |
| Tax Selector | Settings > Tax Rates | Choose what ore types to tax (all moon ore / only corp moon ore / none + regular types) |
| Price Provider | Settings > Pricing | Market data source (SeAT, Fuzzwork, Janice, or Manager Core) |

### Corporation Tax Model

| Miner Type | Data Source | Tax Rate Applied |
|---|---|---|
| Member of configured corp | Moon observer + character ledger | That corp's tax rates |
| Guest miner (not in any configured corp) | Moon observer only | Guest tax rates (from General Settings) |
| Non-member mining elsewhere | Not processed | Not taxed |

## Permissions

4-tier permission model -- higher tiers inherit all lower tier access.

| Permission | Tier | Description |
|---|---|---|
| `mining-manager.view` | Base | Help page access |
| `mining-manager.member` | Member | View own mining data, join events, view moon schedules, report jackpots, reprocessing calculator |
| `mining-manager.director` | Director | View all corp data, manage operations, analytics, reports, theft detection |
| `mining-manager.admin` | Admin | Full control: settings, tax management, delete actions, API, diagnostics |

## Artisan Commands

31 commands available, 21 run on automated schedules via SeAT's scheduler.

### Operational Commands

| Command | Schedule | Description |
|---|---|---|
| `mining-manager:process-ledger` | Every 30min (:15, :45) | Process corporation observer mining data |
| `mining-manager:import-character-mining` | Every 30min (:20, :50) | Import character mining from SeAT ESI cache |
| `mining-manager:update-extractions` | Every 2h | Refresh moon extraction data from ESI |
| `mining-manager:update-events` | Every 2h | Update mining event status and participant data |
| `mining-manager:cache-prices` | Every 4h (:30) | Cache market prices from configured provider |
| `mining-manager:update-ledger-prices` | Daily 1:00 AM | Lock in daily session prices for mining entries |
| `mining-manager:update-daily-summaries` | Daily 1:30 AM | Safety net for non-observer mining data |
| `mining-manager:calculate-taxes` | Daily 2:15 AM | Update running month-to-date tax totals |
| `mining-manager:generate-invoices` | Daily 2:30 AM | Generate tax invoices for completed periods |
| `mining-manager:verify-payments` | Every 6h (:05) | Match wallet transfers against tax codes |
| `mining-manager:send-reminders` | Daily 10:00 AM | Send tax payment reminders (if enabled in settings) |
| `mining-manager:generate-reports` | Daily 4:05 AM + hourly (scheduled) | Generate daily reports and process scheduled reports |
| `mining-manager:recalculate-extraction-values` | Twice daily (6AM/6PM) | Update moon extraction values with current prices |
| `mining-manager:archive-extractions` | Daily 5:05 AM | Archive completed extractions older than 7 days |
| `mining-manager:detect-jackpots` | Daily 6:05 AM | Detect jackpot extractions + verify manual reports |
| `mining-manager:detect-theft` | 1st and 15th 1:00 AM | Full scan for unauthorized moon mining |
| `mining-manager:monitor-active-thefts` | Every 6h (:10) | Monitor characters already on theft list |
| `mining-manager:finalize-month` | 2nd of month 2:00 AM | Pre-calculate summaries for closed month |
| `mining-manager:calculate-monthly-stats` | 2nd of month 3:00 AM + every 30min (current month) | Dashboard statistics |

### Utility Commands

| Command | Description |
|---|---|
| `mining-manager:initialize` | Guided first-time setup wizard -- verifies settings, populates current month, optional historical backfill |
| `mining-manager:backfill-ore-types` | One-time backfill of ore type flags on existing data |
| `mining-manager:backfill-extraction-notifications` | Backfill fractured_at from historical ESI notifications |
| `mining-manager:generate-tax-codes` | Generate or regenerate tax codes for invoices |
| `mining-manager:generate-test-data` | Generate test data for development/testing |
| `mining-manager:backup-data` | Export Mining Manager data for backup or migration |
| `mining-manager:restore-data` | Import Mining Manager data from a backup |
| `mining-manager:diagnose-prices` | Diagnose price cache health and market data |
| `mining-manager:diagnose-affiliation` | Debug character corporation affiliations |
| `mining-manager:diagnose-character` | Debug character mining data and imports |
| `mining-manager:diagnose-extractions` | Debug moon extraction data and notifications |
| `mining-manager:diagnose-type-ids` | Debug ore type ID classification |

## Webhook Notifications

15 notification types across 5 categories. Each webhook can independently toggle which events it receives.

Supported channels: Discord webhooks and Slack.

| Category | Events | Description |
|---|---|---|
| Tax | generated, announcement, reminder, invoice, overdue | Payment lifecycle notifications |
| Moon | arrival, jackpot | Chunk ready and jackpot detection alerts |
| Events | created, started, completed | Mining event lifecycle |
| Theft | detected, critical, active, resolved | Security alerts |
| Reports | generated | Scheduled report delivery |

## Support

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/Mining-Manager/issues)
- **Wiki**: [Documentation & Screenshots](https://github.com/MattFalahe/Mining-Manager/wiki)
- **In-App Help**: Full documentation available at Settings > Help within the plugin

## License

GNU General Public License v2.0 -- see [LICENSE](LICENSE) for details.

---

*EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights are reserved worldwide.*
