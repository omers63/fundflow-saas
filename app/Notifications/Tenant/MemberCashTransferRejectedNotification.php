<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\MemberCashTransferRequest;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class MemberCashTransferRejectedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public MemberCashTransferRequest $transferRequest,
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
        return [
            'title' => __('Cash transfer rejected'),
            'body' => __('Your transfer request of :amount to :recipient was rejected.', [
                'amount' => number_format((float) $this->transferRequest->amount, 2),
                'recipient' => $this->transferRequest->recipient_name,
            ]),
            'member_cash_transfer_request_id' => $this->transferRequest->id,
            'icon' => 'heroicon-o-x-circle',
            'color' => 'danger',
        ];
    }
}
