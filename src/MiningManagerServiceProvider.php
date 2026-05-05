<?php

namespace MiningManager;

use Seat\Services\AbstractSeatPlugin;
use MiningManager\Console\Commands\ProcessMiningLedgerCommand;
use MiningManager\Console\Commands\BackfillOreTypeFlagsCommand;
use MiningManager\Console\Commands\CalculateMonthlyTaxesCommand;
use MiningManager\Console\Commands\CalculateMonthlyStatisticsCommand;
use MiningManager\Console\Commands\GenerateTaxInvoicesCommand;
use MiningManager\Console\Commands\UpdateMiningEventsCommand;
use MiningManager\Console\Commands\GenerateReportsCommand;
use MiningManager\Console\Commands\VerifyWalletPaymentsCommand;
use MiningManager\Console\Commands\SendTaxRemindersCommand;
use MiningManager\Console\Commands\UpdateMoonExtractionsCommand;
use MiningManager\Console\Commands\CheckExtractionArrivalsCommand;
use MiningManager\Console\Commands\BackfillExtractionHistoryCommand;
use MiningManager\Console\Commands\BackfillEventRecordsCommand;
use MiningManager\Console\Commands\DetectJackpotsCommand;
use MiningManager\Console\Commands\InitializeCommand;
use MiningManager\Console\Commands\CachePriceDataCommand;
use MiningManager\Console\Commands\DiagnosePricesCommand;
use MiningManager\Console\Commands\DiagnoseAffiliationCommand;
use MiningManager\Console\Commands\DiagnoseCharacterCommand;
use MiningManager\Console\Commands\DiagnoseMoonExtractionsCommand;
use MiningManager\Console\Commands\DiagnoseTypeIdsCommand;
use MiningManager\Console\Commands\GenerateTestDataCommand;
use MiningManager\Console\Commands\RecalculateExtractionValuesCommand;
use MiningManager\Console\Commands\ArchiveOldExtractionsCommand;
use MiningManager\Console\Commands\BackfillExtractionNotificationsCommand;
use MiningManager\Console\Commands\DetectMoonTheftCommand;
use MiningManager\Console\Commands\MonitorActiveTheftsCommand;
use MiningManager\Console\Commands\FinalizeMonthCommand;
use MiningManager\Console\Commands\UpdateLedgerPricesCommand;
use MiningManager\Console\Commands\UpdateDailySummariesCommand;
use MiningManager\Console\Commands\ImportCharacterMiningCommand;
use MiningManager\Console\Commands\GenerateTaxCodesCommand;
use MiningManager\Console\Commands\BackupDataCommand;
use MiningManager\Console\Commands\RestoreDataCommand;
use MiningManager\Database\Seeders\ScheduleSeeder;
use Illuminate\Support\Facades\Event;

// Import Events

use Seat\Eveapi\Events\CharacterWalletJournalUpdated;

// Import Listeners

use MiningManager\Listeners\ProcessWalletJournalListener;

