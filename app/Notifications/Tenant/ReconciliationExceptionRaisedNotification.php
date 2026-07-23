<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Models\Tenant\ReconciliationException;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class ReconciliationExceptionRaisedNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public readonly ReconciliationException $exception,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'reconciliation-exception-'.$this->exception->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('Reconciliation exception'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackSummary())
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor($this->exception->severity === 'critical' ? 'danger' : 'warning')
                ->actions([
                    Action::make('review')
                        ->label(__('Review exception'))
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
            'severity' => ucfirst($this->exception->severity),
            'code' => (string) $this->exception->exception_code,
            'domain' => (string) $this->exception->domain,
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review exception'),
        ];
    }

    protected function fallbackSummary(): string
    {
        return __(':severity exception :code in :domain.', [
            'severity' => ucfirst($this->exception->severity),
            'code' => $this->exception->exception_code,
            'domain' => $this->exception->domain,
        ]);
    }

    protected function reviewUrl(): string
    {
        $url = ReconciliationOverviewPage::getUrl([
            'sideTab' => 'exceptions',
            'exception' => $this->exception->getKey(),
        ], panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
