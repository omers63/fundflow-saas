<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class LoanRejectedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(public readonly string $reason) {}

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
