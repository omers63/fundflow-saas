<?php

namespace App\Notifications\Tenant;

use App\Models\Tenant\FundPosting;
use Illuminate\Notifications\Notification;

class NewFundPostingNotification extends Notification
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
            'title' => __('New deposit request'),
            'body' => __(':name submitted :amount on :date', [
                'name' => $this->fundPosting->member->name,
                'amount' => $this->fundPosting->amount,
                'date' => $this->fundPosting->posting_date->format('M d, Y'),
            ]),
            'fund_posting_id' => $this->fundPosting->id,
            'member_name' => $this->fundPosting->member->name,
            'amount' => $this->fundPosting->amount,
        ];
    }
}
