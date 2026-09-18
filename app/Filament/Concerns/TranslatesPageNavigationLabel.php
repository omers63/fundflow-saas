<?php

namespace App\Filament\Concerns;

use App\Support\Lang;

trait TranslatesPageNavigationLabel
{
    public static function getNavigationLabel(): string
    {
        return Lang::translateUi(parent::getNavigationLabel());
    }
}
