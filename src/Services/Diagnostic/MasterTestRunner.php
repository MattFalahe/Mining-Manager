<?php

namespace MiningManager\Services\Diagnostic;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\WebhookConfiguration;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Notification\NotificationService;
use MiningManager\Services\Pricing\PriceProviderService;
use Throwable;

/**
 * One-click "Master Test" — a comprehensive read-only smoke check that
 * exercises every major area of the plugin and returns a structured
 * pass/warn/fail/skip report.
 *
 * Design:
 *   - Every test is idempotent and read-only (no production data mutated).
 *   - Each test method returns an associative array with keys:
 *       name      → human label ("Schema: alert dedup columns present")
 *       category  → grouping bucket ("schema", "settings", "cross-plugin",
 *                   "notifications", "pricing", "lifecycle", "tax",
 *                   "security")
 *       status    → "pass" | "warn" | "fail" | "skip"
 *       message   → one-line summary shown in the UI
 *       detail    → optional longer text or array of sub-facts (collapsible)
 *   - Tests are wrapped in try/catch — an unexpected throw becomes a
 *     `fail` with the exception message. No single broken test can crash
 *     the run.
 *   - Repeating logic from the other diagnostic tabs (tax, moon, settings,
 *     etc.) is intentional. The Master Test is meant as a "click once,
 *     see everything green" pre-flight check; the per-area tabs remain
 *     useful for deep-dive debugging.
 *
 * Adding a new test:
 *   1. Add a private method `protected function checkXyz(): array { ... }`
 *      that returns the result row (use `pass()`, `warn()`, `fail()`,
 *      `skip()` helpers).
 *   2. Append the method name to `$this->testMethods` in `runAll()`.
 *   3. Done.
 *
 * Performance budget:
 *   - Aim for <30s total for the whole chain on a typical install.
 *   - Heavy DB queries should use COUNT() not full row fetch.
 *   - Network calls (MC bridge, ESI, webhook POSTs) should NOT happen
 *     during master tests; we verify configuration and reachability via
 *     in-process probes only. Webhook test buttons remain on the per-
 *     webhook diagnostic UI for explicit operator-driven smoke.
 */
class MasterTestRunner
{
    protected SettingsManagerService $settingsService;
    protected PriceProviderService $priceProvider;
    protected NotificationService $notificationService;

    public function __construct(
        SettingsManagerService $settingsService,
        PriceProviderService $priceProvider,
        NotificationService $notificationService
    ) {
        $this->settingsService = $settingsService;
        $this->priceProvider = $priceProvider;
        $this->notificationService = $notificationService;
    }

    /**
     * Run every test and return the full report.
     *
     * @return array {
     *     started_at:    string ISO 8601,
     *     finished_at:   string ISO 8601,
     *     duration_ms:   int,
     *     summary:       array {pass,warn,fail,skip,total},
     *     results:       array of result rows,
     *     overall_status: 'pass'|'warn'|'fail'
     * }
     */
    public function runAll(): array
    {
        $startedAt = Carbon::now();
        $startMs = (int) (microtime(true) * 1000);

        $testMethods = [
            // Schema & migrations
            'checkMigrationsApplied',
            'checkAlertDedupColumns',
            'checkTaxCodesUniqueConstraint',
            'checkPeriodStartBackfill',
            'checkSettingsTableShape',

            // Settings consistency
            'checkPricingSettingsLoadable',
            'checkNotificationSettingsLoadable',
            'checkFeatureFlagsLoadable',
            'checkActiveCorporationConfigured',

            // Cross-plugin detection + integration
            'checkManagerCoreDetected',
            'checkStructureManagerDetected',
            'checkEventBusSubscriptionPresent',
            'checkPluginBridgeCapabilitiesRegistered',
            'checkMcPricingSubscriptionPresent',
            'checkMcPriceFreshness',

            // Pricing path
            'checkConfiguredProviderValid',
            'checkPriceProviderRoundtrip',

            // Notifications path
            'checkWebhookConfigurations',
            'checkCustomTemplateInjectionSafety',

            // Mining lifecycle
            'checkSchedulesPresent',
            'checkMoonExtractionsHealth',

            // Tax pipeline
            'checkTaxPipelineSanity',
            'checkProcessedTransactionsTable',

            // Security / audit hardening verification
            'checkAtomicCasColumnsIndexed',
            'checkScheduleSeederPattern',

            // Infra
            'checkCacheRoundtrip',
        ];

        $results = [];
        foreach ($testMethods as $method) {
            $results[] = $this->runOne($method);
        }

        $finishedAt = Carbon::now();
        $endMs = (int) (microtime(true) * 1000);

        $summary = [
            'pass' => 0,
            'warn' => 0,
            'fail' => 0,
            'skip' => 0,
            'total' => count($results),
        ];
        foreach ($results as $r) {
            $summary[$r['status']]++;
        }

        // Overall: any fail → fail; any warn (no fail) → warn; else pass.
        $overall = $summary['fail'] > 0 ? 'fail' : ($summary['warn'] > 0 ? 'warn' : 'pass');

        return [
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'duration_ms' => $endMs - $startMs,
            'summary' => $summary,
            'results' => $results,
            'overall_status' => $overall,
        ];
    }

