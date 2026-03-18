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
use MiningManager\Console\Commands\DetectJackpotsCommand;
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
                DetectMoonTheftCommand::class,
                MonitorActiveTheftsCommand::class,
                FinalizeMonthCommand::class,
                UpdateLedgerPricesCommand::class,
                UpdateDailySummariesCommand::class,
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
