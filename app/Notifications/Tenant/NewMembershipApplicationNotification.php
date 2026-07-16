<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\MembershipApplication;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewMembershipApplicationNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public MembershipApplication $application,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildAdminWebPushFor(
            $notifiable,
            __('New membership application'),
            __(':name submitted a membership application.', ['name' => $this->application->name]),
            $this->reviewUrl(),
            'membership-application-'.$this->application->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('New membership application'))
            ->body(__(':name submitted a membership application.', ['name' => $this->application->name]))
            ->icon('heroicon-o-user-plus')
            ->iconColor('warning')
            ->actions([
                Action::make('review')
                    ->label(__('Review application'))
                    ->url($this->reviewUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function reviewUrl(): string
    {
        $url = MembershipApplicationResource::getUrl('edit', ['record' => $this->application], panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
