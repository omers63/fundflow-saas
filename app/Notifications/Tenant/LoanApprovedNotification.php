<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanApprovedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly float $amount,
        public readonly int $installments,
        public readonly string $dueDate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Loan approved'),
            'body' => __('Your loan of :amount has been approved with :installments monthly installments. Final due: :date.', [
                'amount' => number_format($this->amount, 2),
                'installments' => $this->installments,
                'date' => $this->dueDate,
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-check-circle',
            'color' => 'success',
        ];
    }
}
