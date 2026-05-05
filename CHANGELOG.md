# Changelog

All notable changes to Mining Manager will be documented in this file.

## [2.0.0] — 2026-05-03 — The Ecosystem Era

> **Mental model:** Mining Manager grew from a standalone tax/extraction tracker into the first ecosystem-aware plugin in Matt's SeAT plugin suite. v2.0.0 marks that transition: the plugin still works perfectly fine on its own, but when **Manager Core** is installed it gains centralised pricing via the documented PluginBridge contract, and when **Structure Manager** is also installed it subscribes to SM's structure-threat events and dispatches `extraction_at_risk` / `extraction_lost` alerts to operators in real time. None of this is required — every existing v1.0.2 install upgrades cleanly without changing a thing.

### 🎉 Headline features

- **Cross-plugin alerts (MC + SM)** — `extraction_at_risk` (fuel critical, shield/armor/hull reinforced) and `extraction_lost` (refinery destroyed) notifications via Discord/Slack/Custom/EVE Mail. Includes attacker info, system security, fuel/timer details, severity-aware embed colors, and a one-click Structure Board deeplink to SM. Toggles auto-disable when either MC or SM is missing.
- **Master Test diagnostic** — one-click read-only smoke chain on the new default Diagnostic tab. ~26 checks across schema integrity, settings consistency, cross-plugin integration, pricing path, notifications, lifecycle, tax pipeline, and security hardening. Sub-30-second runtime. Pass/warn/fail/skip table with category badges + "Show only issues" filter.
- **Auto-match wallet payments toggle** — Settings → General → Payment Settings checkbox. ON (default) = listener applies matched payments automatically; OFF = matches detected and listed on Wallet Verification but require manual confirmation. Useful for installs wanting a human-review step.
- **Manager Core pricing integration** — when MC is the configured provider, MM consumes prices via `pricing.getPrices` capability. Boot-time idempotent re-subscribe with signature-cache fast path; staleness check on served prices (8h threshold) with structured warning logs.
- **Notification surface filled in** — `formatMessageForESI` now covers all 19 notification types (was ~60% pre-v2.0.0). EVE Mail recipients now get readable subjects + bodies for theft, jackpot, structure alerts, reports, etc., not raw JSON dumps.

### 🔄 Architectural changes

- **Three audit cycles** (cycle 1 hardening, cycle 2 cross-plugin contract drift, cycle 3 full audit Tier 1+2+3) shipped before this release. ~50 numbered findings across CRITICAL/HIGH/MEDIUM/LOW, all fixed.
- **Atomic compare-and-swap pattern** standardised across 4 race-prone sites: `StructureAlertHandler` dedup latches, `MoonController::reportJackpot`, `MoonExtractionService::sendMoonArrivalNotification`, `EventManagementService::updateEventStatuses` + manual paths. Same pattern across all 3 wallet-payment dispatch sites: `applyPayment`, `ProcessWalletJournalListener::handle`, `autoVerifyFromCorporationWallet`.
- **PluginBridge contract** fully respected — every cross-plugin operation (read, subscribe, unsubscribe, getPrice, getPrices, getTrend) routes through the documented capability surface. Direct `DB::table('manager_core_*')` queries eliminated.
- **Forward-only migration discipline** — 5 new migrations (000011-000015) for tax-code uniqueness, period_start backfill, orphan settings cleanup, discord_avatar_url add+drop. Migration 000001 untouched. Released-migration immutability rule honored.

### ⚠️ Compatibility notes

