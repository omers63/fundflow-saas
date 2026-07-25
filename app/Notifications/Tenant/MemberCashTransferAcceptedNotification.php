<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\MemberCashTransferRequest;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class MemberCashTransferAcceptedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public MemberCashTransferRequest $transferRequest,
        public bool $forRecipient = false,
    ) {}

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
    protected function contentPayload(object $notifiable): array
    {
        $this->transferRequest->loadMissing(['fromMember', 'toMember']);
        $amount = number_format((float) $this->transferRequest->amount, 2);

        if ($this->forRecipient) {
            return [
                'title' => __('Cash transfer received'),
                'body' => __('You received :amount from :name.', [
                    'amount' => $amount,
                    'name' => $this->transferRequest->fromMember->name ?? __('Member'),
                ]),
                'member_cash_transfer_request_id' => $this->transferRequest->id,
                'icon' => 'heroicon-o-banknotes',
                'color' => 'success',
            ];
        }

        return [
            'title' => __('Cash transfer approved'),
            'body' => __('Your transfer of :amount to :recipient was approved.', [
                'amount' => $amount,
                'recipient' => $this->transferRequest->toMember->name
                    ?? $this->transferRequest->recipient_name,
            ]),
            'member_cash_transfer_request_id' => $this->transferRequest->id,
            'icon' => 'heroicon-o-check-circle',
            'color' => 'success',
        ];
    }
}
