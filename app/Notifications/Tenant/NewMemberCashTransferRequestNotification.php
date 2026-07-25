<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Support\AdminNotificationActions;
use App\Filament\Tenant\Resources\MemberCashTransferRequests\MemberCashTransferRequestResource;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewMemberCashTransferRequestNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public MemberCashTransferRequest $transferRequest,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'member-cash-transfer-'.$this->transferRequest->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('New cash transfer request'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-arrows-right-left')
                ->iconColor('warning')
                ->actions([
                    AdminNotificationActions::review(
                        __('Review request'),
                        MemberCashTransferRequestResource::getUrl('index', [
                            'filters' => ['status' => ['value' => 'pending']],
                        ], panel: 'tenant'),
                    ),
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
        $this->transferRequest->loadMissing('fromMember');

        return [
            'member_name' => (string) ($this->transferRequest->fromMember->name ?? __('Member')),
            'recipient_name' => (string) $this->transferRequest->recipient_name,
            'amount' => number_format((float) $this->transferRequest->amount, 2),
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review'),
        ];
    }

    protected function fallbackBody(): string
    {
        $this->transferRequest->loadMissing('fromMember');

        return __(':name requested a cash transfer of :amount to :recipient.', [
            'name' => $this->transferRequest->fromMember->name ?? __('Member'),
            'amount' => number_format((float) $this->transferRequest->amount, 2),
            'recipient' => $this->transferRequest->recipient_name,
        ]);
    }

    protected function reviewUrl(): string
    {
        return TenantAbsoluteUrl::resolve(
            MemberCashTransferRequestResource::getUrl('index', [
                'filters' => ['status' => ['value' => 'pending']],
            ], panel: 'tenant'),
        );
    }
}
