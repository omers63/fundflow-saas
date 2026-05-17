<?php

namespace App\Filament\Concerns;

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

        return is_string($group) ? Lang::formatUiLabel(__($group)) : $group;
    }
}
