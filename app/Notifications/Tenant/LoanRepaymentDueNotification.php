<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;

class LoanRepaymentDueNotification extends Notification
{
    public function __construct(
        public readonly Loan $loan,
        public readonly LoanInstallment $installment,
        public readonly CarbonInterface $deadline,
        public readonly float $cashBalance,
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
            'title' => __('Loan repayment due'),
            'body' => __('Installment #:num of :amount is due by :deadline. Cash balance: :cash.', [
                'num' => $this->installment->installment_number,
                'amount' => number_format((float) $this->installment->amount, 2),
                'deadline' => $this->deadline->format('d M Y'),
                'cash' => number_format($this->cashBalance, 2),
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-calendar',
            'color' => 'warning',
        ];
    }
}
