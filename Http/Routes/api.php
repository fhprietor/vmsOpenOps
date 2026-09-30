<?php

use Illuminate\Support\Facades\Route;

// Las rutas ya tienen middleware web y auth desde el RouteServiceProvider
Route::get('/operations', 'OperationsController@index')->name('api.index');
Route::get('/operations/pending', 'OperationsController@checkPending')->name('api.pending');
Route::get('/user/balance', 'OperationsController@getUserBalance')->name('api.user.balance');
Route::post('/jumpseat/preview', 'OperationsController@previewJumpseat')->name('api.jumpseat.preview');
Route::post('/jumpseat', 'OperationsController@storeJumpseat')->name('api.jumpseat.store');
Route::post('/ferry/available', 'OperationsController@getAvailableAircraft')->name('api.ferry.available');
Route::post('/ferry', 'OperationsController@storeFerry')->name('api.ferry.store');
Route::post('/ferry/preview', 'OperationsController@previewFerry')->name('api.ferry.preview');
Route::delete('/{id}', 'OperationsController@cancel')->name('api.cancel');
Route::get('/stats', 'StatisticsController@getData')->name('api.stats');