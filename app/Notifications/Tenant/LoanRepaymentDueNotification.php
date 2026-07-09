<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Services\Tenant\NotificationPreferenceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;

class LoanRepaymentDueNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly LoanInstallment $installment,
        public readonly CarbonInterface $deadline,
        public readonly float $cashBalance,
        public readonly string $memberName = '',
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return NotificationPreferenceService::resolveDueReminder(
            $notifiable,
            NotificationPreferenceService::LOAN_REPAYMENT,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $deadline = $this->deadline instanceof Carbon
            ? $this->deadline
            : Carbon::parse($this->deadline);

        return [
            'title' => __('Loan repayment due'),
            'body' => __('Installment #:num of :amount is due by :deadline. Cash balance: :cash.', [
                'num' => $this->installment->installment_number,
                'amount' => number_format((float) $this->installment->amount, 2),
                'deadline' => $deadline->copy()->startOfDay()->translatedFormat('j M Y'),
                'cash' => number_format($this->cashBalance, 2),
            ]),
            'loan_id' => $this->loan->id,
            'member_name' => $this->memberName !== ''
                ? $this->memberName
                : (string) ($this->loan->member?->name ?? ''),
            'icon' => 'heroicon-o-calendar',
            'color' => 'warning',
        ];
    }
}
