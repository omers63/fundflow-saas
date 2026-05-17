<?php

declare(strict_types=1);

namespace App\Support;

final class ShowsFundPublicShell
{
    public static function onTenantFilamentAuthPage(): bool
    {
        if (! tenant()) {
            return false;
        }

        return self::isFilamentAuthRoute('tenant') || self::isFilamentAuthRoute('member');
    }

    public static function onCentralFilamentAuthPage(): bool
    {
        return self::isFilamentAuthRoute('admin');
    }

    private static function isFilamentAuthRoute(string $panelId): bool
    {
        $routeName = request()->route()?->getName();

        return is_string($routeName) && str_starts_with($routeName, "filament.{$panelId}.auth.");
    }
}
