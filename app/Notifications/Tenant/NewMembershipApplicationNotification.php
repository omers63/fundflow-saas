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
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'membership-application-'.$this->application->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('New membership application'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-user-plus')
                ->iconColor('warning')
                ->actions([
                    Action::make('review')
                        ->label(__('Review application'))
                        ->url($this->reviewUrl())
                        ->markAsRead(),
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
            'member_name' => (string) $this->application->name,
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review application'),
        ];
    }

    protected function fallbackBody(): string
    {
        return __(':name submitted a membership application.', ['name' => $this->application->name]);
    }

    protected function reviewUrl(): string
    {
        $url = MembershipApplicationResource::getUrl('edit', ['record' => $this->application], panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
