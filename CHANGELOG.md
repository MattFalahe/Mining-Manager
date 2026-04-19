# Changelog

All notable changes to Mining Manager will be documented in this file.

## [1.0.1] - Notification & Event Fixes

### Fixed
- **Ghost webhook / duplicate report notifications** -- Monthly report cron ran daily instead of monthly, generating identical reports every day and dispatching to all webhooks. Changed to day 9 of month (7 days after finalize-month for collection % to mature). Added dedup guard with `--force` override.
- **Moon arrival notifications silently never sent** -- Cron command had a duplicate status-transition method that bypassed the notification dispatcher. Extractions transitioned to "ready" but no Discord/Slack notification ever fired. Now delegates to the service's method which includes notification dispatch.
- **Events stuck in PLANNED status** -- No automatic status transitions existed. Events never moved from planned to active to completed unless manually clicked. Added auto-transition logic to the cron with event_started and event_completed notification dispatch.
- **Event location scope broken for constellation/region** -- Constellation and region-scoped events silently failed because the code compared a constellation/region ID directly against solar system IDs. Added spatial hierarchy resolution via mapDenormalize with 24h caching.
- **Role ping ignoring per-type settings** -- Both NotificationService and WebhookService had a legacy fallback that pinged the webhook's discord_role_id even when the per-type "Ping Role" toggle was OFF. Per-type settings are now authoritative in both dispatchers.
- **Manual report dispatch to wrong channel** -- Hidden webhook picker in report generation form silently submitted the first webhook ID. Removed the picker entirely; dispatch is now subscription-driven via webhook configuration.
- **Tax notification scoping** -- Tax notifications via NotificationService were dispatched to all enabled webhooks regardless of corporation. Now scoped to the Moon Owner / Tax Program Corporation, consistent with moon and theft notification scoping.
- **Wallet division showing hangar name** -- Payment instructions displayed hangar division name (e.g. "Handouts") instead of wallet division name (e.g. "Taxes and Bills") because the query didn't filter by `type='wallet'`.
- **Silent event notification failure** -- `sendBroadcast()` checked `general.corporation_id` which was often empty at global scope. Now uses `getTaxProgramCorporationId()` (reads `general.moon_owner_corporation_id`).

### Added
- **Auto tax code generation** -- Tax codes are now automatically generated when invoices are created. The manual `generate-tax-codes` command remains as a fallback.
- **`getTaxProgramCorporationId()` accessor** -- Single canonical method on SettingsManagerService for resolving the tax program / moon owner corporation. All legacy `general.corporation_id` fallback patterns consolidated.
- **`getMoonOwnerScopedWebhooks()` helper** -- Shared webhook filtering for moon, theft, and tax notifications. Ensures webhooks from other directors' corps on the same SeAT install are excluded.
- **Event location resolution on MiningEvent model** -- `getMatchingSystemIds()`, `applyLocationFilter()`, `matchesSystem()` methods resolve constellation/region scopes to system ID lists via mapDenormalize.
- **Audit logging for direct webhook dispatch** -- Moon, theft, and report notifications now log to `mining_notification_log` (previously only tax and event notifications were logged).
- **Report dedup guard** -- `GenerateReportsCommand` skips generation if a report for the same period+type already exists. Use `--force` to override.
- **`--force` flag on generate-reports** -- Allows intentional regeneration of existing reports.

### Changed
- **Event cron frequency** -- `mining-manager:update-events` changed from every 2 hours to every minute for timely status transitions and notifications.
- **Report cron frequency** -- `mining-manager:generate-reports` changed from daily to day 9 of month at 4:05 AM.
- **`generate-tax-codes` default scope** -- Without `--month`, now scans ALL unpaid taxes missing active codes instead of only the previous month.
- **Report "Send to Discord" UI** -- Removed webhook picker from both generate and show pages. Dispatch is now controlled entirely by webhook subscriptions (notify_report_generated flag). Shows informational list of subscribed webhooks.
- **Event notifications scope** -- Event notifications (created/started/completed) remain globally dispatched. All other notification types (moon/theft/tax) are scoped to the Moon Owner Corporation.

## [1.0.0] - Initial Release

### Initial Release

**Core Systems**
- Mining ledger processing with automated price lookups from multiple market data sources (SeAT, Fuzzwork, Janice, Manager Core)
- Daily summaries as single source of truth for all tax calculations
- Per-ore category tax rates (moon R4-R64, regular ore, ice, gas, abyssal, triglavian)
- Multi-corporation support with per-corp tax rates and tax selectors
- Guest mining detection with separate global tax rates (tied to Moon Owner Corporation)
- Event tax modifiers for mining operations (percentage-based discounts/surcharges)
- Tax code generation with wallet payment verification and auto-reconciliation
- Orphan moon ore reconciliation against Moon Owner Corp observer data

