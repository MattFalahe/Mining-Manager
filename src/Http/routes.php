<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace' => 'MiningManager\Http\Controllers',
    'prefix' => 'mining-manager',
    'middleware' => ['web', 'auth', 'locale'],
], function () {

    // =====================================================================
    // MEMBER ROUTES - View own data, join events, view moon schedules
    // =====================================================================

    // Dashboard Routes
    Route::group(['prefix' => 'dashboard'], function () {
        Route::get('/', [
            'as' => 'mining-manager.dashboard',
            'uses' => 'DashboardController@index',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/member', [
            'as' => 'mining-manager.dashboard.member',
            'uses' => 'DashboardController@memberDashboard',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/live-data', [
            'as' => 'mining-manager.dashboard.live-data',
            'uses' => 'DashboardController@getLiveChartData',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/tab/corporation', [
            'as' => 'mining-manager.dashboard.tab.corporation',
            'uses' => 'DashboardController@getCorporationTabData',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/tab/guest-miners', [
            'as' => 'mining-manager.dashboard.tab.guest-miners',
            'uses' => 'DashboardController@getGuestMinersTabData',
            'middleware' => 'can:mining-manager.director',
        ]);
    });

    // Mining Ledger Routes
    Route::group(['prefix' => 'ledger'], function () {
        // View routes - Member
        Route::get('/', [
            'as' => 'mining-manager.ledger.index',
            'uses' => 'LedgerController@summaryIndex',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/summary', [
            'as' => 'mining-manager.ledger.summary',
            'uses' => 'LedgerController@summaryIndex',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/character/{characterId}', [
            'as' => 'mining-manager.ledger.character-details',
            'uses' => 'LedgerController@showCharacterDetails',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/summary/character/{characterId}/daily', [
            'as' => 'mining-manager.ledger.character-daily',
            'uses' => 'LedgerController@getCharacterDailySummary',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/summary/character/{characterId}/entries', [
            'as' => 'mining-manager.ledger.character-entries',
            'uses' => 'LedgerController@getDetailedEntries',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/summary/character/{characterId}/system/{systemId}', [
            'as' => 'mining-manager.ledger.character-system',
            'uses' => 'LedgerController@getCharacterSystemDetails',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/my-mining', [
            'as' => 'mining-manager.ledger.my-mining',
            'uses' => 'LedgerController@myMining',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/reprocessing', [
            'as' => 'mining-manager.ledger.reprocessing',
            'uses' => 'LedgerController@reprocessingCalculator',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::post('/reprocessing/calculate', [
            'as' => 'mining-manager.ledger.reprocessing.calculate',
            'uses' => 'LedgerController@calculateReprocessing',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/details/{id}', [
            'as' => 'mining-manager.ledger.details',
            'uses' => 'LedgerController@details',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/export-personal', [
            'as' => 'mining-manager.ledger.export-personal',
            'uses' => 'LedgerController@exportPersonal',
            'middleware' => 'can:mining-manager.member',
        ]);

        // Director routes
        Route::get('/export', [
            'as' => 'mining-manager.ledger.export',
            'uses' => 'LedgerController@export',
            'middleware' => 'can:mining-manager.director',
        ]);

        // Delete routes - Admin
        Route::delete('/delete/{id}', [
            'as' => 'mining-manager.ledger.delete',
            'uses' => 'LedgerController@delete',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/bulk-delete', [
            'as' => 'mining-manager.ledger.bulk-delete',
            'uses' => 'LedgerController@bulkDelete',
            'middleware' => 'can:mining-manager.admin',
        ]);
    });

    // Tax Management Routes
    // 3-tier: member (view own) < director (toggle all, verify) < admin (full management)
    Route::group(['prefix' => 'tax'], function () {

        // --- Member routes (view own data) ---
        Route::get('/', [
            'as' => 'mining-manager.taxes.index',
            'uses' => 'TaxController@index',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/my-taxes', [
            'as' => 'mining-manager.taxes.my-taxes',
            'uses' => 'TaxController@myTaxes',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/my-taxes/breakdown/{month?}', [
            'as' => 'mining-manager.taxes.my-breakdown',
            'uses' => 'TaxController@myTaxBreakdown',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/codes', [
            'as' => 'mining-manager.taxes.codes',
            'uses' => 'TaxController@codes',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/details/{taxId}', [
            'as' => 'mining-manager.taxes.details',
            'uses' => 'TaxController@details',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/wallet', [
            'as' => 'mining-manager.taxes.wallet',
            'uses' => 'TaxController@wallet',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/my-taxes/export', [
            'as' => 'mining-manager.taxes.export-personal',
            'uses' => 'TaxController@exportPersonal',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/{id}/receipt', [
            'as' => 'mining-manager.taxes.download-receipt',
            'uses' => 'TaxController@downloadReceipt',
            'middleware' => 'can:mining-manager.member',
        ]);

        // --- Director routes (toggle view, verify payments, export all) ---
        Route::post('/toggle-view', [
            'as' => 'mining-manager.taxes.toggle-view',
            'uses' => 'TaxController@toggleView',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::post('/wallet/verify', [
            'as' => 'mining-manager.taxes.wallet.verify',
            'uses' => 'TaxController@verifyPayments',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::post('/wallet/dismiss', [
            'as' => 'mining-manager.taxes.wallet.dismiss',
            'uses' => 'TaxController@dismissTransaction',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/export', [
            'as' => 'mining-manager.taxes.export',
            'uses' => 'TaxController@export',
            'middleware' => 'can:mining-manager.director',
        ]);

        // --- Admin routes (full tax management) ---
        Route::get('/calculate', [
            'as' => 'mining-manager.taxes.calculate',
            'uses' => 'TaxController@showCalculateForm',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/calculate/process', [
            'as' => 'mining-manager.taxes.process-calculation',
            'uses' => 'TaxController@processCalculation',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/calculate/live-tracking', [
            'as' => 'mining-manager.taxes.live-tracking',
            'uses' => 'TaxController@liveTracking',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/calculate/regenerate', [
            'as' => 'mining-manager.taxes.regenerate-payments',
            'uses' => 'TaxController@regeneratePayments',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/codes/generate', [
            'as' => 'mining-manager.taxes.codes.generate',
            'uses' => 'TaxController@generateCodes',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/codes', [
            'as' => 'mining-manager.taxes.codes.store',
            'uses' => 'TaxController@storeCode',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::delete('/codes/{id}', [
            'as' => 'mining-manager.taxes.codes.destroy',
            'uses' => 'TaxController@destroyCode',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/mark-paid', [
            'as' => 'mining-manager.taxes.mark-paid',
            'uses' => 'TaxController@markPaid',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::post('/manual-entry', [
            'as' => 'mining-manager.taxes.manual-entry',
            'uses' => 'TaxController@createManualEntry',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::post('/send-reminder', [
            'as' => 'mining-manager.taxes.send-reminder',
            'uses' => 'TaxController@sendReminder',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/bulk/mark-paid', [
            'as' => 'mining-manager.taxes.bulk-mark-paid',
            'uses' => 'TaxController@bulkMarkPaid',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/bulk/send-reminders', [
            'as' => 'mining-manager.taxes.bulk-send-reminders',
            'uses' => 'TaxController@bulkSendReminders',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/{id}/status', [
            'as' => 'mining-manager.taxes.update-status',
            'uses' => 'TaxController@updateStatus',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::delete('/{id}', [
            'as' => 'mining-manager.taxes.destroy',
            'uses' => 'TaxController@destroy',
            'middleware' => 'can:mining-manager.admin',
        ]);
    });

    // Mining Events Routes
    Route::group(['prefix' => 'events'], function () {
        // Member - view, join/leave
        Route::get('/', [
            'as' => 'mining-manager.events.index',
            'uses' => 'EventController@index',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/search-locations', [
            'as' => 'mining-manager.events.search-locations',
            'uses' => 'EventController@searchLocations',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/active', [
            'as' => 'mining-manager.events.active',
            'uses' => 'EventController@active',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/calendar', [
            'as' => 'mining-manager.events.calendar',
            'uses' => 'EventController@calendar',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/my-events', [
            'as' => 'mining-manager.events.my-events',
            'uses' => 'EventController@myEvents',
            'middleware' => 'can:mining-manager.member',
        ]);

        // Director - create (MUST be before /{id} wildcard to avoid route collision)
        Route::get('/create', [
            'as' => 'mining-manager.events.create',
            'uses' => 'EventController@create',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/{id}', [
            'as' => 'mining-manager.events.show',
            'uses' => 'EventController@show',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::post('/{id}/join', [
            'as' => 'mining-manager.events.join',
            'uses' => 'EventController@join',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::post('/{id}/leave', [
            'as' => 'mining-manager.events.leave',
            'uses' => 'EventController@leave',
            'middleware' => 'can:mining-manager.member',
        ]);

        // Director - edit, start/complete

        Route::post('/', [
            'as' => 'mining-manager.events.store',
            'uses' => 'EventController@store',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/{id}/edit', [
            'as' => 'mining-manager.events.edit',
            'uses' => 'EventController@edit',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::put('/{id}', [
            'as' => 'mining-manager.events.update',
            'uses' => 'EventController@update',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::post('/{id}/start', [
            'as' => 'mining-manager.events.start',
            'uses' => 'EventController@start',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::post('/{id}/complete', [
            'as' => 'mining-manager.events.complete',
            'uses' => 'EventController@complete',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::post('/{id}/update-data', [
            'as' => 'mining-manager.events.update-data',
            'uses' => 'EventController@updateData',
            'middleware' => 'can:mining-manager.director',
        ]);

        // Admin - delete
        Route::delete('/{id}', [
            'as' => 'mining-manager.events.destroy',
            'uses' => 'EventController@destroy',
            'middleware' => 'can:mining-manager.admin',
        ]);
    });

    // Moon Extraction Routes
    Route::group(['prefix' => 'moon'], function () {
        // Member - view
        Route::get('/', [
            'as' => 'mining-manager.moon.index',
            'uses' => 'MoonController@index',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/calendar', [
            'as' => 'mining-manager.moon.calendar',
            'uses' => 'MoonController@calendar',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/compositions', [
            'as' => 'mining-manager.moon.compositions',
            'uses' => 'MoonController@compositions',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/calculator', [
            'as' => 'mining-manager.moon.calculator',
            'uses' => 'MoonController@calculator',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::post('/simulate', [
            'as' => 'mining-manager.moon.simulate',
            'uses' => 'MoonController@simulate',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/active', [
            'as' => 'mining-manager.moon.active',
            'uses' => 'MoonController@active',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/{id}', [
            'as' => 'mining-manager.moon.show',
            'uses' => 'MoonController@show',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/{id}/data', [
            'as' => 'mining-manager.moon.data',
            'uses' => 'MoonController@data',
            'middleware' => 'can:mining-manager.member',
        ]);

        Route::get('/structure/{structureId}', [
            'as' => 'mining-manager.moon.extractions',
            'uses' => 'MoonController@extractions',
            'middleware' => 'can:mining-manager.member',
        ]);

        // Member - report jackpot
        Route::post('/{id}/report-jackpot', [
            'as' => 'mining-manager.moon.report-jackpot',
            'uses' => 'MoonController@reportJackpot',
            'middleware' => 'can:mining-manager.member',
        ]);

        // Director - update
        Route::post('/{id}/update', [
            'as' => 'mining-manager.moon.update',
            'uses' => 'MoonController@update',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::post('/refresh-all', [
            'as' => 'mining-manager.moon.refresh-all',
            'uses' => 'MoonController@refreshAll',
            'middleware' => 'can:mining-manager.director',
        ]);
    });

    // =====================================================================
    // DIRECTOR ROUTES - View all corp data, analytics, reports, theft
    // =====================================================================

    // Analytics Routes
    Route::group(['prefix' => 'analytics'], function () {
        // Director - view
        Route::get('/', [
            'as' => 'mining-manager.analytics.index',
            'uses' => 'AnalyticsController@index',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/charts', [
            'as' => 'mining-manager.analytics.charts',
            'uses' => 'AnalyticsController@charts',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/tables', [
            'as' => 'mining-manager.analytics.tables',
            'uses' => 'AnalyticsController@tables',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/data', [
            'as' => 'mining-manager.analytics.data',
            'uses' => 'AnalyticsController@data',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/compare', [
           'as' => 'mining-manager.analytics.compare',
           'uses' => 'AnalyticsController@compare',
           'middleware' => 'can:mining-manager.director',
       ]);

        Route::get('/moons', [
            'as' => 'mining-manager.analytics.moons',
            'uses' => 'AnalyticsController@moons',
            'middleware' => 'can:mining-manager.director',
        ]);

        // Admin - export
        Route::get('/export', [
            'as' => 'mining-manager.analytics.export',
            'uses' => 'AnalyticsController@export',
            'middleware' => 'can:mining-manager.admin',
        ]);
    });

    // Reports Routes
    Route::group(['prefix' => 'reports'], function () {
        // Director - view
        Route::get('/', [
            'as' => 'mining-manager.reports.index',
            'uses' => 'ReportController@index',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/scheduled', [
            'as' => 'mining-manager.reports.scheduled',
            'uses' => 'ReportController@scheduled',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/export', [
            'as' => 'mining-manager.reports.export',
            'uses' => 'ReportController@exportView',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/export/{id}/download', [
            'as' => 'mining-manager.reports.export.download',
            'uses' => 'ReportController@downloadExport',
            'middleware' => 'can:mining-manager.director',
        ]);

        // Admin - generate, delete, export (MUST be before /{id} wildcard)
        Route::get('/generate', [
            'as' => 'mining-manager.reports.generate',
            'uses' => 'ReportController@generate',
            'middleware' => 'can:mining-manager.admin',
        ]);

        // Wildcard view routes - MUST be after all named routes
        Route::get('/{id}', [
            'as' => 'mining-manager.reports.show',
            'uses' => 'ReportController@show',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/{id}/download', [
            'as' => 'mining-manager.reports.download',
            'uses' => 'ReportController@download',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::post('/', [
            'as' => 'mining-manager.reports.store',
            'uses' => 'ReportController@store',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/cleanup', [
            'as' => 'mining-manager.reports.cleanup',
            'uses' => 'ReportController@cleanup',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/schedules', [
            'as' => 'mining-manager.reports.schedules.store',
            'uses' => 'ReportController@storeSchedule',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/schedules/{id}/toggle', [
            'as' => 'mining-manager.reports.schedules.toggle',
            'uses' => 'ReportController@toggleSchedule',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/schedules/{id}/run', [
            'as' => 'mining-manager.reports.schedules.run',
            'uses' => 'ReportController@runSchedule',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::delete('/schedules/{id}', [
            'as' => 'mining-manager.reports.schedules.destroy',
            'uses' => 'ReportController@destroySchedule',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/export/process', [
            'as' => 'mining-manager.reports.export.process',
            'uses' => 'ReportController@processExport',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/{id}/send-discord', [
            'as' => 'mining-manager.reports.send-discord',
            'uses' => 'ReportController@sendToDiscord',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::delete('/{id}', [
            'as' => 'mining-manager.reports.destroy',
            'uses' => 'ReportController@destroy',
            'middleware' => 'can:mining-manager.admin',
        ]);
    });

    // Theft Detection Routes
    Route::group(['prefix' => 'theft'], function () {
        // Director - view
        Route::get('/', [
            'as' => 'mining-manager.theft.index',
            'uses' => 'TheftIncidentController@index',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/export/csv', [
            'as' => 'mining-manager.theft.export',
            'uses' => 'TheftIncidentController@export',
            'middleware' => 'can:mining-manager.director',
        ]);

        Route::get('/{id}', [
            'as' => 'mining-manager.theft.show',
            'uses' => 'TheftIncidentController@show',
            'middleware' => 'can:mining-manager.director',
        ]);

        // Admin - resolve, update status
        Route::post('/{id}/status', [
            'as' => 'mining-manager.theft.update-status',
            'uses' => 'TheftIncidentController@updateStatus',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/{id}/resolve', [
            'as' => 'mining-manager.theft.resolve',
            'uses' => 'TheftIncidentController@resolve',
            'middleware' => 'can:mining-manager.admin',
        ]);
    });

    // =====================================================================
    // ADMIN ROUTES - Settings, diagnostics, API
    // =====================================================================

    // Settings Routes
    Route::group(['prefix' => 'settings'], function () {
        Route::get('/', [
            'as' => 'mining-manager.settings.index',
            'uses' => 'SettingsController@index',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/search-corporations', [
            'as' => 'mining-manager.settings.search-corporations',
            'uses' => 'SettingsController@searchCorporations',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/general', [
            'as' => 'mining-manager.settings.update-general',
            'uses' => 'SettingsController@updateGeneral',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/tax-rates', [
            'as' => 'mining-manager.settings.update-tax-rates',
            'uses' => 'SettingsController@updateTaxRates',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/pricing', [
            'as' => 'mining-manager.settings.update-pricing',
            'uses' => 'SettingsController@updatePricing',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/features', [
            'as' => 'mining-manager.settings.update-features',
            'uses' => 'SettingsController@updateFeatures',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/dashboard', [
            'as' => 'mining-manager.settings.update-dashboard',
            'uses' => 'SettingsController@updateDashboard',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/notifications', [
            'as' => 'mining-manager.settings.update-notifications',
            'uses' => 'SettingsController@updateNotifications',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/reset', [
            'as' => 'mining-manager.settings.reset',
            'uses' => 'SettingsController@reset',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/export', [
            'as' => 'mining-manager.settings.export',
            'uses' => 'SettingsController@export',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/import', [
            'as' => 'mining-manager.settings.import',
            'uses' => 'SettingsController@import',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/clear-cache', [
            'as' => 'mining-manager.settings.clear-cache',
            'uses' => 'SettingsController@clearCache',
            'middleware' => 'can:mining-manager.admin',
        ]);

        // Webhook Management
        Route::get('/webhooks', [
            'as' => 'mining-manager.settings.webhooks.index',
            'uses' => 'SettingsController@getWebhooks',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/webhooks/{id}', [
            'as' => 'mining-manager.settings.webhooks.show',
            'uses' => 'SettingsController@getWebhook',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/webhooks', [
            'as' => 'mining-manager.settings.webhooks.store',
            'uses' => 'SettingsController@storeWebhook',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::put('/webhooks/{id}', [
            'as' => 'mining-manager.settings.webhooks.update',
            'uses' => 'SettingsController@updateWebhook',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/webhooks/{id}/toggle', [
            'as' => 'mining-manager.settings.webhooks.toggle',
            'uses' => 'SettingsController@toggleWebhook',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/webhooks/{id}/test', [
            'as' => 'mining-manager.settings.webhooks.test',
            'uses' => 'SettingsController@testWebhook',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::delete('/webhooks/{id}', [
            'as' => 'mining-manager.settings.webhooks.destroy',
            'uses' => 'SettingsController@deleteWebhook',
            'middleware' => 'can:mining-manager.admin',
        ]);

    });

    // Help Route - base view permission (anyone with plugin access)
    Route::get('/help', [
        'as' => 'mining-manager.help',
        'uses' => 'SettingsController@help',
        'middleware' => 'can:mining-manager.view',
    ]);

    // Configured Corporations Route - Admin
    Route::get('/configured-corporations', [
        'as' => 'mining-manager.settings.configured-corporations',
        'uses' => 'SettingsController@configuredCorporations',
        'middleware' => 'can:mining-manager.admin',
    ]);

    // Diagnostic Routes - Admin only
    Route::group(['prefix' => 'diagnostic'], function () {
        Route::get('/ping', [
            'as' => 'mining-manager.diagnostic.ping',
            'uses' => 'DiagnosticController@ping',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/', [
            'as' => 'mining-manager.diagnostic.index',
            'uses' => 'DiagnosticController@index',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/generate-corporations', [
            'as' => 'mining-manager.diagnostic.generate-corporations',
            'uses' => 'DiagnosticController@generateTestCorporations',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/generate-characters', [
            'as' => 'mining-manager.diagnostic.generate-characters',
            'uses' => 'DiagnosticController@generateTestCharacters',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/generate-mining-data', [
            'as' => 'mining-manager.diagnostic.generate-mining-data',
            'uses' => 'DiagnosticController@generateTestMiningData',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/cleanup', [
            'as' => 'mining-manager.diagnostic.cleanup',
            'uses' => 'DiagnosticController@cleanupTestData',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/test-price-provider', [
            'as' => 'mining-manager.diagnostic.test-price-provider',
            'uses' => 'DiagnosticController@testPriceProvider',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/price-provider-config', [
            'as' => 'mining-manager.diagnostic.price-provider-config',
            'uses' => 'DiagnosticController@getPriceProviderConfig',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/test-batch-pricing', [
            'as' => 'mining-manager.diagnostic.test-batch-pricing',
            'uses' => 'DiagnosticController@testBatchPricing',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/cache-health', [
            'as' => 'mining-manager.diagnostic.cache-health',
            'uses' => 'DiagnosticController@getCacheHealth',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/warm-cache', [
            'as' => 'mining-manager.diagnostic.warm-cache',
            'uses' => 'DiagnosticController@warmCache',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/validate-type-ids', [
            'as' => 'mining-manager.diagnostic.validate-type-ids',
            'uses' => 'DiagnosticController@validateTypeIds',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/test-webhook/{id}', [
            'as' => 'mining-manager.diagnostic.test-webhook',
            'uses' => 'DiagnosticController@testWebhook',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/settings-health', [
            'as' => 'mining-manager.diagnostic.settings-health',
            'uses' => 'DiagnosticController@settingsHealth',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/tax-diagnostic', [
            'as' => 'mining-manager.diagnostic.tax-diagnostic',
            'uses' => 'DiagnosticController@taxDiagnostic',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/data-integrity', [
            'as' => 'mining-manager.diagnostic.data-integrity',
            'uses' => 'DiagnosticController@dataIntegrity',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/valuation-test', [
            'as' => 'mining-manager.diagnostic.valuation-test',
            'uses' => 'DiagnosticController@valuationTest',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::get('/system-status', [
            'as' => 'mining-manager.diagnostic.system-status',
            'uses' => 'DiagnosticController@systemStatus',
            'middleware' => 'can:mining-manager.admin',
        ]);

        Route::post('/test-notification', [
            'as' => 'mining-manager.diagnostic.test-notification',
            'uses' => 'DiagnosticController@testNotification',
            'middleware' => 'can:mining-manager.admin',
        ]);

        // Moon Extraction Diagnostic
        Route::get('/moon-diagnostic', [
            'as' => 'mining-manager.diagnostic.moon-diagnostic',
            'uses' => 'DiagnosticController@moonDiagnostic',
            'middleware' => 'can:mining-manager.admin',
        ]);

        // Tax Pipeline Diagnostic
        Route::get('/tax-pipeline', [
            'as' => 'mining-manager.diagnostic.tax-pipeline',
            'uses' => 'DiagnosticController@taxPipelineDiagnostic',
            'middleware' => 'can:mining-manager.admin',
        ]);

        // Theft Detection Diagnostic
        Route::get('/theft-diagnostic', [
            'as' => 'mining-manager.diagnostic.theft-diagnostic',
            'uses' => 'DiagnosticController@theftDiagnostic',
            'middleware' => 'can:mining-manager.admin',
        ]);

        // Event Lifecycle Diagnostic
        Route::get('/event-diagnostic', [
            'as' => 'mining-manager.diagnostic.event-diagnostic',
            'uses' => 'DiagnosticController@eventDiagnostic',
            'middleware' => 'can:mining-manager.admin',
        ]);

        // Analytics & Reports Diagnostic
        Route::get('/analytics-diagnostic', [
            'as' => 'mining-manager.diagnostic.analytics-diagnostic',
            'uses' => 'DiagnosticController@analyticsDiagnostic',
            'middleware' => 'can:mining-manager.admin',
        ]);
    });

    // API Routes - Admin only, rate limited
    Route::group(['prefix' => 'api', 'middleware' => ['can:mining-manager.admin', 'throttle:60,1']], function () {
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

        Route::get('/corporation-characters', [
            'as' => 'mining-manager.api.corporation-characters',
            'uses' => 'ApiController@corporationCharacters',
        ]);
    });
});
