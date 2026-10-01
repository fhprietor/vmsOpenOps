<?php

use Illuminate\Support\Facades\Route;

// El grupo de rutas de admin ya aplica `web` + `ability:admin,admin-access`
// desde RouteServiceProvider, asi que aqui no se repite el middleware (antes
// estaba aplicado tres veces: grupo, este fichero y el constructor del
// controlador).
Route::get('/', 'OperationsController@index')->name('index');
Route::post('/{id}/approve', 'OperationsController@approve')->name('approve');
Route::post('/{id}/reject', 'OperationsController@reject')->name('reject');
Route::get('/settings', 'OperationsController@settings')->name('settings');
Route::post('/settings', 'OperationsController@updateSettings')->name('update-settings');
