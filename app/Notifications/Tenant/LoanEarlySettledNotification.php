<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanEarlySettledNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(public readonly Loan $loan) {}

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
            'title' => __('Loan early settled'),
            'body' => __('Loan #:id has been paid off early.', ['id' => $this->loan->id]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-check-badge',
            'color' => 'success',
        ];
    }
}
