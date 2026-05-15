<?php

namespace App\Filament\Concerns;

trait TranslatesPageNavigationLabel
{
    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }
}
