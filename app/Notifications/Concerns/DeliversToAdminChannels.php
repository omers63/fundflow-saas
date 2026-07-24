<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;
use App\Services\Tenant\NotificationTemplateRenderer;
use App\Support\AdminNotificationChannels;
use App\Support\MemberLocale;
use App\Support\NotificationPlainText;
use App\Support\NotificationTemplateCatalog;
use App\Support\PushEventSettings;
use App\Support\WebPushNotification;
use NotificationChannels\WebPush\WebPushMessage;

trait DeliversToAdminChannels
{
    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return PushEventSettings::filterChannels(
            AdminNotificationChannels::resolve(),
            $this->adminNotificationTemplateKey(),
        );
    }

    protected function adminNotificationTemplateKey(): ?string
    {
        return NotificationTemplateCatalog::keyFor(static::class);
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function adminTemplateVariables(object $notifiable): array
    {
        return [];
    }

    /**
     * @return array{title: string, body: string}
     */
    protected function adminTemplatedCopy(object $notifiable, string $channelFamily): array
    {
        $key = $this->adminNotificationTemplateKey();

        if ($key === null) {
            return ['title' => '', 'body' => ''];
        }

        $locale = $notifiable instanceof User
            ? $notifiable->preferredLocale()
            : app()->getLocale();

        $rendered = app(NotificationTemplateRenderer::class)->render(
            $key,
            $channelFamily,
            $locale,
            $this->adminTemplateVariables($notifiable),
        );

        return [
            'title' => $rendered['subject'],
            'body' => $rendered['body'],
        ];
    }

    /**
     * @return array{title: string, body: string}
     */
    protected function adminBellCopy(object $notifiable): array
    {
        return $this->adminTemplatedCopy($notifiable, NotificationTemplate::FAMILY_IN_APP);
    }

    /**
     * @return array{title: string, body: string}
     */
    protected function adminPushCopy(object $notifiable): array
    {
        return $this->adminTemplatedCopy($notifiable, NotificationTemplate::FAMILY_SMS_PUSH);
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
            ->icon(WebPushNotification::iconUrl())
            ->badge(WebPushNotification::badgeUrl())
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

    protected function buildTemplatedAdminWebPush(
        object $notifiable,
        ?string $url = null,
        ?string $tag = null,
    ): WebPushMessage {
        return $this->withRecipientLocale($notifiable, function () use ($notifiable, $url, $tag): WebPushMessage {
            $copy = $this->adminPushCopy($notifiable);

            return $this->buildAdminWebPush($copy['title'], $copy['body'], $url, $tag);
        });
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
