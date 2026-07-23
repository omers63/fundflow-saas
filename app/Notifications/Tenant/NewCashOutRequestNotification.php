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
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'cash-out-request-'.$this->cashOutRequest->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('New cash-out request'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-arrow-up-tray')
                ->iconColor('warning')
                ->actions([
                    AdminNotificationActions::reviewCashOutRequest($this->cashOutRequest),
                ])
                ->getDatabaseMessage();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function adminTemplateVariables(object $notifiable): array
    {
        $this->cashOutRequest->loadMissing('member');

        return [
            'member_name' => (string) ($this->cashOutRequest->member->name ?? __('Member')),
            'amount' => number_format((float) $this->cashOutRequest->amount, 2),
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review'),
        ];
    }

    protected function fallbackBody(): string
    {
        $this->cashOutRequest->loadMissing('member');

        return __(':name requested :amount.', [
            'name' => $this->cashOutRequest->member->name,
            'amount' => number_format((float) $this->cashOutRequest->amount, 2),
        ]);
    }

    protected function reviewUrl(): string
    {
        return TenantAbsoluteUrl::resolve(
            AdminNotificationActions::cashOutRequestUrl($this->cashOutRequest),
        );
    }
}
