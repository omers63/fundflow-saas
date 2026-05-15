<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

trait TranslatesRelationManagerTitle
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(parent::getTitle($ownerRecord, $pageClass));
    }
}
