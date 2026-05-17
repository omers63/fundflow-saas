<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Loan;
use Illuminate\Notifications\Notification;

class LoanSettledNotification extends Notification
{
    public function __construct(public readonly Loan $loan) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Loan settled'),
            'body' => __('Loan #:id is fully settled.', ['id' => $this->loan->id]),
            'loan_id' => $this->loan->id,
            'icon' => 'heroicon-o-check-badge',
            'color' => 'success',
        ];
    }
}
