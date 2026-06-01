<?php

namespace App\Filament\Resources\RelationManagers;

use App\Filament\Support\TabLabelColors;
use App\Filament\Support\UiLabelIcons;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager as BaseRelationManager;
use Filament\Support\Enums\IconPosition;
use Illuminate\Contracts\Support\Htmlable;
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

    public static function getIcon(Model $ownerRecord, string $pageClass): string|BackedEnum|Htmlable|null
    {
        return UiLabelIcons::forKey(static::$relationship)
            ?? UiLabelIcons::forLabel(static::getTitle($ownerRecord, $pageClass));
    }

    public static function getIconPosition(Model $ownerRecord, string $pageClass): IconPosition
    {
        return IconPosition::Before;
    }
}
