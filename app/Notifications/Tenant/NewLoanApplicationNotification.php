<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use Illuminate\Notifications\Notification;

class NewLoanApplicationNotification extends Notification
{
    public function __construct(
        public Loan $loan,
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
        $this->loan->loadMissing('member');

        return [
            'title' => __('New loan application'),
            'body' => __(':name applied for :amount.', [
                'name' => $this->loan->member?->name ?? __('Member'),
                'amount' => number_format((float) $this->loan->amount_requested, 2),
            ]),
            'loan_id' => $this->loan->id,
            'member_name' => $this->loan->member?->name,
            'amount' => $this->loan->amount_requested,
        ];
    }
}