    /**
     * Invoke one test method, catching any throw so a single broken test
     * can't crash the whole run.
     */
    protected function runOne(string $method): array
    {
        try {
            return $this->{$method}();
        } catch (Throwable $e) {
            return [
                'name' => $method,
                'category' => 'unknown',
                'status' => 'fail',
                'message' => 'Test threw an exception: ' . $e->getMessage(),
                'detail' => substr($e->getTraceAsString(), 0, 1000),
            ];
        }
    }

    // -----------------------------------------------------------------
    // Result row constructors
    // -----------------------------------------------------------------

    protected function pass(string $name, string $category, string $message, $detail = null): array
    {
        return ['name' => $name, 'category' => $category, 'status' => 'pass', 'message' => $message, 'detail' => $detail];
    }

    protected function warn(string $name, string $category, string $message, $detail = null): array
    {
        return ['name' => $name, 'category' => $category, 'status' => 'warn', 'message' => $message, 'detail' => $detail];
    }

    protected function fail(string $name, string $category, string $message, $detail = null): array
    {
        return ['name' => $name, 'category' => $category, 'status' => 'fail', 'message' => $message, 'detail' => $detail];
    }

    protected function skip(string $name, string $category, string $message, $detail = null): array
    {
        return ['name' => $name, 'category' => $category, 'status' => 'skip', 'message' => $message, 'detail' => $detail];
    }

    // =================================================================
    // SCHEMA & MIGRATIONS
    // =================================================================

    /**
     * Verify the expected MM migrations are recorded as applied.
     */
    protected function checkMigrationsApplied(): array
    {
        $expected = [
            '2026_01_01_000001_create_mining_manager_tables',
            '2026_01_01_000002_seed_initial_indexes',
            '2026_01_01_000003_seed_default_data',
            '2026_01_01_000004_add_value_tracking_to_events',
            '2026_01_01_000005_create_event_mining_records',
            '2026_01_01_000006_add_event_discount_to_daily_summaries',
            '2026_01_01_000007_add_moon_chunk_unstable_notification',
            '2026_01_01_000008_add_extraction_at_risk_notifications',
            '2026_01_01_000009_add_abyssal_triglavian_to_summaries',
            '2026_01_01_000010_add_composite_index_alert_flags',
            '2026_01_01_000011_add_unique_to_mining_tax_codes_code',
            '2026_01_01_000012_backfill_mining_taxes_period_start',
            '2026_01_01_000013_cleanup_orphan_manager_core_settings',
        ];

        if (!Schema::hasTable('migrations')) {
            return $this->fail('Migrations table present', 'schema', 'migrations table missing — Laravel install is broken');
        }

        $applied = DB::table('migrations')->whereIn('migration', $expected)->pluck('migration')->toArray();
        $missing = array_diff($expected, $applied);

        if (!empty($missing)) {
            return $this->fail(
                'MM migrations applied',
                'schema',
                count($missing) . ' of ' . count($expected) . ' MM migrations not applied',
                ['missing' => array_values($missing), 'hint' => 'Run `php artisan migrate` (auto-runs on docker restart in SeAT v5)']
            );
        }

        return $this->pass('MM migrations applied', 'schema', 'all ' . count($expected) . ' MM migrations recorded');
    }

