<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Support\AdminNotificationChannels;
use App\Support\NotificationPlainText;
use App\Support\WebPushNotification;
use NotificationChannels\WebPush\WebPushMessage;

trait DeliversToAdminChannels
{
    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return AdminNotificationChannels::resolve();
    }

    protected function buildAdminWebPush(
        string $title,
        string $body,
        ?string $url = null,
        ?string $tag = null,
    ): WebPushMessage {
        $message = (new WebPushMessage)
            ->title(NotificationPlainText::from($title))
            ->body(NotificationPlainText::from($body))
            ->icon(WebPushNotification::ICON_PATH)
            ->badge(WebPushNotification::BADGE_PATH)
            ->options(['TTL' => 86400]);

        if ($tag !== null) {
            $message->tag($tag);
        }

        if ($url !== null) {
            $message->data(['url' => $url]);
        }

        return $message;
    }
}
