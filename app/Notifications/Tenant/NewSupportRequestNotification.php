<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Support\AdminNotificationActions;
use App\Filament\Tenant\Resources\SupportRequests\SupportRequestResource;
use App\Models\Tenant\SupportRequest;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewSupportRequestNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public readonly SupportRequest $supportRequest,
        public readonly string $memberInfo,
        public readonly string $categoryLabel,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'support-request-'.$this->supportRequest->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->withRecipientLocale($notifiable, function () use ($notifiable): array {
            $copy = $this->adminBellCopy($notifiable);

            return FilamentNotification::make()
                ->title($copy['title'] !== '' ? $copy['title'] : __('Support request #:id: :subject', [
                    'id' => $this->supportRequest->id,
                    'subject' => $this->supportRequest->subject,
                ]))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackSummary())
                ->icon('heroicon-o-chat-bubble-left-right')
                ->iconColor('warning')
                ->actions([
                    AdminNotificationActions::reviewSupportRequest($this->supportRequest),
                ])
                ->getDatabaseMessage();
        });
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function adminTemplateVariables(object $notifiable): array
    {
        return [
            'request_id' => (string) $this->supportRequest->id,
            'subject' => (string) $this->supportRequest->subject,
            'from' => $this->memberInfo,
            'category' => $this->categoryLabel,
            'message' => (string) $this->supportRequest->message,
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review'),
        ];
    }

    protected function fallbackSummary(): string
    {
        return __('Request #:id from :from', [
            'id' => $this->supportRequest->id,
            'from' => $this->memberInfo,
        ])
            ."\n".__('Category: :category', ['category' => $this->categoryLabel])
            ."\n\n".$this->supportRequest->message;
    }

    protected function reviewUrl(): string
    {
        $url = SupportRequestResource::getUrl('index', panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