class MiningManagerServiceProvider extends AbstractSeatPlugin
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Check if routes are cached before loading
        if (!$this->app->routesAreCached()) {
            include __DIR__ . '/Http/routes.php';
        }
        
        $this->loadTranslationsFrom(__DIR__ . '/Resources/lang/', 'mining-manager');
        $this->loadViewsFrom(__DIR__ . '/Resources/views/', 'mining-manager');

        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations/');

        // Register Blade directives for consistent formatting
        \Illuminate\Support\Facades\Blade::directive('isk', function ($expression) {
            return "<?php
                \$__isk_val = (float)($expression);
                if (\$__isk_val >= 1000000000) {
                    echo number_format(\$__isk_val / 1000000000, 2) . 'B ISK';
                } elseif (\$__isk_val >= 1000000) {
                    echo number_format(\$__isk_val / 1000000, 2) . 'M ISK';
                } else {
                    echo number_format(\$__isk_val, 0) . ' ISK';
                }
            ?>";
        });

        // Standard date format: "Jan 15, 2026 14:30"
        \Illuminate\Support\Facades\Blade::directive('miningDate', function ($expression) {
            return "<?php echo ($expression) ? \Carbon\Carbon::parse($expression)->format('M d, Y H:i') : '-'; ?>";
        });

        // Short date format: "Jan 15, 2026"
        \Illuminate\Support\Facades\Blade::directive('miningDateShort', function ($expression) {
            return "<?php echo ($expression) ? \Carbon\Carbon::parse($expression)->format('M d, Y') : '-'; ?>";
        });

        // Register event listeners
        $this->registerEventListeners();

        // Register Manager Core capability + EventBus subscription for
        // Structure Manager's `structure.alert.*` threat events. No-op if
        // either MC or SM is missing — plugin still works standalone.
        $this->registerCrossPluginStructureAlerts();

        // Idempotently re-register MM's pricing type subscriptions with
        // Manager Core when MC is the chosen provider. Without this,
        // subscriptions only ever happen on the settings-save path, so
        // installing MC AFTER MM (a common ops sequence) leaves MC's
        // scheduler with zero MM type IDs to fetch. No-op when MC is
        // absent, when the chosen provider isn't 'manager-core', or when
        // anything throws — the plugin continues to function standalone.
        $this->registerCrossPluginPricingSubscription();

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessMiningLedgerCommand::class,
                BackfillOreTypeFlagsCommand::class,
                CalculateMonthlyTaxesCommand::class,
                CalculateMonthlyStatisticsCommand::class,
                GenerateTaxInvoicesCommand::class,
                UpdateMiningEventsCommand::class,
                GenerateReportsCommand::class,
                VerifyWalletPaymentsCommand::class,
                SendTaxRemindersCommand::class,
                UpdateMoonExtractionsCommand::class,
                CheckExtractionArrivalsCommand::class,
                DetectJackpotsCommand::class,
                CachePriceDataCommand::class,
                DiagnosePricesCommand::class,
                DiagnoseAffiliationCommand::class,
                DiagnoseCharacterCommand::class,
                DiagnoseMoonExtractionsCommand::class,
                DiagnoseTypeIdsCommand::class,
                GenerateTestDataCommand::class,
                RecalculateExtractionValuesCommand::class,
                ArchiveOldExtractionsCommand::class,
                BackfillExtractionNotificationsCommand::class,
                BackfillExtractionHistoryCommand::class,
                BackfillEventRecordsCommand::class,
                DetectMoonTheftCommand::class,
                MonitorActiveTheftsCommand::class,
                FinalizeMonthCommand::class,
                UpdateLedgerPricesCommand::class,
                UpdateDailySummariesCommand::class,
                ImportCharacterMiningCommand::class,
                GenerateTaxCodesCommand::class,
                InitializeCommand::class,
                BackupDataCommand::class,
                RestoreDataCommand::class,
            ]);
        }

        // Add publications
        $this->add_publications();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // Register sidebar configuration (SeAT 5.x method)
        $this->mergeConfigFrom(
            __DIR__ . '/Config/Menu/package.sidebar.php',
            'package.sidebar'
        );

        // Register permissions - FIXED: Use correct file in Permissions subfolder
        // SeAT v5 expects permissions to be in /Config/Permissions/ folder
        $this->registerPermissions(
            __DIR__ . '/Config/Permissions/mining-manager.permissions.php',
            'mining-manager'
        );

        // Register config
        $this->mergeConfigFrom(
            __DIR__ . '/Config/mining-manager.config.php',
            'mining-manager'
        );

        // Register service singletons for consistent state across the request lifecycle
        // SettingsManagerService holds activeCorporationId state, so must be singleton
        $this->app->singleton(
            \MiningManager\Services\Configuration\SettingsManagerService::class
        );

        $this->app->singleton(
            \MiningManager\Services\Pricing\PriceProviderService::class
        );

        $this->app->singleton(
            \MiningManager\Services\Pricing\MarketDataService::class
        );

        $this->app->singleton(
            \MiningManager\Services\Pricing\OreValuationService::class
        );

        // Add database seeders
        $this->add_database_seeders();
    }

    /**
     * Register event listeners for the plugin
     * 
     * IMPORTANT: As of v2.0, this plugin uses Corporation Observer data
     * for COMPLETE moon mining tracking (not character ledgers).
     * 
     * The CharacterMiningUpdated listener is kept for backward compatibility
     * but the primary data source is now corporation_industry_mining_observer_data
     * which tracks ALL miners at your structures (not just SeAT users).
     * 
     * @return void
     */
    private function registerEventListeners()
    {
        // Hook into SeAT's character mining job completion
        // SeAT v5 doesn't fire events — we use Queue::after to detect when the job finishes
        \Illuminate\Support\Facades\Queue::after(function (\Illuminate\Queue\Events\JobProcessed $event) {
            $jobName = $event->job->resolveName();

            // Character mining ledger updated — import into our mining_ledger table
            if ($jobName === 'Seat\Eveapi\Jobs\Character\Industry\Mining') {
                try {
                    $payload = $event->job->payload();
                    $command = unserialize($payload['data']['command'] ?? '');

                    // Extract character_id from the job
                    $characterId = $command->character_id ?? ($command->getCharacterId() ?? null);

                    if ($characterId) {
                        \Illuminate\Support\Facades\Log::debug("Mining Manager: SeAT character mining job completed for character {$characterId}, triggering import");
                        \Illuminate\Support\Facades\Artisan::queue('mining-manager:import-character-mining', [
                            '--character_id' => $characterId,
                            '--days' => 7,
                        ]);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::debug("Mining Manager: Could not extract character_id from mining job: " . $e->getMessage());
                }
            }
        });

        // Wallet Journal Updates - Track tax payments
        Event::listen(
            CharacterWalletJournalUpdated::class,
            ProcessWalletJournalListener::class
        );
    }

    /**
     * Register Mining Manager as a subscriber to Structure Manager's
     * `structure.alert.*` events via Manager Core's EventBus. Powers the
     * extraction_at_risk + extraction_lost notifications.
     *
     * No-op if either Manager Core or Structure Manager is missing —
     * Mining Manager continues to function standalone; the relevant
     * notification toggles in settings/webhooks just grey out with a
     * banner so users know what's required.
     *
     * Idempotent: registerCapability is per-request (in-memory), subscribe
     * is persistent via updateOrCreate so repeated boots don't duplicate.
     *
     * @return void
     */
    private function registerCrossPluginStructureAlerts()
    {
        if (!class_exists('ManagerCore\\Services\\PluginBridge')
            || !class_exists('ManagerCore\\Services\\EventBus')
            || !class_exists('StructureManager\\Helpers\\FuelCalculator')) {
            // Silent no-op — not an error, just means the feature isn't available
            return;
        }

        try {
            $bridge = $this->app->make(\ManagerCore\Services\PluginBridge::class);

            // B6: log a clear warning if the installed Manager Core is older
            // than the version that introduced features MM uses (Topics +
            // publishSanitized landed in MC 1.5.0). Doesn't abort — older MC
            // versions still mostly work, the version gate is for diagnostics.
            try {
                $bridge->call('ManagerCore', 'bridge.requireMinimumVersion', '1.5.0', false);
            } catch (\Throwable $e) {
                // bridge.requireMinimumVersion not available on this MC version
                // (added in 1.4.2). Older MC = older features; not fatal.
            }

            // Expose our handler as a PluginBridge capability so MC's
            // EventBus can dispatch to it via the standard capability
            // resolution path. Handler is resolved via service container
            // (auto-wires NotificationService dep).
            $bridge->registerCapability(
                'mining-manager',
                'structure.notify_alert',
                function (string $eventName, string $publisher, array $payload) {
                    $handler = $this->app->make(\MiningManager\Services\Structure\StructureAlertHandler::class);
                    $handler->handle($eventName, $publisher, $payload);
                }
            );

            // B4: expose 2 read-only query capabilities so other plugins
            // (HR Manager, Pings, Buyback Manager, etc.) can ask MM for
            // mining/tax data WITHOUT coupling to MM's DB schema. Returns
            // small DTOs with stable field names so MM can refactor models
            // without breaking consumers.

            $bridge->registerCapability(
                'mining-manager',
                'mining.getCharacterTaxStatus',
                function (int $characterId, ?string $period = null): ?array {
                    try {
                        $query = \MiningManager\Models\MiningTax::where('character_id', $characterId);
                        if ($period !== null) {
                            // Accept 'YYYY-MM' or 'YYYY-MM-DD' or any string Carbon can parse;
                            // store by canonical first-of-month for the comparison.
                            try {
                                $monthDate = \Carbon\Carbon::parse($period)->startOfMonth()->toDateString();
                                $query->whereDate('month', $monthDate);
                            } catch (\Throwable $e) {
                                $query->where('month', $period); // fallback to literal match
                            }
                        } else {
                            $query->orderBy('period_end', 'desc');
                        }
                        $tax = $query->first();
                        if (!$tax) {
                            return null;
                        }
                        return [
                            'character_id' => (int) $tax->character_id,
                            'period'       => optional($tax->month)->toDateString(),
                            'period_start' => optional($tax->period_start)->toDateString(),
                            'period_end'   => optional($tax->period_end)->toDateString(),
                            'amount_owed'  => (float) $tax->amount_owed,
                            'amount_paid'  => (float) $tax->amount_paid,
                            'status'       => $tax->status,
                            'due_date'     => optional($tax->due_date)->toDateString(),
                        ];
                    } catch (\Throwable $e) {
                        return null;
                    }
                }
            );

            $bridge->registerCapability(
                'mining-manager',
                'mining.getCharacterRecentMining',
                function (int $characterId, int $daysBack = 30): array {
                    try {
                        $cutoff = now()->subDays(max(1, min(365, $daysBack)));
                        $row = \MiningManager\Models\MiningLedger::where('character_id', $characterId)
                            ->where('date', '>=', $cutoff->toDateString())
                            ->selectRaw('SUM(quantity) as total_quantity, SUM(total_value) as total_isk_value, COUNT(DISTINCT date) as session_days')
                            ->first();
                        return [
                            'character_id'    => $characterId,
                            'days_back'       => (int) $daysBack,
                            'since'           => $cutoff->toDateString(),
                            'total_quantity'  => (int) ($row->total_quantity ?? 0),
                            'total_isk_value' => (float) ($row->total_isk_value ?? 0),
                            'session_days'    => (int) ($row->session_days ?? 0),
                        ];
                    } catch (\Throwable $e) {
                        return [
                            'character_id' => $characterId,
                            'days_back'    => (int) $daysBack,
                            'error'        => 'unavailable',
                        ];
                    }
                }
            );

            // Persistent subscription — survives restarts. updateOrCreate
            // semantics so repeated boots are safe.
            $eventBus = $this->app->make(\ManagerCore\Services\EventBus::class);
            $eventBus->subscribe(
                'mining-manager',
                'structure.alert.*',
                'structure.notify_alert',
                [
                    'queued' => false,    // Sync dispatch — event volume is tiny (per-structure poll)
                    'priority' => 10,     // Above default 0; threat alerts should fire early
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[MM] Cross-plugin structure alert subscription failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Boot-time idempotent re-subscribe of MM's mining-related type IDs to
     * Manager Core's pricing service.
     *
     * Why this is needed:
     *
     *   Pre-fix the only place that called `subscribeToManagerCore()` was
     *   `SettingsController::updatePricing()` — i.e. when the admin clicked
     *   "Save" on the pricing tab with provider=manager-core. That meant:
     *
     *     - Installing MC AFTER MM was already configured with provider=
     *       manager-core left MC with zero MM subscriptions. MC's
     *       update-prices cron had nothing to fetch for moon ores / fuel
     *       / ice, and MM's reads from `manager_core_market_prices` came
     *       back empty (cascading to all-zero prices in tax invoices,
     *       payouts, ledger valuations).
     *
     *     - Restoring MC's database from a backup older than the last
     *       MM settings-save silently dropped the subscription rows
     *       and the same failure mode kicked in.
     *
     *   Now: every boot, if MC is installed AND the configured provider is
     *   'manager-core', we call `subscribeToManagerCore` with
     *   `$immediateRefresh = false`. The MC-side persistence is
     *   `updateOrCreate` keyed on (plugin_name, type_id, market) so this is
     *   safe to run on every request — duplicate inserts can't happen, and
     *   the cost is one DB write per type per boot which is negligible.
     *
     * Why $immediateRefresh = false here specifically:
     *
     *   With true, MC synchronously fetches prices for any newly-subscribed
     *   types at the moment the registerTypes call returns. For the boot
     *   path (every PHP-FPM request that touches the plugin), that would
     *   mean N synchronous HTTP calls to ESI on every page load. We pick
     *   up new prices via MC's existing 4-hourly `manager-core:update-prices`
     *   cron instead. The settings-save path keeps `$immediateRefresh = true`
     *   so admins clicking "Save" get prices populated by the time the
     *   pricing tab reloads.
     *
     * Failure mode: any exception is caught and logged at warning. The
     * plugin continues to function — the worst case is that we fall back
     * to whichever provider the user has Jita-fallback configured for
     * (typically Fuzzwork or SeAT's own market_prices table).
     *
     * @return void
     */
    private function registerCrossPluginPricingSubscription()
    {
        if (!class_exists('ManagerCore\\Services\\PricingService')) {
            return;
        }

        try {
            $settingsService = $this->app->make(\MiningManager\Services\Configuration\SettingsManagerService::class);
            $pricingSettings = $settingsService->getPricingSettings();

            $provider = $pricingSettings['price_provider'] ?? null;
            if ($provider !== \MiningManager\Services\Pricing\PriceProviderService::PROVIDER_MANAGER_CORE) {
                // Not using MC for pricing — nothing to re-subscribe.
                return;
            }

            $market = $pricingSettings['manager_core_market'] ?? 'jita';

            // Pre-compute a stable signature of "what we'd subscribe right
            // now" and short-circuit when the MC table already matches.
            //
            // Pre-fix this method called subscribeToManagerCore on EVERY
            // boot (every PHP-FPM request), which UPSERTs hundreds of rows
            // into manager_core_type_subscriptions every single time.
            // Even with $immediateRefresh=false (so MC doesn't dispatch a
            // refresh job), that's N row-existence DB writes per request
            // — 50-300ms of extra work for active corps with config-tab
            // loads, dashboard loads, every "view my taxes" page, etc.
            //
            // Now: signature is `<market>:<count>:<hash of typeIds>`. If
            // the cached signature matches what's already in the DB,
            // skip the per-row UPSERT entirely. Re-validates once per
            // hour (Cache TTL) so stale-cache risk is bounded — if MC's
            // table got reset or a registry change shipped that changed
            // the typeId list, the next run after the cache window will
            // re-subscribe naturally.
            $typeIds = \MiningManager\Services\TypeIdRegistry::getTypeIdsByCategory('all');
            $signature = $market . ':' . count($typeIds) . ':' . md5(implode(',', $typeIds));
            $cacheKey = 'mining-manager:mc-subscription-signature';

            if (\Illuminate\Support\Facades\Cache::get($cacheKey) === $signature) {
                // Signature cached and matches → MC table is in sync,
                // no work to do. Cheapest fast-path on every request.
                return;
            }

            // Defensive: also verify the actual count matches what we'd
            // expect, in case MC's table got reset since we cached. This
            // catches the "operator wiped MC and reinstalled with empty
            // table" scenario without waiting an hour for the cache TTL.
            $actualCount = \Illuminate\Support\Facades\DB::table('manager_core_type_subscriptions')
                ->where('plugin_name', 'mining-manager')
                ->where('market', $market)
                ->count();

            if ($actualCount === count($typeIds)) {
                // Counts match — assume the rows are in sync (we don't
                // hash every row's typeId on every request; the upstream
                // signature check + the periodic full re-subscribe via
                // the settings save path catch any drift).
                \Illuminate\Support\Facades\Cache::put($cacheKey, $signature, 3600);
                return;
            }

            // Drift detected (or first boot after install) — do the full
            // subscribe and cache the signature for the next hour.
            $priceProvider = $this->app->make(\MiningManager\Services\Pricing\PriceProviderService::class);
            $priceProvider->subscribeToManagerCore($market, false); // false = no synchronous refresh

            \Illuminate\Support\Facades\Cache::put($cacheKey, $signature, 3600);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[MM] Boot-time MC pricing subscription failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Add content which must be published.
     */
    private function add_publications()
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/Config/mining-manager.config.php' => config_path('mining-manager.php'),
        ], ['config', 'seat']);
        
        // Publish assets
        $this->publishes([
            __DIR__ . '/Resources/assets' => public_path('vendor/mining-manager'),
        ], ['public', 'seat']);
    }

    /**
     * Register database seeders
     */
    private function add_database_seeders()
    {
        $this->registerDatabaseSeeders([
            ScheduleSeeder::class,
        ]);
    }

    /**
     * Get the plugin name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Mining Manager';
    }

    /**
     * Get the plugin repository URL.
     *
     * @return string
     */
    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/MattFalahe/mining-manager';
    }

    /**
     * Get the packagist package name.
     *
     * @return string
     */
    public function getPackagistPackageName(): string
    {
        return 'mining-manager';
    }

    /**
     * Get the packagist vendor name.
     *
     * @return string
     */
    public function getPackagistVendorName(): string
    {
        return 'mattfalahe';
    }
}
