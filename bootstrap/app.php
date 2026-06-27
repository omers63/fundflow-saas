<?php

use App\Http\Middleware\EnforceMemberPortalMaintenance;
use App\Http\Middleware\InitializeTenancyByDomainEarly;
use App\Http\Middleware\SetApplicationLocale;
use App\Http\Middleware\StartWallClockSession;
use App\Http\Middleware\UseWallClockForSessions;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Http\Middleware\AuthenticateSession as FilamentAuthenticateSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function () {

            // Central routes
            $domains = [
                config('tenancy.central_domain') => base_path('routes/web.php'),

                // you can add more domains here and adjusting the tenancy config for multiple central domains
            ];

            foreach ($domains as $domain => $file) {
                Route::middleware('web')
                    ->domain($domain)
                    ->group($file);
            }

            // Tenant routes
            Route::middleware('web')->group(base_path('routes/tenant.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(InitializeTenancyByDomainEarly::class);
        $middleware->web(replace: [
            StartSession::class => StartWallClockSession::class,
        ]);
        $middleware->web(append: [
            SetApplicationLocale::class,
            UseWallClockForSessions::class,
        ]);
        $middleware->alias([
            'member-portal-maintenance' => EnforceMemberPortalMaintenance::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withBroadcasting(
        channels: __DIR__.'/../routes/channels.php',
        attributes: [
            'middleware' => [
                'web',
                InitializeTenancyByDomainEarly::class,
                FilamentAuthenticateSession::class,
                FilamentAuthenticate::class,
            ],
        ],
    )
    ->create();
