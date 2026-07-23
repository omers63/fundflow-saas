<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanCancelledNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly ?string $reason = null,
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
        $body = filled($this->reason)
            ? $this->reason
            : __('Your pending loan application #:id was cancelled.', ['id' => $this->loan->id]);

        return [
            'title' => __('Loan cancelled'),
            'body' => $body,
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-x-circle',
            'color' => 'gray',
        ];
    }
}
