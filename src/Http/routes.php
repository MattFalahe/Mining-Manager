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
        // Enhanced summary view is now the default
        Route::get('/', [
            'as' => 'mining-manager.ledger.index',
            'uses' => 'LedgerController@summaryIndex',
            'middleware' => 'can:mining-manager.ledger.view',
        ]);

        // Keep /summary route for backwards compatibility
        Route::get('/summary', [
            'as' => 'mining-manager.ledger.summary',
            'uses' => 'LedgerController@summaryIndex',
            'middleware' => 'can:mining-manager.ledger.view',
        ]);

        // Character details page (full view)
        Route::get('/character/{characterId}', [
            'as' => 'mining-manager.ledger.character-details',
            'uses' => 'LedgerController@showCharacterDetails',
            'middleware' => 'can:mining-manager.ledger.view',
        ]);

        // AJAX endpoint for daily breakdown (NEW)
        Route::get('/summary/character/{characterId}/daily', [
            'as' => 'mining-manager.ledger.character-daily',
            'uses' => 'LedgerController@getCharacterDailySummary',
            'middleware' => 'can:mining-manager.ledger.view',
        ]);

        // AJAX endpoint for detailed entries (NEW)
        Route::get('/summary/character/{characterId}/entries', [
            'as' => 'mining-manager.ledger.character-entries',
            'uses' => 'LedgerController@getDetailedEntries',
            'middleware' => 'can:mining-manager.ledger.view',
        ]);

        // AJAX endpoint for system-specific details (NEW)
        Route::get('/summary/character/{characterId}/system/{systemId}', [
            'as' => 'mining-manager.ledger.character-system',
            'uses' => 'LedgerController@getCharacterSystemDetails',
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

        // Entry details
        Route::get('/details/{id}', [
            'as' => 'mining-manager.ledger.details',
            'uses' => 'LedgerController@details',
            'middleware' => 'can:mining-manager.ledger.view',
        ]);

        // Delete single entry
        Route::delete('/delete/{id}', [
            'as' => 'mining-manager.ledger.delete',
            'uses' => 'LedgerController@delete',
            'middleware' => 'can:mining-manager.ledger.delete',
        ]);

        // Bulk delete entries
        Route::post('/bulk-delete', [
            'as' => 'mining-manager.ledger.bulk-delete',
            'uses' => 'LedgerController@bulkDelete',
            'middleware' => 'can:mining-manager.ledger.delete',
        ]);

        // Export ledger data
        Route::get('/export', [
            'as' => 'mining-manager.ledger.export',
            'uses' => 'LedgerController@export',
            'middleware' => 'can:mining-manager.ledger.view',
        ]);

        // Export personal ledger data
        Route::get('/export-personal', [
            'as' => 'mining-manager.ledger.export-personal',
            'uses' => 'LedgerController@exportPersonal',
            'middleware' => 'can:mining-manager.view',
        ]);

        // Download CSV template for manual entry
        Route::get('/download-template', [
            'as' => 'mining-manager.ledger.download-template',
            'uses' => 'LedgerController@downloadTemplate',
            'middleware' => 'can:mining-manager.ledger.process',
        ]);

        // Import from ESI
        Route::post('/import-esi', [
            'as' => 'mining-manager.ledger.import-esi',
            'uses' => 'LedgerController@importFromESI',
            'middleware' => 'can:mining-manager.ledger.process',
        ]);

        // Upload CSV file
        Route::post('/upload-csv', [
            'as' => 'mining-manager.ledger.upload-csv',
            'uses' => 'LedgerController@uploadCSV',
            'middleware' => 'can:mining-manager.ledger.process',
        ]);

        // Toggle processing queue
        Route::post('/toggle-queue', [
            'as' => 'mining-manager.ledger.toggle-queue',
            'uses' => 'LedgerController@toggleQueue',
            'middleware' => 'can:mining-manager.ledger.process',
        ]);

        // Retry failed job
        Route::post('/retry-job/{id}', [
            'as' => 'mining-manager.ledger.retry-job',
            'uses' => 'LedgerController@retryJob',
            'middleware' => 'can:mining-manager.ledger.process',
        ]);

        // View job log
        Route::get('/job-log/{id}', [
            'as' => 'mining-manager.ledger.job-log',
            'uses' => 'LedgerController@getJobLog',
            'middleware' => 'can:mining-manager.ledger.process',
        ]);
    });

    // Tax Management Routes
    // FIXED: All route names changed from 'tax' to 'taxes' (singular to plural)
    // ADDED: 10 missing routes that views were expecting
    Route::group(['prefix' => 'tax'], function () {
        // Main tax index
        Route::get('/', [
            'as' => 'mining-manager.taxes.index',  // FIXED: was mining-manager.tax.index
            'uses' => 'TaxController@index',
            'middleware' => 'can:mining-manager.tax.view',
        ]);

        // Tax calculation form (GET displays form)
        Route::get('/calculate', [
            'as' => 'mining-manager.taxes.calculate',  // FIXED: was mining-manager.tax.calculate
            'uses' => 'TaxController@showCalculateForm',
            'middleware' => 'can:mining-manager.tax.calculate',
        ]);

        Route::post('/calculate', [
            'as' => 'mining-manager.taxes.calculate.process',  // FIXED: was mining-manager.tax.calculate.process
            'uses' => 'TaxController@processCalculation',
            'middleware' => 'can:mining-manager.tax.calculate',
        ]);

        // ADDED: Process calculation (alternative route name that views expect)
        Route::post('/calculate/process', [
            'as' => 'mining-manager.taxes.process-calculation',
            'uses' => 'TaxController@processCalculation',
            'middleware' => 'can:mining-manager.tax.calculate',
        ]);

        // ADDED: Live tracking during calculation
        Route::get('/calculate/live-tracking', [
            'as' => 'mining-manager.taxes.live-tracking',
            'uses' => 'TaxController@liveTracking',
            'middleware' => 'can:mining-manager.tax.calculate',
        ]);

        // ADDED: Regenerate payments
        Route::post('/calculate/regenerate', [
            'as' => 'mining-manager.taxes.regenerate-payments',
            'uses' => 'TaxController@regeneratePayments',
            'middleware' => 'can:mining-manager.tax.calculate',
        ]);

        // Contracts
        Route::get('/contracts', [
            'as' => 'mining-manager.taxes.contracts',  // FIXED: was mining-manager.tax.contracts
            'uses' => 'TaxController@contracts',
            'middleware' => 'can:mining-manager.tax.generate_invoices',
        ]);

        Route::post('/contracts/generate', [
            'as' => 'mining-manager.taxes.contracts.generate',  // FIXED: was mining-manager.tax.contracts.generate
            'uses' => 'TaxController@generateInvoices',
            'middleware' => 'can:mining-manager.tax.generate_invoices',
        ]);

        // Wallet
        Route::get('/wallet', [
            'as' => 'mining-manager.taxes.wallet',  // FIXED: was mining-manager.tax.wallet
            'uses' => 'TaxController@wallet',
            'middleware' => 'can:mining-manager.tax.verify_payments',
        ]);

        Route::post('/wallet/verify', [
            'as' => 'mining-manager.taxes.wallet.verify',  // FIXED: was mining-manager.tax.wallet.verify
            'uses' => 'TaxController@verifyPayments',
            'middleware' => 'can:mining-manager.tax.verify_payments',
        ]);

        // Tax details
        Route::get('/details/{characterId}', [
            'as' => 'mining-manager.taxes.details',  // FIXED: was mining-manager.tax.details
            'uses' => 'TaxController@details',
            'middleware' => 'can:mining-manager.tax.view',
        ]);

        // My taxes
        Route::get('/my-taxes', [
            'as' => 'mining-manager.taxes.my-taxes',  // FIXED: was mining-manager.tax.my-taxes
            'uses' => 'TaxController@myTaxes',
            'middleware' => 'can:mining-manager.view',
        ]);

        // Tax codes
        Route::get('/codes', [
            'as' => 'mining-manager.taxes.codes',  // FIXED: was mining-manager.tax.codes
            'uses' => 'TaxController@codes',
            'middleware' => 'can:mining-manager.tax.view',
        ]);

        Route::post('/codes', [
            'as' => 'mining-manager.taxes.codes.store',  // FIXED: was mining-manager.tax.codes.store
            'uses' => 'TaxController@storeCode',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        Route::delete('/codes/{id}', [
            'as' => 'mining-manager.taxes.codes.destroy',  // FIXED: was mining-manager.tax.codes.destroy
            'uses' => 'TaxController@destroyCode',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        // ADDED: Mark as paid (single) - ID comes from request body, not URL
        Route::post('/mark-paid', [
            'as' => 'mining-manager.taxes.mark-paid',
            'uses' => 'TaxController@markPaid',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        // ADDED: Send reminder (single) - ID comes from request body, not URL
        Route::post('/send-reminder', [
            'as' => 'mining-manager.taxes.send-reminder',
            'uses' => 'TaxController@sendReminder',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        // ADDED: Bulk mark as paid
        Route::post('/bulk/mark-paid', [
            'as' => 'mining-manager.taxes.bulk-mark-paid',
            'uses' => 'TaxController@bulkMarkPaid',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        // ADDED: Bulk send reminders
        Route::post('/bulk/send-reminders', [
            'as' => 'mining-manager.taxes.bulk-send-reminders',
            'uses' => 'TaxController@bulkSendReminders',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        // ADDED: Export taxes
        Route::get('/export', [
            'as' => 'mining-manager.taxes.export',
            'uses' => 'TaxController@export',
            'middleware' => 'can:mining-manager.tax.view',
        ]);

        // ADDED: Export personal taxes
        Route::get('/my-taxes/export', [
            'as' => 'mining-manager.taxes.export-personal',
            'uses' => 'TaxController@exportPersonal',
            'middleware' => 'can:mining-manager.view',
        ]);

        // ADDED: Download receipt
        Route::get('/{id}/receipt', [
            'as' => 'mining-manager.taxes.download-receipt',
            'uses' => 'TaxController@downloadReceipt',
            'middleware' => 'can:mining-manager.view',
        ]);

        // Update status
        Route::post('/{id}/status', [
            'as' => 'mining-manager.taxes.update-status',  // FIXED: was mining-manager.tax.update-status
            'uses' => 'TaxController@updateStatus',
            'middleware' => 'can:mining-manager.tax.manage',
        ]);

        // Delete tax
        Route::delete('/{id}', [
            'as' => 'mining-manager.taxes.destroy',  // FIXED: was mining-manager.tax.destroy
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

        // Location search for AJAX dropdown (must be before wildcards)
        Route::get('/search-locations', [
            'as' => 'mining-manager.events.search-locations',
            'uses' => 'EventController@searchLocations',
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

        Route::post('/{id}/join', [
            'as' => 'mining-manager.events.join',
            'uses' => 'EventController@join',
            'middleware' => 'can:mining-manager.events.view',
        ]);

        Route::post('/{id}/leave', [
            'as' => 'mining-manager.events.leave',
            'uses' => 'EventController@leave',
            'middleware' => 'can:mining-manager.events.view',
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

        Route::post('/simulate', [
            'as' => 'mining-manager.moon.simulate',
            'uses' => 'MoonController@simulate',
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

        Route::post('/cleanup', [
            'as' => 'mining-manager.reports.cleanup',
            'uses' => 'ReportController@cleanup',
            'middleware' => 'can:mining-manager.reports.delete',
        ]);

        // Scheduled reports routes - MUST be before /{id} wildcard
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

        // Export routes - MUST be before /{id} wildcard
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

        // Wildcard routes - MUST be last to avoid catching /scheduled, /export, etc.
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
    });

    // Settings Routes
    Route::group(['prefix' => 'settings'], function () {
        Route::get('/', [
            'as' => 'mining-manager.settings.index',
            'uses' => 'SettingsController@index',
            'middleware' => 'can:mining-manager.settings.view',
        ]);

        // Corporation search for dropdown (Ajax)
        Route::get('/search-corporations', [
            'as' => 'mining-manager.settings.search-corporations',
            'uses' => 'SettingsController@searchCorporations',
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

        Route::post('/dashboard', [
            'as' => 'mining-manager.settings.update-dashboard',
            'uses' => 'SettingsController@updateDashboard',
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

        // Webhook Management Routes
        Route::get('/webhooks', [
            'as' => 'mining-manager.settings.webhooks.index',
            'uses' => 'SettingsController@getWebhooks',
            'middleware' => 'can:mining-manager.settings.view',
        ]);

        Route::get('/webhooks/{id}', [
            'as' => 'mining-manager.settings.webhooks.show',
            'uses' => 'SettingsController@getWebhook',
            'middleware' => 'can:mining-manager.settings.view',
        ]);

        Route::post('/webhooks', [
            'as' => 'mining-manager.settings.webhooks.store',
            'uses' => 'SettingsController@storeWebhook',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::put('/webhooks/{id}', [
            'as' => 'mining-manager.settings.webhooks.update',
            'uses' => 'SettingsController@updateWebhook',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/webhooks/{id}/toggle', [
            'as' => 'mining-manager.settings.webhooks.toggle',
            'uses' => 'SettingsController@toggleWebhook',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/webhooks/{id}/test', [
            'as' => 'mining-manager.settings.webhooks.test',
            'uses' => 'SettingsController@testWebhook',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::delete('/webhooks/{id}', [
            'as' => 'mining-manager.settings.webhooks.destroy',
            'uses' => 'SettingsController@deleteWebhook',
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

    // Theft Detection Routes
    Route::group(['prefix' => 'theft'], function () {
        Route::get('/', [
            'as' => 'mining-manager.theft.index',
            'uses' => 'TheftIncidentController@index',
            'middleware' => 'can:mining-manager.view',
        ]);

        Route::get('/{id}', [
            'as' => 'mining-manager.theft.show',
            'uses' => 'TheftIncidentController@show',
            'middleware' => 'can:mining-manager.view',
        ]);

        Route::post('/{id}/status', [
            'as' => 'mining-manager.theft.update-status',
            'uses' => 'TheftIncidentController@updateStatus',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/{id}/resolve', [
            'as' => 'mining-manager.theft.resolve',
            'uses' => 'TheftIncidentController@resolve',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::get('/export/csv', [
            'as' => 'mining-manager.theft.export',
            'uses' => 'TheftIncidentController@export',
            'middleware' => 'can:mining-manager.view',
        ]);
    });

    // Help Route
    Route::get('/help', [
        'as' => 'mining-manager.help',
        'uses' => 'SettingsController@help',
        'middleware' => 'can:mining-manager.view',
    ]);

    // Configured Corporations Route
    Route::get('/configured-corporations', [
        'as' => 'mining-manager.settings.configured-corporations',
        'uses' => 'SettingsController@configuredCorporations',
        'middleware' => 'can:mining-manager.settings.view',
    ]);

    // Diagnostic Routes (Test Data Generation)
    Route::group(['prefix' => 'diagnostic'], function () {
        Route::get('/ping', [
            'as' => 'mining-manager.diagnostic.ping',
            'uses' => 'DiagnosticController@ping',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::get('/', [
            'as' => 'mining-manager.diagnostic.index',
            'uses' => 'DiagnosticController@index',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/generate-corporations', [
            'as' => 'mining-manager.diagnostic.generate-corporations',
            'uses' => 'DiagnosticController@generateTestCorporations',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/generate-characters', [
            'as' => 'mining-manager.diagnostic.generate-characters',
            'uses' => 'DiagnosticController@generateTestCharacters',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/generate-mining-data', [
            'as' => 'mining-manager.diagnostic.generate-mining-data',
            'uses' => 'DiagnosticController@generateTestMiningData',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/cleanup', [
            'as' => 'mining-manager.diagnostic.cleanup',
            'uses' => 'DiagnosticController@cleanupTestData',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/test-price-provider', [
            'as' => 'mining-manager.diagnostic.test-price-provider',
            'uses' => 'DiagnosticController@testPriceProvider',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::get('/price-provider-config', [
            'as' => 'mining-manager.diagnostic.price-provider-config',
            'uses' => 'DiagnosticController@getPriceProviderConfig',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/test-batch-pricing', [
            'as' => 'mining-manager.diagnostic.test-batch-pricing',
            'uses' => 'DiagnosticController@testBatchPricing',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::get('/cache-health', [
            'as' => 'mining-manager.diagnostic.cache-health',
            'uses' => 'DiagnosticController@getCacheHealth',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/warm-cache', [
            'as' => 'mining-manager.diagnostic.warm-cache',
            'uses' => 'DiagnosticController@warmCache',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::get('/validate-type-ids', [
            'as' => 'mining-manager.diagnostic.validate-type-ids',
            'uses' => 'DiagnosticController@validateTypeIds',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/test-webhook/{id}', [
            'as' => 'mining-manager.diagnostic.test-webhook',
            'uses' => 'DiagnosticController@testWebhook',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        // New Diagnostic Tools
        Route::get('/settings-health', [
            'as' => 'mining-manager.diagnostic.settings-health',
            'uses' => 'DiagnosticController@settingsHealth',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::get('/tax-diagnostic', [
            'as' => 'mining-manager.diagnostic.tax-diagnostic',
            'uses' => 'DiagnosticController@taxDiagnostic',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::get('/data-integrity', [
            'as' => 'mining-manager.diagnostic.data-integrity',
            'uses' => 'DiagnosticController@dataIntegrity',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);

        Route::post('/valuation-test', [
            'as' => 'mining-manager.diagnostic.valuation-test',
            'uses' => 'DiagnosticController@valuationTest',
            'middleware' => 'can:mining-manager.settings.edit',
        ]);
    });

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

        Route::get('/corporation-characters', [
            'as' => 'mining-manager.api.corporation-characters',
            'uses' => 'ApiController@corporationCharacters',
        ]);
    });
});
