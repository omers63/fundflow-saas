<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use Illuminate\Notifications\Notification;

class LoanRejectedNotification extends Notification
{
    public function __construct(public readonly string $reason) {}

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
            'title' => __('Loan rejected'),
            'body' => $this->reason,
            'icon' => 'heroicon-o-x-circle',
            'color' => 'danger',
        ];
    }
}
