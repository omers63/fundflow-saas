<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanSubmittedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public Loan $loan,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Loan application submitted'),
            'body' => __('Your application for :amount is pending review.', [
                'amount' => number_format((float) $this->loan->amount_requested, 2),
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-document-text',
            'color' => 'info',
        ];
    }
}
