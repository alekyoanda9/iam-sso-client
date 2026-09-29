<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => config('sso.routes.prefix', 'sso'),
    'middleware' => config('sso.routes.middleware', ['web']),
], function () {
    Route::get('login', '\Sd1\IamSso\Http\Controllers\SsoController@login')->name('sso.login');
    Route::get('callback', '\Sd1\IamSso\Http\Controllers\SsoController@callback')->name('sso.callback');
    Route::match(['get', 'post'], 'logout', '\Sd1\IamSso\Http\Controllers\SsoController@logout')->name('sso.logout');
    Route::get('error', '\Sd1\IamSso\Http\Controllers\SsoController@error')->name('sso.error');
});
