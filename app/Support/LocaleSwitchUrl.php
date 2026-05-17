<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;

final class LocaleSwitchUrl
{
    public static function for(string $locale): string
    {
        if (Route::has('tenant.locale.switch')) {
            return route('tenant.locale.switch', ['locale' => $locale]);
        }

        if (Route::has('locale.switch')) {
            return route('locale.switch', ['locale' => $locale]);
        }

        return url('/locale/'.$locale);
    }

    public static function redirectFallback(): string
    {
        if (Route::has('tenant.home')) {
            return route('tenant.home');
        }

        if (Route::has('filament.admin.auth.login')) {
            return route('filament.admin.auth.login');
        }

        return url('/');
    }
}
