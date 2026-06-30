# Mining Manager for SeAT

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/mining-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/mining-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-blue.svg?style=flat-square)](https://github.com/eveseat/seat)

A comprehensive mining management plugin for SeAT 5.x. Track mining operations, manage moon extractions, calculate taxes, and generate detailed reports for your corporation.

**v2.0.2 highlights** — **The Ecosystem Era: Field Repairs**. Real production bugs the v2.0.1 Polish Pass left behind, surfaced when live traffic hit them. Critical fix: **moon_chunk_unstable capital-safety warning silently never fired** — `MoonExtractionService::determineStatus()` treated `natural_decay_time` (the auto-fracture mark, ~3h after chunk arrival) as the chunk's expiry; rows got stamped `status='expired'` ~3h after arrival even though they had 50 more hours of mineable life, and the cron filtered them out. Math now mirrors `MoonExtraction::scopeExpiredByTime()` (prefers `fractured_at + 50h`, falls back to `natural_decay_time + 50h` as a conservative estimate). Companion: **`NotificationService::send()` no longer silently skips** — the dispatcher now returns a specific reason ("No enabled channel found...", "Webhook table probe threw...", etc.) so the Diagnostic Notification Testing tab tells you exactly what's wrong instead of `reason: unknown`. New permanent backstop: **`mining-manager:validate-lifecycle-integrity` daily cron** (03:00 UTC, `--quiet-ok`) walks `moon_extractions` and warns when the persisted `status` diverges from the model's helper-computed value; `--fix` flag applies corrections. UX fix: **Mark-as-Paid modal no longer freezes on click** — Bootstrap-meets-AdminLTE CSS stacking context issue; modals now `appendTo('body')` before show. Fairness fix: **tax payments from a player's alt are now accepted** — `WalletTransferService::processTransaction()` was strict per-character match; now accepts payments from any character sharing a SeAT `user_id` (via `refresh_tokens`) with the taxed character, with audit log noting which alt actually paid. New setting `payment.accept_alt_characters` (default `true`, UI toggle at Settings → General) for operators who want strict-only mode. Every item additive, no schema changes, no new ESI scopes.

**v2.0.1 highlights** — The polish pass on top of v2.0.0's ecosystem turn. Director workflows accelerated: inline **Discord role picker** on every per-type role-id input (one-click pick from your installed Discord role source), **Notification Routing Map** (read-only Settings tab showing what fires where and who gets pinged), **Metenox Cargo readout** (director-only sidebar page surfacing what's in every Metenox Moon Drill's `MoonMaterialBay` with ISK valuation, per-drill bay fill %, and solar-system names instead of raw IDs; SeAT admins get a corp-scope picker for install-wide spot-checks while keeping the same Moon Owner Corp default directors see), plus a new **Metenox Cargo Bay Full notification** that fires when a drill crosses the configurable fill threshold (default 85%) — yield-stopping warning so directors can schedule pulls before the bay caps out. MM joins the EventBus producer side via three new `mining.extraction_*` topics (consumable by SeAT Broadcast for FC Opportunities). **Manager Core pricing centralization** — when MC is your price provider, the market + price-type lookup is now centralized in MC's Pricing Preferences page (single source of truth) with live cache invalidation via the new `pricing.preference_changed` EventBus topic; MM's Settings tab becomes a read-only status readout + a "Configure pricing in Manager Core →" deep-link button. Jita fallback now actually works on the MC path (was effectively dead code before). **Per-plugin provider override** — MC's Pricing Preferences page now lets the operator route Mining Manager through a specific provider (e.g. Janice for Jita tax accuracy) while other plugins reading the same market continue through the default provider — independent per-plugin routing without duplicate market rows. Per-surface quality lifts: **live local-time conversion** (every EVE timestamp gets a hover tooltip + opt-in inline pill in your browser's timezone, plus 1-second-tick countdowns on active extractions and upcoming events), **EVE/Local toggle** on event create/edit forms (DST-safe), **jackpot rendering hardened** against custom SeAT themes, **diagnostic page aligned** to the suite-wide standard (Health Checks default tab with intro boxes on every Tier 1 surface). Every item additive, no breaking changes, no new ESI scopes. The pricing centralization story requires Manager Core v1.0.0 (MC's first stable release); MM keeps working without Manager Core installed at all (pricing falls back to direct Fuzzwork / Janice fetches, same as v2.0.0).

**v2.0.0 highlights** — Mining Manager works perfectly fine on its own (every existing v1.0.x install upgrades cleanly without changing a thing). When **Manager Core** is also installed, MM consumes centralised market pricing via the documented PluginBridge contract. When **Structure Manager** is also installed, MM subscribes to SM's structure-threat events and dispatches *Extraction At Risk* (fuel critical, shield/armor/hull reinforced) and *Extraction Lost* (refinery destroyed) notifications with attacker info and a one-click Structure Board deeplink. Both cross-plugin integrations are optional — toggles auto-disable when either plugin is absent.

## Features

- **Mining Ledger** -- Automated processing of character and corporation mining data with daily summary aggregation
- **Moon Mining** -- Extraction tracking, ore composition, value estimation, jackpot detection (automatic + manual reporting), chunk arrival alerts. Past Extractions table with sortable/filterable DataTables view, structure column, status filter (expired/fractured/cancelled), and search — scoped to Moon Owner Corporation only
- **Moon Extraction Planner** *(Planner Era, Moon Manager / Director)* -- A corp-internal coordination calendar at **Moon Planner** for staggering refinery pulls so a small crew isn't drowned by chunks landing together (chunks not mined promptly are wasted). Shows each refinery's planned pulls alongside the real extractions; per-refinery sidebar with cadence, last arrival, and projected next pull. **Auto-fill from history** projects each refinery's next pull (needs ≥2 past arrivals; median interval at ≥3) and spreads them to honour a configurable minimum gap. **Re-anchor a recurring day** — drag a Monday moon onto Tuesday and it sticks (future projections chain off the moved slot). **<gap proximity guard** — placing/moving a pull within the minimum-gap window of another arrival raises a confirmation listing the clashing moons (enforced client- AND server-side). Plan-vs-actual reconciliation confirms a slot once the real extraction appears. SeAT can only read in-game extractions, so the planner is intent/coordination only — it never controls the structure. Gated by the new standalone `mining-manager.moon_manager` ability (directors/admins included).
- **Metenox Cargo Readout** *(v2.0.1, director-only)* -- New sidebar page showing what's currently in every Metenox Moon Drill's `MoonMaterialBay` owned by your **Moon Owner Corporation** (matches the Past Extractions table scope and the related notification). Per-drill cards with ore composition, quantity, m³ volume, percent-of-cargo bars, ISK valuation (Manager Core pricing primary, Jita/Fuzzwork fallback), structure state, **solar-system name** (joined from `solar_systems.name` with id-only fallback), and last-polled timestamp (yellow if stale > 2h). Per-drill **bay fill indicator** with color-graded progress bar (500,000 m³ Metenox MoonMaterialBay capacity, verified against SDE attribute 5693 and EVE Ref). **Admin scope picker** -- operators with `mining-manager.admin` get a dropdown above the chips listing every corp with at least one Metenox + an "All corps" aggregate option, defaulting to the same Moon Owner Corp view directors see (one-click "Back to Moon Owner" shortcut whenever off the default). Notifications still scope to Moon Owner Corp only, so admin's expanded read visibility doesn't generate extra alert traffic. **Cargo Bay Full notification** fires when a drill crosses the configurable fill threshold (default 85%, configurable 50-99%) — yield-stopping warning, dedup-latched against repeats while still over threshold, resets when cargo is pulled. Cron `mining-manager:scan-metenox-cargo-fill` every 5 min. Cross-plugin contract: PluginBridge capability `mining.metenox.cargoSnapshot($structureId)` lets Structure Manager render the bay on its structure detail page.
- **Tax System** -- Daily summary-based tax calculation with per-ore rates (moon R4-R64, regular ore, ice, gas, abyssal, triglavian), multi-corporation support, guest mining rates, event modifiers (per-row attribution), configurable minimum tax amount with exempt/enforce behavior, wallet payment verification, and manual payment entry. Supports **monthly** and **biweekly** tax periods with a safe queued-switch mechanism (effective day 3 of next month to prevent row collisions). Weekly period type removed in v2.0.0 &mdash; historical weekly rows still render correctly.
- **Mining Events** -- Create events with tax modifiers (tax-free to double-tax). Dedicated `event_mining_records` table materialises the exact mining activity qualifying for each event, with all four scope filters (corporation, location, time, ore category) applied at populate time. Per-row tax attribution: the modifier applies only to mining that actually overlaps the event window, not the whole day. Historical pricing preserved via proportional allocation from the mining ledger. Event form surfaces a live tax-compatibility panel so organisers know which event types are meaningful given current tax settings. *(v2.0.1)* Event create/edit forms gained an **EVE/UTC vs My local time** input toggle with live confirmation box; server still always stores UTC. Miners see their event discount ("saved X ISK") on My Mining and My Taxes; directors see an Event Tax column + 12-month chart.
- **Local time + live countdowns** *(v2.0.1)* -- Every server-rendered EVE timestamp gets a hover tooltip with full local time formatted in your browser's timezone (same mechanism Discord / Google Calendar / GitHub use; DST handled automatically). High-priority surfaces (active extractions, upcoming events, calendar, my-events) opt into an inline " · HH:MM local" pill for at-a-glance reading. `Carbon::diffForHumans()` text replaced with 1-second-tick countdowns color-graded from green (>1d) to red+bold (<1h) on the relevant pages. Browser-TZ readout in Help & Documentation lets operators sanity-check what their browser reports.
- **Reports** -- Daily/weekly/monthly reports with PDF/CSV/JSON export and scheduled Discord/Slack delivery
- **Theft Detection** -- Detect and monitor unauthorized mining with severity classification and incident tracking
- **Dashboard** -- Corporation-wide analytics with 12-month charts, leaderboards, and statistics
- **Notifications** -- 21 notification types via Discord webhooks, Slack, EVE Mail, or custom JSON endpoints, with per-webhook event toggles. Cross-plugin alerts for fuel/shield/armor/hull/destroyed events when Manager Core + Structure Manager are installed. *(Planner Era)* Two new standalone moon-lifecycle notifications: **Extraction Started** (a refinery lit its drill — read from the in-game `MoonminingExtractionStarted` director notification) and **Next Extraction Planned** (fires *after* a chunk is ready, announcing the refinery's next planned pull from the planner so a director re-fires on schedule). **Manager Core fast-poll** — when Manager Core is installed, Extraction Started is detected in ~2 min via MC's ESI fast-poll instead of the ~30 min moon-extraction endpoint cache; the two paths are mutually exclusive (no duplicates), with an operator `auto` / `seat_native` toggle. *(v2.0.1)* Inline **Discord role picker** on every per-type role-id input (one-click pick from your installed Discord role source — SeAT Broadcast / SeAT Connector / legacy warlof tables). **Notification Routing Map** read-only Settings tab shows what fires where and who gets pinged at a glance, with "enabled but firing nowhere" warnings.
- **EventBus Publishing** *(v2.0.1)* -- Three new `mining.extraction_*` events published via Manager Core's Topics facade (`ready` / `unstable` / `expired`) once per extraction per lifecycle stage. Rich payload with deeplink URL. New cron `mining-manager:scan-extraction-events` at `*/5 * * * *`. Standalone-safe via `class_exists` guard on `\ManagerCore\Topics`. Consumable by SeAT Broadcast's FC Opportunities board.
- **Diagnostics** -- 16-tab diagnostic suite. *(v2.0.1)* Default tab is now **Health Checks** (renamed from "System Status", reordered to match the suite-wide standard from `feedback_plugin_diagnostic_standard.md`). Tier 1 tabs each open with a "What this tab does / When to use / Heads up" intro box. Tabs: Health Checks, Master Test (one-click read-only smoke chain, ~26 checks, sub-30s), System Validation, Settings Health, Data Integrity, Tax Trace, Notification Testing, plus plugin-specific traces and a DEV-only Test Data tab.

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

**Extraction Started — fast-poll vs SeAT-native:** The `extraction_started` notification has two possible trigger paths. With **Manager Core** installed, MM registers a handler with MC's ESI fast-poll registry and reacts to the in-game `MoonminingExtractionStarted` director notification in ~2 minutes. Without Manager Core, the SeAT-native path fires it from the stored `extraction_start_time` once the corp moon-extraction endpoint refreshes (~30 min cache). The two are **mutually exclusive** — when fast-poll is active, the SeAT-native pass in `check-extraction-arrivals` is suppressed, so a single notification fires per extraction. Operator toggle at Settings → Notifications → *Extraction Started — Detection Speed* (`auto` / `seat_native`, default `auto`). Same model Structure Manager uses for its structure alerts.

## Permissions

4-tier permission model -- higher tiers inherit all lower tier access. Plus one standalone capability (`moon_manager`) that grants the planner without requiring full director.

| Permission | Tier | Description |
|---|---|---|
| `mining-manager.view` | Base | Help page access |
| `mining-manager.member` | Member | View own mining data, join events, view moon schedules, report jackpots, reprocessing calculator |
| `mining-manager.director` | Director | View all corp data, manage operations, analytics, reports, theft detection |
| `mining-manager.admin` | Admin | Full control: settings, tax management, delete actions, API, diagnostics |
| `mining-manager.moon_manager` | Capability *(standalone)* | Access the Moon Extraction Planner — assign / move / auto-fill planned moon pulls. Directors and admins also have this access. |

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

18 notification types across 5 categories (plus 3 cross-plugin / Metenox types — extraction-at-risk, extraction-lost, metenox-cargo-full — documented elsewhere). Each webhook can independently toggle which events it receives.

Supported channels: Discord webhooks, Slack, and ESI in-game mail (for tax reminders/invoices/overdue notices).

| Category | Events | Description |
|---|---|---|
| Tax | generated, announcement, reminder, invoice, overdue | Payment lifecycle notifications |
| Moon | arrival, jackpot, chunk-unstable, extraction-started, next-planned | Chunk ready, jackpot detection, capital safety warnings (~2h before chunk goes unstable), drill lit (Extraction Started), and the planner's next-pull nudge (Next Extraction Planned) |
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
| **Fire ALL (Chain)** | Full pipeline, every fire-able type sequentially | Post-deploy smoke test — every subscribed webhook receives every type in well under a minute |

Settings → Webhooks → **Test** button sends a minimal "✅ Webhook Active" ping for wiring verification.

## Support

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/Mining-Manager/issues)
- **Wiki**: [Documentation & Screenshots](https://github.com/MattFalahe/Mining-Manager/wiki)
- **In-App Help**: Full documentation available at Settings > Help within the plugin

## License

GNU General Public License v2.0 -- see [LICENSE](LICENSE) for details.

---

*EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights are reserved worldwide.*
