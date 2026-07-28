<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanDefaultBorrowerGuarantorPaidNotification extends Notification
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
        $guarantorName = $this->loan->guarantor?->name ?? __('your guarantor');

        return [
            'title' => __('Installment paid by guarantor'),
            'body' => __('Installment #:num on loan #:id was paid from the guarantor fund (:guarantor).', [
                'num' => $this->installment->installment_number,
                'id' => $this->loan->id,
                'guarantor' => $guarantorName,
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-shield-check',
            'color' => 'warning',
        ];
    }
}
