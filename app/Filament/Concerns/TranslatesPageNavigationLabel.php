<?php

namespace App\Filament\Concerns;

use App\Support\Lang;

trait TranslatesPageNavigationLabel
{
    public static function getNavigationLabel(): string
    {
        return Lang::formatUiLabel(__(parent::getNavigationLabel()));
    }
}
