# Mining Manager for SeAT

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/mining-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/mining-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-blue.svg?style=flat-square)](https://github.com/eveseat/seat)

A comprehensive mining management plugin for SeAT 5.x. Track mining operations, manage moon extractions, calculate taxes, and generate detailed reports for your corporation.

**v2.0.0 highlights** — Mining Manager works perfectly fine on its own (every existing v1.0.x install upgrades cleanly without changing a thing). When **Manager Core** is also installed, MM consumes centralised market pricing via the documented PluginBridge contract. When **Structure Manager** is also installed, MM subscribes to SM's structure-threat events and dispatches *Extraction At Risk* (fuel critical, shield/armor/hull reinforced) and *Extraction Lost* (refinery destroyed) notifications with attacker info and a one-click Structure Board deeplink. Both cross-plugin integrations are optional — toggles auto-disable when either plugin is absent.

## Features

- **Mining Ledger** -- Automated processing of character and corporation mining data with daily summary aggregation
- **Moon Mining** -- Extraction tracking, ore composition, value estimation, jackpot detection (automatic + manual reporting), chunk arrival alerts. Past Extractions table with sortable/filterable DataTables view, structure column, status filter (expired/fractured/cancelled), and search — scoped to Moon Owner Corporation only
- **Tax System** -- Daily summary-based tax calculation with per-ore rates (moon R4-R64, regular ore, ice, gas, abyssal, triglavian), multi-corporation support, guest mining rates, event modifiers (per-row attribution), configurable minimum tax amount with exempt/enforce behavior, wallet payment verification, and manual payment entry. Supports **monthly** and **biweekly** tax periods with a safe queued-switch mechanism (effective day 3 of next month to prevent row collisions). Weekly period type removed in v2.0.0 &mdash; historical weekly rows still render correctly.
- **Mining Events** -- Create events with tax modifiers (tax-free to double-tax). Dedicated `event_mining_records` table materialises the exact mining activity qualifying for each event, with all four scope filters (corporation, location, time, ore category) applied at populate time. Per-row tax attribution: the modifier applies only to mining that actually overlaps the event window, not the whole day. Historical pricing preserved via proportional allocation from the mining ledger. Event form surfaces a live tax-compatibility panel so organisers know which event types are meaningful given current tax settings. Miners see their event discount ("saved X ISK") on My Mining and My Taxes; directors see an Event Tax column + 12-month chart. Auto-detects participants, supports system/constellation/region/global location scope, shows leaderboards. Time precision: day-level for moon events (ESI limitation), sub-day for belt/ice/gas events via SeAT's fetch time.
- **Reports** -- Daily/weekly/monthly reports with PDF/CSV/JSON export and scheduled Discord/Slack delivery
- **Theft Detection** -- Detect and monitor unauthorized mining with severity classification and incident tracking
- **Dashboard** -- Corporation-wide analytics with 12-month charts, leaderboards, and statistics
- **Notifications** -- 19 notification types via Discord webhooks, Slack, EVE Mail, or custom JSON endpoints, with per-webhook event toggles. Cross-plugin alerts for fuel/shield/armor/hull/destroyed events when Manager Core + Structure Manager are installed.
- **Diagnostics** -- 16-tab diagnostic suite (default tab: Master Test, a one-click read-only smoke chain that verifies schema, settings, cross-plugin integration, pricing, notifications, lifecycle, tax pipeline, and security hardening in under 30 seconds). Plus per-area tabs: test data, price provider, cache health, system validation, settings health, tax trace, data integrity, valuation test, system status, notification testing, moon extractions, tax pipeline, theft detection, event lifecycle, analytics and reports.

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
| Tax Rates | Settings > Tax Rates | Per-corporation rates for moon ore (R4-R64), regular ore, ice, gas, abyssal, triglavian. Period type (monthly / biweekly) and the queued-switch safeguard configured here too. |
| Guest Miner Tax Rates | Settings > General | Global rates for non-member miners on your moons (0% = no tax) |
| Tax Selector | Settings > Tax Rates | Choose what ore types to tax (all moon ore / only corp moon ore / none + regular types) |
| Price Provider | Settings > Pricing | Market data source (SeAT, Fuzzwork, Janice, or Manager Core) |

### Corporation Tax Model

| Miner Type | Data Source | Tax Rate Applied |
|---|---|---|
| Member of configured corp | Moon observer + character ledger | That corp's tax rates |
| Guest miner (not in any configured corp) | Moon observer only | Guest tax rates (from General Settings) |
| Non-member mining elsewhere | Not processed | Not taxed |

### Moon Arrival Notification Architecture

Moon arrival notifications use two decoupled systems:

```
┌──────────────────────────────────────────────────────────────────┐
│  STATE SYSTEM (ESI-driven)                                       │
│  - update-extractions every 2h                                   │
│  - Pulls ESI and writes chunk_arrival_time, natural_decay_time,  │
│    fractured_at, status                                          │
│  - Answers: "what does EVE say is happening?"                    │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│  NOTIFICATION SYSTEM (time-driven)                               │
│  - check-extraction-arrivals every 1 min                         │
│  - Reads stored chunk_arrival_time, compares to now()            │
│  - Answers: "has arrival time passed + unnotified?"              │
│  - Idempotent via notification_sent flag                         │
└──────────────────────────────────────────────────────────────────┘
```

**Mental model: ESI tells us WHAT is happening. The clock tells us WHEN to notify.**

This decoupling means arrivals notify within ~60 seconds of the actual chunk arrival time regardless of ESI refresh timing or outages. The chunk_arrival_time is known the moment an extraction is first imported (days or weeks before arrival); the notification watchdog just compares it to the current time.

**Cancellation handling:** If a director cancels an extraction in-game before chunk arrival, EVE sends a `MoonminingExtractionCancelled` character notification. The state system detects this during its next ESI poll and marks the extraction as `cancelled`. The notification watchdog then skips it — no false "Moon Chunk Ready" alert fires at the originally scheduled arrival time.

## Permissions

4-tier permission model -- higher tiers inherit all lower tier access.

| Permission | Tier | Description |
|---|---|---|
| `mining-manager.view` | Base | Help page access |
| `mining-manager.member` | Member | View own mining data, join events, view moon schedules, report jackpots, reprocessing calculator |
| `mining-manager.director` | Director | View all corp data, manage operations, analytics, reports, theft detection |
| `mining-manager.admin` | Admin | Full control: settings, tax management, delete actions, API, diagnostics |

## Artisan Commands

33 commands available, 22 run on automated schedules via SeAT's scheduler.

### Operational Commands

| Command | Schedule | Description |
|---|---|---|
| `mining-manager:process-ledger` | Every 30min (:15, :45) | Process corporation observer mining data |
| `mining-manager:import-character-mining` | Every 30min (:20, :50) | Import character mining from SeAT ESI cache |
| `mining-manager:update-extractions` | Every 2h | Refresh moon extraction data from ESI (state system: what EVE says is happening) |
| `mining-manager:check-extraction-arrivals` | Every minute | Fire moon_arrival notifications based on stored chunk_arrival_time (notification system: when to notify). Idempotent via notification_sent flag |
| `mining-manager:update-events` | Every minute | Auto-transition event status (planned→active→completed) with notifications, update participant data |
| `mining-manager:cache-prices` | Every 4h (:30) | Cache market prices from configured provider |
| `mining-manager:update-ledger-prices` | Daily 1:00 AM | Lock in daily session prices for mining entries |
| `mining-manager:update-daily-summaries` | Daily 1:30 AM | Safety net for non-observer mining data |
| `mining-manager:calculate-taxes` | Daily 2:15 AM | Update running month-to-date tax totals |
| `mining-manager:generate-invoices` | Daily 2:30 AM | Generate tax invoices for completed periods with automatic tax code assignment |
| `mining-manager:verify-payments` | Every 6h (:05) | Match wallet transfers against tax codes |
| `mining-manager:send-reminders` | Daily 10:00 AM | Send tax payment reminders (if enabled in settings) |
| `mining-manager:generate-reports` | Day 9 of month 4:05 AM + hourly (scheduled) | Generate monthly report (7 days after finalize-month for collection % to mature) and process user-defined scheduled reports. Dedup guard skips if same period+type exists (use `--force` to override) |
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
| `mining-manager:backfill-extraction-history` | Reconstruct moon_extraction_history from `MoonminingExtractionStarted` notifications. Recovers past cycles for structures that pre-date plugin install. Progress bars for both dedup and processing passes. Use `--dry-run` to preview, `--structure=ID` to scope to one structure. Automatically invoked during `mining-manager:initialize` (Phase 3 historical backfill) |
| `mining-manager:generate-tax-codes` | Generate tax codes for any unpaid taxes missing active codes (auto-generated on invoice creation, this is the manual fallback) |
| `mining-manager:generate-test-data` | Generate test data for development/testing |
| `mining-manager:backup-data` | Export Mining Manager data for backup or migration |
| `mining-manager:restore-data` | Import Mining Manager data from a backup |
| `mining-manager:diagnose-prices` | Diagnose price cache health and market data |
| `mining-manager:diagnose-affiliation` | Debug character corporation affiliations |
| `mining-manager:diagnose-character` | Debug character mining data and imports |
| `mining-manager:diagnose-extractions` | Debug moon extraction data and notifications |
| `mining-manager:diagnose-type-ids` | Debug ore type ID classification |

## Webhook Notifications

16 notification types across 5 categories. Each webhook can independently toggle which events it receives.

Supported channels: Discord webhooks, Slack, and ESI in-game mail (for tax reminders/invoices/overdue notices).

| Category | Events | Description |
|---|---|---|
| Tax | generated, announcement, reminder, invoice, overdue | Payment lifecycle notifications |
| Moon | arrival, jackpot, chunk-unstable | Chunk ready, jackpot detection, capital safety warnings (~2h before chunk goes unstable) |
| Events | created, started, completed | Mining event lifecycle |
| Theft | detected, critical, active, resolved | Security alerts |
| Reports | generated | Scheduled report delivery |

All dispatch goes through a single `NotificationService` (consolidated from the previous two-dispatcher design) with 5xx/429 retry, per-type master toggles, per-channel filters, and per-webhook subscription gating. Webhooks are routable to the Tax Program Corporation (moon/theft/tax) or global (events/reports).

### Diagnostic Testing

**Mining Manager → Diagnostic → Notification Testing** provides three test modes for verifying webhook configuration:

| Mode | Scope | Purpose |
|---|---|---|
| **Preview Test** | One webhook (selected or custom URL) | Check embed layout + single-webhook wiring — renders without writing to audit log |
| **Fire Live Notification** | Full pipeline, one type, all subscribed webhooks | End-to-end verification for one specific surface. Respects corp scoping + all gates. Writes audit log. |
| **Fire ALL (Chain)** | Full pipeline, all 16 types sequentially | Post-deploy smoke test — every subscribed webhook receives every type in ~24 seconds |

Settings → Webhooks → **Test** button sends a minimal "✅ Webhook Active" ping for wiring verification.

## Support

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/Mining-Manager/issues)
- **Wiki**: [Documentation & Screenshots](https://github.com/MattFalahe/Mining-Manager/wiki)
- **In-App Help**: Full documentation available at Settings > Help within the plugin

## License

GNU General Public License v2.0 -- see [LICENSE](LICENSE) for details.

---

*EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights are reserved worldwide.*
