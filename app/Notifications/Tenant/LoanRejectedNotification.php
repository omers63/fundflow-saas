<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanRejectedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Loan rejected'),
            'body' => $this->reason,
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-x-circle',
            'color' => 'danger',
        ];
    }
}
