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
        return $this->buildAdminWebPushFor(
            $notifiable,
            __('New member request'),
            $this->summary(),
            $this->reviewUrl(),
            'member-request-'.$this->request->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('New member request'))
            ->body($this->summary())
            ->icon('heroicon-o-clipboard-document-list')
            ->iconColor('warning')
            ->actions([
                AdminNotificationActions::reviewMemberRequest($this->request),
            ])
            ->getDatabaseMessage();
    }

    protected function summary(): string
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