- **No breaking changes for standalone installs.** The plugin still works without Manager Core or Structure Manager — the cross-plugin features simply remain disabled and the relevant webhook toggles auto-grey-out in the UI.
- **`discord_avatar_url`** removed end-to-end. The field never worked correctly (was a duplicate of Discord's webhook UI avatar setting). Operators who tried to use it never got the override they expected; no behavior change in production. Forward-only migration 000015 drops the column.
- **`slack_webhook_url`** now requires `https://`. Slack webhooks have always been HTTPS-only at `hooks.slack.com`, so any existing valid value passes the new rule.
- **`ScheduleSeeder`** reverted to canonical SeAT v5 `firstOrCreate` semantics. The override that rewrote operator cron customizations on every plugin boot is gone. Existing installs keep whatever's currently in their `schedules` table (no behavior change on first upgrade).

### 🚀 What's next

- Pings plugin to subscribe to MM's events (Phase 3 of SM v2 roadmap, applies to MM too)
- Per-corp event webhook scoping (P6, deferred from v1.0.x cycles)
- EVE Mail channel for tax invoices to miners not in SeAT (planned for v2.1.0)

---

## [Pre-2.0.0 polish history]

Below is the historical pre-release work that landed during the cycle 1+2+3 audit waves. All shipped on `dev-5.0` between 2026-04-23 and 2026-05-03; no separate v1.0.3 was tagged — the work rolled directly into v2.0.0.

A large polish pass on top of v1.0.2. Three streams of work converged:

1. **Notification system consolidated.** Before: two separate dispatcher
   classes (`NotificationService` + `WebhookService`) each carried its own
   copy of the same webhook query logic, role-mention code, and corp-scoping
   filter. Bug fixes had to be applied in both. Now: a single consolidated
   dispatcher + one shared trait.
2. **Tax lifecycle notifications fixed.** `tax_overdue` now actually fires.
   Status flips `unpaid → overdue` on the day after the due date instead of
   7 days later. `tax_invoice` now dispatches when an invoice is generated
   instead of being defined-but-never-called.
3. **Robustness polish.** `Cache::lock` on every scheduled command (was on
   10 of 21). Tri-state cast bug on `jackpot_verified` fixed. Settings cache
   race closed. Dead settings removed from the UI.

### Changed

**Jackpot multiplier applied + indicator badges across all extraction list views:**
- `MoonController` gained a private `computeDisplayValue()` helper that calls
  `MoonValueCalculationService::calculateExtractionValue` and wraps with
  `MoonExtraction::calculateValueWithJackpotBonus()` when the extraction is
  flagged as jackpot. All 7 places in the controller that previously set
  `$extraction->calculated_value` directly from the value service now route
  through the helper. Single source of truth for "the value we want to
  display to operators".
- Active Extractions (`moon/active.blade.php`), Moon Listing (`moon/index.blade.php`),
  and per-structure Extractions list (`moon/extractions.blade.php`) now show
  a small gold ⭐ badge next to the value column on jackpot rows. The
  badge displays "2x" on larger value displays (cards/headlines) and just
  ⭐ on compact tables. Hover shows "Jackpot — 2x multiplier applied".
- API endpoint `MoonController::extractionData` now includes `is_jackpot` in
  its JSON response (used by the calendar JS) and returns the jackpot-adjusted
  value in `estimated_value`. Calendar tile values now correctly reflect
  jackpot doubling, even though the calendar itself doesn't yet show a
  badge (kept the visual layout untouched).
- For non-jackpot extractions: zero visual change anywhere. Every new
  badge is gated on `$extraction->is_jackpot`.

**Extraction details page — jackpot value reflects 2x multiplier:**
- The "Estimated Value" field on the extraction details page now applies
  the jackpot multiplier when `is_jackpot=true`, so operators see the
  post-reprocessing value rather than the ESI-predicted base. Same as the
  notification embed change but on the in-app surface.
- Display shows the multiplied value prominently with a small annotation
  underneath: "Base: X ISK × 2.0 jackpot multiplier" so operators can see
  both numbers without confusion.
- Per-ore Value column in the Ore Composition table also applies the
  multiplier so the row values sum correctly to the Total Value in the
  table footer (otherwise the rows would total to half of the displayed
  Total — confusing).
- Added a prominent **"⭐ JACKPOT"** badge next to the status badge in
  the extraction information card header. Visible at-a-glance whenever
  the extraction is a confirmed jackpot, complementing the existing
  full-width golden banner.
- Added "2x JACKPOT" badge next to the Estimated Value label as a
  contextual cue for the multiplier application.
- New `MoonExtraction::display_estimated_value` accessor returns the
  jackpot-adjusted value when applicable, otherwise the raw base. Use
  this anywhere you want operator-facing value; use raw `estimated_value`
  only when you specifically need pre-jackpot base.

**Jackpot detected notification — embed redesign:**
- Replaced the "Jackpot Ores Found" field (which listed mined-so-far
  quantities like `Glistening Sylvite (x453,181), Shimmering Sperrylite
  (x126,985)`) with the same "💎 Ore Composition" field used by the
  moon_ready notification — full chunk composition with percentages and
  m³ volumes per ore type. Justification: jackpot moons have +100%
  variants for ALL their ore (binary, not partial), so showing the full
  chunk is more useful than showing partial mining quantities at
  notification time.
- Removed the redundant "Jackpot %" field that always read "100% of ores
  are +100% variants". The title (`JACKPOT MOON DETECTED!`) and
  description already establish that this is a jackpot, and the literal
  100% restated as a percentage is confusing wordplay.
- Added "💰 Estimated Value" field showing the chunk's estimated ISK value.
- Added "🔗 Extraction Details" link with `[View Extraction](url)` so
  operators can jump directly to the extraction page from the channel
  message — same pattern as moon_ready and moon_chunk_unstable.
- All three jackpot entry points (real-time auto-detect via
  `process-ledger`, daily backstop via `detect-jackpots`, manual report
  via the "Report Jackpot" button) updated to pass the new fields.
- Logic for building the ore-composition summary string moved from
  inline in `MoonExtractionService::sendMoonArrivalNotification` into a
  new `MoonExtraction::buildOreSummary()` model method. Reused by both
  notification surfaces with no duplication.
- Diagnostic Fire Live preview data also updated to the new shape.
- **Estimated Value field shows the jackpot-adjusted value.** Plugin already
  had `MoonExtraction::calculateValueWithJackpotBonus()` and
  `MoonOreHelper::calculateJackpotMultiplier()` (returns ~2.0x for a full
  jackpot — matches EVE's "+100% variants reprocess to ~2x mineral content"
  reality). But the notifications were passing raw `extraction.estimated_value`,
  which is computed at chunk arrival from ESI's pre-jackpot composition
  (base ore type IDs only — Sylvite, not Glistening Sylvite). All three
  jackpot entry points now wrap the raw value through
  `calculateValueWithJackpotBonus()` so operators see what the jackpot is
  actually worth at reprocess time, not the deceptively-low pre-detection
  base. Field labeled "💰 Estimated Value (Jackpot)" to make the multiplier
  application explicit.

### Fixed

**Audit-driven hardening pass (2026-04-28):**

The post-jackpot-feature audit turned up a CRITICAL race plus 7 lower-severity
items. All fixed in this pass.

- **CRITICAL — Race condition in cross-plugin alert dedup latch.**
  `StructureAlertHandler::handle()` previously did a non-atomic check-then-update
  on the dedup latch column. Two concurrent queue workers processing the same
  event could both read `false`, both dispatch, both flip the flag — duplicate
  Discord pings for one real event. Replaced with atomic compare-and-swap via
  `UPDATE WHERE flag=false`. Only the worker that wins the flip proceeds; the
  others bail. On dispatch failure (skipped or thrown), the claim is rolled
  back so the next event retries naturally.

- **HIGH — UI gating wasn't enforced server-side.**
  The notifications tab UI greys out the `extraction_at_risk` /
  `extraction_lost` toggles when Manager Core or Structure Manager isn't
  installed, but `SettingsController::storeWebhook` and `updateWebhook` did
  not validate cross-plugin presence — a direct API POST or stale form replay
  could persist `notify_extraction_at_risk=true` without MC/SM. Added closure
  validator that rejects enabling these toggles when either plugin is absent,
  with a clear error message naming the missing plugin(s). Also extracted
  shared validation rules to `webhookValidationRules()` helper so the two
  endpoints can't drift again — the update endpoint had been missing the
  `notify_moon_chunk_unstable`, `notify_extraction_at_risk`, and
  `notify_extraction_lost` rules entirely.

- **HIGH — Two un-paginated 10,000-row CSV exports.**
  `LedgerController::exportLedger` and `exportPersonal` previously used
  `->limit(10000)->get()` which loaded all rows into PHP memory at once. A
  100-character active corp could OOM the PHP process. Replaced with
  `->chunkByIdDesc(500, ...)` cursor-paginated streaming inside the
  `streamDownload` callback. Memory now bounded to ~500 rows + relations
  regardless of dataset size, and the artificial 10K limit is gone (operators
  can export their full mining history when needed).

- **MEDIUM — Webhook URL validation accepted `http://` (SSRF surface).**
  `'webhook_url' => 'required|url'` accepted `http://127.0.0.1:port` and similar
  internal URLs. Discord/Slack webhooks are always HTTPS so the relaxed
  validation served no purpose and exposed an SSRF vector for compromised
  admin accounts. Tightened to `['required', 'url', 'starts_with:https://']`.

- **MEDIUM — `DB::raw` with float interpolation in `TheftIncident::markAsActiveTheft`.**
  Previously `DB::raw("ore_value + {$newMiningValue}")` with `$newMiningValue`
  type-hinted `float`. Functionally safe in current code (PHP type system blocks
  injection) but locale-fragile (PHP's float-to-string conversion uses the
  locale's decimal separator — a server with German locale would emit
  `ore_value + 1234,56` and SQL syntax-error) and brittle to future
  refactoring. Replaced with `incrementEach(['ore_value' => $newMiningValue])`
  which uses bound parameters via the query builder.

- **MEDIUM — Two empty `catch (\Exception $e) {}` blocks in DashboardController.**
  Lines 380 and 397 silently swallowed exceptions on the miner-id lookup
  queries. If `mining_ledger` or `corporation_industry_mining_observer_data`
  was unreachable, the dashboard showed partial/blank data with no log entry
  — operators couldn't diagnose why the page was empty. Now logged at
  `warning` level with corp_id, month, and error message.

- **LOW — Composite index gap on three alert dedup flags.**
  Migration 000008 only indexed `alert_fuel_critical_sent` and
  `alert_destroyed_sent`. The shield/armor/hull flags were unindexed because
  Structure Manager doesn't yet publish those events. Added migration 000010
  with single-column indexes on the three remaining flags so when SM ships
  combat detection, the StructureAlertHandler's dedup compare-and-swap query
  has index coverage from day one.

- **LOW — SM `FuelCalculator::hasActiveMoonExtraction` failures logged at debug.**
  When the MM cross-plugin check fails (DB issue, schema drift, etc.), SM's
  helper caught and logged at `debug` level then returned false. Operators
  saw SM's own low-fuel webhooks fire normally but no MM `extraction_at_risk`
  alert — silently degraded integration with no visibility at default log
  levels. Bumped to `warning` with structured context (structure_id, error,
  first stack frame). NOTE: this change is in the Structure Manager repo,
  shipped separately on dev-3.0.

**Cross-plugin payload-contract drift fixes (2026-04-30):**

After SM shipped the full `structure.alert.*` family (fuel_critical,
fuel_recovered, shield_reinforced, armor_reinforced, hull_reinforced,
destroyed) wrapped through its new `AlertEventEnvelope` helper,
re-cross-referenced MM's subscriber against the published contract. Found
one real gap (fuel_recovered unhandled, leading to silently-stuck dedup
latches) plus four nice-to-have surfaces the new envelope makes available.
All five fixed.

- **HIGH — `fuel_recovered` flavor was unhandled, leaving the dedup latch
  stuck.** `StructureAlertHandler` recognised five flavors but not the
  sixth (`fuel_recovered`, fired by SM when an operator tops off a
  refinery). Effect: after a refuel → re-critical cycle, MM's
  `alert_fuel_critical_sent=true` latch remained set from the first
  critical event, silently swallowing the second `fuel_critical`
  notification. Added a `fuel_recovered` handler that resets the latch
  via `UPDATE WHERE alert_fuel_critical_sent=true` (atomic, no-op if
  already clear). No notification dispatch on this flavor — pure state
  cleanup; SM's own webhook fires the operator-facing all-clear message.

- **LOW — Added `schema_version` defensive check.**
  Every SM `structure.alert.*` payload carries `schema_version=1` today.
  If SM ever ships v2 with breaking field changes, MM should defensively
  skip those events rather than render embeds with missing fields. Added
  `const SUPPORTED_SCHEMA_VERSION = 1` and a top-of-handler check that
  logs at `warning` and bails when `payload['schema_version'] >
  SUPPORTED_SCHEMA_VERSION`. Bumping the cap is intentional code review:
  verify the new shape is compatible with our embed builders + dedup
  keys, then raise the constant.

- **NICE-TO-HAVE — Surface attacker info in tactical embeds.**
  SM's envelope includes `attacker_corporation_name` (and the legacy
  `attacker_summary` rich string when available). For
  shield/armor/hull_reinforced and destroyed flavors, MM now adds a
  "⚔️ Hostile Force" / "Destroyed By" field to both the Discord and Slack
  embed builders, preferring `attacker_summary` (e.g. "Goonswarm [GOONS]
  — Pilot Name [Corp Name]") and falling back to bare
  `attacker_corporation_name`. Skipped on fuel_critical (not combat) and
  when SM has no attacker info. Defense FCs get fleet-relevant intel
  without clicking through.

- **NICE-TO-HAVE — SM Structure Board deeplink as secondary embed link.**
  Envelope's `url` field carries an absolute deeplink to SM's Structure
  Board, scoped to the affected structure. Both `extraction_at_risk` and
  `extraction_lost` embeds now render a "🛰️ Structure Board" link
  alongside the existing "View Extraction" link. One-click pivot from a
  Discord ping to SM's full structure context (timers, fuel, history),
  even post-destruction (SM keeps board entries for archival forensics).

- **NICE-TO-HAVE — Color-code embeds by envelope `severity` field.**
  `resolveExtractionAtRiskColor()` now consults `severity` first
  ('info' | 'warning' | 'critical') before falling back to per-flavor
  colors. SM may upgrade severity in edge cases (e.g. mark a
  `shield_reinforced` as 'critical' when the timer ends in <30min, or
  `info` for `fuel_recovered` all-clear). Per-severity overrides:
  `info` → calm blue (`0x3498DB`), `critical` → hard red (`0xFF0000`),
  `warning` (or missing) → flavor default. Existing per-flavor mapping
  preserved as the fallback so older publishers still render correctly.

**Removed `discord_avatar_url` (H1 reverted, 2026-05-03):**

The H1 fix wired up `discord_avatar_url` end-to-end as a per-webhook
avatar override. On reflection, the feature was a duplicate of Discord's
own webhook UI: operators configure the avatar in two places (the
Discord channel's Edit → Integrations → edit webhook → upload, OR MM's
"Custom Avatar URL" field) and get the same per-message render. No
added value over Discord's native UI; just one more thing to think
about.

Removed end-to-end:
- `NotificationService::sendViaDiscord` + `sendViaWebhooks` Discord
  branches: dispatch reads gone (kept `discord_username` override; that
  one IS useful per-webhook because Discord's UI doesn't expose a
  per-message username override the way it does for avatar)
- `WebhookConfiguration` model: `$fillable` + property docblock entry
  removed
- `SettingsController::webhookValidationRules`: validation rule removed
- `webhooks.js`: load + submit fields removed
- `webhooks.blade.php`: form input removed
- `lang/en/settings.php`: `discord_avatar_url` + `discord_avatar_help`
  translation keys removed

Database: new forward-only migration
`2026_01_01_000015_drop_discord_avatar_url_from_webhook_configurations`
drops the column 000014 added. Migration 000014 stays in the set as
historical record per the released-migration immutability rule. Net
effect across the 014+015 pair: column never persists.

Future-proofing note: if a multi-corp shared-channel use case ever
needs per-message corp logos, the right pattern is auto-resolved from
`$data['corporation_id']` to the EVE images CDN URL — no per-webhook
field needed. That can land as a small auto-feature later if the use
case arises.

**`subscribeToManagerCore` validates bridge return value (L2, 2026-04-30):**

`PluginBridge::call` returns null when the capability isn't registered.
For `pricing.subscribeTypes`, this happens on older MC versions before
commit `8381cc1` (which plumbed `immediateRefresh` through the
capability lambda) or much older ones that didn't ship the capability
at all. Pre-fix MM ignored the return value and logged "Subscribed N
type IDs" even when nothing got persisted to MC's table — operators
saw success in logs while MC's scheduler stayed empty.

Now: check `$bridgeResult === null` and fall back to a direct
service-locator call to `PricingService::registerTypes`. Older MC
versions still expose the method directly even when the bridge
capability is missing or out of date — keeps subscriptions working
during MM-ahead-of-MC upgrade windows. If the fallback also throws,
return 0 to signal failure to callers.

**Polish batch — log/metrics + payload safety (L1+L3+L5+N3+N4, 2026-04-30):**

Five small polish items batched into one commit since each is a single-
file targeted change:

- **L1** `logNotification` recipients payload — pre-fix `json_encode`d
  the full recipient list. Broadcast notifications (entire corp member
  list) duplicated hundreds-thousands of character IDs per row, then
  every monthly tax_announcement broadcast for 12 months stored 12×N.
  Now stores `{count, sample[<=50], truncated}` — accurate count
  always, sample sufficient for forensic debugging, bounded size.

- **L3** `formatCustomPayload` for `TYPE_REPORT_GENERATED` — array_merge
  order let `raw_report_data` keys override the canonical envelope.
  A report payload with a key like `event_type` would silently
  overwrite the canonical `'event_type' => 'report_generated'`,
  confusing any subscriber that branches on it. Reversed the merge
  so envelope keys win on collision.

- **L5** Per-setting `Log::info` demoted to `Log::debug`. Bulk saves
  emit dozens of nearly-identical log lines per request — noise that
  drowns out the controller-level batch-complete info line operators
  actually want to read.

- **N3** `processCustomTemplate` JSON parse error surfacing — pre-fix
  the `?? []` swallowed every parse failure with no log line. An
  operator's broken template silently delivered empty bodies. Now
  emits a `Log::warning` with type, event_type, json_last_error_msg,
  and a 500-char preview of the unparseable payload when decode
  returns null on non-empty input.

- **N4** `recordSuccess` / `recordFailure` round-trips — pre-fix did
  UPDATE + refresh() = 2 DB round-trips per webhook fire. Now mirror
  the values onto `$this` directly = 1 round-trip. Tiny perf gain
  but matters at high webhook-fire volume.

**Legacy global Slack health metric (M7, 2026-04-30):**

Per-webhook rows in `webhook_configurations` already track
`success_count`, `failure_count`, `last_error`, etc. on the model
itself (via `recordSuccess`/`recordFailure`). The legacy GLOBAL Slack
path — single URL stored in `notifications.slack_webhook_url`,
dispatched via `NotificationService::sendViaSlack` — had no equivalent.
Operators using the legacy path had to grep logs to find out their
Slack webhook was broken; per-webhook installs got a clean health
table in the UI.

Wired up parallel persistent counters in `notifications.slack_legacy_*`
settings (no schema change — just settings rows):
- `slack_legacy_success_count` (int)
- `slack_legacy_failure_count` (int)
- `slack_legacy_last_success_at` (ISO 8601 timestamp string)
- `slack_legacy_last_failure_at` (ISO 8601 timestamp string)
- `slack_legacy_last_error` (last 1000 chars of the most recent error)

Increment via `recordLegacySlackSuccess` / `recordLegacySlackFailure`
helpers called from `sendViaSlack`. Success path also clears
`slack_legacy_last_error` so a recovered Slack endpoint doesn't keep
showing the stale error string from days ago.

Exposed via `getNotificationSettings()` so the Notification Settings
tab can render the metrics — the actual UI surface is left as a
follow-up; data is now persisted regardless.

**Drop reflection bypass for diagnostic preview formatters (M6, 2026-04-30):**

`DiagnosticController` reached `NotificationService::formatMessageForESI`,
`formatMessageForDiscord`, `formatMessageForSlack`, `buildTheftData`, and
`getDiscordRoleMention` via `ReflectionMethod::setAccessible(true)` —
~10 sites — because those methods were `protected`. Reflection bypass:

- Breaks IDE rename refactors (silent runtime failure instead of
  compile-time error)
- Obscures the actual API surface
- Survived the M4 commit `e99d1f9` reflection-drop sweep that only
  covered `sendMoonArrivalNotification`

Promoted all five methods to `public` on their respective classes
(NotificationService for the four formatters, WebhookDispatchTrait for
`getDiscordRoleMention`) and replaced every reflection invocation
in DiagnosticController with a direct method call. The methods are
pure data formatters — no side effects — so the visibility change
is safe and overdue.

**Settings cache + singleton state hygiene (M2 + M8, 2026-04-30):**

**M8 — `clearSettingsCache` no longer a no-op for the actual cache keys.**
The bulk-clear iterated GROUP names ('general', 'pricing',
'notifications', etc.) and called `Cache::forget('<prefix>global_<group>')`
— but cache keys SettingsManagerService writes use the FULL dotted path
(`<prefix>global_notifications.enabled_types`,
`<prefix>global_pricing.cache_duration`). The bulk-clear forgot
non-existent keys; actual cache entries persisted for up to
`CACHE_DURATION` (60 minutes) after a save. Stale-cache risk after
every settings save (admin disables a notification toggle, pings keep
firing for up to an hour).

Now: enumerate actual setting keys from the DB (`Setting::query()->pluck('key')`)
and per-key `Cache::forget`. Both global rows (corporation_id IS NULL)
and active-corp rows. Per-key forget is portable across all cache
drivers (file/db/redis); the tags-flush attempt at the top still tries
the Redis fast-path first.

**M2 — `setActiveCorporation` try/finally cleanup in `ProcessMiningLedgerCommand`.**
The chunk loop switched the singleton's active corp on every observer
to read corp-scoped tax settings, but never restored the original
context after the loop. Invisible on web requests (each gets a fresh
container) but real on Laravel's persistent queue worker (reuses the
same PHP process across jobs). Last observer's corp context leaked
into the next job, causing settings reads to return the wrong corp's
values.

Now: capture `getActiveCorporation()` BEFORE the loop and restore in
`finally`. Belt-and-suspenders cleanup regardless of how the method
exits (success / failure / throw).

Other call sites (LedgerSummaryService etc.) flagged by the audit are
lower-impact (web context only) and can be addressed in a future
sweep without urgency.

**Wired up auto-match wallet payments toggle to settings UI (M3, 2026-04-30):**

`ProcessWalletJournalListener::handle` had a gate at line 108:

```php
if ($this->settingsService->getSetting('tax_payment.auto_match_payments', true)) {
    // apply payment
}
```

— but no part of the codebase wrote this setting, no controller validated
it, no Blade form surfaced it. Default `true` so the gate always passed,
but the toggle itself was dead weight from a planned UI feature that
never landed.

Decision: HOOK IT UP rather than remove. The toggle is genuinely useful
for installs that want a human-review step before any tax row updates
(e.g. multi-corp setups where the director wants to verify the match
before crediting).

End-to-end wiring in one commit:

1. **Canonical key**: `payment.auto_match_payments` (matches the sibling
   `payment.match_tolerance`, `payment.grace_period_hours`,
   `payment.minimum_tax_amount` keys). Listener updated to read from
   the new canonical key.
2. **Service**: `getPaymentSettings()` and `getGeneralSettings()`
   both expose the value (former for runtime gating, latter for blade
   render).
3. **Validator**: `payment_auto_match_payments => nullable|boolean`
   in `updateGeneral`.
4. **Controller**: explicit `$request->has('payment_auto_match_payments')`
   handling so unchecked checkbox correctly persists `false`.
5. **Mapping**: extended the form-to-setting-key whitelist in
   `updateGeneralSettings` to include `payment_auto_match_payments` →
   `payment.auto_match_payments`.
6. **Blade**: new switch toggle in the General settings → Payment
   Settings card, defaults checked (matches existing default behaviour).

Backward compat: default value unchanged (true). Existing installs
continue to auto-match without operator intervention.

**`formatMessageForESI` filled in for the missing 9 notification types (M1, 2026-04-30):**

`formatMessageForESI` previously covered ~60% of the notification types
— `TAX_REMINDER/INVOICE/OVERDUE`, `EVENT_*`, `MOON_READY`, `CUSTOM` —
and fell through to a default that emitted subject "Mining Manager
Notification" with body `json_encode($data)`. An operator who enabled
EVE Mail for, say, `theft_detected` got an in-game mail with a raw JSON
dump and a generic subject — exactly the types where in-game mail is
most useful (you can't always check Discord on phone but EVE mail pings
you in-client).

Added explicit cases for the missing 9 types:

- `TYPE_JACKPOT_DETECTED` — moon name + system + detected-by + jackpot-
  multiplied value + ore composition
- `TYPE_MOON_CHUNK_UNSTABLE` — capital-pilots safety warning with timing
- `TYPE_EXTRACTION_AT_RISK` — flavor (fuel/shield/armor/hull) + system +
  fuel-or-timer details + attacker summary when present
- `TYPE_EXTRACTION_LOST` — destroyed-at + outcome + chunk value lost +
  attacker + killmail link
