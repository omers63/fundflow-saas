<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\CashOutRequest;
use Illuminate\Notifications\Notification;

class NewCashOutRequestNotification extends Notification
{
    public function __construct(
        public CashOutRequest $cashOutRequest,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->cashOutRequest->loadMissing('member');

        return [
            'title' => __('New cash-out request'),
            'body' => __(':name requested :amount.', [
                'name' => $this->cashOutRequest->member->name,
                'amount' => number_format((float) $this->cashOutRequest->amount, 2),
            ]),
            'cash_out_request_id' => $this->cashOutRequest->id,
            'member_name' => $this->cashOutRequest->member->name,
            'amount' => $this->cashOutRequest->amount,
        ];
    }
}
