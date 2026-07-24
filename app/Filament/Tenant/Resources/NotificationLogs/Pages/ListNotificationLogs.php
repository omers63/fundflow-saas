<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\NotificationLogs\Pages;

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Resources\NotificationLogs\NotificationLogResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationLogs extends ListRecords
{
    protected static string $resource = NotificationLogResource::class;

    public function mount(): void
    {
        $this->redirect(AuditSystemPage::getUrl(['sideTab' => 'notifications'], panel: 'tenant'));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
