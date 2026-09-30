<?php

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['auth']], function () {
    // Jumpseat routes
    Route::prefix('jumpseat')->group(function () {
        Route::get('/', 'JumpseatController@index')->name('jumpseat.index');
        Route::get('/create', 'JumpseatController@create')->name('jumpseat.create');
        Route::post('/', 'JumpseatController@store')->name('jumpseat.store');
        Route::post('/preview', 'JumpseatController@preview')->name('jumpseat.preview');  // <-- Esta línea debe estar
        Route::delete('/{id}', 'JumpseatController@cancel')->name('jumpseat.cancel');
    });
    
    // Ferry routes
    Route::prefix('ferry')->group(function () {
        Route::get('/', 'FerryController@index')->name('ferry.index');
        Route::get('/create', 'FerryController@create')->name('ferry.create');
        Route::post('/', 'FerryController@store')->name('ferry.store');
        Route::delete('/{id}', 'FerryController@cancel')->name('ferry.cancel');
    });

    // CHARTER routes
    Route::prefix('charter')->group(function () {
        Route::get('/create', 'CharterController@create')->name('charter.create');
        Route::post('/', 'CharterController@store')->name('charter.store');
        Route::post('/preview', 'CharterController@preview')->name('charter.preview');
        Route::post('/aircraft', 'CharterController@getAvailableAircraft')->name('charter.aircraft');
    });

    // STATISTICS route
    Route::prefix('stats')->group(function () {
      Route::get('/', 'StatisticsController@index')->name('stats.index');
    });
});
