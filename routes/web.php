<?php

use App\Http\Controllers\LocaleSwitchController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/locale/{locale}', LocaleSwitchController::class)
    ->name('locale.switch');

Route::get('/offline', fn () => view('offline'));
