<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Notifications\Concerns\DeliversToAdminChannels;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class TestAdminWebPushNotification extends Notification
{
    use DeliversToAdminChannels;

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildAdminWebPush(
            __('Test notification'),
            __('This is a test push notification from FundFlow.'),
            null,
            'fundflow-test-push',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Test notification'))
            ->body(__('This is a test push notification from FundFlow.'))
            ->icon('heroicon-o-bell')
            ->iconColor('info')
            ->getDatabaseMessage();
    }
}
