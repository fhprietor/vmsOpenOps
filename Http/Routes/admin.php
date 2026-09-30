<?php

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['ability:admin,admin-access']], function () {
    Route::get('/', 'OperationsController@index')->name('index');
    Route::post('/{id}/approve', 'OperationsController@approve')->name('approve');
    Route::post('/{id}/reject', 'OperationsController@reject')->name('reject');
    Route::get('/settings', 'OperationsController@settings')->name('settings');
    Route::post('/settings', 'OperationsController@updateSettings')->name('update-settings');
});