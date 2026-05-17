<?php

namespace App\Support;

final class ShowsTenantPublicNavigation
{
    public static function onFilamentAuthPage(): bool
    {
        return false;
    }
}
