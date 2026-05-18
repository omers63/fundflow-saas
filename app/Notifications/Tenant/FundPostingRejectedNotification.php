<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\FundPosting;
use Illuminate\Notifications\Notification;

class FundPostingRejectedNotification extends Notification
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
        $remarks = filled($this->fundPosting->admin_remarks)
            ? ' '.__('Reason: :reason', ['reason' => $this->fundPosting->admin_remarks])
            : '';

        return [
            'title' => __('Deposit rejected'),
            'body' => __('Your deposit of :amount on :date was not accepted.:remarks', [
                'amount' => $this->fundPosting->amount,
                'date' => $this->fundPosting->posting_date->format('M d, Y'),
                'remarks' => $remarks,
            ]),
            'fund_posting_id' => $this->fundPosting->id,
            'status' => 'rejected',
        ];
    }
}
