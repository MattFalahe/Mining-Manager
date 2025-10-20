<?php

namespace MiningManager;

use Seat\Services\AbstractSeatPlugin;
use MiningManager\Console\Commands\ProcessMiningLedgerCommand;
use MiningManager\Console\Commands\CalculateMonthlyTaxesCommand;
use MiningManager\Console\Commands\GenerateTaxInvoicesCommand;
use MiningManager\Console\Commands\UpdateMiningEventsCommand;
use MiningManager\Console\Commands\GenerateReportsCommand;
use MiningManager\Console\Commands\VerifyWalletPaymentsCommand;
use MiningManager\Console\Commands\SendTaxRemindersCommand;
use MiningManager\Console\Commands\UpdateMoonExtractionsCommand;
use MiningManager\Console\Commands\CachePriceDataCommand;
use Illuminate\Support\Facades\Event;

// Import Events
use Seat\Eveapi\Events\CharacterMiningLedgerUpdated;
use Seat\Eveapi\Events\CharacterWalletJournalUpdated;
use Seat\Eveapi\Events\CharacterContractsUpdated;

// Import Listeners
use MiningManager\Listeners\ProcessMiningLedgerListener;
use MiningManager\Listeners\ProcessWalletJournalListener;
use MiningManager\Listeners\ProcessContractUpdatesListener;

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

        // Register event listeners
        $this->registerEventListeners();

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessMiningLedgerCommand::class,
                CalculateMonthlyTaxesCommand::class,
                GenerateTaxInvoicesCommand::class,
                UpdateMiningEventsCommand::class,
                GenerateReportsCommand::class,
                VerifyWalletPaymentsCommand::class,
                SendTaxRemindersCommand::class,
                UpdateMoonExtractionsCommand::class,
                CachePriceDataCommand::class,
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
        // Register sidebar configuration
        $this->mergeConfigFrom(__DIR__ . '/Config/Menu/package.sidebar.php', 'package.sidebar');
        
        // Register permissions
        $this->registerPermissions(__DIR__ . '/Config/Permissions/mining-manager.php', 'mining-manager');
        
        // Register config
        $this->mergeConfigFrom(__DIR__ . '/Config/mining-manager.config.php', 'mining-manager');

        // Add database seeders
        $this->add_database_seeders();
    }

    /**
     * Register event listeners for the plugin
     * 
     * @return void
     */
    private function registerEventListeners()
    {
        // Mining Ledger Updates - Process mining activity
        Event::listen(
            CharacterMiningLedgerUpdated::class,
            ProcessMiningLedgerListener::class
        );

        // Wallet Journal Updates - Track tax payments
        Event::listen(
            CharacterWalletJournalUpdated::class,
            ProcessWalletJournalListener::class
        );

        // Contract Updates - Process tax invoice contracts
        Event::listen(
            CharacterContractsUpdated::class,
            ProcessContractUpdatesListener::class
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
            // Add seeders here when needed
            // \MiningManager\Database\Seeders\MiningManagerSeeder::class
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
