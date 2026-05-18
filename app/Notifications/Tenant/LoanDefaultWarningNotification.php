<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanDefaultWarningNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly LoanInstallment $installment,
        public readonly int $defaultCount,
        public readonly int $graceCycles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Loan repayment overdue'),
            'body' => __('Installment #:num is overdue (:count of :grace grace cycles used).', [
                'num' => $this->installment->installment_number,
                'count' => $this->defaultCount,
                'grace' => $this->graceCycles,
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
        ];
    }
}
