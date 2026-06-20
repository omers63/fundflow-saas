<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

/**
 * Item remains reachable by URL but is not shown in the consolidated tenant sidebar.
 */
trait HidesFromTenantSidebar
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
