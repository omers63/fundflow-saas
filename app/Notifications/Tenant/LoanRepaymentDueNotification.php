<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Notifications\Concerns\DeliversToMemberChannels;
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
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->templatedArrayPayload($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentPayload(object $notifiable): array
    {
        return [
            'loan_id' => $this->loan->id,
            'member_name' => $this->memberName !== ''
                ? $this->memberName
                : (string) ($this->loan->member?->name ?? ''),
            'url' => $this->memberLoanUrl((int) $this->loan->id),
            'icon' => 'heroicon-o-calendar',
            'color' => 'warning',
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        $deadline = $this->deadline instanceof Carbon
            ? $this->deadline
            : Carbon::parse($this->deadline);

        return [
            'member_name' => $this->memberName !== ''
                ? $this->memberName
                : (string) ($this->loan->member?->name ?? ''),
            'amount' => number_format((float) $this->installment->amount, 2),
            'deadline' => $deadline->copy()->startOfDay()->translatedFormat('j M Y'),
            'loan_id' => (string) $this->loan->id,
            'balance' => number_format($this->cashBalance, 2),
            'action_url' => $this->memberLoanUrl((int) $this->loan->id),
            'action_label' => __('Open'),
        ];
    }
}
