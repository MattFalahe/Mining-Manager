<?php
use Illuminate\Support\Facades\Route;

Route::group([
    'namespace' => 'MattFalahe\Seat\MiningManager\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale'],
    'prefix' => 'mining',
    'as' => 'mining.'
], function () {
    
    // Dashboard
    Route::get('/', 'DashboardController@index')->name('dashboard');
    Route::get('/dashboard/data', 'DashboardController@getData')->name('dashboard.data');
    
    // Analytics
    Route::get('/analytics', 'AnalyticsController@index')->name('analytics');
    Route::get('/analytics/data', 'AnalyticsController@getData')->name('analytics.data');
    Route::get('/analytics/export', 'AnalyticsController@export')->name('analytics.export');
    
    // Tax Management
    Route::get('/taxes', 'TaxController@index')->name('taxes');
    Route::get('/taxes/calculate', 'TaxController@calculate')->name('taxes.calculate');
    Route::post('/taxes/generate', 'TaxController@generateContracts')->name('taxes.generate');
    Route::get('/taxes/contracts', 'TaxController@contracts')->name('taxes.contracts');
    Route::post('/taxes/contracts/{id}/update', 'TaxController@updateContract')->name('taxes.contract.update');
    
    // Events
    Route::get('/events', 'EventController@index')->name('events');
    Route::get('/events/create', 'EventController@create')->name('events.create');
    Route::post('/events/store', 'EventController@store')->name('events.store');
    Route::get('/events/{id}', 'EventController@show')->name('events.show');
    Route::post('/events/{id}/update', 'EventController@update')->name('events.update');
    Route::delete('/events/{id}', 'EventController@destroy')->name('events.destroy');
    
    // Moon Tracking
    Route::get('/moons', 'MoonController@index')->name('moons');
    Route::get('/moons/{id}', 'MoonController@show')->name('moons.show');
    Route::get('/moons/{id}/extractions', 'MoonController@extractions')->name('moons.extractions');
    
    // Reports
    Route::get('/reports', 'ReportController@index')->name('reports');
    Route::get('/reports/generate', 'ReportController@generate')->name('reports.generate');
    Route::get('/reports/download/{id}', 'ReportController@download')->name('reports.download');
    
    // Settings
    Route::get('/settings', 'SettingsController@index')->name('settings');
    Route::post('/settings/update', 'SettingsController@update')->name('settings.update');
    
    // API Routes
    Route::prefix('api')->group(function () {
        Route::get('/miners', 'ApiController@miners')->name('api.miners');
        Route::get('/ore-types', 'ApiController@oreTypes')->name('api.ore-types');
        Route::get('/statistics', 'ApiController@statistics')->name('api.statistics');
    });
});
