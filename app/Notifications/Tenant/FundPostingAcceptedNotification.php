<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\FundPosting;
use Illuminate\Notifications\Notification;

class FundPostingAcceptedNotification extends Notification
{
    public function __construct(
        public FundPosting $fundPosting,
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
        return [
            'title' => __('Deposit accepted'),
            'body' => __('Your deposit of :amount on :date was accepted.', [
                'amount' => $this->fundPosting->amount,
                'date' => $this->fundPosting->posting_date->format('M d, Y'),
            ]),
            'fund_posting_id' => $this->fundPosting->id,
            'status' => 'accepted',
        ];
    }
}
