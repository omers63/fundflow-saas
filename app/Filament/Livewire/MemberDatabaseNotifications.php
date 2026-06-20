<?php

declare(strict_types=1);

namespace App\Filament\Livewire;

use App\Support\StoredNotificationTranslator;
use Carbon\CarbonInterface;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;

class MemberDatabaseNotifications extends DatabaseNotifications
{
    public function getNotification(DatabaseNotification $notification): Notification
    {
        return StoredNotificationTranslator::localizeFilamentNotification(
            parent::getNotification($notification),
        );
    }

    protected function formatNotificationDate(CarbonInterface $date): string
    {
        return $date->locale(app()->getLocale())->diffForHumans();
    }
}
