<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
