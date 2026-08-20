<?php

use App\Http\Controllers\LocaleSwitchController;
use App\Http\Controllers\ManifestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/manifest.json', ManifestController::class)
    ->name('manifest');

Route::get('/locale/{locale}', LocaleSwitchController::class)
    ->name('locale.switch');

Route::get('/offline', fn () => view('offline'));
