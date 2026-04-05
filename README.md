# Mining Manager for SeAT

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/mining-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/mining-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-blue.svg?style=flat-square)](https://github.com/eveseat/seat)

A comprehensive mining management plugin for SeAT 5.x. Track mining operations, manage moon extractions, calculate taxes, and generate detailed reports for your corporation.

## Features

- **Mining Ledger** — Automated processing of character and corporation mining data with daily summary aggregation
- **Moon Mining** — Extraction tracking, ore composition, value estimation, jackpot detection (automatic + manual reporting), chunk arrival alerts
- **Tax System** — Daily summary-based tax calculation with per-ore rates, multi-corporation support, guest mining rates, event modifiers, and wallet payment verification
- **Mining Events** — Create events with tax modifiers, track participants, leaderboards
- **Reports** — Daily/weekly/monthly reports with PDF/CSV/JSON export and scheduled Discord/Slack delivery
- **Theft Detection** — Detect and monitor unauthorized mining with severity classification and incident tracking
- **Dashboard** — Corporation-wide analytics with 12-month charts, leaderboards, and statistics
- **Notifications** — 13 notification types via Discord webhooks, Slack, and EVE Mail with per-webhook event toggles
- **Diagnostics** — Tax Trace, price cache health, character affiliation, and extraction debugging tools

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

After installation, access Mining Manager from the SeAT sidebar. Configure your corporation in **Settings > General** and tax rates in **Settings > Tax Rates**.

## Configuration

### Key Settings

| Setting | Location | Description |
|---|---|---|
| Moon Owner Corporation | Settings > General | Which corporation owns the moon structures — determines observer data scope |
| Tax Rates | Settings > Tax Rates | Per-corporation rates for moon ore (R4-R64), regular ore, ice, gas, abyssal |
| Guest Miner Tax Rates | Settings > General | Global rates for non-member miners on your moons (0% = no tax) |
| Tax Selector | Settings > Tax Rates | Choose what ore types to tax (all moon ore / only corp moon ore / none + regular types) |
| Price Provider | Settings > Pricing | Market data source (EVE Market or Janice API) |

### Corporation Tax Model

| Miner Type | Data Source | Tax Rate Applied |
|---|---|---|
| Member of configured corp | Moon observer + character ledger | That corp's tax rates |
| Guest miner (not in any configured corp) | Moon observer only | Guest tax rates (from General Settings) |
| Non-member mining elsewhere | Not processed | Not taxed |

## Permissions

4-tier permission model — higher tiers inherit all lower tier access.

| Permission | Tier | Description |
|---|---|---|
| `mining-manager.view` | Base | Help page access |
| `mining-manager.member` | Member | View own mining data, join events, view moon schedules, report jackpots, reprocessing calculator |
| `mining-manager.director` | Director | View all corp data, manage operations, analytics, reports, theft detection |
| `mining-manager.admin` | Admin | Full control: settings, tax management, delete actions, API, diagnostics |

## Artisan Commands

27 commands available, 21 run on automated schedules via SeAT's scheduler.

### Operational Commands

| Command | Schedule | Description |
|---|---|---|
| `mining-manager:process-ledger` | Every 30min (:15, :45) | Process corporation observer mining data |
| `mining-manager:import-character-mining` | Every 30min (:20, :50) | Import character mining from SeAT ESI cache |
| `mining-manager:update-extractions` | Every 6h | Refresh moon extraction data from ESI |
| `mining-manager:update-events` | Every 2h | Update mining event status and participant data |
| `mining-manager:cache-prices` | Every 4h (:30) | Cache market prices from configured provider |
| `mining-manager:update-ledger-prices` | Daily 1:00 AM | Lock in daily session prices for mining entries |
| `mining-manager:update-daily-summaries` | Daily 2:30 AM | Safety net for non-observer mining data |
| `mining-manager:calculate-taxes` | Daily 2:00 AM | Update running month-to-date tax totals |
| `mining-manager:generate-invoices` | 1st of month 3:00 AM | Generate tax invoices for previous month |
| `mining-manager:verify-payments` | Every 6h | Match wallet transfers against tax codes |
| `mining-manager:send-reminders` | Daily 10:00 AM | Send tax payment reminders (if enabled in settings) |
| `mining-manager:generate-reports` | Daily 4:00 AM | Generate daily reports and process scheduled reports |
| `mining-manager:recalculate-extraction-values` | Twice daily (6AM/6PM) | Update moon extraction values with current prices |
| `mining-manager:archive-extractions` | Daily 5:00 AM | Archive completed extractions older than 7 days |
| `mining-manager:detect-jackpots` | Daily 6:00 AM | Detect jackpot extractions + verify manual reports |
| `mining-manager:detect-theft` | 1st and 15th 1:00 AM | Full scan for unauthorized moon mining |
| `mining-manager:monitor-active-thefts` | Every 6h | Monitor characters already on theft list |
| `mining-manager:finalize-month` | 2nd of month 3:00 AM | Pre-calculate summaries for closed month |
| `mining-manager:calculate-monthly-stats` | 2nd of month 4:00 AM + every 30min (current month) | Dashboard statistics |

### Utility Commands

| Command | Description |
|---|---|
| `mining-manager:backfill-ore-types` | One-time backfill of ore type flags on existing data |
| `mining-manager:backfill-extraction-notifications` | Backfill fractured_at from historical ESI notifications |
| `mining-manager:generate-test-data` | Generate test data for development/testing |
| `mining-manager:diagnose-prices` | Diagnose price cache health and market data |
| `mining-manager:diagnose-affiliation` | Debug character corporation affiliations |
| `mining-manager:diagnose-character` | Debug character mining data and imports |
| `mining-manager:diagnose-extractions` | Debug moon extraction data and notifications |
| `mining-manager:diagnose-type-ids` | Debug ore type ID classification |

## Webhook Notifications

13 notification types across 5 categories. Each webhook can independently toggle which events it receives.

| Category | Events | Description |
|---|---|---|
| Tax | reminder, invoice, overdue, generated | Payment lifecycle notifications |
| Moon | arrival, jackpot | Chunk ready and jackpot detection alerts |
| Events | created, started, completed | Mining event lifecycle |
| Theft | detected, critical, active, resolved | Security alerts |
| Reports | generated | Scheduled report delivery |

## Support

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/Mining-Manager/issues)
- **Wiki**: [Documentation & Screenshots](https://github.com/MattFalahe/Mining-Manager/wiki)
- **In-App Help**: Full documentation available at Settings > Help within the plugin

## License

GNU General Public License v2.0 — see [LICENSE](LICENSE) for details.

---

*EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights are reserved worldwide.*
