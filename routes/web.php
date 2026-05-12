<?php

use App\Http\Controllers\FamilyAuthController;
use App\Http\Controllers\PublicPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicPageController::class, 'home'])->name('public.home');
Route::get('/lang/{locale}', [PublicPageController::class, 'switchLocale'])->name('locale.switch');

Route::get('/family/{family:slug}', [PublicPageController::class, 'familyPage'])->name('public.family');
Route::post('/family/{family:slug}/enroll', [PublicPageController::class, 'submitEnrollment'])->name('public.enroll');

Route::get('/family/{family:slug}/login', [FamilyAuthController::class, 'show'])->name('family.login');
Route::post('/family/{family:slug}/login', [FamilyAuthController::class, 'login'])->name('family.login.submit');
Route::post('/logout', [FamilyAuthController::class, 'logout'])->name('logout');
