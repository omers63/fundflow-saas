<?php

namespace App\Filament\Resources\RelationManagers;

use App\Filament\Support\TabLabelColors;
use Filament\Resources\RelationManagers\RelationManager as BaseRelationManager;
use Illuminate\Database\Eloquent\Model;

abstract class RelationManager extends BaseRelationManager
{
    protected static bool $isBadgeDeferred = true;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->{static::$relationship}()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return TabLabelColors::forKey(static::$relationship);
    }
}