**Moon Mining**
- Moon extraction tracking with ore composition and estimated values
- Jackpot detection -- automatic (daily scan of mining data for +100% variant ores)
- Manual jackpot reporting -- members can report jackpots from arrived extractions
- Jackpot verification -- auto-detection verifies manual reports, marks unverified if no data found
- Moon chunk arrival and jackpot Discord/Slack webhook notifications
- Extraction calendar view, active extractions dashboard with auto-refresh
- Moon value calculator/simulator
- Ready-to-fracture and unstable extraction alerts

**Tax System**
- Corporation tax model: Moon Owner Corp observers for moon tax, per-corp rates for configured corps
- Guest miner tax rates in General Settings (global, tied to Moon Owner Corporation)
- 0% guest rate means actual zero tax (not fallback to corp rate)
- Tax calculation from daily summaries (Calculate button) or full regeneration (Recalculate button)
- Payment code generation with configurable prefix
- Tax code mixed-length support (6, 8, 10, or 12 characters) with automatic detection of all active lengths during wallet matching
- Configurable minimum tax amount with exempt/enforce behavior
- Wallet payment verification with tolerance matching and dismissed transaction tracking
- Manual payment entry with two modes: record payment (existing invoices with partial payment support) and manual entry (ad-hoc mid-period settlements for characters leaving corp)
- Tax exemption threshold for small miners
- Tax reminders, invoices, and overdue notifications via Discord/Slack
- Tax announcement notification for all members when new invoices are generated (no ISK amounts, links to My Taxes and How to Pay)

**Mining Events**
- Create mining events with participant tracking and leaderboards
- Tax modifier support (percentage discount/surcharge during events)
- Event lifecycle notifications (created, started, completed)

**Reports & Analytics**
- Daily, weekly, monthly reports with PDF, CSV, and JSON export
- Scheduled report generation with Discord/Slack webhook delivery
- Corporation dashboard with 12-month charts and statistics
- Mining leaderboards and per-character analytics
- Analytics data tables with corporation names, region names via SDE lookup
- Weekly activity heatmap with non-SeAT character name resolution via ESI/zKill
- Comparative analysis: period vs period, miner vs miner, system vs system, ore vs ore

**Notifications & Webhooks**
- Multiple webhook support -- each with independent event toggles
- Discord role pinging with personal vs broadcast notification modes
- Ping content options: show tax amount or general notice with link
- Individual/General scope labels on all notification types in settings UI
- 15 notification types: tax (generated, announcement, reminder, invoice, overdue), moon (arrival, jackpot), events (created, started, completed), theft (detected, critical, active, resolved), reports
- Supported channels: Discord webhooks and Slack (EVE Mail channel is not currently available)
- Unified notification testing panel in diagnostics with all 15 types

**Theft Detection**
- Detect unauthorized mining at corporation moons
- Severity classification (medium, high, critical)
- Active theft monitoring with activity tracking
- Incident management with resolution tracking

**Diagnostics**
- 15-tab diagnostic suite:
  - Test Data -- generate and manage test data
  - Price Provider -- test and compare price sources
  - Cache Health -- price cache status and staleness detection
  - System Validation -- verify configuration and dependencies
  - Settings Health -- audit settings for inconsistencies
  - Tax Trace -- daily summary inspection and live recalculation comparison
  - Data Integrity -- check for orphaned or inconsistent records
  - Valuation Test -- compare ore valuations across providers
  - System Status -- scheduler health and queue monitoring
  - Notification Testing -- unified panel with all 15 notification types and production-parity formatting
  - Moon Extractions -- debug extraction data, notifications, and fractured_at timestamps
  - Tax Pipeline -- trace the full tax calculation pipeline from ledger to invoice
  - Theft Detection -- inspect theft scan results and active monitoring
  - Event Lifecycle -- debug mining event state transitions and participant data
  - Analytics & Reports -- verify report generation and analytics data integrity

**Settings**
- Moon Owner Corporation configuration for moon tax scoping
- Per-corporation tax rates via Switch Corporation Context
- Tax selector (all moon ore / only corp moon ore / no moon ore + regular ore, ice, gas, abyssal, triglavian)
- Configurable price provider (SeAT, Fuzzwork, Janice, Manager Core)
- Payment settings (wallet division, match tolerance, grace period)
- Display settings (currency decimals, pagination, compact mode)

**Documentation**
- Built-in Help & Documentation page with comprehensive guides
- How to Pay Taxes (member guide)
- How to Collect Taxes (director guide)
- Corporation Tax Model explanation with flow table
- Webhooks & Notifications setup guide
- CLI commands reference

**Technical**
- First-time setup wizard (`mining-manager:initialize`) with settings verification, current month data population, and optional historical backfill
- 31 artisan commands with 21 automated scheduled tasks
- Data backup and restore commands (`mining-manager:backup-data`, `mining-manager:restore-data`)
- SeAT 5.x permission integration (4-tier: view, member, director, admin)
- Reprocessing calculator with batch support for compressed ores
- Full settings cache management with per-corporation context
