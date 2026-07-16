<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\FundPosting;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class FundPostingBankClearedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly FundPosting $fundPosting,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Deposit cleared'),
            'body' => __('Your deposit of :amount has been matched on the bank statement.', [
                'amount' => number_format((float) $this->fundPosting->amount, 2),
            ]),
            'fund_posting_id' => $this->fundPosting->id,
            'icon' => 'heroicon-o-check-badge',
            'color' => 'success',
        ];
    }
}