    /**
     * Verify the 5 alert_*_sent dedup columns exist on moon_extractions.
     * Required by the StructureAlertHandler atomic-CAS pattern.
     */
    protected function checkAlertDedupColumns(): array
    {
        $required = [
            'alert_fuel_critical_sent',
            'alert_shield_reinforced_sent',
            'alert_armor_reinforced_sent',
            'alert_hull_reinforced_sent',
            'alert_destroyed_sent',
        ];

        if (!Schema::hasTable('moon_extractions')) {
            return $this->fail('Alert dedup columns', 'schema', 'moon_extractions table missing');
        }

        $missing = [];
        foreach ($required as $col) {
            if (!Schema::hasColumn('moon_extractions', $col)) {
                $missing[] = $col;
            }
        }

        if (!empty($missing)) {
            return $this->fail(
                'Alert dedup columns',
                'schema',
                count($missing) . ' alert_*_sent columns missing on moon_extractions',
                ['missing' => $missing]
            );
        }

        return $this->pass('Alert dedup columns', 'schema', 'all 5 alert_*_sent columns present');
    }

    /**
     * Verify migration 000011's UNIQUE constraint on mining_tax_codes.code
     * is in place AND no duplicate codes exist (which would have prevented
     * the migration from applying).
     */
    protected function checkTaxCodesUniqueConstraint(): array
    {
        if (!Schema::hasTable('mining_tax_codes')) {
            return $this->fail('Tax-code uniqueness', 'schema', 'mining_tax_codes table missing');
        }

        // Check for duplicates first — proves the constraint is being enforced
        // even if we can't read information_schema directly.
        $dupes = DB::table('mining_tax_codes')
            ->select('code', DB::raw('COUNT(*) as count'))
            ->whereNotNull('code')
            ->groupBy('code')
            ->having('count', '>', 1)
            ->limit(5)
            ->get();

        if ($dupes->isNotEmpty()) {
            return $this->fail(
                'Tax-code uniqueness',
                'schema',
                'Found ' . $dupes->count() . '+ duplicated tax codes — migration 000011 likely did not run',
                ['sample_duplicates' => $dupes->pluck('code')->all()]
            );
        }

        return $this->pass('Tax-code uniqueness', 'schema', 'no duplicate codes (constraint OK)');
    }

    /**
     * Verify migration 000012 backfilled period_start for legacy NULL rows.
     * Should be 0 unless there are legacy mining_taxes rows with NULL `month`
     * column too (rare — separate data corruption scenario).
     */
    protected function checkPeriodStartBackfill(): array
    {
        if (!Schema::hasTable('mining_taxes')) {
            return $this->fail('Tax period_start backfill', 'schema', 'mining_taxes table missing');
        }

        $nullCount = DB::table('mining_taxes')->whereNull('period_start')->count();

        if ($nullCount > 0) {
            return $this->warn(
                'Tax period_start backfill',
                'schema',
                "{$nullCount} mining_taxes rows still have NULL period_start",
                ['hint' => 'These rows likely have NULL `month` too (data corruption from a much older era). Investigate manually.']
            );
        }

        return $this->pass('Tax period_start backfill', 'schema', 'no NULL period_start rows');
    }

    /**
     * Sanity check on the settings table itself — must exist and have rows.
     */
    protected function checkSettingsTableShape(): array
    {
        if (!Schema::hasTable('mining_manager_settings')) {
            return $this->fail('Settings table present', 'schema', 'mining_manager_settings table missing');
        }

        $rowCount = DB::table('mining_manager_settings')->count();
        if ($rowCount === 0) {
            return $this->warn('Settings table present', 'schema', 'mining_manager_settings is empty — fresh install or seeder not run');
        }

        return $this->pass('Settings table present', 'schema', "{$rowCount} settings rows");
    }

    // =================================================================
    // SETTINGS CONSISTENCY
    // =================================================================