- `TYPE_THEFT_DETECTED`, `TYPE_CRITICAL_THEFT`, `TYPE_ACTIVE_THEFT`,
  `TYPE_INCIDENT_RESOLVED` — share a single match arm with a
  type-aware subject and a status-aware opening sentence (detected /
  reached critical / continued / been resolved)
- `TYPE_REPORT_GENERATED` — report type + period + generator + URL
- `TYPE_TAX_GENERATED`, `TYPE_TAX_ANNOUNCEMENT` — period + amount +
  due date + wallet hint

Each subject is short and identifies the key entity (moon name,
character name, period); each body uses the same field set as the
Discord/Slack embeds for parity. The default fallback is still in
place as a defensive net (raw JSON dump) but should rarely be hit.

**Webhook form / validation completeness (M5 + M9 + M4, 2026-04-30):**

Three related correctness gaps in webhook persistence + validation:

**M5 — `is_enabled` form input ignored**: `storeWebhook` and
`updateWebhook` never read `is_enabled` from the request, so new
webhooks always came up live regardless of submitted value, and edits
made via the modal couldn't disable the webhook unless the toggle
endpoint was used separately. `storeWebhook` now defaults to true
when omitted (matches historical behaviour); `updateWebhook` only
writes when the form actually submits the field, preserving values
set via the standalone toggle.

**M9 — `slack_webhook_url` should be required when `slack_enabled=true`**:
Pre-fix an admin could save with `slack_enabled=true` and an empty
URL. Every notification dispatch then returned "Slack webhook URL not
configured" with no save-time error to point them at the missing
field. Added `required_if:slack_enabled,1` and `required_if:slack_enabled,true`
to the validator (handles both string/bool form encodings).

**M4 — webhook validation rule completeness**: Three missing rules in
`webhookValidationRules()`:

- `is_enabled` — added explicit `nullable|boolean` (was silently
  coerced via `$request->boolean()` in the controller, no validator
  pass).
- `discord_role_id` — added `regex:/^\d{17,20}$/` to require Discord
  snowflake format. Pre-fix an operator who pasted a role NAME got
  it accepted and their pings rendered as `<@&MyRole>` literal-string
  garbage that didn't ping anyone. Now we reject at save time.
- `custom_headers` — added `nullable|array` + `custom_headers.* =>
  string|max:1024` so malformed shapes don't blow up at json_encode
  during dispatch. HTTP headers are string-typed; blocks arbitrary
  array/object values that would serialize inside a header line.

**`getPricesFromManagerCoreWithMarket` migrated to PluginBridge (H4, 2026-04-30):**

The H7b PluginBridge migration left this Jita-fallback overload behind
— it still hit `DB::table('manager_core_market_prices')` directly with
hardcoded MC column names (`price_min`, `price_max`, `price_avg`, etc.).
An MC schema rename or storage refactor would silently zero out the
fallback path, breaking valuations across the plugin without surfacing
in MC's own logs or in MM's diagnostics.

Now goes through `pricing.getPrices` like the primary path. Reuses the
existing `normaliseBridgeGetPricesShape` for the single-element-collapse
quirk and `extractVariant` for the variant-selector logic — single
source of truth for the read pattern. Same `priceType==='average'`
shortcut as the pre-fix code (uses sell-side; primary path still does
proper buy+sell averaging).

Defensive layers: bridge call wrapped in try/catch returning zeros on
failure; null bridge return (older MC versions without
`pricing.getPrices` capability) also yields zeros so the caller doesn't
treat null as a price.

**Boot-time MC subscription signature cache (H3, 2026-04-30):**

`MiningManagerServiceProvider::registerCrossPluginPricingSubscription`
called `subscribeToManagerCore` on every boot (every PHP-FPM request).
Even with `$immediateRefresh=false`, that meant hundreds of row-existence
DB writes (`UPDATE...ON DUPLICATE KEY UPDATE`) into
`manager_core_type_subscriptions` every single request. 50-300ms of
extra work per page load on active corps.

Two-layer fast-path:

1. **Signature cache** (1h TTL): pre-compute
   `<market>:<typeIdCount>:<md5 of typeIds>` and compare against the
   cached value. Match → cheapest fast-path possible (no DB hits, no
   subscribe call, just a Redis GET).
2. **DB count cross-check**: when the cache misses (first request of
   the hour, or registry change), do a `SELECT COUNT(*)` on
   `manager_core_type_subscriptions` filtered by plugin + market. If
   it matches `count($typeIds)`, we're in sync — write the new
   signature to cache and skip the subscribe call. This catches the
   "operator wiped MC and reinstalled" scenario without waiting an
   hour for the TTL.
3. **Drift fallback**: count mismatch → call the full subscribe
   (existing `subscribeToManagerCore`), then cache the signature for
   the next hour.

Stale-cache risk is bounded: TTL caps it at 1 hour; the count
cross-check catches table resets immediately; the settings-save path
already does an unconditional subscribe (with `$immediateRefresh=true`)
so any registry change a future release ships propagates within one
admin save.

**Removed diagnostic `temp_role_id` lying-feature (H6, 2026-04-30):**

`DiagnosticController::testWebhook` accepted a `temp_role_id` form
field, mutated `$webhook->discord_role_id` IN-MEMORY before dispatch,
and reported back which role was "pinged" — but the in-memory mutation
never reached the actual dispatch. `sendViaWebhooks` does a fresh DB
read via `WebhookConfiguration::enabled()->forEvent(...)->get()` and
ignores the in-memory model entirely. So the feature lied: it said
"role X pinged" while the saved role actually got pinged.

Compounding bug: the "restore original role ID" block at the end was
also a no-op (mutated a stale, unsaved, about-to-be-garbage-collected
model). Pure dead code.

Audit recommended fix-via-DB-save+restore (hostile — a crash
mid-dispatch leaves wrong role saved) OR removal. Chose removal.
Operators who want to test a different role should edit the webhook
→ save → test → revert (same number of clicks, no lie). Removed:
the input read, the in-memory mutation block, the restore block, and
the `temp_role_used` / conditional `role_mention` fields from the
success response payload.

The diagnostic UI never wired this field into the form, so this was
purely a controller-internal feature. No UI changes needed.

**Webhook channel-detection silently dropped notifications for non-Discord installs (H2, 2026-04-30):**

`NotificationService::hasEnabledDiscordWebhooks()` (the gate that
decides whether `CHANNEL_DISCORD` gets added to the dispatch channel
list) had two correctness bugs:

1. **Filtered to `type = 'discord'`** — installs whose only configured
   webhooks were Slack rows or Custom rows in `webhook_configurations`
   got back `false` and the entire `sendViaWebhooks` dispatch path
   (which actually fans out to all three types based on each row's
   `type` column) was skipped. Silent drop of every per-webhook
   notification.
2. **OR'd only 7 of ~17 `notify_*` flags** — missing
   `notify_jackpot_detected`, `notify_moon_chunk_unstable`,
   `notify_extraction_at_risk`, `notify_extraction_lost`,
   `notify_theft_*`, `notify_incident_resolved`,
   `notify_tax_generated`, `notify_tax_announcement`,
   `notify_report_generated`. An operator who configured webhooks
   ONLY for theft + structure-alerts (perfectly reasonable
   security-alarm setup) got back `false` — every notification
   silently dropped.

Fix: simplified to `WebhookConfiguration::enabled()->exists()` and
renamed the method to `hasAnyEnabledWebhook` to match its actual
behaviour. The per-event filtering still happens correctly
downstream in `sendViaWebhooks` via `forEvent($eventType)->get()`,
so this upstream gate doesn't need to enumerate per-event flags —
it just needs to answer "any enabled webhooks at all?"

`getEnabledChannels()` comment updated to clarify that
`CHANNEL_DISCORD` is a misnomer-kept-for-backward-compat that
actually drives the per-webhook dispatch path for all three types.

**Discord webhook custom avatar URL — feature now actually works (H1, 2026-04-30):**

`discord_avatar_url` was a half-shipped feature: `NotificationService`
read `$webhook->discord_avatar_url` at dispatch (lines 778-779 +
1401-1402) and the lang file exposed a "Custom Avatar URL" label —
but the column didn't exist in the migration, wasn't `$fillable` on the
model, wasn't validated by the controller, and had no UI surface in the
form. Operators saw the label, never found the input, the dispatch read
always returned null.

End-to-end fix landing all 5 missing layers in one commit so the feature
works after migration runs:

1. **Migration** `2026_01_01_000014_add_discord_avatar_url_to_webhook_configurations`
   adds the nullable string column next to `discord_username`.
2. **Model** `WebhookConfiguration` — `discord_avatar_url` added to
   `$fillable` + property docblock.
3. **Validator** `webhookValidationRules()` — new rule
   `['nullable', 'url', 'starts_with:https://', 'max:255']`. Discord
   rejects http:// at the avatar endpoint anyway.
4. **JS** `webhooks.js` — load + submit fields wired.
5. **Blade** `settings/tabs/webhooks.blade.php` — new form group
   inside the Discord-specific settings panel with placeholder + help text.

Forward-only per `feedback_released_plugin_migrations.md`. Migration
000001 is unchanged.

**Wallet payment atomic-claim pattern unified across all 3 paths (C1+C2 follow-up, 2026-04-30):**

The H1 wallet-payment fix (commit `27cf560`) only patched
`WalletTransferService::applyPayment`. A follow-up audit found two
parallel paths still had divergent logic:

- **C1**: `Listeners\ProcessWalletJournalListener::handle` (the queued
  listener that fires on every `CharacterWalletJournalUpdated` SeAT
  event) had the OLD non-atomic order — locked the tax, accumulated
  amount_paid, updated the tax, THEN inserted the dedup row last.
  Two queue workers processing the same event (Laravel queue retry,
  parallel workers, deadlock-retry) could both update amount_paid
  before either reached the dedup insert. Same partial-payment
  double-credit bug class as the original H1.
