<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
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

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildAdminWebPush(
            $this->title(),
            $this->summary,
            $this->absoluteUrl(),
            'reconciliation-'.$this->mode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->summary)
            ->icon($this->critical ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-shield-check')
            ->iconColor($this->critical ? 'danger' : 'success')
            ->actions([
                Action::make('review')
                    ->label(__('Review reconciliation'))
                    ->url($this->absoluteUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function title(): string
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
