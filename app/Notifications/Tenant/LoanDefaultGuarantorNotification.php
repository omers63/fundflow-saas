<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanDefaultGuarantorNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly LoanInstallment $installment,
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
            'title' => __('Guarantor liability'),
            'body' => __('Your fund account was debited for loan #:id installment #:num.', [
                'id' => $this->loan->id,
                'num' => $this->installment->installment_number,
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-shield-exclamation',
            'color' => 'danger',
        ];
    }
}
