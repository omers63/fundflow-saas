<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\Tenant\User;
use App\Support\AdminNotificationChannels;
use App\Support\MemberLocale;
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

    protected function buildAdminWebPushFor(
        object $notifiable,
        string $title,
        string $body,
        ?string $url = null,
        ?string $tag = null,
    ): WebPushMessage {
        return $this->withRecipientLocale($notifiable, fn (): WebPushMessage => $this->buildAdminWebPush(
            $title,
            $body,
            $url,
            $tag,
        ));
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withRecipientLocale(object $notifiable, callable $callback): mixed
    {
        if ($notifiable instanceof User) {
            return MemberLocale::usingPreferred($notifiable, $callback);
        }

        return $callback();
    }
}
