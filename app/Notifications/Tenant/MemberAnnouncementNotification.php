<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Pages\CommunicationsPage;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Services\Tenant\NotificationPreferenceService;
use App\Support\PushEventSettings;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;

class MemberAnnouncementNotification extends Notification
{
    use DeliversToMemberChannels;
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public bool $sendInApp = true,
        public bool $sendEmail = true,
        public bool $sendSms = false,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $supported = [];

        if ($this->sendInApp) {
            $supported[] = NotificationPreferenceService::CH_IN_APP;
            $supported[] = NotificationPreferenceService::CH_PUSH;
        }

        if ($this->sendEmail) {
            $supported[] = NotificationPreferenceService::CH_EMAIL;
        }

        if ($this->sendSms) {
            $supported[] = NotificationPreferenceService::CH_SMS;
        }

        if ($supported === []) {
            return [];
        }

        $resolved = NotificationPreferenceService::resolve(
            $notifiable,
            NotificationPreferenceService::BROADCASTS,
            $supported,
        );

        // Keep push available when email-only announcements are selected and push is preferred.
        if (
            $this->sendEmail
            && ! $this->sendInApp
            && in_array(NotificationPreferenceService::CH_PUSH, NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::BROADCASTS]['supported'], true)
        ) {
            $withPush = NotificationPreferenceService::resolve(
                $notifiable,
                NotificationPreferenceService::BROADCASTS,
                array_values(array_unique([...$supported, NotificationPreferenceService::CH_PUSH])),
            );

            foreach ($withPush as $driver) {
                if ($driver === WebPushChannel::class && ! in_array($driver, $resolved, true)) {
                    $resolved[] = $driver;
                }
            }
        }

        return PushEventSettings::filterChannels(
            $resolved,
            $this->memberNotificationTemplateKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->templatedArrayPayload($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payload = $this->templatedArrayPayload($notifiable);

        return FilamentNotification::make()
            ->title((string) ($payload['title'] ?? $this->title))
            ->body((string) ($payload['body'] ?? $this->body))
            ->icon('heroicon-o-megaphone')
            ->iconColor('primary')
            ->actions([
                Action::make('open')
                    ->label(__('View alerts'))
                    ->url($this->alertsUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentPayload(object $notifiable): array
    {
        return [
            'url' => $this->alertsUrl(),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->alertsUrl(),
            'action_label' => __('View alerts'),
        ];
    }

    protected function alertsUrl(): string
    {
        $url = CommunicationsPage::getUrl(['tab' => 'alerts'], panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
    }
}
