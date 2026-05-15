<?php

namespace App\Filament\Concerns;

use UnitEnum;

trait TranslatesFilamentNavigationLabels
{
    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = parent::getNavigationGroup();

        return is_string($group) ? __($group) : $group;
    }
}
