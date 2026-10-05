<?php

use App\Http\Controllers\LocaleSwitchController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\SaasCheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/manifest.json', ManifestController::class)
    ->name('manifest');

Route::get('/locale/{locale}', LocaleSwitchController::class)
    ->name('locale.switch');

Route::get('/offline', fn () => view('offline'));

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post('/admin/saas-checkout/{invoice}', SaasCheckoutController::class)
        ->name('central.saas.checkout');
});
