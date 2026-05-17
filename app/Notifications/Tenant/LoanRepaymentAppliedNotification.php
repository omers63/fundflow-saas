<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use Illuminate\Notifications\Notification;

class LoanRepaymentAppliedNotification extends Notification
{
    public function __construct(
        public readonly Loan $loan,
        public readonly LoanInstallment $installment,
        public readonly float $cashBalance,
        public readonly bool $isLate,
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
            'title' => __('Loan repayment applied'),
            'body' => __('Installment #:num paid. Remaining cash: :cash.', [
                'num' => $this->installment->installment_number,
                'cash' => number_format($this->cashBalance, 2),
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-currency-dollar',
            'color' => $this->isLate ? 'warning' : 'success',
        ];
    }
}
