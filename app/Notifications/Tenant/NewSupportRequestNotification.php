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
        return $this->buildAdminWebPushFor(
            $notifiable,
            __('Support request #:id', ['id' => $this->supportRequest->id]),
            $this->summary(),
            $this->reviewUrl(),
            'support-request-'.$this->supportRequest->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Support request #:id: :subject', [
                'id' => $this->supportRequest->id,
                'subject' => $this->supportRequest->subject,
            ]))
            ->body($this->summary())
            ->icon('heroicon-o-chat-bubble-left-right')
            ->iconColor('warning')
            ->actions([
                AdminNotificationActions::reviewSupportRequest($this->supportRequest),
            ])
            ->getDatabaseMessage();
    }

    protected function summary(): string
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
