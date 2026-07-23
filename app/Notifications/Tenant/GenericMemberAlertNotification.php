<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Services\Tenant\NotificationPreferenceService;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * Ad-hoc member alert that still honors preferences and the shared template pipeline.
 */
class GenericMemberAlertNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public string $category = NotificationPreferenceService::ACCOUNT_ALERTS,
    ) {}

    protected function memberNotificationCategory(): ?string
    {
        return $this->category;
    }

    protected function memberNotificationTemplateKey(): ?string
    {
        return 'generic_member_alert';
    }

    /**
     * @return list<string>
     */
    protected function memberNotificationSupportedChannels(): array
    {
        return NotificationPreferenceService::CATEGORIES[$this->category]['supported']
            ?? NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::ACCOUNT_ALERTS]['supported'];
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
            ->icon('heroicon-o-bell')
            ->iconColor('info')
            ->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentPayload(object $notifiable): array
    {
        return [
            'url' => $this->url,
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
            'action_url' => $this->url,
            'action_label' => __('Open'),
        ];
    }
}
