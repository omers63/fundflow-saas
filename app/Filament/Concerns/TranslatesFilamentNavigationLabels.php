<?php

namespace App\Filament\Concerns;

use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Support\Lang;
use UnitEnum;

trait TranslatesFilamentNavigationLabels
{
    public static function getNavigationLabel(): string
    {
        return Lang::formatUiLabel(__(parent::getNavigationLabel()));
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = parent::getNavigationGroup();

        if (! is_string($group)) {
            return $group;
        }

        if (MemberNavigation::isGroupKey($group) || TenantNavigation::isGroupKey($group)) {
            return $group;
        }

        return Lang::formatUiLabel(__($group));
    }
}
