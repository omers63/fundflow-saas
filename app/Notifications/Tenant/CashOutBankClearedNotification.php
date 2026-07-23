<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\CashOutRequest;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class CashOutBankClearedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly CashOutRequest $cashOutRequest,
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
            'title' => __('Withdrawal cleared'),
            'body' => __('Your withdrawal of :amount has been matched on the bank statement.', [
                'amount' => number_format((float) $this->cashOutRequest->amount, 2),
            ]),
            'cash_out_request_id' => $this->cashOutRequest->id,
            'icon' => 'heroicon-o-check-badge',
            'color' => 'success',
        ];
    }
}
