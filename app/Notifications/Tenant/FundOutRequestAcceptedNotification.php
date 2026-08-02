<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\FundOutRequest;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class FundOutRequestAcceptedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public FundOutRequest $fundOutRequest,
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
            'title' => __('Fund-out approved'),
            'body' => __('Your fund transfer of :amount has been approved and credited to your cash account.', [
                'amount' => number_format((float) $this->fundOutRequest->amount, 2),
            ]),
            'fund_out_request_id' => $this->fundOutRequest->id,
            'icon' => 'heroicon-o-check-circle',
            'color' => 'success',
        ];
    }
}
