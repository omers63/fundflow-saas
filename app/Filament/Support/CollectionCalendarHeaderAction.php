<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Pages\CollectionCalendarPage;
use Filament\Actions\Action;

final class CollectionCalendarHeaderAction
{
    public static function make(): Action
    {
        return Action::make('collection_calendar')
            ->label(__('Collection calendar'))
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->url(CollectionCalendarPage::getUrl());
    }
}
