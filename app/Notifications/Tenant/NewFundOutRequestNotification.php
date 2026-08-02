<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Support\AdminNotificationActions;
use App\Models\Tenant\FundOutRequest;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewFundOutRequestNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public FundOutRequest $fundOutRequest,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'fund-out-request-'.$this->fundOutRequest->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('New fund-out request'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-arrow-right-circle')
                ->iconColor('warning')
                ->actions([
                    AdminNotificationActions::reviewFundOutRequest($this->fundOutRequest),
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
        $this->fundOutRequest->loadMissing('member');

        return [
            'member_name' => (string) ($this->fundOutRequest->member->name ?? __('Member')),
            'amount' => number_format((float) $this->fundOutRequest->amount, 2),
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review'),
        ];
    }

    protected function fallbackBody(): string
    {
        $this->fundOutRequest->loadMissing('member');

        return __(':name requested a fund out of :amount.', [
            'name' => $this->fundOutRequest->member->name,
            'amount' => number_format((float) $this->fundOutRequest->amount, 2),
        ]);
    }

    protected function reviewUrl(): string
    {
        return TenantAbsoluteUrl::resolve(
            AdminNotificationActions::fundOutRequestUrl($this->fundOutRequest),
        );
    }
}
