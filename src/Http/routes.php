<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace' => 'MiningManager\Http\Controllers',
    'prefix' => 'mining-manager',
    'middleware' => ['web', 'auth', 'locale'],
], function () {

    // Dashboard Routes
    Route::group(['prefix' => 'dashboard'], function () {
        Route::get('/', [
            'as' => 'mining-manager.dashboard',
            'uses' => 'DashboardController@index',
            'middleware' => 'can:mining-manager.view',
        ]);

        Route::get('/member', [
            'as' => 'mining-manager.dashboard.member',
            'uses' => 'DashboardController@memberDashboard',
            'middleware' => 'can:mining-manager.view',
        ]);

        Route::get('/director', [
            'as' => 'mining-manager.dashboard.director',
            'uses' => 'DashboardController@directorDashboard',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/metrics', [
            'as' => 'mining-manager.dashboard.metrics',
            'uses' => 'DashboardController@metrics',
            'middleware' => 'can:mining-manager.view',
        ]);

        Route::get('/live-data', [
            'as' => 'mining-manager.dashboard.live-data',
            'uses' => 'DashboardController@getLiveChartData',
            'middleware' => 'can:mining-manager.view',
        ]);

        Route::post('/refresh', [
            'as' => 'mining-manager.dashboard.refresh',
            'uses' => 'DashboardController@refresh',
            'middleware' => 'can:mining-manager.view',
        ]);
    });

    
    // Mining Ledger Routes             
    Route::group(['prefix' => 'ledger'], function () {
        Route::get('/', [
            'as' => 'mining-manager.ledger.index',
            'uses' => 'LedgerController@index',
            'middleware' => 'can:mining-manager.ledger.view',
        ]);

        Route::get('/my-mining', [
            'as' => 'mining-manager.ledger.my-mining',
            'uses' => 'LedgerController@myMining',
            'middleware' => 'can:mining-manager.view',
        ]);

        Route::get('/process', [
            'as' => 'mining-manager.ledger.process',
            'uses' => 'LedgerController@process',
            'middleware' => 'can:mining-manager.ledger.process',
        ]);

        Route::post('/process', [
            'as' => 'mining-manager.ledger.process.submit',
            'uses' => 'LedgerController@processSubmit',
            'middleware' => 'can:mining-manager.ledger.process',
        ]);
    });
    

    // Tax Management Routes
    Route::group(['prefix' => 'tax'], function () {
        Route::get('/', [
            'as' => 'mining-manager.tax.index',
            'uses' => 'TaxController@index',
            'middleware' => 'can:mining-manager.tax.view',
        ]);

        Route::get('/calculate', [
            'as' => 'mining-manager.tax.calculate',
            'uses' => 'TaxController@calculate',
            'middleware' => 'can:mining-manager.tax.calculate',
        ]);

        Route::post('/calculate', [
            'as' => 'mining-manager.tax.calculate.process',
            'uses' => 'TaxController@processCalculation',
            'middleware' => 'can:mining-manager.tax.calculate',
        ]);

        Route::get('/contracts', [
            'as' => 'mining-manager.tax.contracts',
            'uses' => 'TaxController@contracts',
            'middleware' => 'can:mining-manager.tax.generate_invoices',
        ]);

        Route::post('/contracts/generate', [
            'as' => 'mining-manager.tax.contracts.generate',
            'uses' => 'TaxController@generateInvoices',
            'middleware' => 'can:mining-manager.tax.generate_invoices',
        ]);

        Route::get('/wallet', [
            'as' => 'mining-manager.tax.wallet',
            'uses' => 'TaxController@wallet',
            'middleware' => 'can:mining-manager.tax.verify_payments',
        ]);

        Route::post('/wallet/verify', [
            'as' => 'mining-manager.tax.wallet.verify',
            'uses' => 'TaxController@verifyPayments',
            'middleware' => 'can:mining-manager.tax.verify_payments',
        ]);

        Route::get('/details/{characterId}', [
            'as' => 'mining-manager.tax.details',
            'uses' => 'TaxController@details',
            'middleware' => 'can:mining-manager.tax.view',
        ]);

        Route::get('/my-taxes', [
            'as' => 'mining-manager.tax.my-taxes',
            'uses' => 'TaxController@myTaxes',
            'middleware' => 'can:mining-manager.view',
        ]);

        Route::get('/codes', [
            'as' => 'mining-manager.tax.codes',
            'uses' => 'TaxController@codes',
            'middleware' => 'can:mining-manager.tax.view',
        ]);

        Route::post('/codes', [
            'as' => 'mining-manager.tax.codes.store',
            'uses' => 'TaxController@storeCode',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        Route::delete('/codes/{id}', [
            'as' => 'mining-manager.tax.codes.destroy',
            'uses' => 'TaxController@destroyCode',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        Route::post('/{id}/status', [
            'as' => 'mining-manager.tax.update-status',
            'uses' => 'TaxController@updateStatus',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        Route::delete('/{id}', [
            'as' => 'mining-manager.tax.destroy',
            'uses' => 'TaxController@destroy',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);
    });

    // Mining Events Routes
    Route::group(['prefix' => 'events'], function () {
        Route::get('/', [
            'as' => 'mining-manager.events.index',
            'uses' => 'EventController@index',
            'middleware' => 'can:mining-manager.events.view',
        ]);

        Route::get('/create', [
            'as' => 'mining-manager.events.create',
            'uses' => 'EventController@create',
            'middleware' => 'can:mining-manager.events.create',
        ]);

        Route::get('/active', [
            'as' => 'mining-manager.events.active',
            'uses' => 'EventController@active',
            'middleware' => 'can:mining-manager.events.view',
        ]);

        Route::get('/calendar', [
            'as' => 'mining-manager.events.calendar',
            'uses' => 'EventController@calendar',
            'middleware' => 'can:mining-manager.events.view',
        ]);

        Route::get('/my-events', [
            'as' => 'mining-manager.events.my-events',
            'uses' => 'EventController@myEvents',
            'middleware' => 'can:mining-manager.events.view',
        ]);

        Route::post('/', [
            'as' => 'mining-manager.events.store',
            'uses' => 'EventController@store',
            'middleware' => 'can:mining-manager.events.create',
        ]);

        Route::get('/{id}', [
            'as' => 'mining-manager.events.show',
            'uses' => 'EventController@show',
            'middleware' => 'can:mining-manager.events.view',
        ]);

        Route::get('/{id}/edit', [
            'as' => 'mining-manager.events.edit',
            'uses' => 'EventController@edit',
            'middleware' => 'can:mining-manager.events.edit',
        ]);

        Route::put('/{id}', [
            'as' => 'mining-manager.events.update',
            'uses' => 'EventController@update',
            'middleware' => 'can:mining-manager.events.edit',
        ]);

        Route::delete('/{id}', [
            'as' => 'mining-manager.events.destroy',
            'uses' => 'EventController@destroy',
            'middleware' => 'can:mining-manager.events.delete',
        ]);

        Route::post('/{id}/start', [
            'as' => 'mining-manager.events.start',
            'uses' => 'EventController@start',
            'middleware' => 'can:mining-manager.events.edit',
        ]);

        Route::post('/{id}/complete', [
            'as' => 'mining-manager.events.complete',
            'uses' => 'EventController@complete',
            'middleware' => 'can:mining-manager.events.edit',
        ]);

        Route::post('/{id}/update-data', [
            'as' => 'mining-manager.events.update-data',
            'uses' => 'EventController@updateData',
            'middleware' => 'can:mining-manager.events.edit',
        ]);
    });

    // Moon Extraction Routes
    Route::group(['prefix' => 'moon'], function () {
        Route::get('/', [
            'as' => 'mining-manager.moon.index',
            'uses' => 'MoonController@index',
            'middleware' => 'can:mining-manager.moon.view',
        ]);

        Route::get('/calendar', [
            'as' => 'mining-manager.moon.calendar',
            'uses' => 'MoonController@calendar',
            'middleware' => 'can:mining-manager.moon.view',
        ]);

        Route::get('/compositions', [
            'as' => 'mining-manager.moon.compositions',
            'uses' => 'MoonController@compositions',
            'middleware' => 'can:mining-manager.moon.view',
        ]);

        Route::get('/calculator', [
            'as' => 'mining-manager.moon.calculator',
            'uses' => 'MoonController@calculator',
            'middleware' => 'can:mining-manager.moon.view',
        ]);

        Route::get('/active', [
            'as' => 'mining-manager.moon.active',
            'uses' => 'MoonController@active',
            'middleware' => 'can:mining-manager.moon.view',
        ]);

        Route::get('/{id}', [
            'as' => 'mining-manager.moon.show',
            'uses' => 'MoonController@show',
            'middleware' => 'can:mining-manager.moon.view',
        ]);

        Route::post('/{id}/update', [
            'as' => 'mining-manager.moon.update',
            'uses' => 'MoonController@update',
            'middleware' => 'can:mining-manager.moon.update',
        ]);

        Route::post('/refresh-all', [
            'as' => 'mining-manager.moon.refresh-all',
            'uses' => 'MoonController@refreshAll',
            'middleware' => 'can:mining-manager.moon.update',
        ]);

        Route::get('/{id}/data', [
            'as' => 'mining-manager.moon.data',
            'uses' => 'MoonController@data',
            'middleware' => 'can:mining-manager.moon.view',
        ]);

        Route::get('/structure/{structureId}', [
            'as' => 'mining-manager.moon.extractions',
            'uses' => 'MoonController@extractions',
            'middleware' => 'can:mining-manager.moon.view',
        ]);
    });

    // Analytics Routes
    Route::group(['prefix' => 'analytics'], function () {
        Route::get('/', [
            'as' => 'mining-manager.analytics.index',
            'uses' => 'AnalyticsController@index',
            'middleware' => 'can:mining-manager.analytics.view',
        ]);

        Route::get('/charts', [
            'as' => 'mining-manager.analytics.charts',
            'uses' => 'AnalyticsController@charts',
            'middleware' => 'can:mining-manager.analytics.view',
        ]);

        Route::get('/tables', [
            'as' => 'mining-manager.analytics.tables',
            'uses' => 'AnalyticsController@tables',
            'middleware' => 'can:mining-manager.analytics.view',
        ]);

        Route::get('/export', [
            'as' => 'mining-manager.analytics.export',
            'uses' => 'AnalyticsController@export',
            'middleware' => 'can:mining-manager.analytics.export',
        ]);

        Route::get('/data', [
            'as' => 'mining-manager.analytics.data',
            'uses' => 'AnalyticsController@data',
            'middleware' => 'can:mining-manager.analytics.view',
        ]);

        Route::get('/compare', [
           'as' => 'mining-manager.analytics.compare',
           'uses' => 'AnalyticsController@compare',
           'middleware' => 'can:mining-manager.analytics.view',
       ]);
    });

    // Reports Routes
    Route::group(['prefix' => 'reports'], function () {
        Route::get('/', [
            'as' => 'mining-manager.reports.index',
            'uses' => 'ReportController@index',
            'middleware' => 'can:mining-manager.reports.view',
        ]);

        Route::get('/generate', [
            'as' => 'mining-manager.reports.generate',
            'uses' => 'ReportController@generate',
            'middleware' => 'can:mining-manager.reports.generate',
        ]);

        Route::post('/', [
            'as' => 'mining-manager.reports.store',
            'uses' => 'ReportController@store',
            'middleware' => 'can:mining-manager.reports.generate',
        ]);

        Route::get('/{id}', [
            'as' => 'mining-manager.reports.show',
            'uses' => 'ReportController@show',
            'middleware' => 'can:mining-manager.reports.view',
        ]);

        Route::get('/{id}/download', [
            'as' => 'mining-manager.reports.download',
            'uses' => 'ReportController@download',
            'middleware' => 'can:mining-manager.reports.view',
        ]);

        Route::delete('/{id}', [
            'as' => 'mining-manager.reports.destroy',
            'uses' => 'ReportController@destroy',
            'middleware' => 'can:mining-manager.reports.delete',
        ]);

        Route::post('/cleanup', [
            'as' => 'mining-manager.reports.cleanup',
            'uses' => 'ReportController@cleanup',
            'middleware' => 'can:mining-manager.reports.delete',
        ]);

        Route::get('/scheduled', [
            'as' => 'mining-manager.reports.scheduled',
            'uses' => 'ReportController@scheduled',
            'middleware' => 'can:mining-manager.reports.view',
        ]);
        
        Route::post('/schedules', [
            'as' => 'mining-manager.reports.schedules.store',
            'uses' => 'ReportController@storeSchedule',
            'middleware' => 'can:mining-manager.reports.generate',
        ]);
        
        Route::post('/schedules/{id}/toggle', [
            'as' => 'mining-manager.reports.schedules.toggle',
            'uses' => 'ReportController@toggleSchedule',
            'middleware' => 'can:mining-manager.reports.generate',
        ]);
        
        Route::post('/schedules/{id}/run', [
            'as' => 'mining-manager.reports.schedules.run',
            'uses' => 'ReportController@runSchedule',
            'middleware' => 'can:mining-manager.reports.generate',
        ]);
        
        Route::delete('/schedules/{id}', [
            'as' => 'mining-manager.reports.schedules.destroy',
            'uses' => 'ReportController@destroySchedule',
            'middleware' => 'can:mining-manager.reports.delete',
        ]);
        
        // Export routes
        Route::get('/export', [
            'as' => 'mining-manager.reports.export',
            'uses' => 'ReportController@exportView',
            'middleware' => 'can:mining-manager.reports.view',
        ]);
        
        Route::post('/export/process', [
            'as' => 'mining-manager.reports.export.process',
            'uses' => 'ReportController@processExport',
            'middleware' => 'can:mining-manager.reports.export',
        ]);
        
        Route::get('/export/{id}/download', [
            'as' => 'mining-manager.reports.export.download',
            'uses' => 'ReportController@downloadExport',
            'middleware' => 'can:mining-manager.reports.view',
        ]);
    });

    // Settings Routes
    Route::group(['prefix' => 'settings'], function () {
        Route::get('/', [
            'as' => 'mining-manager.settings.index',
            'uses' => 'SettingsController@index',
            'middleware' => 'can:mining-manager.settings.view',
        ]);

        Route::post('/general', [
            'as' => 'mining-manager.settings.update-general',
            'uses' => 'SettingsController@updateGeneral',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/tax-rates', [
            'as' => 'mining-manager.settings.update-tax-rates',
            'uses' => 'SettingsController@updateTaxRates',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/pricing', [
            'as' => 'mining-manager.settings.update-pricing',
            'uses' => 'SettingsController@updatePricing',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/features', [
            'as' => 'mining-manager.settings.update-features',
            'uses' => 'SettingsController@updateFeatures',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/reset', [
            'as' => 'mining-manager.settings.reset',
            'uses' => 'SettingsController@reset',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::get('/export', [
            'as' => 'mining-manager.settings.export',
            'uses' => 'SettingsController@export',
            'middleware' => 'can:mining-manager.settings.view',
        ]);

        Route::post('/import', [
            'as' => 'mining-manager.settings.import',
            'uses' => 'SettingsController@import',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/clear-cache', [
            'as' => 'mining-manager.settings.clear-cache',
            'uses' => 'SettingsController@clearCache',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::get('/{key}', [
            'as' => 'mining-manager.settings.get',
            'uses' => 'SettingsController@getSetting',
            'middleware' => 'can:mining-manager.settings.view',
        ]);

        Route::put('/{key}', [
            'as' => 'mining-manager.settings.update',
            'uses' => 'SettingsController@updateSetting',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);
    });

    // Help Route
    Route::get('/help', [
        'as' => 'mining-manager.help',
        'uses' => 'SettingsController@help',
        'middleware' => 'can:mining-manager.view',
    ]);

    // API Routes
    Route::group(['prefix' => 'api', 'middleware' => 'can:mining-manager.api.access'], function () {
        Route::get('/ledger', [
            'as' => 'mining-manager.api.ledger',
            'uses' => 'ApiController@ledger',
        ]);

        Route::get('/taxes', [
            'as' => 'mining-manager.api.taxes',
            'uses' => 'ApiController@taxes',
        ]);

        Route::get('/events', [
            'as' => 'mining-manager.api.events',
            'uses' => 'ApiController@events',
        ]);

        Route::get('/extractions', [
            'as' => 'mining-manager.api.extractions',
            'uses' => 'ApiController@extractions',
        ]);

        Route::get('/analytics', [
            'as' => 'mining-manager.api.analytics',
            'uses' => 'ApiController@analytics',
        ]);

        Route::get('/metrics', [
            'as' => 'mining-manager.api.metrics',
            'uses' => 'ApiController@metrics',
        ]);

        Route::get('/character/{characterId}/summary', [
            'as' => 'mining-manager.api.character-summary',
            'uses' => 'ApiController@characterSummary',
        ]);

        Route::get('/health', [
            'as' => 'mining-manager.api.health',
            'uses' => 'ApiController@health',
        ]);
    });
});
