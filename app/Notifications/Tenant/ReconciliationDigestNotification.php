<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\AdminNotificationChannels;
use App\Support\PushEventSettings;
use App\Support\ReconciliationDigestSettings;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class ReconciliationDigestNotification extends Notification
{
    use DeliversToAdminChannels;

    /**
     * @param  'nightly'|'daily'|'monthly'  $mode
     */
    public function __construct(
        public string $mode,
        public string $summary,
        public string $reconciliationUrl,
        public bool $critical = false,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $channels = PushEventSettings::filterChannels(
            AdminNotificationChannels::resolve(),
            $this->adminNotificationTemplateKey(),
        );

        if (ReconciliationDigestSettings::digestPushEnabled()) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            fn (mixed $channel): bool => $channel !== WebPushChannel::class,
        ));
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->absoluteUrl(),
            'reconciliation-'.$this->mode,
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
                ->title($copy['title'] !== '' ? $copy['title'] : $this->fallbackTitle())
                ->body($copy['body'] !== '' ? $copy['body'] : $this->summary)
                ->icon($this->critical ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-shield-check')
                ->iconColor($this->critical ? 'danger' : 'success')
                ->actions([
                    Action::make('review')
                        ->label(__('Review reconciliation'))
                        ->url($this->absoluteUrl())
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
            'title' => $this->fallbackTitle(),
            'summary' => $this->summary,
            'mode' => $this->mode,
            'action_url' => $this->absoluteUrl(),
            'action_label' => __('Review reconciliation'),
        ];
    }

    protected function fallbackTitle(): string
    {
        return match ($this->mode) {
            'nightly' => $this->critical
                ? __('Nightly reconciliation needs attention')
                : __('Nightly reconciliation complete'),
            'daily' => $this->critical
                ? __('Daily reconciliation needs attention')
                : __('Daily reconciliation complete'),
            'monthly' => $this->critical
                ? __('Monthly reconciliation needs attention')
                : __('Monthly reconciliation complete'),
            default => __('Reconciliation complete'),
        };
    }

    protected function absoluteUrl(): string
    {
        return TenantAbsoluteUrl::resolve($this->reconciliationUrl);
    }
}
