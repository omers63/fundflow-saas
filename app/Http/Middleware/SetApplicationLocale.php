<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AppLocale;
use App\Support\LocalizationSettings;
use App\Support\UserPreferredLocale;
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
            $locale = tenancy()->initialized
                ? LocalizationSettings::guestLocale($request)
                : config('app.locale', AppLocale::DEFAULT);
        }

        app()->setLocale($locale);

        // Prefer session/cookie language over a stale preferred_locale (common after language switch
        // on the tenant guard) so emails/toasts/jobs match the portal language the user sees.
        UserPreferredLocale::syncFromRequestLocale();

        return $next($request);
    }
}
