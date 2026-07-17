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
        return $this->buildAdminWebPushFor(
            $notifiable,
            __('Reconciliation exception'),
            $this->summary(),
            $this->reviewUrl(),
            'reconciliation-exception-'.$this->exception->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Reconciliation exception'))
            ->body($this->summary())
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor($this->exception->severity === 'critical' ? 'danger' : 'warning')
            ->actions([
                Action::make('review')
                    ->label(__('Review exception'))
                    ->url($this->reviewUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function summary(): string
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
