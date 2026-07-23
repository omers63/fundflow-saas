<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanGuarantorTransferNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly Member $borrower,
        public readonly Member $guarantor,
        public readonly string $role,
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
        if ($this->role === 'guarantor') {
            return [
                'title' => __('Guarantor loan transfer'),
                'body' => __('Loan #:id from :borrower has been transferred to you after missed repayments.', [
                    'id' => $this->loan->id,
                    'borrower' => $this->borrower->name,
                ]),
                'loan_id' => $this->loan->id,
                'icon' => 'heroicon-o-shield-exclamation',
                'color' => 'danger',
            ];
        }

        return [
            'title' => __('Loan transferred to guarantor'),
            'body' => __('Loan #:id has been transferred to guarantor :name after missed repayments.', [
                'id' => $this->loan->id,
                'name' => $this->guarantor->name,
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
        ];
    }
}
