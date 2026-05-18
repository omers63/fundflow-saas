<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class GuarantorLoanApplicationNotification extends Notification
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
        $this->loan->loadMissing('member');

        return [
            'title' => __('Guarantor request'),
            'body' => __(':name applied for a loan of :amount and listed you as guarantor.', [
                'name' => $this->loan->member?->name ?? __('A member'),
                'amount' => number_format((float) $this->loan->amount_requested, 2),
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-shield-exclamation',
            'color' => 'warning',
        ];
    }
}