- **C2**: `WalletTransferService::autoVerifyFromCorporationWallet`
  REPLACED amount_paid (didn't accumulate), set status='paid'
  unconditionally, and SKIPPED the dedup-table insert entirely. A tax
  with status='partial' (50% paid) got bulldozed to status='paid'
  with amount_paid=$lastTransactionAmount on the next auto-verify
  run — director sees "fully paid" but actual ISK collected is wrong.

Plus a related filter bug: `verifyPaymentFromJournal` excluded
already-applied transactions by reading `mining_taxes.transaction_id`,
which gets overwritten on every payment to a tax. A partial→partial
payment sequence with a fresh transaction would overwrite the older
transaction's id, and the OLDER transaction would re-suggest itself
on the next auto-verify run. Switched to the canonical dedup table
`mining_manager_processed_transactions.transaction_id` which is
append-only.

Fix: extracted a single canonical helper
`WalletTransferService::applyPaymentToTax` that all three paths now
funnel through. Atomic-claim semantics (insert dedup row FIRST,
catch QueryException, bail before tax mutation) are guaranteed
identical across:

  1. `processTransaction` → `applyPayment` (model-typed wrapper)
  2. `ProcessWalletJournalListener::handle` (queued)
  3. `autoVerifyFromCorporationWallet`

Also fixed the dedup-source filter in `verifyPaymentFromJournal`.

Backward compat: same external API (`applyPayment(MiningTax, TaxCode,
CharacterWalletJournal, float)`); the new `applyPaymentToTax` is
private. Existing call sites unchanged.

**One-click "Master Test" diagnostic tab (2026-04-30):**

Added a new top-priority tab on `/mining-manager/diagnostic` that runs a
comprehensive read-only smoke check of every major plugin area in one
click. Replaces the manual "click around per-tab and watch logs"
verification cycle with a structured pass/warn/fail/skip report.

Coverage (~26 tests, grouped by category):

- **Schema & migrations** — verifies all 13 expected MM migrations are
  applied, the 5 alert dedup columns + atomic-CAS target columns exist,
  the M1 unique constraint on `mining_tax_codes.code` holds (no
  duplicates), and the L2 backfill cleared all NULL `period_start` rows.
- **Settings consistency** — `getPricingSettings`, `getNotificationSettings`,
  `getFeatureFlags` all load with the expected shape; tax program
  corporation is configured.
- **Cross-plugin integration** — Manager Core + Structure Manager
  detection; EventBus subscription to `structure.alert.*` is registered;
  PluginBridge capability `mining-manager.structure.notify_alert` is
  registered; MC pricing subscription rows present when MC is the
  configured provider; MC price freshness against the
  `MC_PRICE_STALENESS_HOURS=8` threshold.
- **Pricing path** — `validateProviderConfig` passes for the configured
  provider; `getPrices(Tritanium)` returns a non-zero value (in-process
  roundtrip).
- **Notifications path** — webhook configurations exist and all use
  HTTPS; custom-template injection-safety verified by feeding hostile
  input through `processCustomTemplate` and asserting no extra JSON
  keys appeared (live verification of the H3 fix).
- **Lifecycle** — all expected MM cron schedules present; moon
  extractions table populated; `mining_manager_processed_transactions`
  table present.
- **Security/audit hardening** — atomic-CAS target columns present;
  `ScheduleSeeder::run` is inherited (verifies M7's revert is in place).
- **Infra** — Cache::put/get roundtrip on the configured driver.

Tests are designed to be:
- **Idempotent** — read-only verification; never mutates production data
- **Fast** — sub-second per test; full chain typically <30s
- **Self-contained** — each test catches its own throws so a single
  broken test can't crash the run

Implementation:
- New `MiningManager\Services\Diagnostic\MasterTestRunner` service
  encapsulates all test logic. Add a method + register it in
  `$testMethods` to extend.
- New `DiagnosticController::runMasterTest` returns structured JSON
- New POST route `/mining-manager/diagnostic/master-test` (admin-gated)
- New "Master Test" tab in the diagnostic blade — set as the default
  active tab so it's the first thing operators see. Summary card with
  pass/warn/fail/skip counts + duration; per-test results table with
  category badges, status badges, and collapsible detail. Filter button
  to show only warns + fails when a report is large.

It's OK that some tests duplicate logic from the per-area diagnostic
tabs — the Master Test is meant as a "click once, see everything green"
pre-flight check; the per-area tabs remain for deep-dive debugging.

**Structured metrics + log on Jita fallback fire (2026-04-30):**

`applyJitaFallback()` previously emitted bare `Log::info` strings without
structured context. Operators couldn't easily answer "how often does our
configured market (Amarr/Dodixie/Hek/Rens) fall back to Jita, and what
fraction of types does Jita actually recover?" from logs.

Two new structured surfaces:

- **Fallback dispatch event** (Log::info) — fires once per request that
  triggered any fallback. Includes provider, configured market,
  requested_count, zero_count, zero_fraction, sample_zero_type_ids
  (first 10 type IDs that primary provider returned 0 for). Operators
  with Loki / ELK / Splunk can now answer questions like "which moon
  ores are missing prices in Amarr?" by querying on
  `sample_zero_type_ids`.
- **Fallback completion summary** — adapts log level by severity:
  - `Log::warning` when the Jita request itself threw an exception
    (operator should investigate the second provider too)
  - `Log::warning` when Jita recovered <50% of missing prices (the
    primary AND fallback are both struggling — likely a data issue
    rather than a network blip)
  - `Log::info` otherwise (typical operating mode for non-Jita primary)
  Schema includes provider, configured_market, requested_count,
  zero_count, fallback_recovered_count, fallback_unrecovered_count,
  recovery_pct, fallback_error, timestamp.

Also added `PriceProviderService::getLastFallbackSummary()` public
accessor returning the same structured summary as an array (or null
when the last call didn't trigger a fallback). Lets a future
diagnostic page surface fallback health without parsing logs — read
the property after a `getPrices()` call to see what just happened.

**`validateProviderConfig` correctly handles Manager Core (L6, 2026-04-30):**

`PriceProviderService::validateProviderConfig()` iterated `config_fields`
on the provider descriptor, but Manager Core's descriptor has no
`config_fields` and `requires_config => false`. The validator returned
`true` even when MC wasn't installed, leading to a confusing two-step
failure: validator says "config OK", then `getPricesFromManagerCore`
throws "Manager Core is not installed."

Fix: added an early-return special-case for the MC provider that uses
`isManagerCoreInstalled()` (the canonical class-existence probe) as
the validation rule. Other providers continue to use the descriptor's
`config_fields` iteration unchanged.

**Drop outer DB::beginTransaction in ProcessMiningLedgerCommand (L4, 2026-04-30):**

`ProcessMiningLedgerCommand::handle` wrapped the entire chunk loop in a
single `DB::beginTransaction` ... `DB::commit` block. For active corps
that's tens of thousands of observer records — the transaction held
row-level locks on `mining_ledger` for minutes. Combined with the
`Cache::lock=900s` on this command, any concurrent writer (the
`import-character-mining` cron at :20/:50 vs this one at :15/:45, or
the dashboard's auto-refresh queries) could hit MySQL's
`innodb_lock_wait_timeout` (default 50s) and fail with confusing
deadlock errors.

Fix: dropped the outer transaction. The inner per-entry work is
naturally idempotent — `MiningLedger::updateOrCreate` is the canonical
idempotent insert/update, `$existing->update` only fires when the
observer quantity grows (cumulative semantics), and the personal-ESI
dedup is idempotent on its own. Each per-entry write commits
independently; locks held only for the duration of one row's update.

A partial failure mid-chunk leaves the DB consistent: rows that
updated have valid data, rows that didn't get picked up by the next
cron tick (every 30min). The inner per-entry try/catch already handled
single-row failures by incrementing `$errors` and continuing — no
behaviour change there. The fatal-error catch block (for crashes
escaping the chunk callback) is preserved sans the now-meaningless
`DB::rollBack`.

**Tighten `postWithRetry` rate-limit cap (L1, 2026-04-30):**

`WebhookDispatchTrait::postWithRetry` honoured the server's `Retry-After`
header on 429 responses, capped at 10 seconds. In a synchronous PHP-FPM
context (settings test buttons, immediate notification dispatches), a
single rate-limited webhook could block a worker for 10s, and batch
operations (tax invoices fanning out to N webhooks) accumulated this
cost linearly.

Tightened the cap to 5 seconds via a new `RETRY_AFTER_HARD_CAP_SECONDS`
constant. Discord/Slack `Retry-After` values are typically 0.5–3s;
anything above 5s usually means a global rate limit that's better
deferred to the next cron tick than blocking a worker. Most notification
surfaces have idempotent dedup latches, so a missed dispatch on rate
limit will retry naturally.

Documented the worst-case total blocking time in the docblock so future
readers can reason about latency budgets when tuning further.

**Drop reflection-based call to `sendMoonArrivalNotification` (M4, 2026-04-30):**

`CheckExtractionArrivalsCommand` reached `MoonExtractionService::sendMoonArrivalNotification`
(private) via `ReflectionClass::getMethod()->setAccessible(true)`. Code
smell — bypassing PHP's accessibility model to dodge writing a public
entry point. Breaks IDE rename refactors (silent runtime failure
instead of compile-time error) and obscures the actual API surface.

Promoted `sendMoonArrivalNotification` from `private` to `public`.
Updated the command to call it directly via the injected
`MoonExtractionService` dependency. Removed the `dispatchArrivalNotification`
reflection helper from the command.

**Removed orphan top-level MC settings writes (M11, 2026-04-30):**

C1 fixed the read side of the bug ("MC market/variant settings silently
ignored"). M11 cleans up the write side: `SettingsController::updatePricing()`
was writing `manager_core_market` and `manager_core_variant` to BOTH
the top-level keys AND the prefixed (`pricing.manager_core_market`)
keys. The reader (`getPricingSettings`) only looks at the prefixed
forms, so the top-level rows were orphan data — never read, just
taking up rows in `mining_manager_settings`.

Two changes:
- Controller no longer writes the top-level versions. Single canonical
  source of truth lives at the prefixed key.
- New forward-only migration `2026_01_01_000013_cleanup_orphan_manager_core_settings`
  deletes any existing orphan rows on existing installs. Safe to
  run repeatedly (DELETE is idempotent; returns 0 on clean installs).

Forward-only per `feedback_released_plugin_migrations.md`. Migration
000001 unchanged.

**`processCustomTemplate` now substitutes array/object values (M6, 2026-04-30):**

The H3 fix (JSON injection) addressed scalar/null substitutions but the
loop's `is_scalar($value) || $value === null` filter still dropped
array and object values silently. A template like
`{"data": {{raw_summary}}, "taxes": {{raw_taxes}}}` (where both
placeholders flow through `formatCustomPayload` as arrays — see
TYPE_REPORT_GENERATED shape) left the literal `{{raw_summary}}` and
`{{raw_taxes}}` tokens in the output, breaking JSON parsing on every
fire. Notification silently dropped, no log line.

Fix: added an `elseif (is_array($value) || is_object($value))` branch
that substitutes the full JSON literal via `json_encode`. Goes into
raw-context placeholders like `"taxes": {{raw_taxes}}` (no surrounding
quotes in the template — they're not needed since the encoded output
is already syntactically a complete JSON value).

Resources / closures / other non-JSON-encodable values still fall
through with no substitution (same as pre-fix for those — they
shouldn't appear in `$data` anyway).

**`getCharacterToken` now uses RefreshToken model + `->token` accessor (M5, 2026-04-30):**

`NotificationService::getCharacterToken()` queried `refresh_tokens` via
raw `DB::table()` and the caller read `$token->access_token` directly.
Per project memory `reference_seat_v5_models.md`, the canonical
SeAT v5 pattern is to load via the `Seat\Eveapi\Models\RefreshToken`
Eloquent model and read the `->token` accessor — which returns NULL
when the access token is expired AND SeAT's auto-refresh path failed
(revoked refresh token, ESI auth outage).

Pre-fix the raw query path returned the row regardless of expiry; the
Eseye client then tried to auto-refresh and surfaced ESI auth errors
mid-mail-send instead of MM cleanly logging "no valid mail token" and
bailing early. Edge case but real — a corp where someone revoked the
sender character's ESI auth would see opaque ESI errors rather than a
clear "configure a valid sender" message.

Two changes:
- `getCharacterToken` now returns the Eloquent `RefreshToken` (or null)
  and validates `$token->token` is non-empty before returning it.
  Logs `info` with character_id when a row exists but is unusable.
- Eseye initialisation now uses `$token->token` instead of
  `$token->access_token`. The accessor auto-refreshes if needed, so
  Eseye gets a guaranteed-fresh token. The other fields (`refresh_token`,
  `expires_at`, `scopes`) are still raw column reads — they're stable.

Going through the model also future-proofs against any SeAT-side
observer, audit hook, or schema change. Raw DB queries bypass that.

**Backfill `mining_taxes.period_start` for legacy rows (L2, 2026-04-30):**

Migration 000001 declared
`unique(['character_id', 'period_start'], 'mining_taxes_char_period_unique')`
but `period_start` is `nullable()`. NULL!=NULL inside unique indexes,
so two rows with the same `character_id` and NULL `period_start` coexist
silently — defeating the unique constraint for exactly the rows it
was meant to protect (legacy taxes from pre-period_start days, before
bi-weekly support landed).

New forward-only migration `2026_01_01_000012_backfill_mining_taxes_period_start`:
single UPDATE that copies the older `month` column into `period_start`
for every row where `period_start IS NULL AND month IS NOT NULL`. After
that, legacy rows are uniqueness-enforceable for the existing
constraint. New code (v1.0.0+) always populates `period_start` so this
is a one-time legacy cleanup.

Did not flip the column to `NOT NULL` — there's no good sentinel value
for the rare row that has NULL `month` too, and forcing a default
risks data loss. Instead, `down()` is a no-op (the assignment is not
destructive: copying month into period_start gives a valid value pair).

Forward-only per `feedback_released_plugin_migrations.md`. Migration
000001 is unchanged.

**Unique constraint on `mining_tax_codes.code` (M1, 2026-04-30):**

Tax-code uniqueness was enforced solely by Laravel's `unique:` validation
rule in `TaxController`, which is racy under concurrent generation
(admin clicks "Generate Codes" + cron fires + manual API POST). Two
paths firing at the same moment can both pass their independent reads
and both INSERT the same code; `WalletTransferService::processTransaction`
then has an ambiguous code-to-tax mapping and could credit the wrong
tax.

New forward-only migration `2026_01_01_000011_add_unique_to_mining_tax_codes_code`:
adds `UNIQUE` index on `mining_tax_codes.code`. The DB-level constraint
makes concurrent INSERTs deterministic — the second one fails
regardless of how the application-layer validation interleaved.

Pre-check: existing installs may already have duplicate codes from
prior runs of the racy paths. Migration detects duplicates first and
aborts with a clear message + sample codes if found, so the operator
can disambiguate (typically by setting `status='cancelled'` on the
older duplicate row) and re-run migrations.

Forward-only per `feedback_released_plugin_migrations.md`. Migration
000001 is unchanged.

**Staleness check on Manager Core prices (M9, 2026-04-30):**

When MM reads prices from MC, it had no visibility into how old those
prices were. If MC's `manager-core:update-prices` cron stopped running
(broken, paused, queue worker down), MM would happily serve weeks-old
prices in tax invoices, payouts, and ledger valuations with zero
indication anything was wrong.

Fix: after the bridge call returns, MM now checks each per-type
`updated_at` against a staleness threshold of 8 hours (2× MC's default
4-hour cron interval — anything older than that almost certainly means
the cron is broken). One log warning per call (not per type) summarises
how many of the returned prices are stale and gives sample type IDs +
the hint to check MC's cron.

Stale prices are still RETURNED — the operator may prefer a stale-but-
real value over a zero that triggers fallback-to-jita storms across
hundreds of types. The warning is observability only; it surfaces in
the log so an operator can fix MC's cron and refresh prices manually.

The staleness threshold is exposed as `MC_PRICE_STALENESS_HOURS = 8`
class constant — easy to tune later if MC's default refresh cadence
changes.

**ScheduleSeeder reverted to firstOrCreate semantics (M7, 2026-04-30):**

`ScheduleSeeder::run()` overrode `AbstractScheduleSeeder::run()` and
used `updateOrInsert` instead of the parent's `firstOrCreate` semantics.
Every plugin boot rewrote every cron expression in the `schedules` table
to whatever was hardcoded in `getSchedules()` — silently reverting any
operator customisations (e.g. shifted process-ledger times to avoid
clashing with backup windows, paused commands by setting expression='',
timezone offsets).

This breaks SeAT v5 conventions. Per project memory
`reference_seat_v5_scheduling.md`: *"AbstractScheduleSeeder is
firstOrCreate (no reconciliation); use getDeprecatedSchedules for two-step
swaps; never write a migration for schedule changes"*. Every other SeAT
plugin uses the canonical firstOrCreate behaviour; the override was the
outlier.

Fix: dropped the `run()` override entirely. The parent's firstOrCreate
behaviour now runs unmodified. Existing installs keep whatever's in their
schedules table (which is already the up-to-date values, having been
written there by the override on previous boots — verified that no tax
pipeline cron has changed since v1.0.0). New installs get the values
from `getSchedules()`. Future cron changes need to either propagate via
a targeted forward-only migration or be applied via the SeAT admin UI.

`getDeprecatedSchedules()` retained — that's the canonical pattern for
removing renamed/dropped commands and is honoured by the parent's run().

Backward compat: zero behaviour change for any operator on the current
release. Their schedules table already has the correct expressions.

**MC pricing operations now go through PluginBridge contract (H7b, 2026-04-30):**

Pre-fix `PriceProviderService` reached directly into Manager Core's
internals for all three pricing operations:
- **Read**: `getPricesFromManagerCore` did `DB::table('manager_core_market_prices')`
  joins. Bypassed MC's `Cache::remember` layer and tied MM to MC's table
  schema.
- **Subscribe**: `subscribeToManagerCore` did
  `app('ManagerCore\Services\PricingService')->registerTypes(...)`
  (service-locator on the concrete class).
- **Unsubscribe**: `unsubscribeFromManagerCore` did a raw
  `DB::table('manager_core_type_subscriptions')->delete()`.

All three now go through the documented PluginBridge contract:
- `pricing.getPrices` for reads — MC controls the response shape via
  `formatPriceStats` and can change its underlying schema without
  breaking MM. Two layers of defensive shape-handling in MM:
  (1) the `getPrice` single-element collapse quirk is normalised
  via `normaliseBridgeGetPricesShape`, (2) per-type the buy/sell vs
  inner-stats shape is detected before extracting the variant.
- `pricing.subscribeTypes` for subscribe — extended in MC commit
  `8381cc1` to plumb the 5th `$immediateRefresh` arg through the
  capability lambda (was previously dropped). MM's boot path passes
  `false`; settings-save path passes `true`.
- `pricing.unsubscribeTypes` for unsubscribe — symmetric capability
  added in MC commit `dd50b94`. MM falls back to raw DB delete when
  the capability isn't registered (older MC version), so an upgrade
  path that bumps MM ahead of MC still works.

Bridge calls are wrapped in try/catch with sensible fallbacks: read
failures return zeros (lets `applyJitaFallback` kick in), subscribe
failures throw (caller already handles). The plugin continues to
function via the SeAT/Janice/Fuzzwork providers when MC is broken or
absent.

**Boot-time idempotent MC pricing re-subscribe (H5, 2026-04-30):**

Pre-fix the only path that registered MM's type IDs with Manager Core's
pricing service was `SettingsController::updatePricing()` — i.e. when the
admin saved the pricing tab with provider=manager-core. Two consequences:

- **Installing MC after MM** (a common ops sequence — admin sets MM up
  first, decides later to add MC for centralised pricing) left MC's
  scheduler with zero MM type IDs to fetch. MC's `update-prices` cron
  had nothing to do, MM's reads from `manager_core_market_prices` came
  back empty for every type, prices cascaded to zero in tax invoices,
  payouts, and ledger valuations.
- **Restoring MC's DB** from a backup older than the last MM
  settings-save silently dropped subscription rows. Same failure mode.

Fix: added `MiningManagerServiceProvider::registerCrossPluginPricingSubscription()`
called from `boot()`. Every request boot (when MC is installed AND the
chosen provider is `manager-core`) calls `subscribeToManagerCore` with
`$immediateRefresh=false`. MC's persistence layer is `updateOrCreate`
keyed on (plugin_name, type_id, market), so the call is safe to run on
every boot — no duplicate rows, just one DB write per type per boot.

Also added a `bool $immediateRefresh = true` parameter to
`PriceProviderService::subscribeToManagerCore()`. Default preserves the
existing settings-save path semantics (admin expects prices to be
populated immediately after clicking Save). The boot path passes false
so we don't block PHP-FPM workers on N synchronous ESI calls — fresh
prices are picked up by MC's 4-hourly scheduled cron.

Failure mode is graceful: any exception is caught and logged at warning.
The plugin continues to function — worst case falls back to the
fallback-to-jita provider chain.

**Event status transition race (cron vs manual button) (L3, 2026-04-30):**

Two paths transition mining events through `planned → active → completed`:
the `update-events` cron (every 2 hours, via
`EventManagementService::updateEventStatuses`) and the manual buttons
on the event detail page (`EventController::start` / `complete`). Both
did check-then-update sequences. A director clicking "Start" the same
minute the cron fires for an event whose `start_time` had just passed
both passed their respective queries, both wrote `status='active'`,
both dispatched `event_started` to Discord — duplicate ping.

Fix applied to all four sites:
- `EventManagementService::updateEventStatuses` planned→active loop:
  per-event UPDATE WHERE status='planned'. Continue to next event when
  affected=0 (already started by another path).
- Same for the active→completed loop with WHERE status='active'.
- `EventController::start`: UPDATE WHERE status='planned'. On
  affected=0, refresh the event and return either the friendly "already
  started" info message or a "cannot start" error depending on the
  current status.
- `EventController::complete`: UPDATE WHERE status='active' with
  symmetric error handling.

Notification dispatch only fires when the UPDATE returned affected=1, so
exactly one path dispatches per real transition. Same shape as
`StructureAlertHandler`, M2 (jackpot), M3 (arrival).

**Moon arrival notification race across two cron paths (M3, 2026-04-30):**

`MoonExtractionService::sendMoonArrivalNotification()` had a
check-then-update on `notification_sent`: build payload → `if
($extraction->notification_sent) return` → dispatch → `update flag=true`.
Two cron commands call this method on the same extractions:
`UpdateMoonExtractionsCommand` (every 2h, via `updateExtractionStatuses`)
and `CheckExtractionArrivalsCommand` (every minute). Each command has
its own `Cache::lock` so it can't race itself, but the two commands
have separate locks and can interleave on the same extraction within a
60-second overlap window. Both workers passed the false check, both
dispatched, both flipped the flag. Duplicate Discord pings for one
arrival.

Compounding bug: `CheckExtractionArrivalsCommand::handle` wrapped the
dispatch in its own try and unconditionally set `notification_sent=true`
in the success path, even though the service's intent was "don't set
the flag on dispatch failure so a later retry can attempt again". The
command's redundant set overrode that intent, silently swallowing
notifications on transient errors.

Fix: replaced the in-method check-then-update with an atomic
compare-and-swap UPDATE (`WHERE notification_sent=false`) at the top of
`sendMoonArrivalNotification`. Only the worker whose UPDATE returns
affected=1 proceeds to dispatch. On dispatch failure the claim is
rolled back so a later cron tick can retry naturally — same shape as
`StructureAlertHandler`'s claim-then-rollback. Removed the redundant
flag-set from `CheckExtractionArrivalsCommand` since the service now
owns the latch lifecycle.

**Manual jackpot report race — duplicate Discord pings on concurrent submission (M2, 2026-04-30):**

`MoonController::reportJackpot()` did a check-then-update sequence on
`is_jackpot`: load extraction → if `is_jackpot==true` early-return with
"already marked" message → else flip the flag and dispatch
`sendJackpotDetected()`. Two members clicking "Report Jackpot" within the
same request window both passed the false check, both saved, both fired
the notification. The DB end-state was consistent (`is_jackpot=true`)
but two duplicate Discord pings hit the channel for one real event.

Fix: replaced check-then-save with an atomic compare-and-swap UPDATE
(`WHERE is_jackpot=false`). The flag plus its metadata columns
(`jackpot_detected_at`, `jackpot_reported_by`, `jackpot_verified=null`)
all flip in one statement. Only the worker whose UPDATE returns
affected-count=1 proceeds to the notification dispatch; the loser
returns the same "already marked" friendly message. Same shape as the
`StructureAlertHandler` dedup latch from the 2026-04-28 audit pass.

The pre-check `if (is_jackpot)` short-circuit is kept ahead of the
atomic UPDATE so the common case (revisiting an already-jackpot moon)
shows the friendlier message without doing a write.

**Slack webhook URL HTTPS-only symmetry (H4, 2026-04-30):**

The audit-driven hardening pass added `starts_with:https://` to the
per-webhook URL validator in `webhookValidationRules()`, but the legacy
global `slack_webhook_url` field on the notifications tab was still
validated as `'nullable|url'`. An admin (or compromised admin token)
could register `http://127.0.0.1:8025/whatever` as the global Slack URL
and the dispatcher would POST to it via `Http::post()`, re-opening the
same SSRF vector the per-webhook fix had closed.

Fix: bumped `slack_webhook_url` to
`['nullable', 'url', 'starts_with:https://']`. Symmetric with
`webhookValidationRules()`. No data migration needed — Slack webhooks
have always been served at `https://hooks.slack.com`, so any admin who
saved the form previously already had a compliant value.

**JSON injection in custom-webhook template substitution (H3, 2026-04-30):**

`NotificationService::processCustomTemplate()` substituted variable values
into custom-webhook JSON templates via raw cast-to-string with no JSON
escaping. Notification fields like `moon_name`, `structure_name`,
`character_name`, `attacker_corporation_name`, `event_name` flowed through
unfiltered.

Two failure modes:
1. **Silent drop on special characters.** A character or structure name
   containing `"`, `\`, or a newline broke the surrounding JSON. After
   substitution, `json_decode` returned null and the function returned
   `[]` — the notification was silently dropped with no log line. Worst
   case: an EVE corp with a quote in its name (legal in CCP's name
   rules) caused all custom-webhook notifications mentioning them to
   silently disappear.
2. **JSON injection.** An attacker-controlled string field could craft a
   payload that injected extra JSON keys. E.g. an attacker corp named
   `Bob", "admin": true, "x": "` substituted into a template like
   `{"text": "Hostile: {{attacker_corporation_name}}"}` produced
   `{"text": "Hostile: Bob", "admin": true, "x": ""}` — the rendered
   payload now carries an `admin: true` key the operator never authored.
   Whether this is exploitable depends on the third-party endpoint
   receiving the webhook (some bots and CI systems honour arbitrary
   keys), but the principle stands: untrusted content should never be
   able to influence the document structure.

Fix: every substitution now goes through `json_encode` with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. For string values, the
surrounding quotes are stripped (one off each end via `substr(..., 1, -1)`)
so the substitution remains a drop-in replacement inside a quoted
template context like `"name": "{{var}}"`. For numbers/booleans/null,
the bare JSON literal is substituted (`42`, `true`, `null`) which is
correct in both raw contexts (`"count": {{count}}`) and quoted contexts
(Discord/Slack accept `"true"` and `"42"` interchangeably).

Side-effect improvement: null-valued placeholders no longer produce
malformed JSON. Pre-fix `{"count": {{count}}}` with `count=null` yielded
`{"count": }` (invalid, whole notification dropped). Now yields
`{"count": null}` (valid).

Backward compat: customers who templated boolean values relying on
`true→"1"` semantics will now see `true→true` (JSON boolean instead of
string-coerced int). More semantically correct and what every JSON
consumer expects, but worth flagging for anyone with very specific
downstream parsing.

**Ledger details IDOR — any member could view any character's mining (H2, 2026-04-30):**

`LedgerController::details($id)` is gated by the `mining-manager.member`
route middleware, which only verifies the user has the member role — not
that the ledger row at `$id` belongs to a character they're allowed to
see. `findOrFail($id)` returned the row for any valid id, regardless of
whose character mined it. A member could iterate ids in the `/details/{id}`
endpoint and read full per-row mining detail (system, ore type, quantity,
ISK value, related tax record) for any character on the SeAT install.

The other character-scoped endpoints in the same controller —
`showCharacterDetails`, `getCharacterDailySummary`, `getDetailedEntries`,
`getCharacterSystemDetails` — all call `canViewCharacter($characterId)`
before returning data; `details()` was the lone outlier.

Fix: added `canViewCharacter($entry->character_id)` check after
`findOrFail`, returning HTTP 403 when it fails. Directors and admins still
see every row (they can already view all characters); members are limited
to ledger rows for their own SeAT-linked characters.

**Wallet partial-payment double-credit (H1, 2026-04-30):**

`WalletTransferService::verifyPayments()` (the engine behind the
`mining-manager:verify-payments` cron, every 6 hours) was missing the
processed-transaction filter that `ProcessWalletJournalListener` already
had. Combined with `applyPayment()` not writing to
`mining_manager_processed_transactions` after applying a payment, partial
payments were silently re-credited on every cron run.

Concrete failure path:
1. Miner pays half of a 100M ISK tax via in-game donation with the tax code
   in the description (50M ISK)
2. First `verifyPayments` cron fires: `applyPayment` updates `amount_paid`
   to 50M, sets `status='partial'`. The associated tax_code stays
   `status='active'` (only flips to 'used' on full payment)
3. Six hours later, second cron fires. The same transaction is queried
   again (no dedup filter). The same active tax code matches. `applyPayment`
   re-credits the same 50M onto `amount_paid` → now 100M, `status='paid'`,
   tax_code marked 'used'. The miner now appears to have paid the full
   amount despite only paying half.

Fix has two layers:
- **Filter (perf optimization):** `verifyPayments` now mirrors the
  listener's filter, excluding transactions already in
  `mining_manager_processed_transactions` from the `CharacterWalletJournal`
  query.
- **Atomic claim (correctness guarantee):** `applyPayment` now inserts
  into `mining_manager_processed_transactions` FIRST inside its
  `DB::transaction`, before touching the tax row. The unique constraint on
  `transaction_id` makes this the canonical "compare-and-swap on a unique
  row" pattern — if two workers race, only one wins the insert; the other
  catches `QueryException`, bails silently, and never modifies the tax.

The atomic claim alone is sufficient even without the filter (defense in
depth). The filter is kept because it's free at query time and avoids
unnecessary work.

**Backward compat:** No schema changes. The dedup table existed since the
initial release; it was just under-used by this specific path. Existing
processed_transactions rows from the listener path remain valid markers.

**Manager Core market/variant pricing settings silently ignored (C1, 2026-04-30):**

The pricing tab lets users pick a Manager Core market (Jita / Amarr /
Dodixie / Hek / Rens) and a price-statistic variant (min / max / avg /
median / percentile) when MC is the chosen price provider.
`SettingsController::updatePricing()` correctly persisted both selections
to the settings table (under the `pricing.manager_core_market` and
`pricing.manager_core_variant` keys), but
`SettingsManagerService::getPricingSettings()` — the canonical reader
every consumer calls — never included those keys in its returned array.
Effect: every consumer
(`PriceProviderService::getPricesFromManagerCore`, `CachePriceDataCommand`,
`DiagnosticController` MC diagnostic block) read
`$pricingSettings['manager_core_market']`, got `null`, and silently fell
back to `'jita'` / `'min'`. A user who picked Amarr/sell/median in the UI
got Jita/min in practice, with no log line to indicate the drift. The
pricing-tab form's `<option … selected>` logic also reads back through
`getPricingSettings()`, so the dropdown always rendered the default —
users couldn't tell their save didn't take.

Fix: added the two missing keys to `getPricingSettings()`, reading from
the same `pricing.manager_core_market` / `pricing.manager_core_variant`
prefix the writer uses. UI selection now flows end-to-end. No data
migration needed (the values were always being saved correctly — they just
weren't being read).

**Jackpot auto-detection silently skipped after first ingestion run:**
- `ProcessMiningLedgerCommand`'s jackpot detection was gated on
  `$processed > 0 && $jackpotCheckEntries->isNotEmpty()`. The
  `$processed` counter only ticks for **brand new** mining_ledger rows.
  After the first run that imports a chunk's mining, every subsequent
  run sees the same cumulative observer quantities, takes the "skipped"
  path on every entry, and ends with `$processed = 0` — so the jackpot
  check never ran, even though jackpot ore type IDs (Glistening Sylvite,
  Glistening Coesite, etc.) were sitting right there in the iterator.
- Symptom: real jackpot mining shows up in the mining ledger, the
  Mining by Group chart correctly displays it as moon ore, but the
  `jackpot_detected` notification never fires. Operators have to spot
  it manually and click Report Jackpot.
- Fixed by removing the `$processed > 0` gate. The check function is
  fully idempotent (only marks not-yet-flagged extractions, only
  verifies pending manual reports — re-runs on flagged extractions are
  no-ops with no DB writes and no notifications). Cost of running each
  cron tick is negligible — one query per unique observer when jackpot
  type IDs are present.

**Abyssal + Triglavian ores hidden from dashboards + analytics charts:**
- The `mining_ledger_daily_summaries` and `mining_ledger_monthly_summaries`
  tables didn't have dedicated columns for abyssal or triglavian value.
  `LedgerSummaryService`'s SUM-by-flag SQL aggregated only moon/ice/gas
  into their own columns and rolled EVERYTHING ELSE (including abyssal +
  triglavian) into `regular_ore_value`. Result: dashboards' "Mining by
  Group" doughnut charts never surfaced Abyssal or Triglavian as separate
  slices — they were silently inflated into Regular Ore.
- New migration `2026_01_01_000009_add_abyssal_triglavian_to_summaries.php`
  adds `abyssal_ore_value` and `triglavian_ore_value` columns to both
  summary tables (decimal 20,2, default 0).
- `LedgerSummaryService` updated across all 4 SQL paths (live monthly,
  stored daily generation, calculateLiveDailySummaries, multi-character
  rollup) to populate the new columns AND exclude abyssal/triglavian from
  `regular_ore_value` so each category is counted exactly once.
- `DashboardController::getMiningVolumeByGroup()` now SUMs all 6 columns
  and emits 6-slice chart data. The frontend `groupColors` map in
  `dashboard/member.blade.php` and `dashboard/combined-director.blade.php`
  already had Abyssal/Triglavian colors configured — they just never
  received any data until now.
- `DashboardController::getOreGroupFromCategory()` extended to handle
  the moon rarity prefixes (`moon_r4`..`moon_r64`) and explicit
  abyssal/triglavian cases instead of falling through to a generic
  `getOreGroup` lookup.
- Ore-type badges added to `ledger/index.blade.php` and
  `ledger/my-mining.blade.php` — both views previously only showed badges
  for moon ore (and ice in one), so abyssal/triglavian/gas mining displayed
  with no visual indicator. Now matches the full pattern from
  `character-details.blade.php`.
- Both summary models (`MiningLedgerDailySummary`, `MiningLedgerMonthlySummary`)
  updated with new columns in `$fillable`, `$casts`, and the @property
  docblocks.

Recovery on existing installs (after the migration runs at restart):

  docker exec -it seat-docker-front-1 \\
    php artisan mining-manager:backfill-ore-types

  docker exec -it seat-docker-front-1 \\
    php artisan mining-manager:process-ledger --recalculate

The first command makes sure every `mining_ledger` row has correct
`is_abyssal` / `is_triglavian` / `ore_category` values. The second
re-runs the daily summary aggregation with the fixed SQL so existing
summaries get their value redistributed from `regular_ore_value` into
the new dedicated columns. Tax calculations and all the per-row data
were always correct — only the rolled-up summaries needed fixing.

**Abyssal + Triglavian ores incorrectly badged as "Regular Ore":**
- Mining ledger detail view (`character-details.blade.php`) badge logic
  fell through to "Regular Ore" for any ore that wasn't moon/ice/gas. The
  `is_abyssal` and `is_triglavian` boolean columns existed on the model but
  weren't checked, so abyssal ores (Talassonite, Bezdnacine, Rakovene and
  their Abyssal/Hadal variants) and Triglavian ores were all visually
  labeled as Regular Ore even though their tax classification was correct.
- Added abyssal (red badge) and triglavian (dark badge) checks in the
  badge if/elseif chain. Translation strings already existed in the
  ledger lang file.
- **Taxation was unaffected** — the tax rate path in
  `ProcessMiningLedgerCommand::getTaxRateFromSettings` correctly checks
  `$isAbyssal` and `$isTriglavian` flags and applies the
  `taxRates['abyssal_ore']` / `taxRates['triglavian_ore']` rates from
  settings. Settings UI exposes both rate inputs and on/off toggles.
  This was display-only.
- `BackfillOreTypeFlagsCommand` extended to also backfill `is_abyssal`,
  `is_triglavian`, and the `ore_category` string column. Previously only
  backfilled `is_moon_ore`, `is_ice`, `is_gas` — meaning legacy rows from
  before abyssal/triglavian classification was added would be stuck with
  default-false flags forever. Run
  `php artisan mining-manager:backfill-ore-types` once after upgrading to
  fix any historical rows.

**Tax overdue status flip + Days Remaining counter:**
- `SendTaxRemindersCommand` now calls `TaxCalculationService::updateOverdueTaxes()`
  at the start of every run. Previously the status flip from `unpaid → overdue`
  only happened during the monthly tax calculation, so newly-past-due taxes
  stayed marked `unpaid` for weeks and kept getting "Tax Payment Reminder"
  pings instead of "Tax Payment Overdue" notifications. Status flip respects
  the ESI Wallet Lag Buffer (`payment.grace_period_hours`, default 24h).
- "Days Remaining" calculation in the reminder embed now uses
  `diffInDays($other, false)` (signed) clamped with `max(0, ...)`. Previously
  used unsigned diff which returned `abs(today - dueDate)`, causing the
  counter to grow past the due date — operators saw "Days Remaining: 5" on
  a tax that was actually 5 days overdue. Same defensive fix applied to the
  overdue branch's days-past-due calculation.

**Jackpot detection (auto + manual report verification):**
- Both detection paths used `natural_decay_time` as the end of the mining
  window. That column is the auto-fracture mark — only ~3 hours after
  chunk arrival — so the lookup window collapsed to a single day and
  missed essentially all real mining activity (which happens in the 50h
  window after fracture). Symptom: user reports a jackpot, the daily
  detect-jackpots cron runs the next morning, queries `chunk_arrival_date
  → natural_decay_date` (same day), finds no jackpot ores in mining_ledger,
  marks `jackpot_verified=false`, UI shows "Could not verify — no jackpot
  ores found in mining data". Same bug also broke auto-detection from
  fresh corp observer data.
- Both paths now use `MoonExtraction::getExpiryTime()` (= fractured_at
  + 50h, with chunk_arrival + 53h fallback) for the window end.
- DetectJackpotsCommand verification trigger now uses `isExpired()` instead
  of `natural_decay_time->isPast()` so it doesn't prematurely lock in a
  "false" verdict 3 hours after chunk arrival before mining has even
  started.
- DetectJackpotsCommand's mining_ledger query now filters by
  `observer_id = structure_id` (precise) instead of `solar_system_id`
  (cross-contaminated between multiple Athanors in the same system).
- New `--rerun-failed` flag on DetectJackpotsCommand resets
  `jackpot_verified=false` rows that have a `jackpot_reported_by`, so
  existing affected extractions can re-verify with the corrected logic.
- ProcessMiningLedgerCommand's real-time jackpot hook now ALSO verifies
  user-reported jackpots (not just auto-detect). Previously the only
  verification path was the daily cron — now real mining data flips
  `jackpot_verified=true` within ~30 minutes of the corp observer
  ESI endpoint refreshing. The notification only fires for genuine
  auto-detects to avoid double-notifying when a user already reported.
- Auto-detected jackpots now set `jackpot_verified=true` consistently,
  so the daily backstop has nothing to do for them.

### Added

**Notification consolidation:**
- New `Services/Notification/Concerns/WebhookDispatchTrait.php` holding
  `postWithRetry`, `getDiscordRoleMention`, `getMoonOwnerScopedWebhooks`,
  and `getCorpName`. Single source of truth for the webhook HTTP layer
  and the per-type role-mention precedence rules.
- `NotificationService` now handles every notification surface directly
  (tax, event, moon, theft, report). New convenience wrappers:
  `sendReportGenerated`, `sendMoonArrival`, `sendJackpotDetected`,
  `sendTheftDetected`, `sendCriticalTheft`, `sendActiveTheft`,
  `sendIncidentResolved`. Each takes a domain model (MiningReport /
  TheftIncident) plus optional `$additionalData` and handles the
  data-shaping internally.
- `NotificationService::testWebhook(WebhookConfiguration)` — single-webhook
  dispatch path for the Settings → Webhooks "Test" button and the
  Diagnostic tool. Replaces the old `WebhookService::testWebhook`.
- `NotificationService::getEventTitle(string)` — public helper so preview
  UIs can get the title for an event type without reflecting into a
  protected match statement.
- New TYPE_* constants: TYPE_REPORT_GENERATED, TYPE_JACKPOT_DETECTED,
  TYPE_THEFT_DETECTED, TYPE_CRITICAL_THEFT, TYPE_ACTIVE_THEFT,
  TYPE_INCIDENT_RESOLVED.
- `TheftIncidentController::resolve()` + `updateStatus()` now fire an
  `incident_resolved` notification when a director marks an incident
  resolved or false-alarm — the previously-dormant wire-up point is
  now active.
- `GenerateTaxInvoicesCommand` now fires `tax_invoice` notifications when
  each invoice is created. Previously the method existed but had zero
  production callers, so miners never got pinged at invoice-creation
  time. Mirrors the mining_tax's `due_date` onto the invoice's `expires_at`
  so the notification displays a real deadline.

**Diagnostic tooling:**
- New **"Fire Live Notification"** button in Diagnostic → Notification
  Testing. Routes through the full `NotificationService` pipeline to every
  subscribed webhook (scope + type toggles applied, audit log written).
  Unlike Preview Test (which posts to one webhook), Live Fire exercises
  the production dispatch path end-to-end — useful for verifying the
  pipeline works without waiting for a real event to trigger it.
- New **"Fire ALL (Chain)"** button. Sequentially fires every notification
  type (all 15) through the pipeline with a 1.5s delay between each. 22-
  second post-deploy smoke test — every Discord/Slack channel receives
  every type of notification the plugin can send. Surfaced the dormant
  `tax_invoice` null-format crash that prompted the fix above.
- `Diagnostic → Notification Testing` dropdown now includes all 15 types
  (tax × 5, event × 3, moon × 2, theft × 4, report × 1).

**Tax lifecycle:**
- `SendTaxRemindersCommand` now branches on tax status: `status='overdue'`
  dispatches `sendTaxOverdue` (positive `daysOverdue`), `status='unpaid'`
  dispatches `sendTaxReminder` (positive `daysRemaining`). Before:
  always called `sendTaxReminder` with `daysRemaining: 0` past the due
  date, and `sendTaxOverdue` had zero callers.

### Changed

**Notification consolidation:**
- All webhook POSTs now use `postWithRetry` — gains 5xx / 429 retry
  behaviour with `Retry-After` support. Previously retries only happened
  on the moon/theft/report paths; tax + event Discord/Slack/custom
  dispatches had no retry logic.
- Custom-webhook support for per-webhook `custom_payload_template`
  and `custom_headers` now applies to every notification surface, not
  just theft. A consumer's JSON template is variable-substituted and
  the webhook's configured headers are attached to every custom POST.
- `NotificationService::send()` now returns a channel-keyed result shape
  for every call — `{discord: {sent: [ids], failed: [{webhook_id, error}]},
  slack: {...}, esi: {...}}`. Callers that previously read the flat
  per-webhook-id map from WebhookService have been updated.
- Slack theft formatting simplified from Block Kit (with button action)
  to the same attachments-style layout every other surface uses. Still
  shows all fields + the incident URL as a link.
- Settings → Webhooks "Test" button now sends a minimal **"✅ Webhook
  Active"** ping instead of a sample theft notification — quick wiring
  check without scary fake incident data.

**New notification family — Extraction Threat Alerts (cross-plugin):**
- Two new notification types, both opt-in per-webhook, both gated behind
  Manager Core + Structure Manager installation:
  - `extraction_at_risk` — fires when a refinery running an active moon
    extraction is in trouble. Four flavors ship with dramatic Discord titles:
    - **fuel_critical** → 🔥 MOON CHUNK COMPROMISED — Fuel Critical
    - **shield_reinforced** → ⚠️ EXTRACTION IN DANGER — Shield Down
    - **armor_reinforced** → 🚨 EXTRACTION IN DANGER — Armor Timer
    - **hull_reinforced** → 💀 MOON CHUNK DESTABILISED — Final Timer
  - `extraction_lost` — post-mortem when the refinery is destroyed:
    - **destroyed** → ☠️ MOON CHUNK DESTROYED
- Routing: Structure Manager publishes `structure.alert.*` events on
  Manager Core's EventBus. Mining Manager subscribes via a wildcard
  pattern through a new PluginBridge capability (`structure.notify_alert`).
  The `StructureAlertHandler` filters to refineries-with-active-extractions,
  then dispatches to `NotificationService::sendExtractionAtRisk()` or
  `sendExtractionLost()`.
- Dynamic embed: internal type is stable (`extraction_at_risk`) but the
  embed title, color, and description adapt per flavor via helper methods
  `resolveExtractionAtRiskColor()` / `resolveExtractionAtRiskTitle()`.
- Idempotency: five boolean columns on `moon_extractions`
  (`alert_fuel_critical_sent`, `alert_shield_reinforced_sent`,
  `alert_armor_reinforced_sent`, `alert_hull_reinforced_sent`,
  `alert_destroyed_sent`). Each flavor fires at most once per extraction —
  fuel_critical can co-exist with under_attack, but not two fuel_criticals.
- Corp scoping: routes like event notifications — admin webhooks + moon
  owner corp + structure owner corp (from SM event payload). Set per-corp
  in the Webhooks tab.
- Ships-now scope on SM side: `fuel_critical` publishing from
  `NotifyUpwellLowFuel` (refinery-only, critical-threshold-only,
  active-extraction-only). Shield/armor/hull/destroyed flavors are future
  SM work — MM is already subscribed to the wildcard so they "just work"
  the moment SM starts publishing them.
- Graceful degradation: if Manager Core or Structure Manager is missing,
  the Settings → Notifications block for these types shows a banner listing
  which plugin is required, and both the master toggle + webhook
  checkboxes are `disabled`. No silent misconfiguration.
- Diagnostic: both types wired into Fire Live Notification + Fire ALL Chain
  (18 types total now). The default flavor for extraction_at_risk preview
  is `fuel_critical`; override via test input.
- New migration: `2026_01_01_000008_add_extraction_at_risk_notifications.php`
  adds the 5 dedup columns + 2 webhook toggle columns. Additive + defaulted.

**New notification type — Moon Chunk Unstable (capital safety warning):**
- Fires ~2 hours before a moon chunk enters EVE's unstable state
  (`natural_decay_time`). Capital ship pilots (Rorquals, Orcas) should
  dock up or warp to safety when this notification fires — unstable
  chunks historically attract hostile gangs.
- Configurable lead time via `--unstable-warning-hours=N` option on
  `mining-manager:check-extraction-arrivals` (default 2).
- Subscribe per-webhook in Settings → Webhooks (new `notify_moon_chunk_unstable`
  toggle). Opt-in — existing webhooks don't auto-enable this.
- Idempotent: each extraction fires the warning ONCE via a new
  `unstable_warning_sent` flag on `moon_extractions`. Dedup guaranteed
  even if the per-minute cron picks the same row up multiple times.
- Role pings ENABLED for this type (unlike tax_invoice) since it fires
  once per chunk, not N times in a batch, and capital-safety warnings
  should be high-visibility. Discord colour: orange-red (0xFF6B00).
- Corp-scoped via the moon owner corp helper — same routing rules as
  moon_arrival / jackpot_detected.

**Per-corp webhook routing:**
- Individual tax notifications (`tax_reminder`, `tax_invoice`, `tax_overdue`)
  now scope to webhooks matching the miner's current corporation (resolved
  via SeAT's `character_affiliations`). Webhook selector in Settings →
  Webhooks lets each webhook be assigned to Global (admin), the Tax
  Program Corp, or a specific corp. Each director gets their own Discord
  channel for their own members without cross-corp noise.
- Event notifications (`event_created`, `event_started`, `event_completed`)
  now scope to the event's target corporation (`mining_events.corporation_id`).
  Corp-specific events go to that corp's webhook + admin + tax program corp;
  null-corp (universal) events go to admin + tax program corp only.
  **Behaviour change for existing installs:** if you had an event webhook
  assigned to a specific corp, it will now STOP receiving events for OTHER
  corps. Move it to Global, or add sibling webhooks per-corp.

**Tax lifecycle:**
- `TaxCalculationService::updateOverdueTaxes()` now flips `unpaid → overdue`
  on the day after `due_date` has passed, respecting only the ESI Wallet
  Lag Buffer (`payment.grace_period_hours`, default 24h). Previously it
  also subtracted `grace_period_days` (default 7), which meant the UI
  showed UNPAID for a week past the due date while `(N days ago)` already
  rendered in red — visible contradiction. The longer `grace_period_days`
  setting is still respected by `TheftDetectionService` for theft
  escalation, a separate concern.
- `SendTaxRemindersCommand` query now includes both `unpaid` AND `overdue`
  statuses. Previously only `unpaid` matched, so once `updateOverdueTaxes`
  eventually flipped a tax, it fell out of the reminder query entirely —
  the character received zero notifications for the overdue period.

**Robustness polish:**
- `Cache::lock` now present on every scheduled command (was on 10 of 21).
  `allow_overlap: false` in the schedule table only serialises the same
  command invocation; manual artisan runs or UI-triggered commands could
  still overlap a running cron. True process-wide mutex now enforced for:
  CachePriceDataCommand, CalculateMonthlyStatisticsCommand,
  CheckExtractionArrivalsCommand, DetectMoonTheftCommand,
  GenerateTaxCodesCommand, GenerateTaxInvoicesCommand,
  MonitorActiveTheftsCommand, RecalculateExtractionValuesCommand,
  SendTaxRemindersCommand, UpdateDailySummariesCommand,
  UpdateMiningEventsCommand.
- `MoonExtraction.jackpot_verified` — removed the `'boolean'` cast.
  Column is nullable with tri-state semantics (null / true / false) and
  consumers use strict comparisons (`=== null`, `=== true`, `=== false`).
  The cast was coercing NULL → false on hydration, collapsing "not yet
  verified" into "verified not-jackpot" — six call sites silently
  misbehaved. All now work as designed.
- `SettingsManagerService::updateSetting()` clears the cache BEFORE the
  DB write + clears again after, instead of only after. Closes a race
  where a concurrent reader could re-populate the cache with the old
  value in the window between write and post-clear.
- `TaxController::markPaid()` and `TaxController::updateStatus()` wrapped
  in `DB::transaction` — the `mining_taxes` update and the `tax_codes`
  deactivation now happen atomically. Closed a gap where a partial
  failure could leave an active tax code after the tax was marked paid,
  causing the wallet listener to double-process the next transfer.

### Removed

**Notification consolidation:**
- `Services/Notification/WebhookService.php` entire file (1535 lines).
- `NotificationService::sendMoonReady` (was marked @deprecated, zero callers).
- 4 duplicate inline implementations of role-mention logic, corp-scoping,
  and `getCorpName` between the two old services.

**Dead settings cleanup** — 5 UI toggles that didn't gate anything:
- `features.notify_extraction_ready` — moon notifications were already
  gated per-webhook, this toggle had no effect.
- `features.extraction_notification_hours` — implied "N hours before
  arrival" pre-reminders that don't exist. Moon notifications fire once
  at actual chunk arrival via `CheckExtractionArrivalsCommand`.
- `features.auto_track_event_participation` — participation is always
  derived from mining activity during the event window, no toggle gated it.
- `features.track_moon_compositions` — compositions always tracked when
  `enable_moon_tracking` is on.
- `features.calculate_moon_value` — value always calculated when
  `enable_moon_tracking` is on.
- `moon.notification_hours_before` config default + dead
  `getExtractionAlerts()` and `markNotificationSent()` methods on
  `MoonExtractionService` (zero callers).

**Notification:**
- The "Ping Role" toggle for `tax_invoice` in Settings → Notifications.
  Rationale: GenerateTaxInvoicesCommand fires invoices one-by-one in a
  loop, so a role ping would tag the role N times per cron run. The
  per-user `@mention` is kept — the individual miner being invoiced gets
  pinged, not the whole role. Runtime guard in `WebhookDispatchTrait`
  also suppresses any legacy DB values with `ping_role=true` for this
  type (defence-in-depth).

### Fixed

- `sendTaxInvoice` null-format crash (`$invoice->due_date` column doesn't
  exist on the `tax_invoices` table — operational deadline is `expires_at`).
  Dormant bug surfaced by the new Fire ALL Chain diagnostic.
- Slack `$color` match was missing entries for `TYPE_TAX_GENERATED`,
  `TYPE_TAX_ANNOUNCEMENT`, and `TYPE_TAX_INVOICE` — they fell through to
  default blue. Discord `$color` match was missing `TYPE_TAX_INVOICE`
  (same fallback). All four added.
- Blade parse error in `events/_tax_compatibility_panel.blade.php` where
  an inline `@foreach { ... @if(...<...) ... @endif ... @endforeach }`
  pattern tripped the compiler. Replaced the `<` comparison with
  Laravel's idiomatic `$loop->last`.

### Migration notes

- No schema changes. All existing webhook configurations, tax records,
  moon extractions, and settings continue to work.
- Orphan DB rows for the 5 removed `features.*` settings are inert —
  never read, never written. Optional cleanup only.
- Wire output to Discord, Slack, and custom endpoints is preserved
  byte-for-byte for every notification type on identical input. Single
  intentional exception: theft Slack is now attachments format instead
  of Block Kit (all other surfaces already used attachments).

## [1.0.3] - Event Accuracy, Period Awareness, Weekly Removal

Big release. Three parallel streams of work converged:

1. **Event tracking rebuilt** from the ground up. A new `event_mining_records` table materialises the exact mining activity qualifying for each event with all four scope filters (corp, location, time, ore category) applied at populate time. Tax attribution is now per-row — the modifier applies only to the actual event-window slice, not the whole day's mining. ISK saved during events is surfaced to miners on their pages and to directors on the dashboard.
2. **Bi-weekly tax period support matured.** The data layer already supported it; presentation and queries now do too. Period switches queue to a safe cutover date to prevent row collisions.
3. **Weekly tax period removed.** ISO weeks don't align to calendar months; the straddling weeks caused double-tax and chart aggregation problems. Biweekly covers the sub-monthly use case cleanly.

### Added

**Event System Refactor (Phases 1–3)**
- New `event_mining_records` table (migration `2026_01_01_000005`) — canonical record of which mining qualifies for each event. Populated by `EventMiningAggregator` with all filters baked in (corp + location + time + ore category). Moon events read `mining_ledger` (day-level observer data); belt/ice/gas events read SeAT's `character_minings` with datetime precision via `time` column.
- New `event_discount_total` column on `mining_ledger_daily_summaries` (migration `2026_01_01_000006`) — daily sum of ISK waived by event modifiers.
- New per-ore entries in `ore_types` JSON: `event_id`, `event_qualified_value`, `event_discount_amount`, blended `effective_rate`.
- New `mining-manager:backfill-event-records` artisan command — `--event=ID` / `--status=active|completed|planned` / `--fresh` for one-off rebuild after deploy.
- New `EventMiningAggregator` service — lazy-promoted via `MiningEvent::booted()` hook when any scope field (`type`, `corporation_id`, `solar_system_id`, `location_scope`, `start_time`, `end_time`) changes on save.
- `LedgerSummaryService::getEventAttributionForLedgerRow()` — per-row tax attribution. Modifier applies to the exact slice of mining that overlapped the event window, not to the whole day.
- Historical pricing preservation for non-moon events via proportional allocation from `mining_ledger.total_value` — backfilling an old event no longer rewrites ISK with today's prices.
- Event form **tax-compatibility panel** — badge row showing currently-taxed categories, reactive status block (🟢 full / 🟡 partial / 🔴 empty) based on the chosen event type, and a suggested event types list. Prevents running a "gas_huffing" event on an install that isn't taxing gas.
- Miner-facing event discount indicators:
  - **My Mining** — green callout "Event Discount Applied: you saved X ISK this period" + new orange small-box "Total Event Savings (All Time)" showing the running ISK total of tax waived from event participation across every event the user's characters have ever joined
  - **My Taxes** — top banner "Event discount applied this period: X ISK saved", plus per-ore "saved Y ISK" sub-line in the breakdown table
  - **My Events** — new full-width banner "Total tax saved from event participation: X ISK" near the top, plus a "Your tax saved: X ISK" line on every event card (active + completed sections)
  - **Ledger Summary** (director) — "incl. −X ISK event discount" under the Total Tax info-box
  - **Calculate Taxes** (admin) — Event Tax column now shows the real per-row discount (previously always 0 — column read a non-existent `event_tax_amount`)
  - **Director Dashboard charts** (Mining Tax, Event Tax) and **Member Dashboard** (Mining Income Last 12 Months) — gained a period-aware footnote under each chart when the install runs biweekly, clarifying that biweekly periods within each calendar month are summed into that month's bar.

**Savings-attribution helpers**
- `LedgerSummaryService::getTotalEventSavings($characterIds, $start = null, $end = null)` — fast sum of `mining_ledger_daily_summaries.event_discount_total` for a character set over an optional date range.
- `LedgerSummaryService::getEventSavingsByEvent($characterIds, $start = null, $end = null)` — walks `ore_types` JSON and returns `[event_id => ISK saved]` for per-event attribution (used by the My Events per-card line).

**Period Awareness (biweekly/weekly presentation)**
- `TaxController::myTaxes` resolves the configured period, queries by `period_start` (exact), falls back to oldest unpaid tax when the current period hasn't been invoiced yet, exposes all unpaid taxes to the view.
- My Taxes page uses period-aware labels everywhere — Current Balance card, Mining Breakdown header, Event Discount banner, "no tax this period" alert.
- New **"All Unpaid Periods" table** on My Taxes when more than one tax is outstanding — shows every period with amount, due date, status badge, details link.
- `TaxController::index` (director Tax Overview) shows period context in the summary header. On non-monthly setups: "Current Biweekly period: Apr 15-30, 2026" + an additional sub-line under "Collected" showing ISK attributable to the current active period specifically (vs. the existing calendar-month total).
- `TaxController::myTaxBreakdown` AJAX returns period-bound slice (`period_type` / `period_start` / `period_end` / `period_label` in response; legacy `month` key kept for backward compat).
- `getMyTaxBreakdownData` signature widened to `(array $characterIds, Carbon $start, Carbon $end)` — mining breakdown aligns with displayed period instead of calendar month.

**Period Switch Safeguard**
- New settings slots: `tax_rates.tax_calculation_period_pending` and `tax_rates.tax_calculation_period_effective_from`. Period-type changes queue instead of applying immediately — unless the admin checks a new "Apply immediately" override (intended for fresh installs).
- Effective date defaults to **day 3 of next month** for monthly/biweekly — lets the current scheme's day-2 previous-period calc complete before promotion. Prevents H2 data loss on biweekly → monthly switches.
- `TaxPeriodHelper::getPendingPeriodChange()` exposes the queued change; new partial `taxes/partials/_pending_period_switch_banner.blade.php` shows a yellow warning on every tax page while a switch is queued.
- Lazy promotion in `getConfiguredPeriodType()` — no cron needed; first tax-page load or calculate-taxes run on or after the effective date auto-promotes and logs the transition.

**Cleanups / Observability**
- `Cache::lock()` added to `mining-manager:generate-reports` and `mining-manager:update-extractions` (matches the 8 other commands that already had it).
- `Http::timeout(10)` added to the three previously-bare HTTP calls (Slack webhook + two Fuzzwork price GETs).
- Security badge on analytics systems table now uses 0.45 (CCP's actual high-sec threshold) instead of 0.5 — Tasabeshi et al. now correctly green.
- Log line on period promotion: `Mining Manager: Tax calculation period promoted biweekly → monthly (effective 2026-05-03, promoted on 2026-05-03)`.

### Fixed

- **Event tracking only counted 1 of 19 participants** — `EventTrackingService::updateEventTracking()` was comparing a DATE column against DateTime watermarks, silently excluding all rows after the first tick. Also dropped the self-defeating `last_updated` incremental watermark; method is now idempotent and runs the full event window every pass via `updateOrCreate`.
- **Events showed "Total Mined: 0 ISK"** — `event_participants.value_mined` column added (migration `2026_01_01_000004`) alongside the existing `quantity_mined`. Event Tracking Service now populates both from `mining_ledger.total_value`.
- **Event type didn't scope tax modifier to correct ore category** — added `EVENT_TYPE_ORE_CATEGORIES` constant on `MiningEvent` + `appliesToOreCategory()` helper. `mining_op` applies only to regular ore, `ice_mining` only to ice, etc. "Special Event" covers every currently-taxed category.
- **`character_infos.corporation_id` reads returned null** — latent bug since SeAT's 2019 schema change dropped that column in favor of `character_affiliations`. Fixed in `LedgerSummaryService::generateDailySummary` (was silently breaking guest-mining detection and corp-scoped event attribution), `EventMiningAggregator`, `MiningTax::getCorporationIdAttribute`, and `CharacterInfoService`.
- **Event charts displayed garbage (~92K ISK for a 3.87B event)** — three director/member dashboard charts computed event tax as `$event->total_mined × hardcoded 10% × modifier`, but `total_mined` is unit quantity not ISK. All three now read from the authoritative `event_discount_total` on daily summaries:
  - Director "Event Tax (12 Months)" chart
  - Member "Mining Income" chart `event_bonus` series
  - Events index "Total Value" KPI
  - My Events "Total Mined" / "Avg Per Event" stats
- **my-events.blade.php crashed with "Attempt to read property 'id' on null"** — the view used `auth()->user()->id` (SeAT user ID) as a character_id filter, so `$myParticipation` was usually null. Refactored to aggregate across all of the user's characters via `$characterIds` passed from the controller; rank computed across top participants by `character_id` match rather than a brittle `$p->id === $myParticipation->id`.
- **Events list showed "0 participants"** — `events/index.blade.php` used `$event->participants_count` (plural typo); column is `participant_count` (singular). Dropped the bogus `/ max_participants` suffix too (that column doesn't exist).
- **Retroactive daily-summary rebuilds showed zero event discount** — `getEventAttributionForLedgerRow` filtered candidate events to `status='active'` only, so rebuilding a past day's summary after the event had transitioned to `completed` found nothing. Now accepts `active` and `completed` (excludes `planned` and `cancelled`).
- **`event_discount_total` was zero for moon events — the actual root cause.** `LedgerSummaryService::getOreCategory()` returned generic strings (`'moon_ore'`, `'abyssal_ore'`, `'triglavian_ore'`) but `MiningEvent::EVENT_TYPE_ORE_CATEGORIES` (and `mining_ledger.ore_category`) use the specific-rarity values (`'moon_r4'`, `'moon_r8'`, ..., `'moon_r64'`, `'abyssal'`, `'triglavian'`). The ingestion commands (`ProcessMiningLedgerCommand`, `ImportCharacterMiningCommand`) already produced the correct specific values; only this view-layer helper drifted. Result: `MiningEvent::appliesToOreCategory('moon_ore')` always returned false, attribution lookup always returned null, and every daily summary had `event_discount_total = 0` regardless of event activity. Aligned the helper with the ingestion side. Verified on user's install: 31 daily summary rows now carry non-zero discounts totaling ~38.5M ISK across 19 miners for a single 48h moon event.
- **Calculate Taxes Event Tax column always showed 0** — the attribution prefetch map keyed on `$row->mining_date` via `sprintf '%s'`, but `EventMiningRecord` casts `mining_date` to Carbon (`'date'` cast). Carbon's `__toString()` emits `"YYYY-MM-DD HH:MM:SS"` while the entry-side lookup used `Carbon::parse($entry->date)->toDateString()` → `"YYYY-MM-DD"`. Keys never matched. Explicit `->format('Y-m-d')` on both sides now.
- **Wrongly-named migration file** — `2026_04_21_000001_add_value_tracking_to_events` renamed to `2026_01_01_000004_add_value_tracking_to_events` to match plugin's fixed-date-prefix + sequential-numbering convention. Also converted from anonymous class to named class (`AddValueTrackingToEvents`) matching the other 3 migrations.
- **Dead code paths removed**:
  - `ProcessMiningLedgerListener` (deprecated, never registered, never fired in SeAT v5)
  - Dead `character_infos.corporation_id` fallback branches in `MiningTax` + `CharacterInfoService` (column dropped in 2019, branches unreachable)

### Changed

- **Weekly tax calculation period removed.**

  *Why:* ISO weeks (Mon-Sun) don't align to calendar months. A week starting Apr 27 ends May 3, so the tax row covered mining from April 27-30 AND May 1-3. Three compounding problems followed: (1) straddling tax rows leaked accounting into the next month; (2) switching weekly → anything caused **double-tax** because the straddling row's May days overlapped with the first new-scheme row also covering May; (3) dashboard charts had to smear weekly row totals across adjacent months. Biweekly (1st-14th, 15th-end) covers the sub-monthly use case cleanly.

  *If your install was running weekly — what happens on upgrade:*
  1. **Auto-heal on first read.** The first tax-page load or `calculate-taxes` cron after the upgrade rewrites `tax_rates.tax_calculation_period` from `weekly` to `monthly` in the settings store, logging a warning: `Mining Manager: Auto-migrated tax_calculation_period from deprecated "weekly" to "monthly"...`. No admin action required.
  2. **Historical weekly rows preserved.** Existing `mining_taxes` rows with `period_type='weekly'` stay in the database forever. They remain visible in Tax History, Tax Details, My Taxes breakdown, and CSV exports — rendered with their original weekly labels (e.g. "Mar 3-9, 2026") via `MiningTax::formatted_period`.
  3. **No new weekly rows.** Going forward the plugin only writes `monthly` or `biweekly` rows.
  4. **Switching to biweekly** (if the admin prefers sub-monthly over monthly): open Settings → Tax Rates and change the dropdown. The change queues to day 3 of next month via the new period-switch safeguard (no collision with the auto-migrated monthly setting).

  *Defense in depth:* three layers of weekly coercion ensure no new weekly data can slip in:
  - Settings form validation rejects `weekly` (`in:monthly,biweekly`)
  - `SettingsManagerService::updateTaxRates` coerces `weekly` → `monthly` with a log warning if any caller bypasses the form
  - `TaxPeriodHelper::normaliseLegacyWeekly()` coerces `weekly` passed to internal methods (period bounds, calc-day checks, etc.)

  No schema migration required. `mining_taxes.period_type` is `string(20)`, not an enum.
- **Moon event corp filter semantics** now documented and uniform:
  - Miner's current corp must equal `event.corporation_id` for corp-scoped events (no miner-corp filter for global events)
  - Observer row corp (moon owner) is NOT required to match miner corp — a Corp-B miner at a Corp-A moon legitimately counts for a Corp-B event and for any global event, provided the moon row is in the source pool per `tax_selector`
  - Source pool for moon events follows `tax_selector`: `only_corp_moon_ore` → restrict to moon-owner corp's observers; `all_moon_ore` → any observer; `no_moon_ore` → no observer data
- **Non-moon event participation narrowed by `tax_selector`** — a gas event on an install with `tax_selector.gas=false` now produces no records (previously it silently tracked zero-tax activity). Event form warns on mismatch.
- **Event webhook includes ISK value mined** — previously moon-event notifications showed only quantity; now also reports ISK total where available.

### Known Limitations

- **Moon events stay day-level.** EVE's observer data is day-aggregated; the plugin cannot get sub-day precision on moon mining until CCP changes ESI. Documented in Help under Events → Time Granularity.
- **Non-moon events use SeAT fetch time**, not literal EVE mining time (character_minings doesn't carry the moment of mining). Good enough for events spanning several hours; noisy for sub-hour events.
- **Weekly removal does not delete historical rows.** They remain visible in Tax History and export reports forever (we don't touch released migrations).

### Mental Model

**ESI tells us WHAT is happening. The clock tells us WHEN to notify. `event_mining_records` tells us WHICH mining counts for which event.**

## [1.0.2] - Time-Based Moon Arrival Notifications

### Fixed
- **Moon arrival notifications silently missed when chunk arrived between cron ticks** -- The previous ESI-polling-based notification path was fragile. The import loop's `determineStatus()` would write `status='ready'` directly when `chunk_arrival_time` had passed, bypassing the transition-detection code that fired notifications. If ESI was stale, offline, or the cron timing was off, notifications were lost. Now decoupled from ESI entirely.

### Added
- **`mining-manager:check-extraction-arrivals` command** -- New lightweight cron running every minute. Pure time arithmetic, no ESI calls. Queries extractions whose stored `chunk_arrival_time` has passed and fires moon_arrival notifications. Idempotent via the `notification_sent` flag. Handles edge cases:
  - Arrivals between 2h ESI-poll ticks (fire within 60s of actual arrival)
  - ESI downtime (stored `chunk_arrival_time` is the source of truth)
  - Extractions imported directly as `'ready'` (notification catches up automatically)
  - Cron outages (backed-up arrivals fire when cron resumes)
- **`mining-manager:backfill-extraction-history` command** -- Reconstructs historical `moon_extraction_history` rows from EVE `MoonminingExtractionStarted` character notifications. When the plugin is installed on a corp that has months of pre-existing mining history, ESI only returns active/upcoming extractions; completed cycles can't be re-fetched. SeAT retains character notifications, though, so this command scans them, dedupes by `(structure_id, readyTime)`, matches each extraction to its corresponding fracture/cancel notification (manual via `MoonminingLaserFired`, auto via `MoonminingAutomaticFracture`, or `MoonminingExtractionCancelled`), computes actual mined values from `mining_ledger` where data exists, and inserts complete history rows. **Progress bars** for both the dedup pass (parsing YAML notifications) and the main processing pass (DB queries per extraction). Supports `--structure=ID` to scope to one structure, `--days=N` lookback window, `--dry-run` preview, and `--force` to recreate existing rows. Historical ISK prices are unknown so `estimated_value` fields are left NULL. Automatically invoked during `mining-manager:initialize` Phase 3 (historical backfill) when the user opts in.
- **Cancellation detection via EVE notifications** -- New `detectCancellations()` method on `MoonExtractionService` parses `MoonminingExtractionCancelled` character notifications (same pattern as existing fracture detection). When a director cancels an extraction in-game, the state system marks it `cancelled` within the next 2h poll cycle. The notification watchdog then skips it -- no false "Moon Chunk Ready" alert fires at the originally scheduled arrival time. `cancelled` is now a valid status alongside `extracting`, `ready`, `expired`. Runs automatically inside `update-extractions`. Follows the existing notification-parsing convention (`MoonminingLaserFired`, `MoonminingAutomaticFracture`, `MoonminingExtractionStarted`).
- **Architecture documentation** -- README and in-app Help docs now explain the two-system model:
  - State system (ESI-driven, every 2h): what EVE says is happening
  - Notification system (time-driven, every minute): when to notify
- **`--dry-run`, `--hours-back`, `--limit` flags** on the new command for testing and controlling dispatch volume.
- **Enhanced diagnostic logging** -- `updateExtractionStatuses()`, `sendMoonArrivalNotification()`, `sendMoonNotification()`, and `getMoonOwnerScopedWebhooks()` now emit structured `Log::info`/`Log::warning` entries at every decision point. Visible in SeAT Log Viewer (filter by Info level). Makes silent failures easy to diagnose.

### Changed
- **`sendMoonArrivalNotification()` now sets `notification_sent = true`** after successful dispatch, enforcing dedup across both entry points (old `updateExtractionStatuses` path and new `check-extraction-arrivals` cron). First caller wins, subsequent callers skip safely.
- **Archive command now archives cancelled extractions** -- previously `ArchiveOldExtractionsCommand` only archived `expired` and `fractured` statuses. Cancelled extractions (detected via `MoonminingExtractionCancelled` notification) accumulated in `moon_extractions` indefinitely because their originally planned `natural_decay_time` stayed in the future. Now handled via an OR branch: cancelled rows are archived 7 days after `updated_at` (the cancellation detection timestamp). Ensures `moon_extraction_history` is the single source of truth for past extractions regardless of final state.
- **Cancelled extractions display with a semantic badge** -- Moon show page now renders cancelled extractions with a dark badge and ban icon (`<i class="fas fa-ban">`) rather than falling through to the generic "warning" label.
- **Backfill command now correctly treats cancelled extractions as having zero mining** -- cancelled extractions never had a chunk to mine. If ledger activity exists in the cancelled extraction's time window, it belongs to a different (typically rescheduled) extraction. The backfill now sets `actual_mined_value`, `total_miners`, and `completion_percentage` to zero for cancelled rows regardless of what the ledger shows.
- **Moon show page history now unions both tables** -- previously the controller checked `moon_extraction_history` first and only fell back to `moon_extractions` if history was empty. Once any archived row existed, recently-expired extractions (still in `moon_extractions`, pending their 7-day archive cooldown) became invisible. The controller now queries both tables, dedupes by `chunk_arrival_time`, and merges into a single sorted list. Recently-terminal extractions appear immediately without waiting for archival. The 7-day archive cooldown is kept as-is to allow late ESI fracture data to settle.
- **"Value at Arrival" now correctly preserved and displayed** -- The `estimated_value_pre_arrival` column on `moon_extractions` (→ `estimated_value_at_arrival` on `moon_extraction_history`) was being overwritten every 12 hours by `RecalculateExtractionValuesCommand`, defeating its purpose as a historical snapshot. Three fixes:
  1. `CheckExtractionArrivalsCommand` (every-minute cron) now snapshots the current `estimated_value` into `estimated_value_pre_arrival` at the moment the chunk arrives — one-time, idempotent, only runs if the field is NULL. This locks in the arrival-time price ~60s after actual arrival.
  2. `RecalculateExtractionValuesCommand` now only updates `estimated_value_pre_arrival` for extractions whose `chunk_arrival_time` is still in the future. Once arrived, the snapshot is frozen.
  3. Moon show page history table column renamed from "Estimated Value" to "Value at Arrival" and now reads `estimated_value_at_arrival` (archive) / `estimated_value_pre_arrival` (pending archive) with fallback to `final_estimated_value` or N/A.
- **Completion % baseline fixed** -- was calculated against `estimated_value` (current running value, drifts with market) — now uses `estimated_value_pre_arrival` (locked at arrival) for historically accurate completion measurements. The chunk had a specific ISK value when it arrived; completion % now measures what fraction of THAT value was captured before despawn. Falls back to `estimated_value` for rows without arrival snapshots.
- **Fixed narrow mining window + cancelled-attribution bug in `calculateActualMined` helpers** -- three separate copies of this helper (backfill command, moon show controller, archive command) all had the same two issues: (1) searched only the 3-hour pre-fracture window instead of the full 72-hour mining lifecycle, missing most actual mining activity, (2) counted ledger activity for cancelled extractions as if they had been mined. All three now use a 72h window from `chunk_arrival_time`, query by `observer_id = structure_id` for precise attribution, and return zeros for cancelled rows.
- **Past Extractions (Archived) table — interactive DataTables + MOC scoping + Structure column** -- the archived history table on `/mining-manager/moon` is now filtered to Moon Owner Corporation only (other directors' private moons on shared SeAT installs no longer leak through). Added a Structure column showing station/refinery names (batch-loaded via `MoonExtraction::loadDisplayNames()` — no N+1). Table uses jQuery DataTables for client-side sorting (all columns, with numeric `data-order` attributes on dates/values/progress bars for correct sort semantics), full-text search across all columns, a Status filter dropdown (auto-populated from the visible badge text), and pagination (10/25/50/100/All). Default sort is chunk arrival descending.
- **`mining-manager:backfill-extraction-history` now filters by Moon Owner Corporation** -- resolves MOC from settings, pre-loads the set of structure IDs owned by that corp, and skips notifications for any other structure during the dedup pass. Rejects `--structure=ID` for structures not owned by MOC. Reports a count of skipped foreign-corp notifications. Fully dynamic — if MOC changes in Settings, next run uses the new value.

### Mental Model
**ESI tells us WHAT is happening. The clock tells us WHEN to notify.**

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
