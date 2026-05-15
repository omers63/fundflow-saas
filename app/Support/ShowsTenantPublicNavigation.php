<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

final class ShowsTenantPublicNavigation
{
    public static function onFilamentAuthPage(): bool
    {
        if (! filament()->auth()->guest()) {
            return false;
        }

        $routeName = Route::currentRouteName();

        if ($routeName === null) {
            return false;
        }

        return str_starts_with($routeName, 'filament.')
            && str_contains($routeName, '.auth.');
    }
}
