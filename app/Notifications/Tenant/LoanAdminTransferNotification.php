<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Services\Loans\LoanTransferPreview;
use Illuminate\Notifications\Notification;

class LoanAdminTransferNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly Member $borrower,
        public readonly Member $recipient,
        public readonly string $role,
        public readonly string $mode,
        public readonly ?Loan $sourceLoan = null,
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
        $modeLabel = $this->mode === LoanTransferPreview::MODE_FULL
            ? __('full')
            : __('remaining');

        if ($this->role === 'recipient') {
            return [
                'title' => __('Loan transferred to you'),
                'body' => __('Loan #:id from :borrower was transferred to you (:mode).', [
                    'id' => $this->sourceLoan?->id ?? $this->loan->id,
                    'borrower' => $this->borrower->name,
                    'mode' => $modeLabel,
                ]),
                'loan_id' => $this->loan->id,
                'icon' => 'heroicon-o-arrow-right-circle',
                'color' => 'warning',
            ];
        }

        return [
            'title' => __('Your loan was transferred'),
            'body' => __('Loan #:id was transferred to :recipient (:mode).', [
                'id' => $this->sourceLoan?->id ?? $this->loan->id,
                'recipient' => $this->recipient->name,
                'mode' => $modeLabel,
            ]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
        ];
    }
}
