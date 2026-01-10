# Changelog

All notable changes to Mining Manager will be documented in this file.

## [1.0.0] - Unreleased

### Initial Release

**Core Systems**
- Mining ledger processing with automated price lookups from multiple market data sources
- Daily summaries as single source of truth for all tax calculations
- Per-ore category tax rates (moon R4-R64, regular ore, ice, gas, abyssal)
- Multi-corporation support with per-corp tax rates and tax selectors
- Guest mining detection with separate global tax rates (tied to Moon Owner Corporation)
- Event tax modifiers for mining operations (percentage-based discounts/surcharges)
- Tax code generation with wallet payment verification and auto-reconciliation
- Orphan moon ore reconciliation against Moon Owner Corp observer data

**Moon Mining**
- Moon extraction tracking with ore composition and estimated values
- Jackpot detection — automatic (daily scan of mining data for +100% variant ores)
- Manual jackpot reporting — members can report jackpots from arrived extractions
- Jackpot verification — auto-detection verifies manual reports, marks unverified if no data found
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
- Wallet payment verification with tolerance matching and dismissed transaction tracking
- Manual payment entry with two modes: record payment (existing invoices with partial payment support) and manual entry (ad-hoc mid-period settlements for characters leaving corp)
- Tax exemption threshold for small miners
- Tax reminders, invoices, and overdue notifications via Discord/Slack/EVE Mail
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
- Multiple webhook support — each with independent event toggles
- Discord role pinging with personal vs broadcast notification modes
- Ping content options: show tax amount or general notice with link
- Individual/General scope labels on all notification types in settings UI
- 15 notification types: tax (generated, announcement, reminder, invoice, overdue), moon (arrival, jackpot), events (created, started, completed), theft (detected, critical, active, resolved), reports
- Unified notification testing panel in diagnostics with all 15 types

**Theft Detection**
- Detect unauthorized mining at corporation moons
- Severity classification (medium, high, critical)
- Active theft monitoring with activity tracking
- Incident management with resolution tracking

**Diagnostics**
- Tax Trace diagnostic with daily summary inspection and live recalculation comparison
- Price cache health diagnostics
- Character affiliation and mining data debugging
- Extraction and type ID classification tools
- Unified notification testing panel with all 15 notification types and production-parity formatting

**Settings**
- Moon Owner Corporation configuration for moon tax scoping
- Per-corporation tax rates via Switch Corporation Context
- Tax selector (all moon ore / only corp moon ore / no moon ore + regular ore, ice, gas, abyssal)
- Configurable price provider (EVE Market, Janice API)
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
- 28 artisan commands with 21 automated scheduled tasks
- SeAT 5.x permission integration (4-tier: view, member, director, admin)
- Reprocessing calculator with batch support for compressed ores
- Full settings cache management with per-corporation context
