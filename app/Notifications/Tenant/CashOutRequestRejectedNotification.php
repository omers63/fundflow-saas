<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\CashOutRequest;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class CashOutRequestRejectedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public CashOutRequest $cashOutRequest,
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
            'title' => __('Cash-out rejected'),
            'body' => __('Your withdrawal request of :amount was rejected.', [
                'amount' => number_format((float) $this->cashOutRequest->amount, 2),
            ]),
            'cash_out_request_id' => $this->cashOutRequest->id,
            'icon' => 'heroicon-o-x-circle',
            'color' => 'danger',
        ];
    }
}
