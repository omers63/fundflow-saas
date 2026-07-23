<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Support\AdminNotificationActions;
use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewMemberRequestNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public readonly MemberRequest $request,
        public readonly Member $requester,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'member-request-'.$this->request->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('New member request'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackSummary())
                ->icon('heroicon-o-clipboard-document-list')
                ->iconColor('warning')
                ->actions([
                    AdminNotificationActions::reviewMemberRequest($this->request),
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
            'member_name' => (string) ($this->requester->name ?? __('Member')),
            'request_type' => MemberRequest::typeLabel($this->request->type),
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review'),
        ];
    }

    protected function fallbackSummary(): string
    {
        return ($this->requester->name ?? __('Member'))
            .' — '
            .MemberRequest::typeLabel($this->request->type);
    }

    protected function reviewUrl(): string
    {
        $url = MemberRequestResource::getUrl('view', ['record' => $this->request], panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
