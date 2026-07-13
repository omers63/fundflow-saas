<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Support\AdminNotificationActions;
use App\Models\Tenant\CashOutRequest;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewCashOutRequestNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public CashOutRequest $cashOutRequest,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $this->cashOutRequest->loadMissing('member');

        return $this->buildAdminWebPushFor(
            $notifiable,
            __('New cash-out request'),
            __(':name requested :amount.', [
                'name' => $this->cashOutRequest->member->name,
                'amount' => number_format((float) $this->cashOutRequest->amount, 2),
            ]),
            $this->reviewUrl(),
            'cash-out-request-'.$this->cashOutRequest->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $this->cashOutRequest->loadMissing('member');

        return FilamentNotification::make()
            ->title(__('New cash-out request'))
            ->body(__(':name requested :amount.', [
                'name' => $this->cashOutRequest->member->name,
                'amount' => number_format((float) $this->cashOutRequest->amount, 2),
            ]))
            ->icon('heroicon-o-arrow-up-tray')
            ->iconColor('warning')
            ->actions([
                AdminNotificationActions::reviewCashOutRequest($this->cashOutRequest),
            ])
            ->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    protected function reviewUrl(): string
    {
        return TenantAbsoluteUrl::resolve(
            AdminNotificationActions::cashOutRequestUrl($this->cashOutRequest),
        );
    }
}
