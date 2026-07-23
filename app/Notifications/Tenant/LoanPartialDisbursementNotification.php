<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\LoanDisbursement;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanPartialDisbursementNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly LoanDisbursement $disbursement,
        public readonly float $totalDisbursed,
        public readonly float $amountApproved,
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
            'title' => __('Partial loan disbursement'),
            'body' => __(':disbursed of :approved disbursed. Repayment starts after full disbursement.', [
                'disbursed' => number_format($this->totalDisbursed, 2),
                'approved' => number_format($this->amountApproved, 2),
            ]),
            'icon' => 'heroicon-o-banknotes',
            'color' => 'info',
        ];
    }
}
