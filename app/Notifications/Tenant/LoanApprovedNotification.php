<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use Illuminate\Notifications\Notification;

class LoanApprovedNotification extends Notification
{
    public function __construct(
        public readonly float $amount,
        public readonly int $installments,
        public readonly string $dueDate,
    ) {}

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
            'title' => __('Loan approved'),
            'body' => __('Your loan of :amount has been approved with :installments monthly installments. Final due: :date.', [
                'amount' => number_format($this->amount, 2),
                'installments' => $this->installments,
                'date' => $this->dueDate,
            ]),
            'icon' => 'heroicon-o-check-circle',
            'color' => 'success',
        ];
    }
}
