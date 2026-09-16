<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Api\V1\BalancesController;
use App\Http\Controllers\Tenant\Api\V1\ContributionsController;
use App\Http\Controllers\Tenant\Api\V1\LoansController;
use App\Http\Controllers\Tenant\Api\V1\MembersController;
use App\Http\Controllers\Tenant\Api\V1\StatementsController;
use App\Http\Controllers\Tenant\Api\V1\WriteApiController;
use App\Http\Middleware\AuthenticateTenantApiToken;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant public REST API (v1)
|--------------------------------------------------------------------------
|
| Bearer tokens are tenant-scoped (hashed in api_access_tokens). Tokens cannot
| cross tenants because tenancy is resolved by domain before auth.
|
*/

Route::middleware([
    'api',
    PreventAccessFromCentralDomains::class,
    InitializeTenancyByDomain::class,
])->prefix('api/v1')->group(function (): void {
    Route::middleware([AuthenticateTenantApiToken::class . ':members:read'])->group(function (): void {
        Route::get('members', [MembersController::class, 'index']);
        Route::get('members/{member}', [MembersController::class, 'show']);
    });

    Route::middleware([AuthenticateTenantApiToken::class . ':balances:read'])->group(function (): void {
        Route::get('balances', [BalancesController::class, 'index']);
        Route::get('balances/{member}', [BalancesController::class, 'show']);
    });

    Route::middleware([AuthenticateTenantApiToken::class . ':contributions:read'])->group(function (): void {
        Route::get('contributions', [ContributionsController::class, 'index']);
        Route::get('contributions/{contribution}', [ContributionsController::class, 'show']);
    });

    Route::middleware([AuthenticateTenantApiToken::class . ':loans:read'])->group(function (): void {
        Route::get('loans', [LoansController::class, 'index']);
        Route::get('loans/{loan}', [LoansController::class, 'show']);
    });

    Route::middleware([AuthenticateTenantApiToken::class . ':balances:read'])->group(function (): void {
        Route::get('statements', [StatementsController::class, 'index']);
        Route::get('statements/{statement}', [StatementsController::class, 'show']);
    });

    Route::middleware([AuthenticateTenantApiToken::class . ':payments:write'])->group(function (): void {
        Route::post('gateway-payments', [WriteApiController::class, 'createGatewayPayment']);
    });

    Route::middleware([AuthenticateTenantApiToken::class . ':votes:write'])->group(function (): void {
        Route::post('motions/{motion}/votes', [WriteApiController::class, 'castVote']);
    });

    Route::middleware([AuthenticateTenantApiToken::class . ':goals:write'])->group(function (): void {
        Route::post('savings-goals', [WriteApiController::class, 'createSavingsGoal']);
    });

    Route::middleware([AuthenticateTenantApiToken::class . ':disbursements:write'])->group(function (): void {
        Route::post('disbursement-batches/{batch}/ack', [WriteApiController::class, 'importDisbursementAck']);
    });
});