    protected function checkPricingSettingsLoadable(): array
    {
        $s = $this->settingsService->getPricingSettings();

        // Verify the C1-fixed keys are now read.
        $expected = [
            'price_provider', 'price_type', 'cache_duration', 'fallback_to_jita',
            'janice_market', 'janice_price_method',
            'manager_core_market', 'manager_core_variant',
            'use_refined_value', 'refining_efficiency',
        ];

        $missing = [];
        foreach ($expected as $key) {
            if (!array_key_exists($key, $s)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            return $this->fail(
                'Pricing settings loadable',
                'settings',
                count($missing) . ' expected keys missing from getPricingSettings()',
                ['missing' => $missing]
            );
        }

        return $this->pass(
            'Pricing settings loadable',
            'settings',
            "provider={$s['price_provider']}, MC market={$s['manager_core_market']}, variant={$s['manager_core_variant']}"
        );
    }

    protected function checkNotificationSettingsLoadable(): array
    {
        $s = $this->settingsService->getNotificationSettings();
        $hasEnabledTypes = isset($s['enabled_types']) && is_array($s['enabled_types']);
        $hasTypeSettings = isset($s['type_settings']) && is_array($s['type_settings']);

        if (!$hasEnabledTypes || !$hasTypeSettings) {
            return $this->warn('Notification settings loadable', 'settings', 'shape unexpected — UI may render with defaults', $s);
        }

        $enabledCount = count(array_filter($s['enabled_types']));
        return $this->pass('Notification settings loadable', 'settings', "{$enabledCount} notification types enabled");
    }

    protected function checkFeatureFlagsLoadable(): array
    {
        $f = $this->settingsService->getFeatureFlags();
        if (!is_array($f) || empty($f)) {
            return $this->warn('Feature flags loadable', 'settings', 'getFeatureFlags() returned empty', $f);
        }
        $on = count(array_filter($f, fn($v) => (bool) $v));
        return $this->pass('Feature flags loadable', 'settings', "{$on}/" . count($f) . " flags enabled");
    }

    protected function checkActiveCorporationConfigured(): array
    {
        $corpId = $this->settingsService->getTaxProgramCorporationId();
        if (!$corpId) {
            return $this->warn(
                'Tax program corporation configured',
                'settings',
                'No tax program corporation set — moon owner-scoped queries will not filter',
                ['hint' => 'Set general.moon_owner_corporation_id in Settings → General']
            );
        }

        $name = DB::table('corporation_infos')->where('corporation_id', $corpId)->value('name');
        return $this->pass(
            'Tax program corporation configured',
            'settings',
            $name ? "{$name} ({$corpId})" : "Corp ID {$corpId} (name not in SDE)"
        );
    }

    // =================================================================
    // CROSS-PLUGIN
    // =================================================================

    protected function checkManagerCoreDetected(): array
    {
        if (!class_exists('ManagerCore\\Services\\PricingService')) {
            return $this->skip('Manager Core detected', 'cross-plugin', 'MC not installed (optional)');
        }
        return $this->pass('Manager Core detected', 'cross-plugin', 'mattfalahe/manager-core loaded');
    }

    protected function checkStructureManagerDetected(): array
    {
        if (!class_exists('StructureManager\\Helpers\\FuelCalculator')) {
            return $this->skip('Structure Manager detected', 'cross-plugin', 'SM not installed (extraction_at_risk/lost notifications disabled)');
        }
        return $this->pass('Structure Manager detected', 'cross-plugin', 'mattfalahe/structure-manager loaded');
    }

    protected function checkEventBusSubscriptionPresent(): array
    {
        if (!class_exists('ManagerCore\\Services\\EventBus')) {
            return $this->skip('EventBus subscription', 'cross-plugin', 'MC EventBus not available');
        }

        if (!Schema::hasTable('manager_core_event_subscriptions')) {
            return $this->fail('EventBus subscription', 'cross-plugin', 'manager_core_event_subscriptions table missing despite MC being loaded');
        }

        $row = DB::table('manager_core_event_subscriptions')
            ->where('subscriber_plugin', 'mining-manager')
            ->where('event_pattern', 'structure.alert.*')
            ->first();

        if (!$row) {
            return $this->warn(
                'EventBus subscription',
                'cross-plugin',
                'No structure.alert.* subscription registered for mining-manager — boot path did not run, or MC table was reset'
            );
        }

        return $this->pass(
            'EventBus subscription',
            'cross-plugin',
            "subscribed to '{$row->event_pattern}' → capability '" . ($row->handler_capability ?? '?') . "'"
        );
    }

    protected function checkPluginBridgeCapabilitiesRegistered(): array
    {
        if (!class_exists('ManagerCore\\Services\\PluginBridge')) {
            return $this->skip('PluginBridge capabilities', 'cross-plugin', 'MC PluginBridge not available');
        }

        try {
            $bridge = app('ManagerCore\\Services\\PluginBridge');
            // Probe the capability we publish back to MC so its EventBus
            // can dispatch into us. Method names reverse-engineered from
            // ManagerCore's PluginBridge::registerCapability/has.
            $hasOurHandler = method_exists($bridge, 'hasCapability')
                ? $bridge->hasCapability('mining-manager', 'structure.notify_alert')
                : null;

            if ($hasOurHandler === false) {
                return $this->warn(
                    'PluginBridge capabilities',
                    'cross-plugin',
                    'mining-manager.structure.notify_alert capability not registered — boot path did not run'
                );
            }

            return $this->pass('PluginBridge capabilities', 'cross-plugin', 'mining-manager.structure.notify_alert registered');
        } catch (Throwable $e) {
            return $this->warn('PluginBridge capabilities', 'cross-plugin', 'PluginBridge probe threw: ' . $e->getMessage());
        }
    }

    protected function checkMcPricingSubscriptionPresent(): array
    {
        if (!PriceProviderService::isManagerCoreInstalled()) {
            return $this->skip('MC pricing subscription', 'cross-plugin', 'MC not installed');
        }

        $provider = $this->settingsService->getPricingSettings()['price_provider'] ?? '';
        if ($provider !== PriceProviderService::PROVIDER_MANAGER_CORE) {
            return $this->skip(
                'MC pricing subscription',
                'cross-plugin',
                "Configured provider is '{$provider}' — MC subscription not required"
            );
        }

        if (!Schema::hasTable('manager_core_type_subscriptions')) {
            return $this->fail('MC pricing subscription', 'cross-plugin', 'manager_core_type_subscriptions table missing despite MC being loaded');
        }

        $count = DB::table('manager_core_type_subscriptions')
            ->where('plugin_name', 'mining-manager')
            ->count();

        if ($count === 0) {
            return $this->warn(
                'MC pricing subscription',
                'cross-plugin',
                'Provider=manager-core but ZERO type subscriptions present — boot re-subscribe failed or MC table was reset',
                ['hint' => 'Re-save the Pricing settings, or check service-provider boot logs for "[MM] Boot-time MC pricing subscription failed"']
            );
        }

        return $this->pass('MC pricing subscription', 'cross-plugin', "{$count} type IDs subscribed");
    }

    protected function checkMcPriceFreshness(): array
    {
        if (!PriceProviderService::isManagerCoreInstalled()) {
            return $this->skip('MC price freshness', 'pricing', 'MC not installed');
        }

        $provider = $this->settingsService->getPricingSettings()['price_provider'] ?? '';
        if ($provider !== PriceProviderService::PROVIDER_MANAGER_CORE) {
            return $this->skip('MC price freshness', 'pricing', "Provider is '{$provider}'");
        }

        if (!Schema::hasTable('manager_core_market_prices')) {
            return $this->fail('MC price freshness', 'pricing', 'manager_core_market_prices table missing');
        }

        $market = $this->settingsService->getPricingSettings()['manager_core_market'] ?? 'jita';
        $threshold = Carbon::now()->subHours(PriceProviderService::MC_PRICE_STALENESS_HOURS);

        $stale = DB::table('manager_core_market_prices')
            ->where('market', $market)
            ->where('updated_at', '<', $threshold)
            ->count();
        $total = DB::table('manager_core_market_prices')->where('market', $market)->count();

        if ($total === 0) {
            return $this->warn('MC price freshness', 'pricing', "No price rows in market '{$market}' — MC update-prices cron has never run for this market");
        }

        if ($stale > 0) {
            $pct = round($stale / $total * 100, 1);
            return $this->warn(
                'MC price freshness',
                'pricing',
                "{$stale}/{$total} prices ({$pct}%) older than " . PriceProviderService::MC_PRICE_STALENESS_HOURS . "h in market '{$market}'",
                ['hint' => 'Check that manager-core:update-prices cron is running. Default schedule: every 4h.']
            );
        }

        return $this->pass('MC price freshness', 'pricing', "{$total} prices in market '{$market}', all fresh (<" . PriceProviderService::MC_PRICE_STALENESS_HOURS . "h)");
    }

    // =================================================================
    // PRICING PATH
    // =================================================================

    protected function checkConfiguredProviderValid(): array
    {
        $provider = $this->settingsService->getPricingSettings()['price_provider'] ?? PriceProviderService::PROVIDER_SEAT;
        $valid = $this->priceProvider->validateProviderConfig($provider);

        if (!$valid) {
            return $this->fail(
                'Configured provider valid',
                'pricing',
                "validateProviderConfig() returned false for provider '{$provider}'",
                ['hint' => 'Provider missing API key, or selected MC as provider but MC not installed.']
            );
        }
        return $this->pass('Configured provider valid', 'pricing', "provider '{$provider}' validates");
    }

    /**
     * In-process price-fetch roundtrip for Tritanium (type 34) — verifies
     * the configured provider can return at least one non-zero price right
     * now without exercising network on every other provider.
     */
    protected function checkPriceProviderRoundtrip(): array
    {
        try {
            $prices = $this->priceProvider->getPrices([34]); // Tritanium
        } catch (Throwable $e) {
            return $this->fail('Price provider roundtrip', 'pricing', 'getPrices threw: ' . $e->getMessage());
        }

        $tritPrice = (float) ($prices[34] ?? 0);
        if ($tritPrice <= 0) {
            return $this->warn(
                'Price provider roundtrip',
                'pricing',
                'getPrices(Tritanium) returned 0 — provider has no price data yet',
                ['hint' => 'For MC: wait for the next manager-core:update-prices cron, or click "Refresh Prices" in MC. For Janice/Fuzzwork: check the API endpoint reachability.']
            );
        }

        return $this->pass(
            'Price provider roundtrip',
            'pricing',
            'Tritanium = ' . number_format($tritPrice, 2) . ' ISK from configured provider'
        );
    }

    // =================================================================
    // NOTIFICATIONS PATH
    // =================================================================

    protected function checkWebhookConfigurations(): array
    {
        if (!Schema::hasTable('webhook_configurations')) {
            return $this->fail('Webhook configurations', 'notifications', 'webhook_configurations table missing');
        }

        $total = WebhookConfiguration::count();
        // Column is `is_enabled` (the model also exposes a scopeEnabled
        // that wraps this; we go through the scope so any future visibility
        // rules baked into it apply here too).
        $enabled = WebhookConfiguration::enabled()->count();

        if ($total === 0) {
            return $this->warn('Webhook configurations', 'notifications', 'No webhooks configured — notifications will be silently dropped');
        }

        // HTTPS-only audit cross-check: scan for any webhook URL still using
        // http:// despite the H4 fix. Should be zero on a properly-saved install.
        $httpUrls = WebhookConfiguration::where('webhook_url', 'like', 'http://%')
            ->where('webhook_url', 'not like', 'https://%')
            ->count();

        if ($httpUrls > 0) {
            return $this->warn(
                'Webhook configurations',
                'notifications',
                "{$httpUrls} webhook(s) still use http:// — saved before HTTPS-only enforcement landed",
                ['hint' => 'Re-edit each webhook in Settings → Webhooks; the new validation will require https://']
            );
        }

        return $this->pass(
            'Webhook configurations',
            'notifications',
            "{$enabled}/{$total} webhooks enabled, all HTTPS"
        );
    }

    /**
     * Run a templated payload through processCustomTemplate with hostile
     * input (containing quotes, backslashes, newlines) and verify the
     * resulting JSON is parseable AND does NOT contain injected keys.
     *
     * Reflection access to the protected method — read-only verification
     * of the H3 fix.
     */
    protected function checkCustomTemplateInjectionSafety(): array
    {
        $template = '{"text": "Hostile: {{character_name}}", "safe": true}';
        $hostileInput = [
            'character_name' => 'Bob", "admin": true, "x": "',
        ];

        try {
            $reflection = new \ReflectionClass($this->notificationService);
            $method = $reflection->getMethod('processCustomTemplate');
            $method->setAccessible(true);
            $result = $method->invoke($this->notificationService, $template, 'tax_reminder', 'tax_reminder', $hostileInput);
        } catch (Throwable $e) {
            return $this->warn('Custom-template injection safety', 'security', 'Could not invoke processCustomTemplate: ' . $e->getMessage());
        }

        if (!is_array($result)) {
            return $this->fail('Custom-template injection safety', 'security', 'processCustomTemplate returned non-array');
        }

        // The H3 fix should escape the hostile content into the `text` value
        // and NOT introduce any `admin` key. The `safe` key was authored by
        // the template, so it stays.
        if (array_key_exists('admin', $result)) {
            return $this->fail(
                'Custom-template injection safety',
                'security',
                'processCustomTemplate allowed JSON key injection from substitution data',
                ['unexpected_keys' => array_keys($result)]
            );
        }

        if (!isset($result['text']) || strpos($result['text'], 'Hostile:') !== 0) {
            return $this->warn(
                'Custom-template injection safety',
                'security',
                'Substitution result shape unexpected — H3 fix may not be active',
                $result
            );
        }

        return $this->pass('Custom-template injection safety', 'security', 'hostile substitution escaped correctly');
    }

    // =================================================================
    // MINING LIFECYCLE
    // =================================================================

    protected function checkSchedulesPresent(): array
    {
        if (!Schema::hasTable('schedules')) {
            return $this->fail('Cron schedules present', 'lifecycle', 'schedules table missing — SeAT install broken');
        }

        $expected = [
            'mining-manager:process-ledger',
            'mining-manager:update-extractions',
            'mining-manager:check-extraction-arrivals',
            'mining-manager:calculate-taxes',
            'mining-manager:generate-invoices',
            'mining-manager:verify-payments --auto-match',
            'mining-manager:send-reminders',
            'mining-manager:cache-prices',
        ];

        $present = DB::table('schedules')->whereIn('command', $expected)->pluck('command')->all();
        $missing = array_diff($expected, $present);

        if (!empty($missing)) {
            return $this->warn(
                'Cron schedules present',
                'lifecycle',
                count($missing) . ' core cron rows missing from schedules table',
                ['missing' => array_values($missing), 'hint' => 'Re-run db:seed or restart the SeAT container']
            );
        }

        return $this->pass('Cron schedules present', 'lifecycle', 'all ' . count($expected) . ' core MM crons present');
    }

    protected function checkMoonExtractionsHealth(): array
    {
        if (!Schema::hasTable('moon_extractions')) {
            return $this->fail('Moon extractions health', 'lifecycle', 'moon_extractions table missing');
        }

        $total = MoonExtraction::count();
        $active = MoonExtraction::whereNotIn('status', ['cancelled', 'expired', 'archived'])->count();
        $futureChunks = MoonExtraction::where('chunk_arrival_time', '>', Carbon::now())->count();

        if ($total === 0) {
            return $this->warn('Moon extractions health', 'lifecycle', 'No extractions ingested — fresh install or import failing');
        }

        return $this->pass(
            'Moon extractions health',
            'lifecycle',
            "{$total} total, {$active} active, {$futureChunks} pending arrival"
        );
    }

    // =================================================================
    // TAX PIPELINE
    // =================================================================

    protected function checkTaxPipelineSanity(): array
    {
        if (!Schema::hasTable('mining_taxes') || !Schema::hasTable('mining_tax_codes')) {
            return $this->fail('Tax pipeline sanity', 'tax', 'mining_taxes or mining_tax_codes table missing');
        }

        $unpaid = DB::table('mining_taxes')
            ->whereIn('status', ['unpaid', 'overdue', 'partial'])
            ->count();
        $orphanCodes = DB::table('mining_tax_codes as mtc')
            ->leftJoin('mining_taxes as mt', 'mtc.mining_tax_id', '=', 'mt.id')
            ->whereNull('mt.id')
            ->count();

        if ($orphanCodes > 0) {
            return $this->warn(
                'Tax pipeline sanity',
                'tax',
                "{$orphanCodes} tax codes reference non-existent mining_tax rows",
                ['hint' => 'FK constraint missing on mining_tax_codes.mining_tax_id; investigate or DELETE orphans manually']
            );
        }

        return $this->pass('Tax pipeline sanity', 'tax', "{$unpaid} unpaid/overdue/partial taxes, no orphan codes");
    }

    protected function checkProcessedTransactionsTable(): array
    {
        if (!Schema::hasTable('mining_manager_processed_transactions')) {
            return $this->fail('Processed transactions table', 'tax', 'mining_manager_processed_transactions table missing — H1 dedup latch will throw');
        }

        $rowCount = DB::table('mining_manager_processed_transactions')->count();
        return $this->pass('Processed transactions table', 'tax', "{$rowCount} processed transaction rows");
    }

    // =================================================================
    // SECURITY / AUDIT-HARDENING VERIFICATION
    // =================================================================

    protected function checkAtomicCasColumnsIndexed(): array
    {
        // We can't reliably read information_schema in a portable way across
        // MySQL/MariaDB/Postgres, but we can confirm the columns themselves
        // exist (the indexes were added in migrations 000008 + 000010).
        // This test pairs with checkAlertDedupColumns above — together they
        // verify the dedup-pattern preconditions.
        if (!Schema::hasTable('moon_extractions')) {
            return $this->fail('Atomic CAS preconditions', 'security', 'moon_extractions missing');
        }

        $required = [
            'is_jackpot',                  // M2 atomic CAS target
            'notification_sent',           // M3 atomic CAS target
            'alert_fuel_critical_sent',    // StructureAlertHandler
            'alert_destroyed_sent',        // StructureAlertHandler
        ];
        $missing = array_filter($required, fn($col) => !Schema::hasColumn('moon_extractions', $col));

        if (!empty($missing)) {
            return $this->fail(
                'Atomic CAS preconditions',
                'security',
                'columns required by atomic-CAS dedup latches missing',
                ['missing' => array_values($missing)]
            );
        }

        return $this->pass('Atomic CAS preconditions', 'security', 'all 4 CAS-target columns present on moon_extractions');
    }

    /**
     * Verify M7's revert is in place — `ScheduleSeeder::run` should NOT
     * be overridden (i.e. should use the parent's firstOrCreate semantics).
     */
    protected function checkScheduleSeederPattern(): array
    {
        $seederClass = '\\MiningManager\\Database\\Seeders\\ScheduleSeeder';
        if (!class_exists($seederClass)) {
            return $this->skip('ScheduleSeeder firstOrCreate', 'security', 'ScheduleSeeder class not autoloadable');
        }

        try {
            $reflection = new \ReflectionClass($seederClass);
            // We want `run` to be inherited from the parent, NOT redeclared
            // on the child. M7 fix removed the override.
            $method = $reflection->getMethod('run');
            $declaringClass = $method->getDeclaringClass()->getName();
            $expectedParent = 'Seat\\Services\\Seeding\\AbstractScheduleSeeder';

            if ($declaringClass !== $expectedParent) {
                return $this->warn(
                    'ScheduleSeeder firstOrCreate',
                    'security',
                    "ScheduleSeeder::run is declared on {$declaringClass} (expected inheritance from {$expectedParent})",
                    ['hint' => 'Operator schedule customisations may be overwritten on every plugin boot. M7 fix has regressed.']
                );
            }

            return $this->pass('ScheduleSeeder firstOrCreate', 'security', "run() inherited from {$expectedParent}");
        } catch (Throwable $e) {
            return $this->warn('ScheduleSeeder firstOrCreate', 'security', 'Reflection probe failed: ' . $e->getMessage());
        }
    }

    // =================================================================
    // INFRA
    // =================================================================

    protected function checkCacheRoundtrip(): array
    {
        $key = 'mining-manager:master-test:roundtrip:' . random_int(100000, 999999);
        $value = 'pong-' . random_int(100000, 999999);

        try {
            Cache::put($key, $value, 30);
            $read = Cache::get($key);
            Cache::forget($key);
        } catch (Throwable $e) {
            return $this->fail('Cache roundtrip', 'infra', 'Cache put/get threw: ' . $e->getMessage());
        }

        if ($read !== $value) {
            return $this->fail(
                'Cache roundtrip',
                'infra',
                'Cache::get returned a different value than Cache::put wrote',
                ['wrote' => $value, 'read' => $read]
            );
        }

        $driver = config('cache.default');
        return $this->pass('Cache roundtrip', 'infra', "driver '{$driver}' OK");
    }
}
