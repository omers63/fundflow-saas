<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanDisbursedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(public readonly Loan $loan) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Loan fully disbursed'),
            'body' => __('Loan #:id is active. :count installments scheduled.', [
                'id' => $this->loan->id,
                'count' => $this->loan->installments_count,
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-banknotes',
            'color' => 'success',
        ];
    }
}
