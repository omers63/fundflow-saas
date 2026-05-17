<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AppLocale;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApplicationLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = LanguageSwitch::make()->getPreferredLocale();

        if (! AppLocale::isSupported($locale)) {
            $locale = config('app.locale', AppLocale::DEFAULT);
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
