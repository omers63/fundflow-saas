<?php

namespace App\Filament\Tenant\Resources\NotificationLogs\Pages;

use App\Filament\Tenant\Resources\NotificationLogs\NotificationLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNotificationLogs extends ListRecords
{
    protected static string $resource = NotificationLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
