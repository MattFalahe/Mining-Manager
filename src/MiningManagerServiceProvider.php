<?php

namespace MattFalahe\Seat\MiningManager;

use Seat\Services\AbstractSeatPlugin;

class MiningManagerServiceProvider extends AbstractSeatPlugin
{
    public function boot()
    {
        $this->add_routes();
        $this->add_publications();
        $this->add_views();
        $this->add_translations();
        $this->add_migrations();
        $this->add_commands();
        $this->register_services();
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/mining-manager.config.php', 'mining-manager.config');
        $this->mergeConfigFrom(__DIR__ . '/Config/mining-manager.permissions.php', 'mining-manager.permissions');
        $this->mergeConfigFrom(__DIR__ . '/Config/Menu/package.sidebar.php', 'package.sidebar');
        
        $this->registerPermissions(__DIR__ . '/Config/Permissions/mining-manager.php', 'mining-manager');
    }

    private function register_services()
    {
        $this->app->singleton(Services\MiningAnalytics::class);
        $this->app->singleton(Services\TaxCalculator::class);
        $this->app->singleton(Services\EventManager::class);
        $this->app->singleton(Services\PriceManager::class);
    }

    private function add_routes()
    {
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');
    }

    private function add_commands()
    {
        $this->commands([
            Commands\ProcessMiningLedger::class,
            Commands\CalculateTaxes::class,
            Commands\UpdateEvents::class,
            Commands\GenerateReports::class,
        ]);
    }

    private function add_publications()
    {
        $this->publishes([
            __DIR__ . '/Resources/assets' => public_path('web/assets/mining-manager'),
        ], ['public', 'seat']);
    }

    private function add_views()
    {
        $this->loadViewsFrom(__DIR__ . '/Resources/views', 'mining-manager');
    }

    private function add_translations()
    {
        $this->loadTranslationsFrom(__DIR__ . '/Resources/lang', 'mining-manager');
    }

    private function add_migrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');
    }

    public function getName(): string
    {
        return 'SeAT Corp Mining Manager';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/mattfalahe/seat-corp-mining-manager';
    }

    public function getPackagistPackageName(): string
    {
        return 'seat-corp-mining-manager';
    }

    public function getPackagistVendorName(): string
    {
        return 'mattfalahe';
    }
}
